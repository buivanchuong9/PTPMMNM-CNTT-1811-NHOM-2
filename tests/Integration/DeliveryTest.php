<?php
declare(strict_types=1);
// Giao hàng khi xuất bán: đơn nhỏ khách tự đến lấy; đơn lớn giao tận nơi bằng xe của kho (tính vào lương tài xế theo km) hoặc thuê đơn vị vận tải ngoài (đủ thông tin).
require __DIR__ . '/../Support/bootstrap.php';

use StockFlow\Domain\DomainError;
use StockFlow\Domain\VoucherType;
use StockFlow\Service\DeliveryService;
use StockFlow\Service\FleetService;
use StockFlow\Service\VoucherService;

$f = Fx::make();
$svc = $f->c->get(VoucherService::class);
$fleet = $f->c->get(FleetService::class);
$del = $f->c->get(DeliveryService::class);
$cccd = fn() => (string)random_int(100000000000, 999999999999);
$plate = fn() => '29C-' . random_int(10000, 99999);
$f->receive($f->wa, 'x', 200);
$pricing = $f->c->get(\StockFlow\Service\PricingService::class);
$pricing->setCost($f->admin, $f->it['x']['item'], 5000, 10, 'giá gốc test');
$pricing->setSellPrice($f->approverA, $f->wa, $f->it['x']['item'], 10000, 'giá bán test');
$draft = fn(int $qty = 5) => (int)$f->draftIssue($f->wa, 'x', $qty)['id'];

section('Khách tự đến lấy (đơn nhỏ)');
$v1 = $draft();
throws(fn() => $svc->confirm($f->approverA, $v1, ['hinh_thuc' => 'TU_LAY']), DomainError::class, 'chưa chọn tình trạng thanh toán');
throws(fn() => $svc->confirm($f->approverA, $v1, ['hinh_thuc' => 'TU_LAY', 'thanh_toan' => 'DA_TT']), DomainError::class, 'đã thanh toán phải chọn tiền mặt / chuyển khoản');
$svc->confirm($f->approverA, $v1, ['hinh_thuc' => 'TU_LAY', 'thanh_toan' => 'DA_TT', 'phuong_thuc_tt' => 'TIEN_MAT']);
same($del->of($v1)['hinh_thuc'], 'TU_LAY', 'ghi nhận khách tự lấy');
same([$del->of($v1)['thanh_toan'], $del->of($v1)['phuong_thuc_tt']], ['DA_TT', 'TIEN_MAT'], 'khách đã thanh toán bằng tiền mặt');
same($f->stock($f->wa, 'x'), 195, 'vẫn trừ tồn như thường');
$v0 = $draft();
$svc->confirm($f->approverA, $v0);
same($del->of($v0), null, 'không gửi thông tin giao hàng thì không ghi nhận (tương thích cũ)');

section('Giao tận nơi bằng xe của kho');
$d = $fleet->addVehicleWithDriver($f->approverA, $plate(), 'Xe tải 2 tấn', $f->wa, 'Trần Văn Tài', $cccd(), '0912345678');
$base = ['thanh_toan' => 'COD', 'hinh_thuc' => 'NOI_BO', 'dia_chi' => '12 Nguyễn Trãi, Thanh Xuân, Hà Nội', 'nguoi_nhan' => 'Nguyễn Thị Hoa', 'sdt_nhan' => '0912000111', 'vehicle_id' => $d['vehicle_id'], 'driver_ok' => true, 'km' => 18, 'tien' => 150000, 'ghi_chu' => 'Hàng cồng kềnh'];
$v2 = $draft(10);
throws(fn() => $svc->confirm($f->approverA, $v2, ['dia_chi' => ''] + $base), DomainError::class, 'thiếu địa chỉ giao');
throws(fn() => $svc->confirm($f->approverA, $v2, ['km' => 0] + $base), DomainError::class, 'thiếu km');
throws(fn() => $svc->confirm($f->approverA, $v2, ['nguoi_nhan' => ''] + $base), DomainError::class, 'thiếu tên người nhận');
throws(fn() => $svc->confirm($f->approverA, $v2, ['sdt_nhan' => '123'] + $base), DomainError::class, 'số điện thoại người nhận sai định dạng');
throws(fn() => $svc->confirm($f->approverA, $v2, ['driver_ok' => false] + $base), DomainError::class, 'tài xế chưa xác nhận đủ hàng');
throws(fn() => $svc->confirm($f->approverA, $v2, ['vehicle_id' => 0] + $base), DomainError::class, 'chưa chọn xe');
same($f->stock($f->wa, 'x'), 190, 'lỗi giao hàng thì phiếu không ghi sổ (tồn vẫn 190 sau hai phiếu 5 cái)');
$svc->confirm($f->approverA, $v2, $base);
$g = $del->of($v2);
same([$g['thanh_toan'], (int)$g['so_tien']], ['COD', 110000], 'thu khi nhận hàng: 10 cái × 10.000 + thuế 10% = 110.000');
same([$g['hinh_thuc'], (int)$g['km'], (int)$g['don_gia_km'], (int)$g['tien_goi_y'], (int)$g['tien_tra'], $g['dia_chi']], ['NOI_BO', 18, 8000, 144000, 150000, '12 Nguyễn Trãi, Thanh Xuân, Hà Nội'], '18 km × 8.000 = 144.000 gợi ý, thực trả 150.000');
same($f->stock($f->wa, 'x'), 180, 'phiếu xuất 10 cái ghi sổ trừ tồn: 190 → 180');
$mine = array_values(array_filter($fleet->drivers($f->wa), fn($x) => (int)$x['id'] === $d['driver_id']))[0];
same([(int)$mine['so_chuyen'], (int)$mine['tong_km'], (int)$mine['tien_chuyen']], [1, 18, 150000], 'chuyến giao hàng tính vào lương tài xế theo km');

