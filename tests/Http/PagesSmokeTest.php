<?php
declare(strict_types=1);
// Mọi trang chính phải trả 200 (không lỗi 500) với người dùng có quyền; chống hồi quy khi sửa truy vấn/giao diện.
require __DIR__ . '/../Support/bootstrap.php';

$f = Fx::make();
$f->receive($f->wa, 'x', 20);
$f->receive($f->wa, 'lot', 9);
$f->receive($f->wb, 'y', 5);
$issue = $f->draftIssue($f->wa, 'x', 3);
$cid = $f->c->get(\StockFlow\Service\StockCountService::class)->open($f->keeperA, $f->wa, '');
$transfer = $f->c->get(\StockFlow\Service\TransferService::class)->createDraft($f->keeperA, $f->wa, $f->wb, [$f->line('x', 2)], '', null)['voucher'];
$f->c->get(\StockFlow\Service\TransferService::class)->confirm($f->approverA, (int)$transfer['id']);
$f->arrive((int)$transfer['id']);
$pair = $f->c->get(\StockFlow\Service\VoucherRepository::class)->pairOf($transfer);
// điều chuyển có xe: một phiếu nháp (chọn xe + tài xế), một phiếu đang vận chuyển (kho đến kiểm tra)
$fleet = $f->c->get(\StockFlow\Service\FleetService::class);
$driverId = $fleet->addDriver($f->approverA, 'Tài xế thử', (string)random_int(100000000000, 999999999999), '0912345678');
$fleet->addVehicle($f->approverA, '30H-' . random_int(100, 999) . '.' . random_int(10, 99), 'Xe tải 2 tấn', $f->wa, $driverId);
$ts = $f->c->get(\StockFlow\Service\TransferService::class);
$xferDraft = $ts->createDraft($f->keeperA, $f->wa, $f->wb, [$f->line('x', 1)], '', null)['voucher'];
$xferGo = $ts->createDraft($f->keeperA, $f->wa, $f->wb, [$f->line('x', 2)], '', null)['voucher'];
$ts->dispatch($f->approverA, (int)$xferGo['id'], (int)$f->db->value('SELECT id FROM xe WHERE kho_goc_id = ?', [$f->wa]), true);
$xferGoIn = $f->c->get(\StockFlow\Service\VoucherRepository::class)->pairOf($xferGo);
// xe đầu đang chạy nên kho A cần thêm một xe + tài xế sẵn sàng để phiếu nháp còn chọn được xe
$spareDriver = $fleet->addDriver($f->approverA, 'Tài xế dự phòng', (string)random_int(100000000000, 999999999999), '0912345679');
$fleet->addVehicle($f->approverA, '30H-' . random_int(100, 999) . '.' . random_int(10, 99), 'Xe tải 1 tấn', $f->wa, $spareDriver);
$thirdDriver = $fleet->addDriver($f->approverA, 'Tài xế thứ ba', (string)random_int(100000000000, 999999999999), '0912345670');
$fleet->addVehicle($f->approverA, '30H-' . random_int(100, 999) . '.' . random_int(10, 99), 'Xe tải 1 tấn', $f->wa, $thirdDriver);   // từ hai xe sẵn sàng trở lên mới có form chia hàng nhiều xe

$port = random_int(19000, 19999);
$sessions = sys_get_temp_dir() . '/sf-sess-' . bin2hex(random_bytes(4));
mkdir($sessions, 0700);
$server = proc_open([PHP_BINARY, '-d', "session.save_path=$sessions", '-S', "127.0.0.1:$port", '-t', dirname(__DIR__, 2) . '/public', dirname(__DIR__, 2) . '/public/index.php'],
    [1 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'], 2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w']], $pipes);
register_shutdown_function(function () use ($server, $sessions) {
    proc_terminate($server);
    foreach (glob("$sessions/*") ?: [] as $s) { @unlink($s); }
    @rmdir($sessions);
});
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) { usleep(100_000); }

