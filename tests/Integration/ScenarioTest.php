<?php
declare(strict_types=1);
// Hai tình huống sử dụng trong đề bài, chạy từ đầu đến cuối.
require __DIR__ . '/../Support/bootstrap.php';

use StockFlow\Domain\VoucherType;
use StockFlow\Service\StockCountService;
use StockFlow\Service\StockQuery;
use StockFlow\Service\TransferService;
use StockFlow\Service\VoucherService;

$f = Fx::make();
$vs = $f->c->get(VoucherService::class);
$ts = $f->c->get(TransferService::class);
$cs = $f->c->get(StockCountService::class);
$q = $f->c->get(StockQuery::class);

section('Tình huống 1: nhập hàng → xác nhận → xuất hàng → tra cứu tồn và lịch sử');
$pn = $vs->createDraft($f->keeperA, VoucherType::Receipt, $f->wa, [$f->line('x', 100), $f->line('lot', 40)], 'Công ty ABC', 'Lô hàng tháng 10', null)['voucher'];
same($f->stock($f->wa, 'x'), 0, 'phiếu nhập nháp chưa ảnh hưởng tồn');
$check = $vs->check($f->keeperA, (int)$pn['id']);
same(count($check), 2, 'bước kiểm tra liệt kê từng dòng');
$vs->confirm($f->approverA, (int)$pn['id']);
same([$f->stock($f->wa, 'x'), $f->stock($f->wa, 'lot')], [100, 40], 'xác nhận ⇒ tồn tăng');
$px = $vs->createDraft($f->keeperA, VoucherType::Issue, $f->wa, [$f->line('x', 30), $f->line('lot', 15)], 'Khách lẻ', '', null)['voucher'];
$chk = $vs->check($f->keeperA, (int)$px['id']);
same(array_column($chk, 'ok'), [true, true], 'kiểm tra: đủ hàng');
$vs->confirm($f->approverA, (int)$px['id']);
same([$f->stock($f->wa, 'x'), $f->stock($f->wa, 'lot')], [70, 25], 'xuất ⇒ tồn giảm');
$onHand = array_values(array_filter($q->onHand([$f->wa], $f->it['x']['sku'], true), fn($r) => $r['sku'] === $f->it['x']['sku']));
same([count($onHand), $onHand[0]['quantity']], [1, 70], 'tra cứu tồn từ sổ phát sinh: x = 70');
$hist = $q->history([$f->wa], ['item' => $f->it['x']['item']]);
same(array_column(array_reverse($hist), 'qty_change'), [100, -30], 'lịch sử hàng x: +100, −30');
same(array_column(array_reverse($hist), 'balance_after'), [100, 70], 'tồn sau từng bước');
same(array_column($hist, 'voucher_code'), [$px['code'], $pn['code']], 'mỗi dòng sổ truy được về chứng từ');
$lotRows = array_values(array_filter($q->onHand([$f->wa], '', true), fn($r) => $r['item_id'] === $f->it['lot']['item']));
same([$lotRows[0]['batch_no'], $lotRows[0]['quantity']], ['L1', 25], 'tồn theo lô');

section('Tình huống 2: điều chuyển → kiểm kê → xác nhận chênh lệch → chứng từ điều chỉnh');
$t = $ts->createDraft($f->keeperA, $f->wa, $f->wb, [$f->line('x', 20)], 'Bổ sung cho kho B', null)['voucher'];
$ts->confirm($f->approverA, (int)$t['id']);
same([$f->stock($f->wa, 'x'), $f->stock($f->wb, 'x')], [50, 20], 'sau điều chuyển: A=50, B=20');
$cid = $cs->open($f->keeperB, $f->wb, 'Kiểm kê sau nhận hàng');
$counted = [];
$why = [];
foreach ($cs->find($cid)['lines'] as $l) {
    $key = $l['item_id'] . ':' . $l['batch_id'];
    $counted[$key] = (int)$l['system_qty'];
    if ($l['item_id'] === $f->it['x']['item']) {
        $counted[$key] = 18;
        $why[$key] = 'Thất thoát / mất hàng: thùng rách trong vận chuyển';
    }
}
$cs->submit($f->keeperB, $cid, $counted, $why);
$res = $cs->approve($f->approverB, $cid);
same([$res['adjusted'], $res['net']], [1, -2], 'xác nhận chênh lệch −2');
same($f->stock($f->wb, 'x'), 18, 'kho B sau điều chỉnh = 18');
same($res['voucher']['type'], 'ADJUSTMENT', 'có chứng từ điều chỉnh');
$rec = $q->reconcile([$f->wa, $f->wb]);
$mine = array_filter($rec['rows'], fn($r) => in_array((int)$r['item_id'], array_column($f->it, 'item'), true));
same(count(array_filter($mine, fn($r) => $r['balance'] != $r['ledger'])), 0, 'đối chiếu tồn/sổ: khớp');
same($rec['mismatches'], 0, 'đối chiếu chứng từ/sổ: khớp');
finish();
