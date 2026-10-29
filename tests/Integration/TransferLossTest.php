<?php
declare(strict_types=1);
// Xác nhận mất hàng: admin đóng chuyến «chưa thấy hàng», ghi người chịu + lý do; tài xế chịu thì bị trừ điểm và lương;
// phiếu đến bị huỷ, tồn kho đến không đổi, Đối chiếu tồn vẫn khớp.
require __DIR__ . '/../Support/bootstrap.php';

use StockFlow\Domain\DomainError;
use StockFlow\Domain\Forbidden;
use StockFlow\Service\FleetService;
use StockFlow\Service\StockQuery;
use StockFlow\Service\TransferService;
use StockFlow\Service\VoucherRepository;

$f = Fx::make();
$fleet = $f->c->get(FleetService::class);
$ts = $f->c->get(TransferService::class);
$repo = $f->c->get(VoucherRepository::class);
$query = $f->c->get(StockQuery::class);
$cccd = fn() => (string)random_int(100000000000, 999999999999);
$driver = $fleet->addDriver($f->approverA, 'Tài xế mất hàng', $cccd(), '0912345678');
$xe = $fleet->addVehicle($f->approverA, '30H-' . random_int(10000, 99999), 'Xe tải', $f->wa, $driver);
$f->db->exec('UPDATE mat_hang SET gia_von = 1000 WHERE id = ?', [$f->it['x']['item']]);
$f->db->exec('UPDATE mat_hang SET gia_von = 2500 WHERE id = ?', [$f->it['y']['item']]);

$ship = function (int $qx, int $qy) use ($f, $ts, $repo, $xe, $fleet): array {
    $fleet->relocateVehicle($f->approverA, $xe, $f->wa);
    $f->receive($f->wa, 'x', $qx);
    $f->receive($f->wa, 'y', $qy);
    $out = $ts->createDraft($f->keeperA, $f->wa, $f->wb, [$f->line('x', $qx), $f->line('y', $qy)], 'chuyến thử', null)['voucher'];
    $ts->dispatch($f->approverA, (int)$out['id'], $xe, true);
    return [(int)$out['id'], (int)$repo->pairOf($repo->find((int)$out['id']))['id']];
};
$trip = fn(int $outId) => $f->db->one('SELECT * FROM chuyen_van_chuyen WHERE phieu_di_id = ?', [$outId]);
$score = fn() => (int)$f->db->value('SELECT diem FROM tai_xe WHERE id = ?', [$driver]);

section('Chuyến chưa được báo thì chưa xác nhận mất hàng được');
[$out1, $in1] = $ship(10, 4);   // giá trị = 10×1000 + 4×2500 = 20.000
throws(fn() => $fleet->confirmLoss($f->approverA, $in1, 'Mất', 'TAI_XE'), DomainError::class, 'kho đến chưa báo ⇒ từ chối');
$ts->reportNotReceived($f->approverB, $in1, 'Không liên lạc được với tài xế');
throws(fn() => $fleet->confirmLoss($f->approverB, $in1, 'Mất', 'TAI_XE'), Forbidden::class, 'quản lý kho không xác nhận mất hàng');
throws(fn() => $fleet->confirmLoss($f->admin, $in1, 'Mất', 'TAI_XE'), Forbidden::class, 'admin chỉ xem, không xác nhận mất hàng');
throws(fn() => $fleet->confirmLoss($f->approverA, $in1, '  ', 'TAI_XE'), DomainError::class, 'phải ghi lý do');
throws(fn() => $fleet->confirmLoss($f->approverA, $in1, 'Mất', 'AI_DO'), DomainError::class, 'người chịu ngoài danh sách bị từ chối');
throws(fn() => $fleet->confirmLoss($f->approverA, $in1, 'Mất', 'TAI_XE', 20001), DomainError::class, 'trừ lương quá giá trị hàng bị từ chối');
throws(fn() => $fleet->confirmLoss($f->approverA, $in1, 'Mất', 'TAI_XE', 100, 101), DomainError::class, 'trừ điểm quá 100 bị từ chối');
same([$trip($out1)['trang_thai'], $score()], ['IN_TRANSIT', 100], 'các lần bị từ chối không đổi gì');

