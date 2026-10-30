<?php
declare(strict_types=1);
// Mã bán hàng: sinh LÚC BÁN (mã lô hàng chung cho mỗi dòng phiếu + mã món hàng riêng từng chiếc), tra bằng cả hai mã, nhận bảo hành theo mã.
require __DIR__ . '/../Support/bootstrap.php';

use StockFlow\Domain\DomainError;
use StockFlow\Domain\Forbidden;
use StockFlow\Service\CatalogService;
use StockFlow\Service\SaleCodeService;
use StockFlow\Service\StockQuery;
use StockFlow\Service\VoucherService;
use StockFlow\Service\WarrantyService;

$f = Fx::make();
$codes = $f->c->get(SaleCodeService::class);
$vs = $f->c->get(VoucherService::class);
$w = $f->c->get(WarrantyService::class);
$cat = $f->c->get(CatalogService::class);
$today = StockQuery::today();
$x = $f->it['x']['item'];
$y = $f->it['y']['item'];
$sell = function (string $k, int $qty) use ($f, $vs): array {
    $v = $f->draftIssue($f->wa, $k, $qty);
    $vs->confirm($f->approverA, (int)$v['id']);
    return $v;
};

section('Cột bảo hành theo lô: đặt ở mặt hàng thì các lô theo');
$cat->updateItem($f->admin, $x, 1, 0, null, 12);
same((int)$f->db->value('SELECT bao_hanh_thang FROM lo_hang WHERE id = ?', [$f->it['x']['batch']]), 12, 'lô của mặt hàng bảo hành 12 tháng');
same((int)$f->db->value('SELECT bao_hanh_thang FROM lo_hang WHERE id = ?', [$f->it['y']['batch']]), 0, 'mặt hàng không bảo hành: lô 0 tháng');

section('Bán hàng có bảo hành ⇒ sinh mã lô hàng (chung) + mã món hàng (từng chiếc)');
$f->receive($f->wa, 'x', 20);
$f->receive($f->wa, 'y', 20);
$v1 = $sell('x', 3);
$rows = $f->db->all('SELECT * FROM san_pham_ban WHERE phieu_nhap_xuat_id = ? ORDER BY id', [(int)$v1['id']]);
same(count($rows), 3, 'bán 3 chiếc ⇒ 3 mã món hàng');
same(count(array_unique(array_column($rows, 'ma_lo'))), 1, 'cả dòng dùng chung một mã lô hàng');
same(count(array_unique(array_column($rows, 'ma_mon'))), 3, 'mỗi chiếc một mã món hàng riêng');
truthy(preg_match('/^LB-[A-Z2-9]{8}$/', $rows[0]['ma_lo']) === 1 && preg_match('/^SP-[A-Z2-9]{8}$/', $rows[0]['ma_mon']) === 1, 'mã đúng dạng, không có ký tự dễ nhầm');
same($rows[0]['het_han'], WarrantyService::expiryOf($today, 12), 'hạn bảo hành = ngày bán + 12 tháng');
same((int)$rows[0]['bao_hanh_thang'], 12, 'lưu số tháng bảo hành tại lúc bán');
$vs->confirm($f->approverA, (int)$v1['id']);
same((int)$f->db->value('SELECT COUNT(*) FROM san_pham_ban WHERE phieu_nhap_xuat_id = ?', [(int)$v1['id']]), 3, 'xác nhận lặp không sinh thêm mã');
$vY = $sell('y', 2);
same((int)$f->db->value('SELECT COUNT(*) FROM san_pham_ban WHERE phieu_nhap_xuat_id = ?', [(int)$vY['id']]), 0, 'hàng không bảo hành: không sinh mã');
$vDraft = $f->draftIssue($f->wa, 'x', 1);
same((int)$f->db->value('SELECT COUNT(*) FROM san_pham_ban WHERE phieu_nhap_xuat_id = ?', [(int)$vDraft['id']]), 0, 'phiếu nháp chưa sinh mã');
$v2 = $sell('x', 2);
$lot2 = $f->db->value('SELECT ma_lo FROM san_pham_ban WHERE phieu_nhap_xuat_id = ? LIMIT 1', [(int)$v2['id']]);
truthy($lot2 !== $rows[0]['ma_lo'], 'hai lần bán khác nhau có mã lô hàng khác nhau');

section('Tra cứu cần cả hai mã');
[$lot, $one, $two] = [$rows[0]['ma_lo'], $rows[0]['ma_mon'], $rows[1]['ma_mon']];
$hit = $codes->find($lot, $one);
truthy($hit !== null, 'đúng cả hai mã ⇒ tìm thấy');
same($hit['voucher_code'], $v1['ma'], 'truy ra hoá đơn (phiếu xuất)');
same($hit['ma_hang'], $f->db->value('SELECT ma_hang FROM mat_hang WHERE id = ?', [$x]), 'truy ra mặt hàng');
same((int)$hit['con_han'], 1, 'còn hạn bảo hành');
truthy($codes->find(' ' . strtolower($lot) . ' ', strtolower($one)) !== null, 'gõ thường / thừa khoảng trắng vẫn tra được');
same($codes->find($lot, 'SP-KHONGCO1'), null, 'mã món hàng sai ⇒ không tìm thấy');
same($codes->find('LB-KHONGCO1', $one), null, 'mã lô hàng sai ⇒ không tìm thấy');
same($codes->find($lot2, $one), null, 'mã món hàng ghép với mã lô hàng của lần bán khác ⇒ không tìm thấy');
same($codes->find($lot, ''), null, 'thiếu một mã ⇒ không tra');

