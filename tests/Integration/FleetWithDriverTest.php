<?php
declare(strict_types=1);
// Thêm / sửa xe KÈM tài xế trong một form: một giao dịch, đổi người lái thì giữ hồ sơ người cũ cho các chuyến đã chạy.
require __DIR__ . '/../Support/bootstrap.php';

use StockFlow\Domain\DomainError;
use StockFlow\Domain\Forbidden;
use StockFlow\Service\FleetService;
use StockFlow\Service\TransferService;

$f = Fx::make();
$fleet = $f->c->get(FleetService::class);
$ts = $f->c->get(TransferService::class);
$cccd = fn() => (string)random_int(100000000000, 999999999999);
$plate = fn() => '30H-' . random_int(10000, 99999);
$count = fn(string $sql, array $a = []) => (int)$f->db->value($sql, $a);
$driverOf = fn(int $xe) => $f->db->one('SELECT t.* FROM tai_xe t JOIN xe x ON x.tai_xe_id = t.id WHERE x.id = ?', [$xe]);

section('Thêm xe kèm tài xế');
$c1 = $cccd();
throws(fn() => $fleet->addVehicleWithDriver($f->admin, $plate(), 'Xe tải', $f->wa, 'Admin thử', $cccd(), ''), Forbidden::class, 'admin chỉ xem, không thêm xe');
$r1 = $fleet->addVehicleWithDriver($f->approverA, $plate(), 'Xe tải', $f->wa, '  Nguyễn  Văn   Lái ', $c1, '0912345678');
$d = $driverOf($r1['vehicle_id']);
same([$d['ho_ten'], $d['cccd'], $d['so_dien_thoai']], ['Nguyễn Văn Lái', $c1, '0912345678'], 'tài xế được tạo cùng xe và gắn đúng xe (họ tên được chuẩn hoá)');
same((int)$f->db->value('SELECT kho_hien_tai_id FROM xe WHERE id = ?', [$r1['vehicle_id']]), $f->wa, 'xe đứng ở kho gốc');
throws(fn() => $fleet->addVehicleWithDriver($f->keeperA, $plate(), '', $f->wa, 'X', $cccd(), ''), Forbidden::class, 'nhân viên không thêm được xe');

section('Lỗi ở phần xe hoặc tài xế thì không tạo gì (nguyên khối)');
$xeBefore = $count('SELECT COUNT(*) FROM xe');
$drvBefore = $count('SELECT COUNT(*) FROM tai_xe');
$samePlate = (string)$f->db->value('SELECT bien_so FROM xe WHERE id = ?', [$r1['vehicle_id']]);
throws(fn() => $fleet->addVehicleWithDriver($f->approverB, $samePlate, '', $f->wb, 'Người mới', $cccd(), ''), DomainError::class, 'trùng biển số bị từ chối');
throws(fn() => $fleet->addVehicleWithDriver($f->approverB, $plate(), '', $f->wb, 'Sai CCCD', '12345', ''), DomainError::class, 'CCCD sai định dạng bị từ chối');
throws(fn() => $fleet->addVehicleWithDriver($f->approverB, 'abc', '', $f->wb, 'Biển sai', $cccd(), ''), DomainError::class, 'biển số sai bị từ chối');
$r2 = $fleet->addVehicleWithDriver($f->approverA, $plate(), '', $f->wa, 'Tài xế hai', $cccd(), '');
$xeBefore++;
$drvBefore++;
$fleet->addVehicleWithDriver($f->approverA, $plate(), '', $f->wa, 'Tài xế ba', $cccd(), '');   // xe thứ ba của kho A: không giới hạn
$xeBefore++;
$drvBefore++;
truthy((int)$f->db->value('SELECT COUNT(*) FROM xe WHERE kho_goc_id = ?', [$f->wa]) >= 3, 'kho A có từ 3 xe trở lên');
same([$count('SELECT COUNT(*) FROM xe'), $count('SELECT COUNT(*) FROM tai_xe')], [$xeBefore, $drvBefore], 'các lần lỗi không để lại xe hay tài xế nào');

section('CCCD đã có');
throws(fn() => $fleet->addVehicleWithDriver($f->approverB, $plate(), '', $f->wb, 'Giả mạo', $c1, ''), DomainError::class, 'CCCD của tài xế đang phụ trách xe khác bị từ chối');