section('Tài xế chịu: trừ lương mặc định đủ giá trị, trừ 10 điểm');
$bx = $f->stock($f->wb, 'x');
$fleet->updateDriverScore($f->approverA, $driver, 100);
same($fleet->tripGoodsValue($out1), 20000, 'giá trị hàng theo giá gốc');
$fleet->confirmLoss($f->approverA, $in1, 'Mất hàng, không liên lạc được 48 giờ', 'TAI_XE');
$t = $trip($out1);
same([$t['trang_thai'], $repo->find($in1)['trang_thai']], ['LOST', 'CANCELLED'], 'chuyến đóng LOST, phiếu đến bị huỷ');
same($f->stock($f->wb, 'x'), $bx, 'tồn kho đến không đổi');
same($score(), 90, 'tài xế bị trừ 10 điểm');
$loss = $fleet->lossRecords(5)[0];
same([$loss['nguoi_chiu'], (int)$loss['gia_tri'], (int)$loss['tien_tru_luong'], (int)$loss['diem_tru']], ['TAI_XE', 20000, 20000, 10], 'ghi nhận thất thoát: giá trị, tiền trừ lương, điểm trừ');
$d = array_values(array_filter($fleet->drivers(), fn($x) => (int)$x['id'] === $driver))[0];
same([(int)$d['tru_thang'], (int)$d['so_vu_mat']], [20000, 1], 'trang tài xế: đã trừ tháng này, số vụ mất');
same($f->db->one('SELECT kho_hien_tai_id, dang_den_kho_id FROM xe WHERE id = ?', [$xe]), ['kho_hien_tai_id' => $f->wa, 'dang_den_kho_id' => null], 'xe về kho gốc, sẵn sàng chạy tiếp');
same(count(array_filter($fleet->missingTrips(), fn($m) => (int)$m['phieu_den_id'] === $in1)), 0, 'chuyến rời mục «chưa thấy đâu»');
throws(fn() => $fleet->confirmLoss($f->approverA, $in1, 'Lại', 'TAI_XE'), DomainError::class, 'chuyến đã đóng không xác nhận lần hai');
throws(fn() => $ts->receiveChecked($f->approverB, $in1, null, true), DomainError::class, 'phiếu đã huỷ thì không nhận hàng được nữa');
same($query->reconcile([$f->wa, $f->wb])['mismatches'], 0, 'Đối chiếu tồn vẫn KHỚP');

section('Kho chịu / số tiền, điểm tuỳ chỉnh');
[$out2, $in2] = $ship(3, 2);   // 3.000 + 5.000 = 8.000
$ts->reportNotReceived($f->approverB, $in2, 'Xe đến nhưng không có hàng');
$fleet->confirmLoss($f->approverA, $in2, 'Kho chịu theo biên bản', 'CONG_TY', 5000, 50);
same([$score(), (int)$fleet->lossRecords(5)[0]['tien_tru_luong']], [90, 0], 'kho chịu ⇒ không trừ điểm, không trừ lương (bỏ qua số nhập)');
[$out3, $in3] = $ship(2, 1);   // 2.000 + 2.500 = 4.500
$ts->reportNotReceived($f->approverB, $in3, 'Tài xế chưa đến kho');
$fleet->confirmLoss($f->approverA, $in3, 'Mất một phần trách nhiệm', 'TAI_XE', 1500, 25);
same([$score(), (int)$fleet->lossRecords(5)[0]['tien_tru_luong']], [65, 1500], 'tài xế chịu số tiền và điểm admin nhập');
$d = array_values(array_filter($fleet->drivers(), fn($x) => (int)$x['id'] === $driver))[0];
same([(int)$d['tru_thang'], (int)$d['so_vu_mat']], [21500, 2], 'cộng dồn trừ lương tháng và số vụ (không tính vụ kho chịu)');

