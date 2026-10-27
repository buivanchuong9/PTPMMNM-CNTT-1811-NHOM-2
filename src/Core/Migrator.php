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
        foreach (self::ADDED_COLUMNS as [$table, $column, $definition]) {
            if ($this->addColumnIfMissing($table, $column, $definition)) {
                $log[] = "✔ Đã thêm cột $table.$column";
            }
        }
        $this->ensureBarcodeIndex();
        $this->backfillDriverWarehouses();
        if ($this->widenTripStatus()) {
            $log[] = '✔ Đã thêm trạng thái LOST, RECALLED cho chuyen_van_chuyen.trang_thai';
        }
        if ($n = $this->moveDefaultPrices()) {
            $log[] = "✔ Đã chuyển giá bán mặc định cũ thành giá riêng của $n dòng (kho × mặt hàng)";
        }
        return $log;
    }

    /** Cột thêm ở các phiên bản sau: [bảng, cột, định nghĩa]. Bảng mới dựng từ 001_schema.sql đã có sẵn cột này. */
    private const ADDED_COLUMNS = [
        ['nguoi_dung', 'anh_dai_dien', 'varchar(255) NULL'],
        ['mat_hang', 'danh_muc_id', 'integer NULL'],
        ['mat_hang', 'gia_ban', 'integer NOT NULL DEFAULT 0'],
        ['mat_hang', 'thue_suat', 'integer NOT NULL DEFAULT 10'],
        ['mat_hang', 'gia_von', 'integer NOT NULL DEFAULT 0'],
        ['mat_hang', 'ma_vach', 'varchar(64) NULL'],
        ['lich_su_gia', 'kho_id', 'integer NULL'],
        ['chi_tiet_phieu_nhap_xuat', 'don_gia', 'integer NULL'],
        ['chi_tiet_phieu_nhap_xuat', 'thue_suat', 'integer NOT NULL DEFAULT 0'],
        ['chi_tiet_phieu_nhap_xuat', 'gia_von', 'integer NOT NULL DEFAULT 0'],
        ['tai_xe', 'diem', 'integer NOT NULL DEFAULT 100'],
        ['tai_xe', 'luong', 'integer NOT NULL DEFAULT 0'],
        ['tai_xe', 'kho_id', 'integer NULL'],
        ['that_thoat_hang', 'loai', "varchar(12) NOT NULL DEFAULT 'MAT_CHUYEN'"],
    ];

    /** Mã vạch không trùng giữa các mặt hàng (nhiều mặt hàng chưa có mã vẫn được: NULL không tính trùng). */
    private function ensureBarcodeIndex(): void
    {
        if ($this->db->isSqlite()) {
            $this->db->raw('CREATE UNIQUE INDEX IF NOT EXISTS mat_hang_ma_vach_uq ON mat_hang (ma_vach)');
        } elseif (!$this->db->value("SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'mat_hang' AND index_name = 'mat_hang_ma_vach_uq'")) {
            $this->db->raw('CREATE UNIQUE INDEX mat_hang_ma_vach_uq ON mat_hang (ma_vach)');
        }
    }

    /** Tài xế cũ chưa có kho quản lý: lấy kho gốc của xe đang phụ trách, hoặc của xe họ chạy chuyến gần nhất. Chạy lại an toàn. */
    private function backfillDriverWarehouses(): void
    {
        $this->db->raw('UPDATE tai_xe SET kho_id = (SELECT x.kho_goc_id FROM xe x WHERE x.tai_xe_id = tai_xe.id)
                        WHERE kho_id IS NULL AND EXISTS (SELECT 1 FROM xe x WHERE x.tai_xe_id = tai_xe.id)');
        $this->db->raw('UPDATE tai_xe SET kho_id = (SELECT x.kho_goc_id FROM chuyen_van_chuyen c JOIN xe x ON x.id = c.xe_id WHERE c.tai_xe_id = tai_xe.id ORDER BY c.id DESC LIMIT 1)
                        WHERE kho_id IS NULL AND EXISTS (SELECT 1 FROM chuyen_van_chuyen c WHERE c.tai_xe_id = tai_xe.id)');
    }

    /** MySQL: bảng chuyến cũ chưa có trạng thái LOST (mất hàng) / RECALLED (thu hồi chuyến) ⇒ mở rộng enum tại chỗ. SQLite chỉ dựng đúng từ đầu (CHECK không sửa được). */
    private function widenTripStatus(): bool
    {
        if ($this->db->isSqlite()) {
            return false;
        }
        $type = (string)$this->db->value("SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'chuyen_van_chuyen' AND column_name = 'trang_thai'");
        if ($type === '' || str_contains($type, 'RECALLED')) {
            return false;
        }
        $this->db->raw("ALTER TABLE chuyen_van_chuyen MODIFY trang_thai enum('IN_TRANSIT','DELIVERED','LOST','RECALLED') NOT NULL DEFAULT 'IN_TRANSIT'");
        return true;
    }

    /**
     * Bỏ «giá bán mặc định»: giá cũ ở mat_hang.gia_ban được sao thành giá riêng của từng kho chưa có giá (gia_ban_kho), rồi đặt về 0.
     * Phải chạy SAU khi cột đã được thêm (nên không nằm trong file .sql). Chạy lại an toàn: hết giá mặc định thì không làm gì.
     * @return int số dòng giá riêng vừa tạo
     */
    private function moveDefaultPrices(): int
    {
        if (!$this->db->value('SELECT COUNT(*) FROM mat_hang WHERE gia_ban > 0')) {
            return 0;
        }
        $before = (int)$this->db->value('SELECT COUNT(*) FROM gia_ban_kho');
        $this->db->raw('INSERT INTO gia_ban_kho (kho_id, mat_hang_id, gia_ban) SELECT k.id, i.id, i.gia_ban FROM kho k JOIN mat_hang i ON i.gia_ban > 0
                        WHERE NOT EXISTS (SELECT 1 FROM gia_ban_kho g WHERE g.kho_id = k.id AND g.mat_hang_id = i.id)');
        $this->db->raw('UPDATE mat_hang SET gia_ban = 0 WHERE gia_ban > 0');
        return (int)$this->db->value('SELECT COUNT(*) FROM gia_ban_kho') - $before;
    }

    private function addColumnIfMissing(string $table, string $column, string $definition): bool
    {
        if ($this->db->isSqlite()) {
            $cols = array_column($this->db->all("PRAGMA table_info($table)"), 'name');
            if (!$cols || in_array($column, $cols, true)) {
                return false;
            }
            $this->db->raw("ALTER TABLE $table ADD COLUMN $column " . preg_replace('/varchar\(\d+\)/', 'text', $definition));
            return true;
        }
        if ($this->db->value('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$table, $column])) {
            return false;
        }
        $this->db->raw("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        return true;
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
