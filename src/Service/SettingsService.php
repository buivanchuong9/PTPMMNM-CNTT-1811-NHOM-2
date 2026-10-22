<?php
declare(strict_types=1);

namespace StockFlow\Service;

use StockFlow\Core\Db;
use StockFlow\Domain\AccountRole;
use StockFlow\Domain\DomainError;
use StockFlow\Domain\Forbidden;
use StockFlow\Domain\Permission;
use StockFlow\Domain\User;

/**
 * Cài đặt theo ba cấp:
 *  - mọi tài khoản: hồ sơ, mật khẩu, tuỳ chọn hiển thị cá nhân;
 *  - quản lý kho: thông tin kho của mình và tài khoản nhân viên trong kho đó;
 *  - quản trị hệ thống: tham số toàn hệ thống + nhật ký thao tác.
 * Cài đặt lưu ở bảng cai_dat; thiếu dòng thì dùng mặc định bên dưới.
 */
final class SettingsService
{
    public const ROWS = [10, 20, 50, 100];
    public const IDLE = [0, 30, 60, 120, 480];
    public const DEFAULT_ROWS = 20;
    public const DEFAULT_MIN_PASSWORD = 8;

    /** @var array<string,array<string,string>> */
    private array $cache = [];

    public function __construct(private Db $db, private Authorizer $auth, private AuditLog $audit) {}

    // ───── đọc ─────
    private function scope(string $scope, int $id): array
    {
        $k = "$scope:$id";
        if (!isset($this->cache[$k])) {
            $this->cache[$k] = array_column($this->db->all('SELECT khoa, gia_tri FROM cai_dat WHERE pham_vi = ? AND doi_tuong_id = ?', [$scope, $id]), 'gia_tri', 'khoa');
        }
        return $this->cache[$k];
    }

    private function put(string $scope, int $id, string $key, string $value): void
    {
        $exists = $this->db->value('SELECT COUNT(*) FROM cai_dat WHERE pham_vi = ? AND doi_tuong_id = ? AND khoa = ?', [$scope, $id, $key]);
        $exists
            ? $this->db->exec('UPDATE cai_dat SET gia_tri = ?, cap_nhat = CURRENT_TIMESTAMP WHERE pham_vi = ? AND doi_tuong_id = ? AND khoa = ?', [$value, $scope, $id, $key])
            : $this->db->exec('INSERT INTO cai_dat (pham_vi, doi_tuong_id, khoa, gia_tri) VALUES (?,?,?,?)', [$scope, $id, $key, $value]);
        unset($this->cache["$scope:$id"]);
    }

    /** @return array{rows_default:int, min_password:int, idle_minutes:int} */
    public function system(): array
    {
        $s = $this->scope('system', 0);
        $rows = (int)($s['rows_default'] ?? self::DEFAULT_ROWS);
        $idle = (int)($s['idle_minutes'] ?? 0);
        return [
            'rows_default' => in_array($rows, self::ROWS, true) ? $rows : self::DEFAULT_ROWS,
            'min_password' => min(32, max(6, (int)($s['min_password'] ?? self::DEFAULT_MIN_PASSWORD))),
            'idle_minutes' => in_array($idle, self::IDLE, true) ? $idle : 0,
        ];
    }

    public function minPassword(): int
    {
        return $this->system()['min_password'];
    }

    /** @return array{rows:int, rows_choice:int, compact:bool} rows_choice = 0 nghĩa là theo mặc định hệ thống */
    public function preferences(?User $user): array
    {
        $default = $this->system()['rows_default'];
        $u = $user ? $this->scope('user', $user->id) : [];
        $choice = (int)($u['rows_per_page'] ?? 0);
        $choice = in_array($choice, self::ROWS, true) ? $choice : 0;
        return ['rows' => $choice ?: $default, 'rows_choice' => $choice, 'compact' => ($u['compact'] ?? '0') === '1'];
    }

