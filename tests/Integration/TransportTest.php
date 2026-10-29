<?php
declare(strict_types=1);
// Tải trọng, thể tích, kích thước hàng và xe; giấy phép lái xe của tài xế; chặn xuất kho khi xe / tài xế không đủ điều kiện.
require __DIR__ . '/../Support/bootstrap.php';

use StockFlow\Domain\DomainError;
use StockFlow\Domain\Forbidden;
use StockFlow\Service\CatalogService;
use StockFlow\Service\FleetService;
use StockFlow\Service\SettingsService;
use StockFlow\Service\TransferService;
use StockFlow\Service\TransportService;
use StockFlow\Service\VoucherRepository;

$f = Fx::make();
$fleet = $f->c->get(FleetService::class);
$catalog = $f->c->get(CatalogService::class);
$settings = $f->c->get(SettingsService::class);
$tr = $f->c->get(TransportService::class);
$ts = $f->c->get(TransferService::class);
$repo = $f->c->get(VoucherRepository::class);
$cccd = fn() => (string)random_int(100000000000, 999999999999);
$plate = fn() => '30H-' . random_int(100, 999) . '.' . random_int(10, 99);
$d = fn(string $s) => date('Y-m-d', strtotime($s));
$validLicense = ['so_gplx' => '790123456789', 'hang_gplx' => 'C1', 'ngay_sinh' => $d('-35 years'), 'ngay_cap_gplx' => $d('-3 years'), 'han_kham_sk' => $d('+1 year')];
$goodSpecs = ['tai_trong_kg' => 2500, 'gvw_kg' => 4900, 'loai_thung' => 'KIN', 'thung_dai_mm' => 4200, 'thung_rong_mm' => 2000, 'thung_cao_mm' => 2000,
              'tong_cao_mm' => 3200, 'han_dang_kiem' => $d('+6 months'), 'han_bao_hiem' => $d('+6 months')];
/** Thêm xe + tài xế cho kho A với thông số cho trước. */
$mk = function (array $specs, array $lic) use ($f, $fleet, $cccd, $plate): array {
    $r = $fleet->addVehicleWithDriver($f->approverA, $plate(), 'Xe tải', $f->wa, 'Tài xế ' . random_int(1, 9999), $cccd(), '', $specs, $lic);
    return [$r['vehicle_id'], $r['driver_id']];
};

section('Hàng hoá: cân nặng và kích thước một đơn vị');
$itemX = $f->it['x']['item'];
throws(fn() => $catalog->setItemLogistics($f->approverA, $itemX, 20000, 600, 400, 300), Forbidden::class, 'quản lý kho không sửa được thông số hàng (chỉ quản trị)');
throws(fn() => $catalog->setItemLogistics($f->admin, $itemX, 20000, 600, 400, 0), DomainError::class, 'kích thước thiếu một cạnh bị từ chối');
throws(fn() => $catalog->setItemLogistics($f->admin, $itemX, -1, 0, 0, 0), DomainError::class, 'cân nặng âm bị từ chối');
throws(fn() => $catalog->setItemLogistics($f->admin, $itemX, 30_000_000, 0, 0, 0), DomainError::class, 'cân nặng vượt 20 tấn một đơn vị bị từ chối');
$catalog->setItemLogistics($f->admin, $itemX, 20000, 600, 400, 300);   // 20 kg, 60 × 40 × 30 cm
$row = $f->db->one('SELECT khoi_luong_g, dai_mm, rong_mm, cao_mm FROM mat_hang WHERE id = ?', [$itemX]);
same(array_map('intval', $row), ['khoi_luong_g' => 20000, 'dai_mm' => 600, 'rong_mm' => 400, 'cao_mm' => 300], 'lưu đúng gram và mm');

