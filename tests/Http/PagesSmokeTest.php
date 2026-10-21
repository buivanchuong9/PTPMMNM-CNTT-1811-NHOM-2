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
$transfer = $f->c->get(\StockFlow\Service\TransferService::class)->createDraft($f->both, $f->wa, $f->wb, [$f->line('x', 2)], '', null)['voucher'];
$f->c->get(\StockFlow\Service\TransferService::class)->confirm($f->both, (int)$transfer['id']);
$pair = $f->c->get(\StockFlow\Service\VoucherRepository::class)->pairOf($transfer);

$port = random_int(19000, 19999);
$sessions = sys_get_temp_dir() . '/sf-sess-' . bin2hex(random_bytes(4));
mkdir($sessions, 0700);
$server = proc_open([PHP_BINARY, '-d', "session.save_path=$sessions", '-S', "127.0.0.1:$port", '-t', dirname(__DIR__, 2) . '/public', dirname(__DIR__, 2) . '/public/index.php'],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
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
[, $page] = $get('/login');
preg_match('/name="csrf" value="([a-f0-9]+)"/', $page, $m);
$ctx = stream_context_create(['http' => ['method' => 'POST', 'ignore_errors' => true, 'follow_location' => 0,
    'header' => "Cookie: $cookie\r\nContent-Type: application/x-www-form-urlencoded\r\n",
    'content' => http_build_query(['csrf' => $m[1], 'username' => $f->both->username, 'password' => Fx::PASSWORD])]]);
file_get_contents("http://127.0.0.1:$port/login", false, $ctx);
foreach ($http_response_header ?? [] as $h) {   // đăng nhập đổi session id ⇒ lấy cookie mới
    if (stripos($h, 'Set-Cookie: STOCKFLOW=') === 0) { $cookie = 'STOCKFLOW=' . explode(';', substr($h, 22))[0]; }
}

section('Các trang dành cho người dùng có quyền ở cả hai kho');
$pages = ['/', '/inventory', '/inventory?view=batch', '/inventory?q=' . $f->it['x']['sku'], '/vouchers', '/vouchers?status=DRAFT', '/vouchers?type=TRANSFER_OUT&q=DC',
    '/vouchers/new?type=RECEIPT', '/vouchers/new?type=ISSUE', '/transfers/new', "/vouchers/{$issue['id']}", "/vouchers/{$transfer['id']}", "/vouchers/{$pair['id']}",
    '/counts', "/counts/$cid", '/history', '/history?type=TRANSFER_IN&from=2020-01-01&to=2099-01-01', '/reconcile', '/reports', '/warehouses', '/items'];
foreach ($pages as $p) {
    [$status, $body] = $get($p);
    same($status, 200, "GET $p ⇒ 200");
    truthy(!preg_match('/(Fatal error|Warning:|Notice:|Deprecated:|Undefined)/', $body), "GET $p: không lộ lỗi PHP");
}
foreach (['/reports/stock.csv', '/reports/movements.csv', '/reports/reconcile.csv'] as $p) {
    [$status, $body] = $get($p);
    same([$status, str_starts_with($body, "\xEF\xBB\xBF")], [200, true], "GET $p ⇒ CSV hợp lệ");
}
same($get('/khong-ton-tai')[0], 404, 'đường dẫn lạ ⇒ 404');
same($get('/vouchers/99999999')[0], 404, 'chứng từ không tồn tại ⇒ 404');
same($get('/users')[0], 403, 'trang tài khoản chỉ dành cho admin ⇒ 403');
finish();
