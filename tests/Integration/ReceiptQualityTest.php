<?php
declare(strict_types=1);
// Nhập hàng từ nhà cung cấp có kiểm tra chất lượng: tồn chỉ cộng phần đạt, phần không đạt thành phiếu trả, nhà cung cấp cấp lại thì trừ dần số còn thiếu.
require __DIR__ . '/../Support/bootstrap.php';

use StockFlow\Domain\DomainError;
use StockFlow\Domain\Forbidden;
use StockFlow\Domain\VoucherType;
use StockFlow\Service\SupplierReturnService;
use StockFlow\Service\VoucherRepository;
use StockFlow\Service\VoucherService;

$f = Fx::make();
$svc = $f->c->get(VoucherService::class);
$ret = $f->c->get(SupplierReturnService::class);
$repo = $f->c->get(VoucherRepository::class);
$x = $f->it['x'];
$k = $x['item'] . ':' . $x['batch'];
$bad = fn(int $n, string $why = 'VO_HONG', string $note = '') => [$k => ['loi' => $n, 'ly_do' => $why, 'ghi_chu' => $note]];
$draft = fn(int $qty, array $q = [], ?int $replace = null, string $partner = 'NCC test') => $svc->createDraft($f->keeperA, VoucherType::Receipt, $f->wa, [$f->line('x', $qty)], $partner, '', null, $q, $replace)['voucher'];

section('Kiểm tra khi lập phiếu');
throws(fn() => $draft(10, $bad(11)), DomainError::class, 'không đạt vượt số nhận');
throws(fn() => $draft(10, $bad(3, '')), DomainError::class, 'có hàng không đạt thì phải chọn tình trạng');
throws(fn() => $draft(10, $bad(3, 'KHAC')), DomainError::class, 'tình trạng «Khác» cần ghi chú');
throws(fn() => $draft(10, $bad(10)), DomainError::class, 'toàn bộ không đạt: không lập phiếu nhập');
throws(fn() => $svc->createDraft($f->keeperA, VoucherType::Issue, $f->wa, [$f->line('x', 1)], 'K', '', null, $bad(1)), DomainError::class, 'phiếu xuất không có kiểm tra chất lượng');

section('Nhận 10, không đạt 4 ⇒ tồn +6, lập phiếu trả');
$v = $draft(10, $bad(4, 'VO_HONG', 'Vỡ khi vận chuyển'));
same((int)$repo->lines((int)$v['id'])[0]['so_luong'], 6, 'dòng phiếu chỉ giữ phần đạt (6)');
$q = $repo->quality((int)$v['id'])[0];
same([(int)$q['so_luong_nhan'], (int)$q['so_luong_loi'], $q['ly_do']], [10, 4, 'VO_HONG'], 'ghi nhận số nhận 10, không đạt 4');
same($f->stock($f->wa, 'x'), 0, 'chưa xác nhận: chưa vào tồn');
$svc->confirm($f->approverA, (int)$v['id']);
same([$f->stock($f->wa, 'x'), $f->balance($f->wa, 'x')], [6, 6], 'xác nhận: tồn +6 (nhận 10 − trả lại 4)');
$svc->confirm($f->approverA, (int)$v['id']);
same($ret->counts([$f->wa]), ['CHO_CAP_LAI' => 1], 'xác nhận lại không lập thêm phiếu trả');
$t = $ret->find((int)$ret->list([$f->wa])[0]['id']);
same([$t['doi_tac'], (int)$t['lines'][0]['so_luong'], $t['trang_thai']], ['NCC test', 4, 'CHO_CAP_LAI'], 'phiếu trả 4 cái cho NCC test, chờ cấp lại');
same($ret->outstanding((int)$t['id']), [$x['item'] => 4], 'còn thiếu 4');

section('Nhận hàng không lỗi: không có phiếu trả');
$ok = $draft(5);
$svc->confirm($f->approverA, (int)$ok['id']);
same([$f->stock($f->wa, 'x'), $ret->counts([$f->wa])['CHO_CAP_LAI']], [11, 1], 'tồn +5, vẫn chỉ một phiếu trả');

section('NCC cấp lại: nhập đúng số lượng, trừ dần số còn thiếu');
throws(fn() => $draft(4, [], (int)$t['id'], 'NCC khác'), DomainError::class, 'cấp lại phải đúng nhà cung cấp');
$other = $f->it['y'];
throws(fn() => $svc->createDraft($f->keeperA, VoucherType::Receipt, $f->wa, [$f->line('y', 4)], 'NCC test', '', null, [], (int)$t['id']), DomainError::class, 'chỉ nhận hàng có trong phiếu trả');
throws(fn() => $svc->createDraft($f->keeperB, VoucherType::Receipt, $f->wb, [$f->line('x', 4)], 'NCC test', '', null, [], (int)$t['id']), DomainError::class, 'phiếu trả của kho khác');
$r1 = $draft(3, [], (int)$t['id']);
same((int)$repo->find((int)$r1['id'])['cap_lai_cho_id'], (int)$t['id'], 'phiếu nhập gắn với phiếu trả');
$svc->confirm($f->approverA, (int)$r1['id']);
same([$f->stock($f->wa, 'x'), $ret->outstanding((int)$t['id'])], [14, [$x['item'] => 1]], 'cấp lại 3: tồn 14, còn thiếu 1');
same($ret->find((int)$t['id'])['trang_thai'], 'CHO_CAP_LAI', 'chưa đủ thì vẫn chờ');
$r2 = $draft(1, [], (int)$t['id']);
throws(fn() => $ret->close($f->approverA, (int)$t['id']), DomainError::class, 'còn phiếu cấp lại nháp: chưa đóng được');
$svc->confirm($f->approverA, (int)$r2['id']);
same([$f->stock($f->wa, 'x'), $ret->find((int)$t['id'])['trang_thai']], [15, 'DA_CAP_LAI'], 'cấp lại đủ 4: tồn 15, phiếu trả đã cấp lại đủ');
throws(fn() => $draft(1, [], (int)$t['id']), DomainError::class, 'phiếu trả đã đủ không nhận thêm');

section('Hàng cấp lại vẫn có thể lỗi: phát sinh phiếu trả mới; NCC không cấp lại thì đóng');
$v2 = $draft(6, $bad(2, 'LOI'));
$svc->confirm($f->approverA, (int)$v2['id']);
$t2 = array_values(array_filter($ret->list([$f->wa], 'CHO_CAP_LAI'), fn($r) => (int)$r['phieu_nhap_xuat_id'] === (int)$v2['id']))[0];
$rr = $draft(2, $bad(1, 'HET_HAN'), (int)$t2['id']);
$svc->confirm($f->approverA, (int)$rr['id']);
same([$ret->find((int)$t2['id'])['trang_thai'], $f->stock($f->wa, 'x'), $ret->outstanding((int)$t2['id'])], ['CHO_CAP_LAI', 20, [$x['item'] => 1]], 'cấp lại 2 nhưng 1 lỗi: chỉ phần đạt (1) trừ vào số thiếu, còn thiếu 1');
same($ret->counts([$f->wa])['CHO_CAP_LAI'], 2, 'phần lỗi của lô cấp lại thành phiếu trả mới');
$ret->close($f->approverA, (int)$t2['id']);
same($ret->find((int)$t2['id'])['trang_thai'], 'HUY', 'NCC không cấp lại nữa: đóng phiếu trả');
throws(fn() => $ret->close($f->keeperA, (int)$ret->list([$f->wa], 'CHO_CAP_LAI')[0]['id']), Forbidden::class, 'nhân viên không đóng phiếu trả');

finish();