section('Tính tải lô hàng: cân thật, thể tích, trọng lượng quy đổi');
$load = $tr->loadOf([['mat_hang_id' => $itemX, 'so_luong' => 50]]);
same([round($load['weight_kg'], 1), round($load['volume_m3'], 2)], [1000.0, 3.6], '50 thùng × 20 kg = 1.000 kg; 50 × 0,072 m³ = 3,6 m³');
same(round($load['chargeable_kg']), 1199.0, 'tính cước theo số lớn hơn: 3,6 m³ × 333 = 1.199 kg > 1.000 kg');
same($load['no_weight'] === [] && $load['no_size'] === [], true, 'hàng đã khai đủ cân và kích thước');
$loadY = $tr->loadOf([['mat_hang_id' => $f->it['y']['item'], 'so_luong' => 5]]);
same([$loadY['no_weight'], $loadY['no_size']], [[$f->it['y']['ma_hang']], [$f->it['y']['ma_hang']]], 'hàng chưa khai được liệt kê để cảnh báo');
same($tr->limits()['quy_doi_kg_m3'], 333, 'hệ số quy đổi mặc định 333 kg/m³');
$settings->saveTransport($f->admin, ['quy_doi_kg_m3' => 250, 'xep_hang_pct' => 85, 'cao_max_mm' => 4200, 'rong_max_mm' => 2500, 'dai_max_mm' => 12000, 'canh_bao_han_ngay' => 30]);
same(round($tr->loadOf([['mat_hang_id' => $itemX, 'so_luong' => 50]])['chargeable_kg']), 1000.0, 'quản trị đổi hệ số 250 ⇒ quy đổi 900 kg < cân thật 1.000 kg, tính theo cân thật');
throws(fn() => $settings->saveTransport($f->approverA, ['quy_doi_kg_m3' => 300]), Forbidden::class, 'quản lý kho không chỉnh được quy định');
throws(fn() => $settings->saveTransport($f->admin, ['quy_doi_kg_m3' => 5] + ['xep_hang_pct' => 85, 'cao_max_mm' => 4200, 'rong_max_mm' => 2500, 'dai_max_mm' => 12000, 'canh_bao_han_ngay' => 30]), DomainError::class, 'giá trị ngoài khoảng bị từ chối');
$settings->saveTransport($f->admin, ['quy_doi_kg_m3' => 333, 'xep_hang_pct' => 85, 'cao_max_mm' => 4200, 'rong_max_mm' => 2500, 'dai_max_mm' => 12000, 'canh_bao_han_ngay' => 30]);

section('Hàng đóng kiện: tính theo thùng (kiện) + phần lẻ');
throws(fn() => $catalog->setItemLogistics($f->admin, $itemX, 20000, 600, 400, 300, 500000, 1200, 800, 0), DomainError::class, 'kích thước kiện thiếu một cạnh bị từ chối');
throws(fn() => $catalog->setItemLogistics($f->admin, $itemX, 20000, 600, 400, 300, 30_000_000), DomainError::class, 'cân nặng kiện quá lớn bị từ chối');
$f->db->exec('UPDATE mat_hang SET quy_cach_lo = 24 WHERE id = ?', [$itemX]);
$catalog->setItemLogistics($f->admin, $itemX, 20000, 600, 400, 300, 500000, 1200, 800, 900);
$kiRow = $f->db->one('SELECT kien_g, kien_dai_mm, kien_rong_mm, kien_cao_mm FROM mat_hang WHERE id = ?', [$itemX]);
same(array_map('intval', $kiRow), ['kien_g' => 500000, 'kien_dai_mm' => 1200, 'kien_rong_mm' => 800, 'kien_cao_mm' => 900], 'lưu đúng cân nặng và kích thước kiện');
$box = $tr->loadOf([['mat_hang_id' => $itemX, 'so_luong' => 50]]);   // 24 cái / kiện: 2 kiện + 2 lẻ
same([round($box['weight_kg']), round($box['volume_m3'], 3)], [1040.0, 1.872], '50 cái = 2 kiện × 500 kg + 2 lẻ × 20 kg = 1.040 kg; thể tích 2 × 0,864 + 2 × 0,072 = 1,872 m³');
same([$box['items'][0]['boxes'], $box['items'][0]['loose']], [2, 2], 'tách đúng 2 kiện + 2 lẻ');
same($box['items'][0]['dims'], [1200, 800, 900], 'kích thước dùng để kiểm lọt thùng xe là của KIỆN (món lớn nhất)');
$catalog->setItemLogistics($f->admin, $itemX, 20000, 600, 400, 300);   // bỏ số liệu kiện ⇒ tự tính từ các đơn vị
$auto = $tr->loadOf([['mat_hang_id' => $itemX, 'so_luong' => 50]]);
same([round($auto['weight_kg']), round($auto['volume_m3'], 3), $auto['items'][0]['dims']], [1000.0, 3.6, [600, 400, 300]], 'chưa khai kiện: tự tính 24 × 20 kg mỗi kiện, thể tích gộp các đơn vị, kích thước lấy của đơn vị');
$f->db->exec('UPDATE mat_hang SET quy_cach_lo = 1 WHERE id = ?', [$itemX]);

