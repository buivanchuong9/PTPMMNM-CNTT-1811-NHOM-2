<?php
declare(strict_types=1);

namespace StockFlow\Service;

use StockFlow\Core\Db;
use StockFlow\Domain\AccountRole;
use StockFlow\Domain\Forbidden;
use StockFlow\Domain\Permission;
use StockFlow\Domain\User;

/**
 * Phân quyền phía máy chủ. Hai lớp:
 *  1. Chức năng (Permission): vai trò của tài khoản có được dùng chức năng đó không — theo bảng phan_quyen_vai_tro
 *     (thiếu dòng thì dùng mặc định trong Permission::defaultFor). ADMIN luôn toàn quyền.
 *  2. Phạm vi kho: tài khoản MANAGER / STAFF chỉ thao tác trong kho được gắn; ADMIN thao tác ở mọi kho.
 * Mọi service gọi require() trước khi ghi dữ liệu — ẩn nút trên giao diện chỉ là tiện lợi, không phải lớp bảo vệ.
 */
final class Authorizer
{
    /** @var array<string,array<string,bool>>|null vai trò → chức năng → được phép (đã gộp mặc định) */
    private ?array $matrix = null;
    /** @var array<int,bool> kho → đang hoạt động */
    private array $active = [];

    public function __construct(private Db $db) {}

    public function forget(): void
    {
        $this->matrix = null;
        $this->active = [];
    }

    /** @return array<string,array<string,bool>> */
    public function matrix(): array
    {
        if ($this->matrix !== null) {
            return $this->matrix;
        }
        $stored = [];
        try {
            foreach ($this->db->all('SELECT vai_tro, quyen, duoc_phep FROM phan_quyen_vai_tro') as $r) {
                $stored[$r['vai_tro']][$r['quyen']] = (bool)$r['duoc_phep'];
            }
        } catch (\Throwable) {
            // CSDL cũ chưa có bảng phân quyền ⇒ dùng mặc định
        }
        $m = [];
        foreach (AccountRole::configurable() as $role) {
            foreach (Permission::cases() as $p) {
                $m[$role->value][$p->value] = !$p->adminOnly() && ($stored[$role->value][$p->value] ?? $p->defaultFor($role));
            }
        }
        return $this->matrix = $m;
    }

    /** Vai trò của tài khoản có chức năng này không (chưa xét kho). */
    public function allows(User $user, Permission $permission): bool
    {
        if ($user->isAdmin) {
            return !$permission->operational(); // chủ hệ thống: xem + quản lý, không thao tác nhập/xuất/kiểm kê
        }
        return $this->matrix()[$user->role->value][$permission->value] ?? false;
    }

    /** Có chức năng này VÀ kho nằm trong phạm vi tài khoản (ADMIN: mọi kho). */
    public function can(User $user, int $warehouseId, Permission $permission): bool
    {
        return $this->allows($user, $permission) && ($user->isAdmin || $user->warehouseId === $warehouseId);
    }

    public function require(User $user, int $warehouseId, Permission $permission): void
    {
        if (!$this->allows($user, $permission)) {
            $this->requirePermission($user, $permission);
        }
        if (!$user->isAdmin && $user->warehouseId !== $warehouseId) {
            $code = $this->db->value('SELECT ma FROM kho WHERE id = ?', [$warehouseId]) ?? "#$warehouseId";
            throw new Forbidden("Tài khoản của bạn thuộc kho khác — không được thao tác tại kho $code");
        }
    }

    public function requirePermission(User $user, Permission $permission): void
    {
        if (!$this->allows($user, $permission)) {
            throw new Forbidden($user->isAdmin
                ? "Tài khoản quản trị chỉ xem thống kê và quản lý — chức năng «{$permission->label()}» do quản lý / nhân viên kho thực hiện"
                : "Tài khoản {$user->role->label()} không được dùng chức năng «{$permission->label()}»");
        }
    }

    public function requireAdmin(User $user): void
    {
        if (!$user->isAdmin) {
            throw new Forbidden('Chỉ tài khoản quản trị hệ thống mới được thực hiện thao tác này');
        }
    }

    public function canAnywhere(User $user, Permission $permission): bool
    {
        return $this->allows($user, $permission);
    }

    /** Các kho tài khoản được xem: ADMIN — mọi kho đang hoạt động; còn lại — đúng kho được gắn. */
    public function visibleWarehouseIds(User $user): array
    {
        if ($user->isAdmin) {
            return array_map('intval', array_column($this->db->all('SELECT id FROM kho WHERE hoat_dong'), 'id'));
        }
        if ($user->warehouseId === null) {
            return [];
        }
        $this->active[$user->warehouseId] ??= (bool)$this->db->value('SELECT hoat_dong FROM kho WHERE id = ?', [$user->warehouseId]);
        return $this->active[$user->warehouseId] ? [$user->warehouseId] : [];
    }

    /** @return list<int> kho mà tài khoản được thao tác chức năng này */
    public function warehousesWith(User $user, Permission $permission): array
    {
        return $this->allows($user, $permission) ? $this->visibleWarehouseIds($user) : [];
    }

    /** Kho mà tài khoản được gắn (mã, tên, địa chỉ) — null với ADMIN. Dùng để tự điền kho khi lập phiếu. */
    public function warehouseOf(?User $user): ?array
    {
        if ($user === null || $user->warehouseId === null) {
            return null;
        }
        return $this->db->one('SELECT id, ma, ten, dia_chi FROM kho WHERE id = ?', [$user->warehouseId]);
    }

    /** Chỉ xem: ném Forbidden nếu kho nằm ngoài phạm vi. */
    public function requireVisible(User $user, int $warehouseId): void
    {
        if (!in_array($warehouseId, $this->visibleWarehouseIds($user), true)) {
            throw new Forbidden('Bạn không có quyền truy cập kho này');
        }
    }

    /**
     * Lưu ma trận quyền chức năng (chỉ ADMIN). Chức năng tác nghiệp chỉ giao cho quản lý / nhân viên.
     * @param array<string,list<string>> $granted vai trò → danh sách mã chức năng được bật
     */
    public function saveMatrix(User $actor, array $granted): void
    {
        $this->requireAdmin($actor);
        $this->db->transaction(function () use ($granted) {
            foreach (AccountRole::configurable() as $role) {
                foreach (Permission::cases() as $p) {
                    if ($p->adminOnly()) {
                        continue;
                    }
                    $on = in_array($p->value, $granted[$role->value] ?? [], true) ? 1 : 0;
                    $this->db->exec('DELETE FROM phan_quyen_vai_tro WHERE vai_tro = ? AND quyen = ?', [$role->value, $p->value]);
                    $this->db->exec('INSERT INTO phan_quyen_vai_tro (vai_tro, quyen, duoc_phep) VALUES (?,?,?)', [$role->value, $p->value, $on]);
                }
            }
        });
        $this->forget();
    }

    /** Đưa ma trận về mặc định (xoá mọi dòng tuỳ chỉnh). */
    public function resetMatrix(User $actor): void
    {
        $this->requireAdmin($actor);
        $this->db->exec('DELETE FROM phan_quyen_vai_tro');
        $this->forget();
    }
}
