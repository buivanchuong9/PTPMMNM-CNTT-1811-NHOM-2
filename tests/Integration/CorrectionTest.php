<?php
declare(strict_types=1);
// Sửa chứng từ đã ghi nhận bằng chứng từ điều chỉnh — không xoá lịch sử.
require __DIR__ . '/../Support/bootstrap.php';

use StockFlow\Domain\DomainError;
use StockFlow\Domain\InsufficientStock;
use StockFlow\Domain\VoucherType;
use StockFlow\Service\StockQuery;
use StockFlow\Service\TransferService;
use StockFlow\Service\VoucherRepository;
use StockFlow\Service\VoucherService;

$f = Fx::make();
$vs = $f->c->get(VoucherService::class);
$ts = $f->c->get(TransferService::class);
$repo = $f->c->get(VoucherRepository::class);
$q = $f->c->get(StockQuery::class);

section('Đảo phiếu nhập nhầm');
$wrong = $f->receive($f->wa, 'x', 900);
$origLines = $repo->lines((int)$wrong['id']);
$movBefore = (int)$f->db->value('SELECT COUNT(*) FROM stock_movements WHERE voucher_id = ?', [$wrong['id']]);
throws(fn() => $vs->correct($f->approverA, (int)$wrong['id'], '  '), DomainError::class, 'bắt buộc có lý do');
$res = $vs->correct($f->approverA, (int)$wrong['id'], 'Nhập nhầm: thực nhận 90');
$adj = $res['voucher'];
same([$adj['type'], $adj['status'], (int)$adj['correction_of']], ['ADJUSTMENT', 'CONFIRMED', (int)$wrong['id']], 'lập chứng từ điều chỉnh trỏ về chứng từ gốc');
same($adj['reason'], 'Nhập nhầm: thực nhận 90', 'lưu lý do');
same($f->stock($f->wa, 'x'), 0, 'tồn về 0 nhờ dòng đảo −900');
same($repo->find((int)$wrong['id'])['status'], 'CONFIRMED', 'chứng từ gốc KHÔNG bị sửa/xoá');
same($repo->lines((int)$wrong['id']), $origLines, 'dòng chứng từ gốc nguyên vẹn');
same((int)$f->db->value('SELECT COUNT(*) FROM stock_movements WHERE voucher_id = ?', [$wrong['id']]), $movBefore, 'sổ phát sinh gốc nguyên vẹn');
same((int)$f->db->value('SELECT COUNT(*) FROM stock_movements WHERE voucher_id = ?', [$adj['id']]), 1, 'thêm một dòng sổ đảo (lịch sử chỉ tăng)');
throws(fn() => $vs->correct($f->approverA, (int)$wrong['id'], 'lần 2'), DomainError::class, 'một chứng từ chỉ được điều chỉnh một lần');
same($repo->correctionOf((int)$wrong['id'])['code'], $adj['code'], 'tra cứu được chứng từ điều chỉnh từ chứng từ gốc');
$f->receive($f->wa, 'x', 90);

section('Không đảo được nếu hàng đã được dùng');
$r = $f->receive($f->wa, 'y', 10);
$iss = $f->draftIssue($f->wa, 'y', 8);
$vs->confirm($f->approverA, (int)$iss['id']);
throws(fn() => $vs->correct($f->approverA, (int)$r['id'], 'nhập nhầm'), InsufficientStock::class, 'đảo phiếu nhập khi tồn không đủ bị chặn');
same([$f->stock($f->wa, 'y'), (int)$f->db->value('SELECT COUNT(*) FROM vouchers WHERE correction_of = ?', [$r['id']])], [2, 0], 'không có thay đổi dở dang');
throws(fn() => $vs->correct($f->approverA, (int)$f->draftIssue($f->wa, 'y', 1)['id'], 'x'), DomainError::class, 'chỉ chứng từ đã xác nhận mới điều chỉnh');

section('Đảo phiếu xuất');
$f->receive($f->wa, 'y', 8);
$iss2 = $f->draftIssue($f->wa, 'y', 5);
$vs->confirm($f->approverA, (int)$iss2['id']);
$before = $f->stock($f->wa, 'y');
$vs->correct($f->approverA, (int)$iss2['id'], 'Xuất nhầm kho');
same($f->stock($f->wa, 'y'), $before + 5, 'tồn hoàn lại +5');