section('Khai thông số xe và giấy phép lái xe: kiểm tra đầu vào');
throws(fn() => $mk(['tai_trong_kg' => 5000, 'gvw_kg' => 3000], []), DomainError::class, 'GVW nhỏ hơn tải trọng bị từ chối');
throws(fn() => $mk(['thung_dai_mm' => 4200], []), DomainError::class, 'thùng chỉ khai một cạnh bị từ chối');
throws(fn() => $mk(['loai_thung' => 'XYZ'], []), DomainError::class, 'loại thùng lạ bị từ chối');
throws(fn() => $mk(['han_dang_kiem' => '2026-13-45'], []), DomainError::class, 'ngày đăng kiểm sai bị từ chối');
throws(fn() => $mk([], ['hang_gplx' => 'C1']), DomainError::class, 'có hạng mà thiếu số bằng bị từ chối');
throws(fn() => $mk([], ['so_gplx' => '790123456789', 'hang_gplx' => 'ZZ']), DomainError::class, 'hạng bằng lạ bị từ chối');
throws(fn() => $mk([], $validLicense + ['diem_gplx' => '20']), DomainError::class, 'điểm GPLX ngoài 0–12 bị từ chối');
throws(fn() => $mk([], ['so_gplx' => '790123456789', 'hang_gplx' => 'C', 'ngay_sinh' => $d('-20 years'), 'ngay_cap_gplx' => $d('-1 month')]), DomainError::class, 'cấp hạng C khi mới 20 tuổi (cần 21) bị từ chối');
throws(fn() => $mk([], ['so_gplx' => '790123456789', 'hang_gplx' => 'B', 'ngay_cap_gplx' => $d('-1 year'), 'han_gplx' => $d('-2 years')]), DomainError::class, 'hạn bằng trước ngày cấp bị từ chối');
same((int)$f->db->value('SELECT COUNT(*) FROM xe WHERE kho_goc_id = ?', [$f->wa]), 0, 'các lần bị từ chối không tạo xe nào (cả xe lẫn tài xế cùng rollback)');
[$xe, $tx] = $mk($goodSpecs, $validLicense);
$lic = $f->db->one('SELECT han_gplx FROM tai_xe WHERE id = ?', [$tx]);
same($lic['han_gplx'], $d('-3 years +5 years'), 'không nhập hạn bằng ⇒ tự tính từ ngày cấp + 5 năm (hạng C1)');
throws(fn() => $fleet->setVehicleSpecs($f->approverB, $xe, $goodSpecs), Forbidden::class, 'quản lý kho khác không sửa thông số xe này');
throws(fn() => $fleet->setDriverLicense($f->approverB, $tx, $validLicense), Forbidden::class, 'quản lý kho khác không sửa bằng lái tài xế này');

section('Kiểm tra xe đủ điều kiện: hợp lệ thì không chặn');
$vehicle = fn(int $id) => $f->db->one('SELECT * FROM xe WHERE id = ?', [$id]);
$driver = fn(int $id) => $f->db->one('SELECT * FROM tai_xe WHERE id = ?', [$id]);
$r = $tr->check($vehicle($xe), $driver($tx), $load);
same($r['blocks'], [], '1.000 kg / 3,6 m³ trên xe 2,5 tấn, thùng 16,8 m³, bằng C1 cho xe 4,9 tấn ⇒ không chặn');

