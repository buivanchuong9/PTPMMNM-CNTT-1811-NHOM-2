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
                if ($table === 'lo_hang' && $column === 'bao_hanh_thang') {   // lô có từ trước: lấy thời hạn bảo hành của mặt hàng làm mặc định
                    $this->db->raw('UPDATE lo_hang SET bao_hanh_thang = (SELECT i.bao_hanh_thang FROM mat_hang i WHERE i.id = lo_hang.mat_hang_id)');
                }
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
        ['mat_hang', 'bao_hanh_thang', 'integer NOT NULL DEFAULT 0'],
        ['lo_hang', 'bao_hanh_thang', 'integer NOT NULL DEFAULT 0'],   // thời hạn bảo hành (tháng) của từng lô hàng; lô mới lấy mặc định từ mặt hàng
        ['phieu_nhap_xuat', 'don_dat_hang_id', 'integer NULL'],   // phiếu nhập theo đơn đặt hàng nhà cung cấp
        ['chi_tiet_tra_hang_khach', 'don_gia', 'bigint NOT NULL DEFAULT 0'],   // giá khách đã trả cho 1 đơn vị (đã gồm thuế), chốt từ phiếu bán lúc lập phiếu trả
        ['chi_tiet_tra_hang_khach', 'tien_tru_khach', 'bigint NOT NULL DEFAULT 0'],   // trừ vào tiền hoàn khi khách làm hỏng
        ['chi_tiet_tra_hang_khach', 'tien_den_bu', 'bigint NOT NULL DEFAULT 0'],   // giá vốn hàng hỏng mà bên chịu trách nhiệm (tài xế / nhân viên kho) phải đền
        ['chi_tiet_tra_hang_khach', 'tai_xe_id', 'integer NULL'],   // lỗi vận chuyển: tài xế chịu
        ['chi_tiet_tra_hang_khach', 'nguoi_chiu_id', 'integer NULL'],   // lỗi do kho: nhân viên chịu
        ['chi_tiet_tra_hang_khach', 'bao_hanh_id', 'integer NULL'],   // lỗi nhà sản xuất: phiếu kho bảo hành đã mở
        ['tra_hang_khach', 'tien_hoan', 'bigint NOT NULL DEFAULT 0'],   // số tiền hoàn lại cho khách
        ['tra_hang_khach', 'hinh_thuc_hoan', "varchar(20) NOT NULL DEFAULT 'TIEN_MAT'"],
        ['bao_hanh', 'san_pham_ban_id', 'bigint NULL'],   // phiếu bảo hành nhận theo mã bán hàng (san_pham_ban)
        ['bao_hanh', 'ma_lo', 'varchar(20) NULL'],   // phiếu nhận CẢ LÔ (chỉ nhập mã lô hàng): so_luong = số chiếc trong lô, san_pham_ban_id để trống
        ['bao_hanh', 'ngay_nhan', 'date NULL'],   // ngày khách mang hàng đến (mặc định hôm nay)
        ['bao_hanh', 'ngay_hen_tra', 'date NULL'],   // ngày hẹn trả khách (ước lượng)
        ['bao_hanh', 'khach_dia_chi', "varchar(255) NOT NULL DEFAULT ''"],
        ['phieu_nhap_xuat', 'cap_lai_cho_id', 'integer NULL'],
        ['giao_hang', 'thanh_toan', "varchar(6) NOT NULL DEFAULT 'DA_TT'"],
        ['giao_hang', 'phuong_thuc_tt', "varchar(12) NOT NULL DEFAULT ''"],
        ['giao_hang', 'so_tien', 'bigint NOT NULL DEFAULT 0'],
        ['giao_hang', 'nguoi_nhan', "varchar(120) NOT NULL DEFAULT ''"],
        ['giao_hang', 'sdt_nhan', "varchar(15) NOT NULL DEFAULT ''"],
        ['doi_tac', 'duyet', "varchar(10) NOT NULL DEFAULT 'DA_DUYET'"],   // DA_DUYET | CHO_DUYET: đối tác do quản lý kho thêm phải chờ quản trị duyệt
        ['doi_tac', 'kho_id', 'integer NULL'],   // kho phụ trách khách hàng: kho gần nhất theo địa chỉ
        ['giao_hang', 'ngay_giao_den', 'timestamp NULL'],   // tài xế đã giao tới nơi (NULL = đang giao; khách tự lấy không dùng)
        ['kho', 'gia_km_noi_bo', 'integer NOT NULL DEFAULT 8000'],
        ['kho', 'gia_km_thue_ngoai', 'integer NOT NULL DEFAULT 15000'],
        ['xe', 'thue_ngoai', 'integer NOT NULL DEFAULT 0'],
        ['tai_xe', 'thue_ngoai', 'integer NOT NULL DEFAULT 0'],
        ['chuyen_van_chuyen', 'hinh_thuc', "varchar(10) NOT NULL DEFAULT 'NOI_BO'"],
        ['chuyen_van_chuyen', 'km', 'integer NOT NULL DEFAULT 0'],
        ['chuyen_van_chuyen', 'don_gia_km', 'integer NOT NULL DEFAULT 0'],
        ['chuyen_van_chuyen', 'tien_goi_y', 'bigint NOT NULL DEFAULT 0'],
        ['chuyen_van_chuyen', 'tien_tra', 'bigint NOT NULL DEFAULT 0'],
        ['chuyen_van_chuyen', 'don_vi_van_tai', "varchar(120) NOT NULL DEFAULT ''"],
        ['chuyen_van_chuyen', 'ghi_chu_gia', "varchar(255) NOT NULL DEFAULT ''"],
        ['kho', 'noi_tieu_huy', "varchar(120) NOT NULL DEFAULT ''"],   // nơi nhận hàng tiêu huỷ của kho (trống = dùng nơi mặc định)
        ['kho', 'dia_chi_tieu_huy', "varchar(255) NOT NULL DEFAULT ''"],
        ['huy_hang', 'xe_id', 'integer NULL'],   // xe chở hàng đi tiêu huỷ (NULL = còn nằm trong kho huỷ)
        ['huy_hang', 'tai_xe_id', 'integer NULL'],
        ['huy_hang', 'ngay_xuat', 'timestamp NULL'],
        ['huy_hang', 'don_vi_tieu_huy', "varchar(120) NOT NULL DEFAULT ''"],   // nơi nhận hàng về tiêu huỷ
        ['huy_hang', 'dia_chi_tieu_huy', "varchar(255) NOT NULL DEFAULT ''"],
        ['bao_hanh', 'phieu_mua_id', 'bigint NULL'],   // phiếu xuất bán chứng minh khách đã mua hàng
        ['bao_hanh', 'nha_san_xuat', "varchar(255) NOT NULL DEFAULT ''"],   // nhà sản xuất / nhà cung cấp của mặt hàng lúc nhận bảo hành
        ['chuyen_van_chuyen', 'tien_coc', 'bigint NOT NULL DEFAULT 0'],   // tiền cọc giá trị hàng thu của đơn vị vận tải thuê ngoài
        ['chuyen_van_chuyen', 'phuong_thuc_coc', "varchar(12) NOT NULL DEFAULT ''"],
        ['giao_hang', 'tien_coc', 'bigint NOT NULL DEFAULT 0'],
        ['giao_hang', 'phuong_thuc_coc', "varchar(12) NOT NULL DEFAULT ''"],
        ['chi_tiet_kiem_ke', 'so_luong_hong', 'integer NOT NULL DEFAULT 0'],
        ['chi_tiet_kiem_ke', 'ly_do_hong', "varchar(20) NOT NULL DEFAULT ''"],
        ['chi_tiet_kiem_ke', 'ghi_chu_hong', "varchar(255) NOT NULL DEFAULT ''"],
        ['lich_su_gia', 'kho_id', 'integer NULL'],
        // tải trọng, kích thước, bằng lái (016): hàng khai theo 1 đơn vị tính (gram, mm); xe khai thông số kỹ thuật; tài xế khai giấy phép lái xe
        ['mat_hang', 'khoi_luong_g', 'integer NOT NULL DEFAULT 0'],
        ['mat_hang', 'dai_mm', 'integer NOT NULL DEFAULT 0'],
        ['mat_hang', 'rong_mm', 'integer NOT NULL DEFAULT 0'],
        ['mat_hang', 'cao_mm', 'integer NOT NULL DEFAULT 0'],
        // 1 kiện (thùng đóng sẵn = quy_cach_lo đơn vị): cân nặng cả bì (gram, 0 = tự tính) và kích thước (mm)
        ['mat_hang', 'kien_g', 'integer NOT NULL DEFAULT 0'],
        ['mat_hang', 'kien_dai_mm', 'integer NOT NULL DEFAULT 0'],
        ['mat_hang', 'kien_rong_mm', 'integer NOT NULL DEFAULT 0'],
        ['mat_hang', 'kien_cao_mm', 'integer NOT NULL DEFAULT 0'],
        ['xe', 'tai_trong_kg', 'integer NOT NULL DEFAULT 0'],
        ['xe', 'gvw_kg', 'integer NOT NULL DEFAULT 0'],
        ['xe', 'ro_mooc_kg', 'integer NOT NULL DEFAULT 0'],
        ['xe', 'thung_dai_mm', 'integer NOT NULL DEFAULT 0'],
        ['xe', 'thung_rong_mm', 'integer NOT NULL DEFAULT 0'],
        ['xe', 'thung_cao_mm', 'integer NOT NULL DEFAULT 0'],
        ['xe', 'loai_thung', "varchar(12) NOT NULL DEFAULT ''"],
        ['xe', 'tong_dai_mm', 'integer NOT NULL DEFAULT 0'],
        ['xe', 'tong_rong_mm', 'integer NOT NULL DEFAULT 0'],
        ['xe', 'tong_cao_mm', 'integer NOT NULL DEFAULT 0'],
        ['xe', 'so_truc', 'integer NOT NULL DEFAULT 0'],
        ['xe', 'han_dang_kiem', 'date NULL'],
        ['xe', 'han_bao_hiem', 'date NULL'],
        // kiểu xe: MAY (xe máy giao hàng) · OTO_NHO (ô tô con, bán tải, van) · TAI (xe tải); dung tích xi-lanh (cm³) của xe máy quyết định bằng A1 hay A
        ['xe', 'kieu_xe', "varchar(8) NOT NULL DEFAULT 'TAI'"],
        ['xe', 'dung_tich_cc', 'integer NOT NULL DEFAULT 0'],
        ['tai_xe', 'ngay_sinh', 'date NULL'],
        ['tai_xe', 'so_gplx', "varchar(20) NOT NULL DEFAULT ''"],
        ['tai_xe', 'hang_gplx', "varchar(4) NOT NULL DEFAULT ''"],
        ['tai_xe', 'ngay_cap_gplx', 'date NULL'],
        ['tai_xe', 'han_gplx', 'date NULL'],
        ['tai_xe', 'noi_cap_gplx', "varchar(120) NOT NULL DEFAULT ''"],
        ['tai_xe', 'anh_gplx', 'varchar(255) NULL'],
        ['tai_xe', 'han_kham_sk', 'date NULL'],
        ['tai_xe', 'diem_gplx', 'integer NOT NULL DEFAULT 12'],
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