$cookie = '';
$get = function (string $path) use ($port, &$cookie): array {
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'follow_location' => 0, 'header' => "Cookie: $cookie\r\n"]]);
    $body = (string)file_get_contents("http://127.0.0.1:$port$path", false, $ctx);
    foreach ($http_response_header ?? [] as $h) {
        if (stripos($h, 'Set-Cookie: STOCKFLOW=') === 0) { $cookie = 'STOCKFLOW=' . explode(';', substr($h, 22))[0]; }
    }
    preg_match('#^HTTP/\S+ (\d+)#', $http_response_header[0] ?? '', $m);
    return [(int)($m[1] ?? 0), $body];
};
$login = function (string $username) use ($get, $port, &$cookie): void {
    $cookie = '';
    [, $page] = $get('/login');
    preg_match('/name="csrf" value="([a-f0-9]+)"/', $page, $m);
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'ignore_errors' => true, 'follow_location' => 0,
        'header' => "Cookie: $cookie\r\nContent-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query(['csrf' => $m[1], 'username' => $username, 'password' => Fx::PASSWORD])]]);
    file_get_contents("http://127.0.0.1:$port/login", false, $ctx);
    foreach ($http_response_header ?? [] as $h) {   // đăng nhập đổi session id ⇒ lấy cookie mới
        if (stripos($h, 'Set-Cookie: STOCKFLOW=') === 0) { $cookie = 'STOCKFLOW=' . explode(';', substr($h, 22))[0]; }
    }
};
$noPhpError = fn(string $body) => !preg_match('/(Fatal error|Warning:|Notice:|Deprecated:|Undefined)/', $body);

section('Chủ hệ thống (tài khoản cấp cao): xem thống kê mọi kho, quản lý — không có trang tác nghiệp');
$login($f->both->username);
$pages = ['/', '/inventory', '/inventory?view=batch', '/inventory?q=' . $f->it['x']['ma_hang'], '/vouchers', '/vouchers?status=DRAFT', '/vouchers?type=TRANSFER_OUT&q=DC',
    "/vouchers/{$issue['id']}", "/vouchers/{$transfer['id']}", "/vouchers/{$pair['id']}", "/vouchers/{$xferDraft['id']}", "/vouchers/{$xferGo['id']}", "/vouchers/{$xferGoIn['id']}",
    '/counts', "/counts/$cid", '/history', '/history?type=TRANSFER_IN&from=2020-01-01&to=2099-01-01', '/audit', '/audit?group=voucher&q=phi', '/audit?format=csv', '/settings?tab=he-thong', '/reports/nxt', '/reports/nxt?format=csv', '/reports/stock-card?item=' . $f->it['x']['item'], '/customer-returns', '/orders', '/import', '/labels', '/labels?q=x', '/labels/print?sel[]=' . $f->it['x']['item'] . '&format=thermal', '/vouchers?format=csv', '/warranty?format=csv', '/fleet?tab=xe&format=csv', '/fleet?tab=taixe&format=csv', '/reconcile', '/reports', '/valuation', '/returns', '/returns?status=DA_CAP_LAI', '/warranty', '/warranty?tab=WAITING&q=a', '/disposals', '/disposals?status=CHO_DUYET&q=a', '/money/in', '/money/out', '/money/profit', '/money/profit?q=x&range=1m', '/money/out?range=custom&from=2020-01-01&to=2099-01-01&q=P', '/warehouses', '/inventory?stock=low', '/inventory?stock=out', '/users', '/roles', '/fleet', '/fleet?q=a&kho=1&tt=off&ai=CONG_TY'];
