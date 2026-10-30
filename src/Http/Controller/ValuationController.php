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

/**
 * Hai trang báo cáo tiền:
 *  - «Vốn kho» (/valuation): trong kho còn bao nhiêu hàng, đáng giá bao nhiêu (giá gốc / giá bán).
 *  - mục con «Tiền nhập» (/money/in) và «Tiền xuất» (/money/out): từng phiếu đã xác nhận nhập / bán bao nhiêu tiền;
 *    «Tiền lãi» (/money/profit): bán ra lãi bao nhiêu, theo từng mặt hàng.
 */
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
        // ô tìm, tình trạng giá, sắp xếp chỉ áp vào bảng mặt hàng; số tổng hợp phía trên vẫn theo kho / danh mục
        $q = trim($r->str('q'));
        $price = in_array($r->str('price'), ['none', 'ok'], true) ? $r->str('price') : '';
        $sort = in_array($r->str('sort'), ['qty', 'sell', 'margin', 'sku'], true) ? $r->str('sort') : '';
        $items = array_filter(array_values($byItem), fn($x) => $price === '' || ($price === 'none') === ($x['sell_price'] <= 0 || $x['cost_price'] <= 0));
        $items = filter_rows(array_values($items), $q, fn($x) => $x['sku'] . ' ' . $x['name'] . ' ' . $x['category']);
        if ($sort !== '') {
            usort($items, fn($a, $b) => match ($sort) {
                'qty' => $b['qty'] <=> $a['qty'], 'sell' => $b['sell'] <=> $a['sell'], 'margin' => ($b['sell'] - $b['cost']) <=> ($a['sell'] - $a['cost']),
                default => strnatcasecmp($a['sku'], $b['sku']),
            });
        }
        [$itemRows, $pg] = Paginator::of($items, $r);

        return $this->render('valuation/index', $user, 'valuation', [
            'totals' => $totals, 'byWh' => array_values($byWh), 'rows' => $itemRows, 'pg' => $pg, 'unpriced' => $unpriced,
            'wh' => $wh, 'q' => $q, 'price' => $price, 'sort' => $sort, 'category' => $catRaw, 'categories' => $this->db->all('SELECT id, ten FROM danh_muc WHERE hoat_dong ORDER BY ten'),
            'warehouses' => $this->db->all('SELECT id, ma, ten FROM kho WHERE hoat_dong ORDER BY ma'), 'visible' => $visible,
        ], 'Vốn kho');
    }

    public function moneyIn(Request $r, array $p, ?User $user): Response
    {
        return $this->money($r, $user, 'in');
    }

    public function moneyOut(Request $r, array $p, ?User $user): Response
    {
        return $this->money($r, $user, 'out');
    }

    public function moneyProfit(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::XemBaoCao);
        $visible = $this->auth->visibleWarehouseIds($user);
        $wh = $r->int('warehouse');
        $scope = $wh && in_array($wh, $visible, true) ? [$wh] : $visible;
        $preset = date_range($r->str('range'));
        [$from, $to] = $preset ?? [$r->str('from') ?: null, $r->str('to') ?: null];
        $rangeSel = $preset ? $r->str('range') : (($r->str('range') === 'custom' || $from || $to) ? 'custom' : '');
        $q = trim($r->str('q'));

        $all = $this->pricing->profitByItem($scope, $from, $to, $q);
        $total = ['so_luong' => 0, 'tien' => 0, 'gia_von' => 0, 'lai' => 0];
        foreach ($all as $row) {
            foreach ($total as $k => $_) {
                $total[$k] += (int)$row[$k];
            }
        }
        [$rows, $pg] = Paginator::of($all, $r);
        return $this->render('valuation/profit', $user, 'money-profit', [
            'rows' => $rows, 'pg' => $pg, 'total' => $total, 'items' => count($all), 'best' => $all[0] ?? null, 'q' => $q,
            'wh' => $wh, 'warehouses' => $this->db->all('SELECT id, ma, ten FROM kho WHERE hoat_dong ORDER BY ma'), 'visible' => $visible,
            'rangeSel' => $rangeSel, 'from' => $from, 'to' => $to,
        ], 'Tiền lãi');
    }

    /** Danh sách phiếu nhập (tiền chi ra) hoặc phiếu xuất (tiền thu về, kèm lãi) đã xác nhận, có dòng tổng cho cả bộ lọc. */
    private function money(Request $r, ?User $user, string $dir): Response
    {
        $this->need($user, Permission::XemBaoCao);
        $visible = $this->auth->visibleWarehouseIds($user);
        $wh = $r->int('warehouse');
        $scope = $wh && in_array($wh, $visible, true) ? [$wh] : $visible;
        $preset = date_range($r->str('range'));
        [$from, $to] = $preset ?? [$r->str('from') ?: null, $r->str('to') ?: null];
        $rangeSel = $preset ? $r->str('range') : (($r->str('range') === 'custom' || $from || $to) ? 'custom' : '');
        $q = trim($r->str('q'));

        $all = $this->pricing->voucherMoney($dir === 'in' ? 'RECEIPT' : 'ISSUE', $scope, $from, $to, $q);
        $total = ['phieu' => count($all), 'tien' => 0, 'thue' => 0, 'gia_von' => 0];
        foreach ($all as $row) {
            $total['tien'] += (int)$row['tien'];
            $total['thue'] += (int)$row['thue'];
            $total['gia_von'] += (int)$row['gia_von'];
        }
        [$rows, $pg] = Paginator::of($all, $r);
        return $this->render('valuation/money', $user, 'money-' . $dir, [
            'dir' => $dir, 'rows' => $rows, 'pg' => $pg, 'total' => $total, 'q' => $q,
            'wh' => $wh, 'warehouses' => $this->db->all('SELECT id, ma, ten FROM kho WHERE hoat_dong ORDER BY ma'), 'visible' => $visible,
            'rangeSel' => $rangeSel, 'from' => $from, 'to' => $to,
        ], $dir === 'in' ? 'Tiền nhập' : 'Tiền xuất');
    }
}
