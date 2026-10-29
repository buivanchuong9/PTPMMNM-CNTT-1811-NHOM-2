<?php
declare(strict_types=1);
// Xe và tài xế quản lý riêng, không gắn cố định: mỗi chuyến chọn xe + tài xế lúc xuất kho; tài xế nghỉ thì tài xế khác lái thay,
// chỉ cần có bằng lái đúng hạng cho xe đó, đang rảnh và thuộc kho đi.
require __DIR__ . '/../Support/bootstrap.php';

use StockFlow\Domain\DomainError;
use StockFlow\Domain\Forbidden;
use StockFlow\Service\FleetService;
use StockFlow\Service\TransferService;
use StockFlow\Service\TransportService;
use StockFlow\Service\VoucherRepository;

$f = Fx::make();
$fleet = $f->c->get(FleetService::class);
$ts = $f->c->get(TransferService::class);
$tr = $f->c->get(TransportService::class);
$repo = $f->c->get(VoucherRepository::class);
$cccd = fn() => (string)random_int(100000000000, 999999999999);
$plate = fn() => '30H-' . random_int(100, 999) . '.' . random_int(10, 99);
$d = fn(string $s) => date('Y-m-d', strtotime($s));
$lic = fn(string $class, array $over = []) => $over + ['so_gplx' => (string)random_int(100000000000, 999999999999), 'hang_gplx' => $class, 'ngay_sinh' => $d('-35 years'),
    'ngay_cap_gplx' => $d('-3 years'), 'han_kham_sk' => $d('+1 year')];
$specs = ['tai_trong_kg' => 2490, 'gvw_kg' => 4990, 'loai_thung' => 'KIN', 'thung_dai_mm' => 4300, 'thung_rong_mm' => 1800, 'thung_cao_mm' => 1800,
          'han_dang_kiem' => $d('+6 months'), 'han_bao_hiem' => $d('+6 months')];   // GVW 4,99 tấn ⇒ cần hạng C1
$pay = ['hinh_thuc' => 'NOI_BO', 'km' => 100, 'tien' => 800000, 'ghi_chu' => ''];
$tripOf = fn(int $outId) => $f->db->one('SELECT * FROM chuyen_van_chuyen WHERE phieu_di_id = ?', [$outId]);
$draft = fn(int $qty) => (int)$ts->createDraft($f->keeperA, $f->wa, $f->wb, [$f->line('x', $qty)], 'test', null)['voucher']['id'];
$arrive = function (int $outId) use ($f, $repo, $ts) {
    $ts->receiveChecked($f->approverB, (int)$repo->pairOf($repo->find($outId))['id'], null, true);
};
$f->receive($f->wa, 'x', 500);

section('Thêm xe và tài xế bằng hai form riêng, không gắn nhau');
$xe = $fleet->createVehicle($f->approverA, $plate(), 'Xe tải 2,5 tấn', $f->wa, $specs);
same($f->db->value('SELECT tai_xe_id FROM xe WHERE id = ?', [$xe]), null, 'xe mới không có tài xế gắn cố định');
same((int)$f->db->value('SELECT tai_trong_kg FROM xe WHERE id = ?', [$xe]), 2490, 'thông số xe được lưu cùng lúc');
throws(fn() => $fleet->createVehicle($f->approverA, $plate(), 'Xe lỗi', $f->wa, ['tai_trong_kg' => 5000, 'gvw_kg' => 3000]), DomainError::class, 'thông số xe sai thì không tạo xe');
throws(fn() => $fleet->createVehicle($f->approverB, $plate(), 'Xe kho khác', $f->wa, $specs), Forbidden::class, 'quản lý kho khác không thêm xe cho kho này');
$c1a = $fleet->createDriver($f->approverA, 'Tài xế C1 A', $cccd(), '', $lic('C1'));
$c1b = $fleet->createDriver($f->approverA, 'Tài xế C1 B', $cccd(), '', $lic('C1'));
$hangB = $fleet->createDriver($f->approverA, 'Tài xế hạng B', $cccd(), '', $lic('B'));
$hangC = $fleet->createDriver($f->approverA, 'Tài xế hạng C', $cccd(), '', $lic('C'));
same($f->db->value('SELECT hang_gplx FROM tai_xe WHERE id = ?', [$c1a]), 'C1', 'tài xế được lưu cùng giấy phép lái xe');
throws(fn() => $fleet->createDriver($f->approverA, 'Sai bằng', $cccd(), '', ['so_gplx' => '123456', 'hang_gplx' => '']), DomainError::class, 'giấy phép khai thiếu thì không tạo tài xế');
same((int)$f->db->value("SELECT COUNT(*) FROM tai_xe WHERE ho_ten = 'Sai bằng'"), 0, 'tài xế bị từ chối không để lại hồ sơ nửa vời');
$other = $fleet->createDriver($f->approverB, 'Tài xế kho B', $cccd(), '', $lic('C1'));
$fleet->editVehicle($f->approverA, $xe, $f->db->value('SELECT bien_so FROM xe WHERE id = ?', [$xe]), 'Xe tải 2,5 tấn (đã sửa)', $f->wa, ['cap_t' => 0] + $specs);
same($f->db->value('SELECT loai_xe FROM xe WHERE id = ?', [$xe]), 'Xe tải 2,5 tấn (đã sửa)', 'sửa xe không cần nhập tài xế');