// (admin xem được Đội xe nhưng chỉ xem: không có form thêm xe)
foreach ($pages as $p) {
    [$status, $body] = $get($p);
    same($status, 200, "admin GET $p ⇒ 200");
    truthy($noPhpError($body), "admin GET $p: không lộ lỗi PHP");
}
[$st, $adminInv] = $get('/inventory');
truthy($st === 200 && $noPhpError($adminInv) && str_contains($adminInv, 'name="weight_kien"') && str_contains($adminInv, 'name="kien_l"'), 'quản trị: form hàng hoá có ô cân nặng và kích thước 1 kiện');
[$st, $adminRules] = $get('/fleet?tab=quydinh');
truthy($st === 200 && $noPhpError($adminRules) && str_contains($adminRules, 'Chỉnh quy định') && str_contains($adminRules, 'name="quy_doi_kg_m3"'), 'quản trị xem được «Quy định vận tải» kèm form chỉnh');
// phiếu in để ký: chỉ phiếu đã ghi sổ; phiếu xuất có tiền + bằng chữ + 3 ô ký, điều chuyển có xe / tài xế
$issuePrint = $f->draftIssue($f->wa, 'x', 1);
$f->c->get(\StockFlow\Service\VoucherService::class)->confirm($f->approverA, (int)$issuePrint['id']);
[$st, $pr] = $get("/vouchers/{$issuePrint['id']}/print");
truthy($st === 200 && $noPhpError($pr) && str_contains($pr, 'Phiếu xuất kho') && str_contains($pr, 'Người nhận hàng') && str_contains($pr, 'data-print'), 'phiếu xuất đã ghi sổ: trang in có tiêu đề, nút in, ô ký người nhận');
[$st, $pr] = $get("/vouchers/{$xferGo['id']}/print");
truthy($st === 200 && $noPhpError($pr) && str_contains($pr, 'Tài xế nhận hàng') && str_contains($pr, 'Giá gốc') && !str_contains($pr, 'Thuế GTGT'), 'phiếu điều chuyển đi đã ghi sổ: có ô ký tài xế, ghi giá gốc và tổng giá trị, không có thuế');
same($get("/vouchers/{$xferDraft['id']}/print")[0], 303, 'phiếu nháp chưa in được ⇒ chuyển về trang phiếu');
same($get('/items')[0], 303, 'trang «Mặt hàng & lô» cũ chuyển sang «Hàng hoá» (/inventory)');
[, $dash] = $get('/');
truthy(str_contains($dash, 'Nhập – xuất theo kho') && str_contains($dash, 'A' . $f->tag) && str_contains($dash, 'B' . $f->tag), 'tổng quan có biểu đồ nhập – xuất theo từng kho');
[, $rep] = $get('/reports');
truthy(str_contains($rep, 'Từng kho: tồn') && str_contains($rep, 'A' . $f->tag) && str_contains($rep, 'B' . $f->tag), 'trang Báo cáo liệt kê từng kho: tồn và xuất nhập');
foreach (['/vouchers/new?type=RECEIPT', '/vouchers/new?type=ISSUE', '/transfers/new'] as $p) {
    same($get($p)[0], 403, "admin GET $p ⇒ 403 (không lập phiếu)");
}
foreach (['/reports/stock.csv', '/reports/movements.csv', '/reports/reconcile.csv'] as $p) {
    [$status, $body] = $get($p);
    same([$status, str_starts_with($body, "\xEF\xBB\xBF")], [200, true], "GET $p ⇒ CSV hợp lệ");
}
same($get('/khong-ton-tai')[0], 404, 'đường dẫn lạ ⇒ 404');
same($get('/vouchers/99999999')[0], 404, 'phiếu nhập xuất không tồn tại ⇒ 404');

