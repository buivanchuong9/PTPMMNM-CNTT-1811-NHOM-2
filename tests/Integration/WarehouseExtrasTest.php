<?php
declare(strict_types=1);
// Khách trả hàng · đơn đặt hàng nhà cung cấp · nhập CSV · mã vạch SVG
require __DIR__ . '/../Support/bootstrap.php';

use StockFlow\Core\Barcode;
use StockFlow\Domain\DomainError;
use StockFlow\Domain\Forbidden;
use StockFlow\Domain\VoucherType;
use StockFlow\Service\CustomerReturnService;
use StockFlow\Service\ImportService;
use StockFlow\Service\OrderService;
use StockFlow\Service\PartnerService;
use StockFlow\Service\VoucherService;

$f = Fx::make();
$vs = $f->c->get(VoucherService::class);
$cr = $f->c->get(CustomerReturnService::class);
$orders = $f->c->get(OrderService::class);
$partners = $f->c->get(PartnerService::class);

section('Khách trả hàng: nhập lại kho, phần lỗi sang kho huỷ');
$f->receive($f->wa, 'x', 100);
$issue = $f->draftIssue($f->wa, 'x', 10);
throws(fn() => $cr->create($f->keeperA, (int)$issue['id'], [['mat_hang_id' => $f->it['x']['item'], 'lo_hang_id' => $f->it['x']['batch'], 'so_luong' => 1, 'so_luong_loi' => 0, 'ly_do_loi' => '']]), DomainError::class, 'phiếu xuất còn nháp thì chưa có hàng trả');
$vs->confirm($f->approverA, (int)$issue['id']);
same($f->stock($f->wa, 'x'), 90, 'bán 10: tồn 100 → 90');
$line = fn(int $qty, int $bad = 0, string $why = '') => [['mat_hang_id' => $f->it['x']['item'], 'lo_hang_id' => $f->it['x']['batch'], 'so_luong' => $qty, 'so_luong_loi' => $bad, 'ly_do_loi' => $why]];
throws(fn() => $cr->create($f->keeperA, (int)$issue['id'], $line(11)), DomainError::class, 'trả nhiều hơn đã bán bị từ chối');
throws(fn() => $cr->create($f->keeperA, (int)$issue['id'], $line(3, 4, 'KHO')), DomainError::class, 'số lỗi lớn hơn số trả bị từ chối');
throws(fn() => $cr->create($f->keeperA, (int)$issue['id'], $line(3, 1, '')), DomainError::class, 'có hàng lỗi thì phải chọn nguyên nhân');
throws(fn() => $cr->create($f->keeperA, (int)$issue['id'], $line(3, 1, 'VO_HONG')), DomainError::class, 'nguyên nhân ngoài danh sách bị từ chối');
throws(fn() => $cr->create($f->keeperB, (int)$issue['id'], $line(3)), Forbidden::class, 'nhân viên kho khác không lập được');
$r1 = $cr->create($f->keeperA, (int)$issue['id'], $line(4, 1, 'KHAC'), 'Khách đổi ý');
same($f->stock($f->wa, 'x'), 90, 'phiếu trả nháp chưa đụng tồn');
throws(fn() => $cr->confirm($f->keeperA, $r1), Forbidden::class, 'nhân viên không xác nhận được (cần quyền xác nhận phiếu)');
$warn = $cr->confirm($f->approverA, $r1);
same($warn, [], 'xác nhận không cảnh báo');
$t = $cr->find($r1);
same($t['trang_thai'], 'CONFIRMED', 'phiếu trả đã xác nhận');
same((int)$f->db->value("SELECT COUNT(*) FROM huy_hang WHERE ghi_chu LIKE ?", ['Khách trả hàng ' . $t['ma'] . '%']), 1, 'phần lỗi tự đề xuất huỷ');
same($f->stock($f->wa, 'x'), 93, 'nhập lại 4 rồi 1 chiếc lỗi vào kho huỷ: 90 + 4 − 1 = 93');
throws(fn() => $cr->confirm($f->approverA, $r1), DomainError::class, 'không xác nhận lần hai');
throws(fn() => $cr->create($f->keeperA, (int)$issue['id'], $line(7)), DomainError::class, 'đã trả 4, chỉ còn trả được 6');
$r2 = $cr->create($f->keeperA, (int)$issue['id'], $line(6));
$cr->cancel($f->keeperA, $r2);
same($f->stock($f->wa, 'x'), 93, 'huỷ phiếu trả nháp không đổi tồn');
$r3 = $cr->create($f->keeperA, (int)$issue['id'], $line(6));
$cr->confirm($f->approverA, $r3);
same($f->stock($f->wa, 'x'), 99, 'trả nốt 6: 99');
throws(fn() => $cr->create($f->keeperA, (int)$issue['id'], $line(1)), DomainError::class, 'đã trả hết số đã bán');

