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
    public function __construct(View $view, Session $session, Authorizer $auth, private StockQuery $query, private NavBadges $badges, private ChangeTracker $changes, private \StockFlow\Service\NotificationService $notifications)
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
        return $this->render('dashboard/index', $user, 'dashboard', ['d' => $this->query->dashboard($user)], 'Tổng quan');
    }
}