section('Quản lý kho A: tác nghiệp trong kho mình, không vào trang quản trị, không xem kho B');
$login($f->approverA->username);
foreach (['/', '/inventory', '/vouchers', '/vouchers/new?type=RECEIPT', '/vouchers/new?type=ISSUE', '/transfers/new', "/vouchers/{$issue['id']}",
          '/orders/new', '/import/template?type=stock', '/reports/nxt?warehouse=' . $f->wa,
          '/counts', "/counts/$cid", '/history', '/reconcile', '/reports', '/valuation', '/money/in', '/money/out', '/money/profit', '/warehouses', '/inventory?stock=in', '/returns', '/warranty', '/warranty?tab=DA_TRA', '/disposals', '/disposals?status=TU_CHOI'] as $p) {
    [$status, $body] = $get($p);
    same($status, 200, "quản lý GET $p ⇒ 200");
    truthy($noPhpError($body), "quản lý GET $p: không lộ lỗi PHP");
}
same($get("/vouchers/{$pair['id']}")[0], 403, 'phiếu nhập xuất của kho B ⇒ 403');
[, $draftPage] = $get("/vouchers/{$xferDraft['id']}");
truthy(str_contains($draftPage, 'name="vehicle_id"') && str_contains($draftPage, 'name="driver_ok"') && str_contains($draftPage, 'name="driver_id"'), 'phiếu điều chuyển nháp: chỉ chọn xe (tài xế đi theo xe) và tài xế xác nhận đủ hàng');
truthy(str_contains($draftPage, 'data-convoy') && str_contains($draftPage, 'name="extra['), 'phiếu điều chuyển nháp có ≥ 2 xe sẵn sàng: hiện nút «Thêm xe chở cùng» dưới ô chọn xe');
$f->db->exec('UPDATE kho SET dia_chi = ? WHERE id = ?', ['KCN Thăng Long, Đông Anh, Hà Nội', $f->wa]);   // bản đồ lộ trình cần địa chỉ nhận ra được tỉnh
$f->db->exec('UPDATE kho SET dia_chi = ? WHERE id = ?', ['KCN Hoà Khánh, Liên Chiểu, Đà Nẵng', $f->wb]);
// phiếu xuất giao tận nơi bằng xe của kho: có bản đồ lộ trình kho → địa chỉ khách (xe chạy 5 giây)
$mv = $f->db->one('SELECT id FROM xe WHERE kho_goc_id = ? AND kho_hien_tai_id = ? AND hoat_dong ORDER BY id DESC LIMIT 1', [$f->wa, $f->wa]);
$dIssue = $f->draftIssue($f->wa, 'x', 2);
$f->c->get(\StockFlow\Service\VoucherService::class)->confirm($f->approverA, (int)$dIssue['id'], ['hinh_thuc' => 'NOI_BO', 'thanh_toan' => 'COD', 'dia_chi' => '12 Nguyễn Trãi, Phường Thanh Xuân, Thành phố Hà Nội',
    'nguoi_nhan' => 'Nguyễn Thị Hoa', 'sdt_nhan' => '0912000111', 'vehicle_id' => (int)$mv['id'], 'driver_ok' => true, 'km' => 8, 'tien' => 40000, 'ghi_chu' => '']);
