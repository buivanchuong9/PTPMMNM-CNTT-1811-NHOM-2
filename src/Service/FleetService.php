<?php
declare(strict_types=1);

namespace StockFlow\Service;

use StockFlow\Core\Db;
use StockFlow\Domain\DomainError;
use StockFlow\Domain\Permission;
use StockFlow\Domain\User;

/**
 * Đội xe & tài xế của hệ thống (chỉ tài khoản cấp cao quản lý).
 * Mỗi kho là «kho gốc» của 1–2 xe; xe luôn ở một kho hoặc đang chạy tới kho khác. Tài xế được chọn cho từng chuyến điều chuyển.
 */
final class FleetService
{
    public const MAX_VEHICLES_PER_WAREHOUSE = 2;

    public function __construct(private Db $db, private Authorizer $auth, private AuditLog $audit) {}

    // ───── tra cứu ─────

    /** Xe kèm vị trí hiện tại, kho đang tới và tài xế của chuyến đang chạy. @return list<array<string,mixed>> */
    public function vehicles(): array
    {
        return $this->db->all(
            "SELECT x.*, g.ma AS goc_ma, h.ma AS ht_ma, h.ten AS ht_ten, d.ma AS den_ma, d.ten AS den_ten,
                    t.ho_ten AS tai_xe_ten, t.cccd AS tai_xe_cccd, t.so_dien_thoai AS tai_xe_sdt, t.hoat_dong AS tai_xe_hoat_dong,
                    c.id AS chuyen_id, p.ma AS phieu_ma, p.id AS phieu_id
             FROM xe x JOIN kho g ON g.id = x.kho_goc_id LEFT JOIN kho h ON h.id = x.kho_hien_tai_id LEFT JOIN kho d ON d.id = x.dang_den_kho_id
             LEFT JOIN tai_xe t ON t.id = x.tai_xe_id
             LEFT JOIN chuyen_van_chuyen c ON c.xe_id = x.id AND c.trang_thai = 'IN_TRANSIT'
             LEFT JOIN phieu_nhap_xuat p ON p.id = c.phieu_di_id
             ORDER BY g.ma, x.bien_so");
    }

    /** Tài xế kèm chuyến đang chạy (nếu có). @return list<array<string,mixed>> */
    public function drivers(): array
    {
        return $this->db->all(
            "SELECT t.*, v.id AS xe_phu_trach_id, v.bien_so AS xe_phu_trach, c.id AS chuyen_id, p.ma AS phieu_ma, p.id AS phieu_id
             FROM tai_xe t LEFT JOIN xe v ON v.tai_xe_id = t.id
             LEFT JOIN chuyen_van_chuyen c ON c.tai_xe_id = t.id AND c.trang_thai = 'IN_TRANSIT'
             LEFT JOIN phieu_nhap_xuat p ON p.id = c.phieu_di_id
             ORDER BY t.ho_ten");
    }

    /** Các chuyến gần đây (mới nhất trước). @return list<array<string,mixed>> */
    public function recentTrips(int $limit = 20): array
    {
        return $this->db->all(
            "SELECT c.*, x.bien_so, t.ho_ten AS tai_xe_ten, a.ma AS phieu_di_ma, b.ma AS phieu_den_ma, kd.ma AS di_kho, kn.ma AS den_kho
             FROM chuyen_van_chuyen c JOIN xe x ON x.id = c.xe_id JOIN tai_xe t ON t.id = c.tai_xe_id
             JOIN phieu_nhap_xuat a ON a.id = c.phieu_di_id JOIN phieu_nhap_xuat b ON b.id = c.phieu_den_id
             JOIN kho kd ON kd.id = c.kho_di_id JOIN kho kn ON kn.id = c.kho_den_id
             ORDER BY c.id DESC LIMIT " . (int)$limit);
    }

    /** Số chuyến đang có báo sai lệch chưa xử lý xong. */
    public function openDiscrepancies(): int
    {
        return (int)$this->db->value("SELECT COUNT(*) FROM chuyen_van_chuyen WHERE trang_thai = 'IN_TRANSIT' AND sai_lech <> ''");
    }

    /** Xe sẵn sàng tại một kho (đang đứng ở kho đó, còn hoạt động) kèm tài xế phụ trách (có thể trống). @return list<array<string,mixed>> */
    public function availableVehicles(int $warehouseId): array
    {
        return $this->db->all(
            'SELECT x.id, x.bien_so, x.loai_xe, x.tai_xe_id, t.ho_ten, t.cccd, t.hoat_dong AS tai_xe_hoat_dong
             FROM xe x LEFT JOIN tai_xe t ON t.id = x.tai_xe_id WHERE x.hoat_dong AND x.kho_hien_tai_id = ? ORDER BY x.bien_so', [$warehouseId]);
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
        $this->auth->requirePermission($actor, Permission::QuanLyDoiXe);
        [$plate, $type] = $this->cleanVehicle($plate, $type, $homeWarehouseId, null, $driverId);
        $id = $this->db->insert('INSERT INTO xe (bien_so, loai_xe, kho_goc_id, kho_hien_tai_id, tai_xe_id) VALUES (?,?,?,?,?)', [$plate, $type, $homeWarehouseId, $homeWarehouseId, $driverId]);
        $this->audit->record($actor->id, 'vehicle.create', 'vehicle', $id, $plate);
        return $id;
    }

    /** Sửa biển số, loại xe, kho gốc, tài xế phụ trách. Vị trí hiện tại của xe không đổi (điều xe dùng relocateVehicle). */
    public function updateVehicle(User $actor, int $vehicleId, string $plate, string $type, int $homeWarehouseId, ?int $driverId = null): void
    {
        $this->auth->requirePermission($actor, Permission::QuanLyDoiXe);
        $v = $this->db->one('SELECT * FROM xe WHERE id = ?', [$vehicleId]) ?? throw new DomainError('Không tìm thấy xe');
        if ($v['kho_hien_tai_id'] === null && (int)($v['tai_xe_id'] ?? 0) !== (int)$driverId) {
            throw new DomainError('Xe đang chạy trên đường — chưa đổi được tài xế phụ trách');
        }
        [$plate, $type] = $this->cleanVehicle($plate, $type, $homeWarehouseId, $v, $driverId);
        $this->db->exec('UPDATE xe SET bien_so = ?, loai_xe = ?, kho_goc_id = ?, tai_xe_id = ? WHERE id = ?', [$plate, $type, $homeWarehouseId, $driverId, $vehicleId]);
        $this->audit->record($actor->id, 'vehicle.update', 'vehicle', $vehicleId, $plate);
    }

    /** Xoá xe chưa từng chạy chuyến nào. Xe đã có lịch sử vận chuyển chỉ được «Ngừng dùng» để giữ nguyên hồ sơ các chuyến cũ. */
    public function deleteVehicle(User $actor, int $vehicleId): void
    {
        $this->auth->requirePermission($actor, Permission::QuanLyDoiXe);
        $v = $this->db->one('SELECT * FROM xe WHERE id = ?', [$vehicleId]) ?? throw new DomainError('Không tìm thấy xe');
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
            if (!$d['hoat_dong'] && (int)($current['tai_xe_id'] ?? 0) !== $driverId) {
                throw new DomainError('Tài xế ' . $d['ho_ten'] . ' đã ngừng dùng');
            }
            if ($other = $this->db->value('SELECT bien_so FROM xe WHERE tai_xe_id = ? AND id <> ?', [$driverId, (int)($current['id'] ?? 0)])) {
                throw new DomainError('Tài xế ' . $d['ho_ten'] . " đang phụ trách xe $other — mỗi tài xế chỉ một xe");
            }
        }
        // Thêm mới, hoặc đổi sang kho gốc khác (xe còn hoạt động): kho gốc đó không được quá số xe cho phép
        $joinsHome = $current === null || ((int)$current['kho_goc_id'] !== $homeWarehouseId && $current['hoat_dong']);
        if ($joinsHome && (int)$this->db->value('SELECT COUNT(*) FROM xe WHERE kho_goc_id = ? AND hoat_dong', [$homeWarehouseId]) >= self::MAX_VEHICLES_PER_WAREHOUSE) {
            throw new DomainError('Mỗi kho chỉ có tối đa ' . self::MAX_VEHICLES_PER_WAREHOUSE . ' xe');
        }
        return [$plate, $type];
    }

    /** Ngừng / dùng lại xe. Xe đang chạy không ngừng được. Dùng lại xe vẫn phải giữ tối đa 2 xe hoạt động mỗi kho gốc. */
    public function setVehicleActive(User $actor, int $vehicleId, bool $active): void
    {
        $this->auth->requirePermission($actor, Permission::QuanLyDoiXe);
        $v = $this->db->one('SELECT * FROM xe WHERE id = ?', [$vehicleId]) ?? throw new DomainError('Không tìm thấy xe');
        if (!$active && $v['kho_hien_tai_id'] === null) {
            throw new DomainError('Xe đang chạy trên đường — chờ kho đến nhận hàng xong mới ngừng được');
        }
        if ($active && !$v['hoat_dong']
            && (int)$this->db->value('SELECT COUNT(*) FROM xe WHERE kho_goc_id = ? AND hoat_dong', [$v['kho_goc_id']]) >= self::MAX_VEHICLES_PER_WAREHOUSE) {
            throw new DomainError('Kho gốc của xe này đã đủ ' . self::MAX_VEHICLES_PER_WAREHOUSE . ' xe hoạt động');
        }
        $this->db->exec('UPDATE xe SET hoat_dong = ? WHERE id = ?', [$active ? 1 : 0, $vehicleId]);
        $this->audit->record($actor->id, $active ? 'vehicle.enable' : 'vehicle.disable', 'vehicle', $vehicleId, $v['bien_so']);
    }

    /** Ghi nhận xe đang đứng ở kho khác (điều xe thủ công). Xe đang chạy thì không đổi được. */
    public function relocateVehicle(User $actor, int $vehicleId, int $warehouseId): void
    {
        $this->auth->requirePermission($actor, Permission::QuanLyDoiXe);
        $v = $this->db->one('SELECT * FROM xe WHERE id = ?', [$vehicleId]) ?? throw new DomainError('Không tìm thấy xe');
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

    public function addDriver(User $actor, string $name, string $cccd, string $phone): int
    {
        $this->auth->requirePermission($actor, Permission::QuanLyDoiXe);
        [$name, $cccd, $phone] = $this->cleanDriver($name, $cccd, $phone, 0);
        $id = $this->db->insert('INSERT INTO tai_xe (ho_ten, cccd, so_dien_thoai) VALUES (?,?,?)', [$name, $cccd, $phone]);
        $this->audit->record($actor->id, 'driver.create', 'driver', $id, $name);
        return $id;
    }

    /** Sửa thông tin tài xế (không sửa được khi tài xế đang có chuyến chưa giao xong — kho đến đang đối chiếu theo thông tin này). */
    public function updateDriver(User $actor, int $driverId, string $name, string $cccd, string $phone): void
    {
        $this->auth->requirePermission($actor, Permission::QuanLyDoiXe);
        $this->db->one('SELECT 1 FROM tai_xe WHERE id = ?', [$driverId]) ?? throw new DomainError('Không tìm thấy tài xế');
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
        $this->auth->requirePermission($actor, Permission::QuanLyDoiXe);
        $d = $this->db->one('SELECT * FROM tai_xe WHERE id = ?', [$driverId]) ?? throw new DomainError('Không tìm thấy tài xế');
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
        $this->auth->requirePermission($actor, Permission::QuanLyDoiXe);
        $d = $this->db->one('SELECT * FROM tai_xe WHERE id = ?', [$driverId]) ?? throw new DomainError('Không tìm thấy tài xế');
        if (!$active && $this->db->value("SELECT 1 FROM chuyen_van_chuyen WHERE tai_xe_id = ? AND trang_thai = 'IN_TRANSIT'", [$driverId])) {
            throw new DomainError('Tài xế đang có chuyến chưa giao xong — chưa ngừng được');
        }
        $this->db->exec('UPDATE tai_xe SET hoat_dong = ? WHERE id = ?', [$active ? 1 : 0, $driverId]);
        $this->audit->record($actor->id, $active ? 'driver.enable' : 'driver.disable', 'driver', $driverId, $d['ho_ten']);
    }
}
