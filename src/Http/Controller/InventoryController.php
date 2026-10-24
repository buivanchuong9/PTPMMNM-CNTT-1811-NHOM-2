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
use StockFlow\Service\CatalogService;
use StockFlow\Service\StockQuery;

/** Trang «Hàng hoá»: danh mục mặt hàng + lô + tồn theo kho + quy cách lô, gộp trong một trang. */
final class InventoryController extends Controller
{
    public function __construct(View $view, Session $session, Authorizer $auth, private StockQuery $query, private Db $db, private CatalogService $catalog)
    {
        parent::__construct($view, $session, $auth);
    }

    public function index(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::XemTonKho);
        $visible = $this->auth->visibleWarehouseIds($user);
        $wh = $r->int('warehouse');
        $scope = $wh && in_array($wh, $visible, true) ? [$wh] : $visible;
        $byBatch = $r->str('view') === 'batch';
        $q = $r->search('q');
        $catRaw = $r->str('category');   // id danh mục · 'none' = chưa phân loại · rỗng = tất cả
        $cat = $catRaw === 'none' ? -1 : (int)$catRaw;
        $inCat = fn(array $x) => $cat === 0 || ($cat === -1 ? (int)$x['danh_muc_id'] === 0 : (int)$x['danh_muc_id'] === $cat);
        $stock = $r->str('stock');   // '' = tất cả · 'low' = sắp hết · 'in' = còn hàng · 'out' = hết hàng
        $items = $this->catalog->items();
        $warehouses = $visible ? $this->db->all('SELECT id, ma, ten FROM kho WHERE id IN (' . implode(',', array_map('intval', $visible)) . ') ORDER BY ma') : [];

        if ($byBatch) {
            $all = $this->query->onHand($scope, $q, true);
            [$rows, $pg] = Paginator::of($cat !== 0 ? array_values(array_filter($all, $inCat)) : $all, $r);
        } else {
            // Mọi mặt hàng đang hoạt động (kể cả đã hết hàng), gắn tồn theo kho đọc từ sổ phát sinh.
            $onHand = array_column($this->query->matrix($scope, ''), null, 'mat_hang_id');
            $rows = [];
            foreach ($items as $i) {
                if (!$i['hoat_dong'] || !$inCat($i) || ($q !== '' && stripos($i['ma_hang'] . ' ' . $i['ten'], $q) === false)) {
                    continue;
                }
                $total = (int)($onHand[$i['id']]['total'] ?? 0);
                $low = (int)$i['dinh_muc_toi_thieu'] > 0 && $total <= (int)$i['dinh_muc_toi_thieu'];
                if (($stock === 'low' && !$low) || ($stock === 'in' && $total <= 0) || ($stock === 'out' && $total > 0)) {
                    continue;
                }
                $rows[] = $i + ['by_wh' => $onHand[$i['id']]['by_wh'] ?? [], 'total' => $total, 'low' => $low];
            }
            [$rows, $pg] = Paginator::of($rows, $r);
        }
        return $this->render('inventory/index', $user, 'inventory', [
            'warehouses' => $warehouses,
            'shown' => array_values(array_filter($warehouses, fn($w) => in_array((int)$w['id'], $scope, true))),
            'rows' => $rows, 'pg' => $pg,
            'byBatch' => $byBatch, 'q' => $q, 'wh' => $wh, 'stock' => $stock, 'cat' => $cat, 'uncategorized' => count(array_filter($items, fn($i) => $i['hoat_dong'] && !(int)$i['danh_muc_id'])), 'totalItems' => count(array_filter($items, fn($i) => $i['hoat_dong'])), 'categories' => $this->catalog->categories(),
            'items' => $items, 'units' => $this->catalog->units(),
            'canAddBatch' => $this->auth->allows($user, Permission::ThemLo),
        ], 'Hàng hoá');
    }
}