[$st, $delPage] = $get("/vouchers/{$dIssue['id']}");
truthy($st === 200 && $noPhpError($delPage) && str_contains($delPage, 'Lộ trình giao hàng') && str_contains($delPage, '&quot;mode&quot;:&quot;delivery&quot;') && str_contains($delPage, 'id="route-replay"'), 'phiếu xuất giao tận nơi: có bản đồ lộ trình kho → khách (xe chạy 5 giây)');
$tuLay = $f->draftIssue($f->wa, 'x', 1);
$f->c->get(\StockFlow\Service\VoucherService::class)->confirm($f->approverA, (int)$tuLay['id'], ['hinh_thuc' => 'TU_LAY', 'thanh_toan' => 'DA_TT', 'phuong_thuc_tt' => 'TIEN_MAT']);
truthy(!str_contains($get("/vouchers/{$tuLay['id']}")[1], 'Lộ trình giao hàng'), 'khách tự lấy thì không có bản đồ giao hàng');
$ts->reportBreakdown($f->approverA, (int)$xferGo['id'], 'Hỏng động cơ');
[$st, $goPage] = $get("/vouchers/{$xferGo['id']}");
truthy($st === 200 && $noPhpError($goPage) && str_contains($goPage, 'hỏng giữa đường') && str_contains($goPage, 'đã sửa xong, chạy tiếp') && str_contains($goPage, 'data-route') && str_contains($goPage, '&quot;broken&quot;'), 'kho đi thấy xe hỏng: cảnh báo, nút chạy tiếp và xe hỏng trên bản đồ');
$f->db->exec('UPDATE mat_hang SET khoi_luong_g = 20000, dai_mm = 600, rong_mm = 400, cao_mm = 300 WHERE id = ?', [$f->it['x']['item']]);
$f->db->exec('UPDATE xe SET tai_trong_kg = 1000 WHERE kho_goc_id = ?', [$f->wa]);
[$st, $loadPage] = $get("/vouchers/{$xferDraft['id']}");
truthy($st === 200 && $noPhpError($loadPage) && str_contains($loadPage, 'Hàng cần chở') && str_contains($loadPage, '20 kg') && str_contains($loadPage, '1 tấn') && str_contains($loadPage, 'name="ext_lic_class"'), 'phiếu điều chuyển nháp: tóm tắt tải hàng, tải trọng từng xe trong danh sách chọn, ô bằng lái cho xe thuê ngoài');
[$st, $invPage] = $get('/inventory?q=' . $f->it['x']['ma_hang']);   // lọc theo SKU để không phụ thuộc phân trang
truthy($st === 200 && $noPhpError($invPage) && str_contains($invPage, 'Cân nặng · kích thước') && str_contains($invPage, '<b>20 kg</b>') && str_contains($invPage, '60 × 40 × 30 cm'), 'trang Hàng hoá có cột cân nặng · kích thước của 1 đơn vị (20 kg, 60 × 40 × 30 cm)');
[$st, $newIssue] = $get('/vouchers/new?type=ISSUE');
truthy($st === 200 && $noPhpError($newIssue) && str_contains($newIssue, '&quot;pack&quot;') && str_contains($newIssue, '&quot;kg&quot;'), 'form phiếu xuất nhận quy cách + cân nặng để nhập theo kiện');
[$st, $rulesPage] = $get('/fleet?tab=quydinh');
truthy($st === 200 && $noPhpError($rulesPage) && str_contains($rulesPage, 'Hạng giấy phép lái xe') && str_contains($rulesPage, 'Xe nào cần bằng gì') && !str_contains($rulesPage, 'Chỉnh quy định'), 'quản lý kho xem được «Quy định vận tải» nhưng không có form chỉnh');
[$st, $xePage] = $get('/fleet?tab=xe');
truthy($st === 200 && $noPhpError($xePage) && str_contains($xePage, 'Tải trọng · kích thước · giấy tờ') && str_contains($xePage, 'name="cap_t"') && str_contains($xePage, 'name="gplx_class"'), 'tab Xe: cột tải trọng / giấy tờ / bằng lái và form khai thông số xe, bằng lái');
[$st, $payTab] = $get('/fleet?tab=diem');
truthy($st === 200 && $noPhpError($payTab) && str_contains($payTab, 'data-trips-toggle') && str_contains($payTab, 'id="trips-') && str_contains($payTab, 'Tổng tiền các chuyến đã giao'), 'tab Điểm & thu nhập: bấm «N chuyến» mở danh sách chuyến thật của tài xế');
[$st, $driverTab] = $get('/fleet?tab=taixe');
truthy($st === 200 && $noPhpError($driverTab) && str_contains($driverTab, 'Thêm tài xế') && str_contains($driverTab, 'name="gplx_class"') && str_contains($driverTab, 'không gắn với xe nào'), 'mục Tài xế: form thêm riêng (giấy phép lái xe, ngày sinh), không gắn với xe');
[$st, $fleetPage] = $get('/fleet');
truthy($st === 200 && $noPhpError($fleetPage) && str_contains($fleetPage, 'name="plate"'), 'quản lý kho A vào được trang Đội xe và thấy form thêm xe');
foreach (['/fleet?q=khongco&tt=ready&ai=TAI_XE', '/fleet?thang=2000-01', '/fleet?thang=sai&tt=zzz&q=' . urlencode('30h')] as $p) {
    [$st, $body] = $get($p);
    truthy($st === 200 && $noPhpError($body) && str_contains($body, 'data-live="results"'), "quản lý GET $p ⇒ 200, có vùng tự lọc");
}
section('Quản lý kho B: kiểm tra hàng khi nhận');
$login($f->approverB->username);
[$st, $inPage] = $get("/vouchers/{$xferGoIn['id']}");
truthy($st === 200 && $noPhpError($inPage), 'quản lý kho đến mở được phiếu đang vận chuyển');
truthy(str_contains($inPage, 'hỏng giữa đường') && !str_contains($inPage, 'đã sửa xong, chạy tiếp'), 'kho đến thấy cảnh báo xe hỏng, không có nút chạy tiếp của kho đi');
truthy(str_contains($inPage, 'name="received[') && str_contains($inPage, 'name="driver_match"') && str_contains($inPage, '/not-received') && !str_contains($inPage, 'name="full_ok"') && str_contains($inPage, 'Tài xế thử'), 'phiếu đến: có ô nhập số thực nhận, ô đúng tài xế, nút «Chưa nhận được hàng» và thông tin tài xế');
section('Quét mã vạch');
$login($f->approverA->username);
$bc = '893' . random_int(1000000000, 9999999999);
$f->c->get(\StockFlow\Service\CatalogService::class)->setBarcode($f->approverA, $f->it['x']['item'], $bc);
[$st, $json] = $get('/scan/lookup?code=' . $bc);
$j = json_decode($json, true);
truthy($st === 200 && ($j['found'] ?? false) && $j['item']['sku'] === $f->it['x']['ma_hang'] && count($j['actions']) === 3, 'quản lý quét mã đã có ⇒ thẻ mặt hàng với 3 việc: nhập / xuất / điều chuyển');
[, $json] = $get('/scan/lookup?code=0000111122223');
$j = json_decode($json, true);
truthy(($j['found'] ?? null) === false && ($j['canCreate'] ?? false) && isset($j['units'], $j['suppliers']), 'mã lạ ⇒ quản lý được mời tạo mặt hàng mới');
same($get('/scan/lookup?code=ab')[0], 422, 'mã quá ngắn ⇒ 422');
[$st, $pp] = $get('/proposals');
truthy($st === 200 && $noPhpError($pp) && !str_contains($pp, 'approve-dlg'), 'quản lý kho vào được trang Đề xuất hàng mới, không có nút duyệt');
[, $form] = $get('/vouchers/new?type=RECEIPT&item=' . $f->it['x']['item']);
truthy(str_contains($form, 'data-prefill="[[&quot;' . $f->it['x']['item'] . ':'), 'mở phiếu từ màn hình quét: mặt hàng được chọn sẵn');
[, $inv] = $get('/inventory?q=' . $f->it['x']['ma_hang']);   // lọc theo SKU để không phụ thuộc phân trang
truthy(str_contains($inv, $bc) && str_contains($inv, 'data-scan-assign'), 'trang Hàng hoá hiện mã vạch và nút gán mã cho quản lý');
truthy(str_contains($inv, 'id="scan-dlg"') && str_contains($inv, 'data-scan-open'), 'có nút và hộp thoại quét mã trên mọi trang');
$login($f->keeperA->username);
same($get('/proposals')[0], 403, 'nhân viên không vào được trang Đề xuất hàng mới');
[$st, $json] = $get('/scan/lookup?code=' . $bc);
$j = json_decode($json, true);
truthy($st === 200 && ($j['canAssign'] ?? true) === false, 'nhân viên quét được nhưng không có quyền gán mã');
[, $inv] = $get('/inventory?q=' . $f->it['x']['ma_hang']);
truthy(!str_contains($inv, 'data-scan-assign'), 'nhân viên không thấy nút gán mã vạch');
$login($f->both->username);
[$st, $pp] = $get('/proposals');
truthy($st === 200 && $noPhpError($pp) && str_contains($pp, 'approve-dlg'), 'admin vào được trang Đề xuất hàng mới và có hộp thoại duyệt');
[, $json] = $get('/scan/lookup?code=' . $bc);
$j = json_decode($json, true);
truthy(($j['found'] ?? false) && $j['actions'] === [] && $j['canAssign'] === false, 'admin quét chỉ xem thông tin, không có nút tác nghiệp');
[, $inv] = $get('/inventory?q=' . $f->it['x']['ma_hang']);
truthy(str_contains($inv, $bc) && !str_contains($inv, 'data-scan-assign'), 'admin xem được mã vạch nhưng không có nút gán');

