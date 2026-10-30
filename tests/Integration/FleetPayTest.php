<?php
declare(strict_types=1);
// Tiền công vận chuyển theo km: đơn giá/km do kho đi đặt, quãng đường giữa hai kho, tiền gợi ý = km × đơn giá (nhập số khác được),
// lương tài xế theo chuyến đã giao, và thuê đơn vị vận tải ngoài (bắt buộc đủ thông tin).
require __DIR__ . '/../Support/bootstrap.php';

use StockFlow\Domain\DomainError;
use StockFlow\Domain\Forbidden;
use StockFlow\Service\FleetService;
use StockFlow\Service\TransferService;

$f = Fx::make();
$fleet = $f->c->get(FleetService::class);
$ts = $f->c->get(TransferService::class);
$cccd = fn() => (string)random_int(100000000000, 999999999999);
$plate = fn() => '29C-' . random_int(10000, 99999);
$arrive = function (int $outId) use ($f, $ts) {   // kho đến nhận hàng (chuyến → DELIVERED)
    $repo = $f->c->get(\StockFlow\Service\VoucherRepository::class);
    $in = $repo->pairOf($repo->find($outId));
    $ts->receiveChecked($f->approverB, (int)$in['id'], null, true);
};
$tripOf = fn(int $outId) => $f->db->one('SELECT * FROM chuyen_van_chuyen WHERE phieu_di_id = ?', [$outId]);

section('Đơn giá mỗi km do kho đi đặt');
same($fleet->rates($f->wa), ['noi_bo' => 8000, 'thue_ngoai' => 15000], 'đơn giá mặc định');
throws(fn() => $fleet->setRates($f->approverB, $f->wa, 9000, 16000), Forbidden::class, 'quản lý kho khác không đặt được');
throws(fn() => $fleet->setRates($f->admin, $f->wa, 9000, 16000), Forbidden::class, 'quản trị chỉ xem');
throws(fn() => $fleet->setRates($f->approverA, $f->wa, -1, 16000), DomainError::class, 'đơn giá âm bị từ chối');
$fleet->setRates($f->approverA, $f->wa, 10000, 20000);
same($fleet->quote($f->wa, $f->wb, 'NOI_BO', 120), ['km' => 120, 'rate' => 10000, 'amount' => 1200000], 'gợi ý xe kho = km × đơn giá nội bộ');
same($fleet->quote($f->wa, $f->wb, 'THUE_NGOAI', 120)['amount'], 2400000, 'gợi ý thuê ngoài = km × đơn giá thuê ngoài');

section('Quãng đường giữa hai kho (dùng chung hai chiều)');
same($fleet->distance($f->wa, $f->wb), null, 'chưa khai báo');
throws(fn() => $fleet->setDistance($f->approverA, $f->wa, $f->wb, 0), DomainError::class, 'km phải ≥ 1');
throws(fn() => $fleet->setDistance($f->approverA, $f->wb, $f->wa, 100), Forbidden::class, 'không khai báo thay kho khác');
$fleet->setDistance($f->approverA, $f->wa, $f->wb, 120);
same([$fleet->distance($f->wa, $f->wb), $fleet->distance($f->wb, $f->wa)], [120, 120], 'nhớ cả hai chiều');
$fleet->setDistance($f->approverB, $f->wb, $f->wa, 125);
same($fleet->distance($f->wa, $f->wb), 125, 'kho kia cập nhật lại được');
same($fleet->quote($f->wa, $f->wb, 'NOI_BO')['km'], 125, 'gợi ý lấy km đã khai báo');

section('Xuất kho điều chuyển bằng xe của kho: ghi km, đơn giá, tiền gợi ý và tiền thực trả');
$d1 = $fleet->addVehicleWithDriver($f->approverA, $plate(), 'Xe tải 2 tấn', $f->wa, 'Trần Văn Tài', $cccd(), '0912345678');
$xe1 = $d1['vehicle_id'];
$f->receive($f->wa, 'x', 100);
$send = fn(int $qty = 10) => (int)$ts->createDraft($f->keeperA, $f->wa, $f->wb, [$f->line('x', $qty)], 't', null)['voucher']['id'];
$pay = ['hinh_thuc' => 'NOI_BO', 'km' => 125, 'tien' => 1200000, 'ghi_chu' => 'Đường xấu'];
$t1 = $send();
throws(fn() => $ts->dispatch($f->approverA, $t1, $xe1, true, ['km' => 0] + $pay), DomainError::class, 'thiếu quãng đường bị từ chối');
throws(fn() => $ts->dispatch($f->approverA, $t1, $xe1, true, ['tien' => -1] + $pay), DomainError::class, 'số tiền không hợp lệ');
$ts->dispatch($f->approverA, $t1, $xe1, true, $pay);
$trip = $tripOf($t1);
same([$trip['hinh_thuc'], (int)$trip['km'], (int)$trip['don_gia_km'], (int)$trip['tien_goi_y'], (int)$trip['tien_tra']], ['NOI_BO', 125, 10000, 1250000, 1200000], 'km, đơn giá, gợi ý 1.250.000, thực trả 1.200.000 (khác gợi ý)');
$arrive($t1);
same($tripOf($t1)['trang_thai'], 'DELIVERED', 'đã giao');

