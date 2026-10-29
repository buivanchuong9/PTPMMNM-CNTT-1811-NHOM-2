<?php
declare(strict_types=1);
// Nhiều xe cùng chở một lô hàng điều chuyển (đợt), thu hồi từng xe, xe hỏng giữa đường → hàng tới muộn.
require __DIR__ . '/../Support/bootstrap.php';

use StockFlow\Domain\DomainError;
use StockFlow\Domain\Forbidden;
use StockFlow\Service\FleetService;
use StockFlow\Service\TransferService;
use StockFlow\Service\VoucherRepository;

$f = Fx::make();
$fleet = $f->c->get(FleetService::class);
$ts = $f->c->get(TransferService::class);
$repo = $f->c->get(VoucherRepository::class);
$cccd = fn() => (string)random_int(100000000000, 999999999999);
$tripOf = fn(int $outId) => $f->db->one('SELECT * FROM chuyen_van_chuyen WHERE phieu_di_id = ?', [$outId]);

$mkVehicle = function (string $name) use ($f, $fleet, $cccd) {
    $d = $fleet->addDriver($f->approverA, $name, $cccd(), '');
    return $fleet->addVehicle($f->approverA, '30H-' . random_int(100, 999) . '.' . random_int(10, 99), 'Xe tải', $f->wa, $d);
};
$xe1 = $mkVehicle('Tài xế một');
$xe2 = $mkVehicle('Tài xế hai');
$xe3 = $mkVehicle('Tài xế ba');
$pay = ['hinh_thuc' => 'NOI_BO', 'km' => 100, 'tien' => 800000, 'ghi_chu' => ''];

section('Chia hàng cho nhiều xe: mỗi xe một phiếu đi + phiếu đến, cùng một đợt');
$f->receive($f->wa, 'x', 100);
$f->receive($f->wa, 'y', 50);
$t = $ts->createDraft($f->keeperA, $f->wa, $f->wb, [$f->line('x', 60), $f->line('y', 20)], 'đợt hai xe', null)['voucher'];
$tid = (int)$t['id'];
$lines = $repo->lines($tid);
$lx = (int)$lines[0]['id'];
$ly = (int)$lines[1]['id'];
throws(fn() => $ts->dispatchConvoy($f->keeperA, $tid, $xe1, [$xe2 => [$lx => 10]], true, $pay), Forbidden::class, 'nhân viên không có quyền xuất kho đi');
throws(fn() => $ts->dispatchConvoy($f->approverA, $tid, $xe1, [$xe2 => [$lx => 10]], false, $pay), DomainError::class, 'tài xế chưa xác nhận đủ hàng bị từ chối');
throws(fn() => $ts->dispatchConvoy($f->approverA, $tid, $xe1, [$xe2 => [$lx => 61]], true, $pay), DomainError::class, 'các xe chở quá số lượng trên phiếu bị từ chối');
throws(fn() => $ts->dispatchConvoy($f->approverA, $tid, $xe1, [$xe1 => [$lx => 5]], true, $pay), DomainError::class, 'xe chính không được chọn làm xe phụ');
throws(fn() => $ts->dispatchConvoy($f->approverA, $tid, $xe1, [$xe2 => [$lx => 60, $ly => 20]], true, $pay), DomainError::class, 'xe chính không còn hàng nào thì bị từ chối');
throws(fn() => $ts->dispatchConvoy($f->approverA, $tid, $xe1, [$xe2 => [$lx => 10]], true, ['hinh_thuc' => 'THUE_NGOAI'] + $pay), DomainError::class, 'thuê ngoài không chia được nhiều xe');
same([$repo->find($tid)['trang_thai'], $f->stock($f->wa, 'x')], ['DRAFT', 100], 'các lần bị từ chối không đổi phiếu, không trừ tồn');

$res = $ts->dispatchConvoy($f->approverA, $tid, $xe1, [$xe2 => [$lx => 25, $ly => 5]], true, $pay);
same(count($res['split']), 2, 'tách thành 2 phiếu đi, mỗi xe một phiếu');
same($repo->find($tid)['trang_thai'], 'CANCELLED', 'phiếu nháp gốc đã bị huỷ (kèm ghi chú tách)');
[$o1, $o2] = $res['split'];
same([$f->stock($f->wa, 'x'), $f->stock($f->wa, 'y')], [40, 30], 'tồn kho đi trừ đúng tổng 60 + 20');
$qty = fn(int $id) => array_map(fn($l) => (int)$l['so_luong'], $repo->lines($id));
same([$qty($o1), $qty($o2)], [[35, 15], [25, 5]], 'xe chính chở phần còn lại, xe phụ chở đúng số đã chia');
same([(int)$tripOf($o1)['xe_id'], (int)$tripOf($o2)['xe_id']], [$xe1, $xe2], 'mỗi chuyến đúng xe');
same((int)$tripOf($o2)['tien_tra'], 100 * (int)$fleet->rates($f->wa)['noi_bo'], 'xe phụ trả công theo km × đơn giá gợi ý');
same(count($fleet->convoyOf($o1)), 2, 'phiếu của xe chính thấy đủ đợt 2 xe');
same(array_column($fleet->convoyOf($o2), 'bien_so'), array_column($fleet->convoyOf($o1), 'bien_so'), 'phiếu xe phụ thấy cùng đợt');
same($fleet->convoyOf($tid), [], 'phiếu nháp gốc đã tách không thuộc đợt nào');

