<?php
declare(strict_types=1);

namespace StockFlow\Http\Controller;

use StockFlow\Core\Request;
use StockFlow\Core\Response;
use StockFlow\Core\Session;
use StockFlow\Core\View;
use StockFlow\Domain\DomainError;
use StockFlow\Domain\Role;
use StockFlow\Domain\StockRules;
use StockFlow\Domain\User;
use StockFlow\Service\Authorizer;
use StockFlow\Service\CatalogService;
use StockFlow\Service\StockQuery;

final class AdminController extends Controller
{
    public function __construct(View $view, Session $session, Authorizer $auth, private CatalogService $catalog, private StockQuery $query)
    {
        parent::__construct($view, $session, $auth);
    }

    public function warehouses(Request $r, array $p, ?User $user): Response
    {
        return $this->render('admin/warehouses', $user, 'warehouses', ['rows' => $this->catalog->warehouses(), 'stats' => array_column($this->query->dashboard($user)['warehouses'], null, 'id')], 'Kho');
    }

    public function addWarehouse(Request $r, array $p, ?User $user): Response
    {
        return $this->act(function () use ($r, $user) {
            $this->catalog->addWarehouse($user, $r->str('code'), $r->str('name'), $r->str('address'));
        }, 'Đã thêm kho.', '/warehouses');
    }

    public function items(Request $r, array $p, ?User $user): Response
    {
        return $this->render('admin/items', $user, 'items', [
            'items' => $this->catalog->items(), 'units' => $this->catalog->units(), 'batches' => $this->catalog->batches(),
            'canAddBatch' => $this->auth->canAnywhere($user, Role::Keeper),
        ], 'Mặt hàng');
    }

    public function addItem(Request $r, array $p, ?User $user): Response
    {
        return $this->act(function () use ($r, $user) {
            $this->catalog->addItem($user, $r->str('sku'), $r->str('name'), (int)$r->int('unit_id'), $r->str('track_batch') === '1', StockRules::parseQty($r->str('reorder_level') ?: '0') ?? -1);
        }, 'Đã thêm mặt hàng.', '/items');
    }

    public function addUnit(Request $r, array $p, ?User $user): Response
    {
        return $this->act(function () use ($r, $user) {
            $this->catalog->addUnit($user, $r->str('code'), $r->str('name'));
        }, 'Đã thêm đơn vị tính.', '/items');
    }

    public function addBatch(Request $r, array $p, ?User $user): Response
    {
        return $this->act(function () use ($r, $user) {
            $this->catalog->addBatch($user, (int)$r->int('item_id'), $r->str('batch_no'), $r->str('expiry') ?: null);
        }, 'Đã thêm lô hàng.', '/items');
    }

    public function users(Request $r, array $p, ?User $user): Response
    {
        $this->auth->requireAdmin($user);
        return $this->render('admin/users', $user, 'users', ['users' => $this->catalog->users(), 'warehouses' => $this->catalog->warehouses(), 'roles' => Role::cases()], 'Tài khoản & quyền');
    }

    public function addUser(Request $r, array $p, ?User $user): Response
    {
        return $this->act(function () use ($r, $user) {
            $this->catalog->addUser($user, $r->str('username'), $r->str('full_name'), (string)($r->post['password'] ?? ''), $r->str('is_admin') === '1');
        }, 'Đã tạo tài khoản.', '/users');
    }

    public function grant(Request $r, array $p, ?User $user): Response
    {
        return $this->act(function () use ($r, $user) {
            $role = Role::tryFrom($r->str('role')) ?? throw new DomainError('Vai trò không hợp lệ');
            $this->catalog->grant($user, (int)$r->int('user_id'), (int)$r->int('warehouse_id'), $role);
        }, 'Đã cấp quyền.', '/users');
    }

    public function revoke(Request $r, array $p, ?User $user): Response
    {
        return $this->act(function () use ($r, $user) {
            $role = Role::tryFrom($r->str('role')) ?? throw new DomainError('Vai trò không hợp lệ');
            $this->catalog->revoke($user, (int)$r->int('user_id'), (int)$r->int('warehouse_id'), $role);
        }, 'Đã thu hồi quyền.', '/users');
    }
}
