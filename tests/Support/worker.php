<?php
declare(strict_types=1);

// Tiến trình con cho kiểm thử đồng thời: tham số là JSON {action, voucher, user, sleepMs, gate}.
require __DIR__ . '/../../src/bootstrap.php';

use StockFlow\Core\Config;
use StockFlow\Core\Container;
use StockFlow\Core\Db;
use StockFlow\Core\Hooks;
use StockFlow\Domain\DomainError;
use StockFlow\Domain\Forbidden;
use StockFlow\Domain\InsufficientStock;
use StockFlow\Service\AuthService;
use StockFlow\Service\VoucherService;

$job = json_decode($argv[1], true);
$c = new Container();
$c->set(Db::class, Db::connect(Config::database()));
$user = $c->get(AuthService::class)->find((int)$job['user']);
if (!empty($job['sleepMs'])) {
    // Giữ khoá tồn thêm một lúc để buộc các giao dịch chồng lên nhau.
    Hooks::on('ledger.after_lock', fn() => usleep((int)$job['sleepMs'] * 1000));
    Hooks::on('ledger.before_write', fn() => usleep((int)$job['sleepMs'] * 1000)); // giữa lúc đọc tồn và ghi
}
$deadline = microtime(true) + 10;
while (!file_exists($job['gate']) && microtime(true) < $deadline) {
    usleep(1000);
}
try {
    $res = $c->get(VoucherService::class)->confirm($user, (int)$job['voucher']);
    echo json_encode(['status' => $res['already'] ? 'already' : 'ok']);
} catch (InsufficientStock $e) {
    echo json_encode(['status' => 'insufficient', 'msg' => $e->getMessage()]);
} catch (Forbidden | DomainError $e) {
    echo json_encode(['status' => 'rejected', 'msg' => $e->getMessage()]);
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'msg' => $e::class . ': ' . $e->getMessage()]);
}
