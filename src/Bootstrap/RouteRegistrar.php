<?php

namespace NexaPressMail\Bootstrap;

use app\Core\Router;
use NexaPressMail\Controllers\AccountController;
use NexaPressMail\Controllers\ActionController;
use NexaPressMail\Controllers\AttachmentController;
use NexaPressMail\Controllers\ComposeController;
use NexaPressMail\Controllers\LogController;
use NexaPressMail\Controllers\MailboxController;
use NexaPressMail\Controllers\SyncController;

final class RouteRegistrar
{
    public static function register(Router $router): void
    {
        $b = '/admin/extensions/nexapress-mail';
        $router->get($b . '/mailbox/{folder}', MailboxController::class . '@index');
        $router->get($b . '/message/{id}', MailboxController::class . '@show');
        $router->get($b . '/compose', ComposeController::class . '@form');
        $router->post($b . '/send', ComposeController::class . '@send');
        $router->post($b . '/draft', ComposeController::class . '@draft');
        $router->post($b . '/message/{id}/action', ActionController::class . '@message');
        $router->post($b . '/bulk', ActionController::class . '@bulk');
        $router->get($b . '/accounts', AccountController::class . '@index');
        $router->get($b . '/accounts/edit/{id}', AccountController::class . '@edit');
        $router->post($b . '/accounts/save', AccountController::class . '@save');
        $router->post($b . '/accounts/delete/{id}', AccountController::class . '@delete');
        $router->post($b . '/accounts/test/{protocol}', AccountController::class . '@test');
        $router->post($b . '/sync', SyncController::class . '@sync');
        $router->get($b . '/logs', LogController::class . '@index');
        $router->get($b . '/attachment/{id}', AttachmentController::class . '@download');
    }
}
