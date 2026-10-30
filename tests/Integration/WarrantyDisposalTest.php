<?php
declare(strict_types=1);
// Kho bảo hành (chỉ hàng có thời hạn bảo hành; đổi mới trừ tồn) và kho huỷ (đề xuất → duyệt trừ tồn → tiêu huỷ).
require __DIR__ . '/../Support/bootstrap.php';

use StockFlow\Domain\DomainError;
use StockFlow\Domain\Forbidden;
use StockFlow\Domain\InsufficientStock;
use StockFlow\Service\CatalogService;
use StockFlow\Service\DisposalService;
use StockFlow\Service\StockQuery;
use StockFlow\Service\WarrantyService;

$f = Fx::make();
$w = $f->c->get(WarrantyService::class);
$d = $f->c->get(DisposalService::class);
$cat = $f->c->get(CatalogService::class);
$today = StockQuery::today();
$x = $f->it['x']['item'];
$sale = function (int $wh, int $item, int $qty, int $daysAgo = 0) use ($f): string {
    $ma = 'PX-T' . $f->tag . '-' . mt_rand(1000, 9999);
    $id = (int)$f->db->insert("INSERT INTO phieu_nhap_xuat (ma, loai, trang_thai, kho_id, nguoi_lap_id, nguoi_xac_nhan_id, ngay_xac_nhan) VALUES (?, 'ISSUE', 'CONFIRMED', ?, ?, ?, ?)",
        [$ma, $wh, $f->approverA->id, $f->approverA->id, gmdate('Y-m-d H:i:s', time() - $daysAgo * 86400)]);
    $f->db->insert('INSERT INTO chi_tiet_phieu_nhap_xuat (phieu_nhap_xuat_id, mat_hang_id, lo_hang_id, so_luong) VALUES (?,?,?,?)', [$id, $item, $f->it['x']['batch'], $qty]);
    return $ma;
};
$maker = (int)$f->db->insert("INSERT INTO doi_tac (loai, ma, ten) VALUES ('SUPPLIER', ?, 'Nhà sản xuất X')", ['NSX' . $f->tag]);

section('Mặt hàng không có thời hạn bảo hành thì không nhận bảo hành');
throws(fn() => $w->receive($f->keeperA, $f->wa, $x, 1, 'Khách A', '', 'PX-NONE', 'Hỏng'), DomainError::class, 'bảo hành 0 tháng bị từ chối');
$cat->updateItem($f->admin, $x, 1, 0, null, 12);
same((int)$f->db->value('SELECT bao_hanh_thang FROM mat_hang WHERE id = ?', [$x]), 12, 'quản trị đặt bảo hành 12 tháng');
throws(fn() => $cat->updateItem($f->admin, $x, 1, 0, null, 121), DomainError::class, 'quá 120 tháng bị từ chối');

section('Tiếp nhận bảo hành');
$saleNow = $sale($f->wa, $x, 3);
throws(fn() => $w->receive($f->keeperA, $f->wa, $x, 1, 'Khách A', '', $saleNow, 'Hỏng'), DomainError::class, 'mặt hàng chưa có nhà sản xuất thì không tạo phiếu bảo hành');
$f->db->exec('INSERT INTO mat_hang_nha_cung_cap (mat_hang_id, doi_tac_id) VALUES (?, ?)', [$x, $maker]);
throws(fn() => $w->receive($f->keeperA, $f->wa, $x, 1, 'Khách A', '', '', 'Hỏng'), DomainError::class, 'thiếu phiếu mua');
throws(fn() => $w->receive($f->keeperA, $f->wa, $x, 1, 'Khách A', '', 'PX-KHONG-CO', 'Hỏng'), DomainError::class, 'phiếu mua không tồn tại');
throws(fn() => $w->receive($f->keeperA, $f->wa, $x, 4, 'Khách A', '', $saleNow, 'Hỏng'), DomainError::class, 'số lượng bảo hành vượt số đã mua trên phiếu');
throws(fn() => $w->receive($f->keeperA, $f->wa, $f->it['y']['item'], 1, 'Khách A', '', $saleNow, 'Hỏng'), DomainError::class, 'mặt hàng không có trên phiếu mua');
throws(fn() => $w->receive($f->keeperA, $f->wa, $x, 1, '', '', $saleNow, 'Hỏng'), DomainError::class, 'thiếu tên khách');
throws(fn() => $w->receive($f->keeperA, $f->wb, $x, 1, 'Khách A', '', $saleNow, 'Hỏng'), Forbidden::class, 'không nhận bảo hành ở kho khác');
throws(fn() => $w->receive($f->admin, $f->wa, $x, 1, 'Khách A', '', $saleNow, 'Hỏng'), Forbidden::class, 'tài khoản quản trị không thao tác');
$inWarranty = $w->receive($f->keeperA, $f->wa, $x, 1, 'Khách A', '0901234567', $saleNow, 'Không lên nguồn');
$expired = $w->receive($f->keeperA, $f->wa, $x, 1, 'Khách B', '', $sale($f->wa, $x, 1, 400), 'Vỡ màn hình');
same([(int)$w->find($inWarranty)['con_han'], (int)$w->find($expired)['con_han']], [1, 0], 'còn hạn / hết hạn tính theo ngày mua');
same(WarrantyService::expiryOf('2026-01-31', 1), '2026-02-28', 'cộng tháng không tràn sang tháng sau');
same($w->counts([$f->wa])['TIEP_NHAN'], 2, 'hai phiếu đang giữ');
same($w->counts([$f->wb])['TIEP_NHAN'], 0, 'kho B không thấy phiếu của kho A');

