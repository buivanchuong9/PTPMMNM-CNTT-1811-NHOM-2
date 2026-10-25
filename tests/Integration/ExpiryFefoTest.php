<?php
declare(strict_types=1);
// Hạn dùng: lô bắt buộc có hạn · xuất / điều chuyển đi theo hạn dùng (hết hạn trước, xuất trước) · lô quá hạn không xuất · cảnh báo hàng sắp hết hạn.
require __DIR__ . '/../Support/bootstrap.php';

use StockFlow\Domain\DomainError;
use StockFlow\Domain\VoucherType;
use StockFlow\Service\CatalogService;
use StockFlow\Service\NotificationService;
use StockFlow\Service\StockQuery;
use StockFlow\Service\TransferService;
use StockFlow\Service\VoucherService;

$f = Fx::make();
$cat = $f->c->get(CatalogService::class);
$vs = $f->c->get(VoucherService::class);
$q = $f->c->get(StockQuery::class);
$item = $f->it['lot']['item'];
$days = fn(int $n) => gmdate('Y-m-d', time() + 7 * 3600 + $n * 86400);

section('Lô quản lý theo lô bắt buộc có hạn dùng');
throws(fn() => $cat->addBatch($f->admin, $item, 'NOEXP', ''), DomainError::class, 'thêm lô không có hạn dùng bị từ chối');
$soon = $cat->addBatch($f->admin, $item, 'SOON', $days(10));
$late = $cat->addBatch($f->admin, $item, 'LATE', $days(400));
$gone = $cat->addBatch($f->admin, $item, 'GONE', $days(-5));
$line = fn(int $batch, int $qty) => ['mat_hang_id' => $item, 'lo_hang_id' => $batch, 'so_luong' => $qty];
$receive = function (int $batch, int $qty) use ($f, $vs) {
    $v = $vs->createDraft($f->keeperA, VoucherType::Receipt, $f->wa, [['mat_hang_id' => $GLOBALS['item'], 'lo_hang_id' => $batch, 'so_luong' => $qty]], 'NCC', '', null)['voucher'];
    $vs->confirm($f->approverA, (int)$v['id']);
};
$GLOBALS['item'] = $item;
$receive($soon, 10);
$receive($late, 40);
$receive($gone, 6);

section('Trạng thái hạn dùng');
same(StockQuery::expiryState($days(10))['status'], 'soon', 'còn 10 ngày ⇒ sắp hết hạn');
same(StockQuery::expiryState($days(400))['status'], 'ok', 'còn 400 ngày ⇒ còn hạn');
same(StockQuery::expiryState($days(-5))['status'], 'expired', 'quá hạn 5 ngày ⇒ hết hạn');
same(StockQuery::expiryState(null)['status'], 'none', 'không ghi hạn');

section('Xuất theo hạn: lô hết hạn trước phải xuất trước');
$issue = fn(int $batch, int $qty) => $vs->createDraft($f->keeperA, VoucherType::Issue, $f->wa, [$line($batch, $qty)], 'KH', '', null)['voucher'];
$v = $issue($late, 5);
throws(fn() => $vs->confirm($f->approverA, (int)$v['id']), DomainError::class, 'xuất lô hạn xa khi lô hạn gần còn hàng ⇒ bị chặn');
same(count($q->fefoErrors($f->wa, [$line($late, 5)])), 1, 'kiểm tra sớm cũng báo đúng một lỗi');
same($f->stock($f->wa, 'lot') >= 0, true, 'chưa ghi sổ gì');
$v2 = $issue($soon, 4);
$vs->confirm($f->approverA, (int)$v2['id']);
same(count($q->fefoErrors($f->wa, [$line($late, 5)])), 1, 'lô SOON còn 6 ⇒ vẫn phải xuất SOON trước');
same(count($q->fefoErrors($f->wa, [$line($soon, 6), $line($late, 5)])), 0, 'lấy hết lô SOON trong cùng phiếu thì xuất thêm lô sau được');

section('Lô đã quá hạn không được xuất đi');
$v3 = $issue($gone, 1);
throws(fn() => $vs->confirm($f->approverA, (int)$v3['id']), DomainError::class, 'xuất lô quá hạn bị chặn');
$t = $f->c->get(TransferService::class)->createDraft($f->keeperA, $f->wa, $f->wb, [$line($late, 5)], '', null)['voucher'];
throws(fn() => $f->c->get(TransferService::class)->confirm($f->approverA, (int)$t['id']), DomainError::class, 'điều chuyển đi cũng phải theo hạn dùng');

section('Điều chuyển đi đúng lô hết hạn trước thì được, hạn dùng đi theo lô sang kho đến');
$t2 = $f->c->get(TransferService::class)->createDraft($f->keeperA, $f->wa, $f->wb, [$line($soon, 6)], '', null)['voucher'];
$f->c->get(TransferService::class)->confirm($f->approverA, (int)$t2['id']);
$f->arrive((int)$t2['id']);
same((string)$f->db->value('SELECT han_dung FROM lo_hang WHERE id = ?', [$soon]), $days(10), 'lô giữ nguyên hạn dùng ở kho đến');
same($f->stock($f->wb, 'lot') >= 0 && (int)$f->db->value('SELECT COALESCE(SUM(so_luong),0) FROM ton_kho WHERE kho_id = ? AND lo_hang_id = ?', [$f->wb, $soon]) === 6, true, 'kho B nhận 6 của lô SOON kèm hạn');