section('Nhận bảo hành bằng mã');
$maker = (int)$f->db->insert("INSERT INTO doi_tac (loai, ma, ten) VALUES ('SUPPLIER', ?, 'Nhà sản xuất X')", ['NSX' . $f->tag]);
$f->db->exec('INSERT INTO mat_hang_nha_cung_cap (mat_hang_id, doi_tac_id) VALUES (?, ?)', [$x, $maker]);
throws(fn() => $w->receive($f->keeperA, $f->wa, 0, 1, 'Khách A', '', '', 'Hỏng', $lot, 'SP-KHONGCO1'), DomainError::class, 'mã không khớp bị từ chối');
throws(fn() => $w->receive($f->keeperB, $f->wb, 0, 1, 'Khách A', '', '', 'Hỏng', $lot, $one), DomainError::class, 'chiếc bán ở kho A, nhận ở kho B bị từ chối');
$bh = $w->receive($f->keeperA, $f->wa, 0, 5, 'Khách A', '0901234567', '', 'Không lên nguồn', $lot, $one);
$b = $w->find($bh);
same([(int)$b['mat_hang_id'], (int)$b['so_luong']], [$x, 1], 'mặt hàng lấy theo mã, mỗi mã một chiếc (bỏ qua số lượng nhập)');
same([$b['ngay_mua'], $b['het_han']], [$today, WarrantyService::expiryOf($today, 12)], 'ngày mua và hạn bảo hành lấy theo mã');
same((int)$b['phieu_mua_id'], (int)$v1['id'], 'liên kết hoá đơn mua');
same((int)$f->db->value('SELECT san_pham_ban_id FROM bao_hanh WHERE id = ?', [$bh]), (int)$rows[0]['id'], 'phiếu bảo hành lưu chiếc đã bán');
throws(fn() => $w->receive($f->keeperA, $f->wa, 0, 1, 'Khách A', '', '', 'Hỏng lại', $lot, $one), DomainError::class, 'chiếc đang bảo hành chưa trả khách: không nhận lần hai');
$bh2 = $w->receive($f->keeperA, $f->wa, 0, 1, 'Khách A', '', '', 'Hỏng', $lot, $two);
truthy($bh2 > 0, 'chiếc khác cùng lô nhận được');
$w->resolve($f->keeperA, $bh, 'DA_SUA', 'Thay main');
$w->handBack($f->keeperA, $bh);
$bh3 = $w->receive($f->keeperA, $f->wa, 0, 1, 'Khách A', '', '', 'Lỗi khác', $lot, $one);
truthy($bh3 > 0, 'trả khách xong thì còn hạn vẫn nhận bảo hành lần sau');
$f->db->exec('UPDATE san_pham_ban SET het_han = ? WHERE ma_mon = ?', [gmdate('Y-m-d', time() - 86400), $two]);
same((int)$codes->find($lot, $two)['con_han'], 0, 'quá hạn thì báo hết hạn bảo hành');

section('Cách cũ (mặt hàng + mã phiếu mua) vẫn dùng được cho hàng bán trước khi có mã');
$old = $w->receive($f->keeperA, $f->wa, $x, 1, 'Khách Cũ', '', $v2['ma'], 'Hỏng');
same((int)$w->find($old)['phieu_mua_id'], (int)$v2['id'], 'nhận theo phiếu mua như trước');
same($f->db->value('SELECT san_pham_ban_id FROM bao_hanh WHERE id = ?', [$old]), null, 'không gắn mã bán hàng');
throws(fn() => $w->receive($f->keeperA, $f->wa, $x, 1, 'Khách', '', '', 'Hỏng'), DomainError::class, 'không có mã lẫn phiếu mua thì từ chối');

section('Phiếu bán bị điều chỉnh đảo ⇒ mã hết hiệu lực');
$v3 = $sell('x', 2);
$r3 = $f->db->all('SELECT ma_lo, ma_mon FROM san_pham_ban WHERE phieu_nhap_xuat_id = ?', [(int)$v3['id']]);
$vs->correct($f->both, (int)$v3['id'], 'Bán nhầm');
same((int)$f->db->value('SELECT COUNT(*) FROM san_pham_ban WHERE phieu_nhap_xuat_id = ? AND da_huy = 1', [(int)$v3['id']]), 2, 'mã của phiếu bị đảo đánh dấu huỷ');
throws(fn() => $w->receive($f->keeperA, $f->wa, 0, 1, 'Khách', '', '', 'Hỏng', $r3[0]['ma_lo'], $r3[0]['ma_mon']), DomainError::class, 'mã đã huỷ không nhận bảo hành');

finish();
