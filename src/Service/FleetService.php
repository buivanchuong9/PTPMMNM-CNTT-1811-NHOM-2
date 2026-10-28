<?php
declare(strict_types=1);

namespace StockFlow\Service;

use StockFlow\Core\Db;
use StockFlow\Domain\DomainError;
use StockFlow\Domain\Permission;
use StockFlow\Domain\User;

/**
 * Đội xe & tài xế: do QUẢN LÝ TỪNG KHO quản lý (xe và tài xế thuộc «kho gốc» của mình); tài khoản quản trị chỉ xem toàn hệ thống.
 * Mỗi kho là «kho gốc» của bao nhiêu xe cũng được (kho tự thuê thêm, huỷ, đổi xe); xe luôn ở một kho hoặc đang chạy tới kho khác. Tài xế đi theo xe; chuyến thuộc kho gốc của xe.
 * Các hàm tra cứu nhận $wh = id kho để chỉ lấy đội xe của kho đó (null = mọi kho, dành cho quản trị).
 */
final class FleetService
{
    public function __construct(private Db $db, private Authorizer $auth, private AuditLog $audit) {}

    // ───── phạm vi kho ─────

    /** Chỉ quản lý của kho đó (có quyền đội xe) mới thao tác được; tài khoản quản trị chỉ xem nên bị từ chối. */
    private function requireHome(User $actor, int $warehouseId): void
    {
        $this->auth->require($actor, $warehouseId, Permission::QuanLyDoiXe);
    }

    /** Xe thuộc kho gốc của người thao tác. @return array<string,mixed> */
    private function ownVehicle(User $actor, int $vehicleId): array
    {
        $this->auth->requirePermission($actor, Permission::QuanLyDoiXe);
        $v = $this->db->one('SELECT * FROM xe WHERE id = ?', [$vehicleId]) ?? throw new DomainError('Không tìm thấy xe');
        $this->requireHome($actor, (int)$v['kho_goc_id']);
        return $v;
    }

    /** Tài xế do kho của người thao tác quản lý. @return array<string,mixed> */
    private function ownDriver(User $actor, int $driverId): array
    {
        $this->auth->requirePermission($actor, Permission::QuanLyDoiXe);
        $d = $this->db->one('SELECT * FROM tai_xe WHERE id = ?', [$driverId]) ?? throw new DomainError('Không tìm thấy tài xế');
        $this->requireHome($actor, (int)($d['kho_id'] ?? 0));
        return $d;
    }

    /** Tài xế đi theo xe nên thuộc kho gốc của xe. */
    private function adopt(?int $driverId, int $warehouseId): void
    {
        if ($driverId !== null) {
            $this->db->exec('UPDATE tai_xe SET kho_id = ? WHERE id = ?', [$warehouseId, $driverId]);
        }
    }

    // ───── quãng đường & đơn giá ─────

    public const DEFAULT_RATE_OWN = 8000;     // đ/km trả tài xế của kho
    public const DEFAULT_RATE_HIRED = 15000;  // đ/km thuê đơn vị vận tải ngoài
    public const MAX_KM = 5000;
    public const MAX_RATE = 1_000_000;

    /** Đơn giá mỗi km do kho đi đặt (gợi ý khi lập chuyến). @return array{noi_bo:int,thue_ngoai:int} */
    public function rates(int $warehouseId): array
    {
        $r = $this->db->one('SELECT gia_km_noi_bo, gia_km_thue_ngoai FROM kho WHERE id = ?', [$warehouseId]) ?? throw new DomainError('Kho không tồn tại');
        return ['noi_bo' => (int)$r['gia_km_noi_bo'], 'thue_ngoai' => (int)$r['gia_km_thue_ngoai']];
    }

    public function setRates(User $actor, int $warehouseId, int $own, int $hired): void
    {
        $this->requireHome($actor, $warehouseId);
        foreach ([$own, $hired] as $v) {
            if ($v < 0 || $v > self::MAX_RATE) {
                throw new DomainError('Đơn giá mỗi km phải từ 0 đến ' . number_format(self::MAX_RATE, 0, ',', '.') . ' đ');
            }
        }
        $this->db->exec('UPDATE kho SET gia_km_noi_bo = ?, gia_km_thue_ngoai = ? WHERE id = ?', [$own, $hired, $warehouseId]);
        $this->audit->record($actor->id, 'fleet.rates', 'warehouse', $warehouseId, 'nội bộ ' . $own . ' đ/km · thuê ngoài ' . $hired . ' đ/km');
    }

    /** Quãng đường giữa hai kho (km); null nếu chưa khai báo. */
    public function distance(int $a, int $b): ?int
    {
        if ($a === $b) {
            return 0;
        }
        $km = $this->db->value('SELECT km FROM khoang_cach_kho WHERE kho_a_id = ? AND kho_b_id = ?', [min($a, $b), max($a, $b)]);
        return $km === null || $km === false ? null : (int)$km;
    }

    public function setDistance(User $actor, int $mine, int $other, int $km): void
    {
        $this->requireHome($actor, $mine);
        if ($mine === $other || !$this->db->value('SELECT 1 FROM kho WHERE id = ?', [$other])) {
            throw new DomainError('Kho đối ứng không hợp lệ');
        }
        $this->saveDistance($mine, $other, $km);
        $this->audit->record($actor->id, 'fleet.distance', 'warehouse', $mine, "$mine ↔ $other: $km km");
    }

    /** Ghi quãng đường (dùng cả khi lập chuyến mà chưa có số liệu). */
    public function saveDistance(int $a, int $b, int $km): void
    {
        if ($km < 1 || $km > self::MAX_KM) {
            throw new DomainError('Quãng đường phải từ 1 đến ' . self::MAX_KM . ' km');
        }
        [$lo, $hi] = [min($a, $b), max($a, $b)];
        if ($this->db->value('SELECT 1 FROM khoang_cach_kho WHERE kho_a_id = ? AND kho_b_id = ?', [$lo, $hi])) {
            $this->db->exec('UPDATE khoang_cach_kho SET km = ?, ngay_cap_nhat = CURRENT_TIMESTAMP WHERE kho_a_id = ? AND kho_b_id = ?', [$km, $lo, $hi]);
        } else {
            $this->db->exec('INSERT INTO khoang_cach_kho (kho_a_id, kho_b_id, km) VALUES (?,?,?)', [$lo, $hi, $km]);
        }
    }

    /** Quãng đường từ một kho tới mọi kho khác (km = null nếu chưa khai báo). @return list<array{id:int,ma:string,ten:string,km:?int}> */
    public function distancesFrom(int $warehouseId): array
    {
        $out = [];
        foreach ($this->db->all('SELECT id, ma, ten FROM kho WHERE hoat_dong AND id <> ? ORDER BY ma', [$warehouseId]) as $k) {
            $out[] = ['id' => (int)$k['id'], 'ma' => $k['ma'], 'ten' => $k['ten'], 'km' => $this->distance($warehouseId, (int)$k['id'])];
        }
        return $out;
    }