section('Cảnh báo hàng đã / sắp hết hạn');
$exp = $q->expiring([$f->wa, $f->wb]);
$byLot = array_column($exp, 'status', 'lo_hang_id');
same($byLot[$gone] ?? null, 'expired', 'lô GONE còn tồn ⇒ hết hạn');
same($byLot[$soon] ?? null, 'soon', 'lô SOON còn tồn ở kho B ⇒ sắp hết hạn');
same(isset($byLot[$late]), false, 'lô LATE còn xa hạn ⇒ không cảnh báo');
$notes = array_column($f->c->get(NotificationService::class)->for($f->approverA), 'title');
same(in_array('Hàng đã hết hạn', $notes, true), true, 'quản lý kho A có thông báo hàng đã hết hạn');
$notesB = array_column($f->c->get(NotificationService::class)->for($f->approverB), 'title');
same(in_array('Hàng sắp hết hạn trong 30 ngày tới', $notesB, true), true, 'quản lý kho B có thông báo hàng sắp hết hạn');

section('Phiếu nhập: gõ số lô + hạn dùng, lô được tạo / dùng lại');
$cs = $f->c->get(CatalogService::class);
$n1 = $cs->ensureReceiptLot($f->keeperA, $item, 'new-1', $days(200));
same((string)$f->db->value('SELECT so_lo FROM lo_hang WHERE id = ?', [$n1]), 'NEW-1', 'lô mới được tạo (số lô viết hoa)');
same((string)$f->db->value('SELECT han_dung FROM lo_hang WHERE id = ?', [$n1]), $days(200), 'lô mang hạn dùng đã nhập');
same($cs->ensureReceiptLot($f->keeperA, $item, 'NEW-1', $days(200)), $n1, 'cùng số lô, cùng hạn ⇒ dùng lại lô cũ');
throws(fn() => $cs->ensureReceiptLot($f->keeperA, $item, 'NEW-1', $days(201)), DomainError::class, 'cùng số lô khác hạn ⇒ báo lỗi');
throws(fn() => $cs->ensureReceiptLot($f->keeperA, $item, 'NEW-2', ''), DomainError::class, 'thiếu hạn dùng ⇒ báo lỗi');
throws(fn() => $cs->ensureReceiptLot($f->keeperA, $item, '', $days(30)), DomainError::class, 'thiếu số lô ⇒ báo lỗi');
throws(fn() => $cs->ensureReceiptLot($f->keeperA, $item, 'OLD-1', $days(-1)), DomainError::class, 'hạn dùng đã qua ⇒ không nhập');
throws(fn() => $cs->ensureReceiptLot($f->keeperA, $f->it['x']['item'], 'ABC', $days(30)), DomainError::class, 'mặt hàng không theo lô ⇒ không có lô');

section('Xuất / điều chuyển: nhập tổng số lượng, hệ thống tự chia lô hết hạn trước');
$f->receive($f->wb, 'lot', 1);   // nền cho kho B (không ảnh hưởng)
[$ks, $qs] = $q->expandAutoLots($f->wa, [$item . ':auto'], ['12']);
$take = [];
foreach ($ks as $i => $k) {
    $take[(int)explode(':', $k)[1]] = (int)$qs[$i];
}
$onHandA = fn(int $lot) => (int)$f->db->value('SELECT COALESCE(SUM(so_luong),0) FROM ton_kho WHERE kho_id = ? AND lo_hang_id = ?', [$f->wa, $lot]);
same(isset($take[$gone]), false, 'lô quá hạn không được chia vào');
same(array_sum($take), 12, 'tổng số lượng chia đúng 12');
$firstLot = (int)array_key_first($take);
same($firstLot === $n1 || $firstLot === $late || $firstLot === $soon, true, 'lấy từ lô có hạn gần nhất còn tồn trước');
same(count($q->fefoErrors($f->wa, array_map(fn($k, $qty) => ['mat_hang_id' => $item, 'lo_hang_id' => (int)explode(':', $k)[1], 'so_luong' => (int)$qty], $ks, $qs))), 0, 'kết quả chia lô luôn đúng thứ tự hết hạn trước');
throws(fn() => $q->expandAutoLots($f->wa, [$item . ':auto'], ['999999']), DomainError::class, 'nhiều hơn tồn dùng được ⇒ báo lỗi');
throws(fn() => $q->expandAutoLots($f->wa, [$item . ':auto'], ['0']), DomainError::class, 'số lượng không hợp lệ ⇒ báo lỗi');
[$ks2, $qs2] = $q->expandAutoLots($f->wa, [$item . ':auto', $item . ':auto'], ['3', '4']);
same(array_sum(array_map('intval', $qs2)), 7, 'hai dòng cùng mặt hàng được cộng lại rồi chia');