section('Quá tải, thiếu chỗ, hàng không lọt thùng');
$heavy = $tr->loadOf([['mat_hang_id' => $itemX, 'so_luong' => 130]]);   // 2.600 kg
truthy(array_filter($tr->check($vehicle($xe), $driver($tx), $heavy)['blocks'], fn($m) => str_contains($m, 'Quá tải')) !== [], '2.600 kg trên xe tải trọng 2.500 kg ⇒ chặn quá tải');
$f->db->exec('UPDATE mat_hang SET khoi_luong_g = 1000 WHERE id = ?', [$itemX]);   // nhẹ lại để chỉ còn lỗi thể tích
$bulky = $tr->loadOf([['mat_hang_id' => $itemX, 'so_luong' => 220]]);   // 220 kg nhưng 15,84 m³ > 16,8 × 85% = 14,28
truthy(array_filter($tr->check($vehicle($xe), $driver($tx), $bulky)['blocks'], fn($m) => str_contains($m, 'Thiếu chỗ')) !== [] && $bulky['weight_kg'] < 2500, 'hàng nhẹ nhưng cồng kềnh vượt thể tích thùng ⇒ chặn thiếu chỗ');
$f->db->exec('UPDATE mat_hang SET dai_mm = 3000, rong_mm = 500, cao_mm = 500 WHERE id = ?', [$itemX]);
$f->db->exec('UPDATE xe SET thung_dai_mm = 2500, thung_rong_mm = 2000, thung_cao_mm = 2000 WHERE id = ?', [$xe]);
truthy(array_filter($tr->check($vehicle($xe), $driver($tx), $tr->loadOf([['mat_hang_id' => $itemX, 'so_luong' => 1]]))['blocks'], fn($m) => str_contains($m, 'không lọt lòng thùng')) !== [], 'hàng dài 3 m trên thùng dài 2,5 m ⇒ chặn không lọt thùng');
$f->db->exec('UPDATE mat_hang SET khoi_luong_g = 20000, dai_mm = 600, rong_mm = 400, cao_mm = 300 WHERE id = ?', [$itemX]);
$f->db->exec('UPDATE xe SET thung_dai_mm = 4200, thung_rong_mm = 2000, thung_cao_mm = 2000 WHERE id = ?', [$xe]);

section('Giấy tờ xe và khổ xe');
$f->db->exec('UPDATE xe SET han_dang_kiem = ? WHERE id = ?', [$d('-1 day'), $xe]);
truthy(array_filter($tr->check($vehicle($xe), $driver($tx), $load)['blocks'], fn($m) => str_contains($m, 'đăng kiểm')) !== [], 'hết hạn đăng kiểm ⇒ chặn');
$f->db->exec('UPDATE xe SET han_dang_kiem = ?, han_bao_hiem = ? WHERE id = ?', [$d('+10 days'), $d('-5 days'), $xe]);
$rr = $tr->check($vehicle($xe), $driver($tx), $load);
truthy(array_filter($rr['blocks'], fn($m) => str_contains($m, 'bảo hiểm')) !== [] && array_filter($rr['warns'], fn($m) => str_contains($m, 'đăng kiểm')) !== [], 'hết hạn bảo hiểm ⇒ chặn; đăng kiểm còn 10 ngày ⇒ chỉ cảnh báo sắp hết hạn');
$f->db->exec('UPDATE xe SET han_dang_kiem = ?, han_bao_hiem = ?, tong_cao_mm = 4500 WHERE id = ?', [$d('+6 months'), $d('+6 months'), $xe]);
truthy(array_filter($tr->check($vehicle($xe), $driver($tx), $load)['blocks'], fn($m) => str_contains($m, 'quá khổ')) !== [], 'xe cao 4,5 m vượt khổ 4,2 m ⇒ chặn, cần giấy phép lưu hành');
$f->db->exec('UPDATE xe SET tong_cao_mm = 3200 WHERE id = ?', [$xe]);

