<?php
declare(strict_types=1);

namespace StockFlow\Core;

use RuntimeException;
use Throwable;

/**
 * Kiểm tra CSDL MySQL/MariaDB trên máy có đúng chuẩn của dự án không (bảng, cột, kiểu, ràng buộc, trigger);
 * nếu sai thì sửa lại: sao lưu dữ liệu ra .local/backup-*, dựng lại đúng chuẩn, rồi nạp lại dữ liệu cũ
 * (có đổi tên bảng/cột/giá trị từ các phiên bản trước, ví dụ chung_tu → phieu_nhap_xuat).
 * "Chuẩn" = kết quả chạy toàn bộ database/migrations trên một CSDL tạm, nên luôn khớp với mã nguồn đang có.
 */
final class SchemaSync
{
    /** Tên bảng cũ → tên hiện tại (tên cột cũ được đổi theo cùng quy tắc chuỗi, xem renameColumn). */
    private const TABLE_RENAMES = ['chung_tu' => 'phieu_nhap_xuat', 'chi_tiet_chung_tu' => 'chi_tiet_phieu_nhap_xuat'];
    /** Giá trị chuỗi cũ → mới (ví dụ mã quyền lưu trong phan_quyen_vai_tro). */
    private const VALUE_RENAMES = ['xem_chung_tu' => 'xem_phieu_nhap_xuat'];

    public function __construct(private Db $db, private array $config) {}

    /** Tạo CSDL nếu chưa có. @return bool true nếu vừa tạo mới */
    public static function ensureDatabase(array $config): bool
    {
        $admin = Db::connect(['host' => $config['host'], 'port' => $config['port'], 'user' => $config['user'], 'password' => $config['password'], 'database' => 'information_schema']);
        $name = str_replace('`', '', $config['database']);
        $exists = $admin->value('SELECT 1 FROM information_schema.schemata WHERE schema_name = ?', [$name]);
        if (!$exists) {
            $admin->raw("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            return true;
        }
        return false;
    }

    /**
     * So CSDL hiện tại với chuẩn. @return array{missing:list<string>,extra:list<string>}
     * missing = chuẩn có mà máy này thiếu/khác · extra = máy này thừa/khác so với chuẩn.
     */
    public function diff(): array
    {
        $expected = $this->expectedSnapshot();
        $actual = self::snapshot($this->db);
        return ['missing' => array_values(array_diff($expected, $actual)), 'extra' => array_values(array_diff($actual, $expected))];
    }

    /**
     * Kiểm tra (và sửa nếu $apply). @return list<string> dòng thông báo
     */
    public function run(bool $apply): array
    {
        $log = [];
        $d = $this->diff();
        if (!$d['missing'] && !$d['extra']) {
            return ['✔ CSDL `' . $this->config['database'] . '` đã đúng chuẩn của dự án — không cần sửa.'];
        }
        $log[] = '✘ CSDL `' . $this->config['database'] . '` CHƯA đúng chuẩn:';
        foreach (['missing' => 'thiếu/khác', 'extra' => 'thừa/khác '] as $key => $label) {
            foreach (array_slice($d[$key], 0, 12) as $m) {
                $log[] = "   - $label : " . self::describe($m);
            }
            if (count($d[$key]) > 12) {
                $log[] = '   … và ' . (count($d[$key]) - 12) . " mục $label khác";
            }
        }
        if (!$apply) {
            $log[] = '→ Chạy `bin/stockflow db-sync` (không kèm --check) để tự sửa.';
            return $log;
        }

        $tables = $this->tables();
        $onlyAdditive = !$d['extra'] && !array_filter($d['missing'], fn($m) => $m[0] === 'C');
        if (!$tables || $onlyAdditive) {
            $log[] = '→ Chỉ thiếu thành phần mới: bổ sung bằng migrate (không đụng dữ liệu).';
            foreach ((new Migrator($this->db))->migrate() as $line) {
                $log[] = '   ' . $line;
            }
        } else {
            array_push($log, ...$this->rebuild($tables));
        }

        $after = $this->diff();
        if ($after['missing'] || $after['extra']) {
            $log[] = '✘ Sau khi sửa vẫn còn khác biệt — gửi nội dung này cho người phụ trách:';
            foreach (array_merge($after['missing'], $after['extra']) as $m) {
                $log[] = '   - ' . self::describe($m);
            }
        } else {
            $log[] = '✔ Đã sửa xong: CSDL đúng chuẩn của dự án.';
        }
        return $log;
    }

    /** Sao lưu → dựng lại đúng chuẩn → nạp lại dữ liệu. @param list<string> $tables @return list<string> */
    private function rebuild(array $tables): array
    {
        $log = [];
        $dir = Config::root() . '/.local/backup-' . date('Ymd-His');
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("Không tạo được thư mục sao lưu $dir");
        }
        $counts = [];
        foreach ($tables as $t) {
            $fh = fopen("$dir/$t.ndjson", 'wb');
            $n = 0;
            foreach ($this->db->all('SELECT * FROM `' . $t . '`') as $row) {
                fwrite($fh, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
                $n++;
            }
            fclose($fh);
            $counts[$t] = $n;
        }
        $log[] = '→ Đã sao lưu ' . array_sum($counts) . ' dòng của ' . count($tables) . ' bảng vào ' . str_replace(Config::root() . '/', '', $dir) . '/';

        $this->db->raw('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($tables as $t) {
                $this->db->raw('DROP TABLE IF EXISTS `' . $t . '`');
            }
            foreach ((new Migrator($this->db))->migrate() as $line) {
                $log[] = '   ' . $line;
            }
            $newTables = $this->tables();
            $restored = 0;
            foreach ($tables as $old) {
                $new = self::TABLE_RENAMES[$old] ?? $old;
                if (!in_array($new, $newTables, true)) {
                    $log[] = "   ⚠ Bảng cũ `$old` không còn trong chuẩn — dữ liệu vẫn nằm trong bản sao lưu.";
                    continue;
                }
                $newCols = array_column($this->db->all('SELECT column_name AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?', [$new]), 'c');
                $newCols = array_map('strtolower', $newCols);
                $fh = fopen("$dir/$old.ndjson", 'rb');
                $skippedCols = [];
                while (($line = fgets($fh)) !== false) {
                    $row = json_decode($line, true);
                    $ins = [];
                    foreach ($row as $col => $val) {
                        $c = self::renameColumn((string)$col);
                        if (!in_array(strtolower($c), $newCols, true)) {
                            $skippedCols[$col] = true;
                            continue;
                        }
                        $ins[$c] = is_string($val) ? (self::VALUE_RENAMES[$val] ?? $val) : $val;
                    }
                    if (!$ins) {
                        continue;
                    }
                    $this->db->exec('INSERT INTO `' . $new . '` (`' . implode('`,`', array_keys($ins)) . '`) VALUES (' . implode(',', array_fill(0, count($ins), '?')) . ')', array_values($ins));
                    $restored++;
                }
                fclose($fh);
                if ($skippedCols) {
                    $log[] = "   ⚠ `$old`: bỏ cột không còn trong chuẩn (" . implode(', ', array_keys($skippedCols)) . ') — vẫn còn trong bản sao lưu.';
                }
                $have = (int)$this->db->value('SELECT COUNT(*) FROM `' . $new . '`');
                if ($have !== $counts[$old]) {
                    $log[] = "   ✘ `$new`: nạp $have/{$counts[$old]} dòng — kiểm tra bản sao lưu.";
                }
            }
            $log[] = "→ Đã nạp lại $restored dòng dữ liệu cũ vào cấu trúc chuẩn.";
        } catch (Throwable $e) {
            $log[] = '✘ Lỗi khi dựng lại: ' . $e->getMessage() . "\n   Dữ liệu cũ còn nguyên trong " . str_replace(Config::root() . '/', '', $dir) . '/ (mỗi bảng một file .ndjson).';
            throw new RuntimeException(implode("\n", $log), 0, $e);
        } finally {
            $this->db->raw('SET FOREIGN_KEY_CHECKS = 1');
        }
        return $log;
    }

