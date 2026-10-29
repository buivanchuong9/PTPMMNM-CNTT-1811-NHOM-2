<?php
declare(strict_types=1);
// Xe máy giao đơn nhỏ: kiểu xe, bằng A1 / A theo dung tích xi-lanh, tải trọng nhỏ, không chạy điều chuyển liên tỉnh,
// và «khuyên dùng» xe nhỏ nhất đủ chở khi hàng nhẹ.
require __DIR__ . '/../Support/bootstrap.php';

use StockFlow\Domain\DomainError;
use StockFlow\Domain\TransportRules as R;
use StockFlow\Service\CatalogService;
use StockFlow\Service\DeliveryService;
use StockFlow\Service\FleetService;
use StockFlow\Service\TransferService;
use StockFlow\Service\TransportService;
use StockFlow\Service\VoucherService;

$f = Fx::make();
$fleet = $f->c->get(FleetService::class);
$tr = $f->c->get(TransportService::class);
$ts = $f->c->get(TransferService::class);
$catalog = $f->c->get(CatalogService::class);
$cccd = fn() => (string)random_int(100000000000, 999999999999);
$d = fn(string $s) => date('Y-m-d', strtotime($s));
$lic = fn(string $class) => ['so_gplx' => (string)random_int(100000000000, 999999999999), 'hang_gplx' => $class, 'ngay_sinh' => $d('-27 years'), 'ngay_cap_gplx' => $d('-4 years'), 'han_kham_sk' => $d('+1 year')];
$ins = $d('+6 months');
$motoSpecs = ['kieu_xe' => 'MAY', 'dung_tich_cc' => 110, 'tai_trong_kg' => 50, 'thung_dai_mm' => 450, 'thung_rong_mm' => 400, 'thung_cao_mm' => 400, 'han_bao_hiem' => $ins];
$carSpecs = ['kieu_xe' => 'OTO_NHO', 'tai_trong_kg' => 900, 'gvw_kg' => 3150, 'loai_thung' => 'LUNG', 'thung_dai_mm' => 1500, 'thung_rong_mm' => 1500, 'thung_cao_mm' => 500, 'han_dang_kiem' => $ins, 'han_bao_hiem' => $ins];
$truckSpecs = ['kieu_xe' => 'TAI', 'tai_trong_kg' => 2490, 'gvw_kg' => 4990, 'loai_thung' => 'KIN', 'thung_dai_mm' => 4300, 'thung_rong_mm' => 1800, 'thung_cao_mm' => 1800, 'han_dang_kiem' => $ins, 'han_bao_hiem' => $ins];
$mkVeh = fn(string $plate, string $type, array $specs) => $fleet->createVehicle($f->approverA, $plate, $type, $f->wa, $specs);
$mkDrv = fn(string $name, string $class) => $fleet->createDriver($f->approverA, $name, $cccd(), '', $lic($class));
$row = fn(string $t, int $id) => $f->db->one("SELECT * FROM $t WHERE id = ?", [$id]);

section('Hạng bằng mô tô A1 / A và hạng bằng cần có của từng kiểu xe');
same([R::needFor(['kieu_xe' => 'MAY', 'dung_tich_cc' => 110]), R::needFor(['kieu_xe' => 'MAY', 'dung_tich_cc' => 175]), R::needFor(['kieu_xe' => 'MAY', 'dung_tich_cc' => 250]), R::needFor(['kieu_xe' => 'MAY'])], ['A1', 'A1', 'A', null], 'xe máy đến 175 cm³ cần A1, trên 175 cm³ cần A, chưa khai thì chưa biết');
same(R::needFor(['kieu_xe' => 'TAI', 'gvw_kg' => 4990]), 'C1', 'xe tải vẫn tính theo GVW');
truthy(R::covers('A1', 'A1') && R::covers('A', 'A1') && !R::covers('A1', 'A'), 'hạng A lái được xe cần A1, A1 không lái xe phân khối lớn');
truthy(!R::covers('B', 'A1') && !R::covers('C', 'A1') && !R::covers('CE', 'A1'), 'bằng ô tô (B, C, CE) không dùng để chạy xe máy');
truthy(!R::covers('A1', 'B') && !R::covers('A', 'C1'), 'bằng mô tô không lái được ô tô');
same([R::reach('A1'), R::reach('A')], ['xe máy đến 175 cm³', 'mọi xe máy'], 'mô tả xe lái được của bằng mô tô');
same([R::minAge('A1'), R::minAge('A')], [18, 18], 'tuổi tối thiểu bằng mô tô');

