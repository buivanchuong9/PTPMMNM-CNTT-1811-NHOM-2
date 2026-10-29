<?php
declare(strict_types=1);
// Quy định vận tải thuần tính toán: hạng bằng theo khối lượng xe, ai được lái xe nào, tuổi tối thiểu, hàng có lọt thùng không.
require __DIR__ . '/../Support/bootstrap.php';

use StockFlow\Domain\TransportRules as R;

section('Hạng bằng lái cần có theo khối lượng toàn bộ (GVW)');
same(R::requiredClass(0), null, 'chưa khai GVW ⇒ chưa biết hạng');
same(R::requiredClass(2500), 'B', 'xe 2,5 tấn ⇒ hạng B');
same(R::requiredClass(3500), 'B', 'đúng 3.500 kg vẫn hạng B');
same(R::requiredClass(3501), 'C1', 'vượt 3.500 kg ⇒ C1');
same(R::requiredClass(7500), 'C1', 'đúng 7.500 kg vẫn C1');
same(R::requiredClass(7501), 'C', 'vượt 7.500 kg ⇒ C');
same(R::requiredClass(16000), 'C', 'xe 16 tấn ⇒ C');
same(R::requiredClass(2500, 750), 'B', 'rơ moóc đến 750 kg không cần hạng E');
same(R::requiredClass(2500, 751), 'BE', 'B kéo rơ moóc trên 750 kg ⇒ BE');
same(R::requiredClass(5000, 1000), 'C1E', 'C1 kéo rơ moóc ⇒ C1E');
same(R::requiredClass(24000, 8000), 'CE', 'C kéo rơ moóc / đầu kéo ⇒ CE');

section('Ai được lái xe nào (hạng cao lái được hạng thấp hơn)');
truthy(R::covers('B', 'B') && R::covers('C1', 'B') && R::covers('C', 'B') && R::covers('B2', 'B'), 'B, C1, C, B2 đều lái được xe cần hạng B');
truthy(!R::covers('B', 'C1') && !R::covers('B2', 'C1'), 'hạng B / B2 không lái được xe 3,5–7,5 tấn');
truthy(R::covers('C1', 'C1') && R::covers('C', 'C1') && R::covers('CE', 'C1'), 'C1, C, CE lái được xe cần C1');
truthy(!R::covers('C1', 'C') && !R::covers('B', 'C'), 'C1 không lái được xe trên 7,5 tấn');
truthy(R::covers('C', 'C') && R::covers('CE', 'C'), 'C, CE lái được xe cần C');
truthy(!R::covers('C', 'BE') && !R::covers('C', 'CE'), 'hạng C không kéo được rơ moóc nặng — cần hạng E');
truthy(R::covers('BE', 'BE') && R::covers('C1E', 'BE') && R::covers('CE', 'BE'), 'BE, C1E, CE kéo được rơ moóc của xe hạng B');
truthy(!R::covers('BE', 'C1E') && R::covers('CE', 'C1E'), 'BE không kéo xe C1; CE kéo được');
truthy(!R::covers('D', 'B') && !R::covers('D1', 'B'), 'hạng D chỉ chở người — không lái xe tải');
truthy(!R::CLASSES['D'][4] && R::CLASSES['C1'][4], 'bảng hạng đánh dấu đúng hạng lái được xe tải');

section('Tuổi tối thiểu');
same([R::minAge('B'), R::minAge('C1'), R::minAge('C'), R::minAge('BE'), R::minAge('CE'), R::minAge('D')], [18, 18, 21, 21, 24, 27], 'tuổi tối thiểu theo hạng');
same(R::ageOn('2000-06-15', '2026-06-14'), 25, 'chưa đến sinh nhật: chưa tròn tuổi');
same(R::ageOn('2000-06-15', '2026-06-15'), 26, 'đúng sinh nhật: tròn tuổi');

section('Hàng có lọt lòng thùng không (được xoay)');
truthy(R::fits([1200, 800, 600], [4200, 2000, 2000]), 'pallet 1,2×0,8×0,6 m lọt thùng 4,2×2×2');
truthy(R::fits([2000, 600, 400], [1500, 2000, 1800]), 'hàng dài 2 m xoay ngang lọt thùng 1,5×2×1,8 (xếp theo cạnh sắp xếp)');
truthy(!R::fits([3000, 500, 500], [2500, 2000, 2000]), 'hàng dài 3 m không lọt thùng dài 2,5 m');
truthy(!R::fits([2100, 2100, 2100], [4000, 2000, 2000]), 'hàng cao 2,1 m không lọt thùng cao 2 m');

section('Thể tích và nhập số thập phân');
same(round(R::cubicMeters(1000, 1000, 1000), 6), 1.0, '1 m × 1 m × 1 m = 1 m³');
same(round(R::cubicMeters(4200, 2000, 2000), 2), 16.8, 'thùng 4,2 × 2 × 2 m = 16,8 m³');
same([R::parseDecimal('2,5'), R::parseDecimal('2.5'), R::parseDecimal(''), R::parseDecimal(' 12 ')], [2.5, 2.5, 0.0, 12.0], 'nhận dấu phẩy và dấu chấm, rỗng là 0');
same([R::parseDecimal('abc'), R::parseDecimal('-1'), R::parseDecimal('1.2345')], [null, null, null], 'chữ, số âm, quá 3 chữ số thập phân bị từ chối');

section('Hiển thị cân nặng, kích thước, tồn nặng bao nhiêu');
same([weight_label(350), weight_label(17500), weight_label(1000), weight_label(0)], ['350 g', '17,5 kg', '1 kg', ''], 'gram ⇒ g hoặc kg dễ đọc');
same([size_label(180, 95, 55), size_label(1200, 800, 900), size_label(180, 0, 55)], ['18 × 9,5 × 5,5 cm', '120 × 80 × 90 cm', ''], 'ba cạnh mm ⇒ cm, thiếu cạnh thì rỗng');
$it = ['quy_cach_lo' => 24, 'khoi_luong_g' => 20000, 'kien_g' => 500000];
same([stock_weight_g($it, 50), stock_weight_g(['kien_g' => 0] + $it, 50), stock_weight_g(['quy_cach_lo' => 1, 'khoi_luong_g' => 350, 'kien_g' => 0], 12)], [1_040_000, 1_000_000, 4200], 'tồn nặng: kiện nguyên theo cân cả kiện (hoặc tự tính), lẻ theo cân đơn vị');

finish();
