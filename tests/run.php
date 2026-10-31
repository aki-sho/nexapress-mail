<?php
declare(strict_types=1);

define('NEXAPRESS_MAIL_PATH', dirname(__DIR__));
require NEXAPRESS_MAIL_PATH . '/vendor/autoload.php';

use NexaPressMail\Services\Attachment\AttachmentFilename;
use NexaPressMail\Services\Attachment\DangerousFileDetector;
use NexaPressMail\Services\Security\HeaderInjectionGuard;
use NexaPressMail\Services\Security\MailHtmlSanitizer;
use NexaPressMail\Services\Send\RecipientNormalizer;
use NexaPressMail\Security\CredentialCipher;

$tests = [];
$test = static function (string $name, callable $callback) use (&$tests): void {
    try { $callback(); $tests[] = [true, $name]; }
    catch (Throwable $error) { $tests[] = [false, $name . ': ' . $error->getMessage()]; }
};
$assert = static function (bool $condition, string $message = 'assertion failed'): void {
    if (!$condition) { throw new RuntimeException($message); }
};

$test('recipient normalization', static function () use ($assert): void {
    $items = (new RecipientNormalizer())->normalize('A@example.com, b@example.com; A@example.com');
    $assert($items === ['A@example.com','b@example.com']);
});
$test('header injection rejection', static function (): void {
    try { HeaderInjectionGuard::assertSafe("subject\nBcc: bad@example.com"); }
    catch (InvalidArgumentException) { return; }
    throw new RuntimeException('injection was accepted');
});
$test('HTML sanitizer', static function () use ($assert): void {
    $html = (new MailHtmlSanitizer())->sanitize('<p onclick="x()">ok<script>alert(1)</script><img src="https://track.test/a"></p>');
    $assert(str_contains($html, '<p>ok') && !str_contains($html, 'script') && !str_contains($html, 'onclick') && !str_contains($html, 'track.test'));
});
$test('attachment safety', static function () use ($assert): void {
    $assert(DangerousFileDetector::isBlocked('invoice.pdf.exe', 'application/x-dosexec'));
    $assert(AttachmentFilename::clean('../bad/name.txt') === '.._bad_name.txt');
});
$test('manifest contract', static function () use ($assert): void {
    $manifest = json_decode((string)file_get_contents(NEXAPRESS_MAIL_PATH.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    $assert($manifest['id']==='nexapress-mail' && $manifest['version']==='1.1.1' && $manifest['requires']['nexapress']==='2.3.0');
});
$test('credential encryption and authentication', static function () use ($assert): void {
    $cipher = new CredentialCipher(null, str_repeat('k', 32));
    $encrypted = $cipher->encrypt('mail-password');
    $assert(!str_contains($encrypted, 'mail-password') && $cipher->decrypt($encrypted) === 'mail-password');
    $tampered = substr($encrypted, 0, -1) . ($encrypted[-1] === 'A' ? 'B' : 'A');
    try { $cipher->decrypt($tampered); } catch (RuntimeException) { return; }
    throw new RuntimeException('tampering was accepted');
});

require __DIR__ . '/security.php';
require __DIR__ . '/features.php';

$failed = array_filter($tests, static fn(array $result): bool => !$result[0]);
foreach ($tests as [$passed, $name]) { echo ($passed ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL; }
echo count($tests) . ' tests, ' . count($failed) . ' failures' . PHP_EOL;
exit($failed ? 1 : 0);
