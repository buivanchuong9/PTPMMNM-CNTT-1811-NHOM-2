<?php
declare(strict_types=1);

namespace StockFlow\Core;

use mysqli_sql_exception;

/** Áp dụng database/migrations/*.sql (các câu lệnh ngăn cách bởi dòng "-- @@"). Migration *triggers* là tuỳ chọn. */
final class Migrator
{
    public function __construct(private Db $db) {}

    /** @return list<string> thông điệp tiến trình / cảnh báo */
    public function migrate(): array
    {
        $log = [];
        foreach (glob(Config::root() . '/database/migrations/*.sql') ?: [] as $file) {
            $optional = str_contains(basename($file), 'triggers');
            $applied = 0;
            foreach (preg_split('/^-- @@\s*$/m', (string)file_get_contents($file)) ?: [] as $chunk) {
                $stmt = trim((string)preg_replace('/^\s*--.*$/m', '', $chunk));
                if ($stmt === '') {
                    continue;
                }
                try {
                    $this->db->raw($stmt);
                    $applied++;
                } catch (mysqli_sql_exception $e) {
                    if (!$optional) {
                        throw $e;
                    }
                    $log[] = '⚠ Bỏ qua ' . basename($file) . ': ' . $e->getMessage()
                        . "\n  (Tuỳ chọn. Để bật: GRANT TRIGGER cho tài khoản và/hoặc SET GLOBAL log_bin_trust_function_creators = 1.)";
                    continue 2;
                }
            }
            $log[] = '✔ ' . basename($file) . " ($applied câu lệnh)";
        }
        return $log;
    }

    /** XOÁ MỌI BẢNG trong CSDL hiện tại — chỉ dùng cho CSDL kiểm thử. */
    public function dropAll(): void
    {
        $this->db->raw('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->db->all('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()') as $r) {
            $this->db->raw('DROP TABLE IF EXISTS `' . str_replace('`', '', $r['t'] ?? $r['TABLE_NAME'] ?? '') . '`');
        }
        $this->db->raw('SET FOREIGN_KEY_CHECKS = 1');
    }
}
