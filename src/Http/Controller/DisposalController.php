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
    public function __construct(View $view, Session $session, Authorizer $auth, private DisposalService $disposals, private \StockFlow\Service\FleetService $fleet, private \StockFlow\Service\TransportService $transport)
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
        $canApprove = $user->warehouseId !== null && $this->auth->allows($user, Permission::XacNhanHuy);
        $vehicles = $canApprove && $status === 'TRONG_KHO_HUY' ? $this->fleet->availableVehicles((int)$user->warehouseId) : [];
        $drivers = $vehicles ? $this->fleet->availableDrivers((int)$user->warehouseId) : [];
        $driverFit = $drivers ? $this->transport->driverFit($drivers) : [];
        $loadBy = $fitBy = $recBy = [];   // theo từng phiếu huỷ chờ xuất: tải của lô hàng, xe nào đủ điều kiện chở, xe nên chọn
        foreach ($rows as $h) {
            if ($vehicles && $h['trang_thai'] === 'TRONG_KHO_HUY' && $h['xe_id'] === null) {
                $load = $this->transport->loadOf([['mat_hang_id' => (int)$h['mat_hang_id'], 'so_luong' => (int)$h['so_luong']]]);
                $loadBy[(int)$h['id']] = $load;
                $fitBy[(int)$h['id']] = $fit = $this->transport->fitMap($vehicles, $load);
                $recBy[(int)$h['id']] = $this->transport->recommend($vehicles, $fit);
            }
        }
        return $this->render('disposals/index', $user, 'disposals', [
            'driverFit' => $driverFit, 'loadBy' => $loadBy, 'fitBy' => $fitBy, 'recBy' => $recBy,
            'vehicles' => $vehicles, 'drivers' => $drivers, 'site' => $user->warehouseId !== null ? $this->disposals->site((int)$user->warehouseId) : null,
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
            $res = $this->disposals->propose($user, (int)($r->int('warehouse_id') ?: $user->warehouseId), (int)$m[1], (int)$m[2], StockRules::parseQty($r->str('qty')) ?? 0, $r->str('reason'), $r->str('note'));
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

    public function undo(Request $r, array $p, ?User $user): Response
    {
        return $this->act(function () use ($p, $user) {
            $to = $this->disposals->undo($user, (int)$p['id']);
            $this->session->flash('ok', ['TRONG_KHO_HUY' => 'Đã hoàn tác: chưa tiêu huỷ, xe vẫn đang chở hàng.', 'KHO_HUY' => 'Đã hoàn tác: hàng xuống xe, nằm lại kho huỷ.', 'TU_CHOI' => 'Đã hoàn tác: hàng được trả về tồn kho.'][$to] ?? 'Đã hoàn tác.');
        }, '', '/disposals');
    }

    public function destroy(Request $r, array $p, ?User $user): Response
    {
        return $this->act(fn() => $this->disposals->destroy($user, (int)$p['id']), 'Xe đã đến nơi — hàng đã được tiêu huỷ.', '/disposals?status=DA_TIEU_HUY');
    }

    public function load(Request $r, array $p, ?User $user): Response
    {
        return $this->act(fn() => $this->disposals->load($user, (int)$p['id'], (int)$r->int('vehicle_id'), (int)$r->int('driver_id'), $r->str('driver_ok') === '1'), 'Đã nhận hàng lên xe và xuất đi tiêu huỷ.', '/disposals');
    }

    public function site(Request $r, array $p, ?User $user): Response
    {
        return $this->act(fn() => $this->disposals->setSite($user, (int)$user->warehouseId, $r->str('noi'), $r->str('dia_chi')), 'Đã lưu nơi tiêu huỷ của kho.', '/disposals');
    }
}
