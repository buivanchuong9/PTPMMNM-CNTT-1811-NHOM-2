<?php
declare(strict_types=1);

namespace StockFlow\Core;

/** Router tối giản: khớp method + mẫu đường dẫn có tham số {id}. */
final class Router
{
    /** @var list<array{0:string,1:string,2:array{0:class-string,1:string},3:bool}> */
    private array $routes = [];

    public function get(string $pattern, array $handler, bool $public = false): void
    {
        $this->add('GET', $pattern, $handler, $public);
    }

    public function post(string $pattern, array $handler, bool $public = false): void
    {
        $this->add('POST', $pattern, $handler, $public);
    }

    private function add(string $method, string $pattern, array $handler, bool $public): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>\d+)', $pattern) . '$#';
        $this->routes[] = [$method, $regex, $handler, $public];
    }

    /** @return array{handler:array,params:array<string,string>,public:bool}|null */
    public function match(string $method, string $path): ?array
    {
        foreach ($this->routes as [$m, $regex, $handler, $public]) {
            if ($m === $method && preg_match($regex, $path, $mm)) {
                return ['handler' => $handler, 'params' => array_filter($mm, 'is_string', ARRAY_FILTER_USE_KEY), 'public' => $public];
            }
        }
        return null;
    }

    public function knowsPath(string $path): bool
    {
        foreach ($this->routes as [, $regex]) {
            if (preg_match($regex, $path)) {
                return true;
            }
        }
        return false;
    }
}
