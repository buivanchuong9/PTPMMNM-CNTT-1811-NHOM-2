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
use StockFlow\Domain\StockRules;
use StockFlow\Domain\User;
use StockFlow\Service\Authorizer;
use StockFlow\Service\DisposalService;

final class DisposalController extends Controller
{
    public function __construct(View $view, Session $session, Authorizer $auth, private DisposalService $disposals)
    {
        parent::__construct($view, $session, $auth);
    }

    public function index(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::XemKhoHuy);
        $scope = $this->auth->visibleWarehouseIds($user);
        $status = isset(DisposalService::STATUS[$r->str('status')]) ? $r->str('status') : 'TRONG_KHO_HUY';
        $q = trim($r->str('q'));
        $canPropose = $user->warehouseId !== null && $this->auth->allows($user, Permission::LapPhieuHuy);
        [$rows, $pg] = Paginator::of($this->disposals->list($scope, $status, $q), $r);
        [$expired, $expiredPg] = Paginator::of($this->disposals->expiredStock($scope), $r, 'hh');
        return $this->render('disposals/index', $user, 'disposals', [
            'rows' => $rows, 'pg' => $pg, 'status' => $status, 'q' => $q, 'counts' => $this->disposals->counts($scope),
            'loss' => $this->disposals->lossByReason($scope), 'expired' => $expired, 'expiredPg' => $expiredPg,
            'pick' => $canPropose ? $this->disposals->pickable((int)$user->warehouseId) : [],
            'canPropose' => $canPropose, 'canApprove' => $user->warehouseId !== null && $this->auth->allows($user, Permission::XacNhanHuy),
        ], 'Kho huỷ');
    }

    public function store(Request $r, array $p, ?User $user): Response
    {
        return $this->act(function () use ($r, $user) {
            if (!preg_match('/^(\d+):(\d+)$/', $r->str('pick'), $m)) {
                throw new DomainError('Chọn mặt hàng/lô cần huỷ');
            }
            $res = $this->disposals->propose($user, (int)$user->warehouseId, (int)$m[1], (int)$m[2], StockRules::parseQty($r->str('qty')) ?? 0, $r->str('reason'), $r->str('note'));
            $this->session->flash('ok', $res['approved'] ? 'Đã chuyển hàng vào kho huỷ và trừ khỏi tồn.' : 'Đã gửi đề xuất huỷ — chờ quản lý kho duyệt.');
        }, '', '/disposals');
    }

    public function approve(Request $r, array $p, ?User $user): Response
    {
        return $this->act(fn() => $this->disposals->approve($user, (int)$p['id']), 'Đã duyệt: hàng vào kho huỷ và được trừ khỏi tồn.', '/disposals?status=CHO_DUYET');
    }

    public function reject(Request $r, array $p, ?User $user): Response
    {
        return $this->act(fn() => $this->disposals->reject($user, (int)$p['id']), 'Đã từ chối đề xuất huỷ.', '/disposals?status=CHO_DUYET');
    }

    public function destroy(Request $r, array $p, ?User $user): Response
    {
        return $this->act(fn() => $this->disposals->destroy($user, (int)$p['id']), 'Đã ghi nhận tiêu huỷ.', '/disposals');
    }
}
