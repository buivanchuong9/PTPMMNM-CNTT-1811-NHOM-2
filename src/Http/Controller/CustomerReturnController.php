<?php
declare(strict_types=1);

namespace StockFlow\Http\Controller;

use StockFlow\Core\Paginator;
use StockFlow\Core\Request;
use StockFlow\Core\Response;
use StockFlow\Core\Session;
use StockFlow\Core\View;
use StockFlow\Domain\DomainError;
use StockFlow\Domain\Permission;
use StockFlow\Domain\User;
use StockFlow\Service\Authorizer;
use StockFlow\Service\CustomerReturnService;

/** Khách trả hàng: lập từ phiếu xuất bán, quản lý kho xác nhận thì hàng nhập lại kho (phần lỗi chuyển sang kho huỷ). */
final class CustomerReturnController extends Controller
{
    public function __construct(View $view, Session $session, Authorizer $auth, private CustomerReturnService $returns)
    {
        parent::__construct($view, $session, $auth);
    }

    public function index(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::XemPhieu);
        $ids = $this->auth->visibleWarehouseIds($user);
        $status = isset(CustomerReturnService::STATUS[$r->str('status')]) ? $r->str('status') : '';
        $q = trim($r->str('q'));
        $customers = $this->returns->customers($ids);
        $customer = in_array($r->str('customer'), $customers, true) ? $r->str('customer') : '';
        [$from, $to, $rangeSel] = range_params($r);
        $found = $this->returns->search($ids, $q, $customer, $from, $to);
        $counts = array_count_values(array_column($found, 'trang_thai'));   // số trên từng tab theo đúng bộ lọc đang chọn
        [$rows, $pg] = Paginator::of($status === '' ? $found : array_values(array_filter($found, fn($t) => $t['trang_thai'] === $status)), $r);
        return $this->render('creturns/index', $user, 'creturns', ['rows' => $rows, 'pg' => $pg, 'status' => $status, 'q' => $q, 'counts' => $counts,
            'customer' => $customer, 'customers' => $customers, 'rangeSel' => $rangeSel, 'from' => $from, 'to' => $to], 'Khách trả hàng');
    }

    public function create(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::LapPhieuNhap);
        $issue = $this->returns->returnableIssue((int)$r->int('voucher'));
        $this->auth->require($user, (int)$issue['kho_id'], Permission::LapPhieuNhap);
        $wh = (int)$issue['kho_id'];
        return $this->render('creturns/form', $user, 'creturns', ['issue' => $issue, 'lines' => $this->returns->soldLines((int)$issue['id']), 'causes' => CustomerReturnService::CAUSES, 'methods' => CustomerReturnService::REFUND_METHODS,
            'drivers' => $this->returns->drivers($wh), 'staff' => $this->returns->warehouseStaff($wh), 'driver' => $this->returns->deliveryDriver((int)$issue['id'])], 'Khách trả hàng');
    }

    public function store(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::LapPhieuNhap);
        $issueId = (int)$r->int('voucher');
        return $this->act(function () use ($r, $user, $issueId) {
            $keys = $r->arr('key');
            $qty = $r->arr('qty');
            $bad = $r->arr('bad');
            $why = $r->arr('why');
            $driver = $r->arr('driver');
            $staff = $r->arr('staff');
            $deduct = $r->arr('deduct');
            $lines = [];
            foreach ($keys as $i => $key) {
                if (!is_scalar($key) || !preg_match('/^(\d+):(\d+)$/', (string)$key, $m)) {
                    continue;
                }
                $q = is_scalar($qty[$i] ?? null) ? trim((string)$qty[$i]) : '';
                $b = is_scalar($bad[$i] ?? null) ? trim((string)$bad[$i]) : '';
                if (($q !== '' && !ctype_digit($q)) || ($b !== '' && !ctype_digit($b))) {
                    throw new DomainError('Số lượng phải là số nguyên không âm');
                }
                $d = is_scalar($deduct[$i] ?? null) ? trim((string)$deduct[$i]) : '';
                if ($d !== '' && !ctype_digit($d)) {
                    throw new DomainError('Tiền trừ khách phải là số nguyên không âm');
                }
                $lines[] = ['mat_hang_id' => (int)$m[1], 'lo_hang_id' => (int)$m[2], 'so_luong' => (int)$q, 'so_luong_loi' => (int)$b, 'ly_do_loi' => is_scalar($why[$i] ?? null) ? (string)$why[$i] : '',
                    'tai_xe_id' => is_scalar($driver[$i] ?? null) ? (int)$driver[$i] : 0, 'nguoi_chiu_id' => is_scalar($staff[$i] ?? null) ? (int)$staff[$i] : 0, 'tien_tru_khach' => $d === '' ? null : (int)$d];
            }
            return '/customer-returns/' . $this->returns->create($user, $issueId, $lines, $r->str('reason'), $r->str('refund_by') ?: 'TIEN_MAT');
        }, 'Đã lập phiếu khách trả hàng. Quản lý kho xác nhận để nhập lại kho.', '/customer-returns/new?voucher=' . $issueId);
    }

    public function show(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::XemPhieu);
        $t = $this->returns->find((int)$p['id']);
        $this->auth->requireVisible($user, (int)$t['kho_id']);
        return $this->render('creturns/show', $user, 'creturns', ['t' => $t, 'canConfirm' => $this->auth->can($user, (int)$t['kho_id'], Permission::XacNhanPhieu),
            'canCancel' => $this->auth->can($user, (int)$t['kho_id'], Permission::XacNhanPhieu) || $this->auth->can($user, (int)$t['kho_id'], Permission::LapPhieuNhap)], 'Phiếu ' . $t['ma']);
    }

    public function confirm(Request $r, array $p, ?User $user): Response
    {
        $id = (int)$p['id'];
        return $this->act(function () use ($user, $id) {
            foreach ($this->returns->confirm($user, $id) as $w) {
                $this->session->flash('err', $w);
            }
        }, 'Đã nhập lại kho. Phần hàng lỗi đã chuyển sang quy trình kho huỷ.', "/customer-returns/$id");
    }

    public function cancel(Request $r, array $p, ?User $user): Response
    {
        $id = (int)$p['id'];
        return $this->act(fn() => $this->returns->cancel($user, $id), 'Đã huỷ phiếu khách trả hàng.', "/customer-returns/$id");
    }
}
