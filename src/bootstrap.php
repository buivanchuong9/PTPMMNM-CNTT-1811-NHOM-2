<?php
declare(strict_types=1);

// PSR-4 autoloader (namespace StockFlow\ → src/) — không cần Composer để chạy.
spl_autoload_register(static function (string $class): void {
    $prefix = 'StockFlow\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
require __DIR__ . '/Core/helpers.php';
