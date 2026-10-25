<?php
declare(strict_types=1);
// Nhận hàng điều chuyển theo SỐ LƯỢNG THỰC TẾ: nhận đủ / nhận thiếu (phần thiếu ghi bằng phiếu điều chỉnh, đối chiếu vẫn khớp) /
// chưa nhận được hàng (không cộng tồn, báo cho admin) rồi hàng về sau.
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
$driver = $fleet->addDriver($f->approverA, 'Tài xế thử', $cccd(), '0912345678');
$xe = $fleet->addVehicle($f->approverA, '30H-' . random_int(10000, 99999), 'Xe tải', $f->wa, $driver);

/** Lập chuyến A→B: trả về [id DCX, id DCN, id dòng DCN của x, id dòng DCN của y]. */
$ship = function (int $qx, int $qy) use ($f, $ts, $repo, $xe, $fleet): array {
    $fleet->relocateVehicle($f->approverA, $xe, $f->wa);   // xe quay về kho đi sau chuyến trước
    $f->receive($f->wa, 'x', $qx);
    $f->receive($f->wa, 'y', $qy);
    $out = $ts->createDraft($f->keeperA, $f->wa, $f->wb, [$f->line('x', $qx), $f->line('y', $qy)], 'chuyến thử', null)['voucher'];
    $ts->dispatch($f->approverA, (int)$out['id'], $xe, true);
    $in = $repo->pairOf($repo->find((int)$out['id']));
    $lines = $repo->lines((int)$in['id']);
    $byItem = [];
    foreach ($lines as $l) { $byItem[(int)$l['mat_hang_id']] = (int)$l['id']; }
    return [(int)$out['id'], (int)$in['id'], $byItem[$f->it['x']['item']], $byItem[$f->it['y']['item']]];
};
$tripOf = fn(int $outId) => $f->db->one('SELECT * FROM chuyen_van_chuyen WHERE phieu_di_id = ?', [$outId]);

section('Nhận đủ theo số thực tế');
[$out1, $in1, $lx1, $ly1] = $ship(10, 6);
$r = $ts->receiveChecked($f->approverB, $in1, [$lx1 => 10, $ly1 => 6], true);
same([$f->stock($f->wb, 'x'), $f->stock($f->wb, 'y')], [10, 6], 'nhận đủ: tồn kho đến tăng đúng số trên phiếu');
same([$r['short'], $r['adjustment']], [[], null], 'không có phần thiếu, không có phiếu điều chỉnh');
same([$tripOf($out1)['trang_thai'], $tripOf($out1)['sai_lech']], ['DELIVERED', ''], 'chuyến hoàn tất, không ghi thiếu');
same($ts->receiveChecked($f->approverB, $in1, [$lx1 => 1, $ly1 => 1], true)['already'], true, 'gọi lại khi đã nhận rồi: không ghi thêm');
same([$f->stock($f->wb, 'x'), $f->stock($f->wb, 'y')], [10, 6], 'tồn không đổi sau lần gọi lại');

section('Nhận thiếu: tồn chỉ tăng đúng số thực nhận, phần thiếu có phiếu điều chỉnh');
[$out2, $in2, $lx2, $ly2] = $ship(20, 8);
$bx = $f->stock($f->wb, 'x');
$by = $f->stock($f->wb, 'y');
$ax = $f->stock($f->wa, 'x');
throws(fn() => $ts->receiveChecked($f->approverB, $in2, [$lx2 => 21, $ly2 => 8], true), DomainError::class, 'thực nhận vượt số trên phiếu bị từ chối');
throws(fn() => $ts->receiveChecked($f->approverB, $in2, [$lx2 => 20], true), DomainError::class, 'thiếu số thực nhận của một dòng bị từ chối');
throws(fn() => $ts->receiveChecked($f->approverB, $in2, [$lx2 => 0, $ly2 => 0], true), DomainError::class, 'nhận 0 ở mọi dòng ⇒ phải bấm «Chưa nhận được hàng»');
same([$f->stock($f->wb, 'x'), $repo->find($in2)['trang_thai']], [$bx, 'DRAFT'], 'các lần bị từ chối không đổi gì');
throws(fn() => $ts->receiveChecked($f->approverB, $in2, [$lx2 => 17, $ly2 => 8], true), DomainError::class, 'nhận thiếu mà không ghi lý do bị từ chối');
throws(fn() => $ts->receiveChecked($f->approverB, $in2, [$lx2 => 17, $ly2 => 8], true, 'Khác'), DomainError::class, 'lý do «Khác» phải ghi rõ');
throws(fn() => $ts->receiveChecked($f->approverB, $in2, [$lx2 => 17, $ly2 => 8], true, 'tự bịa'), DomainError::class, 'lý do ngoài danh sách bị từ chối');
$r = $ts->receiveChecked($f->approverB, $in2, [$lx2 => 17, $ly2 => 8], true, 'Hàng vỡ / hư hỏng trong lúc vận chuyển', '3 thùng vỡ');
same([$f->stock($f->wb, 'x') - $bx, $f->stock($f->wb, 'y') - $by], [17, 8], 'tồn kho đến tăng đúng số thực nhận (17 và 8)');
same($f->stock($f->wa, 'x'), $ax, 'kho đi không bị đụng tới');
same(count($r['short']) . '|' . $r['short'][0]['expected'] . '|' . $r['short'][0]['got'], '1|20|17', 'báo đúng dòng thiếu (17/20)');
same([$r['adjustment']['loai'], (int)$r['adjustment']['kho_id'], $r['adjustment']['trang_thai']], ['ADJUSTMENT', $f->wb, 'CONFIRMED'], 'phần thiếu ghi bằng phiếu điều chỉnh đã ghi sổ ở kho đến');
same((int)$f->db->value('SELECT so_luong FROM chi_tiet_phieu_nhap_xuat WHERE phieu_nhap_xuat_id = ?', [$r['adjustment']['id']]), -3, 'phiếu điều chỉnh trừ đúng phần thiếu (−3)');
$trip = $tripOf($out2);
same([$trip['trang_thai'], (int)$trip['nhan_du_hang'], str_contains($trip['sai_lech'], 'Nhận thiếu')], ['DELIVERED', 0, true], 'chuyến ghi «nhận thiếu» để admin theo dõi');
$loss = $fleet->lossRecords(5)[0];
same([$loss['loai'], $loss['nguoi_chiu'], (int)$loss['diem_tru']], ['NHAN_THIEU', 'TAI_XE', 5], 'thiếu do hàng vỡ ⇒ tài xế chịu, ghi nhận thất thoát nhận thiếu');
same((int)$f->db->value('SELECT diem FROM tai_xe WHERE id = ?', [$driver]), 95, 'tài xế bị trừ 5 điểm');
truthy(str_contains($trip['sai_lech'], 'Hàng vỡ') && str_contains($trip['sai_lech'], '3 thùng vỡ'), 'chuyến ghi lý do thiếu hàng');
$rec = $query->reconcile([$f->wa, $f->wb]);
same($rec['mismatches'], 0, 'Đối chiếu tồn vẫn KHỚP sau khi nhận thiếu');

