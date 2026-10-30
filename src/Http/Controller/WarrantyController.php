<?php
declare(strict_types=1);

namespace StockFlow\Http\Controller;

use StockFlow\Core\Paginator;
use StockFlow\Core\Request;
use StockFlow\Core\Response;
use StockFlow\Core\Session;
use StockFlow\Core\View;
use StockFlow\Domain\Permission;
use StockFlow\Domain\StockRules;
use StockFlow\Domain\User;
use StockFlow\Service\Authorizer;
use StockFlow\Service\SaleCodeService;
use StockFlow\Service\StockQuery;
use StockFlow\Service\WarrantyService;

final class WarrantyController extends Controller
{
    public function __construct(View $view, Session $session, Authorizer $auth, private WarrantyService $warranty, private SaleCodeService $saleCodes)
    {
        parent::__construct($view, $session, $auth);
    }

    public function index(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::XemBaoHanh);
        $scope = $this->auth->visibleWarehouseIds($user);
        $tab = in_array($r->str('tab'), ['TIEP_NHAN', 'WAITING', 'DA_TRA'], true) ? $r->str('tab') : 'TIEP_NHAN';
        $q = trim($r->str('q'));
        [$rows, $pg] = Paginator::of($this->warranty->list($scope, $tab, $q), $r);
        return $this->render('warranty/index', $user, 'warranty', [
            'rows' => $rows, 'pg' => $pg, 'tab' => $tab, 'q' => $q, 'counts' => $this->warranty->counts($scope),
            'items' => $this->warranty->warrantyItems(), 'today' => StockQuery::today(),
            'canHandle' => $user->warehouseId !== null && $this->auth->allows($user, Permission::XuLyBaoHanh),
        ], 'Bảo hành');
    }

    /** Tra nhanh bằng mã lô hàng + mã món hàng (form nhận bảo hành điền sẵn thông tin). */
    public function lookup(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::XemBaoHanh);
        $u = $this->saleCodes->find($r->str('lot'), $r->str('item'));
        $body = ['found' => false];
        if ($u && in_array((int)$u['kho_id'], $this->auth->visibleWarehouseIds($user), true)) {
            $body = ['found' => true, 'item' => $u['ma_hang'] . ' — ' . $u['item_name'], 'lot' => $u['so_lo'] === 'NOLOT' ? '' : $u['so_lo'], 'wh' => $u['wh_code'],
                'voucher' => $u['voucher_code'], 'customer' => (string)$u['customer'], 'bought' => $u['ngay_mua'], 'months' => (int)$u['bao_hanh_thang'], 'expiry' => $u['het_han'],
                'valid' => (bool)$u['con_han'] && !(int)$u['da_huy'], 'void' => (bool)(int)$u['da_huy'], 'open' => $u['dang_bao_hanh']];
        }
        return new Response(json_encode($body, JSON_UNESCAPED_UNICODE), 200, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public function store(Request $r, array $p, ?User $user): Response
    {
        return $this->act(function () use ($r, $user) {
            $this->warranty->receive($user, (int)$user->warehouseId, (int)$r->int('item_id'), StockRules::parseQty($r->str('qty')) ?? 0,
                $r->str('customer'), $r->str('phone'), $r->str('phieu_mua'), $r->str('defect'), $r->str('lot_code'), $r->str('item_code'));
        }, 'Đã nhận hàng vào kho bảo hành.', '/warranty');
    }

    public function resolve(Request $r, array $p, ?User $user): Response
    {
        $label = ['DA_SUA' => 'Đã ghi: sửa xong.', 'DOI_MOI' => 'Đã đổi mới — một đơn vị đã được trừ khỏi tồn kho.', 'TU_CHOI' => 'Đã từ chối bảo hành.'];
        return $this->act(fn() => $this->warranty->resolve($user, (int)$p['id'], $r->str('result'), $r->str('note')),
            $label[$r->str('result')] ?? 'Đã lưu kết quả.', '/warranty');
    }

    public function handBack(Request $r, array $p, ?User $user): Response
    {
        return $this->act(fn() => $this->warranty->handBack($user, (int)$p['id']), 'Đã trả hàng cho khách.', '/warranty?tab=WAITING');
    }
}
