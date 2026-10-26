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
        [$rows, $pg] = Paginator::of($this->proposals->list($user, $status ?: null), $r);
        $this->proposals->markSeen($user);
        return $this->render('proposals/index', $user, 'proposals', [
            'rows' => $rows, 'pg' => $pg, 'status' => $status, 'counts' => $counts, 'total' => array_sum($counts),
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