section('Khách trả hàng: lỗi do ai → hàng đi đâu, ai chịu tiền, hoàn bao nhiêu');
$f->db->exec('UPDATE mat_hang SET gia_ban = 100000, thue_suat = 10, gia_von = 60000 WHERE id = ?', [$f->it['x']['item']]);
$f->db->exec('REPLACE INTO gia_ban_kho (kho_id, mat_hang_id, gia_ban) VALUES (?,?,100000)', [$f->wa, $f->it['x']['item']]);
$issue2 = $f->draftIssue($f->wa, 'x', 10);
$vs->confirm($f->approverA, (int)$issue2['id']);
$before = $f->stock($f->wa, 'x');
$mix = fn(array $over = []) => [
    $over + ['mat_hang_id' => $f->it['x']['item'], 'lo_hang_id' => $f->it['x']['batch'], 'so_luong' => 6, 'so_luong_loi' => 2, 'ly_do_loi' => 'KHACH'],
];
throws(fn() => $cr->create($f->keeperA, (int)$issue2['id'], $mix(['tien_tru_khach' => 999999999])), DomainError::class, 'tiền trừ khách không vượt giá hàng hỏng');
throws(fn() => $cr->create($f->keeperA, (int)$issue2['id'], $mix(['ly_do_loi' => 'KHO', 'nguoi_chiu_id' => $f->keeperB->id])), DomainError::class, 'người chịu phải là nhân viên của kho này');
throws(fn() => $cr->create($f->keeperA, (int)$issue2['id'], $mix(), '', 'BITCOIN'), DomainError::class, 'hình thức hoàn tiền không hợp lệ');
$xid = $f->it['x']['item'];
$xlot = $f->it['x']['batch'];
$rr = $cr->create($f->keeperA, (int)$issue2['id'], [
    ['mat_hang_id' => $xid, 'lo_hang_id' => $xlot, 'so_luong' => 2, 'so_luong_loi' => 2, 'ly_do_loi' => 'KHACH'],
], 'Khách làm rơi', 'CHUYEN_KHOAN');
$t = $cr->find($rr);
same((int)$t['tien_hoan'], 0, 'khách tự làm hỏng cả 2 chiếc: trừ đủ 2 × 110.000, hoàn 0');
same((int)$t['deducted'], 220000, 'tiền trừ khách = giá hàng hỏng (đã gồm thuế)');
$cr->cancel($f->keeperA, $rr);
$rm = $cr->create($f->keeperA, (int)$issue2['id'], [
    ['mat_hang_id' => $xid, 'lo_hang_id' => $xlot, 'so_luong' => 6, 'so_luong_loi' => 2, 'ly_do_loi' => 'KHACH', 'tien_tru_khach' => 110000],
], 'Trả 6', 'TIEN_MAT');
$t = $cr->find($rm);
same((int)$t['tien_hoan'], 6 * 110000 - 110000, 'trừ khách 110.000 theo thoả thuận: hoàn 550.000');
$cr->cancel($f->keeperA, $rm);
$rx = $cr->create($f->keeperA, (int)$issue2['id'], [
    ['mat_hang_id' => $xid, 'lo_hang_id' => $xlot, 'so_luong' => 6, 'so_luong_loi' => 2, 'ly_do_loi' => 'KHACH'],
    ['mat_hang_id' => $xid, 'lo_hang_id' => $xlot, 'so_luong' => 1, 'so_luong_loi' => 1, 'ly_do_loi' => 'NHA_SX'],
    ['mat_hang_id' => $xid, 'lo_hang_id' => $xlot, 'so_luong' => 1, 'so_luong_loi' => 1, 'ly_do_loi' => 'KHO', 'nguoi_chiu_id' => $f->keeperA->id],
], 'Nhiều lỗi', 'TRU_CONG_NO');
$t = $cr->find($rx);
same((int)$t['tien_hoan'], 8 * 110000 - 220000, '8 chiếc trả, khách làm hỏng 2: hoàn 660.000 (lỗi NSX và kho vẫn hoàn đủ)');
same((int)$t['compensation'], 60000, 'lỗi do kho: nhân viên đền 1 × giá vốn 60.000');
same($f->stock($f->wa, 'x'), $before, 'phiếu nháp chưa đụng tồn');
$cr->confirm($f->approverA, $rx);
$t = $cr->find($rx);
same($f->stock($f->wa, 'x'), $before + 7 - 3, 'nhập lại 7 (trừ 1 lỗi NSX) rồi huỷ 3 chiếc hỏng (2 khách + 1 kho)');
$nsx = array_values(array_filter($t['lines'], fn($l) => $l['ly_do_loi'] === 'NHA_SX'))[0];
truthy($nsx['warranty_code'] !== null, 'lỗi nhà sản xuất mở phiếu kho bảo hành');
same((int)$f->db->value('SELECT so_luong FROM bao_hanh WHERE id = ?', [(int)$nsx['bao_hanh_id']]), 1, 'phiếu bảo hành 1 chiếc');
$row = $f->c->get(\StockFlow\Service\VoucherRepository::class)->find((int)$issue2['id']);
$det = $cr->forIssueDetail((int)$issue2['id']);
$sum = fn(string $k) => array_sum(array_map(fn($r) => array_sum(array_column($r['lines'], $k)), array_filter($det, fn($r) => $r['trang_thai'] === 'CONFIRMED')));
same([$sum('good'), $sum('scrap'), $sum('warranty')], [4, 3, 1], 'danh sách hoàn trả: nhập lại 4, huỷ 3, bảo hành 1');
truthy(str_contains(voucher_status_tag($row), 'Hoàn trả một phần'), 'phiếu xuất đã có hàng trả nhưng chưa hết: «Hoàn trả một phần»');
same((int)$f->db->value("SELECT COUNT(*) FROM huy_hang WHERE ghi_chu LIKE ?", ['Khách trả hàng ' . $t['ma'] . '%']), 2, 'hàng hỏng do khách và do kho sang kho huỷ (2 phiếu huỷ), lỗi NSX thì không');

