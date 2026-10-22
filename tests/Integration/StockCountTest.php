<?php
declare(strict_types=1);
// Phép thử 5: điều chỉnh kiểm kê có lý do và phiếu nhập xuất; tổng tồn sau điều chỉnh khớp lịch sử phát sinh.
require __DIR__ . '/../Support/bootstrap.php';

use StockFlow\Domain\DomainError;
use StockFlow\Service\StockCountService;
use StockFlow\Service\StockQuery;
use StockFlow\Service\VoucherRepository;

$f = Fx::make();
$cs = $f->c->get(StockCountService::class);
$q = $f->c->get(StockQuery::class);
$repo = $f->c->get(VoucherRepository::class);
$f->receive($f->wa, 'x', 50);
$f->receive($f->wa, 'y', 20);
$f->receive($f->wa, 'lot', 8);
$k = fn(string $n) => $f->it[$n]['item'] . ':' . $f->it[$n]['batch'];

section('Mở đợt: chụp tồn sổ sách');
$id = $cs->open($f->keeperA, $f->wa, 'kiểm kê test');
$snap = [];
foreach ($cs->find($id)['lines'] as $l) {
    $snap[$l['mat_hang_id'] . ':' . $l['lo_hang_id']] = (int)$l['so_luong_so_sach'];
}
same([$snap[$k('x')], $snap[$k('y')], $snap[$k('lot')]], [50, 20, 8], 'sổ sách lúc mở: x=50, y=20, lot=8');
throws(fn() => $cs->open($f->keeperA, $f->wa, ''), DomainError::class, 'mỗi kho một đợt đang mở');

section('Trong lúc đếm có phát sinh mới (nhập thêm 5 x)');
$f->receive($f->wa, 'x', 5);   // sổ = 55

section('Nhập số thực tế & tính chênh lệch');
$all = array_map(fn($s) => $s, $snap);
$counted = $all;
$counted[$k('x')] = 47;   // lệch −3 so với sổ lúc mở
$counted[$k('lot')] = 10; // lệch +2
$counted[$k('y')] = 20;   // khớp
$cs->save($f->keeperA, $id, $counted);
$line = array_values(array_filter($cs->find($id)['lines'], fn($l) => $l['mat_hang_id'] === $f->it['x']['item']))[0];
same([(int)$line['so_luong_so_sach'], (int)$line['so_luong_thuc_dem']], [50, 47], 'lưu số đếm kèm số sổ sách tại thời điểm mở');

section('Không có lý do thì không nộp được');
throws(fn() => $cs->submit($f->keeperA, $id, $counted, []), DomainError::class, 'dòng lệch thiếu lý do bị từ chối');
$cs->submit($f->keeperA, $id, $counted, [$k('x') => 'Hư hỏng / vỡ: 3 thùng rách', $k('lot') => 'Chưa ghi phiếu nhập xuất']);
same($cs->find($id)['trang_thai'], 'REVIEW', 'đã nộp, chờ người duyệt');
throws(fn() => $cs->save($f->keeperA, $id, $counted), DomainError::class, 'đang chờ duyệt: không sửa được số đếm');

section('Xác nhận chênh lệch ⇒ phiếu nhập xuất điều chỉnh có lý do');
$before = array_sum(array_map(fn($n) => $f->stock($f->wa, $n), ['x', 'y', 'lot']));
$res = $cs->approve($f->approverA, $id);
$adj = $res['voucher'];
same([$res['adjusted'], $res['net']], [2, -1], '2 dòng lệch, chênh lệch ròng −3 + 2 = −1');
same([$adj['loai'], $adj['trang_thai']], ['ADJUSTMENT', 'CONFIRMED'], 'có phiếu nhập xuất điều chỉnh đã xác nhận');
truthy(str_contains($adj['ly_do'], $cs->find($id)['ma']) && str_contains($adj['ly_do'], 'Hư hỏng'), 'phiếu nhập xuất ghi lý do và mã đợt kiểm kê');
$lines = $repo->lines((int)$adj['id']);
same(array_column($lines, 'so_luong'), [-3, 2], 'dòng điều chỉnh mang dấu: −3 và +2');
truthy(array_filter($lines, fn($l) => str_contains($l['ghi_chu'], 'Hư hỏng')) !== [], 'mỗi dòng giữ lý do chênh lệch');
same($cs->find($id)['trang_thai'], 'CLOSED', 'đợt kiểm kê đã đóng');
same((int)$cs->find($id)['phieu_nhap_xuat_dieu_chinh_id'], (int)$adj['id'], 'đợt kiểm kê trỏ tới phiếu nhập xuất điều chỉnh');

