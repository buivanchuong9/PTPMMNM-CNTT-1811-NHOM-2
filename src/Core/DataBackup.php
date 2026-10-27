<?php
declare(strict_types=1);

namespace StockFlow\Core;

use RuntimeException;

/**
 * Xuất / nạp toàn bộ DỮ LIỆU của CSDL ra một file nén duy nhất (database/backup/stockflow-data.json.gz) để commit lên git.
 * Máy khác pull về, chạy `bin/stockflow restore` là có dữ liệu giống hệt máy gốc. Không cần mysqldump.
 */
final class DataBackup
{
    public const FILE = 'database/backup/stockflow-data.json.gz';
    private const VERSION = 1;

    public function __construct(private Db $db) {}

    public static function path(): string
    {
        return Config::root() . '/' . self::FILE;
    }

    /** @return list<string> */
    private function tables(): array
    {
        return array_column($this->db->all("SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name"), 't');
    }

    /** Ghi dữ liệu ra file nén. @return array<string,int> số dòng từng bảng */
    public function export(string $file): array
    {
        $data = [];
        $counts = [];
        foreach ($this->tables() as $t) {
            $data[$t] = $this->db->all('SELECT * FROM `' . $t . '`');
            $counts[$t] = count($data[$t]);
        }
        $json = json_encode(['version' => self::VERSION, 'created' => date('c'), 'tables' => $data], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        @mkdir(dirname($file), 0755, true);
        // mtime = 0 trong header gzip để cùng dữ liệu thì cùng byte, git không báo thay đổi vô ích
        $gz = gzencode($json, 9);
        if ($gz === false || file_put_contents($file, substr_replace($gz, "\0\0\0\0", 4, 4)) === false) {
            throw new RuntimeException("Không ghi được $file");
        }
        return $counts;
    }

    /** @return array<string,list<array<string,mixed>>> */
    public static function read(string $file): array
    {
        if (!is_file($file)) {
            throw new RuntimeException('Chưa có file dữ liệu ' . self::FILE . ' — máy gốc cần chạy `bin/stockflow backup`, commit rồi push.');
        }
        $json = gzdecode((string)file_get_contents($file));
        $doc = $json === false ? null : json_decode($json, true);
        if (!is_array($doc) || ($doc['version'] ?? 0) !== self::VERSION || !is_array($doc['tables'] ?? null)) {
            throw new RuntimeException("File $file hỏng hoặc không đúng định dạng.");
        }
        return $doc['tables'];
    }

    /**
     * Xoá sạch bảng hiện có, dựng lại đúng cấu trúc của mã nguồn rồi nạp dữ liệu từ file.
     * Cột không còn trong cấu trúc mới thì bỏ qua; cột mới thì lấy giá trị mặc định.
     * @return list<string> nhật ký
     */
    public function restore(string $file): array
    {
        $tables = self::read($file);
        $log = [];
        $this->db->raw('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($this->tables() as $t) {
                $this->db->raw('DROP TABLE IF EXISTS `' . $t . '`');
            }
            foreach ((new Migrator($this->db))->migrate() as $line) {
                $log[] = '   ' . $line;
            }
            $existing = $this->tables();
            $total = 0;
            foreach ($tables as $t => $rows) {
                if (!in_array($t, $existing, true)) {
                    $log[] = "   ⚠ Bảng `$t` không còn trong cấu trúc hiện tại — bỏ qua.";
                    continue;
                }
                $cols = array_map('strtolower', array_column($this->db->all('SELECT column_name AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?', [$t]), 'c'));
                foreach ($rows as $row) {
                    $ins = array_filter($row, fn($c) => in_array(strtolower((string)$c), $cols, true), ARRAY_FILTER_USE_KEY);
                    if (!$ins) {
                        continue;
                    }
                    $this->db->exec('INSERT INTO `' . $t . '` (`' . implode('`,`', array_keys($ins)) . '`) VALUES (' . implode(',', array_fill(0, count($ins), '?')) . ')', array_values($ins));
                    $total++;
                }
            }
            $log[] = "→ Đã nạp $total dòng của " . count($tables) . ' bảng.';
        } finally {
            $this->db->raw('SET FOREIGN_KEY_CHECKS = 1');
        }
        return $log;
    }
}
