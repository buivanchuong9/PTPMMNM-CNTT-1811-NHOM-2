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
    '/counts', "/counts/$cid", '/history', '/history?type=TRANSFER_IN&from=2020-01-01&to=2099-01-01', '/reconcile', '/reports', '/valuation', '/returns', '/returns?status=DA_CAP_LAI', '/warranty', '/warranty?tab=WAITING&q=a', '/disposals', '/disposals?status=CHO_DUYET&q=a', '/money/in', '/money/out', '/money/profit', '/money/profit?q=x&range=1m', '/money/out?range=custom&from=2020-01-01&to=2099-01-01&q=P', '/warehouses', '/inventory?stock=low', '/inventory?stock=out', '/users', '/roles', '/fleet', '/fleet?q=a&kho=1&tt=off&ai=CONG_TY'];
// (admin xem được Đội xe nhưng chỉ xem: không có form thêm xe)
foreach ($pages as $p) {
    [$status, $body] = $get($p);
    same($status, 200, "admin GET $p ⇒ 200");
    truthy($noPhpError($body), "admin GET $p: không lộ lỗi PHP");
}
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
          '/counts', "/counts/$cid", '/history', '/reconcile', '/reports', '/valuation', '/money/in', '/money/out', '/money/profit', '/warehouses', '/inventory?stock=in', '/returns', '/warranty', '/warranty?tab=DA_TRA', '/disposals', '/disposals?status=TU_CHOI'] as $p) {
    [$status, $body] = $get($p);
    same($status, 200, "quản lý GET $p ⇒ 200");
    truthy($noPhpError($body), "quản lý GET $p: không lộ lỗi PHP");
}
same($get("/vouchers/{$pair['id']}")[0], 403, 'phiếu nhập xuất của kho B ⇒ 403');
[, $draftPage] = $get("/vouchers/{$xferDraft['id']}");
truthy(str_contains($draftPage, 'name="vehicle_id"') && str_contains($draftPage, 'name="driver_ok"') && !str_contains($draftPage, 'name="driver_id"'), 'phiếu điều chuyển nháp: chỉ chọn xe (tài xế đi theo xe) và tài xế xác nhận đủ hàng');
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
finish();