section('Chưa nhận được hàng: không cộng tồn, báo admin; hàng về sau vẫn nhận được');
[$out3, $in3, $lx3, $ly3] = $ship(5, 4);
$bx = $f->stock($f->wb, 'x');
$ts->reportNotReceived($f->approverB, $in3, 'Không liên lạc được với tài xế');
same([$f->stock($f->wb, 'x'), $repo->find($in3)['trang_thai'], $tripOf($out3)['trang_thai']], [$bx, 'DRAFT', 'IN_TRANSIT'], 'tồn không đổi, phiếu vẫn chờ nhận, hàng vẫn đang vận chuyển');
$miss = array_values(array_filter($fleet->missingTrips(), fn($m) => (int)$m['phieu_den_id'] === $in3));
same(count($miss), 1, 'chuyến hiện ở mục «tài xế chưa thấy đâu» của admin');
same([$miss[0]['tai_xe_ten'], $miss[0]['so_dien_thoai']], ['Tài xế thử', '0912345678'], 'kèm tên và số điện thoại tài xế để gọi');
truthy(str_contains($miss[0]['sai_lech'], 'Không liên lạc được với tài xế'), 'kèm lý do');
truthy($miss[0]['bao_luc'] !== null && $miss[0]['bao_boi'] !== null, 'biết ai báo và báo lúc nào');
same($fleet->openDiscrepancies(), count($fleet->missingTrips()), 'số nhỏ trên menu «Đội xe» = số chuyến chưa thấy hàng');

section('Admin phản hồi cho kho');
throws(fn() => $fleet->replyToMissing($f->approverB, $in3, 'Xe gặp sự cố, đang xử lý'), Forbidden::class, 'quản lý kho không phản hồi thay admin');
throws(fn() => $fleet->replyToMissing($f->approverA, $in3, 'tình hình tự bịa'), DomainError::class, 'tình hình ngoài danh sách bị từ chối');
throws(fn() => $fleet->replyToMissing($f->approverA, $in3, 'Khác'), DomainError::class, 'tình hình «Khác» phải ghi rõ');
$fleet->replyToMissing($f->approverA, $in3, 'Xe gặp sự cố, đang xử lý', 'dự kiến tới lúc 16:30');
$fleet->replyToMissing($f->approverA, $in3, 'Đã liên hệ được tài xế, đang quay lại');
$rep = $fleet->replies($in3);
same(count($rep), 2, 'kho thấy cả hai phản hồi (mới nhất trước)');
truthy(str_contains($rep[0]['noi_dung'], 'đang quay lại') && str_contains($rep[1]['noi_dung'], '16:30'), 'nội dung phản hồi kèm ghi chú');
$miss = array_values(array_filter($fleet->missingTrips(), fn($m) => (int)$m['phieu_den_id'] === $in3));
same([(int)$miss[0]['phan_hoi_so'], str_contains((string)$miss[0]['phan_hoi'], 'đang quay lại')], [2, true], 'trang Đội xe thấy phản hồi gần nhất và số lần phản hồi');

$ts->receiveChecked($f->approverB, $in3, [$lx3 => 5, $ly3 => 4], true);
same([$f->stock($f->wb, 'x') - $bx, $tripOf($out3)['trang_thai'], $tripOf($out3)['sai_lech']], [5, 'DELIVERED', ''], 'hàng về sau: nhận bình thường, hết báo');
same(count(array_filter($fleet->missingTrips(), fn($m) => (int)$m['phieu_den_id'] === $in3)), 0, 'chuyến rời khỏi mục «chưa thấy đâu»');
throws(fn() => $fleet->replyToMissing($f->approverA, $in3, 'Xe gặp sự cố, đang xử lý'), DomainError::class, 'chuyến đã giao xong thì không phản hồi thêm');

finish();
