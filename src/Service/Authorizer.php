<?php
declare(strict_types=1);

namespace StockFlow\Service;

use StockFlow\Core\Db;
use StockFlow\Domain\Forbidden;
use StockFlow\Domain\Role;
use StockFlow\Domain\User;

/**
 * Phân quyền phía máy chủ theo từng kho. Mọi service gọi require() trước khi ghi dữ liệu —
 * việc ẩn nút trên giao diện chỉ là tiện lợi, không phải lớp bảo vệ.
 */
final class Authorizer
{
    /** @var array<int,array<int,list<string>>> cache theo request: userId → whId → roles */
    private array $cache = [];

    public function __construct(private Db $db) {}

    /** @return array<int,list<string>> */
    public function rolesOf(User $user): array
    {
        return $this->cache[$user->id] ??= $this->load($user->id);
    }

    private function load(int $userId): array
    {
        $out = [];
        foreach ($this->db->all('SELECT warehouse_id, role FROM warehouse_roles WHERE user_id = ?', [$userId]) as $r) {
            $out[(int)$r['warehouse_id']][] = $r['role'];
        }
        return $out;
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    public function can(User $user, int $warehouseId, Role $role): bool
    {
        return $user->isAdmin || in_array($role->value, $this->rolesOf($user)[$warehouseId] ?? [], true);
    }

    public function require(User $user, int $warehouseId, Role $role): void
    {
        if (!$this->can($user, $warehouseId, $role)) {
            $code = $this->db->value('SELECT code FROM warehouses WHERE id = ?', [$warehouseId]) ?? "#$warehouseId";
            throw new Forbidden("Bạn không có quyền «{$role->label()}» tại kho $code");
        }
    }

    public function requireAdmin(User $user): void
    {
        if (!$user->isAdmin) {
            throw new Forbidden('Chỉ quản trị viên mới được thực hiện thao tác này');
        }
    }

    public function canAnywhere(User $user, Role $role): bool
    {
        if ($user->isAdmin) {
            return true;
        }
        foreach ($this->rolesOf($user) as $roles) {
            if (in_array($role->value, $roles, true)) {
                return true;
            }
        }
        return false;
    }

    /** Các kho người dùng được xem (có ít nhất một quyền; quản trị viên: tất cả). */
    public function visibleWarehouseIds(User $user): array
    {
        if ($user->isAdmin) {
            return array_map('intval', array_column($this->db->all('SELECT id FROM warehouses WHERE active'), 'id'));
        }
        return array_map('intval', array_keys($this->rolesOf($user)));
    }

    /** @return list<int> kho mà user có role đó */
    public function warehousesWith(User $user, Role $role): array
    {
        $ids = $this->visibleWarehouseIds($user);
        return array_values(array_filter($ids, fn(int $id) => $this->can($user, $id, $role)));
    }

    /** Chỉ xem: ném Forbidden nếu kho nằm ngoài phạm vi. */
    public function requireVisible(User $user, int $warehouseId): void
    {
        if (!in_array($warehouseId, $this->visibleWarehouseIds($user), true)) {
            throw new Forbidden('Bạn không có quyền truy cập kho này');
        }
    }
}