section('Xuất kho: bắt buộc chọn tài xế, đúng hạng bằng với xe đó');
$o1 = $draft(10);
throws(fn() => $ts->dispatch($f->approverA, $o1, $xe, true, $pay), DomainError::class, 'không chọn tài xế thì không xuất được (xe không có tài xế mặc định)');
throws(fn() => $ts->dispatch($f->approverA, $o1, $xe, true, $pay, true, $hangB), DomainError::class, 'tài xế hạng B không lái được xe GVW 4,99 tấn (cần C1)');
throws(fn() => $ts->dispatch($f->approverA, $o1, $xe, true, $pay, true, $other), DomainError::class, 'tài xế của kho khác không nhận chuyến của kho này');
same($repo->find($o1)['trang_thai'], 'DRAFT', 'các lần bị từ chối không đổi phiếu');
$e = null;
try {
    $ts->dispatch($f->approverA, $o1, $xe, true, $pay, true, $hangB);
} catch (DomainError $ex) {
    $e = $ex->getMessage();
}
truthy($e !== null && str_contains($e, 'cần Hạng C1'), 'lý do nói rõ cần hạng nào: ' . $e);
$ts->dispatch($f->approverA, $o1, $xe, true, $pay, true, $c1a);
same([(int)$tripOf($o1)['tai_xe_id'], (int)$tripOf($o1)['xe_id']], [$c1a, $xe], 'chuyến ghi đúng xe và đúng tài xế được chọn');

section('Tài xế đang chạy thì không nhận thêm; xong chuyến lại rảnh; xe nào cũng do tài xế nào có bằng lái thay');
$xe2 = $fleet->createVehicle($f->approverA, $plate(), 'Xe tải thứ hai', $f->wa, $specs);
$o2 = $draft(10);
throws(fn() => $ts->dispatch($f->approverA, $o2, $xe2, true, $pay, true, $c1a), DomainError::class, 'tài xế đang chạy chuyến khác không nhận thêm');
same(array_column($fleet->availableDrivers($f->wa), 'id') !== [] && !in_array($c1a, array_map('intval', array_column($fleet->availableDrivers($f->wa), 'id')), true), true, 'danh sách tài xế rảnh không có người đang chạy');
same(in_array($other, array_map('intval', array_column($fleet->availableDrivers($f->wa), 'id')), true), false, 'danh sách tài xế rảnh không có người của kho khác');
$vrow = array_values(array_filter($fleet->vehicles($f->wa), fn($v) => (int)$v['id'] === $xe))[0];
same($vrow['chay_tai_xe'], 'Tài xế C1 A', 'danh sách xe cho biết ai đang lái xe đang chạy');
$fleet->setDriverActive($f->approverA, $c1b, false);
throws(fn() => $ts->dispatch($f->approverA, $o2, $xe2, true, $pay, true, $c1b), DomainError::class, 'tài xế đã nghỉ không được chọn');
$fleet->setDriverActive($f->approverA, $c1b, true);
$ts->dispatch($f->approverA, $o2, $xe2, true, $pay, true, $hangC);
same((int)$tripOf($o2)['tai_xe_id'], $hangC, 'tài xế hạng C lái được xe cần C1 (hạng cao lái xe hạng thấp)');
$arrive($o1);
$arrive($o2);
$fleet->relocateVehicle($f->approverA, $xe, $f->wa);
same(in_array($c1a, array_map('intval', array_column($fleet->availableDrivers($f->wa), 'id')), true), true, 'giao xong thì tài xế lại rảnh');
$o3 = $draft(10);
$ts->dispatch($f->approverA, $o3, $xe, true, $pay, true, $c1b);
same([(int)$tripOf($o3)['xe_id'], (int)$tripOf($o3)['tai_xe_id']], [$xe, $c1b], 'cùng một xe, hôm nay tài xế khác lái thay — không bị gắn cố định');