section('Đổi mới trừ tồn, chỉ khi còn hạn');
$f->receive($f->wa, 'x', 5);
throws(fn() => $w->resolve($f->keeperA, $expired, 'DOI_MOI', ''), DomainError::class, 'hết hạn bảo hành: không đổi mới');
throws(fn() => $w->resolve($f->keeperA, $inWarranty, 'TU_CHOI', ''), DomainError::class, 'từ chối phải có lý do');
throws(fn() => $w->resolve($f->keeperB, $inWarranty, 'DA_SUA', ''), Forbidden::class, 'kho B không xử lý phiếu kho A');
$w->resolve($f->keeperA, $inWarranty, 'DOI_MOI', 'Đổi máy mới');
same($f->stock($f->wa, 'x'), 4, 'đổi mới: tồn 5 → 4');
same($f->balance($f->wa, 'x'), 4, 'số dư khớp sổ phát sinh');
same($w->find($inWarranty)['trang_thai'], 'DOI_MOI', 'trạng thái đổi mới');
truthy($w->find($inWarranty)['voucher_code'], 'có phiếu điều chỉnh trừ kho');
throws(fn() => $w->resolve($f->keeperA, $inWarranty, 'DA_SUA', ''), DomainError::class, 'đã có kết quả thì không ghi lại');
$w->resolve($f->keeperA, $expired, 'DA_SUA', 'Thay màn hình, thu phí');
same($f->stock($f->wa, 'x'), 4, 'sửa xong: tồn không đổi');

section('Trả khách');
throws(fn() => $w->handBack($f->keeperA, $w->receive($f->keeperA, $f->wa, $x, 1, 'Khách C', '', $saleNow, 'Lỗi')), DomainError::class, 'chưa có kết quả thì chưa trả');
$w->handBack($f->keeperA, $inWarranty);
same($w->find($inWarranty)['trang_thai'], 'DA_TRA', 'đã trả khách');
throws(fn() => $w->handBack($f->keeperA, $inWarranty), DomainError::class, 'không trả hai lần');
same($w->counts([$f->wa]), ['TIEP_NHAN' => 1, 'WAITING' => 1, 'DA_TRA' => 1], 'đếm theo tab');

$bigId = $w->receive($f->keeperA, $f->wa, $x, 100, 'Khách D', '', $sale($f->wa, $x, 100), 'Lô lỗi');
throws(fn() => $w->resolve($f->keeperA, $bigId, 'DOI_MOI', ''), DomainError::class, 'không đủ tồn để đổi: báo rõ, không âm kho');
same($f->stock($f->wa, 'x'), 4, 'tồn không đổi khi đổi thất bại');

section('Kho huỷ: đề xuất chưa động tới tồn');
$f->receive($f->wa, 'y', 20);
$y = $f->it['y'];
$r = $d->propose($f->keeperA, $f->wa, $y['item'], $y['batch'], 8, 'VO_HONG', 'Rơi vỡ');
same($r['approved'], false, 'nhân viên chỉ đề xuất, chưa được duyệt');
same($f->stock($f->wa, 'y'), 20, 'chưa trừ tồn');
throws(fn() => $d->propose($f->keeperA, $f->wa, $y['item'], $y['batch'], 13, 'VO_HONG', ''), DomainError::class, 'không đề xuất vượt tồn đã trừ phần đang chờ');
throws(fn() => $d->propose($f->keeperA, $f->wa, $y['item'], $y['batch'], 1, 'KHAC', ''), DomainError::class, 'lý do «Khác» cần ghi chú');
throws(fn() => $d->propose($f->keeperA, $f->wa, $y['item'], $y['batch'], 1, 'XYZ', ''), DomainError::class, 'lý do lạ bị từ chối');
throws(fn() => $d->propose($f->keeperB, $f->wa, $y['item'], $y['batch'], 1, 'VO_HONG', ''), Forbidden::class, 'kho B không đề xuất huỷ hàng kho A');
throws(fn() => $d->approve($f->keeperA, $r['id']), Forbidden::class, 'nhân viên không tự duyệt');