section('Thuê đơn vị vận tải ngoài để giao');
$ext = ['company' => 'Nhà xe Hoàng Long', 'driver' => 'Lê Văn Thuê', 'cccd' => $cccd(), 'phone' => '0987654321', 'plate' => $plate(), 'type' => 'Xe tải 5 tấn'];
$payExt = ['thanh_toan' => 'DA_TT', 'phuong_thuc_tt' => 'CHUYEN_KHOAN', 'hinh_thuc' => 'THUE_NGOAI', 'dia_chi' => 'KCN Quế Võ, Bắc Ninh', 'nguoi_nhan' => 'Trần Văn Kho', 'sdt_nhan' => '0987000222', 'driver_ok' => true, 'km' => 60, 'tien' => 1200000, 'ghi_chu' => '', 'ngoai' => $ext];
$v3 = $draft(20);
foreach (['company', 'driver', 'cccd', 'phone', 'plate', 'type'] as $k) {
    throws(fn() => $svc->confirm($f->approverA, $v3, ['ngoai' => [$k => ''] + $ext] + $payExt), DomainError::class, "thiếu thông tin bên thứ ba: $k");
}
throws(fn() => $svc->confirm($f->approverA, $v3, ['tien' => 0] + $payExt), DomainError::class, 'thuê ngoài phải có số tiền');
$svc->confirm($f->approverA, $v3, $payExt);
$g = $del->of($v3);
same([$g['hinh_thuc'], $g['don_vi_van_tai'], (int)$g['tien_goi_y'], (int)$g['tien_tra'], $g['bien_so'], $g['tai_xe_ten']], ['THUE_NGOAI', 'Nhà xe Hoàng Long', 900000, 1200000, $ext['plate'], 'Lê Văn Thuê'], 'đơn vị, gợi ý 60 × 15.000, thực trả, xe, tài xế');
$mine = array_values(array_filter($fleet->drivers($f->wa), fn($x) => (int)$x['id'] === $d['driver_id']))[0];
same((int)$mine['tien_chuyen'], 150000, 'giao thuê ngoài không tính vào lương tài xế nội bộ');
same(count(array_filter($fleet->vehicles($f->wa), fn($x) => $x['bien_so'] === $ext['plate'])), 0, 'xe thuê ngoài không hiện trong đội xe');

section('Xe không ở kho / xác nhận lại');
$v4 = $draft(1);
$other = $fleet->addVehicleWithDriver($f->approverB, $plate(), 'Xe B', $f->wb, 'Phạm Văn B', $cccd(), '0912345679');
throws(fn() => $svc->confirm($f->approverA, $v4, ['vehicle_id' => $other['vehicle_id']] + $base), DomainError::class, 'xe của kho khác không chọn được (tồn kho chỉ bị trừ khi giao hợp lệ)');
$svc->confirm($f->approverA, $v3, ['hinh_thuc' => 'TU_LAY', 'thanh_toan' => 'COD']);
same($del->of($v3)['hinh_thuc'], 'THUE_NGOAI', 'xác nhận lại không ghi đè thông tin giao hàng');

finish();