section('Tổng tồn sau điều chỉnh khớp lịch sử phát sinh');
same($f->stock($f->wa, 'x'), 52, 'x: 55 (kể cả nhập thêm khi đang đếm) − 3 = 52');
same($f->stock($f->wa, 'lot'), 10, 'lot: 8 + 2 = 10');
same($f->stock($f->wa, 'y'), 20, 'y không đổi');
same(array_sum(array_map(fn($n) => $f->stock($f->wa, $n), ['x', 'y', 'lot'])), $before - 1, 'tổng tồn giảm đúng chênh lệch ròng −1');
foreach (['x', 'y', 'lot'] as $n) {
    same($f->balance($f->wa, $n), $f->stock($f->wa, $n), "số dư = tổng sổ phát sinh ($n)");
}
$mine = array_values(array_filter($q->reconcile([$f->wa])['rows'], fn($r) => in_array((int)$r['mat_hang_id'], array_column($f->it, 'item'), true)));
same(array_sum(array_map(fn($r) => (int)$r['balance'] - (int)$r['ledger'], $mine)), 0, 'trang Đối chiếu: không lệch');
same($q->reconcile([$f->wa])['mismatches'], 0, 'không có dòng phiếu nhập xuất nào thiếu/lệch sổ');

section('Đợt kiểm khớp hoàn toàn: đóng mà không lập phiếu nhập xuất');
$id2 = $cs->open($f->keeperA, $f->wa, '');
$c2 = [];
foreach ($cs->find($id2)['lines'] as $l) {
    $c2[$l['mat_hang_id'] . ':' . $l['lo_hang_id']] = (int)$l['so_luong_so_sach'];
}
$cs->submit($f->keeperA, $id2, $c2, []);
$r2 = $cs->approve($f->approverA, $id2);
same([$r2['adjusted'], $r2['voucher']], [0, null], 'không chênh lệch ⇒ không có phiếu nhập xuất điều chỉnh');

section('Chỉ nhập dòng có thay đổi: dòng để trống coi như khớp sổ sách');
$id3 = $cs->open($f->keeperA, $f->wa, 'chỉ một dòng lệch');
$sys = [];
foreach ($cs->find($id3)['lines'] as $l) {
    $sys[$l['mat_hang_id'] . ':' . $l['lo_hang_id']] = (int)$l['so_luong_so_sach'];
}
$only = [$k('y') => $sys[$k('y')] - 4];
throws(fn() => $cs->submit($f->keeperA, $id3, $only, []), DomainError::class, 'dòng đã nhập mà lệch thì vẫn phải có lý do');
$cs->submit($f->keeperA, $id3, $only, [$k('y') => 'Thất thoát / mất hàng']);
$before3 = [$f->stock($f->wa, 'x'), $f->stock($f->wa, 'lot')];
$r3 = $cs->approve($f->approverA, $id3);
same([$r3['adjusted'], $r3['net']], [1, -4], 'chỉ 1 dòng được điều chỉnh (−4); các dòng để trống không bị đụng tới');
same([$f->stock($f->wa, 'y'), $f->stock($f->wa, 'x'), $f->stock($f->wa, 'lot')], [16, $before3[0], $before3[1]], 'y giảm 4; x và lot giữ nguyên');
foreach (['x', 'y', 'lot'] as $n) {
    same($f->balance($f->wa, $n), $f->stock($f->wa, $n), "số dư = tổng sổ phát sinh ($n)");
}
finish();
