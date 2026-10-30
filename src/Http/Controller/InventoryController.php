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
use StockFlow\Service\PartnerService;
use StockFlow\Service\StockQuery;

/** Trang «Hàng hoá»: danh mục mặt hàng + lô + tồn theo kho + quy cách đóng gói, gộp trong một trang. */
final class InventoryController extends Controller
{
    public function __construct(View $view, Session $session, Authorizer $auth, private StockQuery $query, private Db $db, private CatalogService $catalog, private PartnerService $partners, private \StockFlow\Service\PricingService $pricing)
    {
        parent::__construct($view, $session, $auth);
    }

    /** Quản lý kho đặt GIÁ BÁN RA của kho mình (mỗi kho một giá; bắt buộc ghi lý do khi đổi, lưu lịch sử giá). */
    public function price(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::DieuChinhGia);
        return $this->act(function () use ($r, $user) {
            $money = \StockFlow\Service\PricingService::parseMoney($r->str('price'));
            if ($money === null || $money < 1) {
                throw new \StockFlow\Domain\DomainError('Giá bán phải là số nguyên lớn hơn 0 (đơn vị đồng / 1 đơn vị tính)');
            }
            $this->pricing->setSellPrice($user, (int)$user->warehouseId, (int)$r->int('item_id'), $money, $r->str('reason'));
        }, 'Đã cập nhật giá bán của kho bạn. Phiếu đã lập trước đó giữ nguyên giá cũ; lịch sử giá được lưu.', '/inventory');
    }

    /** Quản trị hệ thống đặt GIÁ GỐC và thuế suất (dùng chung mọi kho; điều chuyển giữa kho lấy giá gốc này). */
    public function cost(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::DatGiaGoc);
        return $this->act(function () use ($r, $user) {
            $cost = \StockFlow\Service\PricingService::parseMoney($r->str('cost'));
            if ($cost === null || $cost < 1) {
                throw new \StockFlow\Domain\DomainError('Giá gốc phải là số nguyên lớn hơn 0 (đơn vị đồng / 1 đơn vị tính)');
            }
            $this->pricing->setCost($user, (int)$r->int('item_id'), $cost, (int)$r->int('vat'), $r->str('reason'));
        }, 'Đã cập nhật giá gốc và thuế. Phiếu đã lập trước đó giữ nguyên giá cũ; lịch sử giá được lưu.', '/inventory');
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
        $supF = (int)$r->int('supplier');   // lọc theo nhà cung cấp
        $inCat = fn(array $x) => $cat === 0 || ($cat === -1 ? (int)$x['danh_muc_id'] === 0 : (int)$x['danh_muc_id'] === $cat);
        $priceSel = in_array($r->str('price'), ['sell_desc', 'sell_asc', 'cost_desc', 'cost_asc', 'margin_desc', 'margin_asc', 'none'], true) ? $r->str('price') : '';   // lọc / sắp xếp theo giá
        $items = $this->catalog->items();
        $supBy = $this->partners->suppliersByItem();
        $hasSup = fn(int $itemId) => !$supF || in_array($supF, array_column($supBy[$itemId] ?? [], 'id'), true);
        foreach ($items as &$it) {
            $it['suppliers'] = $supBy[(int)$it['id']] ?? [];
        }
        unset($it);
        // Chế độ «Theo mặt hàng» cho mọi vai trò xem tồn của TẤT CẢ kho đang hoạt động (chỉ đọc) để biết nhờ kho nào chuyển hàng;
        // quản lý kho kèm theo để liên hệ. Chế độ «Theo lô», phiếu, lịch sử, báo cáo vẫn giới hạn theo kho của tài khoản.
        $warehouses = $this->db->all(
            'SELECT k.id, k.ma, k.ten, (SELECT u.ho_ten FROM nguoi_dung u WHERE u.quan_ly_kho_id = k.id AND u.hoat_dong LIMIT 1) AS quan_ly FROM kho k WHERE k.hoat_dong ORDER BY k.ma'
        );
        if (!$byBatch && !$user->isAdmin) {
            $scope = array_map(fn($w) => (int)$w['id'], $warehouses);
        } else {
            $warehouses = array_values(array_filter($warehouses, fn($w) => in_array((int)$w['id'], $visible, true)));
        }

        $expiry = in_array($r->str('expiry'), ['expired', 'soon'], true) ? $r->str('expiry') : '';   // lọc theo hạn dùng (chế độ «Theo lô»)
        if ($byBatch) {
            $all = $this->query->onHand($scope, $q, true);
            foreach ($all as &$x) {
                $x['expiry'] = StockQuery::expiryState($x['han_dung'] ?? null);
            }
            unset($x);
            if ($expiry !== '') {   // gần hạn nhất lên đầu
                $all = array_values(array_filter($all, fn($x) => $x['expiry']['status'] === $expiry));
                usort($all, fn($a, $b) => strcmp((string)$a['han_dung'], (string)$b['han_dung']));
            }
            [$rows, $pg] = Paginator::of($cat !== 0 || $supF ? array_values(array_filter($all, fn($x) => $inCat($x) && $hasSup((int)$x['mat_hang_id']))) : $all, $r);
        } else {
            // Mọi mặt hàng đang hoạt động (kể cả đã hết hàng), gắn tồn theo kho đọc từ sổ phát sinh.
            $onHand = array_column($this->query->matrix($scope, ''), null, 'mat_hang_id');
            $rows = [];
            foreach ($items as $i) {
                if (!$i['hoat_dong'] || !$inCat($i) || !$hasSup((int)$i['id']) || ($q !== '' && stripos($i['ma_hang'] . ' ' . $i['ten'], $q) === false)) {
                    continue;
                }
                $total = (int)($onHand[$i['id']]['total'] ?? 0);
                // «Tình trạng» của tài khoản thuộc một kho tính theo tồn của CHÍNH KHO ĐÓ (không phải tổng các kho)
                $own = $user->warehouseId !== null ? (int)($onHand[$i['id']]['by_wh'][(int)$user->warehouseId] ?? 0) : $total;
                $low = (int)$i['dinh_muc_toi_thieu'] > 0 && $own <= (int)$i['dinh_muc_toi_thieu'];
                $rows[] = $i + ['by_wh' => $onHand[$i['id']]['by_wh'] ?? [], 'total' => $total, 'own' => $own, 'low' => $low];
            }
            if ($priceSel !== '') {
                $shownIds = array_values(array_map(fn($w) => (int)$w['id'], array_filter($warehouses, fn($w) => in_array((int)$w['id'], $scope, true))));
                $sell = $this->sellPricesFor($shownIds, array_map(fn($x) => (int)$x['id'], $rows));
                $avg = function (array $x) use ($sell): ?float {   // giá bán trung bình của các kho đang hiện (kho chưa đặt giá không tính)
                    $p = $sell[(int)$x['id']] ?? [];
                    return $p ? array_sum($p) / count($p) : null;
                };
                $key = match ($priceSel) {
                    'sell_desc', 'sell_asc' => $avg,
                    'cost_desc', 'cost_asc' => fn(array $x): ?float => (int)$x['gia_von'] > 0 ? (float)$x['gia_von'] : null,
                    default => function (array $x) use ($avg): ?float {   // lãi gộp % = (giá bán − giá gốc) ÷ giá bán
                        $a = $avg($x);
                        return $a !== null && (int)$x['gia_von'] > 0 ? ($a - (int)$x['gia_von']) / $a : null;
                    },
                };
                if ($priceSel === 'none') {   // mặt hàng chưa kho nào đặt giá bán
                    $rows = array_values(array_filter($rows, fn($x) => $avg($x) === null));
                } else {
                    $desc = str_ends_with($priceSel, '_desc');
                    usort($rows, function ($a, $b) use ($key, $desc) {   // mặt hàng chưa có giá luôn xuống cuối
                        [$ka, $kb] = [$key($a), $key($b)];
                        return $ka === null || $kb === null ? ($ka === null) <=> ($kb === null) : ($desc ? $kb <=> $ka : $ka <=> $kb);
                    });
                }
            }
            [$rows, $pg] = Paginator::of($rows, $r);
            // Bấm tên hàng ⇒ xem các lô còn tồn: hạn dùng, còn hạn hay sắp / đã hết hạn, tồn theo từng kho.
            $lotsOf = [];
            foreach ($this->query->onHand($scope, '', true) as $x) {
                $lotsOf[(int)$x['mat_hang_id']][(int)$x['lo_hang_id']] ??= ['lot' => $x['so_lo'], 'exp' => $x['han_dung'], 'bh' => (int)$x['lo_bao_hanh'], 'by' => [], 'total' => 0] + ['state' => StockQuery::expiryState($x['han_dung'] ?? null)];
                $lotsOf[(int)$x['mat_hang_id']][(int)$x['lo_hang_id']]['by'][(int)$x['kho_id']] = $x['so_luong'];
                $lotsOf[(int)$x['mat_hang_id']][(int)$x['lo_hang_id']]['total'] += $x['so_luong'];
            }
            foreach ($lotsOf as &$lots) {   // hạn gần nhất lên đầu (FEFO); lô không ghi hạn xuống cuối
                uasort($lots, fn($a, $b) => [$a['exp'] === null, (string)$a['exp']] <=> [$b['exp'] === null, (string)$b['exp']]);
                $lots = array_values($lots);
            }
            unset($lots);
        }
        $shown = array_values(array_filter($warehouses, fn($w) => in_array((int)$w['id'], $scope, true)));
        return $this->render('inventory/index', $user, 'inventory', [
            'warehouses' => $warehouses, 'mine' => $this->auth->warehouseOf($user),
            'shown' => $shown,
            'rows' => $rows, 'pg' => $pg,
            'byBatch' => $byBatch, 'q' => $q, 'wh' => $wh, 'priceSel' => $priceSel, 'canAddCat' => $user->isAdmin || $user->role === \StockFlow\Domain\AccountRole::Manager, 'cat' => $cat, 'supF' => $supF, 'suppliers' => $this->partners->suppliers(), 'uncategorized' => count(array_filter($items, fn($i) => $i['hoat_dong'] && !(int)$i['danh_muc_id'])), 'totalItems' => count(array_filter($items, fn($i) => $i['hoat_dong'])), 'categories' => $this->catalog->categories(),
            'expiry' => $expiry, 'lotsOf' => $lotsOf ?? [],
            'canPrice' => $this->auth->allows($user, Permission::DieuChinhGia) && $user->warehouseId !== null, 'canCost' => $this->auth->allows($user, Permission::DatGiaGoc),
            'sellAt' => $byBatch ? [] : $this->sellPricesFor(array_map(fn($w) => (int)$w['id'], $shown), array_map(fn($x) => (int)$x['id'], $rows)),
            'priceHist' => $byBatch ? [] : $this->pricing->historyForMany(array_map(fn($x) => (int)$x['id'], $rows), $this->auth->allows($user, Permission::DatGiaGoc) ? null : (int)$user->warehouseId),
            'items' => $items, 'units' => $this->catalog->units(),
            'canRequest' => $user->warehouseId !== null && $this->auth->allows($user, Permission::LapPhieuDieuChuyen),
            'canAddBatch' => $this->auth->allows($user, Permission::ThemLo), 'canDispose' => $this->auth->allows($user, Permission::LapPhieuHuy), 'canBarcode' => $this->auth->allows($user, Permission::GanMaVach),
        ], 'Hàng hoá');
    }

    /**
     * Giá bán từng kho hiển thị ở bảng hàng hoá (mỗi kho đang hiện một ô nhỏ).
     * @param list<int> $warehouseIds @param list<int> $ids @return array<int,array<int,int>> mặt hàng ⇒ [kho ⇒ giá bán]; kho chưa đặt giá thì không có khoá
     */
    private function sellPricesFor(array $warehouseIds, array $ids): array
    {
        $out = [];
        foreach ($warehouseIds as $wh) {
            foreach ($this->pricing->sellPricesAt($wh, $ids) as $id => $price) {
                $out[$id][$wh] = $price;
            }
        }
        return $out;
    }
}
