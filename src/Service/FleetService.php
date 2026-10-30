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

    /**
     * Quãng đường dùng để điền sẵn vào form: số đã khai báo, nếu chưa có thì ước tính theo địa chỉ hai kho.
     * @return array{km:?int,estimated:bool}
     */
    public function distanceHint(int $a, int $b): array
    {
        $km = $this->distance($a, $b);
        if ($km !== null) {
            return ['km' => $km, 'estimated' => false];
        }
        $addr = fn(int $id) => $this->db->value('SELECT dia_chi FROM kho WHERE id = ?', [$id]);
        $est = \StockFlow\Domain\VnAddress::roadKm($addr($a) ?: null, $addr($b) ?: null);
        return ['km' => $est, 'estimated' => $est !== null];
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

    /** Giá trị hàng trên phiếu (số lượng × đơn giá trên dòng, chưa có thì giá vốn): số tiền đơn vị vận tải thuê ngoài phải cọc trước khi nhận hàng. */
    public function goodsValue(int $voucherId): int
    {
        $r = $this->db->one('SELECT COALESCE(SUM(c.so_luong * COALESCE(c.don_gia, m.gia_von, 0)), 0) AS v FROM chi_tiet_phieu_nhap_xuat c JOIN mat_hang m ON m.id = c.mat_hang_id WHERE c.phieu_nhap_xuat_id = ?', [$voucherId]);
        return (int)($r['v'] ?? 0);
    }

    /**
     * Thuê ngoài: hàng không nằm trong tầm kiểm soát của kho nên bên vận tải phải cọc đủ giá trị hàng trước khi nhận (mất hàng thì giữ cọc).
     * @param array<string,string> $c coc_ok = '1' (kho đã thu cọc), coc_pt = TIEN_MAT | CHUYEN_KHOAN @return array{tien:int,pt:string}
     */
    public function externalDeposit(int $voucherId, array $c): array
    {
        $pt = (string)($c['coc_pt'] ?? '');
        if (!isset(DeliveryService::METHODS[$pt])) {
            throw new DomainError('Thuê ngoài: chọn hình thức đơn vị vận tải nộp tiền cọc hàng (tiền mặt hoặc chuyển khoản)');
        }
        if (($c['coc_ok'] ?? '') !== '1') {
            throw new DomainError('Thuê ngoài: phải thu đủ tiền cọc bằng giá trị hàng trước khi bàn giao hàng');
        }
        return ['tien' => $this->goodsValue($voucherId), 'pt' => $pt];
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
        // thông số xe + giấy phép lái xe của bên thuê ngoài (tuỳ chọn ở dịch vụ; form xuất kho bắt buộc nhập): đủ để kiểm tải trọng và hạng bằng
        $specs = array_filter(['tai_trong_kg' => $c['tai_trong_kg'] ?? '', 'gvw_kg' => $c['gvw_kg'] ?? ''], fn($x) => trim((string)$x) !== '');
        if ($specs) {
            $s = $this->cleanSpecs($specs);
            $this->db->exec('UPDATE xe SET tai_trong_kg = ?, gvw_kg = ? WHERE id = ?', [$s['tai_trong_kg'], $s['gvw_kg'], $vehicleId]);
        }
        if (trim((string)($c['license_no'] ?? '')) !== '' || trim((string)($c['license_class'] ?? '')) !== '') {
            $l = $this->cleanLicense(['so_gplx' => $c['license_no'] ?? '', 'hang_gplx' => $c['license_class'] ?? '', 'han_gplx' => $c['license_expiry'] ?? '']);
            $this->db->exec('UPDATE tai_xe SET so_gplx = ?, hang_gplx = ?, han_gplx = ? WHERE id = ?', [$l['so_gplx'], $l['hang_gplx'], $l['han_gplx'], $driverId]);
        }
        return $vehicleId;
    }

    // ───── tra cứu ─────

    // ───── tài xế và xe quản lý riêng: mỗi chuyến chọn xe + tài xế lúc xuất kho ─────

    /** Tài xế đang chạy một chuyến chưa giao xong (chuyến điều chuyển). */
    public function driverBusy(int $driverId): bool
    {
        return (bool)$this->db->value("SELECT 1 FROM chuyen_van_chuyen WHERE tai_xe_id = ? AND trang_thai = 'IN_TRANSIT'", [$driverId]);
    }

    /**
     * Tài xế của kho có thể nhận chuyến mới: đang làm, không phải thuê ngoài, không đang chạy chuyến nào. Gồm cả hồ sơ bằng lái để lọc theo xe.
     * @return list<array<string,mixed>>
     */
    public function availableDrivers(int $warehouseId): array
    {
        return $this->db->all(
            "SELECT t.* FROM tai_xe t WHERE t.hoat_dong AND t.thue_ngoai = 0 AND t.kho_id = ?
               AND NOT EXISTS (SELECT 1 FROM chuyen_van_chuyen c WHERE c.tai_xe_id = t.id AND c.trang_thai = 'IN_TRANSIT')
             ORDER BY t.ho_ten", [$warehouseId]);
    }

    /**
     * Kiểm tra và khoá tài xế được giao cho chuyến xuất từ kho $warehouseId: phải còn làm việc, thuộc kho này, không đang chạy chuyến khác.
     * Xe và tài xế KHÔNG gắn cố định — người nào có bằng lái đúng hạng cho xe thì lái được; việc kiểm bằng lái do TransportService làm.
     * @return array<string,mixed> dòng tai_xe
     */
    public function assignableDriver(int $driverId, int $warehouseId): array
    {
        $d = $this->db->one('SELECT * FROM tai_xe WHERE id = ? FOR UPDATE', [$driverId]);
        if (!$d || (int)$d['thue_ngoai']) {
            throw new DomainError('Tài xế không tồn tại');
        }
        if (!$d['hoat_dong']) {
            throw new DomainError('Tài xế ' . $d['ho_ten'] . ' đã nghỉ — chọn tài xế khác lái thay');
        }
        if ((int)($d['kho_id'] ?? 0) !== $warehouseId) {
            throw new DomainError('Tài xế ' . $d['ho_ten'] . ' thuộc kho khác — chọn tài xế của kho này');
        }
        if ($this->driverBusy($driverId)) {
            throw new DomainError('Tài xế ' . $d['ho_ten'] . ' đang chạy một chuyến chưa giao xong');
        }
        return $d;
    }

    /** Thêm xe (không gắn tài xế) kèm thông số kỹ thuật, trong một giao dịch: lỗi thì không tạo xe. */
    public function createVehicle(User $actor, string $plate, string $type, int $homeWarehouseId, ?array $specs = null): int
    {
        $this->requireHome($actor, $homeWarehouseId);
        return $this->db->transaction(function () use ($actor, $plate, $type, $homeWarehouseId, $specs) {
            $id = $this->addVehicle($actor, $plate, $type, $homeWarehouseId, null);
            $specs === null || $this->setVehicleSpecs($actor, $id, $specs);
            return $id;
        });
    }

    /** Sửa biển số, loại xe, kho gốc và thông số kỹ thuật; không đụng tới tài xế (tài xế chọn theo từng chuyến). */
    public function editVehicle(User $actor, int $vehicleId, string $plate, string $type, int $homeWarehouseId, ?array $specs = null): void
    {
        $this->auth->requirePermission($actor, Permission::QuanLyDoiXe);
        $this->db->transaction(function () use ($actor, $vehicleId, $plate, $type, $homeWarehouseId, $specs) {
            $v = $this->ownVehicle($actor, $vehicleId);
            $this->requireHome($actor, $homeWarehouseId);
            $this->updateVehicle($actor, $vehicleId, $plate, $type, $homeWarehouseId, $v['tai_xe_id'] !== null ? (int)$v['tai_xe_id'] : null);
            $specs === null || $this->setVehicleSpecs($actor, $vehicleId, $specs);
        });
    }

    /** Thêm tài xế (thuộc kho của người thêm) kèm giấy phép lái xe, trong một giao dịch. */
    public function createDriver(User $actor, string $name, string $cccd, string $phone, ?array $license = null): int
    {
        return $this->db->transaction(function () use ($actor, $name, $cccd, $phone, $license) {
            $id = $this->addDriver($actor, $name, $cccd, $phone);
            $license === null || $this->setDriverLicense($actor, $id, $license);
            return $id;
        });
    }

    /** Xe kèm vị trí hiện tại, kho đang tới và tài xế của chuyến đang chạy. @return list<array<string,mixed>> */
    public function vehicles(?int $wh = null): array
    {
        return $this->db->all(
            "SELECT x.*, g.ma AS goc_ma, h.ma AS ht_ma, h.ten AS ht_ten, d.ma AS den_ma, d.ten AS den_ten,
                    t.ho_ten AS tai_xe_ten, t.cccd AS tai_xe_cccd, t.so_dien_thoai AS tai_xe_sdt, t.hoat_dong AS tai_xe_hoat_dong,
                    t.so_gplx AS tx_so_gplx, t.hang_gplx AS tx_hang_gplx, t.ngay_sinh AS tx_ngay_sinh, t.ngay_cap_gplx AS tx_ngay_cap_gplx, t.han_gplx AS tx_han_gplx,
                    t.noi_cap_gplx AS tx_noi_cap_gplx, t.han_kham_sk AS tx_han_kham_sk, t.diem_gplx AS tx_diem_gplx, t.anh_gplx AS tx_anh_gplx,
                    c.id AS chuyen_id, p.ma AS phieu_ma, p.id AS phieu_id, tc.ho_ten AS chay_tai_xe, tc.id AS chay_tai_xe_id
             FROM xe x JOIN kho g ON g.id = x.kho_goc_id LEFT JOIN kho h ON h.id = x.kho_hien_tai_id LEFT JOIN kho d ON d.id = x.dang_den_kho_id
             LEFT JOIN tai_xe t ON t.id = x.tai_xe_id
             LEFT JOIN chuyen_van_chuyen c ON c.xe_id = x.id AND c.trang_thai = 'IN_TRANSIT'
             LEFT JOIN phieu_nhap_xuat p ON p.id = c.phieu_di_id LEFT JOIN tai_xe tc ON tc.id = c.tai_xe_id
             WHERE x.thue_ngoai = 0" . ($wh !== null ? ' AND x.kho_goc_id = ' . (int)$wh : '') . "
             ORDER BY g.ma, x.bien_so");
    }

    /** Tài xế kèm chuyến đang chạy (nếu có). @return list<array<string,mixed>> */
    public function drivers(?int $wh = null, ?string $month = null): array
    {
        return $this->db->all(
            "SELECT t.*, k.ma AS kho_ma, v.id AS xe_phu_trach_id, v.bien_so AS xe_phu_trach, cx.bien_so AS xe_dang_chay, c.id AS chuyen_id, p.ma AS phieu_ma, p.id AS phieu_id,
                    (SELECT COUNT(*) FROM that_thoat_hang h WHERE h.tai_xe_id = t.id AND h.nguoi_chiu = 'TAI_XE') AS so_vu_mat,
                    (SELECT COALESCE(SUM(h.tien_tru_luong), 0) FROM that_thoat_hang h WHERE h.tai_xe_id = t.id AND h.ngay_tao >= ? AND h.ngay_tao < ?) AS tru_thang,
                    (SELECT COUNT(*) FROM chuyen_van_chuyen z WHERE z.tai_xe_id = t.id AND z.hinh_thuc = 'NOI_BO' AND z.trang_thai = 'DELIVERED' AND z.ngay_di >= ? AND z.ngay_di < ?)
                      + (SELECT COUNT(*) FROM giao_hang g WHERE g.tai_xe_id = t.id AND g.hinh_thuc = 'NOI_BO' AND g.ngay_tao >= ? AND g.ngay_tao < ?) AS so_chuyen,
                    (SELECT COALESCE(SUM(z.km), 0) FROM chuyen_van_chuyen z WHERE z.tai_xe_id = t.id AND z.hinh_thuc = 'NOI_BO' AND z.trang_thai = 'DELIVERED' AND z.ngay_di >= ? AND z.ngay_di < ?)
                      + (SELECT COALESCE(SUM(g.km), 0) FROM giao_hang g WHERE g.tai_xe_id = t.id AND g.hinh_thuc = 'NOI_BO' AND g.ngay_tao >= ? AND g.ngay_tao < ?) AS tong_km,
                    (SELECT COALESCE(SUM(z.tien_tra), 0) FROM chuyen_van_chuyen z WHERE z.tai_xe_id = t.id AND z.hinh_thuc = 'NOI_BO' AND z.trang_thai = 'DELIVERED' AND z.ngay_di >= ? AND z.ngay_di < ?)
                      + (SELECT COALESCE(SUM(g.tien_tra), 0) FROM giao_hang g WHERE g.tai_xe_id = t.id AND g.hinh_thuc = 'NOI_BO' AND g.ngay_tao >= ? AND g.ngay_tao < ?) AS tien_chuyen
             FROM tai_xe t LEFT JOIN kho k ON k.id = t.kho_id LEFT JOIN xe v ON v.tai_xe_id = t.id
             LEFT JOIN chuyen_van_chuyen c ON c.tai_xe_id = t.id AND c.trang_thai = 'IN_TRANSIT' LEFT JOIN xe cx ON cx.id = c.xe_id
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

    /**
     * Các chuyến THẬT của từng tài xế trong tháng (xem chi tiết ở tab «Điểm & thu nhập»): chuyến điều chuyển (mọi tình trạng, chỉ chuyến đã giao mới tính tiền)
     * và đơn giao hàng cho khách. Mới nhất trước.
     * @param list<int> $driverIds
     * @return array<int,list<array<string,mixed>>> id tài xế ⇒ danh sách chuyến
     */
    public function driverTrips(array $driverIds, ?string $month = null): array
    {
        $driverIds = array_values(array_filter(array_map('intval', $driverIds)));
        if ($driverIds === []) {
            return [];
        }
        $in = implode(',', $driverIds);
        [$from, $to] = self::monthRange($month);
        $rows = $this->db->all(
            "SELECT 'DIEU_CHUYEN' AS loai, c.id AS id, c.tai_xe_id, c.ngay_di AS ngay, c.trang_thai, c.hinh_thuc, c.km, c.tien_tra, x.bien_so,
                    pd.id AS phieu_id, pd.ma AS phieu_ma, c.kho_di_id AS kho_phieu_id, k1.ma AS tu, k2.ma AS den, c.sai_lech
             FROM chuyen_van_chuyen c JOIN xe x ON x.id = c.xe_id JOIN phieu_nhap_xuat pd ON pd.id = c.phieu_di_id
             JOIN kho k1 ON k1.id = c.kho_di_id JOIN kho k2 ON k2.id = c.kho_den_id
             WHERE c.tai_xe_id IN ($in) AND c.ngay_di >= ? AND c.ngay_di < ?
             UNION ALL
             SELECT 'GIAO_KHACH', g.id, g.tai_xe_id, g.ngay_tao, 'DELIVERED', g.hinh_thuc, g.km, g.tien_tra, x.bien_so,
                    p.id, p.ma, g.kho_id, k.ma, g.dia_chi, ''
             FROM giao_hang g JOIN phieu_nhap_xuat p ON p.id = g.phieu_nhap_xuat_id JOIN kho k ON k.id = g.kho_id LEFT JOIN xe x ON x.id = g.xe_id
             WHERE g.tai_xe_id IN ($in) AND g.ngay_tao >= ? AND g.ngay_tao < ?
             ORDER BY ngay DESC, id DESC", [$from, $to, $from, $to]);
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['tai_xe_id']][] = $r;
        }
        return $out;
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

    /** Sửa điểm uy tín của tài xế (VD: khôi phục điểm sau khi xử lý xong). Tài xế không có lương cố định: thu nhập chỉ gồm tiền từng chuyến đã giao (trừ phần phạt khi mất hàng). */
    public function updateDriverScore(User $actor, int $driverId, int $score): void
    {
        $this->auth->requirePermission($actor, Permission::QuanLyDoiXe);
        $d = $this->ownDriver($actor, $driverId);
        if ($score < 0 || $score > self::MAX_SCORE) {
            throw new DomainError('Điểm từ 0 đến ' . self::MAX_SCORE);
        }
        $this->db->exec('UPDATE tai_xe SET diem = ? WHERE id = ?', [$score, $driverId]);
        $this->audit->record($actor->id, 'driver.pay', 'driver', $driverId, $d['ho_ten'] . ' · ' . $score . ' điểm');
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
            'SELECT x.id, x.bien_so, x.loai_xe, x.kieu_xe, x.dung_tich_cc, x.tai_trong_kg, x.gvw_kg, x.ro_mooc_kg, x.tai_xe_id, t.ho_ten, t.cccd, t.hoat_dong AS tai_xe_hoat_dong
             FROM xe x LEFT JOIN tai_xe t ON t.id = x.tai_xe_id WHERE x.hoat_dong AND x.thue_ngoai = 0 AND x.kho_hien_tai_id = ? ORDER BY x.bien_so', [$warehouseId]);
    }

    /** Chuyến của một phiếu điều chuyển (phiếu đi hoặc phiếu đến); null nếu phiếu không có thông tin vận chuyển (dữ liệu trước khi có đội xe). */
    /** Sự cố xe hỏng đang mở của một chuyến (chưa sửa xong); null nếu xe chạy bình thường. */
    public function breakdownOf(int $tripId): ?array
    {
        return $this->db->one('SELECT s.*, u.ho_ten AS bao_ten FROM su_co_xe s JOIN nguoi_dung u ON u.id = s.nguoi_bao_id WHERE s.chuyen_id = ? AND s.ngay_sua IS NULL ORDER BY s.id DESC', [$tripId]);
    }

    /**
     * Các xe cùng đợt với phiếu đi này (nối đuôi nhau), kèm trạng thái từng chuyến và sự cố đang mở; rỗng nếu phiếu không thuộc đợt nhiều xe.
     * @return list<array<string,mixed>>
     */
    public function convoyOf(int $outId): array
    {
        $rows = $this->db->all(
            'SELECT d.thu_tu, d.phieu_di_id, p.ma AS ma_di, c.id AS chuyen_id, c.phieu_den_id, pi.ma AS ma_den, c.trang_thai, x.bien_so, t.ho_ten AS tai_xe_ten,
                    s.ly_do AS hong_ly_do, s.tien_do AS hong_tien_do
             FROM dot_dieu_chuyen m JOIN dot_dieu_chuyen d ON d.dot_id = m.dot_id JOIN phieu_nhap_xuat p ON p.id = d.phieu_di_id
             LEFT JOIN chuyen_van_chuyen c ON c.phieu_di_id = d.phieu_di_id LEFT JOIN phieu_nhap_xuat pi ON pi.id = c.phieu_den_id
             LEFT JOIN xe x ON x.id = c.xe_id LEFT JOIN tai_xe t ON t.id = c.tai_xe_id
             LEFT JOIN su_co_xe s ON s.chuyen_id = c.id AND s.ngay_sua IS NULL
             WHERE m.phieu_di_id = ? ORDER BY d.thu_tu', [$outId]);
        return count($rows) >= 2 ? $rows : [];
    }

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
    public function addVehicleWithDriver(User $actor, string $plate, string $type, int $homeWarehouseId, string $driverName, string $driverCccd, string $driverPhone, ?array $specs = null, ?array $license = null): array
    {
        $this->requireHome($actor, $homeWarehouseId);
        return $this->db->transaction(function () use ($actor, $plate, $type, $homeWarehouseId, $driverName, $driverCccd, $driverPhone, $specs, $license) {
            $driverId = $this->resolveDriver($actor, $driverName, $driverCccd, $driverPhone, null, $homeWarehouseId);
            $vehicleId = $this->addVehicle($actor, $plate, $type, $homeWarehouseId, $driverId);
            $specs === null || $this->setVehicleSpecs($actor, $vehicleId, $specs);        // thông số xe + bằng lái khai luôn lúc thêm: lỗi thì cả xe lẫn tài xế không được tạo
            $license === null || $this->setDriverLicense($actor, $driverId, $license);
            return ['vehicle_id' => $vehicleId, 'driver_id' => $driverId];
        });
    }

    /**
     * Sửa xe KÈM thông tin tài xế phụ trách. Cùng CCCD ⇒ sửa họ tên / số điện thoại tại chỗ; CCCD khác ⇒ người khác lên thay:
     * tài xế mới được tạo (hoặc dùng lại tài xế cùng CCCD đang rảnh), còn hồ sơ tài xế cũ được GIỮ NGUYÊN (các chuyến cũ vẫn ghi đúng người đã chạy):
     * xoá nếu chưa từng chạy chuyến nào, ngược lại chuyển sang «Ngừng dùng».
     */
    public function updateVehicleWithDriver(User $actor, int $vehicleId, string $plate, string $type, int $homeWarehouseId, string $driverName, string $driverCccd, string $driverPhone, ?array $specs = null, ?array $license = null): void
    {
        $this->auth->requirePermission($actor, Permission::QuanLyDoiXe);
        $this->db->transaction(function () use ($actor, $vehicleId, $plate, $type, $homeWarehouseId, $driverName, $driverCccd, $driverPhone, $specs, $license) {
            $v = $this->ownVehicle($actor, $vehicleId);
            $this->requireHome($actor, $homeWarehouseId);
            $oldId = $v['tai_xe_id'] !== null ? (int)$v['tai_xe_id'] : null;
            $newId = $this->resolveDriver($actor, $driverName, $driverCccd, $driverPhone, $oldId, $homeWarehouseId);
            $this->updateVehicle($actor, $vehicleId, $plate, $type, $homeWarehouseId, $newId);
            $specs === null || $this->setVehicleSpecs($actor, $vehicleId, $specs);
            $license === null || $this->setDriverLicense($actor, $newId, $license);
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

    // ───── thông số kỹ thuật của xe & giấy phép lái xe của tài xế ─────

    private static function isDate(string $s): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $s);
        return $d !== false && $d->format('Y-m-d') === $s;
    }

    /**
     * Kiểm tra thông số xe. Số 0 hoặc rỗng = chưa khai (cho phép: dữ liệu cũ khai dần, thiếu số liệu chỉ bị cảnh báo khi chọn xe).
     * @param array<string,mixed> $v tai_trong_kg, gvw_kg, ro_mooc_kg, loai_thung, thung_dai_mm/rong/cao, tong_dai_mm/rong/cao, so_truc, han_dang_kiem, han_bao_hiem
     * @return array<string,mixed>
     */
    private function cleanSpecs(array $v): array
    {
        $int = function (string $k, int $lo, int $hi, string $label) use ($v): int {
            $raw = trim((string)($v[$k] ?? ''));
            if ($raw === '') {
                return 0;
            }
            $n = filter_var($raw, FILTER_VALIDATE_INT);
            if ($n === false || ($n !== 0 && ($n < $lo || $n > $hi))) {
                throw new DomainError("$label không hợp lệ (từ " . number_format($lo, 0, ',', '.') . ' đến ' . number_format($hi, 0, ',', '.') . ')');
            }
            return $n;
        };
        $out = [
            'tai_trong_kg' => $int('tai_trong_kg', 10, 60000, 'Tải trọng (kg)'),
            'gvw_kg' => $int('gvw_kg', 500, 60000, 'Khối lượng toàn bộ (kg)'),
            'ro_mooc_kg' => $int('ro_mooc_kg', 100, 60000, 'Khối lượng rơ moóc (kg)'),
            'thung_dai_mm' => $int('thung_dai_mm', 200, 20000, 'Chiều dài thùng (mm)'),
            'thung_rong_mm' => $int('thung_rong_mm', 200, 4000, 'Chiều rộng thùng (mm)'),
            'thung_cao_mm' => $int('thung_cao_mm', 200, 5000, 'Chiều cao thùng (mm)'),
            'tong_dai_mm' => $int('tong_dai_mm', 500, 30000, 'Chiều dài xe (mm)'),
            'tong_rong_mm' => $int('tong_rong_mm', 300, 4000, 'Chiều rộng xe (mm)'),
            'tong_cao_mm' => $int('tong_cao_mm', 500, 6000, 'Chiều cao xe (mm)'),
            'so_truc' => $int('so_truc', 2, 10, 'Số trục'),
            'dung_tich_cc' => $int('dung_tich_cc', 50, 2000, 'Dung tích xi-lanh (cm³)'),
        ];
        $kind = trim((string)($v['kieu_xe'] ?? '')) ?: 'TAI';
        if (!isset(\StockFlow\Domain\TransportRules::VEHICLE_KINDS[$kind])) {
            throw new DomainError('Kiểu xe không hợp lệ');
        }
        $out['kieu_xe'] = $kind;
        if ($kind === 'MAY' && $out['tai_trong_kg'] > 300) {
            throw new DomainError('Xe máy chở tối đa 300 kg — hàng nặng hơn dùng ô tô hoặc xe tải');
        }
        $box = [$out['thung_dai_mm'], $out['thung_rong_mm'], $out['thung_cao_mm']];
        if (count(array_filter($box)) % 3 !== 0) {
            throw new DomainError('Kích thước lòng thùng: nhập đủ cả dài, rộng, cao (hoặc để trống cả ba)');
        }
        if ($out['gvw_kg'] > 0 && $out['tai_trong_kg'] > 0 && $out['gvw_kg'] < $out['tai_trong_kg']) {
            throw new DomainError('Khối lượng toàn bộ (GVW) phải lớn hơn hoặc bằng tải trọng — GVW gồm cả xe không và hàng');
        }
        $type = trim((string)($v['loai_thung'] ?? ''));
        if ($type !== '' && !isset(\StockFlow\Domain\TransportRules::BODY_TYPES[$type])) {
            throw new DomainError('Loại thùng không hợp lệ');
        }
        $out['loai_thung'] = $type;
        foreach (['han_dang_kiem' => 'Hạn đăng kiểm', 'han_bao_hiem' => 'Hạn bảo hiểm TNDS'] as $k => $label) {
            $d = trim((string)($v[$k] ?? ''));
            if ($d !== '' && !self::isDate($d)) {
                throw new DomainError("$label không phải ngày hợp lệ");
            }
            $out[$k] = $d === '' ? null : $d;
        }
        return $out;
    }

    /**
     * Kiểm tra giấy phép lái xe và hồ sơ tài xế. Chưa khai gì thì để trống hết; đã khai hạng thì phải có số bằng và ngược lại.
     * Hạng bằng có thời hạn mà chưa nhập hạn thì tự tính từ ngày cấp (theo thời hạn của hạng).
     * @param array<string,mixed> $v so_gplx, hang_gplx, ngay_sinh, ngay_cap_gplx, han_gplx, noi_cap_gplx, han_kham_sk, diem_gplx
     * @return array<string,mixed>
     */
    private function cleanLicense(array $v): array
    {
        $today = date('Y-m-d');
        $no = strtoupper(preg_replace('/\s+/', '', (string)($v['so_gplx'] ?? '')) ?? '');
        $class = trim((string)($v['hang_gplx'] ?? ''));
        if ($class !== '' && !isset(\StockFlow\Domain\TransportRules::CLASSES[$class])) {
            throw new DomainError('Hạng giấy phép lái xe không hợp lệ');
        }
        if (($class === '') !== ($no === '')) {
            throw new DomainError('Khai giấy phép lái xe: nhập cả số bằng và hạng bằng (hoặc để trống cả hai)');
        }
        if ($no !== '' && !preg_match('/^[0-9A-Z]{6,20}$/', $no)) {
            throw new DomainError('Số giấy phép lái xe gồm 6–20 chữ và số');
        }
        $date = function (string $k, string $label) use ($v): ?string {
            $d = trim((string)($v[$k] ?? ''));
            if ($d === '') {
                return null;
            }
            if (!self::isDate($d)) {
                throw new DomainError("$label không phải ngày hợp lệ");
            }
            return $d;
        };
        $birth = $date('ngay_sinh', 'Ngày sinh');
        $issued = $date('ngay_cap_gplx', 'Ngày cấp bằng');
        $expiry = $date('han_gplx', 'Hạn bằng lái');
        $health = $date('han_kham_sk', 'Hạn giấy khám sức khoẻ');
        if ($birth !== null && \StockFlow\Domain\TransportRules::ageOn($birth, $today) < 18) {
            throw new DomainError('Tài xế phải từ 18 tuổi trở lên');
        }
        if ($issued !== null && $issued > $today) {
            throw new DomainError('Ngày cấp bằng không được ở tương lai');
        }
        if ($class !== '' && $birth !== null && \StockFlow\Domain\TransportRules::ageOn($birth, $issued ?? $today) < \StockFlow\Domain\TransportRules::minAge($class)) {
            throw new DomainError('Chưa đủ ' . \StockFlow\Domain\TransportRules::minAge($class) . ' tuổi tại thời điểm được cấp ' . \StockFlow\Domain\TransportRules::label($class) . ' — kiểm tra lại ngày sinh hoặc ngày cấp');
        }
        if ($issued !== null && $expiry !== null && $expiry < $issued) {
            throw new DomainError('Hạn bằng lái phải sau ngày cấp');
        }
        $years = $class !== '' ? (int)\StockFlow\Domain\TransportRules::CLASSES[$class][2] : 0;
        if ($expiry === null && $issued !== null && $years > 0) {
            $expiry = date('Y-m-d', strtotime("$issued +$years years"));
        }
        $place = trim(preg_replace('/\s+/u', ' ', (string)($v['noi_cap_gplx'] ?? '')) ?? '');
        if (mb_strlen($place) > 120) {
            throw new DomainError('Nơi cấp bằng tối đa 120 ký tự');
        }
        $points = trim((string)($v['diem_gplx'] ?? ''));
        $points = $points === '' ? 12 : filter_var($points, FILTER_VALIDATE_INT);
        if ($points === false || $points < 0 || $points > 12) {
            throw new DomainError('Điểm giấy phép lái xe từ 0 đến 12');
        }
        return ['so_gplx' => $no, 'hang_gplx' => $class, 'ngay_sinh' => $birth, 'ngay_cap_gplx' => $issued, 'han_gplx' => $expiry,
                'noi_cap_gplx' => $place, 'han_kham_sk' => $health, 'diem_gplx' => $points];
    }

    /** Lưu thông số kỹ thuật của xe (kho gốc của xe tự khai). */
    public function setVehicleSpecs(User $actor, int $vehicleId, array $v): void
    {
        $x = $this->ownVehicle($actor, $vehicleId);
        $s = $this->cleanSpecs($v);
        $cols = array_keys($s);
        $this->db->exec('UPDATE xe SET ' . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ' WHERE id = ?', [...array_values($s), $vehicleId]);
        $need = \StockFlow\Domain\TransportRules::needFor($s);
        $this->audit->record($actor->id, 'vehicle.specs', 'vehicle', $vehicleId, $x['bien_so'] . ' · tải ' . $s['tai_trong_kg'] . ' kg · GVW ' . $s['gvw_kg'] . ' kg' . ($need ? ' · cần hạng ' . $need : ''));
    }

    /** Lưu giấy phép lái xe và hồ sơ của tài xế (kho quản lý tài xế tự khai). */
    public function setDriverLicense(User $actor, int $driverId, array $v): void
    {
        $d = $this->ownDriver($actor, $driverId);
        $s = $this->cleanLicense($v);
        $cols = array_keys($s);
        $this->db->exec('UPDATE tai_xe SET ' . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ' WHERE id = ?', [...array_values($s), $driverId]);
        $this->audit->record($actor->id, 'driver.license', 'driver', $driverId, $d['ho_ten'] . ' · ' . ($s['hang_gplx'] !== '' ? 'hạng ' . $s['hang_gplx'] . ' · ' . $s['so_gplx'] : 'chưa khai bằng'));
    }

    public const PHOTO_MAX_BYTES = 3 * 1024 * 1024;
    private const PHOTO_TYPES = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    private const PHOTO_MIME = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    private const PHOTO_NAME = '/^d\d+_[a-f0-9]{12}\.(jpg|png|webp)$/';

    private static function photoDir(): string
    {
        $dir = \StockFlow\Core\Config::storage('licenses');
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new DomainError('Không tạo được thư mục lưu ảnh bằng lái');
        }
        return $dir;
    }

    /**
     * Đính ảnh giấy phép lái xe cho tài xế (kho quản lý tài xế): kiểm tra nội dung thật của file, lưu vào storage/licenses, ảnh cũ bị xoá.
     * @return string tên file đã lưu
     */
    public function saveLicensePhoto(User $actor, int $driverId, string $tmpPath, int $size): string
    {
        $d = $this->ownDriver($actor, $driverId);
        if ($size <= 0 || !is_file($tmpPath)) {
            throw new DomainError('Hãy chọn một ảnh');
        }
        if ($size > self::PHOTO_MAX_BYTES) {
            throw new DomainError('Ảnh quá lớn — tối đa 3 MB');
        }
        $info = @getimagesize($tmpPath);
        if (!$info || !isset(self::PHOTO_TYPES[$info[2]])) {
            throw new DomainError('Chỉ nhận ảnh JPG, PNG hoặc WebP');
        }
        if ($info[0] < 200 || $info[1] < 200 || $info[0] > 8000 || $info[1] > 8000) {
            throw new DomainError('Kích thước ảnh phải từ 200×200 đến 8000×8000 điểm ảnh — chụp rõ cả tấm bằng');
        }
        $name = sprintf('d%d_%s.%s', $driverId, bin2hex(random_bytes(6)), self::PHOTO_TYPES[$info[2]]);
        $dest = self::photoDir() . '/' . $name;
        if (!@copy($tmpPath, $dest)) {
            throw new DomainError('Không lưu được ảnh. Hãy thử lại.');
        }
        @chmod($dest, 0644);
        try {
            $this->db->exec('UPDATE tai_xe SET anh_gplx = ? WHERE id = ?', [$name, $driverId]);
        } catch (\Throwable $e) {
            @unlink($dest);
            throw $e;
        }
        $this->dropPhotoFile((string)($d['anh_gplx'] ?? ''), $name);
        $this->audit->record($actor->id, 'driver.license_photo', 'driver', $driverId, $d['ho_ten'] . ' · đã đính ảnh bằng lái');
        return $name;
    }

    public function removeLicensePhoto(User $actor, int $driverId): void
    {
        $d = $this->ownDriver($actor, $driverId);
        $this->db->exec('UPDATE tai_xe SET anh_gplx = NULL WHERE id = ?', [$driverId]);
        $this->dropPhotoFile((string)($d['anh_gplx'] ?? ''), '');
        $this->audit->record($actor->id, 'driver.license_photo', 'driver', $driverId, $d['ho_ten'] . ' · gỡ ảnh bằng lái');
    }

    private function dropPhotoFile(string $old, string $keep): void
    {
        if ($old !== '' && $old !== $keep && preg_match(self::PHOTO_NAME, $old)) {
            @unlink(self::photoDir() . '/' . $old);
        }
    }

    /** File ảnh bằng lái để phục vụ; null nếu chưa có / file đã mất. Đường dẫn chỉ lấy từ tên do hệ thống sinh trong CSDL, không từ yêu cầu. @return ?array{path:string,mime:string} */
    public function licensePhotoFile(User $actor, int $driverId): ?array
    {
        $name = $this->licensePhotoOf($actor, $driverId);
        if (!$name || !preg_match(self::PHOTO_NAME, $name)) {
            return null;
        }
        $path = self::photoDir() . '/' . $name;
        return is_file($path) ? ['path' => $path, 'mime' => self::PHOTO_MIME[substr($name, strrpos($name, '.') + 1)]] : null;
    }

    /** Tài xế (id) cho trang xem ảnh bằng lái: chỉ trả đường dẫn nếu người xem có quyền xem đội xe và tài xế thuộc phạm vi kho của họ. */
    public function licensePhotoOf(User $actor, int $driverId): ?string
    {
        $this->auth->requirePermission($actor, Permission::XemDoiXe);
        $d = $this->db->one('SELECT kho_id, anh_gplx FROM tai_xe WHERE id = ?', [$driverId]);
        if (!$d || !$d['anh_gplx']) {
            return null;
        }
        if (!$actor->isAdmin && (int)($d['kho_id'] ?? 0) !== (int)$actor->warehouseId) {
            throw new \StockFlow\Domain\Forbidden('Chỉ xem được ảnh bằng lái của tài xế kho mình');
        }
        return (string)$d['anh_gplx'];
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
    public function updateDriver(User $actor, int $driverId, string $name, string $cccd, string $phone, ?array $license = null): void
    {
        $this->ownDriver($actor, $driverId);
        if ($this->db->value("SELECT 1 FROM chuyen_van_chuyen WHERE tai_xe_id = ? AND trang_thai = 'IN_TRANSIT'", [$driverId])) {
            throw new DomainError('Tài xế đang có chuyến chưa giao xong — chờ giao xong mới sửa được');
        }
        [$name, $cccd, $phone] = $this->cleanDriver($name, $cccd, $phone, $driverId);
        $this->db->exec('UPDATE tai_xe SET ho_ten = ?, cccd = ?, so_dien_thoai = ? WHERE id = ?', [$name, $cccd, $phone, $driverId]);
        $license === null || $this->setDriverLicense($actor, $driverId, $license);
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