section('Sửa mức xử lý và lọc theo tháng');
$lid = (int)$fleet->lossRecords(5)[0]['id'];   // vụ 1.500 đ / 25 điểm (điểm hiện 65)
throws(fn() => $fleet->adjustLoss($f->admin, $lid, 'CONG_TY', null, null), Forbidden::class, 'admin chỉ xem, không sửa mức trừ');
throws(fn() => $fleet->adjustLoss($f->approverB, $lid, 'CONG_TY', null, null), Forbidden::class, 'quản lý kho khác không sửa mức trừ');
$fleet->adjustLoss($f->approverA, $lid, 'CONG_TY', null, null);
same($score(), 90, 'chuyển sang kho chịu ⇒ hoàn lại 25 điểm');
throws(fn() => $fleet->adjustLoss($f->approverA, $lid, 'TAI_XE', 99999, 5), DomainError::class, 'trừ lương quá giá trị hàng bị từ chối');
$fleet->adjustLoss($f->approverA, $lid, 'TAI_XE', 4500, 30);
same([$score(), (int)$fleet->lossRecords(5)[0]['tien_tru_luong']], [60, 4500], 'chuyển lại tài xế chịu mức mới: −30 điểm, trừ 4.500 đ');
$month = date('Y-m');
same(count($fleet->lossRecords(50, null, '2000-01')), 0, 'tháng khác không có thất thoát');
truthy(count($fleet->lossRecords(50, null, $month)) >= 3, 'tháng hiện tại có đủ các vụ');
$d = fn(string $m) => array_values(array_filter($fleet->drivers(null, $m), fn($x) => (int)$x['id'] === $driver))[0];
same([(int)$d('2000-01')['tru_thang'], (int)$d($month)['tru_thang']], [0, 20000 + 4500], 'lương trừ theo đúng tháng được chọn');
same(FleetService::monthRange('2026-12')[1], '2027-01-01 00:00:00', 'khoảng tháng 12 kết thúc đầu tháng 1 năm sau');

section('Sửa điểm (không còn lương cố định)');
throws(fn() => $fleet->updateDriverScore($f->approverB, $driver, 1), Forbidden::class, 'quản lý kho khác không sửa điểm tài xế của kho này');
throws(fn() => $fleet->updateDriverScore($f->admin, $driver, 1), Forbidden::class, 'admin chỉ xem, không sửa điểm tài xế');
throws(fn() => $fleet->updateDriverScore($f->approverA, $driver, -1), DomainError::class, 'điểm âm bị từ chối');
throws(fn() => $fleet->updateDriverScore($f->approverA, $driver, 101), DomainError::class, 'điểm quá 100 bị từ chối');
$fleet->updateDriverScore($f->approverA, $driver, 80);
same($score(), 80, 'khôi phục điểm');

section('Phạm vi kho: mỗi quản lý chỉ thấy đội xe của kho mình, admin xem tất cả');
$ids = fn(array $rows) => array_map('intval', array_column($rows, 'id'));
truthy(in_array($xe, $ids($fleet->vehicles($f->wa)), true) && !in_array($xe, $ids($fleet->vehicles($f->wb)), true), 'xe của kho A chỉ hiện ở danh sách kho A');
truthy(in_array($xe, $ids($fleet->vehicles()), true), 'admin (không lọc kho) thấy xe');
truthy(in_array($driver, $ids($fleet->drivers($f->wa)), true) && !in_array($driver, $ids($fleet->drivers($f->wb)), true), 'tài xế của kho A chỉ hiện ở kho A');
same(count($fleet->lossRecords(20, $f->wb)), 0, 'kho B không thấy thất thoát của xe kho A');
throws(fn() => $fleet->addVehicle($f->approverB, '51C-' . random_int(10000, 99999), '', $f->wa), Forbidden::class, 'quản lý kho B không thêm xe cho kho A');
throws(fn() => $fleet->relocateVehicle($f->approverB, $xe, $f->wb), Forbidden::class, 'quản lý kho B không điều xe của kho A');
throws(fn() => $fleet->setDriverActive($f->approverB, $driver, false), Forbidden::class, 'quản lý kho B không ngừng tài xế của kho A');

finish();
