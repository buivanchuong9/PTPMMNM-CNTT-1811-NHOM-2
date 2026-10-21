<?php
declare(strict_types=1);
// Phép thử 1: kho còn 5, hai yêu cầu cùng xuất 4 ⇒ chỉ một phiếu được ghi nhận, tồn không âm.
require __DIR__ . '/../Support/bootstrap.php';

use StockFlow\Service\VoucherService;

$f = Fx::make();
$f->receive($f->wa, 'x', 5);

section('Hai phiếu xuất 4 cùng lúc (2 tiến trình, 2 kết nối) khi tồn = 5');
$v1 = $f->draftIssue($f->wa, 'x', 4);
$v2 = $f->draftIssue($f->wa, 'x', 4);
$res = runParallel([
    ['voucher' => $v1['id'], 'user' => $f->approverA->id, 'sleepMs' => 350],
    ['voucher' => $v2['id'], 'user' => $f->approverA->id, 'sleepMs' => 350],
]);
$status = array_column($res, 'status');
sort($status);
same($status, ['insufficient', 'ok'], 'đúng một yêu cầu thành công, một bị từ chối vì thiếu tồn');
same($f->stock($f->wa, 'x'), 1, 'tồn (tính từ sổ) = 5 − 4 = 1, không âm');
same($f->balance($f->wa, 'x'), 1, 'số dư khớp sổ phát sinh');
same((int)$f->db->value("SELECT COUNT(*) FROM chung_tu WHERE id IN (?,?) AND status = 'CONFIRMED'", [$v1['id'], $v2['id']]), 1, 'chỉ một phiếu xuất ở trạng thái đã xác nhận');
same((int)$f->db->value("SELECT COUNT(*) FROM chung_tu WHERE id IN (?,?) AND status = 'DRAFT'", [$v1['id'], $v2['id']]), 1, 'phiếu thua vẫn là nháp (không ghi dở dang)');
same((int)$f->db->value('SELECT COUNT(*) FROM so_phat_sinh WHERE voucher_id IN (?,?)', [$v1['id'], $v2['id']]), 1, 'sổ phát sinh chỉ có một dòng xuất');
$msg = implode(' ', array_column($res, 'msg'));
truthy(str_contains($msg, 'hiện có 1') && str_contains($msg, 'cần 4'), 'thông báo lỗi nêu rõ tồn hiện có và số cần');

section('Nhiều lượt lặp lại: không lần nào tồn âm');
$f->receive($f->wa, 'y', 10);
$ok = 0;
for ($round = 0; $round < 3; $round++) {
    $a = $f->draftIssue($f->wa, 'y', 6);
    $b = $f->draftIssue($f->wa, 'y', 6);
    $r = runParallel([['voucher' => $a['id'], 'user' => $f->approverA->id], ['voucher' => $b['id'], 'user' => $f->approverA->id]]);
    $ok += count(array_filter($r, fn($x) => $x['status'] === 'ok'));
    truthy($f->stock($f->wa, 'y') >= 0, "vòng $round: tồn không âm");
    $f->receive($f->wa, 'y', 6 * (count(array_filter($r, fn($x) => $x['status'] === 'ok'))) ); // bù lại để vòng sau lại cạnh tranh
}
truthy($ok === 3, 'mỗi vòng chỉ một yêu cầu thành công (tồn 10 chỉ đủ cho một phiếu 6)');

section('Hai tiến trình cùng xác nhận MỘT phiếu (bấm đúp / mạng lặp)');
$f->receive($f->wb, 'x', 20);
$v = $f->draftIssue($f->wb, 'x', 7);
$res = runParallel([['voucher' => $v['id'], 'user' => $f->approverB->id, 'sleepMs' => 250], ['voucher' => $v['id'], 'user' => $f->approverB->id, 'sleepMs' => 250]]);
$status = array_column($res, 'status');
sort($status);
same($status, ['already', 'ok'], 'một tiến trình ghi sổ, tiến trình kia nhận "đã xác nhận"');
same($f->stock($f->wb, 'x'), 13, 'chỉ trừ một lần: 20 − 7 = 13');
same((int)$f->db->value('SELECT COUNT(*) FROM so_phat_sinh WHERE voucher_id = ?', [$v['id']]), 1, 'đúng một dòng sổ cho phiếu');
finish();
