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
        // bộ lọc: tìm · kho · mặt hàng · hạn bảo hành · kết quả (tab chờ trả) · thời gian nhận · sắp xếp
        $q = trim($r->str('q'));
        [$from, $to, $rangeSel] = range_params($r);
        $wh = (int)$r->int('warehouse');
        $item = (int)$r->int('item');
        $valid = in_array($r->str('valid'), ['yes', 'no'], true) ? $r->str('valid') : '';
        $result = $tab === 'WAITING' && in_array($r->str('result'), WarrantyService::WAITING, true) ? $r->str('result') : '';
        $sort = in_array($r->str('sort'), ['old', 'expiry'], true) ? $r->str('sort') : '';
        $all = $this->warranty->list($scope, $tab);
        $list = array_filter($all, fn($b) => (!$wh || (int)$b['kho_id'] === $wh) && (!$item || (int)$b['mat_hang_id'] === $item)
            && ($valid === '' || (bool)(int)$b['con_han'] === ($valid === 'yes')) && ($result === '' || $b['trang_thai'] === $result)
            && in_vn_range($b['ngay_tao'], $from, $to));
        $list = filter_rows(array_values($list), $q, fn($b) => implode(' ', [$b['ma'], $b['khach_ten'], $b['khach_sdt'], $b['item_name'], $b['ma_hang'],
            $b['loi'], $b['ket_qua'], $b['sale_lot'], $b['sale_item'], $b['voucher_code'], $b['wh_code']]));
        if ($sort === 'old') {
            $list = array_reverse($list);
        } elseif ($sort === 'expiry') {
            usort($list, fn($a, $b) => strcmp((string)$a['het_han'], (string)$b['het_han']));
        }
        [$rows, $pg] = Paginator::of($list, $r);
        $f = ['warehouse' => $wh, 'item' => $item, 'valid' => $valid, 'result' => $result, 'sort' => $sort,
            'whOpts' => options_of($all, 'kho_id', 'wh_code'), 'itemOpts' => options_of($all, 'mat_hang_id', fn($b) => $b['ma_hang'] . ' — ' . $b['item_name'])];
        $keep = keep_params(['q' => $q, 'warehouse' => $wh, 'item' => $item, 'valid' => $valid, 'range' => $rangeSel,
            'from' => $rangeSel === 'custom' ? $from : null, 'to' => $rangeSel === 'custom' ? $to : null, 'sort' => $sort]);
        return $this->render('warranty/index', $user, 'warranty', [
            'rows' => $rows, 'pg' => $pg, 'tab' => $tab, 'q' => $q, 'f' => $f, 'keep' => $keep, 'from' => $from, 'to' => $to, 'rangeSel' => $rangeSel,
            'counts' => $this->warranty->counts($scope),
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