section('Duyệt ⇒ trừ tồn, hàng vào kho huỷ');
$d->approve($f->approverA, $r['id']);
same([$f->stock($f->wa, 'y'), $f->balance($f->wa, 'y')], [12, 12], 'tồn 20 → 12, số dư khớp sổ');
same($d->find($r['id'])['trang_thai'], 'TRONG_KHO_HUY', 'hàng nằm trong kho huỷ');
$d->approve($f->approverA, $r['id']);
same($f->stock($f->wa, 'y'), 12, 'duyệt lại không trừ thêm (idempotent)');
throws(fn() => $d->reject($f->approverA, $r['id']), DomainError::class, 'đã duyệt thì không từ chối được');

section('Tiêu huỷ');
throws(fn() => $d->destroy($f->keeperA, $r['id']), Forbidden::class, 'nhân viên không xác nhận tiêu huỷ');
$fleetSvc = $f->c->get(StockFlow\Service\FleetService::class);
$veh = $fleetSvc->addVehicleWithDriver($f->approverA, '29C-' . random_int(10000, 99999), 'Xe tải 2 tấn', $f->wa, 'Tài xế Huỷ', (string)random_int(100000000000, 999999999999), '0912345670');
throws(fn() => $d->destroy($f->approverA, $r['id']), DomainError::class, 'chưa lên xe thì không xác nhận đến');
throws(fn() => $d->load($f->approverA, $r['id'], $veh['vehicle_id'], $veh['driver_id'], false), DomainError::class, 'tài xế chưa xác nhận nhận hàng');
throws(fn() => $d->load($f->approverA, $r['id'], 0, $veh['driver_id'], true), DomainError::class, 'chưa chọn xe');
throws(fn() => $d->load($f->keeperA, $r['id'], $veh['vehicle_id'], $veh['driver_id'], true), Forbidden::class, 'nhân viên không xuất hàng đi tiêu huỷ');
same($d->site($f->wa)['address'], DisposalService::DEFAULT_SITE['address'], 'chưa đặt thì dùng nơi tiêu huỷ mặc định');
throws(fn() => $d->setSite($f->approverA, $f->wa, 'Công ty Môi trường Xanh', ''), DomainError::class, 'đặt nơi tiêu huỷ phải đủ tên và địa chỉ');
$d->setSite($f->approverA, $f->wa, 'Công ty Môi trường Xanh', 'KCN Sóc Sơn, Hà Nội');
same($d->site($f->wa)['address'], 'KCN Sóc Sơn, Hà Nội', 'kho đặt nơi tiêu huỷ riêng');
$f->db->exec('UPDATE mat_hang SET khoi_luong_g = 10000 WHERE id = ?', [$y['item']]);   // 8 hộp × 10 kg = 80 kg
$f->db->exec('UPDATE xe SET tai_trong_kg = 50 WHERE id = ?', [$veh['vehicle_id']]);
throws(fn() => $d->load($f->approverA, $r['id'], $veh['vehicle_id'], $veh['driver_id'], true), DomainError::class, 'xe quá tải thì không chở hàng đi tiêu huỷ');
same($d->find($r['id'])['xe_id'], null, 'bị chặn thì hàng vẫn nằm trong kho huỷ');
$f->db->exec('UPDATE xe SET tai_trong_kg = 5000 WHERE id = ?', [$veh['vehicle_id']]);
$d->load($f->approverA, $r['id'], $veh['vehicle_id'], $veh['driver_id'], true);
same([$d->find($r['id'])['trang_thai'], $d->find($r['id'])['dia_chi_tieu_huy'], (int)($d->find($r['id'])['xe_id'] === $veh['vehicle_id'])], ['TRONG_KHO_HUY', 'KCN Sóc Sơn, Hà Nội', 1], 'hàng lên xe: chốt địa chỉ, chưa tiêu huỷ');
throws(fn() => $d->load($f->approverA, $r['id'], $veh['vehicle_id'], $veh['driver_id'], true), DomainError::class, 'không xuất hai lần');
same($d->undo($f->approverA, $r['id']), 'KHO_HUY', 'bấm nhầm xuất xe: hàng xuống xe, nằm lại kho huỷ');
same($d->find($r['id'])['xe_id'], null, 'gỡ xe khỏi phiếu huỷ');
$d->load($f->approverA, $r['id'], $veh['vehicle_id'], $veh['driver_id'], true);
throws(fn() => $d->destroy($f->keeperA, $r['id']), Forbidden::class, 'nhân viên không xác nhận đến nơi');
$d->destroy($f->approverA, $r['id']);
same($d->find($r['id'])['trang_thai'], 'DA_TIEU_HUY', 'xe đến nơi ⇒ đã tiêu huỷ');
throws(fn() => $d->destroy($f->approverA, $r['id']), DomainError::class, 'không tiêu huỷ hai lần');
throws(fn() => $d->undo($f->keeperA, $r['id']), Forbidden::class, 'nhân viên không hoàn tác');
same($d->undo($f->approverA, $r['id']), 'TRONG_KHO_HUY', 'bấm nhầm đã đến: xe vẫn đang chở hàng');
same([$d->find($r['id'])['dia_chi_tieu_huy'], $f->stock($f->wa, 'y')], ['KCN Sóc Sơn, Hà Nội', 12], 'tồn không đổi');
$d->destroy($f->approverA, $r['id']);
$o = $d->propose($f->approverA, $f->wa, $y['item'], $y['batch'], 2, 'VO_HONG', '');
same($f->stock($f->wa, 'y'), 10, 'huỷ thêm 2: tồn 12 → 10');
same($d->undo($f->approverA, $o['id']), 'TU_CHOI', 'bấm nhầm huỷ: không huỷ nữa');
same([$f->stock($f->wa, 'y'), $f->balance($f->wa, 'y')], [12, 12], 'hoàn tác trả hàng về tồn, số dư khớp sổ');
throws(fn() => $d->undo($f->approverA, $o['id']), DomainError::class, 'không hoàn tác hai lần');
throws(fn() => $d->destroy($f->approverA, $r['id'], 'A', 'B'), DomainError::class, 'không tiêu huỷ hai lần');

