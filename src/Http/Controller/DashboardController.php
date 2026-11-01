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

    /** Đóng chuông ⇒ đánh dấu đã xem hoạt động mới. */
    public function notificationsSeen(Request $r, array $p, ?User $user): Response
    {
        if ($user) {
            $this->notifications->markActivitySeen($user);
        }
        return new Response('{}', 200, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    /**
     * Giá trị tồn kho theo giá gốc (số lượng × giá gốc): tổng, theo từng kho và theo danh mục hàng.
     * @param list<array<string,mixed>> $warehouses các kho hiển thị ở tổng quan
     * @return array{total:int,missing:int,rows:list<array{id:int,code:string,name:string,value:int}>,cats:array<string,int>}
     */
    private function stockValue(User $user, array $warehouses): array
    {
        $by = [];
        $cats = [];
        $missing = 0;   // dòng tồn chưa có giá gốc
        foreach ($this->pricing->valuation($this->auth->visibleWarehouseIds($user)) as $s) {
            $v = (int)$s['so_luong'] * (int)$s['gia_von'];
            $by[(int)$s['kho_id']] = ($by[(int)$s['kho_id']] ?? 0) + $v;
            $c = (string)($s['category'] ?? '') !== '' ? (string)$s['category'] : 'Chưa phân loại';
            $cats[$c] = ($cats[$c] ?? 0) + $v;
            $missing += (int)$s['gia_von'] > 0 ? 0 : 1;
        }
        arsort($cats);
        $rows = array_map(fn($w) => ['id' => (int)$w['id'], 'code' => (string)$w['ma'], 'name' => (string)($w['ten'] ?? ''), 'value' => $by[(int)$w['id']] ?? 0], $warehouses);
        return ['total' => array_sum($by), 'missing' => $missing, 'rows' => $rows, 'cats' => array_filter($cats, fn($v) => $v > 0)];
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
        $range = in_array($r->str('range'), ['7', '30', '90', 'month'], true) ? $r->str('range') : '30';
        $days = $range === 'month' ? (int)gmdate('j', time() + 7 * 3600) : (int)$range;   // «tháng này» = từ mùng 1 đến hôm nay
        $period = $this->query->dashboardPeriod($this->auth->visibleWarehouseIds($user), $days);
        return $this->render('dashboard/index', $user, 'dashboard', ['d' => $d, 'period' => $period, 'range' => $range, 'days' => $days, 'todo' => $this->badges->todo($user), 'canReceive' => $this->auth->allows($user, Permission::LapPhieuNhap) && !$user->isAdmin, 'canOrder' => $this->auth->allows($user, Permission::LapDonDatHang) && !$user->isAdmin, 'value' => $this->stockValue($user, $d['warehouses'])], 'Tổng quan');
    }
}