section('Đảo cả điều chuyển (hai kho, một giao dịch)');
$f->receive($f->wa, 'lot', 20);
$t = $ts->createDraft($f->both, $f->wa, $f->wb, [$f->line('lot', 12)], '', null)['voucher'];
$ts->confirm($f->both, (int)$t['id']);
same([$f->stock($f->wa, 'lot'), $f->stock($f->wb, 'lot')], [8, 12], 'sau điều chuyển: A=8, B=12');
$in = $repo->pairOf($repo->find((int)$t['id']));
$res = $vs->correct($f->both, (int)$in['id'], 'Điều chuyển nhầm tuyến');   // gọi từ phía chứng từ NƠI ĐẾN vẫn đảo cả cặp
same([$f->stock($f->wa, 'lot'), $f->stock($f->wb, 'lot')], [20, 0], 'đảo: A=20, B=0');
same((int)$f->db->value("SELECT COUNT(*) FROM vouchers WHERE type = 'ADJUSTMENT' AND correction_of IN (?,?)", [$t['id'], $in['id']]), 2, 'hai chứng từ điều chỉnh, một ở mỗi kho');
throws(fn() => $vs->correct($f->both, (int)$t['id'], 'lần 2'), DomainError::class, 'điều chuyển đã đảo không đảo lần nữa');
$f->receive($f->wb, 'lot', 3);
$t2 = $ts->createDraft($f->both, $f->wa, $f->wb, [$f->line('lot', 6)], '', null)['voucher'];
$ts->confirm($f->both, (int)$t2['id']);
$f->draftIssue($f->wb, 'x', 1); // không liên quan
$iss3 = $vs->createDraft($f->keeperB, VoucherType::Issue, $f->wb, [$f->line('lot', 8)], '', '', null)['voucher'];
$vs->confirm($f->approverB, (int)$iss3['id']);          // B còn 9 − 8 = 1 < 6
$snapshot = [$f->stock($f->wa, 'lot'), $f->stock($f->wb, 'lot')];
throws(fn() => $vs->correct($f->both, (int)$t2['id'], 'đảo'), InsufficientStock::class, 'kho đến đã dùng hàng ⇒ không đảo được');
same([$f->stock($f->wa, 'lot'), $f->stock($f->wb, 'lot')], $snapshot, 'và không có thay đổi dở dang ở kho đi');

section('Toàn bộ vẫn đối chiếu được');
$mine = array_filter($q->reconcile([$f->wa, $f->wb])['rows'], fn($r) => in_array((int)$r['item_id'], array_column($f->it, 'item'), true));
same(count(array_filter($mine, fn($r) => $r['balance'] != $r['ledger'])), 0, 'số dư = sổ phát sinh ở mọi vị trí');

section('Khoá bất biến ở CSDL (nếu đã cài trigger)');
$hasTriggers = (int)$f->db->value("SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = DATABASE() AND trigger_name = 'stock_movements_no_update'") > 0;
if ($hasTriggers) {
    throws(fn() => $f->db->exec('UPDATE stock_movements SET qty_change = 999 WHERE voucher_id = ?', [$wrong['id']]), mysqli_sql_exception::class, 'không sửa được dòng sổ phát sinh');
    throws(fn() => $f->db->exec('DELETE FROM stock_movements WHERE voucher_id = ?', [$wrong['id']]), mysqli_sql_exception::class, 'không xoá được dòng sổ phát sinh');
    throws(fn() => $f->db->exec('DELETE FROM vouchers WHERE id = ?', [$wrong['id']]), mysqli_sql_exception::class, 'không xoá được chứng từ');
    throws(fn() => $f->db->exec("UPDATE vouchers SET note = 'sửa lén' WHERE id = ?", [$wrong['id']]), mysqli_sql_exception::class, 'không sửa được chứng từ đã xác nhận');
    throws(fn() => $f->db->exec('UPDATE voucher_lines SET qty = 1 WHERE voucher_id = ?', [$wrong['id']]), mysqli_sql_exception::class, 'không sửa được dòng chứng từ');
} else {
    echo "  ⚠ chưa cài trigger bất biến (migration 002 tuỳ chọn) — bỏ qua phần này\n";
}
finish();
