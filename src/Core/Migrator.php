<?php
declare(strict_types=1);

namespace StockFlow\Core;

use mysqli_sql_exception;

/** Áp dụng database/migrations/*.sql (các câu lệnh ngăn cách bởi dòng "-- @@"). Migration *triggers* là tuỳ chọn. */
final class Migrator
{
    public function __construct(private Db $db) {}

    /** Trigger bất biến bản SQLite (tương đương migration 002 của MySQL). */
    private const SQLITE_TRIGGERS = [
        "CREATE TRIGGER IF NOT EXISTS so_phat_sinh_khong_sua BEFORE UPDATE ON so_phat_sinh BEGIN SELECT RAISE(ABORT, 'stock_movements is append-only'); END",
        "CREATE TRIGGER IF NOT EXISTS so_phat_sinh_khong_xoa BEFORE DELETE ON so_phat_sinh BEGIN SELECT RAISE(ABORT, 'stock_movements is append-only'); END",
        "CREATE TRIGGER IF NOT EXISTS phieu_nhap_xuat_khong_xoa BEFORE DELETE ON phieu_nhap_xuat BEGIN SELECT RAISE(ABORT, 'vouchers cannot be deleted; cancel or correct them instead'); END",
        "CREATE TRIGGER IF NOT EXISTS phieu_nhap_xuat_da_duyet_khoa BEFORE UPDATE ON phieu_nhap_xuat WHEN OLD.trang_thai = 'CONFIRMED' BEGIN SELECT RAISE(ABORT, 'confirmed vouchers are immutable; issue an adjustment voucher'); END",
        "CREATE TRIGGER IF NOT EXISTS chi_tiet_phieu_nhap_xuat_khong_xoa BEFORE DELETE ON chi_tiet_phieu_nhap_xuat BEGIN SELECT RAISE(ABORT, 'voucher lines cannot be deleted'); END",
        "CREATE TRIGGER IF NOT EXISTS chi_tiet_phieu_nhap_xuat_khong_sua BEFORE UPDATE ON chi_tiet_phieu_nhap_xuat BEGIN SELECT RAISE(ABORT, 'voucher lines are immutable'); END",
    ];

    /**
     * Dịch một câu CREATE TABLE của MySQL sang SQLite (một nguồn schema duy nhất cho cả hai driver).
     * @return list<string> CREATE TABLE + các CREATE INDEX tách riêng
     */
    public static function toSqlite(string $sql): array
    {
        if (!preg_match('/^\s*CREATE TABLE IF NOT EXISTS (\w+)/', $sql, $m)) {
            return [$sql];
        }
        $table = $m[1];
        $indexes = [];
        $sql = preg_replace_callback('/,\s*INDEX (\w+) \(([^)]*)\)/', function ($i) use (&$indexes, $table) {
            $indexes[] = "CREATE INDEX IF NOT EXISTS {$i[1]} ON $table ({$i[2]})";
            return '';
        }, $sql);
        $sql = preg_replace('/\b(?:integer|bigint)\s+NOT NULL\s+AUTO_INCREMENT\s+PRIMARY KEY/', 'integer PRIMARY KEY AUTOINCREMENT', $sql);
        $sql = preg_replace('/(\w+)\s+enum\(([^)]*)\)/', '$1 text CHECK ($1 IN ($2))', $sql);
        $sql = preg_replace(['/\bbigint\b/', '/\bvarchar\(\d+\)/', '/\bboolean\b/', '/\btimestamp\b/', '/\bdate\b(?! *\()/', '/\bDEFAULT TRUE\b/', '/\bDEFAULT FALSE\b/'],
            ['integer', 'text', 'integer', 'text', 'text', 'DEFAULT 1', 'DEFAULT 0'], $sql);
        $sql = preg_replace('/\)\s*ENGINE=.*$/s', ')', $sql);
        return [$sql, ...$indexes];
    }

    /** @return list<string> thông điệp tiến trình / cảnh báo */
    public function migrate(): array
    {
        $log = [];
        foreach (glob(Config::root() . '/database/migrations/*.sql') ?: [] as $file) {
            $optional = str_contains(basename($file), 'triggers');
            $applied = 0;
            if ($optional && $this->db->isSqlite()) {
                foreach (self::SQLITE_TRIGGERS as $stmt) {
                    $this->db->raw($stmt);
                    $applied++;
                }
                $log[] = '✔ ' . basename($file) . " ($applied trigger SQLite)";
                continue;
            }
            foreach (preg_split('/^-- @@\s*$/m', (string)file_get_contents($file)) ?: [] as $chunk) {
                $stmt = trim((string)preg_replace('/^\s*--.*$/m', '', $chunk));
                if ($stmt === '') {
                    continue;
                }
                try {
                    foreach ($this->db->isSqlite() ? self::toSqlite($stmt) : [$stmt] as $one) {
                        $this->db->raw($one);
                    }
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
        // SQLite (chế độ demo) không tự thêm cột vào bảng đã có: bổ sung cột mới của các phiên bản sau
        if ($this->db->isSqlite()) {
            $cols = array_column($this->db->all('PRAGMA table_info(mat_hang)'), 'name');
            if ($cols && !in_array('quy_cach_lo', $cols, true)) {
                $this->db->raw('ALTER TABLE mat_hang ADD COLUMN quy_cach_lo integer NOT NULL DEFAULT 1');
                $log[] = '✔ Đã thêm cột mat_hang.quy_cach_lo (SQLite)';
            }
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