section('Hồ sơ bằng lái không đạt: gợi ý chọn tài xế báo trước');
$expired = $fleet->createDriver($f->approverA, 'Bằng hết hạn', $cccd(), '', $lic('C1', ['ngay_cap_gplx' => $d('-8 years'), 'han_gplx' => $d('-1 day')]));
$young = $fleet->createDriver($f->approverA, 'Tài xế trẻ', $cccd(), '', $lic('C1', ['ngay_sinh' => $d('-30 years'), 'ngay_cap_gplx' => $d('-6 months')]));
$f->db->exec('UPDATE tai_xe SET ngay_sinh = ? WHERE id = ?', [$d('-19 years'), $young]);   // 19 tuổi giữ bằng C1 chỉ hợp lệ từ 18 — đổi sang hạng C để chưa đủ tuổi
$f->db->exec("UPDATE tai_xe SET hang_gplx = 'C' WHERE id = ?", [$young]);
$bare = $fleet->createDriver($f->approverA, 'Chưa khai bằng', $cccd(), '');
$fit = $tr->driverFit([$f->db->one('SELECT * FROM tai_xe WHERE id = ?', [$expired]), $f->db->one('SELECT * FROM tai_xe WHERE id = ?', [$young]), $f->db->one('SELECT * FROM tai_xe WHERE id = ?', [$bare]), $f->db->one('SELECT * FROM tai_xe WHERE id = ?', [$c1b])]);
truthy(array_filter($fit[$expired]['hard'], fn($m) => str_contains($m, 'hết hạn')) !== [], 'bằng hết hạn ⇒ không chọn được');
truthy(array_filter($fit[$young]['hard'], fn($m) => str_contains($m, 'chưa đủ 21 tuổi')) !== [] && $fit[$young]['age'] === 19, '19 tuổi giữ bằng C (cần 21) ⇒ không chọn được, hiện đúng tuổi');
same([$fit[$bare]['hard'], $fit[$bare]['class']], [[], ''], 'chưa khai bằng: chỉ cảnh báo, không chặn');
same($fit[$c1b]['hard'], [], 'tài xế hợp lệ không có lỗi');
throws(fn() => $ts->dispatch($f->approverA, $draft(10), $xe2, true, $pay, true, $expired), DomainError::class, 'chọn tài xế bằng hết hạn khi xuất kho bị chặn');
$vOnly = $tr->checkVehicle($xe2, $tr->loadOf([['mat_hang_id' => $f->it['x']['item'], 'so_luong' => 10]]), null, false);
truthy(array_filter($vOnly['blocks'], fn($m) => str_contains($m, 'tài xế')) === [], 'kiểm riêng xe thì không đòi tài xế');

section('Chia nhiều xe: mỗi xe một tài xế riêng');
$fleet->relocateVehicle($f->approverA, $xe2, $f->wa);
$arrive($o3);
$fleet->relocateVehicle($f->approverA, $xe, $f->wa);
$bigO = $draft(20);
$ln = (int)$repo->lines($bigO)[0]['id'];
throws(fn() => $ts->dispatchConvoy($f->approverA, $bigO, $xe, [$xe2 => [$ln => 8]], true, $pay, $c1a, [$xe2 => $c1a]), DomainError::class, 'hai xe cùng một tài xế bị từ chối (một người không lái hai xe)');
same([$repo->find($bigO)['trang_thai'], $f->stock($f->wa, 'x') >= 0], ['DRAFT', true], 'chia sai thì cả đợt rollback, phiếu còn nguyên');
$res = $ts->dispatchConvoy($f->approverA, $bigO, $xe, [$xe2 => [$ln => 8]], true, $pay, $c1a, [$xe2 => $c1b]);
[$l1, $l2] = $res['split'];
same([(int)$tripOf($l1)['tai_xe_id'], (int)$tripOf($l2)['tai_xe_id']], [$c1a, $c1b], 'mỗi xe trong đợt do đúng tài xế được chọn lái');

section('Xem chuyến thật của từng tài xế trong tháng');
$trips = $fleet->driverTrips([$c1a, $c1b, $hangB]);
same(array_keys($trips) === [] ? [] : array_map('intval', array_keys($trips)), array_map('intval', array_keys($trips)), 'chỉ tài xế có chuyến mới có trong kết quả');
same(isset($trips[$hangB]), false, 'tài xế chưa chạy chuyến nào không có dòng');
$mine = $trips[$c1a] ?? [];
same(count($mine), 2, 'tài xế C1 A có 2 chuyến trong tháng (1 đã giao, 1 đang chạy trong đợt chia xe)');
same(array_column($mine, 'trang_thai') === ['IN_TRANSIT', 'DELIVERED'] || array_column($mine, 'trang_thai') === ['DELIVERED', 'IN_TRANSIT'], true, 'hiện đủ tình trạng từng chuyến: đã giao và đang chạy');
$first = $mine[0];
truthy(str_starts_with((string)$first['phieu_ma'], 'DCX-') && (int)$first['phieu_id'] > 0 && $first['bien_so'] !== null && $first['tu'] !== $first['den'], 'mỗi chuyến có mã phiếu, tuyến kho đi → kho đến và biển số xe');
same((int)$first['kho_phieu_id'], $f->wa, 'mỗi chuyến ghi kho phát hành phiếu (kho xuất phát) để biết ai được mở phiếu');
same($fleet->driverTrips([$c1a], '2000-01'), [], 'chọn tháng khác thì không có chuyến');
same($fleet->driverTrips([]), [], 'không truyền tài xế thì rỗng');
finish();
