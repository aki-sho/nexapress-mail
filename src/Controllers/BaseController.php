<?php
namespace NexaPressMail\Controllers;

use app\Core\Csrf;
use app\Core\Permission;
use NexaPressMail\Support\AdminView;
use NexaPressMail\Support\FlashMessage;

abstract class BaseController
{
    public function __construct() { Permission::require('admin.manage'); }
    protected function csrf(): void { Csrf::requireValid($_POST['_csrf_token'] ?? null); }
    protected function view(string $view, array $data = [], string $title = 'NexaPress Mail'): void
    {
        $data['flash'] = FlashMessage::pull();
        AdminView::render($view, $data, $title);
    }
    protected function redirect(string $path): never { header('Location: ' . url($path)); exit; }
    protected function json(array $data, int $status = 200): void
    {
        http_response_code($status); header('Content-Type: application/json; charset=UTF-8'); header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
    protected function ok(string $message, string $path): never
    { FlashMessage::set('success', $message); $this->redirect($path); }
    protected function fail(\Throwable $error, string $path): never
    { FlashMessage::set('error', $error->getMessage()); $this->redirect($path); }
}
