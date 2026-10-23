<?php
declare(strict_types=1);

namespace StockFlow\Http\Controller;

use StockFlow\Core\Request;
use StockFlow\Core\Response;
use StockFlow\Core\Session;
use StockFlow\Core\View;
use StockFlow\Domain\Permission;
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
        $this->need($user, Permission::QuanLyDoiXe);
        return $this->render('admin/fleet', $user, 'fleet', [
            'vehicles' => $this->fleet->vehicles(), 'drivers' => $this->fleet->drivers(), 'trips' => $this->fleet->recentTrips(20),
            'warehouses' => array_values(array_filter($this->catalog->warehouses(), fn($w) => $w['hoat_dong'])),
            'maxPerWarehouse' => FleetService::MAX_VEHICLES_PER_WAREHOUSE,
        ], 'Đội xe & tài xế');
    }

    public function addVehicle(Request $r, array $p, ?User $user): Response
    {
        return $this->act(function () use ($r, $user) {
            $this->fleet->addVehicle($user, $r->str('plate'), $r->str('type'), (int)$r->int('warehouse_id'), $r->int('driver_id') ?: null);
        }, 'Đã thêm xe.', '/fleet');
    }

    public function updateVehicle(Request $r, array $p, ?User $user): Response
    {
        return $this->act(fn() => $this->fleet->updateVehicle($user, (int)$r->int('vehicle_id'), $r->str('plate'), $r->str('type'), (int)$r->int('warehouse_id'), $r->int('driver_id') ?: null), 'Đã lưu thông tin xe.', '/fleet');
    }

    public function deleteVehicle(Request $r, array $p, ?User $user): Response
    {
        return $this->act(fn() => $this->fleet->deleteVehicle($user, (int)$r->int('vehicle_id')), 'Đã xoá xe.', '/fleet');
    }

    public function vehicleActive(Request $r, array $p, ?User $user): Response
    {
        $on = $r->str('active') === '1';
        return $this->act(fn() => $this->fleet->setVehicleActive($user, (int)$r->int('vehicle_id'), $on), $on ? 'Đã dùng lại xe.' : 'Đã ngừng xe — không chọn được cho chuyến mới.', '/fleet');
    }

    public function vehicleMove(Request $r, array $p, ?User $user): Response
    {
        return $this->act(fn() => $this->fleet->relocateVehicle($user, (int)$r->int('vehicle_id'), (int)$r->int('warehouse_id')), 'Đã cập nhật vị trí xe.', '/fleet');
    }

    public function addDriver(Request $r, array $p, ?User $user): Response
    {
        return $this->act(function () use ($r, $user) {
            $this->fleet->addDriver($user, $r->str('full_name'), $r->str('cccd'), $r->str('phone'));
        }, 'Đã thêm tài xế.', '/fleet');
    }

    public function updateDriver(Request $r, array $p, ?User $user): Response
    {
        return $this->act(fn() => $this->fleet->updateDriver($user, (int)$r->int('driver_id'), $r->str('full_name'), $r->str('cccd'), $r->str('phone')), 'Đã lưu thông tin tài xế.', '/fleet');
    }

    public function deleteDriver(Request $r, array $p, ?User $user): Response
    {
        return $this->act(fn() => $this->fleet->deleteDriver($user, (int)$r->int('driver_id')), 'Đã xoá tài xế.', '/fleet');
    }

    public function driverActive(Request $r, array $p, ?User $user): Response
    {
        $on = $r->str('active') === '1';
        return $this->act(fn() => $this->fleet->setDriverActive($user, (int)$r->int('driver_id'), $on), $on ? 'Đã dùng lại tài xế.' : 'Đã ngừng tài xế — không chọn được cho chuyến mới.', '/fleet');
    }
}
