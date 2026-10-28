<?php
declare(strict_types=1);
// Kiểm kê có chất lượng: số hàng không đạt nằm trong thực đếm; duyệt xong tồn khớp thực đếm rồi phần không đạt được trừ vào kho huỷ.
require __DIR__ . '/../Support/bootstrap.php';

use StockFlow\Domain\DomainError;
use StockFlow\Service\DisposalService;
use StockFlow\Service\StockCountService;

$f = Fx::make();
$cs = $f->c->get(StockCountService::class);
$d = $f->c->get(DisposalService::class);
$f->receive($f->wa, 'x', 50);
$f->receive($f->wa, 'y', 20);
$k = fn(string $n) => $f->it[$n]['item'] . ':' . $f->it[$n]['batch'];
$f->db->exec('UPDATE mat_hang SET gia_von = 2000 WHERE id = ?', [$f->it['x']['item']]);

section('Nhập chất lượng khi đếm');
$id = $cs->open($f->keeperA, $f->wa, '');
$counted = [$k('x') => 48];
throws(fn() => $cs->save($f->keeperA, $id, $counted, [], [$k('x') => ['qty' => 49, 'reason' => 'VO_HONG', 'note' => '']]), DomainError::class, 'không đạt vượt thực đếm bị từ chối');
throws(fn() => $cs->save($f->keeperA, $id, $counted, [], [$k('x') => ['qty' => 3, 'reason' => '', 'note' => '']]), DomainError::class, 'có hàng không đạt thì phải chọn tình trạng');
throws(fn() => $cs->save($f->keeperA, $id, $counted, [], [$k('x') => ['qty' => 3, 'reason' => 'KHAC', 'note' => '']]), DomainError::class, 'tình trạng «Khác» cần ghi chú');
$cs->save($f->keeperA, $id, $counted, [], [$k('x') => ['qty' => 3, 'reason' => 'VO_HONG', 'note' => 'Rách thùng'], $k('y') => ['qty' => 2, 'reason' => 'HET_HAN', 'note' => '']]);
$lines = array_column($cs->find($id)['lines'], null, 'mat_hang_id');
same([(int)$lines[$f->it['x']['item']]['so_luong_hong'], (int)$lines[$f->it['y']['item']]['so_luong_hong']], [3, 2], 'lưu số không đạt; dòng chưa đếm tính theo sổ sách (20 ≥ 2)');
$cs->save($f->keeperA, $id, $counted, [], []);   // dòng không gửi chất lượng thì giữ nguyên
same((int)array_column($cs->find($id)['lines'], null, 'mat_hang_id')[$f->it['x']['item']]['so_luong_hong'], 3, 'lưu lại không kèm chất lượng: giữ nguyên số đã nhập');

section('Duyệt: tồn khớp thực đếm rồi trừ phần không đạt');
$cs->submit($f->keeperA, $id, $counted, [$k('x') => 'Nhập sai số lượng'], [$k('x') => ['qty' => 3, 'reason' => 'VO_HONG', 'note' => 'Rách thùng'], $k('y') => ['qty' => 2, 'reason' => 'HET_HAN', 'note' => '']]);
$res = $cs->approve($f->approverA, $id);
same($res['disposed'], 2, 'hai dòng vào kho huỷ');
same([$f->stock($f->wa, 'x'), $f->balance($f->wa, 'x')], [45, 45], 'x: sổ 50 → thực đếm 48 → trừ 3 hỏng = 45');
same([$f->stock($f->wa, 'y'), $f->balance($f->wa, 'y')], [18, 18], 'y: khớp 20, trừ 2 hết hạn = 18');
$rows = $d->list([$f->wa], 'TRONG_KHO_HUY');
same(count($rows), 2, 'kho huỷ có hai phiếu');
$hx = array_values(array_filter($rows, fn($r) => (int)$r['mat_hang_id'] === $f->it['x']['item']))[0];
same([(int)$hx['so_luong'], $hx['ly_do'], (int)$hx['value']], [3, 'VO_HONG', 6000], 'số lượng, lý do, giá trị vốn');
$again = $cs->approve($f->approverA, $id);
same([$again['already'], $f->stock($f->wa, 'x')], [true, 45], 'duyệt lại không trừ thêm');

finish();