section('Quản lý tự lập thì duyệt luôn; từ chối thì tồn không đổi');
$m = $d->propose($f->approverA, $f->wa, $y['item'], $y['batch'], 2, 'KHONG_BAN_DUOC', '');
same([$m['approved'], $f->stock($f->wa, 'y')], [true, 10], 'quản lý lập: vào kho huỷ ngay, tồn 12 → 10');
$p = $d->propose($f->keeperA, $f->wa, $y['item'], $y['batch'], 3, 'LOI', '');
$d->reject($f->approverA, $p['id']);
same([$d->find($p['id'])['trang_thai'], $f->stock($f->wa, 'y')], ['TU_CHOI', 10], 'từ chối: tồn giữ nguyên');
throws(fn() => $d->approve($f->approverA, $p['id']), DomainError::class, 'đã từ chối thì không duyệt');

section('Giá trị thiệt hại & hàng hết hạn');
$f->db->exec('UPDATE mat_hang SET gia_von = 1000 WHERE id = ?', [$y['item']]);
$g = $d->propose($f->approverA, $f->wa, $y['item'], $y['batch'], 1, 'VO_HONG', '');
same((int)$d->find($g['id'])['value'], 1000, 'giá trị = số lượng × giá vốn lúc lập');
same($d->lossByReason([$f->wa])['VO_HONG']['qty'], 9, 'tổng huỷ vì vỡ/hỏng: 8 + 1');
$lot = $f->it['lot'];
$f->db->exec('UPDATE lo_hang SET han_dung = ? WHERE id = ?', [gmdate('Y-m-d', time() - 3 * 86400), $lot['batch']]);
$f->receive($f->wa, 'lot', 6);
$exp = array_values(array_filter($d->expiredStock([$f->wa]), fn($e) => (int)$e['lo_hang_id'] === $lot['batch']));
same([count($exp), (int)($exp[0]['qty'] ?? 0)], [1, 6], 'lô quá hạn còn tồn được gợi ý huỷ');
$d->propose($f->approverA, $f->wa, $lot['item'], $lot['batch'], 6, 'HET_HAN', '');
same(array_filter($d->expiredStock([$f->wa]), fn($e) => (int)$e['lo_hang_id'] === $lot['batch']), [], 'huỷ hết thì không còn gợi ý');
same($f->stock($f->wa, 'lot'), 0, 'tồn lô hết hạn về 0');

finish();
