<?php
declare(strict_types=1);

/**
 * Chạy toàn bộ kiểm thử.
 *  - tests/Unit: thuần, không cần CSDL.
 *  - tests/Integration + tests/Http: cần MySQL; chạy trên CSDL RIÊNG "<tên CSDL>_test" (hoặc TEST_DB_NAME),
 *    bị XOÁ SẠCH và dựng lại mỗi lần chạy — không bao giờ đụng dữ liệu thật/demo.
 */
require __DIR__ . '/../src/bootstrap.php';

use StockFlow\Core\Config;
use StockFlow\Core\Db;
use StockFlow\Core\Migrator;

$root = dirname(__DIR__);
$failed = 0;
$summary = [];

$runFile = function (string $file) use (&$failed, &$summary, $root): void {
    $name = str_replace($root . '/', '', $file);
    echo "\n━━ $name\n";
    $proc = proc_open([PHP_BINARY, $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    $rc = proc_close($proc);
    echo $out;
    preg_match('/TESTS=(\d+) FAILED=(\d+)/', $out, $m);
    $summary[$name] = [(int)($m[1] ?? 0), (int)($m[2] ?? 1), $rc];
    if ($rc !== 0) {
        $failed++;
    }
};

foreach (glob(__DIR__ . '/Unit/*Test.php') as $f) {
    $runFile($f);
}

$integration = [...glob(__DIR__ . '/Integration/*Test.php'), ...glob(__DIR__ . '/Http/*Test.php')];
$dbReady = false;
try {
    $cfg = Config::database();
    $testDb = getenv('TEST_DB_NAME') ?: $cfg['database'] . '_test';
    if ($testDb === $cfg['database']) {
        throw new RuntimeException('TEST_DB_NAME trùng CSDL thật — từ chối để không xoá dữ liệu.');
    }
    $admin = Db::connect($cfg);
    try {
        $admin->raw('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $testDb) . '` CHARACTER SET utf8mb4');
    } catch (Throwable) {
        // không đủ quyền tạo: có thể CSDL test đã được tạo sẵn — thử kết nối luôn
    }
    $cfg['database'] = $testDb;
    $test = Db::connect($cfg);
    (new Migrator($test))->dropAll();
    $log = (new Migrator($test))->migrate();
    foreach ($cfg as $k => $v) {
        putenv('DB_' . ['host' => 'HOST', 'port' => 'PORT', 'database' => 'NAME', 'user' => 'USER', 'password' => 'PASS'][$k] . '=' . $v);
    }
    echo "\nCSDL kiểm thử: $testDb (đã dựng lại schema)\n";
    foreach ($log as $l) {
        echo "  $l\n";
    }
    $dbReady = true;
} catch (Throwable $e) {
    echo "\n⚠ Bỏ qua integration/http: {$e->getMessage()}\n"
       . "  Cần MySQL ≥ 8.0.16 và một CSDL kiểm thử riêng. Ví dụ:\n"
       . "    CREATE DATABASE stockflow_test CHARACTER SET utf8mb4; GRANT ALL ON stockflow_test.* TO 'stockflow'@'localhost';\n";
    if (getenv('CI')) {
        $failed++;
    }
}

if ($dbReady) {
    foreach ($integration as $f) {
        $runFile($f);
    }
}

echo "\n══════════ Tổng kết ══════════\n";
$total = 0;
$fails = 0;
foreach ($summary as $name => [$n, $f, $rc]) {
    printf("%s %-42s %3d test%s\n", $rc === 0 ? '✔' : '✘', $name, $n, $f ? " — $f LỖI" : '');
    $total += $n;
    $fails += $f;
}
echo "TỔNG: $total test, " . ($fails + ($failed && !$fails ? 1 : 0)) . " lỗi\n";
exit($failed === 0 ? 0 : 1);