    /**
     * Gợi ý tiền công: km (theo quãng đường đã khai báo) × đơn giá của kho đi theo hình thức.
     * @return array{km:?int,rate:int,amount:?int}
     */
    public function quote(int $fromWh, int $toWh, string $mode, ?int $km = null): array
    {
        $km ??= $this->distance($fromWh, $toWh);
        $rate = $this->rates($fromWh)[$mode === 'THUE_NGOAI' ? 'thue_ngoai' : 'noi_bo'];
        return ['km' => $km, 'rate' => $rate, 'amount' => $km ? $km * $rate : null];
    }

    /**
     * Chuẩn hoá tiền công của một chuyến: quãng đường, đơn giá/km của kho đi, tiền gợi ý (km × đơn giá) và tiền thực trả.
     * $toWh = kho đến (điều chuyển): chưa có quãng đường giữa hai kho thì km nhập tay được ghi nhớ. null = giao cho khách (không có kho đến).
     * @param array<string,mixed> $pay hinh_thuc NOI_BO | THUE_NGOAI, km, tien, ghi_chu, ngoai[company…]
     * @return array{hinh_thuc:string,km:int,don_gia_km:int,tien_goi_y:int,tien_tra:int,don_vi_van_tai:string,ghi_chu_gia:string}
     */
    public function tripPay(int $fromWh, ?int $toWh, array $pay): array
    {
        $mode = ($pay['hinh_thuc'] ?? '') === 'THUE_NGOAI' ? 'THUE_NGOAI' : 'NOI_BO';
        $km = (int)($pay['km'] ?? 0);
        if ($km < 1 || $km > self::MAX_KM) {
            throw new DomainError('Nhập quãng đường của chuyến (từ 1 đến ' . self::MAX_KM . ' km)');
        }
        $q = $this->quote($fromWh, $toWh ?? $fromWh, $mode, $km);
        $paid = (int)($pay['tien'] ?? -1);
        if ($paid < 0 || $paid > 1_000_000_000) {
            throw new DomainError('Số tiền trả cho chuyến không hợp lệ');
        }
        if ($mode === 'THUE_NGOAI' && $paid < 1) {
            throw new DomainError('Thuê ngoài: nhập số tiền thuê vận chuyển');
        }
        if ($toWh !== null && $this->distance($fromWh, $toWh) === null) {
            $this->saveDistance($fromWh, $toWh, $km);
        }
        return ['hinh_thuc' => $mode, 'km' => $km, 'don_gia_km' => $q['rate'], 'tien_goi_y' => $km * $q['rate'], 'tien_tra' => $paid,
                'don_vi_van_tai' => $mode === 'THUE_NGOAI' ? mb_substr(trim((string)($pay['ngoai']['company'] ?? '')), 0, 120) : '',
                'ghi_chu_gia' => mb_substr(trim((string)($pay['ghi_chu'] ?? '')), 0, 255)];
    }

    /**
     * Bàn giao hàng cho đơn vị vận tải bên thứ ba: PHẢI có đủ thông tin đơn vị, tài xế (họ tên, CCCD, điện thoại) và xe (biển số, loại xe).
     * Xe và tài xế thuê ngoài được lưu (đánh dấu thue_ngoai, dùng lại khi trùng biển số / CCCD) để chuyến vẫn có hồ sơ người chở; không hiện trong đội xe của kho.
     * Gọi trong giao dịch xuất kho. @param array<string,string> $c company, driver, cccd, phone, plate, type @return int id xe thuê ngoài đang ở kho đi
     */
    public function registerExternal(int $fromWh, array $c): int
    {
        $company = trim(preg_replace('/\s+/u', ' ', (string)($c['company'] ?? '')) ?? '');
        $name = trim(preg_replace('/\s+/u', ' ', (string)($c['driver'] ?? '')) ?? '');
        $cccd = trim((string)($c['cccd'] ?? ''));
        $phone = preg_replace('/[\s.]/', '', (string)($c['phone'] ?? '')) ?? '';
        $plate = strtoupper(trim(preg_replace('/\s+/', '', (string)($c['plate'] ?? '')) ?? ''));
        $type = trim((string)($c['type'] ?? ''));
        if ($company === '' || mb_strlen($company) > 120) {
            throw new DomainError('Thuê ngoài: nhập tên đơn vị vận tải (tối đa 120 ký tự)');
        }
        if ($name === '' || mb_strlen($name) > 120) {
            throw new DomainError('Thuê ngoài: nhập họ tên tài xế của đơn vị vận tải');
        }
        if (!preg_match('/^[0-9]{12}$/', $cccd)) {
            throw new DomainError('Thuê ngoài: số CCCD của tài xế gồm đúng 12 chữ số');
        }
        if (!preg_match('/^0[0-9]{9}$/', $phone)) {
            throw new DomainError('Thuê ngoài: nhập số điện thoại tài xế (10 chữ số, bắt đầu bằng 0)');
        }
        if (!preg_match('/^[0-9]{2}[A-Z][A-Z0-9]?-?[0-9]{3,5}(\.[0-9]{2})?$/', $plate)) {
            throw new DomainError('Thuê ngoài: biển số xe không hợp lệ (ví dụ 30H-123.45 hoặc 51C-12345)');
        }
        if ($type === '' || mb_strlen($type) > 60) {
            throw new DomainError('Thuê ngoài: nhập loại xe (ví dụ xe tải 2 tấn)');
        }
        $driver = $this->db->one('SELECT * FROM tai_xe WHERE cccd = ?', [$cccd]);
        if ($driver && !(int)$driver['thue_ngoai']) {
            throw new DomainError('CCCD này là của tài xế nội bộ — chọn «Xe của kho» thay vì thuê ngoài');
        }
        $vehicle = $this->db->one('SELECT * FROM xe WHERE bien_so = ?', [$plate]);
        if ($vehicle && !(int)$vehicle['thue_ngoai']) {
            throw new DomainError("Biển số $plate là xe nội bộ — chọn «Xe của kho» thay vì thuê ngoài");
        }
        foreach ([['tai_xe_id', $driver['id'] ?? null, 'Tài xế'], ['xe_id', $vehicle['id'] ?? null, 'Xe']] as [$col, $id, $label]) {
            if ($id !== null && $this->db->value("SELECT 1 FROM chuyen_van_chuyen WHERE $col = ? AND trang_thai = 'IN_TRANSIT'", [$id])) {
                throw new DomainError("$label thuê ngoài này đang chạy một chuyến chưa giao xong");
            }
        }
        if ($driver) {
            $driverId = (int)$driver['id'];
            $this->db->exec('UPDATE tai_xe SET ho_ten = ?, so_dien_thoai = ?, hoat_dong = 1 WHERE id = ?', [$name, $phone, $driverId]);
        } else {
            $driverId = $this->db->insert('INSERT INTO tai_xe (ho_ten, cccd, so_dien_thoai, kho_id, thue_ngoai) VALUES (?,?,?,NULL,1)', [$name, $cccd, $phone]);
        }
        $this->db->exec('UPDATE xe SET tai_xe_id = NULL WHERE tai_xe_id = ? AND thue_ngoai = 1' . ($vehicle ? ' AND id <> ' . (int)$vehicle['id'] : ''), [$driverId]);
        if ($vehicle) {
            $vehicleId = (int)$vehicle['id'];
            $this->db->exec('UPDATE xe SET loai_xe = ?, kho_goc_id = ?, kho_hien_tai_id = ?, dang_den_kho_id = NULL, tai_xe_id = ?, hoat_dong = 1 WHERE id = ?', [$type, $fromWh, $fromWh, $driverId, $vehicleId]);
        } else {
            $vehicleId = $this->db->insert('INSERT INTO xe (bien_so, loai_xe, kho_goc_id, kho_hien_tai_id, tai_xe_id, thue_ngoai) VALUES (?,?,?,?,?,1)', [$plate, $type, $fromWh, $fromWh, $driverId]);
        }
        return $vehicleId;
    }