section('Nhận hàng riêng từng xe');
$in1 = (int)$repo->pairOf($repo->find($o1))['id'];
$in2 = (int)$repo->pairOf($repo->find($o2))['id'];
$ts->receiveChecked($f->approverB, $in2, null, true);
same([$tripOf($o2)['trang_thai'], $tripOf($o1)['trang_thai']], ['DELIVERED', 'IN_TRANSIT'], 'xe 2 tới trước: chỉ chuyến xe 2 hoàn tất');
same([$f->stock($f->wb, 'x'), $f->stock($f->wb, 'y')], [25, 5], 'kho đến cộng đúng phần xe 2 chở');
same($repo->find($in1)['trang_thai'], 'DRAFT', 'phiếu đến của xe 1 vẫn chờ');

section('Xe hỏng giữa đường: hàng tới muộn');
throws(fn() => $ts->reportBreakdown($f->approverB, $o1, 'Hỏng động cơ'), Forbidden::class, 'kho đến không báo xe hỏng thay kho đi');
throws(fn() => $ts->reportBreakdown($f->approverA, $o1, 'lý do tự bịa'), DomainError::class, 'lý do ngoài danh sách bị từ chối');
throws(fn() => $ts->reportBreakdown($f->approverA, $o1, 'Khác'), DomainError::class, 'lý do «Khác» phải ghi rõ');
throws(fn() => $ts->reportBreakdown($f->approverA, $o2, 'Hỏng động cơ'), DomainError::class, 'chuyến đã giao xong không báo hỏng được');
throws(fn() => $ts->resumeTrip($f->approverA, $o1), DomainError::class, 'xe chưa hỏng thì không «chạy tiếp»');
$ts->reportBreakdown($f->approverA, $o1, 'Thủng lốp / hỏng bánh', 'giữa đèo');
$b = $fleet->breakdownOf((int)$tripOf($o1)['id']);
truthy($b && str_contains($b['ly_do'], 'Thủng lốp') && str_contains($b['ly_do'], 'giữa đèo') && $b['tien_do'] >= 25 && $b['tien_do'] <= 75, 'sự cố được ghi: lý do + vị trí xe dừng');
same(array_column(array_filter($fleet->convoyOf($o1), fn($r) => $r['hong_ly_do'] !== null), 'bien_so') !== [], true, 'danh sách đợt đánh dấu xe hỏng');
throws(fn() => $ts->reportBreakdown($f->approverA, $o1, 'Hỏng động cơ'), DomainError::class, 'không báo hỏng hai lần');
throws(fn() => $ts->receiveChecked($f->approverB, $in1, null, true), DomainError::class, 'xe đang hỏng thì kho đến chưa nhận được');
same([$repo->find($in1)['trang_thai'], $f->stock($f->wb, 'x')], ['DRAFT', 25], 'từ chối nhận không đổi tồn kho đến');
throws(fn() => $ts->resumeTrip($f->approverB, $o1), Forbidden::class, 'kho đến không xác nhận xe chạy tiếp');
$ts->resumeTrip($f->approverA, $o1);
same($fleet->breakdownOf((int)$tripOf($o1)['id']), null, 'xe sửa xong, chạy tiếp');
$ts->receiveChecked($f->approverB, $in1, null, true);
same([$tripOf($o1)['trang_thai'], $f->stock($f->wb, 'x'), $f->stock($f->wb, 'y')], ['DELIVERED', 60, 20], 'hàng tới muộn vẫn nhận đủ, tồn kho đến đủ cả đợt');

section('Thu hồi một xe, xe còn lại tiếp tục');
foreach ([$xe1, $xe2] as $x) {
    $fleet->relocateVehicle($f->approverA, $x, $f->wa);   // hai xe vừa giao hàng ở kho B, điều về kho A cho đợt sau
}
$f->receive($f->wa, 'x', 100);
$t = $ts->createDraft($f->keeperA, $f->wa, $f->wb, [$f->line('x', 30)], 'đợt hai', null)['voucher'];
$l = (int)$repo->lines((int)$t['id'])[0]['id'];
$r2 = $ts->dispatchConvoy($f->approverA, (int)$t['id'], $xe1, [$xe2 => [$l => 10], $xe3 => [$l => 8]], true, $pay);
same(count($r2['split']), 3, 'ba xe nối đuôi trong một đợt');
[$a1, $a2, $a3] = $r2['split'];
same(array_map(fn($id) => $qty($id)[0], $r2['split']), [12, 10, 8], 'chia 12 + 10 + 8 = 30');
$before = $f->stock($f->wa, 'x');
$ts->reportBreakdown($f->approverA, $a3, 'Hỏng động cơ');
$ts->recall($f->approverA, $a3, 'Xe gặp sự cố');
same($tripOf($a3)['trang_thai'], 'RECALLED', 'thu hồi đúng xe bị hỏng');
same($f->stock($f->wa, 'x'), $before + 8, 'hàng của xe đó cộng lại kho đi, các xe khác không đổi');
same($fleet->breakdownOf((int)$tripOf($a3)['id']), null, 'thu hồi đóng luôn sự cố đang mở');
same([$tripOf($a1)['trang_thai'], $tripOf($a2)['trang_thai']], ['IN_TRANSIT', 'IN_TRANSIT'], 'hai xe còn lại vẫn đang chạy');
$ts->receiveChecked($f->approverB, (int)$repo->pairOf($repo->find($a1))['id'], null, true);
$ts->receiveChecked($f->approverB, (int)$repo->pairOf($repo->find($a2))['id'], null, true);
same($f->stock($f->wb, 'x'), 60 + 12 + 10, 'kho đến nhận đúng phần của hai xe tới nơi');

finish();
