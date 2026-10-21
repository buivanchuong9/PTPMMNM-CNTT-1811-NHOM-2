<?php
declare(strict_types=1);

namespace StockFlow\Http\Controller;

use StockFlow\Core\Paginator;
use StockFlow\Core\Request;
use StockFlow\Core\Response;
use StockFlow\Core\Session;
use StockFlow\Core\View;
use StockFlow\Domain\DomainError;
use StockFlow\Domain\AccountRole;
use StockFlow\Domain\StockRules;
use StockFlow\Domain\Permission;
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
        $this->need($user, Permission::XemDanhMuc);
        $visible = $this->auth->visibleWarehouseIds($user);
        $directory = array_values(array_filter($this->catalog->warehouseDirectory(), fn($w) => $user->isAdmin || in_array((int)$w['id'], $visible, true)));
        [$rows, $pg] = Paginator::of($directory, $r);
        return $this->render('admin/warehouses', $user, 'warehouses', ['rows' => $rows, 'pg' => $pg, 'stats' => array_column($this->query->dashboard($user)['warehouses'], null, 'id')], 'Kho');
    }

    public function addWarehouse(Request $r, array $p, ?User $user): Response
    {
        return $this->act(function () use ($r, $user) {
            $this->catalog->addWarehouse($user, $r->str('code'), $r->str('name'), $r->str('address'));
        }, 'Đã thêm kho.', '/warehouses');
    }

    /** Quản lý kho thêm nhân viên cho kho của mình (tài khoản cấp cao: chọn kho). */
    public function addStaff(Request $r, array $p, ?User $user): Response
    {
        return $this->act(function () use ($r, $user) {
            $id = $this->catalog->createStaff($user, $r->str('username'), $r->str('full_name'), (string)($r->post['password'] ?? ''), $r->int('warehouse_id'));
            $this->session->flash('ok', 'Đã thêm nhân viên «' . $r->str('full_name') . '» vào kho. Tên đăng nhập: ' . strtolower($r->str('username')));
        }, '', '/warehouses');
    }

    public function items(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::XemDanhMuc);
        $items = $this->catalog->items();
        [$pageItems, $pg] = Paginator::of($items, $r);
        [$pageBatches, $bpg] = Paginator::of($this->catalog->batches(), $r, 'bpage');
        return $this->render('admin/items', $user, 'items', [
            'items' => $items, 'pageItems' => $pageItems, 'pg' => $pg, 'units' => $this->catalog->units(), 'batches' => $pageBatches, 'bpg' => $bpg,
            'canAddBatch' => $this->auth->allows($user, Permission::ThemLo),
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
        $this->need($user, Permission::QuanLyTaiKhoan);
        [$pageUsers, $pg] = Paginator::of($this->catalog->users(), $r);
        return $this->render('admin/users', $user, 'users', [
            'users' => $pageUsers, 'pg' => $pg, 'warehouses' => array_values(array_filter($this->catalog->warehouses(), fn($w) => $w['hoat_dong'])),
            'accountRoles' => AccountRole::cases(),
        ], 'Tài khoản');
    }

    private function roleFrom(Request $r): AccountRole
    {
        return AccountRole::tryFrom($r->str('role')) ?? throw new DomainError('Vai trò không hợp lệ');
    }

    public function addUser(Request $r, array $p, ?User $user): Response
    {
        return $this->act(function () use ($r, $user) {
            $this->catalog->createAccount($user, $r->str('username'), $r->str('full_name'), (string)($r->post['password'] ?? ''), $this->roleFrom($r), $r->int('warehouse_id'));
        }, 'Đã tạo tài khoản.', '/users');
    }

    public function updateUser(Request $r, array $p, ?User $user): Response
    {
        return $this->act(function () use ($r, $user) {
            $this->catalog->updateAccount($user, (int)$r->int('user_id'), $this->roleFrom($r), $r->int('warehouse_id'));
        }, 'Đã cập nhật vai trò / kho. Chức năng của tài khoản này thay đổi theo vai trò mới ngay lần tải trang kế tiếp.', '/users');
    }

    public function setUserActive(Request $r, array $p, ?User $user): Response
    {
        $on = $r->str('active') === '1';
        return $this->act(function () use ($r, $user, $on) {
            $this->catalog->setAccountActive($user, (int)$r->int('user_id'), $on);
        }, $on ? 'Đã mở khoá tài khoản.' : 'Đã khoá tài khoản — không đăng nhập được nữa nhưng lịch sử thao tác vẫn được giữ.', '/users');
    }

    public function resetUserPassword(Request $r, array $p, ?User $user): Response
    {
        return $this->act(function () use ($r, $user) {
            $this->catalog->resetPassword($user, (int)$r->int('user_id'), (string)($r->post['password'] ?? ''));
        }, 'Đã đặt lại mật khẩu.', '/users');
    }

    // ───── phân quyền chức năng theo vai trò ─────
    public function roles(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::PhanQuyen);
        return $this->render('admin/roles', $user, 'roles', ['matrix' => $this->auth->matrix(), 'groups' => Permission::grouped()], 'Phân quyền chức năng');
    }

    public function saveRoles(Request $r, array $p, ?User $user): Response
    {
        return $this->act(function () use ($r, $user) {
            $granted = [];
            foreach (AccountRole::configurable() as $role) {
                $granted[$role->value] = array_values(array_filter((array)($r->arr('quyen')[$role->value] ?? []), 'is_string'));
            }
            $this->auth->saveMatrix($user, $granted);
        }, 'Đã lưu phân quyền. Chức năng bị thu hồi được ẩn khỏi menu của vai trò đó nhưng vẫn còn nguyên trong hệ thống.', '/roles');
    }

    public function resetRoles(Request $r, array $p, ?User $user): Response
    {
        return $this->act(function () use ($user) {
            $this->auth->resetMatrix($user);
        }, 'Đã đưa phân quyền về mặc định.', '/roles');
    }
}