    // ───── tra cứu ─────

    /** Xe kèm vị trí hiện tại, kho đang tới và tài xế của chuyến đang chạy. @return list<array<string,mixed>> */
    public function vehicles(?int $wh = null): array
    {
        return $this->db->all(
            "SELECT x.*, g.ma AS goc_ma, h.ma AS ht_ma, h.ten AS ht_ten, d.ma AS den_ma, d.ten AS den_ten,
                    t.ho_ten AS tai_xe_ten, t.cccd AS tai_xe_cccd, t.so_dien_thoai AS tai_xe_sdt, t.hoat_dong AS tai_xe_hoat_dong,
                    c.id AS chuyen_id, p.ma AS phieu_ma, p.id AS phieu_id
             FROM xe x JOIN kho g ON g.id = x.kho_goc_id LEFT JOIN kho h ON h.id = x.kho_hien_tai_id LEFT JOIN kho d ON d.id = x.dang_den_kho_id
             LEFT JOIN tai_xe t ON t.id = x.tai_xe_id
             LEFT JOIN chuyen_van_chuyen c ON c.xe_id = x.id AND c.trang_thai = 'IN_TRANSIT'
             LEFT JOIN phieu_nhap_xuat p ON p.id = c.phieu_di_id
             WHERE x.thue_ngoai = 0" . ($wh !== null ? ' AND x.kho_goc_id = ' . (int)$wh : '') . "
             ORDER BY g.ma, x.bien_so");
    }

    /** Tài xế kèm chuyến đang chạy (nếu có). @return list<array<string,mixed>> */
    public function drivers(?int $wh = null, ?string $month = null): array
    {
        return $this->db->all(
            "SELECT t.*, k.ma AS kho_ma, v.id AS xe_phu_trach_id, v.bien_so AS xe_phu_trach, c.id AS chuyen_id, p.ma AS phieu_ma, p.id AS phieu_id,
                    (SELECT COUNT(*) FROM that_thoat_hang h WHERE h.tai_xe_id = t.id AND h.nguoi_chiu = 'TAI_XE') AS so_vu_mat,
                    (SELECT COALESCE(SUM(h.tien_tru_luong), 0) FROM that_thoat_hang h WHERE h.tai_xe_id = t.id AND h.ngay_tao >= ? AND h.ngay_tao < ?) AS tru_thang,
                    (SELECT COUNT(*) FROM chuyen_van_chuyen z WHERE z.tai_xe_id = t.id AND z.hinh_thuc = 'NOI_BO' AND z.trang_thai = 'DELIVERED' AND z.ngay_di >= ? AND z.ngay_di < ?)
                      + (SELECT COUNT(*) FROM giao_hang g WHERE g.tai_xe_id = t.id AND g.hinh_thuc = 'NOI_BO' AND g.ngay_tao >= ? AND g.ngay_tao < ?) AS so_chuyen,
                    (SELECT COALESCE(SUM(z.km), 0) FROM chuyen_van_chuyen z WHERE z.tai_xe_id = t.id AND z.hinh_thuc = 'NOI_BO' AND z.trang_thai = 'DELIVERED' AND z.ngay_di >= ? AND z.ngay_di < ?)
                      + (SELECT COALESCE(SUM(g.km), 0) FROM giao_hang g WHERE g.tai_xe_id = t.id AND g.hinh_thuc = 'NOI_BO' AND g.ngay_tao >= ? AND g.ngay_tao < ?) AS tong_km,
                    (SELECT COALESCE(SUM(z.tien_tra), 0) FROM chuyen_van_chuyen z WHERE z.tai_xe_id = t.id AND z.hinh_thuc = 'NOI_BO' AND z.trang_thai = 'DELIVERED' AND z.ngay_di >= ? AND z.ngay_di < ?)
                      + (SELECT COALESCE(SUM(g.tien_tra), 0) FROM giao_hang g WHERE g.tai_xe_id = t.id AND g.hinh_thuc = 'NOI_BO' AND g.ngay_tao >= ? AND g.ngay_tao < ?) AS tien_chuyen
             FROM tai_xe t LEFT JOIN kho k ON k.id = t.kho_id LEFT JOIN xe v ON v.tai_xe_id = t.id
             LEFT JOIN chuyen_van_chuyen c ON c.tai_xe_id = t.id AND c.trang_thai = 'IN_TRANSIT'
             LEFT JOIN phieu_nhap_xuat p ON p.id = c.phieu_di_id
             WHERE t.thue_ngoai = 0" . ($wh !== null ? ' AND t.kho_id = ' . (int)$wh : '') . "
             ORDER BY t.ho_ten", [...($r = self::monthRange($month)), ...$r, ...$r, ...$r, ...$r, ...$r, ...$r]);
    }

    /** Các vụ mất hàng đã xác nhận (mới nhất trước). @return list<array<string,mixed>> */
    /**
     * Khoảng thời gian của tháng «YYYY-MM» (mặc định tháng hiện tại): [từ, đến) để lọc theo tháng.
     * @return array{0:string,1:string}
     */
    public static function monthRange(?string $month): array
    {
        $month = $month !== null && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) ? $month : date('Y-m');
        return [$month . '-01 00:00:00', date('Y-m-d H:i:s', strtotime($month . '-01 +1 month'))];
    }

    public function lossRecords(int $limit = 20, ?int $wh = null, ?string $month = null): array
    {
        $range = $month !== null ? self::monthRange($month) : null;
        return $this->db->all(
            "SELECT h.*, t.ho_ten AS tai_xe_ten, x.bien_so, b.ma AS phieu_den_ma, b.id AS phieu_den_id, kd.ma AS di_kho, kn.ma AS den_kho, u.ho_ten AS nguoi_xn
             FROM that_thoat_hang h JOIN chuyen_van_chuyen c ON c.id = h.chuyen_id JOIN tai_xe t ON t.id = h.tai_xe_id JOIN xe x ON x.id = c.xe_id
             JOIN phieu_nhap_xuat b ON b.id = c.phieu_den_id JOIN kho kd ON kd.id = c.kho_di_id JOIN kho kn ON kn.id = c.kho_den_id
             JOIN nguoi_dung u ON u.id = h.nguoi_xac_nhan_id
             WHERE 1 = 1" . ($wh !== null ? ' AND x.kho_goc_id = ' . (int)$wh : '') . ($range ? ' AND h.ngay_tao >= ? AND h.ngay_tao < ?' : '') . "
             ORDER BY h.id DESC LIMIT " . (int)$limit, $range ?? []);
    }

    /** Các chuyến gần đây (mới nhất trước). @return list<array<string,mixed>> */
    public function recentTrips(int $limit = 20, ?int $wh = null): array
    {
        return $this->db->all(
            "SELECT c.*, x.bien_so, t.ho_ten AS tai_xe_ten, a.ma AS phieu_di_ma, b.ma AS phieu_den_ma, kd.ma AS di_kho, kn.ma AS den_kho
             FROM chuyen_van_chuyen c JOIN xe x ON x.id = c.xe_id JOIN tai_xe t ON t.id = c.tai_xe_id
             JOIN phieu_nhap_xuat a ON a.id = c.phieu_di_id JOIN phieu_nhap_xuat b ON b.id = c.phieu_den_id
             JOIN kho kd ON kd.id = c.kho_di_id JOIN kho kn ON kn.id = c.kho_den_id
             " . ($wh !== null ? 'WHERE x.kho_goc_id = ' . (int)$wh : '') . "
             ORDER BY c.id DESC LIMIT " . (int)$limit);
    }

    /**
     * Chuyến kho đến báo «chưa nhận được hàng» (hàng vẫn đang vận chuyển): kèm xe, tài xế (để gọi điện), người báo và thời điểm báo.
     * @return list<array<string,mixed>>
     */
    public function missingTrips(?int $wh = null): array
    {
        return $this->db->all(
            "SELECT c.*, x.bien_so, x.loai_xe, t.ho_ten AS tai_xe_ten, t.cccd, t.so_dien_thoai, a.ma AS phieu_di_ma, b.ma AS phieu_den_ma, b.id AS phieu_den_id,
                    kd.ma AS di_kho, kn.ma AS den_kho, u.ho_ten AS bao_boi,
                    (SELECT COALESCE(SUM(l.so_luong * m.gia_von), 0) FROM chi_tiet_phieu_nhap_xuat l JOIN mat_hang m ON m.id = l.mat_hang_id WHERE l.phieu_nhap_xuat_id = c.phieu_di_id) AS gia_tri_hang,
                    (SELECT n.ngay_tao FROM nhat_ky n WHERE n.doi_tuong = 'voucher' AND n.doi_tuong_id = c.phieu_den_id AND n.hanh_dong = 'transfer.missing' ORDER BY n.id DESC LIMIT 1) AS bao_luc,
                    (SELECT n.chi_tiet FROM nhat_ky n WHERE n.doi_tuong = 'voucher' AND n.doi_tuong_id = c.phieu_den_id AND n.hanh_dong = 'transfer.reply' ORDER BY n.id DESC LIMIT 1) AS phan_hoi,
                    (SELECT n.ngay_tao FROM nhat_ky n WHERE n.doi_tuong = 'voucher' AND n.doi_tuong_id = c.phieu_den_id AND n.hanh_dong = 'transfer.reply' ORDER BY n.id DESC LIMIT 1) AS phan_hoi_luc,
                    (SELECT COUNT(*) FROM nhat_ky n WHERE n.doi_tuong = 'voucher' AND n.doi_tuong_id = c.phieu_den_id AND n.hanh_dong = 'transfer.reply') AS phan_hoi_so
             FROM chuyen_van_chuyen c JOIN xe x ON x.id = c.xe_id JOIN tai_xe t ON t.id = c.tai_xe_id
             JOIN phieu_nhap_xuat a ON a.id = c.phieu_di_id JOIN phieu_nhap_xuat b ON b.id = c.phieu_den_id
             JOIN kho kd ON kd.id = c.kho_di_id JOIN kho kn ON kn.id = c.kho_den_id LEFT JOIN nguoi_dung u ON u.id = c.nguoi_nhan_id
             WHERE c.trang_thai = 'IN_TRANSIT' AND c.sai_lech <> ''" . ($wh !== null ? ' AND x.kho_goc_id = ' . (int)$wh : '') . " ORDER BY c.id DESC");
    }

    /** Tình hình tài xế mà quản trị chọn khi phản hồi cho kho đến. */
    public const REPLY_STATUSES = ['Tài xế đang trên đường, sắp tới kho', 'Xe gặp sự cố, đang xử lý', 'Đang kẹt xe, giao chậm', 'Đã liên hệ được tài xế, đang quay lại',
        'Chưa liên lạc được — đang xác minh', 'Đã giao nhầm kho hoặc điểm khác', 'Khác'];

    /**
     * Quản trị PHẢN HỒI cho kho đến về chuyến chưa thấy hàng: tài xế đang làm gì + ghi chú (dự kiến tới lúc nào…).
     * Lưu ở nhật ký thao tác của phiếu đến (kho đi và kho đến đều thấy ở trang phiếu). Chuyến đã giao xong thì không phản hồi thêm.
     */
    public function replyToMissing(User $actor, int $inVoucherId, string $status, string $note = ''): void
    {
        $this->auth->requirePermission($actor, Permission::QuanLyDoiXe);
        $trip = $this->db->one('SELECT c.*, b.ma AS phieu_den_ma, x.kho_goc_id FROM chuyen_van_chuyen c JOIN phieu_nhap_xuat b ON b.id = c.phieu_den_id JOIN xe x ON x.id = c.xe_id WHERE c.phieu_den_id = ?', [$inVoucherId])
            ?? throw new DomainError('Không tìm thấy chuyến của phiếu này');
        $this->requireHome($actor, (int)$trip['kho_goc_id']);
        if ($trip['trang_thai'] !== 'IN_TRANSIT') {
            throw new DomainError('Chuyến này đã giao xong — không cần phản hồi thêm');
        }
        $status = trim($status);
        $note = trim($note);
        if (!in_array($status, self::REPLY_STATUSES, true)) {
            throw new DomainError('Chọn tình hình của tài xế');
        }
        if ($status === 'Khác' && $note === '') {
            throw new DomainError('Ghi rõ tình hình khác của tài xế');
        }
        if (mb_strlen($note) > 200) {
            throw new DomainError('Ghi chú tối đa 200 ký tự');
        }
        $this->audit->record($actor->id, 'transfer.reply', 'voucher', $inVoucherId, $status . ($note !== '' ? ' — ' . $note : ''));
    }

    /** Điểm uy tín tối đa của tài xế; mỗi vụ mất hàng tài xế chịu thì trừ mặc định DEFAULT_LOSS_POINTS. */
    public const MAX_SCORE = 100;
    public const DEFAULT_LOSS_POINTS = 10;

    /** Giá trị hàng của chuyến theo giá gốc hiện hành (số lượng trên phiếu đi × giá gốc mặt hàng). */
    public function tripGoodsValue(int $outVoucherId): int
    {
        return (int)$this->db->value('SELECT COALESCE(SUM(l.so_luong * m.gia_von), 0) FROM chi_tiet_phieu_nhap_xuat l JOIN mat_hang m ON m.id = l.mat_hang_id
                                      WHERE l.phieu_nhap_xuat_id = ?', [$outVoucherId]);
    }

    /**
     * XÁC NHẬN MẤT HÀNG: quản trị đóng chuyến mà kho đến đã báo «chưa nhận được hàng». Chuyến chuyển sang LOST, phiếu đến bị huỷ (không bao giờ cộng tồn kho đến;
     * tồn kho đi đã trừ từ lúc xuất nên đối chiếu vẫn khớp), xe về kho gốc. Tài xế chịu ($bearer = TAI_XE) thì bị trừ lương ($deduct, mặc định = giá trị hàng mất,
     * không quá giá trị đó) và trừ điểm ($points, mặc định 10); kho chịu thì không trừ tài xế. Tất cả trong một giao dịch.
     * @return int id bản ghi thất thoát
     */
    public function confirmLoss(User $actor, int $inVoucherId, string $reason, string $bearer, ?int $deduct = null, ?int $points = null): int
    {
        $this->auth->requirePermission($actor, Permission::QuanLyDoiXe);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 200) {
            throw new DomainError('Ghi lý do mất hàng (tối đa 200 ký tự)');
        }
        if (!in_array($bearer, ['TAI_XE', 'CONG_TY'], true)) {
            throw new DomainError('Chọn người chịu trách nhiệm');
        }
        return $this->db->transaction(function () use ($actor, $inVoucherId, $reason, $bearer, $deduct, $points) {
            $trip = $this->db->one('SELECT * FROM chuyen_van_chuyen WHERE phieu_den_id = ? FOR UPDATE', [$inVoucherId]) ?? throw new DomainError('Không tìm thấy chuyến của phiếu này');
            $this->requireHome($actor, (int)$this->db->value('SELECT kho_goc_id FROM xe WHERE id = ?', [(int)$trip['xe_id']]));
            if ($trip['trang_thai'] !== 'IN_TRANSIT') {
                throw new DomainError('Chuyến này đã đóng — không xác nhận mất hàng được nữa');
            }
            if ($trip['sai_lech'] === '') {
                throw new DomainError('Kho đến chưa báo «chưa nhận được hàng» cho chuyến này');
            }
            $value = $this->tripGoodsValue((int)$trip['phieu_di_id']);
            if ($bearer === 'TAI_XE') {
                $deduct ??= $value;
                $points ??= self::DEFAULT_LOSS_POINTS;
                if ($deduct < 0 || $deduct > $value) {
                    throw new DomainError('Số tiền trừ lương từ 0 đến giá trị hàng mất (' . number_format($value, 0, ',', '.') . ' đ)');
                }
                if ($points < 0 || $points > self::MAX_SCORE) {
                    throw new DomainError('Số điểm trừ từ 0 đến ' . self::MAX_SCORE);
                }
            } else {
                $deduct = $points = 0;
            }
            $in = $this->db->one('SELECT * FROM phieu_nhap_xuat WHERE id = ? FOR UPDATE', [$inVoucherId]);
            if (!$in || $in['trang_thai'] !== 'DRAFT') {
                throw new DomainError('Phiếu đến không còn ở trạng thái chờ nhận hàng');
            }
            $driver = $this->db->one('SELECT * FROM tai_xe WHERE id = ? FOR UPDATE', [(int)$trip['tai_xe_id']]);
            $this->db->exec("UPDATE phieu_nhap_xuat SET trang_thai = 'CANCELLED', ghi_chu = ? WHERE id = ?", [mb_substr('Mất hàng trên đường: ' . $reason, 0, 255), $inVoucherId]);
            $this->db->exec("UPDATE chuyen_van_chuyen SET trang_thai = 'LOST' WHERE id = ?", [(int)$trip['id']]);
            $this->db->exec('UPDATE xe SET kho_hien_tai_id = kho_goc_id, dang_den_kho_id = NULL WHERE id = ?', [(int)$trip['xe_id']]);
            $id = $this->db->insert('INSERT INTO that_thoat_hang (chuyen_id, tai_xe_id, nguoi_chiu, ly_do, gia_tri, tien_tru_luong, diem_tru, nguoi_xac_nhan_id) VALUES (?,?,?,?,?,?,?,?)',
                [(int)$trip['id'], (int)$trip['tai_xe_id'], $bearer, $reason, $value, $deduct, $points, $actor->id]);
            if ($points > 0) {
                $this->db->exec('UPDATE tai_xe SET diem = ? WHERE id = ?', [max(0, (int)$driver['diem'] - $points), (int)$driver['id']]);
            }
            $who = $bearer === 'TAI_XE' ? 'tài xế ' . $driver['ho_ten'] . ' chịu (−' . number_format($deduct, 0, ',', '.') . ' đ lương, −' . $points . ' điểm)' : 'kho chịu';
            $this->audit->record($actor->id, 'transfer.loss', 'voucher', $inVoucherId, $in['ma'] . ' · mất hàng ' . number_format($value, 0, ',', '.') . ' đ · ' . $who . ' · ' . $reason);
            if ($bearer === 'TAI_XE') {
                $this->audit->record($actor->id, 'driver.penalty', 'driver', (int)$driver['id'], $in['ma'] . ' · −' . number_format($deduct, 0, ',', '.') . ' đ lương, −' . $points . ' điểm');
            }
            return $id;
        });
    }

    /**
     * Sửa mức xử lý một vụ thất thoát (quản lý kho của xe): đổi người chịu, số tiền trừ lương, số điểm trừ.
     * Điểm đã trừ trước đó được hoàn lại rồi trừ theo mức mới.
     */
    public function adjustLoss(User $actor, int $lossId, string $bearer, ?int $deduct, ?int $points): void
    {
        $this->auth->requirePermission($actor, Permission::QuanLyDoiXe);
        if (!in_array($bearer, ['TAI_XE', 'CONG_TY'], true)) {
            throw new DomainError('Chọn người chịu trách nhiệm');
        }
        $this->db->transaction(function () use ($actor, $lossId, $bearer, $deduct, $points) {
            $h = $this->db->one('SELECT h.*, x.kho_goc_id FROM that_thoat_hang h JOIN chuyen_van_chuyen c ON c.id = h.chuyen_id JOIN xe x ON x.id = c.xe_id WHERE h.id = ? FOR UPDATE', [$lossId])
                ?? throw new DomainError('Không tìm thấy vụ thất thoát');
            $this->requireHome($actor, (int)$h['kho_goc_id']);
            $value = (int)$h['gia_tri'];
            if ($bearer === 'TAI_XE') {
                $deduct ??= $value;
                $points ??= self::DEFAULT_LOSS_POINTS;
                if ($deduct < 0 || $deduct > $value) {
                    throw new DomainError('Số tiền trừ lương từ 0 đến giá trị hàng mất (' . number_format($value, 0, ',', '.') . ' đ)');
                }
                if ($points < 0 || $points > self::MAX_SCORE) {
                    throw new DomainError('Số điểm trừ từ 0 đến ' . self::MAX_SCORE);
                }
            } else {
                $deduct = $points = 0;
            }
            $d = $this->db->one('SELECT * FROM tai_xe WHERE id = ? FOR UPDATE', [(int)$h['tai_xe_id']]);
            $score = max(0, min(self::MAX_SCORE, (int)$d['diem'] + (int)$h['diem_tru'] - $points));
            $this->db->exec('UPDATE tai_xe SET diem = ? WHERE id = ?', [$score, (int)$d['id']]);
            $this->db->exec('UPDATE that_thoat_hang SET nguoi_chiu = ?, tien_tru_luong = ?, diem_tru = ? WHERE id = ?', [$bearer, $deduct, $points, $lossId]);
            $this->audit->record($actor->id, 'driver.penalty', 'driver', (int)$d['id'], 'Sửa mức xử lý thất thoát #' . $lossId . ' · ' . ($bearer === 'TAI_XE' ? '−' . number_format($deduct, 0, ',', '.') . ' đ lương, −' . $points . ' điểm' : 'kho chịu'));
        });
    }

    /** Sửa lương tháng và điểm của tài xế (VD: khôi phục điểm sau khi xử lý xong). */
    public function updateDriverPay(User $actor, int $driverId, int $salary, int $score): void
    {
        $this->auth->requirePermission($actor, Permission::QuanLyDoiXe);
        $d = $this->ownDriver($actor, $driverId);
        if ($salary < 0 || $salary > 1_000_000_000) {
            throw new DomainError('Lương không hợp lệ');
        }
        if ($score < 0 || $score > self::MAX_SCORE) {
            throw new DomainError('Điểm từ 0 đến ' . self::MAX_SCORE);
        }
        $this->db->exec('UPDATE tai_xe SET luong = ?, diem = ? WHERE id = ?', [$salary, $score, $driverId]);
        $this->audit->record($actor->id, 'driver.pay', 'driver', $driverId, $d['ho_ten'] . ' · lương ' . number_format($salary, 0, ',', '.') . ' đ · ' . $score . ' điểm');
    }

    /** Các phản hồi của quản trị cho một phiếu đến (mới nhất trước). @return list<array<string,mixed>> */
    public function replies(int $inVoucherId): array
    {
        return $this->db->all("SELECT a.chi_tiet AS noi_dung, a.ngay_tao, u.ho_ten FROM nhat_ky a LEFT JOIN nguoi_dung u ON u.id = a.nguoi_dung_id
                               WHERE a.doi_tuong = 'voucher' AND a.doi_tuong_id = ? AND a.hanh_dong = 'transfer.reply' ORDER BY a.id DESC", [$inVoucherId]);
    }

    /** Số chuyến đang có báo sai lệch chưa xử lý xong. */
    public function openDiscrepancies(?int $wh = null): int
    {
        return (int)$this->db->value("SELECT COUNT(*) FROM chuyen_van_chuyen c JOIN xe x ON x.id = c.xe_id WHERE c.trang_thai = 'IN_TRANSIT' AND c.sai_lech <> ''"
            . ($wh !== null ? ' AND x.kho_goc_id = ' . (int)$wh : ''));
    }

    /** Xe sẵn sàng tại một kho (đang đứng ở kho đó, còn hoạt động) kèm tài xế phụ trách (có thể trống). @return list<array<string,mixed>> */
    public function availableVehicles(int $warehouseId): array
    {
        return $this->db->all(
            'SELECT x.id, x.bien_so, x.loai_xe, x.tai_xe_id, t.ho_ten, t.cccd, t.hoat_dong AS tai_xe_hoat_dong
             FROM xe x LEFT JOIN tai_xe t ON t.id = x.tai_xe_id WHERE x.hoat_dong AND x.thue_ngoai = 0 AND x.kho_hien_tai_id = ? ORDER BY x.bien_so', [$warehouseId]);
    }

    /** Chuyến của một phiếu điều chuyển (phiếu đi hoặc phiếu đến); null nếu phiếu không có thông tin vận chuyển (dữ liệu trước khi có đội xe). */
    public function tripOf(array $voucher): ?array
    {
        $col = $voucher['loai'] === 'TRANSFER_OUT' ? 'phieu_di_id' : ($voucher['loai'] === 'TRANSFER_IN' ? 'phieu_den_id' : null);
        if ($col === null) {
            return null;
        }
        return $this->db->one(
            "SELECT c.*, x.bien_so, x.loai_xe, t.ho_ten AS tai_xe_ten, t.cccd, t.so_dien_thoai, g.ho_ten AS giao_ten, n.ho_ten AS nhan_ten
             FROM chuyen_van_chuyen c JOIN xe x ON x.id = c.xe_id JOIN tai_xe t ON t.id = c.tai_xe_id
             JOIN nguoi_dung g ON g.id = c.nguoi_giao_id LEFT JOIN nguoi_dung n ON n.id = c.nguoi_nhan_id
             WHERE c.$col = ?", [$voucher['id']]);
    }

    // ───── quản lý xe ─────

    public function addVehicle(User $actor, string $plate, string $type, int $homeWarehouseId, ?int $driverId = null): int
    {
        $this->requireHome($actor, $homeWarehouseId);
        [$plate, $type] = $this->cleanVehicle($plate, $type, $homeWarehouseId, null, $driverId);
        $id = $this->db->insert('INSERT INTO xe (bien_so, loai_xe, kho_goc_id, kho_hien_tai_id, tai_xe_id) VALUES (?,?,?,?,?)', [$plate, $type, $homeWarehouseId, $homeWarehouseId, $driverId]);
        $this->adopt($driverId, $homeWarehouseId);
        $this->audit->record($actor->id, 'vehicle.create', 'vehicle', $id, $plate);
        return $id;
    }

    /**
     * Thêm xe KÈM tài xế phụ trách trong một giao dịch: tài xế mới được tạo cùng lúc (hoặc dùng lại tài xế cùng CCCD đang rảnh).
     * Lỗi ở phần xe (trùng biển số, kho đủ xe…) thì không có tài xế nào được tạo.
     * @return array{vehicle_id:int,driver_id:int}
     */
    public function addVehicleWithDriver(User $actor, string $plate, string $type, int $homeWarehouseId, string $driverName, string $driverCccd, string $driverPhone): array
    {
        $this->requireHome($actor, $homeWarehouseId);
        return $this->db->transaction(function () use ($actor, $plate, $type, $homeWarehouseId, $driverName, $driverCccd, $driverPhone) {
            $driverId = $this->resolveDriver($actor, $driverName, $driverCccd, $driverPhone, null, $homeWarehouseId);
            $vehicleId = $this->addVehicle($actor, $plate, $type, $homeWarehouseId, $driverId);
            return ['vehicle_id' => $vehicleId, 'driver_id' => $driverId];
        });
    }

    /**
     * Sửa xe KÈM thông tin tài xế phụ trách. Cùng CCCD ⇒ sửa họ tên / số điện thoại tại chỗ; CCCD khác ⇒ người khác lên thay:
     * tài xế mới được tạo (hoặc dùng lại tài xế cùng CCCD đang rảnh), còn hồ sơ tài xế cũ được GIỮ NGUYÊN (các chuyến cũ vẫn ghi đúng người đã chạy):
     * xoá nếu chưa từng chạy chuyến nào, ngược lại chuyển sang «Ngừng dùng».
     */
    public function updateVehicleWithDriver(User $actor, int $vehicleId, string $plate, string $type, int $homeWarehouseId, string $driverName, string $driverCccd, string $driverPhone): void
    {
        $this->auth->requirePermission($actor, Permission::QuanLyDoiXe);
        $this->db->transaction(function () use ($actor, $vehicleId, $plate, $type, $homeWarehouseId, $driverName, $driverCccd, $driverPhone) {
            $v = $this->ownVehicle($actor, $vehicleId);
            $this->requireHome($actor, $homeWarehouseId);
            $oldId = $v['tai_xe_id'] !== null ? (int)$v['tai_xe_id'] : null;
            $newId = $this->resolveDriver($actor, $driverName, $driverCccd, $driverPhone, $oldId, $homeWarehouseId);
            $this->updateVehicle($actor, $vehicleId, $plate, $type, $homeWarehouseId, $newId);
            if ($oldId !== null && $oldId !== $newId) {
                if ($this->db->value('SELECT 1 FROM chuyen_van_chuyen WHERE tai_xe_id = ?', [$oldId])) {
                    $this->setDriverActive($actor, $oldId, false);
                } else {
                    $this->deleteDriver($actor, $oldId);
                }
            }
        });
    }

    /**
     * Tìm hoặc tạo tài xế theo thông tin nhập trên form xe. $currentId = tài xế đang phụ trách xe (null khi thêm xe mới).
     * @return int id tài xế sẽ phụ trách xe
     */
    private function resolveDriver(User $actor, string $name, string $cccd, string $phone, ?int $currentId, int $homeWarehouseId): int
    {
        $cccdKey = trim($cccd);
        $existing = $this->db->one('SELECT * FROM tai_xe WHERE cccd = ?', [$cccdKey]);
        if ($existing !== null && (int)$existing['thue_ngoai']) {
            throw new DomainError('CCCD này đang dùng cho tài xế thuê ngoài — không gắn được vào xe của kho');
        }
        if ($existing === null) {
            return $this->addDriver($actor, $name, $cccd, $phone, $homeWarehouseId);   // người mới hoàn toàn (addDriver kiểm tra định dạng)
        }
        $id = (int)$existing['id'];
        if ($existing['kho_id'] !== null && (int)$existing['kho_id'] !== $homeWarehouseId) {
            throw new DomainError('Tài xế có CCCD này thuộc kho khác — không dùng được cho xe của kho này');
        }
        if ($id !== $currentId) {
            if ($other = $this->db->value('SELECT bien_so FROM xe WHERE tai_xe_id = ?', [$id])) {
                throw new DomainError('Tài xế có CCCD này đang phụ trách xe ' . $other . ' — mỗi tài xế chỉ một xe');
            }
            if (!$existing['hoat_dong']) {
                throw new DomainError('Tài xế có CCCD này đã ngừng dùng — bật lại ở mục tài xế chưa gắn xe, hoặc dùng người khác');
            }
        }
        [$cleanName, , $cleanPhone] = $this->cleanDriver($name, $cccd, $phone, $id);
        if ($cleanName !== $existing['ho_ten'] || $cleanPhone !== (string)$existing['so_dien_thoai']) {
            $this->updateDriver($actor, $id, $name, $cccd, $phone);   // chỉ sửa khi có thay đổi (tài xế đang chạy chuyến thì chưa sửa được)
        }
        return $id;
    }

    /** Sửa biển số, loại xe, kho gốc, tài xế phụ trách. Vị trí hiện tại của xe không đổi (điều xe dùng relocateVehicle). */
    public function updateVehicle(User $actor, int $vehicleId, string $plate, string $type, int $homeWarehouseId, ?int $driverId = null): void
    {
        $v = $this->ownVehicle($actor, $vehicleId);
        $this->requireHome($actor, $homeWarehouseId);
        if ($v['kho_hien_tai_id'] === null && (int)($v['tai_xe_id'] ?? 0) !== (int)$driverId) {
            throw new DomainError('Xe đang chạy trên đường — chưa đổi được tài xế phụ trách');
        }
        [$plate, $type] = $this->cleanVehicle($plate, $type, $homeWarehouseId, $v, $driverId);
        $this->db->exec('UPDATE xe SET bien_so = ?, loai_xe = ?, kho_goc_id = ?, tai_xe_id = ? WHERE id = ?', [$plate, $type, $homeWarehouseId, $driverId, $vehicleId]);
        $this->adopt($driverId, $homeWarehouseId);
        $this->audit->record($actor->id, 'vehicle.update', 'vehicle', $vehicleId, $plate);
    }

    /** Xoá xe chưa từng chạy chuyến nào. Xe đã có lịch sử vận chuyển chỉ được «Ngừng dùng» để giữ nguyên hồ sơ các chuyến cũ. */
    public function deleteVehicle(User $actor, int $vehicleId): void
    {
        $v = $this->ownVehicle($actor, $vehicleId);
        if ($v['kho_hien_tai_id'] === null) {
            throw new DomainError('Xe đang chạy trên đường — không xoá được');
        }
        if ($this->db->value('SELECT 1 FROM chuyen_van_chuyen WHERE xe_id = ?', [$vehicleId])) {
            throw new DomainError('Xe này đã có chuyến vận chuyển trong lịch sử nên không xoá được — hãy bấm «Ngừng dùng»');
        }
        $this->db->exec('DELETE FROM xe WHERE id = ?', [$vehicleId]);
        $this->audit->record($actor->id, 'vehicle.delete', 'vehicle', $vehicleId, $v['bien_so']);
    }

    /** @param ?array<string,mixed> $current xe đang sửa (null khi thêm mới) @return array{0:string,1:string} [biển số chuẩn hoá, loại xe] */
    private function cleanVehicle(string $plate, string $type, int $homeWarehouseId, ?array $current, ?int $driverId): array
    {
        $plate = strtoupper(trim(preg_replace('/\s+/', '', $plate) ?? ''));
        if (!preg_match('/^[0-9]{2}[A-Z][A-Z0-9]?-?[0-9]{3,5}(\.[0-9]{2})?$/', $plate)) {
            throw new DomainError('Biển số không hợp lệ (ví dụ 30H-123.45 hoặc 51C-12345)');
        }
        $type = trim($type);
        if (mb_strlen($type) > 60) {
            throw new DomainError('Loại xe tối đa 60 ký tự');
        }
        if (!$this->db->value('SELECT 1 FROM kho WHERE id = ? AND hoat_dong', [$homeWarehouseId])) {
            throw new DomainError('Kho gốc không tồn tại hoặc đã ngừng hoạt động');
        }
        if ($this->db->value('SELECT 1 FROM xe WHERE bien_so = ? AND id <> ?', [$plate, (int)($current['id'] ?? 0)])) {
            throw new DomainError("Biển số $plate đã có trong hệ thống");
        }
        if ($driverId !== null) {
            $d = $this->db->one('SELECT * FROM tai_xe WHERE id = ?', [$driverId]) ?? throw new DomainError('Không tìm thấy tài xế');
            if ($d['kho_id'] !== null && (int)$d['kho_id'] !== $homeWarehouseId) {
                throw new DomainError('Tài xế ' . $d['ho_ten'] . ' thuộc kho khác');
            }
            if (!$d['hoat_dong'] && (int)($current['tai_xe_id'] ?? 0) !== $driverId) {
                throw new DomainError('Tài xế ' . $d['ho_ten'] . ' đã ngừng dùng');
            }
            if ($other = $this->db->value('SELECT bien_so FROM xe WHERE tai_xe_id = ? AND id <> ?', [$driverId, (int)($current['id'] ?? 0)])) {
                throw new DomainError('Tài xế ' . $d['ho_ten'] . " đang phụ trách xe $other — mỗi tài xế chỉ một xe");
            }
        }
        return [$plate, $type];
    }

    /** Ngừng / dùng lại xe. Xe đang chạy không ngừng được. */
    public function setVehicleActive(User $actor, int $vehicleId, bool $active): void
    {
        $v = $this->ownVehicle($actor, $vehicleId);
        if (!$active && $v['kho_hien_tai_id'] === null) {
            throw new DomainError('Xe đang chạy trên đường — chờ kho đến nhận hàng xong mới ngừng được');
        }
        $this->db->exec('UPDATE xe SET hoat_dong = ? WHERE id = ?', [$active ? 1 : 0, $vehicleId]);
        $this->audit->record($actor->id, $active ? 'vehicle.enable' : 'vehicle.disable', 'vehicle', $vehicleId, $v['bien_so']);
    }

    /** Ghi nhận xe đang đứng ở kho khác (điều xe thủ công). Xe đang chạy thì không đổi được. */
    public function relocateVehicle(User $actor, int $vehicleId, int $warehouseId): void
    {
        $v = $this->ownVehicle($actor, $vehicleId);
        if ($v['kho_hien_tai_id'] === null) {
            throw new DomainError('Xe đang chạy trên đường — vị trí tự cập nhật khi kho đến nhận hàng');
        }
        if (!$this->db->value('SELECT 1 FROM kho WHERE id = ? AND hoat_dong', [$warehouseId])) {
            throw new DomainError('Kho không tồn tại hoặc đã ngừng hoạt động');
        }
        $this->db->exec('UPDATE xe SET kho_hien_tai_id = ? WHERE id = ?', [$warehouseId, $vehicleId]);
        $this->audit->record($actor->id, 'vehicle.relocate', 'vehicle', $vehicleId, $v['bien_so'] . " → kho #$warehouseId");
    }

    // ───── quản lý tài xế ─────

    /** Tài xế mới thuộc kho $warehouseId (mặc định kho của người thêm). */
    public function addDriver(User $actor, string $name, string $cccd, string $phone, ?int $warehouseId = null): int
    {
        $warehouseId ??= (int)$actor->warehouseId;
        $this->requireHome($actor, $warehouseId);
        [$name, $cccd, $phone] = $this->cleanDriver($name, $cccd, $phone, 0);
        $id = $this->db->insert('INSERT INTO tai_xe (ho_ten, cccd, so_dien_thoai, kho_id) VALUES (?,?,?,?)', [$name, $cccd, $phone, $warehouseId]);
        $this->audit->record($actor->id, 'driver.create', 'driver', $id, $name);
        return $id;
    }

    /** Sửa thông tin tài xế (không sửa được khi tài xế đang có chuyến chưa giao xong — kho đến đang đối chiếu theo thông tin này). */
    public function updateDriver(User $actor, int $driverId, string $name, string $cccd, string $phone): void
    {
        $this->ownDriver($actor, $driverId);
        if ($this->db->value("SELECT 1 FROM chuyen_van_chuyen WHERE tai_xe_id = ? AND trang_thai = 'IN_TRANSIT'", [$driverId])) {
            throw new DomainError('Tài xế đang có chuyến chưa giao xong — chờ giao xong mới sửa được');
        }
        [$name, $cccd, $phone] = $this->cleanDriver($name, $cccd, $phone, $driverId);
        $this->db->exec('UPDATE tai_xe SET ho_ten = ?, cccd = ?, so_dien_thoai = ? WHERE id = ?', [$name, $cccd, $phone, $driverId]);
        $this->audit->record($actor->id, 'driver.update', 'driver', $driverId, $name);
    }

    /** Xoá tài xế chưa từng chạy chuyến nào; đã có lịch sử thì chỉ «Ngừng dùng». */
    public function deleteDriver(User $actor, int $driverId): void
    {
        $d = $this->ownDriver($actor, $driverId);
        if ($xe = $this->db->value('SELECT bien_so FROM xe WHERE tai_xe_id = ?', [$driverId])) {
            throw new DomainError("Tài xế đang phụ trách xe $xe — đổi tài xế của xe đó trước khi xoá");
        }
        if ($this->db->value('SELECT 1 FROM chuyen_van_chuyen WHERE tai_xe_id = ?', [$driverId])) {
            throw new DomainError('Tài xế này đã có chuyến vận chuyển trong lịch sử nên không xoá được — hãy bấm «Ngừng dùng»');
        }
        $this->db->exec('DELETE FROM tai_xe WHERE id = ?', [$driverId]);
        $this->audit->record($actor->id, 'driver.delete', 'driver', $driverId, $d['ho_ten']);
    }

    /** @return array{0:string,1:string,2:string} [họ tên, CCCD, số điện thoại] đã chuẩn hoá */
    private function cleanDriver(string $name, string $cccd, string $phone, int $selfId): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        $cccd = trim($cccd);
        $phone = preg_replace('/[\s.]/', '', $phone) ?? '';
        if ($name === '' || mb_strlen($name) > 120) {
            throw new DomainError('Họ tên tài xế không hợp lệ');
        }
        if (!preg_match('/^[0-9]{12}$/', $cccd)) {
            throw new DomainError('Số CCCD gồm đúng 12 chữ số');
        }
        if ($phone !== '' && !preg_match('/^0[0-9]{9}$/', $phone)) {
            throw new DomainError('Số điện thoại gồm 10 chữ số, bắt đầu bằng 0');
        }
        if ($this->db->value('SELECT 1 FROM tai_xe WHERE cccd = ? AND id <> ?', [$cccd, $selfId])) {
            throw new DomainError('CCCD này đã có trong danh sách tài xế');
        }
        return [$name, $cccd, $phone];
    }

    public function setDriverActive(User $actor, int $driverId, bool $active): void
    {
        $d = $this->ownDriver($actor, $driverId);
        if (!$active && $this->db->value("SELECT 1 FROM chuyen_van_chuyen WHERE tai_xe_id = ? AND trang_thai = 'IN_TRANSIT'", [$driverId])) {
            throw new DomainError('Tài xế đang có chuyến chưa giao xong — chưa ngừng được');
        }
        $this->db->exec('UPDATE tai_xe SET hoat_dong = ? WHERE id = ?', [$active ? 1 : 0, $driverId]);
        $this->audit->record($actor->id, $active ? 'driver.enable' : 'driver.disable', 'driver', $driverId, $d['ho_ten']);
    }
}
