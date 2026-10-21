<?php
declare(strict_types=1);
// Phép thử 2: lỗi giữa lúc trừ kho A và cộng kho B ⇒ không thay đổi dở dang; không mất / nhân đôi hàng.
require __DIR__ . '/../Support/bootstrap.php';

use StockFlow\Core\Hooks;
use StockFlow\Domain\DomainError;
use StockFlow\Domain\InsufficientStock;
use StockFlow\Service\TransferService;
use StockFlow\Service\VoucherRepository;

$f = Fx::make();
$f->receive($f->wa, 'x', 50);
$f->receive($f->wa, 'lot', 30);
$f->receive($f->wb, 'x', 10);
$svc = $f->c->get(TransferService::class);
$repo = $f->c->get(VoucherRepository::class);
$total = fn(string $k) => $f->stock($f->wa, $k) + $f->stock($f->wb, $k);
$snapshot = fn() => [$f->stock($f->wa, 'x'), $f->stock($f->wb, 'x'), $f->stock($f->wa, 'lot'), $f->stock($f->wb, 'lot'), $f->movements($f->wa), $f->movements($f->wb),
    (int)$f->db->value("SELECT COUNT(*) FROM vouchers WHERE type = 'TRANSFER_IN' AND warehouse_id IN (?,?)", [$f->wa, $f->wb])];

$t = $svc->createDraft($f->keeperA, $f->wa, $f->wb, [$f->line('x', 20), $f->line('lot', 5)], 'test', null)['voucher'];
$before = $snapshot();

section('Lỗi NGAY SAU khi trừ kho A, TRƯỚC khi cộng kho B');
Hooks::on('transfer.between', fn() => throw new RuntimeException('mất điện giữa chừng'));
throws(fn() => $svc->confirm($f->both, (int)$t['id']), RuntimeException::class, 'xác nhận ném lỗi giả lập');
Hooks::clear();
same($snapshot(), $before, 'tồn/sổ/chứng từ y hệt trước đó — không mất hàng ở kho đi, không có hàng ở kho đến');
same($repo->find((int)$t['id'])['status'], 'DRAFT', 'phiếu điều chuyển vẫn là nháp');
same($f->balance($f->wa, 'x'), 50, 'số dư kho A không đổi');

section('Lỗi SAU khi đã cộng kho B, TRƯỚC khi chốt');
Hooks::on('transfer.before_commit', fn() => throw new RuntimeException('lỗi ở bước cuối'));
throws(fn() => $svc->confirm($f->both, (int)$t['id']), RuntimeException::class, 'xác nhận ném lỗi giả lập');
Hooks::clear();
same($snapshot(), $before, 'rollback toàn bộ: không nhân đôi hàng (kho B không giữ phần đã cộng)');
same($total('x'), 60, 'tổng tồn hai kho bảo toàn (50 + 10)');

section('Thử lại không lỗi ⇒ trừ đúng một lần, cộng đúng một lần');
$res = $svc->confirm($f->both, (int)$t['id']);
same($res['already'], false, 'xác nhận thành công');
same([$f->stock($f->wa, 'x'), $f->stock($f->wb, 'x')], [30, 30], 'x: A 50→30, B 10→30');
same([$f->stock($f->wa, 'lot'), $f->stock($f->wb, 'lot')], [25, 5], 'lô: A 30→25, B 0→5');
same($total('x'), 60, 'tổng tồn bảo toàn');
same([$f->balance($f->wa, 'x'), $f->balance($f->wb, 'x')], [30, 30], 'số dư khớp sổ phát sinh');

section('Chứng từ đầy đủ tại nơi đi và nơi đến');
$out = $repo->find((int)$t['id']);
$pair = $repo->pairOf($out);
$in = $repo->find((int)$pair['id']);
same([$out['type'], $out['status'], (int)$out['warehouse_id'], (int)$out['counterpart_wh_id']], ['TRANSFER_OUT', 'CONFIRMED', $f->wa, $f->wb], 'chứng từ nơi đi: DCX tại kho A');
same([$in['type'], $in['status'], (int)$in['warehouse_id'], (int)$in['counterpart_wh_id']], ['TRANSFER_IN', 'CONFIRMED', $f->wb, $f->wa], 'chứng từ nơi đến: DCN tại kho B');
same((int)$in['ref_voucher_id'], (int)$out['id'], 'hai chứng từ liên kết với nhau');
same(count($repo->lines((int)$in['id'])), 2, 'chứng từ nơi đến có đủ dòng hàng');
same((int)$f->db->value('SELECT COUNT(*) FROM stock_movements WHERE voucher_id IN (?,?)', [$out['id'], $in['id']]), 4, '4 dòng sổ: 2 trừ ở A + 2 cộng ở B');

section('Thiếu hàng ⇒ từ chối, không thay đổi gì');
$big = $svc->createDraft($f->keeperA, $f->wa, $f->wb, [$f->line('x', 5), $f->line('lot', 999)], '', null)['voucher'];
$before = $snapshot();
throws(fn() => $svc->confirm($f->both, (int)$big['id']), InsufficientStock::class, 'dòng thứ hai thiếu hàng');
same($snapshot(), $before, 'dòng đầu (x×5) cũng không bị trừ — giao dịch nguyên khối');

section('Điều chuyển hai chiều đồng thời không deadlock');
$f->receive($f->wb, 'y', 40);
$f->receive($f->wa, 'y', 40);
$ab = $svc->createDraft($f->both, $f->wa, $f->wb, [$f->line('x', 3), $f->line('y', 4)], '', null)['voucher'];
$ba = $svc->createDraft($f->both, $f->wb, $f->wa, [$f->line('y', 5), $f->line('x', 2)], '', null)['voucher'];
$res = runParallel([['voucher' => $ab['id'], 'user' => $f->both->id, 'sleepMs' => 300], ['voucher' => $ba['id'], 'user' => $f->both->id, 'sleepMs' => 300]]);
same(array_column($res, 'status'), ['ok', 'ok'], 'cả hai chiều thành công (khoá theo thứ tự toàn cục)');
same([$f->stock($f->wa, 'x'), $f->stock($f->wb, 'x')], [29, 31], 'x: A 30−3+2, B 30+3−2');
same($f->stock($f->wa, 'y') + $f->stock($f->wb, 'y'), 80, 'y: tổng bảo toàn');
finish();
