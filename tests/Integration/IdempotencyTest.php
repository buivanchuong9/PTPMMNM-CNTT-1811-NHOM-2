<?php
declare(strict_types=1);
// Phép thử 3: gửi lại phiếu nhập xuất đã xác nhận ⇒ số tồn và sổ phát sinh không tăng thêm.
require __DIR__ . '/../Support/bootstrap.php';

use StockFlow\Domain\DomainError;
use StockFlow\Domain\VoucherType;
use StockFlow\Service\StockCountService;
use StockFlow\Service\TransferService;
use StockFlow\Service\VoucherService;

$f = Fx::make();
$vs = $f->c->get(VoucherService::class);
$ts = $f->c->get(TransferService::class);
$cs = $f->c->get(StockCountService::class);

section('Xác nhận phiếu nhập hai lần');
$v = $vs->createDraft($f->keeperA, VoucherType::Receipt, $f->wa, [$f->line('x', 12), $f->line('y', 3)], 'NCC', '', null)['voucher'];
$r1 = $vs->confirm($f->approverA, (int)$v['id']);
$m = $f->movements($f->wa);
$r2 = $vs->confirm($f->approverA, (int)$v['id']);
$r3 = $vs->confirm($f->approverA, (int)$v['id']);
same([$r1['already'], $r2['already'], $r3['already']], [false, true, true], 'lần đầu ghi sổ; các lần sau chỉ báo "đã xác nhận"');
same($f->movements($f->wa), $m, 'sổ phát sinh không tăng thêm');
same($f->stock($f->wa, 'x'), 12, 'tồn không đổi (12, không phải 24 hay 36)');
same($f->balance($f->wa, 'x'), 12, 'số dư không đổi');

section('Gửi lại biểu mẫu tạo phiếu (cùng khoá idempotency)');
$key = bin2hex(random_bytes(16));
$a = $vs->createDraft($f->keeperA, VoucherType::Issue, $f->wa, [$f->line('x', 2)], '', '', $key);
$b = $vs->createDraft($f->keeperA, VoucherType::Issue, $f->wa, [$f->line('x', 2)], '', '', $key);
same([$a['created'], $b['created']], [true, false], 'lần hai không tạo phiếu mới');
same($a['voucher']['id'], $b['voucher']['id'], 'trả về đúng phiếu đã có');
same((int)$f->db->value('SELECT COUNT(*) FROM phieu_nhap_xuat WHERE khoa_chong_trung = ?', [$key]), 1, 'chỉ một phiếu nhập xuất trong CSDL');
$tkey = bin2hex(random_bytes(16));
$t1 = $ts->createDraft($f->keeperA, $f->wa, $f->wb, [$f->line('x', 1)], '', $tkey);
$t2 = $ts->createDraft($f->keeperA, $f->wa, $f->wb, [$f->line('x', 1)], '', $tkey);
same([$t1['created'], $t2['created'], $t1['voucher']['id'] === $t2['voucher']['id']], [true, false, true], 'phiếu điều chuyển cũng idempotent');
same((int)$f->db->value('SELECT COUNT(*) FROM chi_tiet_phieu_nhap_xuat WHERE phieu_nhap_xuat_id = ?', [$a['voucher']['id']]), 1, 'không nhân đôi dòng hàng');

section('Xác nhận điều chuyển hai lần');
$ts->confirm($f->approverA, (int)$t1['voucher']['id']);
$sa = [$f->stock($f->wa, 'x'), $f->stock($f->wb, 'x'), $f->movements($f->wa), $f->movements($f->wb)];
$again = $ts->confirm($f->approverA, (int)$t1['voucher']['id']);
same($again['already'], true, 'lần hai: đã xác nhận');
same([$f->stock($f->wa, 'x'), $f->stock($f->wb, 'x'), $f->movements($f->wa), $f->movements($f->wb)], $sa, 'tồn và sổ cả hai kho không đổi');
same((int)$f->db->value("SELECT COUNT(*) FROM phieu_nhap_xuat WHERE loai = 'TRANSFER_IN' AND phieu_nhap_xuat_lien_quan_id = ?", [$t1['voucher']['id']]), 1, 'chỉ một phiếu nhập xuất nơi đến');

section('Chốt kiểm kê hai lần');
$cid = $cs->open($f->keeperA, $f->wa, '');
$lines = $cs->find($cid)['lines'];
$counted = [];
$reasons = [];
foreach ($lines as $l) {
    $key = $l['mat_hang_id'] . ':' . $l['lo_hang_id'];
    $counted[$key] = (int)$l['so_luong_so_sach'] + ($l['mat_hang_id'] === $f->it['x']['item'] ? 2 : 0);
    if ($l['mat_hang_id'] === $f->it['x']['item']) {
        $reasons[$key] = 'Chưa ghi phiếu nhập xuất';
    }
}
$cs->submit($f->keeperA, $cid, $counted, $reasons);
$ra = $cs->approve($f->approverA, $cid);
$mv = $f->movements($f->wa);
$rb = $cs->approve($f->approverA, $cid);
same([$ra['already'], $rb['already']], [false, true], 'lần hai báo đã xác nhận');
same($f->movements($f->wa), $mv, 'không lập thêm phiếu nhập xuất điều chỉnh / dòng sổ');
same((int)$f->db->value("SELECT COUNT(*) FROM phieu_nhap_xuat WHERE loai = 'ADJUSTMENT' AND kho_id = ?", [$f->wa]), 1, 'chỉ một phiếu nhập xuất điều chỉnh');

section('Ràng buộc CSDL là lớp bảo vệ cuối cùng');
$lineId = (int)$f->db->value('SELECT id FROM chi_tiet_phieu_nhap_xuat WHERE phieu_nhap_xuat_id = ? LIMIT 1', [$v['id']]);
throws(fn() => $f->db->exec('INSERT INTO so_phat_sinh (phieu_nhap_xuat_id, chi_tiet_phieu_nhap_xuat_id, kho_id, mat_hang_id, lo_hang_id, bien_dong, ton_sau) VALUES (?,?,?,?,?,?,?)',
    [$v['id'], $lineId, $f->wa, $f->it['x']['item'], $f->it['x']['batch'], 12, 24]), mysqli_sql_exception::class, 'UNIQUE(voucher_line_id): một dòng phiếu nhập xuất không thể ghi sổ hai lần');
throws(fn() => $f->db->exec('UPDATE ton_kho SET so_luong = -1 WHERE kho_id = ? AND mat_hang_id = ?', [$f->wa, $f->it['x']['item']]), mysqli_sql_exception::class, 'CHECK(quantity >= 0): tồn âm bị CSDL chặn');
finish();
