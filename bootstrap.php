<?php

use app\Core\Router;
use NexaPressMail\Bootstrap\ExtensionBoot;

if (!defined('BASE_PATH') || !isset($router) || !$router instanceof Router) {
    return;
}

define('NEXAPRESS_MAIL_PATH', __DIR__);
define('NEXAPRESS_MAIL_ID', 'nexapress-mail');

$autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoload)) {
    error_log('[NexaPress Mail] vendor/autoload.php がありません。');
    return;
}
require_once $autoload;

try {
    ExtensionBoot::boot($router);
} catch (Throwable $exception) {
    error_log('[NexaPress Mail] 起動失敗: ' . $exception->getMessage());
    if (function_exists('add_action')) {
        add_action('admin_head', static function () use ($exception): void {
            echo '<div class="notice error"><p>NexaPress Mail: ';
            echo e($exception->getMessage()) . '</p></div>';
        });
    }
}