section('Lương tài xế theo chuyến đã giao');
$mine = array_values(array_filter($fleet->drivers($f->wa), fn($d) => (int)$d['id'] === $d1['driver_id']))[0];
same([(int)$mine['so_chuyen'], (int)$mine['tong_km'], (int)$mine['tien_chuyen']], [1, 125, 1200000], '1 chuyến · 125 km · 1.200.000 đ');
$fleet->relocateVehicle($f->approverA, $xe1, $f->wa);
$t2 = $send(5);
$ts->dispatch($f->approverA, $t2, $xe1, true, $pay);   // đang chạy: chưa tính vào lương
$mine = array_values(array_filter($fleet->drivers($f->wa), fn($d) => (int)$d['id'] === $d1['driver_id']))[0];
same((int)$mine['tien_chuyen'], 1200000, 'chuyến chưa giao chưa tính');

section('Thuê đơn vị vận tải ngoài: phải có đủ thông tin');
$ext = ['company' => 'Công ty Vận tải Nhanh', 'driver' => 'Lê Văn Thuê', 'cccd' => $cccd(), 'phone' => '0987654321', 'plate' => $plate(), 'type' => 'Xe tải 5 tấn', 'coc_ok' => '1', 'coc_pt' => 'TIEN_MAT'];
$payExt = ['hinh_thuc' => 'THUE_NGOAI', 'km' => 125, 'tien' => 2500000, 'ghi_chu' => '', 'ngoai' => $ext];
$t3 = $send(3);
foreach (['company' => 'đơn vị', 'driver' => 'họ tên tài xế', 'cccd' => 'CCCD', 'phone' => 'điện thoại', 'plate' => 'biển số', 'type' => 'loại xe'] as $k => $label) {
    throws(fn() => $ts->dispatch($f->approverA, $t3, 0, true, ['ngoai' => [$k => ''] + $ext] + $payExt), DomainError::class, "thiếu $label bị từ chối");
}
throws(fn() => $ts->dispatch($f->approverA, $t3, 0, true, ['ngoai' => ['cccd' => '123'] + $ext] + $payExt), DomainError::class, 'CCCD sai định dạng');
throws(fn() => $ts->dispatch($f->approverA, $t3, 0, true, ['tien' => 0] + $payExt), DomainError::class, 'thuê ngoài phải có số tiền thuê');
throws(fn() => $ts->dispatch($f->approverA, $t3, 0, true, ['ngoai' => ['coc_ok' => ''] + $ext] + $payExt), DomainError::class, 'chưa thu cọc hàng thì không được bàn giao');
throws(fn() => $ts->dispatch($f->approverA, $t3, 0, false, $payExt), DomainError::class, 'tài xế thuê ngoài cũng phải xác nhận đủ hàng');
same((int)$f->db->value("SELECT COUNT(*) FROM tai_xe WHERE cccd = ?", [$ext['cccd']]), 0, 'lỗi thì không lưu tài xế thuê ngoài');
$int = $f->db->one('SELECT cccd FROM tai_xe WHERE id = ?', [$d1['driver_id']]);
throws(fn() => $ts->dispatch($f->approverA, $t3, 0, true, ['ngoai' => ['cccd' => $int['cccd']] + $ext] + $payExt), DomainError::class, 'CCCD của tài xế nội bộ không dùng cho thuê ngoài');
$intPlate = $f->db->value('SELECT bien_so FROM xe WHERE id = ?', [$xe1]);
throws(fn() => $ts->dispatch($f->approverA, $t3, 0, true, ['ngoai' => ['plate' => $intPlate] + $ext] + $payExt), DomainError::class, 'biển số xe nội bộ không dùng cho thuê ngoài');
$ts->dispatch($f->approverA, $t3, 0, true, $payExt);
$tr = $tripOf($t3);
same([$tr['hinh_thuc'], $tr['don_vi_van_tai'], (int)$tr['tien_tra'], (int)$tr['tien_goi_y'], (int)$tr['don_gia_km']], ['THUE_NGOAI', 'Công ty Vận tải Nhanh', 2500000, 2500000, 20000], 'chuyến thuê ngoài: đơn vị, tiền, gợi ý = 125 × 20.000');
same((int)$f->db->value('SELECT COUNT(*) FROM tai_xe WHERE cccd = ? AND thue_ngoai = 1', [$ext['cccd']]), 1, 'hồ sơ tài xế thuê ngoài được lưu');
same((int)$f->db->value('SELECT thue_ngoai FROM xe WHERE bien_so = ?', [$ext['plate']]), 1, 'xe thuê ngoài được lưu và đánh dấu');
same(count(array_filter($fleet->vehicles($f->wa), fn($x) => $x['bien_so'] === $ext['plate'])), 0, 'xe thuê ngoài không hiện trong đội xe của kho');
same(count(array_filter($fleet->drivers($f->wa), fn($d) => $d['cccd'] === $ext['cccd'])), 0, 'tài xế thuê ngoài không hiện trong danh sách tài xế');
$arrive($t3);
$mine = array_values(array_filter($fleet->drivers($f->wa), fn($d) => (int)$d['id'] === $d1['driver_id']))[0];
same((int)$mine['tien_chuyen'], 1200000, 'chuyến thuê ngoài không tính vào lương tài xế nội bộ');
same(count($fleet->availableVehicles($f->wb)), 0, 'xe thuê ngoài đến kho B cũng không thành xe sẵn sàng của kho B');

section('Chưa có quãng đường giữa hai kho: km nhập khi lập chuyến được ghi nhớ');
$f->db->exec('DELETE FROM khoang_cach_kho');
$t4 = $send(2);
$ts->dispatch($f->approverA, $t4, 0, true, ['ngoai' => ['plate' => $plate(), 'cccd' => $cccd()] + $ext] + ['km' => 99] + $payExt);
same($fleet->distance($f->wa, $f->wb), 99, 'km nhập tay thành quãng đường chung');

finish();