section('Đơn đặt hàng nhà cung cấp: chỉ quản lý lập, nhập hàng tự cập nhật số đã nhận');
$sup = $partners->add($f->admin, 'NCC' . $f->tag, 'Nhà cung cấp ' . $f->tag, 'SUPPLIER', '', '', '', '', '');
$partners->setPartnerItems($f->admin, $sup, [$f->it['x']['item']]);
$supName = 'Nhà cung cấp ' . $f->tag;
$ordLines = fn(string $k, int $qty) => [['mat_hang_id' => $f->it[$k]['item'], 'so_luong' => $qty]];
throws(fn() => $orders->create($f->keeperA, $f->wa, $supName, $ordLines('x', 50), null, ''), Forbidden::class, 'nhân viên không lập được đơn đặt hàng');
throws(fn() => $orders->create($f->admin, $f->wa, $supName, $ordLines('x', 50), null, ''), Forbidden::class, 'quản trị chỉ xem, không lập đơn');
throws(fn() => $orders->create($f->approverA, $f->wb, $supName, $ordLines('x', 50), null, ''), Forbidden::class, 'quản lý không lập đơn cho kho khác');
throws(fn() => $orders->create($f->approverA, $f->wa, $supName, $ordLines('y', 5), null, ''), DomainError::class, 'hàng không thuộc nhà cung cấp bị từ chối');
throws(fn() => $orders->create($f->approverA, $f->wa, $supName, [], null, ''), DomainError::class, 'đơn không có mặt hàng bị từ chối');
$po = $orders->create($f->approverA, $f->wa, $supName, $ordLines('x', 50), null, 'Giao trong tuần');
same($orders->find($po)['trang_thai'], 'NHAP', 'đơn mới là nháp');
$recv = fn(int $qty) => $vs->createDraft($f->keeperA, VoucherType::Receipt, $f->wa, [$f->line('x', $qty)], $supName, '', null, [], null, $po)['voucher'];
throws(fn() => $recv(10), DomainError::class, 'đơn còn nháp thì chưa nhận hàng được');
throws(fn() => $orders->markSent($f->keeperA, $po), Forbidden::class, 'nhân viên không đánh dấu đã gửi được');
$orders->markSent($f->approverA, $po);
same($orders->find($po)['trang_thai'], 'DA_GUI', 'đã gửi nhà cung cấp');
throws(fn() => $vs->createDraft($f->keeperA, VoucherType::Receipt, $f->wa, [$f->line('x', 5)], 'NCC khác', '', null, [], null, $po), DomainError::class, 'sai nhà cung cấp bị từ chối');
throws(fn() => $vs->createDraft($f->keeperA, VoucherType::Receipt, $f->wa, [$f->line('y', 5)], $supName, '', null, [], null, $po), DomainError::class, 'hàng ngoài đơn bị từ chối');
$stock0 = $f->stock($f->wa, 'x');
$v1 = $recv(30);
same((int)$f->db->value('SELECT don_dat_hang_id FROM phieu_nhap_xuat WHERE id = ?', [$v1['id']]), $po, 'phiếu nhập gắn với đơn');
same($orders->find($po)['trang_thai'], 'DA_GUI', 'phiếu nháp chưa tính là đã nhận');
$vs->confirm($f->approverA, (int)$v1['id']);
$o = $orders->find($po);
same([$o['trang_thai'], (int)$o['lines'][0]['so_luong_da_nhan']], ['NHAN_MOT_PHAN', 30], 'nhận 30/50 ⇒ nhận một phần');
same($f->stock($f->wa, 'x'), $stock0 + 30, 'tồn tăng 30');
same($orders->outstanding($po), [$f->it['x']['item'] => 20], 'còn thiếu 20');
$v2 = $recv(20);
$vs->confirm($f->approverA, (int)$v2['id']);
same($orders->find($po)['trang_thai'], 'DA_NHAN', 'nhận đủ 50/50 ⇒ đã nhận đủ');
throws(fn() => $recv(1), DomainError::class, 'đơn đã nhận đủ không nhận thêm');
$po2 = $orders->create($f->approverA, $f->wa, $supName, $ordLines('x', 10), null, '');
$orders->close($f->approverA, $po2);
same($orders->find($po2)['trang_thai'], 'HUY', 'đơn nháp: «đóng» là huỷ');
throws(fn() => $orders->markSent($f->approverA, $po2), DomainError::class, 'đơn đã huỷ không gửi được');
$po3 = $orders->create($f->approverA, $f->wa, $supName, $ordLines('x', 10), null, '');
$orders->markSent($f->approverA, $po3);
$vs->confirm($f->approverA, (int)$vs->createDraft($f->keeperA, VoucherType::Receipt, $f->wa, [$f->line('x', 4)], $supName, '', null, [], null, $po3)['voucher']['id']);
$orders->close($f->approverA, $po3);
same($orders->find($po3)['trang_thai'], 'DA_DONG', 'đơn nhận một phần: «đóng» = không nhận thêm');
truthy($f->c->get(StockFlow\Service\Authorizer::class)->allows($f->admin, StockFlow\Domain\Permission::XemDonDatHang), 'quản trị xem được đơn đặt hàng');
truthy(!$f->c->get(StockFlow\Service\Authorizer::class)->allows($f->keeperA, StockFlow\Domain\Permission::XemDonDatHang), 'nhân viên không có chức năng đơn đặt hàng');