$login($f->approverA->username);
same($get('/users')[0], 403, 'trang tài khoản chỉ dành cho admin ⇒ 403');
same($get('/roles')[0], 403, 'trang phân quyền chỉ dành cho admin ⇒ 403');
section('Kho huỷ: hàng chờ xuất đi tiêu huỷ hiện tải cần chở và xe đủ điều kiện');
$fleetD = $f->c->get(StockFlow\Service\FleetService::class);
$dispD = $f->c->get(StockFlow\Service\DisposalService::class);
$fleetD->addVehicleWithDriver($f->approverA, '29C-' . random_int(10000, 99999), 'Xe tải 2 tấn', $f->wa, 'Tài xế Huỷ', (string)random_int(100000000000, 999999999999), '0912345671');
$f->receive($f->wa, 'y', 5);
$dispId = $dispD->propose($f->approverA, $f->wa, $f->it['y']['item'], $f->it['y']['batch'], 2, 'VO_HONG', '')['id'];
[$st, $dBody] = $get('/disposals?status=TRONG_KHO_HUY');
truthy($st === 200 && $noPhpError($dBody) && str_contains($dBody, 'id="dispatch-form"') && str_contains($dBody, 'name="vehicle_id"') && str_contains($dBody, 'data-pick-dispose') && substr_count($dBody, 'name="vehicle_id"') === 1, 'Kho huỷ: một khung chọn xe chung, mỗi lô chỉ có ô tích (không còn ô chọn xe từng dòng)');
[$stF, $fitBody] = $get('/disposals/fit?ids[]=' . $dispId);
$fitJson = json_decode($fitBody, true);
truthy($stF === 200 && is_array($fitJson) && $fitJson['count'] === 1 && str_contains($fitJson['summary'], 'Hàng cần chở') && (str_contains($fitJson['options'], 'đủ điều kiện') || str_contains($fitJson['options'], 'chưa khai đủ')), 'Kho huỷ: chọn lô thì máy chủ trả tóm tắt tải và xe đủ điều kiện cho đúng các lô đã tích');

