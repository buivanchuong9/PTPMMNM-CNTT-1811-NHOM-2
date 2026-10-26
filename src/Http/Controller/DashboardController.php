<?php
declare(strict_types=1);

namespace StockFlow\Http\Controller;

use StockFlow\Core\Request;
use StockFlow\Core\Response;
use StockFlow\Core\Session;
use StockFlow\Core\View;
use StockFlow\Domain\Permission;
use StockFlow\Domain\User;
use StockFlow\Service\Authorizer;
use StockFlow\Service\ChangeTracker;
use StockFlow\Service\NavBadges;
use StockFlow\Service\StockQuery;

final class DashboardController extends Controller
{
    public function __construct(View $view, Session $session, Authorizer $auth, private StockQuery $query, private NavBadges $badges, private ChangeTracker $changes, private \StockFlow\Service\NotificationService $notifications, private \StockFlow\Service\PricingService $pricing)
    {
        parent::__construct($view, $session, $auth);
    }

    /** Số nhỏ trên menu (JSON) — trang gọi định kỳ để số tự cập nhật khi người khác xác nhận / huỷ / lập phiếu. */
    public function badges(Request $r, array $p, ?User $user): Response
    {
        return new Response(json_encode(['badges' => (object)$this->badges->for($user), 'news' => (object)$this->changes->counts($user), 'notifications' => $this->notifications->all($user)]), 200, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    /** Quản trị đóng chuông ⇒ đánh dấu đã xem hoạt động mới. */
    public function notificationsSeen(Request $r, array $p, ?User $user): Response
    {
        if ($user->isAdmin) {
            $this->notifications->markActivitySeen($user);
        }
        return new Response('{}', 200, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    /**
     * Giá trị tồn kho theo giá gốc (số lượng × giá gốc) của từng kho và tổng — chỉ tổng quan của quản trị.
     * @param list<array<string,mixed>> $warehouses các kho hiển thị ở tổng quan
     * @return array{total:int,missing:int,rows:list<array{code:string,name:string,value:int}>}
     */
    private function stockValue(User $user, array $warehouses): array
    {
        $by = [];
        $missing = 0;   // dòng tồn chưa có giá gốc
        foreach ($this->pricing->valuation($this->auth->visibleWarehouseIds($user)) as $s) {
            $by[(int)$s['kho_id']] = ($by[(int)$s['kho_id']] ?? 0) + (int)$s['so_luong'] * (int)$s['gia_von'];
            $missing += (int)$s['gia_von'] > 0 ? 0 : 1;
        }
        $rows = array_map(fn($w) => ['code' => (string)$w['ma'], 'name' => (string)($w['ten'] ?? ''), 'value' => $by[(int)$w['id']] ?? 0], $warehouses);
        return ['total' => array_sum($by), 'missing' => $missing, 'rows' => $rows];
    }

    public function index(Request $r, array $p, ?User $user): Response
    {
        if (!$this->auth->allows($user, Permission::XemTongQuan)) {
            foreach (['/inventory' => Permission::XemTonKho, '/vouchers' => Permission::XemPhieu, '/vouchers/new?type=RECEIPT' => Permission::LapPhieuNhap,
                      '/vouchers/new?type=ISSUE' => Permission::LapPhieuXuat, '/transfers/new' => Permission::LapPhieuDieuChuyen,
                      '/warehouses' => Permission::XemDanhMuc] as $to => $perm) {
                if ($this->auth->allows($user, $perm)) {
                    return Response::redirect($to);
                }
            }
            $this->need($user, Permission::XemTongQuan);
        }
        $d = $this->query->dashboard($user);
        return $this->render('dashboard/index', $user, 'dashboard', ['d' => $d, 'value' => $user->isAdmin ? $this->stockValue($user, $d['warehouses']) : null], 'Tổng quan');
    }
}