section('Nhập CSV: danh mục mặt hàng và tồn đầu kỳ');
$imp = $f->c->get(ImportService::class);
$unitCode = (string)$f->db->value('SELECT ma FROM don_vi_tinh ORDER BY id DESC LIMIT 1');
$csv = tempnam(sys_get_temp_dir(), 'imp');
file_put_contents($csv, "\xEF\xBB\xBF" . implode("\n", [
    'SKU;Tên hàng;Đơn vị tính;Loại hàng;Theo lô (có/không);Tồn tối thiểu;Quy cách (đơn vị/kiện);Bảo hành (tháng);Mã vạch',
    "IMP1-{$f->tag};Hàng nhập file 1;{$unitCode};;không;5;10;12;",
    "IMP2-{$f->tag};Hàng nhập file 2;{$unitCode};;có;0;1;0;",
    "IMP1-{$f->tag};Trùng SKU;{$unitCode};;không;0;1;0;",
    "X;Sai SKU;{$unitCode};;không;0;1;0;",
    "IMP3-{$f->tag};Sai đơn vị;khongco;;không;0;1;0;",
    "IMP4-{$f->tag};Số âm;{$unitCode};;không;-1;1;0;",
]) . "\n");
$rows = $imp->previewItems($csv);
same(count($rows), 6, 'đọc đủ 6 dòng (dấu chấm phẩy, có BOM)');
same(array_map(fn($r) => $r['error'] === null, $rows), [true, true, false, false, false, false], 'chỉ hai dòng đầu hợp lệ; trùng SKU, SKU sai, đơn vị lạ, số âm bị báo lỗi');
$valid = array_values(array_map(fn($r) => $r['data'], array_filter($rows, fn($r) => $r['error'] === null)));
throws(fn() => $imp->applyItems($f->approverA, $valid), Forbidden::class, 'quản lý không nhập được danh mục mặt hàng (chỉ quản trị)');
$res = $imp->applyItems($f->admin, $valid);
same([$res['ok'], $res['fail']], [2, []], 'quản trị thêm 2 mặt hàng');
same([(int)$f->db->value('SELECT quan_ly_lo FROM mat_hang WHERE ma_hang = ?', ["IMP2-{$f->tag}"]), (int)$f->db->value('SELECT bao_hanh_thang FROM mat_hang WHERE ma_hang = ?', ["IMP1-{$f->tag}"])], [1, 12], 'theo lô và bảo hành lưu đúng');
same(array_column($imp->previewItems($csv), 'error')[0] !== null, true, 'nhập lại cùng file: SKU đã có bị báo lỗi');

