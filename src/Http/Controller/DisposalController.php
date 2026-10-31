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
        $reason = isset(DisposalService::REASONS[$r->str('reason')]) ? $r->str('reason') : '';
        $cat = (int)$r->int('category');
        $wh = in_array((int)$r->int('warehouse'), $scope, true) ? (int)$r->int('warehouse') : 0;
        [$from, $to, $rangeSel] = range_params($r);
        $found = $this->disposals->search($scope, $q, $reason, $cat, $wh, $from, $to);
        $counts = array_count_values(array_column($found, 'trang_thai'));   // số trên từng tab theo đúng bộ lọc đang chọn
        $tabRows = array_values(array_filter($found, fn($h) => $h['trang_thai'] === $status));
        $onRoad = $status === 'TRONG_KHO_HUY' ? array_values(array_filter($tabRows, fn($h) => $h['xe_id'] !== null)) : [];   // hàng đã lên xe: hiện thành từng chuyến, không phân trang
        if ($onRoad) {
            $tabRows = array_values(array_filter($tabRows, fn($h) => $h['xe_id'] === null));
        }
        [$rows, $pg] = Paginator::of($tabRows, $r);
        [$expired, $expiredPg] = Paginator::of($this->disposals->expiredStock($scope), $r, 'hh');
        $canApprove = $user->warehouseId !== null && $this->auth->allows($user, Permission::XacNhanHuy);
        $vehicles = $canApprove && $status === 'TRONG_KHO_HUY' ? $this->fleet->availableVehicles((int)$user->warehouseId) : [];
        $drivers = $vehicles ? $this->fleet->availableDrivers((int)$user->warehouseId) : [];
        $driverFit = $drivers ? $this->transport->driverFit($drivers) : [];
        $trips = [];   // hàng đã lên xe gom theo chuyến (cùng xe, cùng giờ xuất): một nút «Đã đến nơi» / «Quay lại» cho cả chuyến
        foreach ($onRoad as $h) {
            $trips[$h['xe_id'] . '|' . $h['ngay_xuat']][] = $h;
        }
        return $this->render('disposals/index', $user, 'disposals', [
            'driverFit' => $driverFit, 'trips' => $trips,
            'vehicles' => $vehicles, 'drivers' => $drivers, 'site' => $user->warehouseId !== null ? $this->disposals->site((int)$user->warehouseId) : null,
            'rows' => $rows, 'pg' => $pg, 'status' => $status, 'q' => $q, 'counts' => $counts,
            'reason' => $reason, 'cat' => $cat, 'wh' => $wh, 'rangeSel' => $rangeSel, 'from' => $from, 'to' => $to,
            'catOptions' => options_of($found, 'danh_muc_id', 'category_name'), 'whOptions' => $user->isAdmin ? options_of($found, 'kho_id', fn($h) => $h['wh_code'] . ' — ' . $h['wh_name']) : [],
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

    /** Nhiều lô cùng lên MỘT xe: tích chọn ở bảng rồi chọn một xe, một tài xế. */
    public function loadBatch(Request $r, array $p, ?User $user): Response
    {
        return $this->act(function () use ($r, $user) {
            $n = $this->disposals->loadMany($user, array_map('intval', $r->arr('ids')), (int)$r->int('vehicle_id'), (int)$r->int('driver_id'), $r->str('driver_ok') === '1');
            $this->session->flash('ok', "Đã nhận $n lô hàng lên xe và xuất đi tiêu huỷ.");
        }, '', '/disposals');
    }

    /** Cả chuyến đã đến nơi tiêu huỷ. */
    public function arriveBatch(Request $r, array $p, ?User $user): Response
    {
        return $this->act(function () use ($r, $user) {
            $n = $this->disposals->destroyMany($user, array_map('intval', $r->arr('ids')));
            $this->session->flash('ok', "Đã xác nhận $n lô hàng đến nơi và tiêu huỷ.");
        }, '', '/disposals');
    }

    /** Cả chuyến xuống xe, quay lại kho huỷ. */
    public function unloadBatch(Request $r, array $p, ?User $user): Response
    {
        return $this->act(function () use ($r, $user) {
            $n = $this->disposals->unloadMany($user, array_map('intval', $r->arr('ids')));
            $this->session->flash('ok', "Đã cho $n lô hàng xuống xe, quay lại kho huỷ.");
        }, '', '/disposals');
    }

    /**
     * Gợi ý xe cho đúng các lô đang tích: tóm tắt tải + danh sách xe (đủ điều kiện / không đủ tải…). Trả JSON {summary, options} để trang cập nhật khi tích chọn.
     */
    public function fit(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::XacNhanHuy);
        $ids = array_values(array_unique(array_filter(array_map('intval', $r->arr('ids')))));
        $lines = [];
        foreach ($ids as $id) {
            $h = $this->disposals->find($id);
            $this->auth->requireVisible($user, (int)$h['kho_id']);
            if ($h['trang_thai'] === 'TRONG_KHO_HUY' && $h['xe_id'] === null) {
                $lines[] = ['mat_hang_id' => (int)$h['mat_hang_id'], 'so_luong' => (int)$h['so_luong']];
            }
        }
        $vehicles = $user->warehouseId !== null ? $this->fleet->availableVehicles((int)$user->warehouseId) : [];
        $load = $lines ? $this->transport->loadOf($lines) : null;
        $fit = $load ? $this->transport->fitMap($vehicles, $load) : [];
        $recommended = $load ? $this->transport->recommend($vehicles, $fit) : null;
        ob_start();
        require __DIR__ . '/../../../templates/partials/load_summary.php';
        $summary = (string)ob_get_clean();
        ob_start();
        require __DIR__ . '/../../../templates/partials/vehicle_options.php';
        $options = (string)ob_get_clean();
        return new Response((string)json_encode(['count' => count($lines), 'summary' => $summary, 'options' => $options], JSON_UNESCAPED_UNICODE), 200, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public function site(Request $r, array $p, ?User $user): Response
    {
        return $this->act(fn() => $this->disposals->setSite($user, (int)$user->warehouseId, $r->str('noi'), $r->str('dia_chi')), 'Đã lưu nơi tiêu huỷ của kho.', '/disposals');
    }
}
