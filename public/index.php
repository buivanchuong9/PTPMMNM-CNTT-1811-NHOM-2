<?php
declare(strict_types=1);

// Front controller. Chạy: php -S 127.0.0.1:8080 -t public public/index.php
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (PHP_SAPI === 'cli-server' && $path !== '/' && is_file(__DIR__ . $path)) {
    return false; // để máy chủ built-in phục vụ file tĩnh trong public/assets
}

ini_set('display_errors', getenv('APP_DEBUG') === '1' ? '1' : '0'); // không rò rỉ đường dẫn/SQL ra trình duyệt
require __DIR__ . '/../src/bootstrap.php';

use StockFlow\Core\App;
use StockFlow\Core\Request;
use StockFlow\Core\Response;

try {
    (new App())->handle(Request::fromGlobals())->send();
} catch (Throwable $e) { // chỉ xảy ra khi chưa kết nối được CSDL
    error_log((string)$e);
    (new Response(
        '<!doctype html><meta charset="utf-8"><title>Chưa kết nối CSDL — Chương Đại Dương</title><body style="font-family:system-ui;max-width:560px;margin:15vh auto;padding:0 1rem">'
        . '<h1>Chưa kết nối được CSDL</h1><p>Mã lỗi: <code>' . htmlspecialchars($e->getMessage()) . '</code></p>'
        . '<p>Xem thử không cần CSDL: <code>bin/stockflow demo</code>. Dùng MySQL: <code>bin/stockflow db-config</code> rồi <code>bin/stockflow setup</code>.</p>',
        503
    ))->send();
}
