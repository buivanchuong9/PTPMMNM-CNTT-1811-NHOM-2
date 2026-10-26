<?php
declare(strict_types=1);
// Mã vạch mặt hàng: quản lý kho gán / đổi / gỡ, không trùng giữa các mặt hàng, admin chỉ xem; mã lạ ⇒ quản lý tạo mặt hàng mới.
require __DIR__ . '/../Support/bootstrap.php';

use StockFlow\Domain\DomainError;
use StockFlow\Domain\Forbidden;
use StockFlow\Service\CatalogService;

$f = Fx::make();
$cat = $f->c->get(CatalogService::class);
$x = $f->it['x']['item'];
$y = $f->it['y']['item'];
$code = '893' . random_int(1000000000, 9999999999);
$barcode = fn(int $id) => $f->db->value('SELECT ma_vach FROM mat_hang WHERE id = ?', [$id]);

section('Gán mã vạch: chỉ quản lý kho');
throws(fn() => $cat->setBarcode($f->keeperA, $x, $code), Forbidden::class, 'nhân viên kho không gán được mã vạch');
throws(fn() => $cat->setBarcode($f->admin, $x, $code), Forbidden::class, 'quản trị chỉ xem, không gán mã vạch');
throws(fn() => $cat->setBarcode($f->approverA, $x, 'ab c'), DomainError::class, 'mã có dấu cách bị từ chối');
throws(fn() => $cat->setBarcode($f->approverA, $x, '12'), DomainError::class, 'mã quá ngắn bị từ chối');
$cat->setBarcode($f->approverA, $x, $code);
same($barcode($x), $code, 'quản lý kho gán mã vạch cho mặt hàng');
same((int)($cat->itemByBarcode($code)['id'] ?? 0), $x, 'tra theo mã vạch ra đúng mặt hàng');
same($cat->itemByBarcode('0000000000'), null, 'mã chưa có ⇒ không có mặt hàng');

section('Mã vạch không trùng');
throws(fn() => $cat->setBarcode($f->approverB, $y, $code), DomainError::class, 'mã đã gắn cho mặt hàng khác bị từ chối');
$cat->setBarcode($f->approverA, $x, $code);
same($barcode($x), $code, 'gán lại cùng mã cho chính mặt hàng đó vẫn được');
$cat->setBarcode($f->approverA, $x, '');
same($barcode($x), null, 'để trống ⇒ gỡ mã vạch');
$cat->setBarcode($f->approverB, $y, $code);
same($barcode($y), $code, 'sau khi gỡ, mặt hàng khác gán được mã đó');

