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
use StockFlow\Service\ItemProposalService;
use StockFlow\Service\PricingService;

/** «Đề xuất hàng mới»: quản lý kho gửi đề xuất từ mã vạch quét được; quản trị chấp nhận (đặt giá gốc) hoặc từ chối kèm lý do. */
final class ProposalController extends Controller
{
    public function __construct(View $view, Session $session, Authorizer $auth, private ItemProposalService $proposals)
    {
        parent::__construct($view, $session, $auth);
    }

    public function index(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::GanMaVach, Permission::DuyetDeXuatHang);
        $status = array_key_exists($r->str('status'), ItemProposalService::LABELS) ? $r->str('status') : '';
        $counts = $this->proposals->counts($user);
        // bộ lọc: tìm · loại hàng · nhà cung cấp · kho · thời gian · sắp xếp
        $q = trim($r->str('q'));
        [$from, $to, $rangeSel] = range_params($r);
        $cat = (int)$r->int('category');
        $sup = (int)$r->int('supplier');
        $wh = (int)$r->int('warehouse');
        $sort = $r->str('sort') === 'old' ? 'old' : 'new';
        $all = $this->proposals->list($user, $status ?: null);
        $list = array_filter($all, fn($x) => (!$cat || (int)$x['danh_muc_id'] === $cat) && (!$sup || (int)$x['nha_cung_cap_id'] === $sup)
            && (!$wh || (int)$x['kho_id'] === $wh) && in_vn_range($x['ngay_tao'], $from, $to));
        $list = filter_rows(array_values($list), $q, fn($x) => implode(' ', [$x['ma_vach'], $x['ma_hang'], $x['ten'], $x['category'], $x['supplier'], $x['kho_ma'], $x['de_xuat_ten']]));
        if ($sort === 'old') {
            usort($list, fn($a, $b) => (int)$a['id'] <=> (int)$b['id']);
        }
        [$rows, $pg] = Paginator::of($list, $r);
        $f = ['category' => $cat, 'supplier' => $sup, 'warehouse' => $wh, 'sort' => $sort,
            'catOpts' => options_of($all, 'danh_muc_id', 'category'), 'supOpts' => options_of($all, 'nha_cung_cap_id', 'supplier'), 'whOpts' => options_of($all, 'kho_id', 'kho_ma')];
        $keep = keep_params(['q' => $q, 'category' => $cat, 'supplier' => $sup, 'warehouse' => $wh, 'range' => $rangeSel, 'from' => $rangeSel === 'custom' ? $from : null, 'to' => $rangeSel === 'custom' ? $to : null, 'sort' => $sort === 'old' ? 'old' : null]);
        $this->proposals->markSeen($user);
        return $this->render('proposals/index', $user, 'proposals', [
            'rows' => $rows, 'pg' => $pg, 'status' => $status, 'q' => $q, 'f' => $f, 'keep' => $keep, 'from' => $from, 'to' => $to, 'rangeSel' => $rangeSel,
            'counts' => $counts, 'total' => array_sum($counts),
            'canReview' => $this->auth->allows($user, Permission::DuyetDeXuatHang), 'canPropose' => $this->auth->allows($user, Permission::GanMaVach),
            'canReceive' => $this->auth->allows($user, Permission::LapPhieuNhap), 'vatRates' => PricingService::VAT_RATES,
        ], 'Đề xuất hàng mới');
    }

    /** Gửi từ hộp thoại quét mã vạch. */
    public function store(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::GanMaVach);
        return $this->act(function () use ($r, $user) {
            $this->proposals->create($user, $r->str('code'), $r->str('sku'), $r->str('name'), (int)$r->int('unit_id'), $r->str('track_batch') === '1',
                (int)$r->int('category_id') ?: throw new DomainError('Chọn danh mục (loại hàng hoá) cho mặt hàng'), (int)$r->int('supplier_id'));
        }, 'Đã gửi đề xuất cho quản trị. Khi được chấp nhận, mặt hàng sẽ được tạo kèm mã vạch này và bạn lập phiếu nhập.', '/proposals');
    }

    public function approve(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::DuyetDeXuatHang);
        return $this->act(function () use ($r, $p, $user) {
            $cost = PricingService::parseMoney($r->str('cost'));
            if ($cost === null || $cost < 1) {
                throw new DomainError('Nhập giá gốc lớn hơn 0 (đồng / 1 đơn vị tính)');
            }
            $this->proposals->approve($user, (int)$p['id'], $cost, (int)$r->int('vat'));
        }, 'Đã chấp nhận: mặt hàng mới được tạo kèm mã vạch. Quản lý kho sẽ nhận thông báo để lập phiếu nhập.', '/proposals');
    }

    public function reject(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::DuyetDeXuatHang);
        return $this->act(fn() => $this->proposals->reject($user, (int)$p['id'], $r->str('reason')), 'Đã từ chối đề xuất.', '/proposals');
    }

    public function cancel(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::GanMaVach);
        return $this->act(fn() => $this->proposals->cancel($user, (int)$p['id']), 'Đã rút lại đề xuất.', '/proposals');
    }
}