$csv2 = tempnam(sys_get_temp_dir(), 'imp');
file_put_contents($csv2, implode("\n", [
    'SKU,Số lô,Hạn dùng (dd/mm/yyyy),Số lượng',
    "{$f->it['x']['ma_hang']},,,25",
    "IMP2-{$f->tag},LOT9,31/12/2031,40",
    "IMP2-{$f->tag},,,5",
    "KHONGCO,,,1",
    "{$f->it['x']['ma_hang']},,,0",
]) . "\n");
$st = $imp->previewStock($csv2);
same(array_map(fn($r) => $r['error'] === null, $st), [true, true, false, false, false], 'dòng hàng theo lô thiếu số lô, SKU lạ, số lượng 0 bị báo lỗi');
$before = $f->stock($f->wa, 'x');
$vid = $imp->applyStock($f->approverA, $f->wa, array_values(array_map(fn($r) => $r['data'], array_filter($st, fn($r) => $r['error'] === null))), '');
$vv = $f->c->get(StockFlow\Service\VoucherRepository::class)->find($vid);
same([$vv['loai'], $vv['trang_thai'], $vv['doi_tac']], ['RECEIPT', 'DRAFT', 'Tồn đầu kỳ'], 'tạo một phiếu nhập nháp «Tồn đầu kỳ»');
same($f->stock($f->wa, 'x'), $before, 'phiếu nháp chưa cộng tồn');
$vs->confirm($f->approverA, $vid);
same($f->stock($f->wa, 'x'), $before + 25, 'xác nhận xong tồn tăng 25');
same((int)$f->db->value('SELECT SUM(bien_dong) FROM so_phat_sinh WHERE phieu_nhap_xuat_id = ?', [$vid]), 65, 'tổng nhập của phiếu: 25 + 40');
@unlink($csv);
@unlink($csv2);

section('Mã vạch SVG');
truthy(Barcode::isEan13('4006381333931') && !Barcode::isEan13('4006381333932') && !Barcode::isEan13('123'), 'nhận biết EAN-13 đúng / sai số kiểm tra');
same(strlen(Barcode::ean13Bits('4006381333931')), 95, 'EAN-13 dài 95 mô-đun');
same(strlen(Barcode::code128Bits('BOX-S')), 90, 'Code 128 của «BOX-S» dài 90 mô-đun (bắt đầu + 5 ký tự + kiểm tra = 7 × 11, cộng 13 của mã dừng)');
truthy(str_starts_with(Barcode::svg('4006381333931'), '<svg') && str_contains(Barcode::svg('BOX-S'), '<rect'), 'sinh được thẻ SVG cho cả hai loại');
truthy(!str_contains(Barcode::svg('<script>'), '<script>'), 'nội dung mã được escape trong SVG');

finish();
