<?php
declare(strict_types=1);

namespace StockFlow\Service;

use StockFlow\Core\Db;
use StockFlow\Domain\DomainError;
use StockFlow\Domain\AccountRole;
use StockFlow\Domain\Permission;
use StockFlow\Domain\User;

/** Danh mục: kho, đơn vị tính, mặt hàng, lô hàng và tài khoản (quản trị hệ thống / quản lý kho / nhân viên kho). */
final class CatalogService
{
    public const NO_LOT = 'NOLOT';

    public function __construct(private Db $db, private Authorizer $auth, private AuditLog $audit) {}

    // ───── đọc ─────
    public function warehouses(): array
    {
        return $this->db->all('SELECT * FROM kho ORDER BY ma');
    }

    public function units(): array
    {
        return $this->db->all('SELECT * FROM don_vi_tinh ORDER BY ma');
    }

    public function items(): array
    {
        return $this->db->all('SELECT i.*, u.ma AS unit, (SELECT COUNT(*) FROM lo_hang b WHERE b.mat_hang_id = i.id AND b.so_lo <> ?) AS lots
                               FROM mat_hang i JOIN don_vi_tinh u ON u.id = i.don_vi_tinh_id ORDER BY i.ma_hang', [self::NO_LOT]);
    }

    public function batches(): array
    {
        return $this->db->all('SELECT b.*, i.ma_hang, i.ten FROM lo_hang b JOIN mat_hang i ON i.id = b.mat_hang_id WHERE b.so_lo <> ? ORDER BY i.ma_hang, b.so_lo', [self::NO_LOT]);
    }

    /** Mọi tài khoản kèm kho được gắn (ADMIN trước, rồi theo kho; quản lý đứng trước nhân viên). */
    public function users(): array
    {
        return $this->db->all(
            "SELECT n.id, n.ten_dang_nhap, n.ho_ten, n.vai_tro, n.kho_id, n.hoat_dong, w.ma AS kho_ma, w.ten AS kho_ten, w.dia_chi AS kho_dia_chi
             FROM nguoi_dung n LEFT JOIN kho w ON w.id = n.kho_id
             ORDER BY CASE n.vai_tro WHEN 'ADMIN' THEN 0 WHEN 'MANAGER' THEN 1 ELSE 2 END, w.ma, n.ten_dang_nhap"
        );
    }

    /**
     * Danh sách kho kèm tài khoản quản lý và nhân viên của từng kho.
     * @return list<array> mỗi phần tử là dòng kho + 'quan_ly' (tài khoản hoặc null) + 'nhan_vien' (danh sách tài khoản)
     */
    public function warehouseDirectory(): array
    {
        $byWh = [];
        foreach ($this->db->all("SELECT id, ten_dang_nhap, ho_ten, vai_tro, kho_id, hoat_dong, ngay_tao FROM nguoi_dung WHERE vai_tro <> 'ADMIN' ORDER BY ten_dang_nhap") as $u) {
            $byWh[(int)$u['kho_id']][] = $u;
        }
        $out = [];
        foreach ($this->warehouses() as $w) {
            $accounts = $byWh[(int)$w['id']] ?? [];
            $w['quan_ly'] = null;
            $w['nhan_vien'] = [];
            foreach ($accounts as $u) {
                if ($u['vai_tro'] === AccountRole::Manager->value) {
                    $w['quan_ly'] = $u;
                } else {
                    $w['nhan_vien'][] = $u;
                }
            }
            $out[] = $w;
        }
        return $out;
    }

    // ───── ghi (quản trị) ─────
    public function addWarehouse(User $actor, string $code, string $name, string $address): int
    {
        $this->auth->requireAdmin($actor);
        $code = strtoupper(trim($code));
        if (!preg_match('/^[A-Z0-9_-]{2,20}$/', $code)) {
            throw new DomainError('Mã kho gồm 2–20 ký tự A-Z, 0-9, _ hoặc -');
        }
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120 || mb_strlen($address) > 255) {
            throw new DomainError('Tên/địa chỉ kho không hợp lệ');
        }
        $this->requireUnique('kho', 'ma', $code, "Mã kho $code đã tồn tại");
        $id = $this->db->insert('INSERT INTO kho (ma, ten, dia_chi) VALUES (?,?,?)', [$code, $name, trim($address)]);
        $this->audit->record($actor->id, 'warehouse.create', 'warehouse', $id, $code);
        return $id;
    }