section('Thêm xe máy: kiểm tra đầu vào');
throws(fn() => $mkVeh('29B1-' . random_int(100, 999) . '.11', 'Xe lạ', ['kieu_xe' => 'XE_BAY'] + $motoSpecs), DomainError::class, 'kiểu xe lạ bị từ chối');
throws(fn() => $mkVeh('29B1-' . random_int(100, 999) . '.12', 'Xe máy nặng', ['tai_trong_kg' => 400] + $motoSpecs), DomainError::class, 'xe máy chở quá 300 kg bị từ chối');
throws(fn() => $mkVeh('29B1-' . random_int(100, 999) . '.13', 'Xe máy sai cc', ['dung_tich_cc' => 10] + $motoSpecs), DomainError::class, 'dung tích xi-lanh vô lý bị từ chối');
$xm = $mkVeh('29B1-' . random_int(100, 999) . '.14', 'Honda Wave 110', $motoSpecs);
$oto = $mkVeh('29C-' . random_int(100, 999) . '.15', 'Xe bán tải', $carSpecs);
$tai = $mkVeh('30H-' . random_int(100, 999) . '.16', 'Xe tải 2,5 tấn', $truckSpecs);
same([$row('xe', $xm)['kieu_xe'], (int)$row('xe', $xm)['dung_tich_cc'], $row('xe', $oto)['kieu_xe']], ['MAY', 110, 'OTO_NHO'], 'lưu kiểu xe và dung tích');
$a1 = $mkDrv('Shipper A1', 'A1');
$bDrv = $mkDrv('Tài xế hạng B', 'B');
$cDrv = $mkDrv('Tài xế hạng C', 'C');
$aDrv = $mkDrv('Tay lái hạng A', 'A');
$f->receive($f->wa, 'x', 300);
$itemX = $f->it['x']['item'];
$catalog->setItemLogistics($f->admin, $itemX, 5000, 300, 200, 200);   // 5 kg, 30 × 20 × 20 cm
$load = fn(int $qty) => $tr->loadOf([['mat_hang_id' => $itemX, 'so_luong' => $qty]]);

section('Xe máy giao đơn nhỏ: tải trọng, thể tích, bằng lái');
$okLoad = $tr->checkVehicle($xm, $load(4), $a1);   // 20 kg, 0,048 m³
same($okLoad['blocks'], [], 'đơn nhỏ 20 kg: xe máy + bằng A1 ⇒ không chặn');
truthy(array_filter($okLoad['warns'], fn($m) => str_contains($m, 'đăng kiểm')) === [], 'xe máy không bị đòi đăng kiểm định kỳ');
truthy(array_filter($tr->checkVehicle($xm, $load(12), $a1)['blocks'], fn($m) => str_contains($m, 'Quá tải')) !== [], '60 kg trên xe máy tải 50 kg ⇒ chặn quá tải');
truthy(array_filter($tr->checkVehicle($xm, $load(10), $a1)['blocks'], fn($m) => str_contains($m, 'Thiếu chỗ')) !== [], 'hàng cồng kềnh hơn thùng xe máy ⇒ chặn thiếu chỗ');
$e = null;
foreach ([[$bDrv, 'B'], [$cDrv, 'C']] as [$drv, $cls]) {
    $blocks = $tr->checkVehicle($xm, $load(4), $drv)['blocks'];
    truthy(array_filter($blocks, fn($m) => str_contains($m, 'cần Hạng A1')) !== [], "tài xế hạng $cls không chạy được xe máy (cần bằng mô tô A1)");
}
same($tr->checkVehicle($xm, $load(4), $aDrv)['blocks'], [], 'bằng A lái được xe máy dưới 175 cm³');
$big = $mkVeh('29B2-' . random_int(100, 999) . '.17', 'Xe tay ga 250', ['dung_tich_cc' => 250] + $motoSpecs);
truthy(array_filter($tr->checkVehicle($big, $load(4), $a1)['blocks'], fn($m) => str_contains($m, 'cần Hạng A')) !== [], 'xe 250 cm³ cần hạng A, bằng A1 không đủ');
same($tr->checkVehicle($big, $load(4), $aDrv)['blocks'], [], 'tài xế hạng A chạy được xe 250 cm³');

