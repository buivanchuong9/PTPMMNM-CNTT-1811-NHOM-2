<?php
declare(strict_types=1);
// Sửa phiếu nhập xuất đã ghi nhận bằng phiếu nhập xuất điều chỉnh — không xoá lịch sử.
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
$movBefore = (int)$f->db->value('SELECT COUNT(*) FROM so_phat_sinh WHERE phieu_nhap_xuat_id = ?', [$wrong['id']]);
throws(fn() => $vs->correct($f->approverA, (int)$wrong['id'], '  '), DomainError::class, 'bắt buộc có lý do');
$res = $vs->correct($f->approverA, (int)$wrong['id'], 'Nhập nhầm: thực nhận 90');
$adj = $res['voucher'];
same([$adj['loai'], $adj['trang_thai'], (int)$adj['dieu_chinh_cho_id']], ['ADJUSTMENT', 'CONFIRMED', (int)$wrong['id']], 'lập phiếu nhập xuất điều chỉnh trỏ về phiếu nhập xuất gốc');
same($adj['ly_do'], 'Nhập nhầm: thực nhận 90', 'lưu lý do');
same($f->stock($f->wa, 'x'), 0, 'tồn về 0 nhờ dòng đảo −900');
same($repo->find((int)$wrong['id'])['trang_thai'], 'CONFIRMED', 'phiếu nhập xuất gốc KHÔNG bị sửa/xoá');
same($repo->lines((int)$wrong['id']), $origLines, 'dòng phiếu nhập xuất gốc nguyên vẹn');
same((int)$f->db->value('SELECT COUNT(*) FROM so_phat_sinh WHERE phieu_nhap_xuat_id = ?', [$wrong['id']]), $movBefore, 'sổ phát sinh gốc nguyên vẹn');
same((int)$f->db->value('SELECT COUNT(*) FROM so_phat_sinh WHERE phieu_nhap_xuat_id = ?', [$adj['id']]), 1, 'thêm một dòng sổ đảo (lịch sử chỉ tăng)');
throws(fn() => $vs->correct($f->approverA, (int)$wrong['id'], 'lần 2'), DomainError::class, 'một phiếu nhập xuất chỉ được điều chỉnh một lần');
same($repo->correctionOf((int)$wrong['id'])['ma'], $adj['ma'], 'tra cứu được phiếu nhập xuất điều chỉnh từ phiếu nhập xuất gốc');
$f->receive($f->wa, 'x', 90);

section('Không đảo được nếu hàng đã được dùng');
$r = $f->receive($f->wa, 'y', 10);
$iss = $f->draftIssue($f->wa, 'y', 8);
$vs->confirm($f->approverA, (int)$iss['id']);
throws(fn() => $vs->correct($f->approverA, (int)$r['id'], 'nhập nhầm'), InsufficientStock::class, 'đảo phiếu nhập khi tồn không đủ bị chặn');
same([$f->stock($f->wa, 'y'), (int)$f->db->value('SELECT COUNT(*) FROM phieu_nhap_xuat WHERE dieu_chinh_cho_id = ?', [$r['id']])], [2, 0], 'không có thay đổi dở dang');
throws(fn() => $vs->correct($f->approverA, (int)$f->draftIssue($f->wa, 'y', 1)['id'], 'x'), DomainError::class, 'chỉ phiếu nhập xuất đã xác nhận mới điều chỉnh');

section('Đảo phiếu xuất');
$f->receive($f->wa, 'y', 8);
$iss2 = $f->draftIssue($f->wa, 'y', 5);
$vs->confirm($f->approverA, (int)$iss2['id']);
$before = $f->stock($f->wa, 'y');
$vs->correct($f->approverA, (int)$iss2['id'], 'Xuất nhầm kho');
same($f->stock($f->wa, 'y'), $before + 5, 'tồn hoàn lại +5');

