<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$options = getopt('', ['base-path:', 'account::']);
$base = realpath((string)($options['base-path'] ?? dirname(__DIR__, 3)));
if (!$base || !is_file($base . '/app/Core/Database.php') || !is_file($base . '/config/config.php')) {
    fwrite(STDERR, "NexaPressのパスを --base-path=/path/to/NexaPress で指定してください。\n"); exit(1);
}
define('BASE_PATH', $base); define('NEXAPRESS_MAIL_PATH', dirname(__DIR__));
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'app\\')) {
        $path = BASE_PATH . '/' . str_replace('\\', '/', $class) . '.php';
        if (is_file($path)) { require_once $path; }
    }
});
require NEXAPRESS_MAIL_PATH . '/vendor/autoload.php';
$general = is_file($base . '/config/general.php') ? require $base . '/config/general.php' : [];
date_default_timezone_set($general['timezone'] ?? 'Asia/Tokyo');
try {
    NexaPressMail\Database\MigrationRunner::run();
    $result = (new NexaPressMail\Services\Receive\MailSyncService())->sync(isset($options['account']) ? (int)$options['account'] : null, [], true);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit($result['errors'] ? 1 : 0);
} catch (Throwable $error) { fwrite(STDERR, '自動同期に失敗しました: ' . $error->getMessage() . PHP_EOL); exit(1); }