section('Sửa xe kèm tài xế');
$fleet->updateVehicleWithDriver($f->approverA, $r1['vehicle_id'], $samePlate, 'Xe tải 3 tấn', $f->wa, 'Nguyễn Văn Lái Mới', $c1, '0987654321');
$d2 = $driverOf($r1['vehicle_id']);
same([(int)$d2['id'], $d2['ho_ten'], $d2['so_dien_thoai']], [(int)$d['id'], 'Nguyễn Văn Lái Mới', '0987654321'], 'cùng CCCD ⇒ sửa họ tên / số điện thoại tại chỗ (cùng hồ sơ)');
same($f->db->value('SELECT loai_xe FROM xe WHERE id = ?', [$r1['vehicle_id']]), 'Xe tải 3 tấn', 'loại xe cũng được lưu');

$c2 = $cccd();
$fleet->updateVehicleWithDriver($f->approverA, $r1['vehicle_id'], $samePlate, 'Xe tải 3 tấn', $f->wa, 'Người Thay Thế', $c2, '');
$d3 = $driverOf($r1['vehicle_id']);
truthy((int)$d3['id'] !== (int)$d['id'] && $d3['cccd'] === $c2, 'CCCD khác ⇒ người khác lên thay: tài xế mới gắn với xe');
same($count('SELECT COUNT(*) FROM tai_xe WHERE id = ?', [$d['id']]), 0, 'tài xế cũ chưa từng chạy chuyến nào ⇒ hồ sơ được xoá, không để rác');

section('Người cũ đã có chuyến: giữ hồ sơ, chỉ ngừng dùng');
$f->receive($f->wa, 'x', 100);
$f->receive($f->wb, 'x', 10);
$t = $ts->createDraft($f->keeperA, $f->wa, $f->wb, [$f->line('x', 5)], 'chuyến thử', null)['voucher'];
$ts->dispatch($f->approverA, (int)$t['id'], $r1['vehicle_id'], true);
$oldId = (int)$d3['id'];
$c3 = $cccd();
throws(fn() => $fleet->updateVehicleWithDriver($f->approverA, $r1['vehicle_id'], $samePlate, 'Xe tải 3 tấn', $f->wa, 'Người Đổi Ca', $c3, ''), DomainError::class, 'xe đang chạy trên đường thì chưa đổi được tài xế');
same((int)$driverOf($r1['vehicle_id'])['id'], $oldId, 'bị từ chối thì tài xế cũ vẫn phụ trách xe (không tạo người mới)');
$in = $f->c->get(\StockFlow\Service\VoucherRepository::class)->pairOf($f->c->get(\StockFlow\Service\VoucherRepository::class)->find((int)$t['id']));
$ts->receiveChecked($f->approverB, (int)$in['id'], null, true);   // kho đến nhận đủ ⇒ chuyến xong, xe đứng ở kho đến
$fleet->updateVehicleWithDriver($f->approverA, $r1['vehicle_id'], $samePlate, 'Xe tải 3 tấn', $f->wa, 'Người Đổi Ca', $c3, '');
same($count('SELECT COUNT(*) FROM tai_xe WHERE id = ?', [$oldId]), 1, 'hồ sơ người cũ còn nguyên vì đã chạy chuyến');
same($count('SELECT hoat_dong FROM tai_xe WHERE id = ?', [$oldId]), 0, 'người cũ chuyển sang «Ngừng dùng»');
same($f->db->value('SELECT t.ho_ten FROM chuyen_van_chuyen c JOIN tai_xe t ON t.id = c.tai_xe_id WHERE c.xe_id = ?', [$r1['vehicle_id']]), 'Người Thay Thế', 'chuyến cũ vẫn ghi đúng người đã chạy');
same($driverOf($r1['vehicle_id'])['cccd'], $c3, 'xe hiện do người mới phụ trách');

section('Dùng lại tài xế đã ngừng / tài xế rảnh');
throws(fn() => $fleet->updateVehicleWithDriver($f->approverA, $r1['vehicle_id'], $samePlate, '', $f->wa, 'Người Thay Thế', $c2, ''), DomainError::class, 'CCCD của tài xế đã ngừng dùng bị từ chối');
$spare = $fleet->addDriver($f->approverA, 'Tài xế dự bị', $c4 = $cccd(), '');
$fleet->updateVehicleWithDriver($f->approverA, $r2['vehicle_id'], (string)$f->db->value('SELECT bien_so FROM xe WHERE id = ?', [$r2['vehicle_id']]), '', $f->wa, 'Tài xế dự bị', $c4, '');
same((int)$driverOf($r2['vehicle_id'])['id'], $spare, 'CCCD của tài xế rảnh ⇒ dùng lại hồ sơ đó, không tạo trùng');

finish();