    public function addUnit(User $actor, string $code, string $name): int
    {
        $this->auth->requireAdmin($actor);
        $code = trim($code);
        $name = trim($name);
        if ($code === '' || mb_strlen($code) > 20 || $name === '' || mb_strlen($name) > 60) {
            throw new DomainError('Đơn vị tính không hợp lệ');
        }
        $this->requireUnique('don_vi_tinh', 'ma', $code, "Đơn vị «{$code}» đã tồn tại");
        return $this->db->insert('INSERT INTO don_vi_tinh (ma, ten) VALUES (?,?)', [$code, $name]);
    }

    public function addItem(User $actor, string $sku, string $name, int $unitId, bool $trackBatch, int $reorder, int $packSize = 1): int
    {
        $this->auth->requireAdmin($actor);
        $sku = strtoupper(trim($sku));
        $name = trim($name);
        if (!preg_match('/^[A-Z0-9._-]{2,40}$/', $sku)) {
            throw new DomainError('SKU gồm 2–40 ký tự A-Z, 0-9, ., _ hoặc -');
        }
        if ($name === '' || mb_strlen($name) > 160) {
            throw new DomainError('Tên mặt hàng không hợp lệ');
        }
        if ($reorder < 0) {
            throw new DomainError('Định mức tồn tối thiểu không được âm');
        }
        if ($packSize < 1 || $packSize > 1000000) {
            throw new DomainError('Quy cách lô phải từ 1 trở lên');
        }
        if (!$this->db->value('SELECT 1 FROM don_vi_tinh WHERE id = ?', [$unitId])) {
            throw new DomainError('Đơn vị tính không hợp lệ');
        }
        $this->requireUnique('mat_hang', 'ma_hang', $sku, "SKU $sku đã tồn tại");
        return $this->db->transaction(function () use ($actor, $sku, $name, $unitId, $trackBatch, $reorder, $packSize) {
            $id = $this->db->insert('INSERT INTO mat_hang (ma_hang, ten, don_vi_tinh_id, quan_ly_lo, dinh_muc_toi_thieu, quy_cach_lo) VALUES (?,?,?,?,?,?)',
                [$sku, $name, $unitId, (int)$trackBatch, $reorder, $packSize]);
            if (!$trackBatch) {
                $this->db->exec('INSERT INTO lo_hang (mat_hang_id, so_lo) VALUES (?,?)', [$id, self::NO_LOT]);
            }
            $this->audit->record($actor->id, 'item.create', 'item', $id, $sku);
            return $id;
        });
    }

    /** Sửa quy cách lô (1 lô = bao nhiêu đơn vị tính) và định mức tồn tối thiểu của mặt hàng. */
    public function updateItem(User $actor, int $itemId, int $packSize, int $reorder): void
    {
        $this->auth->requireAdmin($actor);
        if ($packSize < 1 || $packSize > 1000000) {
            throw new DomainError('Quy cách lô phải từ 1 trở lên');
        }
        if ($reorder < 0) {
            throw new DomainError('Định mức tồn tối thiểu không được âm');
        }
        $item = $this->db->one('SELECT ma_hang FROM mat_hang WHERE id = ?', [$itemId]) ?? throw new DomainError('Mặt hàng không tồn tại');
        $this->db->exec('UPDATE mat_hang SET quy_cach_lo = ?, dinh_muc_toi_thieu = ? WHERE id = ?', [$packSize, $reorder, $itemId]);
        $this->audit->record($actor->id, 'item.update', 'item', $itemId, "{$item['ma_hang']} · quy cách $packSize · định mức $reorder");
    }