section('Đảo cả điều chuyển (hai kho, một giao dịch)');
$f->receive($f->wa, 'lot', 20);
$t = $ts->createDraft($f->keeperA, $f->wa, $f->wb, [$f->line('lot', 12)], '', null)['voucher'];
$ts->confirm($f->approverA, (int)$t['id']);
same([$f->stock($f->wa, 'lot'), $f->stock($f->wb, 'lot')], [8, 12], 'sau điều chuyển: A=8, B=12');
$in = $repo->pairOf($repo->find((int)$t['id']));
$res = $vs->correct($f->both, (int)$in['id'], 'Điều chuyển nhầm tuyến');   // gọi từ phía phiếu nhập xuất NƠI ĐẾN vẫn đảo cả cặp
same([$f->stock($f->wa, 'lot'), $f->stock($f->wb, 'lot')], [20, 0], 'đảo: A=20, B=0');
same((int)$f->db->value("SELECT COUNT(*) FROM phieu_nhap_xuat WHERE loai = 'ADJUSTMENT' AND dieu_chinh_cho_id IN (?,?)", [$t['id'], $in['id']]), 2, 'hai phiếu nhập xuất điều chỉnh, một ở mỗi kho');
throws(fn() => $vs->correct($f->both, (int)$t['id'], 'lần 2'), DomainError::class, 'điều chuyển đã đảo không đảo lần nữa');
$f->receive($f->wb, 'lot', 3);
$t2 = $ts->createDraft($f->keeperA, $f->wa, $f->wb, [$f->line('lot', 6)], '', null)['voucher'];
$ts->confirm($f->approverA, (int)$t2['id']);
$f->draftIssue($f->wb, 'x', 1); // không liên quan
$iss3 = $vs->createDraft($f->keeperB, VoucherType::Issue, $f->wb, [$f->line('lot', 8)], '', '', null)['voucher'];
$vs->confirm($f->approverB, (int)$iss3['id']);          // B còn 9 − 8 = 1 < 6
$snapshot = [$f->stock($f->wa, 'lot'), $f->stock($f->wb, 'lot')];
throws(fn() => $vs->correct($f->both, (int)$t2['id'], 'đảo'), InsufficientStock::class, 'kho đến đã dùng hàng ⇒ không đảo được');
same([$f->stock($f->wa, 'lot'), $f->stock($f->wb, 'lot')], $snapshot, 'và không có thay đổi dở dang ở kho đi');

section('Toàn bộ vẫn đối chiếu được');
$mine = array_filter($q->reconcile([$f->wa, $f->wb])['rows'], fn($r) => in_array((int)$r['mat_hang_id'], array_column($f->it, 'item'), true));
same(count(array_filter($mine, fn($r) => $r['balance'] != $r['ledger'])), 0, 'số dư = sổ phát sinh ở mọi vị trí');

section('Khoá bất biến ở CSDL (nếu đã cài trigger)');
$hasTriggers = (int)$f->db->value("SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = DATABASE() AND trigger_name = 'so_phat_sinh_khong_sua'") > 0;
if ($hasTriggers) {
    throws(fn() => $f->db->exec('UPDATE so_phat_sinh SET bien_dong = 999 WHERE phieu_nhap_xuat_id = ?', [$wrong['id']]), mysqli_sql_exception::class, 'không sửa được dòng sổ phát sinh');
    throws(fn() => $f->db->exec('DELETE FROM so_phat_sinh WHERE phieu_nhap_xuat_id = ?', [$wrong['id']]), mysqli_sql_exception::class, 'không xoá được dòng sổ phát sinh');
    throws(fn() => $f->db->exec('DELETE FROM phieu_nhap_xuat WHERE id = ?', [$wrong['id']]), mysqli_sql_exception::class, 'không xoá được phiếu nhập xuất');
    throws(fn() => $f->db->exec("UPDATE phieu_nhap_xuat SET ghi_chu = 'sửa lén' WHERE id = ?", [$wrong['id']]), mysqli_sql_exception::class, 'không sửa được phiếu nhập xuất đã xác nhận');
    throws(fn() => $f->db->exec('UPDATE chi_tiet_phieu_nhap_xuat SET so_luong = 1 WHERE phieu_nhap_xuat_id = ?', [$wrong['id']]), mysqli_sql_exception::class, 'không sửa được dòng phiếu nhập xuất');
} else {
    echo "  ⚠ chưa cài trigger bất biến (migration 002 tuỳ chọn) — bỏ qua phần này\n";
}
finish();
