<?php
declare(strict_types=1);

define('NEXAPRESS_MAIL_PATH', dirname(__DIR__));
require NEXAPRESS_MAIL_PATH . '/vendor/autoload.php';

$directory = $argv[1];
file_put_contents($directory . '/ready-' . (int)$argv[2], 'ready');
$key = (new NexaPressMail\Services\Security\EncryptionKeyManager($directory))->key();
echo hash('sha256', $key);