section('Quét ra mã lạ: quản lý đề xuất, quản trị duyệt');
use StockFlow\Service\ItemProposalService;
$pr = $f->c->get(ItemProposalService::class);
$unit = (int)$f->db->value('SELECT don_vi_tinh_id FROM mat_hang WHERE id = ?', [$x]);
$catId = $cat->addCategory($f->admin, 'BC' . $f->tag, 'Danh mục mã vạch');
$sup = (int)$f->db->insert("INSERT INTO doi_tac (loai, ma, ten) VALUES ('SUPPLIER', ?, ?)", ['NC' . $f->tag, 'NCC mã vạch']);
$new = '893' . random_int(1000000000, 9999999999);
$sku = 'BCA-' . $f->tag;
throws(fn() => $pr->create($f->keeperA, $new, $sku, 'Hàng quét', $unit, false, $catId, $sup), Forbidden::class, 'nhân viên không gửi được đề xuất');
throws(fn() => $pr->create($f->admin, $new, $sku, 'Hàng quét', $unit, false, $catId, $sup), Forbidden::class, 'quản trị không gửi đề xuất');
throws(fn() => $pr->create($f->approverA, $code, $sku, 'Hàng quét', $unit, false, $catId, $sup), DomainError::class, 'mã đã có trong hệ thống ⇒ không đề xuất trùng');
$pid = $pr->create($f->approverA, $new, $sku, 'Hàng quét', $unit, false, $catId, $sup);
same((int)$f->db->value('SELECT COUNT(*) FROM mat_hang WHERE ma_hang = ?', [$sku]), 0, 'gửi đề xuất chưa tạo mặt hàng');
same($pr->pendingByBarcode($new)['ma_hang'] ?? null, $sku, 'tra mã thấy đề xuất đang chờ');
throws(fn() => $pr->create($f->approverB, $new, 'BCB-' . $f->tag, 'Hàng khác', $unit, false, $catId, $sup), DomainError::class, 'cùng mã vạch đang chờ duyệt ⇒ không đề xuất thêm');
same($pr->attention($f->admin) >= 1, true, 'quản trị có việc chờ duyệt');
throws(fn() => $pr->approve($f->approverA, $pid, 1000, 10), Forbidden::class, 'quản lý kho không tự duyệt đề xuất của mình');
throws(fn() => $pr->reject($f->admin, $pid, ''), DomainError::class, 'từ chối phải có lý do');
throws(fn() => $pr->approve($f->admin, $pid, 0, 10), DomainError::class, 'duyệt phải có giá gốc');
$itemId = $pr->approve($f->admin, $pid, 21500, 5);
same($barcode($itemId), $new, 'duyệt xong mặt hàng mới mang đúng mã vạch');
same([(int)$f->db->value('SELECT gia_von FROM mat_hang WHERE id = ?', [$itemId]), (int)$f->db->value('SELECT thue_suat FROM mat_hang WHERE id = ?', [$itemId])], [21500, 5], 'giá gốc và thuế do quản trị đặt khi duyệt');
same((int)$f->db->value('SELECT COUNT(*) FROM mat_hang_nha_cung_cap WHERE mat_hang_id = ? AND doi_tac_id = ?', [$itemId, $sup]), 1, 'mặt hàng mới gắn nhà cung cấp để lập phiếu nhập');
throws(fn() => $pr->approve($f->admin, $pid, 21500, 5), DomainError::class, 'đề xuất đã xử lý không duyệt lại được');
same($pr->attention($f->approverA) >= 1, true, 'quản lý kho có thông báo kết quả');
$pr->markSeen($f->approverA);
same($pr->attention($f->approverA), 0, 'xem rồi thì hết thông báo');

$new2 = '893' . random_int(1000000000, 9999999999);
$pid2 = $pr->create($f->approverA, $new2, 'BCR-' . $f->tag, 'Hàng bị từ chối', $unit, false, $catId, $sup);
$pr->reject($f->admin, $pid2, 'Ngoài danh mục kinh doanh');
same([$f->db->value('SELECT trang_thai FROM de_xuat_mat_hang WHERE id = ?', [$pid2]), (int)$f->db->value('SELECT COUNT(*) FROM mat_hang WHERE ma_vach = ?', [$new2])], ['REJECTED', 0], 'bị từ chối thì không tạo mặt hàng');
$pid3 = $pr->create($f->approverA, $new2, 'BCR-' . $f->tag, 'Gửi lại', $unit, false, $catId, $sup);
throws(fn() => $pr->cancel($f->approverB, $pid3), Forbidden::class, 'quản lý kho khác không rút được đề xuất');
$pr->cancel($f->approverA, $pid3);
same($f->db->value('SELECT trang_thai FROM de_xuat_mat_hang WHERE id = ?', [$pid3]), 'CANCELLED', 'quản lý rút lại đề xuất còn chờ');
same(count($pr->list($f->approverB, null)), 0, 'quản lý kho khác không thấy đề xuất của kho A');
same((int)$f->db->value('SELECT COUNT(*) FROM nhat_ky WHERE hanh_dong = ?', ['item.barcode']) >= 2, true, 'gán / gỡ mã được ghi nhật ký');

finish();
