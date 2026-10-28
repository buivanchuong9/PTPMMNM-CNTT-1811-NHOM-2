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
use StockFlow\Service\CatalogService;
use StockFlow\Service\FleetService;

/** Đội xe & tài xế của hệ thống (tài khoản cấp cao). */
final class FleetController extends Controller
{
    public function __construct(View $view, Session $session, Authorizer $auth, private FleetService $fleet, private CatalogService $catalog)
    {
        parent::__construct($view, $session, $auth);
    }

    public function index(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::XemDoiXe);
        $tab = in_array($r->str('tab'), ['chuyen', 'xe', 'diem', 'thatthoat', 'gia'], true) ? $r->str('tab') : 'chuyen';   // mỗi mục một trang riêng cho đỡ dài
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $r->str('thang')) ? $r->str('thang') : date('Y-m');   // tháng xem điểm, lương, thất thoát
        $wh = $user->isAdmin ? null : (int)$user->warehouseId;   // quản lý kho chỉ thấy đội xe của kho mình; admin thấy tất cả (chỉ xem)
        $warehouses = array_values(array_filter($this->catalog->warehouses(), fn($w) => $w['hoat_dong'] && ($wh === null || (int)$w['id'] === $wh)));
        // bộ lọc (tự lọc ở trình duyệt): tìm kiếm, kho (admin), trạng thái xe, ai chịu thất thoát, tháng
        $q = mb_strtolower(mb_substr(trim($r->str('q')), 0, 50));
        $khoSel = $user->isAdmin ? (int)$r->int('kho') : 0;
        $status = in_array($r->str('tt'), ['ready', 'running', 'off'], true) ? $r->str('tt') : '';
        $bearer = in_array($r->str('ai'), ['TAI_XE', 'CONG_TY'], true) ? $r->str('ai') : '';
        $scope = $khoSel ?: $wh;
        $hit = fn(array $row, array $keys) => $q === '' || array_filter($keys, fn($k) => mb_strpos(mb_strtolower((string)($row[$k] ?? '')), $q) !== false) !== [];
        $all = $this->fleet->vehicles($scope);
        $vehicles = array_values(array_filter($all, fn($x) => $hit($x, ['bien_so', 'loai_xe', 'tai_xe_ten', 'goc_ma', 'ht_ma', 'ht_ten', 'den_ma', 'den_ten'])
            && ($status === '' || $status === (!$x['hoat_dong'] ? 'off' : ($x['dang_den_kho_id'] !== null ? 'running' : 'ready')))));
        $drivers = array_values(array_filter($this->fleet->drivers($scope, $month), fn($d) => $hit($d, ['ho_ten', 'cccd', 'so_dien_thoai', 'xe_phu_trach', 'kho_ma'])));
        $losses = array_values(array_filter($this->fleet->lossRecords(500, $scope, $month), fn($h) => $hit($h, ['phieu_den_ma', 'tai_xe_ten', 'bien_so', 'ly_do', 'di_kho', 'den_kho']) && ($bearer === '' || $h['nguoi_chiu'] === $bearer)));
        $trips = array_values(array_filter($this->fleet->recentTrips(200, $scope), fn($t) => $hit($t, ['phieu_di_ma', 'phieu_den_ma', 'bien_so', 'tai_xe_ten', 'di_kho', 'den_kho', 'sai_lech'])));
        [$pageVehicles, $pgV] = Paginator::of($vehicles, $r, 'xe');
        [$pageDrivers, $pgD] = Paginator::of($drivers, $r, 'tx');
        [$pageLosses, $pgL] = Paginator::of($losses, $r, 'tt');
        [$pageTrips, $pgT] = Paginator::of($trips, $r, 'ch');
        $filtered = $q !== '' || $khoSel || $status !== '' || $bearer !== '' || $month !== date('Y-m');
        return $this->render('admin/fleet', $user, 'fleet', [
            'allVehicles' => $all, 'vehicles' => $pageVehicles, 'missing' => $this->fleet->missingTrips($scope), 'drivers' => $pageDrivers, 'losses' => $pageLosses, 'month' => $month, 'trips' => $pageTrips,
            'tab' => $tab, 'allDrivers' => $drivers,
            'rates' => array_column(array_map(fn($w) => ['id' => (int)$w['id']] + $this->fleet->rates((int)$w['id']), $warehouses), null, 'id'),
            'distances' => $tab === 'gia' ? ($wh !== null ? $this->fleet->distancesFrom($wh) : []) : [], 'myWh' => $wh, 'pgV' => $pgV, 'pgD' => $pgD, 'pgL' => $pgL, 'pgT' => $pgT,
            'q' => $q, 'khoSel' => $khoSel, 'status' => $status, 'bearer' => $bearer, 'filtered' => $filtered, 'isAdmin' => $user->isAdmin,
            'warehouses' => $warehouses, 'canManage' => $this->auth->allows($user, Permission::QuanLyDoiXe) && $wh !== null,
        ], 'Đội xe & tài xế');
    }

    public function saveRates(Request $r, array $p, ?User $user): Response
    {
        return $this->act(function () use ($r, $user) {
            $money = fn(string $k) => \StockFlow\Service\PricingService::parseMoney($r->str($k)) ?? throw new DomainError('Nhập đơn giá mỗi km hợp lệ (đồng)');
            $this->fleet->setRates($user, (int)$user->warehouseId, $money('rate_own'), $money('rate_hired'));
        }, 'Đã lưu đơn giá mỗi km.', '/fleet?tab=gia');
    }

    public function saveDistances(Request $r, array $p, ?User $user): Response
    {
        return $this->act(function () use ($r, $user) {
            foreach ($r->arr('km') as $other => $raw) {
                $raw = is_string($raw) ? trim($raw) : '';
                if ($raw !== '') {
                    $km = StockRules::parseQty($raw) ?? throw new DomainError('Quãng đường phải là số nguyên km');
                    $this->fleet->setDistance($user, (int)$user->warehouseId, (int)$other, $km);
                }
            }
        }, 'Đã lưu quãng đường giữa các kho.', '/fleet?tab=gia');
    }

    public function addVehicle(Request $r, array $p, ?User $user): Response
    {
        return $this->act(function () use ($r, $user) {
            $this->fleet->addVehicleWithDriver($user, $r->str('plate'), $r->str('type'), (int)$r->int('warehouse_id'), $r->str('driver_name'), $r->str('driver_cccd'), $r->str('driver_phone'));
        }, 'Đã thêm xe và tài xế phụ trách.', '/fleet?tab=xe');
    }

    public function updateVehicle(Request $r, array $p, ?User $user): Response
    {
        return $this->act(fn() => $this->fleet->updateVehicleWithDriver($user, (int)$r->int('vehicle_id'), $r->str('plate'), $r->str('type'), (int)$r->int('warehouse_id'), $r->str('driver_name'), $r->str('driver_cccd'), $r->str('driver_phone')), 'Đã lưu thông tin xe và tài xế.', '/fleet?tab=xe');
    }

    public function deleteVehicle(Request $r, array $p, ?User $user): Response
    {
        return $this->act(fn() => $this->fleet->deleteVehicle($user, (int)$r->int('vehicle_id')), 'Đã xoá xe.', '/fleet?tab=xe');
    }

    public function vehicleActive(Request $r, array $p, ?User $user): Response
    {
        $on = $r->str('active') === '1';
        return $this->act(fn() => $this->fleet->setVehicleActive($user, (int)$r->int('vehicle_id'), $on), $on ? 'Đã dùng lại xe.' : 'Đã ngừng xe — không chọn được cho chuyến mới.', '/fleet?tab=xe');
    }

    public function vehicleMove(Request $r, array $p, ?User $user): Response
    {
        return $this->act(fn() => $this->fleet->relocateVehicle($user, (int)$r->int('vehicle_id'), (int)$r->int('warehouse_id')), 'Đã cập nhật vị trí xe.', '/fleet?tab=xe');
    }

    /** Quản trị phản hồi cho kho đến: tài xế đang làm gì, dự kiến thế nào. */
    public function reply(Request $r, array $p, ?User $user): Response
    {
        return $this->act(fn() => $this->fleet->replyToMissing($user, (int)$r->int('voucher_id'), $r->str('status'), $r->str('note')), 'Đã gửi phản hồi cho kho — kho thấy ngay ở phiếu điều chuyển.', '/fleet');
    }

    /** Quản trị xác nhận mất hàng: ghi người chịu, lý do; tài xế chịu thì trừ lương và điểm. */
    public function confirmLoss(Request $r, array $p, ?User $user): Response
    {
        $num = fn(string $k) => $r->str($k) === '' ? null : (int)preg_replace('/\D/', '', $r->str($k));
        return $this->act(fn() => $this->fleet->confirmLoss($user, (int)$r->int('voucher_id'), $r->str('reason'), $r->str('bearer'), $num('deduct'), $num('points')),
            'Đã xác nhận mất hàng — chuyến được đóng, phiếu đến bị huỷ.', '/fleet?tab=thatthoat');
    }

    public function adjustLoss(Request $r, array $p, ?User $user): Response
    {
        $num = fn(string $k) => $r->str($k) === '' ? null : (int)preg_replace('/\D/', '', $r->str($k));
        return $this->act(fn() => $this->fleet->adjustLoss($user, (int)$r->int('loss_id'), $r->str('bearer'), $num('deduct'), $num('points')), 'Đã cập nhật mức xử lý thất thoát.', '/fleet?tab=thatthoat');
    }

    public function driverPay(Request $r, array $p, ?User $user): Response
    {
        return $this->act(fn() => $this->fleet->updateDriverPay($user, (int)$r->int('driver_id'), (int)preg_replace('/\D/', '', $r->str('salary')), (int)$r->int('score')), 'Đã lưu lương và điểm tài xế.', '/fleet?tab=diem');
    }

    public function updateDriver(Request $r, array $p, ?User $user): Response
    {
        return $this->act(fn() => $this->fleet->updateDriver($user, (int)$r->int('driver_id'), $r->str('full_name'), $r->str('cccd'), $r->str('phone')), 'Đã lưu thông tin tài xế.', '/fleet?tab=diem');
    }

    public function deleteDriver(Request $r, array $p, ?User $user): Response
    {
        return $this->act(fn() => $this->fleet->deleteDriver($user, (int)$r->int('driver_id')), 'Đã xoá tài xế.', '/fleet?tab=diem');
    }

    public function driverActive(Request $r, array $p, ?User $user): Response
    {
        $on = $r->str('active') === '1';
        return $this->act(fn() => $this->fleet->setDriverActive($user, (int)$r->int('driver_id'), $on), $on ? 'Đã dùng lại tài xế.' : 'Đã ngừng tài xế — không chọn được cho chuyến mới.', '/fleet?tab=diem');
    }
}
