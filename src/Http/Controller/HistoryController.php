<?php
declare(strict_types=1);

namespace StockFlow\Http\Controller;

use StockFlow\Core\Db;
use StockFlow\Core\Request;
use StockFlow\Core\Response;
use StockFlow\Core\Session;
use StockFlow\Core\View;
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
                'q' => $r->str('q'), 'from' => $r->str('from'), 'to' => $r->str('to')];
    }

    public function index(Request $r, array $p, ?User $user): Response
    {
        $ids = $this->auth->visibleWarehouseIds($user);
        $f = $this->filters($r);
        return $this->render('history/index', $user, 'history', [
            'rows' => $this->query->history($ids, $f), 'f' => $f,
            'warehouses' => $ids ? $this->db->all('SELECT id, code FROM warehouses WHERE id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY code') : [],
            'items' => $this->db->all('SELECT id, sku, name FROM items ORDER BY sku'),
        ], 'Lịch sử phát sinh');
    }

    public function reconcile(Request $r, array $p, ?User $user): Response
    {
        $ids = $this->auth->visibleWarehouseIds($user);
        $res = $this->query->reconcile($ids);
        return $this->render('history/reconcile', $user, 'reconcile', ['res' => $res], 'Đối chiếu tồn');
    }

    public function reports(Request $r, array $p, ?User $user): Response
    {
        return $this->render('history/reports', $user, 'reports', [], 'Báo cáo');
    }

    public function exportStock(Request $r, array $p, ?User $user): Response
    {
        $rows = array_map(fn($x) => [$x['wh_code'], $x['sku'], $x['name'], $x['batch_no'], $x['expiry_date'], $x['quantity'], $x['unit']],
            $this->query->onHand($this->auth->visibleWarehouseIds($user), '', true));
        return Response::csv('ton-kho-' . gmdate('Ymd') . '.csv', ['Kho', 'SKU', 'Tên hàng', 'Lô', 'Hạn dùng', 'Tồn', 'ĐVT'], $rows);
    }

    public function exportMovements(Request $r, array $p, ?User $user): Response
    {
        $rows = array_map(fn($m) => [$m['id'], $m['posted_at'], $m['voucher_code'], $m['type'], $m['wh_code'], $m['sku'], $m['batch_no'], $m['qty_change'], $m['balance_after'], $m['reason'] ?: $m['note']],
            $this->query->history($this->auth->visibleWarehouseIds($user), $this->filters($r), 5000));
        return Response::csv('so-phat-sinh-' . gmdate('Ymd') . '.csv', ['#', 'Thời gian (UTC)', 'Chứng từ', 'Loại', 'Kho', 'SKU', 'Lô', 'Biến động', 'Tồn sau', 'Lý do/Ghi chú'], $rows);
    }

    public function exportReconcile(Request $r, array $p, ?User $user): Response
    {
        $res = $this->query->reconcile($this->auth->visibleWarehouseIds($user));
        $rows = array_map(fn($x) => [$x['wh_code'], $x['sku'], $x['batch_no'], $x['balance'], $x['ledger'], (int)$x['balance'] - (int)$x['ledger']], $res['rows']);
        return Response::csv('doi-chieu-ton-' . gmdate('Ymd') . '.csv', ['Kho', 'SKU', 'Lô', 'Số dư', 'Tổng sổ phát sinh', 'Lệch'], $rows);
    }
}