section('Xe máy không chạy điều chuyển giữa các kho');
$out = (int)$ts->createDraft($f->keeperA, $f->wa, $f->wb, [$f->line('x', 4)], 'đơn nhỏ', null)['voucher']['id'];
$e = null;
try {
    $ts->dispatch($f->approverA, $out, $xm, true, ['hinh_thuc' => 'NOI_BO', 'km' => 100, 'tien' => 100000, 'ghi_chu' => ''], true, $a1);
} catch (DomainError $ex) {
    $e = $ex->getMessage();
}
truthy($e !== null && str_contains($e, 'Xe máy chỉ dùng giao hàng'), 'xuất điều chuyển bằng xe máy bị chặn: ' . $e);
same($f->db->value('SELECT trang_thai FROM phieu_nhap_xuat WHERE id = ?', [$out]), 'DRAFT', 'phiếu còn nguyên');

section('Khuyên dùng: hàng nhẹ thì xe nhỏ nhất đủ chở');
$vehicles = $fleet->availableVehicles($f->wa);
$ids = fn(array $vs) => array_map(fn($x) => (int)$x['id'], $vs);
$fitSmall = $tr->fitMap($vehicles, $load(4));
same($tr->recommend($vehicles, $fitSmall), $xm, 'đơn 20 kg giao khách: khuyên dùng xe máy');
$fitXfer = $tr->fitMap($vehicles, $load(4), true);
truthy(array_filter($fitXfer[$xm]['hard'], fn($m) => str_contains($m, 'Xe máy chỉ giao hàng cho khách')) !== [], 'điều chuyển: xe máy bị loại khỏi danh sách chọn');
$recXfer = $tr->recommend($vehicles, $fitXfer, true);
same($recXfer, $oto, 'điều chuyển hàng nhẹ: khuyên dùng ô tô nhỏ (không phải xe máy, không phải xe tải)');
$fitMid = $tr->fitMap($vehicles, $load(30));   // 150 kg: xe máy không đủ
same($tr->recommend($vehicles, $fitMid), $oto, 'đơn 150 kg: xe máy không đủ ⇒ khuyên dùng ô tô nhỏ');
$fitHeavy = $tr->fitMap($vehicles, $load(300));   // 1.500 kg: chỉ xe tải đủ
same($tr->recommend($vehicles, $fitHeavy), null, 'hàng nặng chỉ có một xe đủ chở thì không cần khuyên');
truthy(in_array($xm, $ids($vehicles), true), 'danh sách xe sẵn sàng của kho có cả xe máy');

section('Giao hàng cho khách bằng xe máy');
$pricing = $f->c->get(\StockFlow\Service\PricingService::class);
$pricing->setCost($f->admin, $itemX, 5000, 10, 'giá gốc test');
$pricing->setSellPrice($f->approverA, $f->wa, $itemX, 10000, 'giá bán test');
$svc = $f->c->get(VoucherService::class);
$del = $f->c->get(DeliveryService::class);
$base = ['thanh_toan' => 'COD', 'hinh_thuc' => 'NOI_BO', 'dia_chi' => '12 Nguyễn Trãi, Thanh Xuân, Hà Nội', 'nguoi_nhan' => 'Nguyễn Thị Hoa', 'sdt_nhan' => '0912000111',
         'km' => 8, 'tien' => 40000, 'ghi_chu' => '', 'driver_ok' => true];
$i1 = (int)$f->draftIssue($f->wa, 'x', 4)['id'];
throws(fn() => $svc->confirm($f->approverA, $i1, ['vehicle_id' => $xm, 'driver_id' => $bDrv] + $base), DomainError::class, 'tài xế chỉ có bằng B giao bằng xe máy bị chặn');
$svc->confirm($f->approverA, $i1, ['vehicle_id' => $xm, 'driver_id' => $a1] + $base);
$g = $del->of($i1);
same([$g['bien_so'], $g['tai_xe_ten'], $g['kieu_xe'], (int)$g['dung_tich_cc']], [$row('xe', $xm)['bien_so'], 'Shipper A1', 'MAY', 110], 'đơn giao ghi đúng xe máy và tài xế A1 (kèm kiểu xe để vẽ bản đồ)');
$i2 = (int)$f->draftIssue($f->wa, 'x', 12)['id'];
throws(fn() => $svc->confirm($f->approverA, $i2, ['vehicle_id' => $xm, 'driver_id' => $aDrv] + $base), DomainError::class, 'đơn 60 kg không giao bằng xe máy (quá tải 50 kg) được');

finish();
