<?php
declare(strict_types=1);

namespace StockFlow\Service;

use StockFlow\Core\Db;
use StockFlow\Domain\DomainError;
use StockFlow\Domain\Role;
use StockFlow\Domain\User;

/** Danh mục: kho, đơn vị tính, mặt hàng, lô hàng, tài khoản và quyền theo kho. */
final class CatalogService
{
    public const NO_LOT = 'NOLOT';

    public function __construct(private Db $db, private Authorizer $auth, private AuditLog $audit) {}

    // ───── đọc ─────
    public function warehouses(): array
    {
        return $this->db->all('SELECT * FROM warehouses ORDER BY code');
    }

    public function units(): array
    {
        return $this->db->all('SELECT * FROM units ORDER BY code');
    }

    public function items(): array
    {
        return $this->db->all('SELECT i.*, u.code AS unit, (SELECT COUNT(*) FROM batches b WHERE b.item_id = i.id AND b.batch_no <> ?) AS lots
                               FROM items i JOIN units u ON u.id = i.unit_id ORDER BY i.sku', [self::NO_LOT]);
    }

    public function batches(): array
    {
        return $this->db->all('SELECT b.*, i.sku, i.name FROM batches b JOIN items i ON i.id = b.item_id WHERE b.batch_no <> ? ORDER BY i.sku, b.batch_no', [self::NO_LOT]);
    }

    public function users(): array
    {
        $users = $this->db->all('SELECT id, username, full_name, is_admin, active FROM users ORDER BY username');
        $roles = [];
        foreach ($this->db->all('SELECT r.user_id, r.role, w.code FROM warehouse_roles r JOIN warehouses w ON w.id = r.warehouse_id ORDER BY w.code, r.role') as $r) {
            $roles[(int)$r['user_id']][] = ['wh' => $r['code'], 'role' => $r['role']];
        }
        foreach ($users as &$u) {
            $u['roles'] = $roles[(int)$u['id']] ?? [];
        }
        return $users;
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
        $this->requireUnique('warehouses', 'code', $code, "Mã kho $code đã tồn tại");
        $id = $this->db->insert('INSERT INTO warehouses (code, name, address) VALUES (?,?,?)', [$code, $name, trim($address)]);
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
        $this->requireUnique('units', 'code', $code, "Đơn vị «{$code}» đã tồn tại");
        return $this->db->insert('INSERT INTO units (code, name) VALUES (?,?)', [$code, $name]);
    }

    public function addItem(User $actor, string $sku, string $name, int $unitId, bool $trackBatch, int $reorder): int
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
        if (!$this->db->value('SELECT 1 FROM units WHERE id = ?', [$unitId])) {
            throw new DomainError('Đơn vị tính không hợp lệ');
        }
        $this->requireUnique('items', 'sku', $sku, "SKU $sku đã tồn tại");
        return $this->db->transaction(function () use ($actor, $sku, $name, $unitId, $trackBatch, $reorder) {
            $id = $this->db->insert('INSERT INTO items (sku, name, unit_id, track_batch, reorder_level) VALUES (?,?,?,?,?)',
                [$sku, $name, $unitId, (int)$trackBatch, $reorder]);
            if (!$trackBatch) {
                $this->db->exec('INSERT INTO batches (item_id, batch_no) VALUES (?,?)', [$id, self::NO_LOT]);
            }
            $this->audit->record($actor->id, 'item.create', 'item', $id, $sku);
            return $id;
        });
    }

    /** Thêm lô cho mặt hàng quản lý theo lô — quản trị viên hoặc người có quyền nhân viên kho ở một kho bất kỳ. */
    public function addBatch(User $actor, int $itemId, string $batchNo, ?string $expiry): int
    {
        if (!$this->auth->canAnywhere($actor, Role::Keeper)) {
            $this->auth->requireAdmin($actor);
        }
        $batchNo = strtoupper(trim($batchNo));
        if (!preg_match('/^[A-Z0-9._-]{1,40}$/', $batchNo) || $batchNo === self::NO_LOT) {
            throw new DomainError('Số lô gồm 1–40 ký tự A-Z, 0-9, ., _ hoặc -');
        }
        $item = $this->db->one('SELECT * FROM items WHERE id = ? AND active', [$itemId]);
        if (!$item || !$item['track_batch']) {
            throw new DomainError('Mặt hàng không tồn tại hoặc không quản lý theo lô');
        }
        if ($expiry !== null && $expiry !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry) || !checkdate((int)substr($expiry, 5, 2), (int)substr($expiry, 8, 2), (int)substr($expiry, 0, 4)))) {
            throw new DomainError('Hạn dùng không hợp lệ');
        }
        if ($this->db->value('SELECT 1 FROM batches WHERE item_id = ? AND batch_no = ?', [$itemId, $batchNo])) {
            throw new DomainError("Lô $batchNo đã tồn tại cho mặt hàng này");
        }
        $id = $this->db->insert('INSERT INTO batches (item_id, batch_no, expiry_date) VALUES (?,?,?)', [$itemId, $batchNo, $expiry ?: null]);
        $this->audit->record($actor->id, 'batch.create', 'batch', $id, "{$item['sku']}/$batchNo");
        return $id;
    }

    public function addUser(User $actor, string $username, string $fullName, string $password, bool $isAdmin): int
    {
        $this->auth->requireAdmin($actor);
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
        $this->requireUnique('users', 'username', $username, "Tên đăng nhập $username đã tồn tại");
        $id = $this->db->insert('INSERT INTO users (username, full_name, password_hash, is_admin) VALUES (?,?,?,?)',
            [$username, trim($fullName), password_hash($password, PASSWORD_DEFAULT), (int)$isAdmin]);
        $this->audit->record($actor->id, 'user.create', 'user', $id, $username);
        return $id;
    }

    public function grant(User $actor, int $userId, int $warehouseId, Role $role): void
    {
        $this->auth->requireAdmin($actor);
        if (!$this->db->value('SELECT 1 FROM users WHERE id = ?', [$userId]) || !$this->db->value('SELECT 1 FROM warehouses WHERE id = ?', [$warehouseId])) {
            throw new DomainError('Tài khoản hoặc kho không tồn tại');
        }
        $this->db->exec('INSERT IGNORE INTO warehouse_roles (user_id, warehouse_id, role) VALUES (?,?,?)', [$userId, $warehouseId, $role->value]);
        $this->audit->record($actor->id, 'role.grant', 'user', $userId, "$warehouseId:{$role->value}");
        $this->auth->forget();
    }

    public function revoke(User $actor, int $userId, int $warehouseId, Role $role): void
    {
        $this->auth->requireAdmin($actor);
        $this->db->exec('DELETE FROM warehouse_roles WHERE user_id = ? AND warehouse_id = ? AND role = ?', [$userId, $warehouseId, $role->value]);
        $this->audit->record($actor->id, 'role.revoke', 'user', $userId, "$warehouseId:{$role->value}");
        $this->auth->forget();
    }

    private function requireUnique(string $table, string $column, string $value, string $message): void
    {
        if ($this->db->value("SELECT 1 FROM $table WHERE $column = ?", [$value])) {
            throw new DomainError($message);
        }
    }
}