section('Nhận hàng theo đơn đặt: bảng đối chiếu «Theo đơn» và ô quét đếm');
$ordSvc = $f->c->get(StockFlow\Service\OrderService::class);
$supPo = (int)$f->db->insert("INSERT INTO doi_tac (loai, ma, ten) VALUES ('SUPPLIER', ?, ?)", ['NCCPO' . $f->tag, 'NCC đặt hàng ' . $f->tag]);
$f->db->exec('INSERT INTO mat_hang_nha_cung_cap (mat_hang_id, doi_tac_id) VALUES (?, ?)', [$f->it['y']['item'], $supPo]);
$poId = $ordSvc->create($f->approverA, $f->wa, 'NCC đặt hàng ' . $f->tag, [['mat_hang_id' => $f->it['y']['item'], 'so_luong' => 10]], null, '');
$ordSvc->markSent($f->approverA, $poId);
[$stP, $poBody] = $get('/vouchers/new?type=RECEIPT&po=' . $poId);
truthy($stP === 200 && $noPhpError($poBody) && str_contains($poBody, 'data-order=') && str_contains($poBody, 'id="count-code"') && str_contains($poBody, 'Theo đơn') && str_contains($poBody, 'Thực nhận') && str_contains($poBody, 'data-scan-count'), 'nhận theo đơn: có cột «Theo đơn», «Thực nhận» và ô quét để đếm');
[$stN, $noPoBody] = $get('/vouchers/new?type=RECEIPT');
truthy($stN === 200 && !str_contains($noPoBody, 'data-order=') && !str_contains($noPoBody, 'id="count-code"'), 'phiếu nhập thường không có cột «Theo đơn» và ô quét đếm');

finish();
