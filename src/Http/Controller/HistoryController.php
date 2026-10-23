<?php
declare(strict_types=1);

namespace StockFlow\Http\Controller;

use StockFlow\Core\Db;
use StockFlow\Core\Paginator;
use StockFlow\Core\Request;
use StockFlow\Core\Response;
use StockFlow\Core\Session;
use StockFlow\Core\View;
use StockFlow\Domain\Permission;
use StockFlow\Domain\User;
use StockFlow\Domain\VoucherType;
use StockFlow\Service\Authorizer;
use StockFlow\Service\StockQuery;

final class HistoryController extends Controller
{
    public function __construct(View $view, Session $session, Authorizer $auth, private StockQuery $query, private Db $db)
    {
        parent::__construct($view, $session, $auth);
    }

    private function filters(Request $r): array
    {
        return ['warehouse' => $r->int('warehouse'), 'item' => $r->int('item'), 'type' => VoucherType::tryFrom($r->str('type'))?->value,
                'q' => $r->search('q'), 'from' => $r->str('from'), 'to' => $r->str('to')];
    }

    public function index(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::XemLichSu);
        $ids = $this->auth->visibleWarehouseIds($user);
        $f = $this->filters($r);
        $pg = new Paginator($this->query->historyCount($ids, $f), $r->int('page'));
        return $this->render('history/index', $user, 'history', [
            'rows' => $this->query->history($ids, $f, $pg->per, $pg->offset), 'pg' => $pg, 'f' => $f,
            'warehouses' => $ids ? $this->db->all('SELECT id, ma FROM kho WHERE id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY ma') : [],
            'items' => $this->db->all('SELECT id, ma_hang, ten FROM mat_hang ORDER BY ma_hang'),
        ], 'Lịch sử phát sinh');
    }

    public function reconcile(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::XemDoiChieu);
        $ids = $this->auth->visibleWarehouseIds($user);
        $res = $this->query->reconcile($ids);
        $rows = $res['mismatches'] === 0 ? array_values(array_filter($res['rows'], fn($x) => (int)$x['balance'] !== 0 || (int)$x['ledger'] !== 0)) : $res['rows'];
        [$res['rows'], $pg] = Paginator::of($rows, $r);
        return $this->render('history/reconcile', $user, 'reconcile', ['res' => $res, 'pg' => $pg], 'Đối chiếu tồn');
    }

    public function reports(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::XemBaoCao);
        return $this->render('history/reports', $user, 'reports', [], 'Báo cáo');
    }

    public function exportStock(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::XemBaoCao);
        $rows = array_map(fn($x) => [$x['wh_code'], $x['ma_hang'], $x['ten'], $x['so_lo'], $x['han_dung'], $x['so_luong'], $x['unit']],
            $this->query->onHand($this->auth->visibleWarehouseIds($user), '', true));
        return Response::csv('ton-kho-' . gmdate('Ymd') . '.csv', ['Kho', 'SKU', 'Tên hàng', 'Lô', 'Hạn dùng', 'Tồn', 'ĐVT'], $rows);
    }

    public function exportMovements(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::XemBaoCao);
        $rows = array_map(fn($m) => [$m['id'], $m['ngay_ghi_so'], $m['voucher_code'], $m['loai'], $m['wh_code'], $m['ma_hang'], $m['so_lo'], $m['bien_dong'], $m['ton_sau'], $m['ly_do'] ?: $m['ghi_chu']],
            $this->query->history($this->auth->visibleWarehouseIds($user), $this->filters($r), 5000));
        return Response::csv('so-phat-sinh-' . gmdate('Ymd') . '.csv', ['#', 'Thời gian (UTC)', 'Phiếu nhập xuất', 'Loại', 'Kho', 'SKU', 'Lô', 'Biến động', 'Tồn sau', 'Lý do/Ghi chú'], $rows);
    }

    public function exportReconcile(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::XemBaoCao, Permission::XemDoiChieu);
        $res = $this->query->reconcile($this->auth->visibleWarehouseIds($user));
        $rows = array_map(fn($x) => [$x['wh_code'], $x['ma_hang'], $x['so_lo'], $x['balance'], $x['ledger'], (int)$x['balance'] - (int)$x['ledger']], $res['rows']);
        return Response::csv('doi-chieu-ton-' . gmdate('Ymd') . '.csv', ['Kho', 'SKU', 'Lô', 'Số dư', 'Tổng sổ phát sinh', 'Lệch'], $rows);
    }
}
