<?php
declare(strict_types=1);

namespace StockFlow\Core;

use RuntimeException;

/**
 * Cấu hình CSDL: ưu tiên biến môi trường (DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS),
 * nếu không có thì đọc .local/db.json (tạo bởi `bin/stockflow db-config`, quyền 0600).
 */
final class Config
{
    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return array{driver?:string,path?:string,host?:string,port?:string,database?:string,user?:string,password?:string} */
    public static function database(): array
    {
        // Chế độ demo: một file SQLite, không cần cài MySQL (bin/stockflow demo).
        if (($sqlite = getenv('STOCKFLOW_SQLITE')) !== false && $sqlite !== '') {
            return ['driver' => 'sqlite', 'path' => $sqlite];
        }
        $env = [
            'host' => getenv('DB_HOST'), 'port' => getenv('DB_PORT'), 'database' => getenv('DB_NAME'),
            'user' => getenv('DB_USER'), 'password' => getenv('DB_PASS'),
        ];
        if (!in_array(false, $env, true)) {
            $config = $env;
        } else {
            $path = self::root() . '/.local/db.json';
            if (!is_file($path)) {
                throw new RuntimeException('DATABASE_CONFIG_MISSING');
            }
            $config = json_decode((string)file_get_contents($path), true);
        }
        foreach (['host', 'port', 'database', 'user', 'password'] as $key) {
            if (!is_array($config) || !isset($config[$key]) || !is_string($config[$key])) {
                throw new RuntimeException('DATABASE_CONFIG_INVALID');
            }
        }
        if (!ctype_digit($config['port']) || (int)$config['port'] < 1 || (int)$config['port'] > 65535) {
            throw new RuntimeException('DATABASE_CONFIG_INVALID');
        }
        return $config;
    }
}