    // ───── mọi tài khoản ─────
    public function savePreferences(User $actor, int $rows, bool $compact): void
    {
        if ($rows !== 0 && !in_array($rows, self::ROWS, true)) {
            throw new DomainError('Số dòng mỗi trang không hợp lệ');
        }
        $this->put('user', $actor->id, 'rows_per_page', (string)$rows);
        $this->put('user', $actor->id, 'compact', $compact ? '1' : '0');
    }

    public function updateProfile(User $actor, string $fullName): void
    {
        $fullName = trim($fullName);
        if ($fullName === '' || mb_strlen($fullName) > 120) {
            throw new DomainError('Họ tên không hợp lệ (1–120 ký tự)');
        }
        $this->db->exec('UPDATE nguoi_dung SET ho_ten = ? WHERE id = ?', [$fullName, $actor->id]);
        $this->audit->record($actor->id, 'user.profile', 'user', $actor->id, $actor->username);
    }

    public function changePassword(User $actor, string $current, string $new, string $confirm): void
    {
        $hash = (string)$this->db->value('SELECT mat_khau_ma_hoa FROM nguoi_dung WHERE id = ?', [$actor->id]);
        if (!password_verify($current, $hash)) {
            throw new DomainError('Mật khẩu hiện tại không đúng');
        }
        $min = $this->minPassword();
        if (strlen($new) < $min) {
            throw new DomainError("Mật khẩu mới tối thiểu $min ký tự");
        }
        if ($new !== $confirm) {
            throw new DomainError('Nhập lại mật khẩu mới chưa khớp');
        }
        if (hash_equals($current, $new)) {
            throw new DomainError('Mật khẩu mới phải khác mật khẩu hiện tại');
        }
        $this->db->exec('UPDATE nguoi_dung SET mat_khau_ma_hoa = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $actor->id]);
        $this->audit->record($actor->id, 'user.password.self', 'user', $actor->id, $actor->username);
    }

    // ───── quản lý kho ─────
    private function managerWarehouse(User $actor): int
    {
        if ($actor->role !== AccountRole::Manager || $actor->warehouseId === null) {
            throw new Forbidden('Chỉ quản lý kho mới chỉnh được thông tin kho của mình');
        }
        return $actor->warehouseId;
    }

    public function updateWarehouse(User $actor, string $name, string $address): void
    {
        $id = $this->managerWarehouse($actor);
        $name = trim($name);
        $address = trim($address);
        if ($name === '' || mb_strlen($name) > 120 || mb_strlen($address) > 255) {
            throw new DomainError('Tên kho (1–120 ký tự) hoặc địa chỉ (tối đa 255 ký tự) không hợp lệ');
        }
        $this->db->exec('UPDATE kho SET ten = ?, dia_chi = ? WHERE id = ?', [$name, $address, $id]);
        $this->audit->record($actor->id, 'warehouse.update', 'warehouse', $id, $name);
    }

    /** @return list<array<string,mixed>> nhân viên của kho mà quản lý phụ trách */
    public function staffOf(User $actor): array
    {
        $id = $this->managerWarehouse($actor);
        return $this->db->all("SELECT id, ten_dang_nhap, ho_ten, hoat_dong FROM nguoi_dung WHERE kho_id = ? AND vai_tro = 'STAFF' ORDER BY ho_ten", [$id]);
    }

    private function ownStaff(User $actor, int $userId): array
    {
        $wh = $this->managerWarehouse($actor);
        $this->auth->require($actor, $wh, Permission::QuanLyNhanVien);
        return $this->db->one("SELECT * FROM nguoi_dung WHERE id = ? AND kho_id = ? AND vai_tro = 'STAFF'", [$userId, $wh])
            ?? throw new Forbidden('Chỉ thao tác được trên nhân viên thuộc kho của bạn');
    }

    /** Quản lý sửa tên đăng nhập và (tuỳ chọn) đặt lại mật khẩu cho nhân viên kho mình. Mật khẩu trống = giữ nguyên. */
    public function editStaff(User $actor, int $userId, string $username, string $newPassword = ''): void
    {
        $u = $this->ownStaff($actor, $userId);
        $username = User::normalizeUsername($username);
        $min = $this->minPassword();
        if ($newPassword !== '' && strlen($newPassword) < $min) {
            throw new DomainError("Mật khẩu tối thiểu $min ký tự");
        }
        $this->db->transaction(function () use ($actor, $userId, $u, $username, $newPassword) {
            if ($username !== $u['ten_dang_nhap']) {
                if ($this->db->one('SELECT id FROM nguoi_dung WHERE ten_dang_nhap = ? AND id <> ?', [$username, $userId])) {
                    throw new DomainError("Tên đăng nhập $username đã tồn tại");
                }
                $this->db->exec('UPDATE nguoi_dung SET ten_dang_nhap = ? WHERE id = ?', [$username, $userId]);
                $this->audit->record($actor->id, 'user.rename', 'user', $userId, "{$u['ten_dang_nhap']} → $username");
            }
            if ($newPassword !== '') {
                $this->db->exec('UPDATE nguoi_dung SET mat_khau_ma_hoa = ? WHERE id = ?', [password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
                $this->audit->record($actor->id, 'user.password', 'user', $userId, $username);
            }
        });
    }

    public function setStaffActive(User $actor, int $userId, bool $active): void
    {
        $u = $this->ownStaff($actor, $userId);
        $this->db->exec('UPDATE nguoi_dung SET hoat_dong = ? WHERE id = ?', [(int)$active, $userId]);
        $this->audit->record($actor->id, $active ? 'user.unlock' : 'user.lock', 'user', $userId, $u['ten_dang_nhap']);
    }

    public function resetStaffPassword(User $actor, int $userId, string $password): void
    {
        $u = $this->ownStaff($actor, $userId);
        $min = $this->minPassword();
        if (strlen($password) < $min) {
            throw new DomainError("Mật khẩu tối thiểu $min ký tự");
        }
        $this->db->exec('UPDATE nguoi_dung SET mat_khau_ma_hoa = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $userId]);
        $this->audit->record($actor->id, 'user.password', 'user', $userId, $u['ten_dang_nhap']);
    }

    // ───── quản trị hệ thống ─────
    public function saveSystem(User $actor, int $rows, int $minPassword, int $idle): void
    {
        $this->auth->requireAdmin($actor);
        if (!in_array($rows, self::ROWS, true) || !in_array($idle, self::IDLE, true) || $minPassword < 6 || $minPassword > 32) {
            throw new DomainError('Giá trị cài đặt không hợp lệ');
        }
        $this->put('system', 0, 'rows_default', (string)$rows);
        $this->put('system', 0, 'min_password', (string)$minPassword);
        $this->put('system', 0, 'idle_minutes', (string)$idle);
        $this->audit->record($actor->id, 'settings.system', 'settings', null, "rows=$rows · mật khẩu≥$minPassword · tự thoát=$idle phút");
    }

    /** @return array<string,int> */
    public function overview(): array
    {
        $count = fn(string $sql) => (int)$this->db->value($sql);
        return [
            'kho' => $count('SELECT COUNT(*) FROM kho WHERE hoat_dong'),
            'tai_khoan' => $count('SELECT COUNT(*) FROM nguoi_dung WHERE hoat_dong'),
            'tai_khoan_khoa' => $count('SELECT COUNT(*) FROM nguoi_dung WHERE NOT hoat_dong'),
            'mat_hang' => $count('SELECT COUNT(*) FROM mat_hang WHERE hoat_dong'),
            'phieu' => $count('SELECT COUNT(*) FROM phieu_nhap_xuat'),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function recentAudit(User $actor, int $limit = 40): array
    {
        $this->auth->requireAdmin($actor);
        return $this->db->all('SELECT a.id, a.hanh_dong, a.doi_tuong, a.chi_tiet, a.ngay_tao, u.ten_dang_nhap, u.ho_ten
                               FROM nhat_ky a LEFT JOIN nguoi_dung u ON u.id = a.nguoi_dung_id ORDER BY a.id DESC LIMIT ' . (int)$limit);
    }
}
