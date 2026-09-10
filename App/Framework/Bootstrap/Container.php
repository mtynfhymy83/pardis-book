<?php

declare(strict_types=1);

namespace App\Framework\Bootstrap;

use Psr\Container\ContainerInterface;

/**
 * Singleton access point to the PHP-DI container.
 */
class Container
{
    private static ?ContainerInterface $instance = null;

    public static function getInstance(): ContainerInterface
    {
        if (self::$instance === null) {
            $factory = require __DIR__ . '/../Config/di.php';
            self::$instance = $factory();
        }
        return self::$instance;
    }

    public static function setInstance(ContainerInterface $container): void
    {
        self::$instance = $container;
    }

    public static function get(string $id): mixed
    {
        return self::getInstance()->get($id);
    }

    public static function has(string $id): bool
    {
        return self::getInstance()->has($id);
    }

    public static function make(string $class, array $parameters = []): mixed
    {
        $container = self::getInstance();
        if ($container instanceof \DI\Container) {
            return $container->make($class, $parameters);
        }
        return $container->get($class);
    }
}
