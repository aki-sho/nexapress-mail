<?php

namespace NexaPressMail\Services\Smtp {
    // UI fixture: never connect to a real server or send mail.
    final class SmtpClient {
        public function send(array $account, array $message, array $attachments = []): array { throw new \RuntimeException('SMTP simulated offline'); }
    }
}

namespace {
    $root = realpath((string)getenv('NPM_UI_TEST_DIR'));
    if (!$root || !preg_match('/^npm-ui-[a-zA-Z0-9]+$/', basename($root))) { http_response_code(500); exit; }
    define('BASE_PATH', $root); define('NEXAPRESS_MAIL_PATH', dirname(__DIR__));
    ini_set('session.save_path', $root); session_start();
    require NEXAPRESS_MAIL_PATH . '/vendor/autoload.php'; require __DIR__ . '/support.php';
    function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    function url(string $path): string { return '/' . ltrim($path, '/'); }
    $pdo = NexaPressMail\Tests\ImapFixture::database('sqlite:' . $root . '/fixture.sqlite');
    if (!(new NexaPressMail\Models\AccountRepository())->all()) {
        (new NexaPressMail\Models\AccountRepository())->save(['name'=>'Fixture','email'=>'test@example.com',
            'smtp_host'=>'smtp.example.com','smtp_port'=>587,'smtp_security'=>'tls','smtp_username'=>'test','smtp_password'=>'dummy',
            'imap_host'=>'imap.example.com','imap_port'=>993,'imap_security'=>'ssl','imap_username'=>'test','imap_password'=>'dummy',
            'folder_sent'=>'Sent','folder_drafts'=>'Drafts','folder_trash'=>'Trash','is_active'=>1,'is_default'=>1]);
    }
    $layout = $root . '/app/Views/admin/layout.php';
    if (!is_file($layout)) {
        mkdir(dirname($layout), 0700, true);
        file_put_contents($layout, '<!doctype html><html lang="ja"><meta charset="utf-8"><link rel="stylesheet" href="/assets/css/mail.css"><body><?= $content ?><script src="/assets/js/mail.js"></script></body></html>');
    }
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH); $base = '/admin/extensions/nexapress-mail';
    try {
        if ($path === '/assets/js/mail.js' || $path === '/assets/css/mail.css') {
            header('Content-Type: ' . (str_ends_with($path, '.js') ? 'application/javascript' : 'text/css'));
            readfile(NEXAPRESS_MAIL_PATH . $path); return;
        }
        if ($path === '/fixture/draft') {
            header('Content-Type: application/json'); echo json_encode((new NexaPressMail\Models\MessageRepository())->find((int)$_GET['id'])); return;
        }
        if ($path === $base . '/compose') { (new NexaPressMail\Controllers\ComposeController())->form(); return; }
        if ($path === $base . '/draft/autosave') { (new NexaPressMail\Controllers\ComposeController())->autosave(); return; }
        if ($path === $base . '/draft/recovery') { (new NexaPressMail\Controllers\ComposeController())->recovery(); return; }
        if ($path === $base . '/send') { (new NexaPressMail\Controllers\ComposeController())->send(); return; }
        if ($path === $base . '/draft') { (new NexaPressMail\Controllers\ComposeController())->draft(); return; }
        if ($path === $base . '/accounts') { (new NexaPressMail\Controllers\AccountController())->index(); return; }
        if ($path === $base . '/sync/status') { (new NexaPressMail\Controllers\SyncController())->status(); return; }
        if ($path === $base . '/dashboard') {
            ob_start(); (new NexaPressMail\Controllers\DashboardController())->content(); $content = ob_get_clean(); require $layout; return;
        }
        http_response_code(404);
    } catch (Throwable $error) {
        if (http_response_code() < 400) { http_response_code(500); }
        header('Content-Type: text/plain'); echo $error->getMessage();
    }
}
