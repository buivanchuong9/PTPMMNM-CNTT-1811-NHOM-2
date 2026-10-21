<?php
declare(strict_types=1);

namespace StockFlow\Core;

use ReflectionClass;
use RuntimeException;

/** Container DI tối giản: tự nối dependency theo type-hint của constructor, mỗi lớp một instance. */
final class Container
{
    /** @var array<class-string,object> */
    private array $instances = [];

    public function set(string $class, object $instance): void
    {
        $this->instances[$class] = $instance;
    }

    public function has(string $class): bool
    {
        return isset($this->instances[$class]);
    }

    /** @template T of object @param class-string<T> $class @return T */
    public function get(string $class): object
    {
        if (isset($this->instances[$class])) {
            return $this->instances[$class];
        }
        $ctor = (new ReflectionClass($class))->getConstructor();
        $args = [];
        foreach ($ctor?->getParameters() ?? [] as $p) {
            $type = $p->getType();
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                throw new RuntimeException("Không tự nối được tham số \${$p->getName()} của $class");
            }
            $args[] = $this->get($type->getName());
        }
        return $this->instances[$class] = new $class(...$args);
    }
}
