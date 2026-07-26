<?php

namespace NexaPressMail\Bootstrap;

use app\Core\Router;
use NexaPressMail\Database\MigrationRunner;
use Throwable;

final class ExtensionBoot
{
    public static function boot(Router $router): void
    {
        MigrationRunner::run();
        RouteRegistrar::register($router);
        AssetRegistrar::register();
    }

    public static function config(string $name): array
    {
        $path = NEXAPRESS_MAIL_PATH . '/config/' . $name . '.php';
        $value = is_file($path) ? require $path : [];
        return is_array($value) ? $value : [];
    }
}
