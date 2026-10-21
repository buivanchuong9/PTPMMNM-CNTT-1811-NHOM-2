<?php
declare(strict_types=1);
// Phép thử 5: điều chỉnh kiểm kê có lý do và chứng từ; tổng tồn sau điều chỉnh khớp lịch sử phát sinh.
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
    $snap[$l['item_id'] . ':' . $l['batch_id']] = (int)$l['system_qty'];
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
$line = array_values(array_filter($cs->find($id)['lines'], fn($l) => $l['item_id'] === $f->it['x']['item']))[0];
same([(int)$line['system_qty'], (int)$line['counted_qty']], [50, 47], 'lưu số đếm kèm số sổ sách tại thời điểm mở');

section('Không có lý do thì không nộp được');
throws(fn() => $cs->submit($f->keeperA, $id, $counted, []), DomainError::class, 'dòng lệch thiếu lý do bị từ chối');
$partial = $counted;
$partial[array_key_first($partial)] = null;
throws(fn() => $cs->submit($f->keeperA, $id, $partial, [$k('x') => 'Hư hỏng', $k('lot') => 'Chưa ghi chứng từ']), DomainError::class, 'còn dòng chưa đếm bị từ chối');
$cs->submit($f->keeperA, $id, $counted, [$k('x') => 'Hư hỏng / vỡ: 3 thùng rách', $k('lot') => 'Chưa ghi chứng từ']);
same($cs->find($id)['status'], 'REVIEW', 'đã nộp, chờ người duyệt');
throws(fn() => $cs->save($f->keeperA, $id, $counted), DomainError::class, 'đang chờ duyệt: không sửa được số đếm');

section('Xác nhận chênh lệch ⇒ chứng từ điều chỉnh có lý do');
$before = array_sum(array_map(fn($n) => $f->stock($f->wa, $n), ['x', 'y', 'lot']));
$res = $cs->approve($f->approverA, $id);
$adj = $res['voucher'];
same([$res['adjusted'], $res['net']], [2, -1], '2 dòng lệch, chênh lệch ròng −3 + 2 = −1');
same([$adj['type'], $adj['status']], ['ADJUSTMENT', 'CONFIRMED'], 'có chứng từ điều chỉnh đã xác nhận');
truthy(str_contains($adj['reason'], $cs->find($id)['code']) && str_contains($adj['reason'], 'Hư hỏng'), 'chứng từ ghi lý do và mã đợt kiểm kê');
$lines = $repo->lines((int)$adj['id']);
same(array_column($lines, 'qty'), [-3, 2], 'dòng điều chỉnh mang dấu: −3 và +2');
truthy(array_filter($lines, fn($l) => str_contains($l['note'], 'Hư hỏng')) !== [], 'mỗi dòng giữ lý do chênh lệch');
same($cs->find($id)['status'], 'CLOSED', 'đợt kiểm kê đã đóng');
same((int)$cs->find($id)['adjustment_voucher_id'], (int)$adj['id'], 'đợt kiểm kê trỏ tới chứng từ điều chỉnh');

section('Tổng tồn sau điều chỉnh khớp lịch sử phát sinh');
same($f->stock($f->wa, 'x'), 52, 'x: 55 (kể cả nhập thêm khi đang đếm) − 3 = 52');
same($f->stock($f->wa, 'lot'), 10, 'lot: 8 + 2 = 10');
same($f->stock($f->wa, 'y'), 20, 'y không đổi');
same(array_sum(array_map(fn($n) => $f->stock($f->wa, $n), ['x', 'y', 'lot'])), $before - 1, 'tổng tồn giảm đúng chênh lệch ròng −1');
foreach (['x', 'y', 'lot'] as $n) {
    same($f->balance($f->wa, $n), $f->stock($f->wa, $n), "số dư = tổng sổ phát sinh ($n)");
}
$mine = array_values(array_filter($q->reconcile([$f->wa])['rows'], fn($r) => in_array((int)$r['item_id'], array_column($f->it, 'item'), true)));
same(array_sum(array_map(fn($r) => (int)$r['balance'] - (int)$r['ledger'], $mine)), 0, 'trang Đối chiếu: không lệch');
same($q->reconcile([$f->wa])['mismatches'], 0, 'không có dòng chứng từ nào thiếu/lệch sổ');

section('Đợt kiểm khớp hoàn toàn: đóng mà không lập chứng từ');
$id2 = $cs->open($f->keeperA, $f->wa, '');
$c2 = [];
foreach ($cs->find($id2)['lines'] as $l) {
    $c2[$l['item_id'] . ':' . $l['batch_id']] = (int)$l['system_qty'];
}
$cs->submit($f->keeperA, $id2, $c2, []);
$r2 = $cs->approve($f->approverA, $id2);
same([$r2['adjusted'], $r2['voucher']], [0, null], 'không chênh lệch ⇒ không có chứng từ điều chỉnh');
finish();
