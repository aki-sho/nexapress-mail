<?php
namespace NexaPressMail\Validation;

use InvalidArgumentException;
use NexaPressMail\Services\Security\HeaderInjectionGuard;

final class AccountValidator
{
    public function validate(array $data, bool $passwordsRequired): void
    {
        foreach (['name','email','smtp_host','smtp_username','imap_host','imap_username'] as $key) {
            if (trim((string)($data[$key] ?? '')) === '') { throw new InvalidArgumentException("{$key} は必須です。"); }
            HeaderInjectionGuard::assertSafe((string)$data[$key]);
        }
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) { throw new InvalidArgumentException('送信元メールアドレスが不正です。'); }
        foreach (['smtp_port','imap_port'] as $key) {
            $port = (int)($data[$key] ?? 0);
            if ($port < 1 || $port > 65535) { throw new InvalidArgumentException('ポート番号が不正です。'); }
        }
        foreach (['smtp_security','imap_security'] as $key) {
            if (!in_array($data[$key] ?? '', ['ssl','tls','none'], true)) { throw new InvalidArgumentException('暗号化方式が不正です。'); }
        }
        foreach (['folder_sent','folder_drafts','folder_trash'] as $key) {
            $folder = trim((string)($data[$key] ?? ''));
            if ($folder === '' || preg_match('/[\r\n\0{}]/', $folder)) { throw new InvalidArgumentException('IMAPフォルダ名が不正です。'); }
        }
        if ($passwordsRequired && (empty($data['smtp_password']) || empty($data['imap_password']))) {
            throw new InvalidArgumentException('SMTP・IMAPパスワードは必須です。');
        }
    }
}