section('Bằng lái: đúng hạng, còn hạn, đủ tuổi, còn điểm, khám sức khoẻ');
$blocks = fn() => $tr->check($vehicle($xe), $driver($tx), $load)['blocks'];
$setLic = fn(array $over) => $fleet->setDriverLicense($f->approverA, $tx, $over + $validLicense);
$f->db->exec('UPDATE xe SET gvw_kg = 9000 WHERE id = ?', [$xe]);   // xe 9 tấn cần hạng C
truthy(array_filter($blocks(), fn($m) => str_contains($m, 'cần Hạng C trở lên')) !== [], 'tài xế C1 lái xe 9 tấn (cần C) ⇒ chặn sai hạng');
$setLic(['hang_gplx' => 'C']);
same($blocks(), [], 'đổi sang hạng C thì lái được');
$f->db->exec('UPDATE xe SET gvw_kg = 4900, ro_mooc_kg = 2000 WHERE id = ?', [$xe]);   // kéo rơ moóc 2 tấn ⇒ C1E
truthy(array_filter($blocks(), fn($m) => str_contains($m, 'cần Hạng C1E')) !== [], 'kéo rơ moóc trên 750 kg mà bằng C (không có E) ⇒ chặn');
$setLic(['hang_gplx' => 'CE']);
same($blocks(), [], 'hạng CE kéo được rơ moóc');
$f->db->exec('UPDATE xe SET ro_mooc_kg = 0 WHERE id = ?', [$xe]);
$setLic(['hang_gplx' => 'B']);
truthy(array_filter($blocks(), fn($m) => str_contains($m, 'cần Hạng C1 trở lên')) !== [], 'tài xế B lái xe GVW 4,9 tấn (cần C1) ⇒ chặn');
$setLic(['hang_gplx' => 'D1', 'ngay_sinh' => $d('-40 years'), 'ngay_cap_gplx' => $d('-2 years')]);
truthy(array_filter($blocks(), fn($m) => str_contains($m, 'Hạng D1')) !== [], 'hạng D1 (chở người) không lái được xe tải ⇒ chặn');
$setLic(['hang_gplx' => 'C1', 'ngay_cap_gplx' => $d('-8 years'), 'han_gplx' => $d('-1 day')]);
truthy(array_filter($blocks(), fn($m) => str_contains($m, 'hết hạn từ')) !== [], 'bằng lái hết hạn ⇒ chặn');
$setLic(['han_gplx' => $d('+1 year'), 'diem_gplx' => '0']);
truthy(array_filter($blocks(), fn($m) => str_contains($m, 'trừ hết điểm')) !== [], 'bị trừ hết điểm GPLX ⇒ chặn');
$setLic(['diem_gplx' => '12', 'han_kham_sk' => $d('-1 day')]);
truthy(array_filter($blocks(), fn($m) => str_contains($m, 'khám sức khoẻ')) !== [], 'hết hạn khám sức khoẻ ⇒ chặn');
$f->db->exec('UPDATE tai_xe SET ngay_sinh = ?, hang_gplx = ? WHERE id = ?', [$d('-19 years'), 'C', $tx]);
$setLic([]);
$f->db->exec('UPDATE tai_xe SET ngay_sinh = ?, hang_gplx = ?, han_kham_sk = ? WHERE id = ?', [$d('-19 years'), 'C', $d('+1 year'), $tx]);
truthy(array_filter($blocks(), fn($m) => str_contains($m, 'chưa đủ 21 tuổi')) !== [], 'tài xế 19 tuổi giữ hạng C (cần 21) ⇒ chặn chưa đủ tuổi');
$setLic([]);

section('Số liệu chưa khai: chỉ cảnh báo, không chặn');
$bareId = $fleet->addVehicle($f->approverA, $plate(), 'Xe cũ', $f->wa, $fleet->addDriver($f->approverA, 'Tài xế cũ', $cccd(), ''));
$bare = $tr->checkVehicle($bareId, $load);
same($bare['blocks'], [], 'xe và tài xế chưa khai gì: không chặn');
truthy(count($bare['warns']) >= 5, 'nhưng cảnh báo đủ: tải trọng, thùng, đăng kiểm, bảo hiểm, GVW, bằng lái… (' . count($bare['warns']) . ' cảnh báo)');

section('Chặn khi xuất kho điều chuyển');
$f->receive($f->wa, 'x', 300);
$f->db->exec('UPDATE mat_hang SET khoi_luong_g = 20000 WHERE id = ?', [$itemX]);
$over = $ts->createDraft($f->keeperA, $f->wa, $f->wb, [$f->line('x', 130)], 'quá tải', null)['voucher'];   // 2.600 kg
$e = null;
try {
    $ts->dispatch($f->approverA, (int)$over['id'], $xe, true);
} catch (DomainError $ex) {
    $e = $ex->getMessage();
}
truthy($e !== null && str_contains($e, 'Quá tải') && str_contains($e, 'không chở được lô hàng'), 'xuất kho lô 2.600 kg bằng xe 2.500 kg bị chặn kèm lý do');
same([$repo->find((int)$over['id'])['trang_thai'], $f->stock($f->wa, 'x')], ['DRAFT', 300], 'phiếu vẫn nháp, tồn kho không bị trừ');
$ok = $ts->createDraft($f->keeperA, $f->wa, $f->wb, [$f->line('x', 100)], 'đủ tải', null)['voucher'];   // 2.000 kg
$ts->dispatch($f->approverA, (int)$ok['id'], $xe, true);
same($repo->find((int)$ok['id'])['trang_thai'], 'CONFIRMED', 'lô 2.000 kg trên xe 2.500 kg xuất được');
$ts->receiveChecked($f->approverB, (int)$repo->pairOf($repo->find((int)$ok['id']))['id'], null, true);   // xe giao xong ở kho B
$fleet->relocateVehicle($f->approverA, $xe, $f->wa);