    private static function renameColumn(string $col): string
    {
        return str_replace('chung_tu', 'phieu_nhap_xuat', $col);
    }

    /** @return list<string> */
    private function tables(): array
    {
        return array_column($this->db->all("SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name"), 't');
    }

    /** Dựng chuẩn trên CSDL tạm rồi chụp cấu trúc; xoá CSDL tạm ngay sau đó. @return list<string> */
    private function expectedSnapshot(): array
    {
        $scratch = str_replace('`', '', $this->config['database']) . '__chuan_tam';
        $admin = Db::connect(['host' => $this->config['host'], 'port' => $this->config['port'], 'user' => $this->config['user'], 'password' => $this->config['password'], 'database' => 'information_schema']);
        try {
            $admin->raw("DROP DATABASE IF EXISTS `$scratch`");
            $admin->raw("CREATE DATABASE `$scratch` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (Throwable $e) {
            throw new RuntimeException('Không tạo được CSDL tạm để so chuẩn (tài khoản cần quyền CREATE/DROP): ' . $e->getMessage(), 0, $e);
        }
        try {
            $tmp = Db::connect([...$this->config, 'database' => $scratch]);
            (new Migrator($tmp))->migrate();
            return self::snapshot($tmp);
        } finally {
            $admin->raw("DROP DATABASE IF EXISTS `$scratch`");
        }
    }

    /** Mô tả cấu trúc dạng tập chuỗi để so sánh: T=bảng · C=cột · X=ràng buộc · G=trigger. @return list<string> */
    private static function snapshot(Db $db): array
    {
        $out = [];
        foreach ($db->all("SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'") as $r) {
            $out[] = 'T ' . $r['t'];
        }
        foreach ($db->all('SELECT table_name AS t, column_name AS c, column_type AS ty, is_nullable AS n FROM information_schema.columns WHERE table_schema = DATABASE()') as $r) {
            if (!in_array('T ' . $r['t'], $out, true)) {
                continue;   // cột của view
            }
            $out[] = "C {$r['t']}.{$r['c']} {$r['ty']} " . ($r['n'] === 'YES' ? 'NULL' : 'NOT NULL');
        }
        foreach ($db->all('SELECT table_name AS t, constraint_name AS c, constraint_type AS ty FROM information_schema.table_constraints WHERE table_schema = DATABASE()') as $r) {
            $out[] = "X {$r['t']}.{$r['c']} {$r['ty']}";
        }
        foreach ($db->all('SELECT trigger_name AS t, event_object_table AS o FROM information_schema.triggers WHERE trigger_schema = DATABASE()') as $r) {
            $out[] = "G {$r['t']} trên {$r['o']}";
        }
        return array_values(array_unique($out));
    }

    private static function describe(string $s): string
    {
        $kind = ['T' => 'bảng', 'C' => 'cột', 'X' => 'ràng buộc', 'G' => 'trigger'][$s[0]] ?? '';
        return "$kind " . substr($s, 2);
    }
}
