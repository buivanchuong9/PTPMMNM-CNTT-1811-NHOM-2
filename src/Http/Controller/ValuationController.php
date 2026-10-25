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
use StockFlow\Service\Authorizer;
use StockFlow\Service\PricingService;

/** «Giá trị tồn & doanh thu»: trong kho có bao nhiêu hàng, đáng giá bao nhiêu (giá vốn / giá bán) và bán ra thu về bao nhiêu (kèm thuế, lãi gộp). */
final class ValuationController extends Controller
{
    public function __construct(View $view, Session $session, Authorizer $auth, private PricingService $pricing, private Db $db)
    {
        parent::__construct($view, $session, $auth);
    }

    public function index(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::XemBaoCao);
        $visible = $this->auth->visibleWarehouseIds($user);
        $wh = $r->int('warehouse');
        $scope = $wh && in_array($wh, $visible, true) ? [$wh] : $visible;
        $catRaw = $r->str('category');
        $category = $catRaw === 'none' ? -1 : ((int)$catRaw ?: null);
        $preset = date_range($r->str('range'));
        [$from, $to] = $preset ?? [$r->str('from') ?: null, $r->str('to') ?: null];
        $rangeSel = $preset ? $r->str('range') : (($r->str('range') === 'custom' || $from || $to) ? 'custom' : '');

        $stock = $this->pricing->valuation($scope, $category);
        $byWh = [];      // kho ⇒ tổng hợp
        $byItem = [];    // mặt hàng ⇒ tổng hợp trong phạm vi lọc
        $unpriced = 0;
        foreach ($stock as $s) {
            $qty = (int)$s['so_luong'];
            $cost = $qty * (int)$s['gia_von'];
            $sell = $qty * (int)$s['gia_ban'];
            $w = &$byWh[(int)$s['kho_id']];
            $w ??= ['code' => $s['wh_code'], 'name' => $s['wh_name'], 'items' => 0, 'qty' => 0, 'cost' => 0, 'sell' => 0];
            $w['items']++;
            $w['qty'] += $qty;
            $w['cost'] += $cost;
            $w['sell'] += $sell;
            unset($w);
            $i = &$byItem[(int)$s['mat_hang_id']];
            $i ??= ['sku' => $s['ma_hang'], 'name' => $s['ten'], 'unit' => $s['unit'], 'category' => $s['category'], 'sell_price' => (int)$s['gia_ban'], 'vat' => (int)$s['thue_suat'],
                    'cost_price' => (int)$s['gia_von'], 'qty' => 0, 'cost' => 0, 'sell' => 0];
            $i['qty'] += $qty;
            $i['cost'] += $cost;
            $i['sell'] += $sell;
            unset($i);
        }
        foreach ($byItem as $i) {
            if ($i['sell_price'] <= 0 || $i['cost_price'] <= 0) {
                $unpriced++;
            }
        }
        uasort($byItem, fn($a, $b) => $b['cost'] <=> $a['cost']);
        $totals = ['items' => count($byItem), 'qty' => array_sum(array_column($byItem, 'qty')), 'cost' => array_sum(array_column($byItem, 'cost')), 'sell' => array_sum(array_column($byItem, 'sell'))];
        [$itemRows, $pg] = Paginator::of(array_values($byItem), $r);

        $sales = $this->pricing->sales($scope, $from, $to);
        $salesTotal = ['phieu' => 0, 'so_luong' => 0, 'doanh_thu' => 0, 'thue' => 0, 'gia_von' => 0];
        foreach ($sales as $s) {
            foreach ($salesTotal as $k => $_) {
                $salesTotal[$k] += (int)$s[$k];
            }
        }
        return $this->render('valuation/index', $user, 'valuation', [
            'totals' => $totals, 'byWh' => array_values($byWh), 'rows' => $itemRows, 'pg' => $pg, 'unpriced' => $unpriced,
            'sales' => $sales, 'salesTotal' => $salesTotal,
            'wh' => $wh, 'category' => $catRaw, 'categories' => $this->db->all('SELECT id, ten FROM danh_muc WHERE hoat_dong ORDER BY ten'),
            'warehouses' => $this->db->all('SELECT id, ma, ten FROM kho WHERE hoat_dong ORDER BY ma'), 'visible' => $visible,
            'rangeSel' => $rangeSel, 'from' => $from, 'to' => $to,
        ], 'Giá trị tồn & doanh thu');
    }
}