section('Chia nhiều xe: mỗi xe kiểm tra theo phần hàng của riêng nó');
[$xe2] = $mk($goodSpecs, $validLicense);
$big = $ts->createDraft($f->keeperA, $f->wa, $f->wb, [$f->line('x', 190)], 'hai xe', null)['voucher'];   // 3.800 kg — một xe không chở nổi
$line = (int)$repo->lines((int)$big['id'])[0]['id'];
$pay = ['hinh_thuc' => 'NOI_BO', 'km' => 100, 'tien' => 800000, 'ghi_chu' => ''];
throws(fn() => $ts->dispatch($f->approverA, (int)$big['id'], $xe2, true, $pay), DomainError::class, 'lô 3.800 kg không đi được bằng một xe 2.500 kg');
throws(fn() => $ts->dispatchConvoy($f->approverA, (int)$big['id'], $xe, [$xe2 => [$line => 20]], true, $pay), DomainError::class, 'chia lệch: xe chính vẫn gánh 3.400 kg ⇒ chặn, cả đợt rollback');
same([$repo->find((int)$big['id'])['trang_thai'], $f->stock($f->wa, 'x')], ['DRAFT', 200], 'chia sai thì không xuất gì, phiếu còn nguyên');
$res = $ts->dispatchConvoy($f->approverA, (int)$big['id'], $xe, [$xe2 => [$line => 95]], true, $pay);
same(count($res['split']), 2, 'chia 95 + 95 thùng (1.900 kg mỗi xe) thì đủ tải cả hai xe');

section('Ảnh giấy phép lái xe');
/** PNG hợp lệ tối thiểu w×h (xám) dựng bằng PHP thuần — môi trường không có GD. */
$png = function (int $w, int $h): string {
    $raw = '';
    for ($y = 0; $y < $h; $y++) {
        $raw .= chr(0) . str_repeat(chr(128), $w);
    }
    $chunk = fn(string $type, string $data) => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    return chr(0x89) . 'PNG' . chr(13) . chr(10) . chr(0x1a) . chr(10) . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 0, 0, 0, 0)) . $chunk('IDAT', gzcompress($raw)) . $chunk('IEND', '');
};
function tap_file(string $bytes): string
{
    $t = tempnam(sys_get_temp_dir(), 'lic');
    file_put_contents($t, $bytes);
    return $t;
}
$good = tap_file($png(300, 220));
throws(fn() => $fleet->saveLicensePhoto($f->approverB, $tx, $good, filesize($good)), Forbidden::class, 'kho khác không đính ảnh cho tài xế này');
throws(fn() => $fleet->saveLicensePhoto($f->approverA, $tx, tap_file($png(100, 100)), 10000), DomainError::class, 'ảnh quá nhỏ bị từ chối');
throws(fn() => $fleet->saveLicensePhoto($f->approverA, $tx, tap_file('không phải ảnh'), 20), DomainError::class, 'file không phải ảnh bị từ chối (kiểm nội dung thật, không tin đuôi file)');
throws(fn() => $fleet->saveLicensePhoto($f->approverA, $tx, $good, 4 * 1024 * 1024), DomainError::class, 'ảnh quá 3 MB bị từ chối');
$name = $fleet->saveLicensePhoto($f->approverA, $tx, $good, filesize($good));
$dir = \StockFlow\Core\Config::storage('licenses');
truthy(preg_match('/^d' . $tx . '_[a-f0-9]{12}\.png$/', $name) === 1 && is_file("$dir/$name"), 'ảnh lưu với tên do hệ thống sinh, không dùng tên file người dùng');
same($fleet->licensePhotoFile($f->approverA, $tx)['mime'], 'image/png', 'kho quản lý xem được ảnh');
same($fleet->licensePhotoFile($f->admin, $tx)['mime'], 'image/png', 'quản trị xem được ảnh');
throws(fn() => $fleet->licensePhotoFile($f->approverB, $tx), Forbidden::class, 'quản lý kho khác không xem được ảnh bằng lái');
throws(fn() => $fleet->licensePhotoFile($f->keeperA, $tx), Forbidden::class, 'nhân viên không có quyền xem đội xe không xem được ảnh');
$name2 = $fleet->saveLicensePhoto($f->approverA, $tx, $good, filesize($good));
same([is_file("$dir/$name"), is_file("$dir/$name2")], [false, true], 'đính ảnh mới thì ảnh cũ bị xoá khỏi ổ đĩa');
$fleet->removeLicensePhoto($f->approverA, $tx);
same([is_file("$dir/$name2"), $fleet->licensePhotoFile($f->approverA, $tx)], [false, null], 'gỡ ảnh: xoá file và không còn ảnh');

finish();