    /** Thêm lô cho mặt hàng quản lý theo lô — tài khoản được giao chức năng «Thêm lô hàng». */
    public function addBatch(User $actor, int $itemId, string $batchNo, ?string $expiry): int
    {
        $this->auth->requirePermission($actor, Permission::ThemLo);
        $batchNo = strtoupper(trim($batchNo));
        if (!preg_match('/^[A-Z0-9._-]{1,40}$/', $batchNo) || $batchNo === self::NO_LOT) {
            throw new DomainError('Số lô gồm 1–40 ký tự A-Z, 0-9, ., _ hoặc -');
        }
        $item = $this->db->one('SELECT * FROM mat_hang WHERE id = ? AND hoat_dong', [$itemId]);
        if (!$item || !$item['quan_ly_lo']) {
            throw new DomainError('Mặt hàng không tồn tại hoặc không quản lý theo lô');
        }
        if ($expiry !== null && $expiry !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry) || !checkdate((int)substr($expiry, 5, 2), (int)substr($expiry, 8, 2), (int)substr($expiry, 0, 4)))) {
            throw new DomainError('Hạn dùng không hợp lệ');
        }
        if ($this->db->value('SELECT 1 FROM lo_hang WHERE mat_hang_id = ? AND so_lo = ?', [$itemId, $batchNo])) {
            throw new DomainError("Lô $batchNo đã tồn tại cho mặt hàng này");
        }
        $id = $this->db->insert('INSERT INTO lo_hang (mat_hang_id, so_lo, han_dung) VALUES (?,?,?)', [$itemId, $batchNo, $expiry ?: null]);
        $this->audit->record($actor->id, 'batch.create', 'batch', $id, "{$item['ma_hang']}/$batchNo");
        return $id;
    }

    /** Kiểm tra vai trò + kho của tài khoản và quy tắc «mỗi kho đúng một quản lý». @return ?int kho_id hợp lệ (null với ADMIN) */
    private function checkPlacement(AccountRole $role, ?int $warehouseId, ?int $exceptUserId = null): ?int
    {
        if ($role === AccountRole::Admin) {
            return null;
        }
        $wh = $warehouseId ? $this->db->one('SELECT id, ma, hoat_dong FROM kho WHERE id = ?', [$warehouseId]) : null;
        if (!$wh || !$wh['hoat_dong']) {
            throw new DomainError('Tài khoản ' . mb_strtolower($role->label()) . ' phải gắn với một kho đang hoạt động');
        }
        if ($role === AccountRole::Manager) {
            $cur = $this->db->one("SELECT id, ten_dang_nhap FROM nguoi_dung WHERE quan_ly_kho_id = ? AND id <> ?", [$warehouseId, $exceptUserId ?? 0]);
            if ($cur) {
                throw new DomainError("Kho {$wh['ma']} đã có quản lý là «{$cur['ten_dang_nhap']}» — mỗi kho chỉ có một quản lý. Hãy chuyển người đó sang nhân viên hoặc kho khác trước.");
            }
        }
        return (int)$warehouseId;
    }

    public function createAccount(User $actor, string $username, string $fullName, string $password, AccountRole $role, ?int $warehouseId): int
    {
        $this->auth->requirePermission($actor, Permission::QuanLyTaiKhoan);
        return $this->insertAccount($actor, $username, $fullName, $password, $role, $warehouseId);
    }

    /**
     * Thêm tài khoản NHÂN VIÊN vào kho. Quản lý kho chỉ thêm được cho kho của chính mình (kho lấy từ tài khoản,
     * không nhận từ form); tài khoản cấp cao chọn kho bất kỳ.
     */
    public function createStaff(User $actor, string $username, string $fullName, string $password, ?int $warehouseId = null): int
    {
        $this->auth->requirePermission($actor, Permission::QuanLyNhanVien);
        $warehouseId = $actor->isAdmin ? $warehouseId : $actor->warehouseId;
        if ($warehouseId === null) {
            throw new DomainError('Hãy chọn kho cho nhân viên');
        }
        $this->auth->require($actor, $warehouseId, Permission::QuanLyNhanVien);
        return $this->insertAccount($actor, $username, $fullName, $password, AccountRole::Staff, $warehouseId);
    }

    private function insertAccount(User $actor, string $username, string $fullName, string $password, AccountRole $role, ?int $warehouseId): int
    {
        $username = strtolower(trim($username));
        if (!preg_match('/^[a-z0-9._-]{3,40}$/', $username)) {
            throw new DomainError('Tên đăng nhập gồm 3–40 ký tự a-z, 0-9, ., _ hoặc -');
        }
        if (trim($fullName) === '' || mb_strlen($fullName) > 120) {
            throw new DomainError('Họ tên không hợp lệ');
        }
        if (strlen($password) < 8) {
            throw new DomainError('Mật khẩu tối thiểu 8 ký tự');
        }
        $this->requireUnique('nguoi_dung', 'ten_dang_nhap', $username, "Tên đăng nhập $username đã tồn tại");
        $kho = $this->checkPlacement($role, $warehouseId);
        $id = $this->db->insert('INSERT INTO nguoi_dung (ten_dang_nhap, ho_ten, mat_khau_ma_hoa, vai_tro, kho_id, quan_ly_kho_id) VALUES (?,?,?,?,?,?)',
            [$username, trim($fullName), password_hash($password, PASSWORD_DEFAULT), $role->value, $kho, $role === AccountRole::Manager ? $kho : null]);
        $this->audit->record($actor->id, 'user.create', 'user', $id, "$username · {$role->value}" . ($kho ? " · kho #$kho" : ''));
        return $id;
    }

    /** Đổi vai trò và/hoặc kho của tài khoản. Chức năng được dùng thay đổi theo vai trò mới ngay ở lần tải trang kế tiếp. */
    public function updateAccount(User $actor, int $userId, AccountRole $role, ?int $warehouseId): void
    {
        $this->auth->requirePermission($actor, Permission::QuanLyTaiKhoan);
        $u = $this->db->one('SELECT * FROM nguoi_dung WHERE id = ?', [$userId]) ?? throw new DomainError('Tài khoản không tồn tại');
        if ($userId === $actor->id && $role !== $actor->role) {
            throw new DomainError('Không tự đổi vai trò của chính mình — hãy nhờ một quản trị viên khác');
        }
        if ($u['vai_tro'] === AccountRole::Admin->value && $role !== AccountRole::Admin && $this->activeAdmins($userId) === 0) {
            throw new DomainError('Hệ thống phải còn ít nhất một tài khoản quản trị hoạt động');
        }
        $kho = $this->checkPlacement($role, $warehouseId, $userId);
        $this->db->exec('UPDATE nguoi_dung SET vai_tro = ?, kho_id = ?, quan_ly_kho_id = ? WHERE id = ?',
            [$role->value, $kho, $role === AccountRole::Manager ? $kho : null, $userId]);
        $this->audit->record($actor->id, 'user.update', 'user', $userId, "{$u['ten_dang_nhap']} → {$role->value}" . ($kho ? " · kho #$kho" : ''));
        $this->auth->forget();
    }

    public function setAccountActive(User $actor, int $userId, bool $active): void
    {
        $this->auth->requirePermission($actor, Permission::QuanLyTaiKhoan);
        $u = $this->db->one('SELECT * FROM nguoi_dung WHERE id = ?', [$userId]) ?? throw new DomainError('Tài khoản không tồn tại');
        if (!$active && $userId === $actor->id) {
            throw new DomainError('Không thể tự khoá tài khoản đang đăng nhập');
        }
        if (!$active && $u['vai_tro'] === AccountRole::Admin->value && $this->activeAdmins($userId) === 0) {
            throw new DomainError('Hệ thống phải còn ít nhất một tài khoản quản trị hoạt động');
        }
        $this->db->exec('UPDATE nguoi_dung SET hoat_dong = ? WHERE id = ?', [(int)$active, $userId]);
        $this->audit->record($actor->id, $active ? 'user.unlock' : 'user.lock', 'user', $userId, $u['ten_dang_nhap']);
    }

    public function resetPassword(User $actor, int $userId, string $password): void
    {
        $this->auth->requirePermission($actor, Permission::QuanLyTaiKhoan);
        if (strlen($password) < 8) {
            throw new DomainError('Mật khẩu tối thiểu 8 ký tự');
        }
        $u = $this->db->one('SELECT ten_dang_nhap FROM nguoi_dung WHERE id = ?', [$userId]) ?? throw new DomainError('Tài khoản không tồn tại');
        $this->db->exec('UPDATE nguoi_dung SET mat_khau_ma_hoa = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $userId]);
        $this->audit->record($actor->id, 'user.password', 'user', $userId, $u['ten_dang_nhap']);
    }

    /** Số tài khoản ADMIN đang hoạt động, không tính một tài khoản (để chặn khoá/hạ cấp admin cuối cùng). */
    private function activeAdmins(int $exceptUserId): int
    {
        return (int)$this->db->value("SELECT COUNT(*) FROM nguoi_dung WHERE vai_tro = 'ADMIN' AND hoat_dong AND id <> ?", [$exceptUserId]);
    }

    private function requireUnique(string $table, string $column, string $value, string $message): void
    {
        if ($this->db->value("SELECT 1 FROM $table WHERE $column = ?", [$value])) {
            throw new DomainError($message);
        }
    }
}
