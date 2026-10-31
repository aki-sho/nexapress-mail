<?php
namespace NexaPressMail\Validation;

use InvalidArgumentException;
use NexaPressMail\Services\Security\HeaderInjectionGuard;
use NexaPressMail\Services\Security\TransportSecurity;

final class AccountValidator
{
    public function validate(array $data, bool $passwordsRequired): void
    {
        if (!in_array($data['auth_type'] ?? 'password', ['password','oauth2'], true)) { throw new InvalidArgumentException('認証方式が不正です。'); }
        $interval = (int)($data['auto_sync_interval'] ?? 300);
        if ($interval < 60 || $interval > 86400) { throw new InvalidArgumentException('自動受信間隔は60〜86400秒にしてください。'); }
        foreach (['name','email','smtp_host','smtp_username','imap_host','imap_username'] as $key) {
            if (trim((string)($data[$key] ?? '')) === '') { throw new InvalidArgumentException("{$key} は必須です。"); }
            HeaderInjectionGuard::assertSafe((string)$data[$key]);
        }
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) { throw new InvalidArgumentException('送信元メールアドレスが不正です。'); }
        foreach (['smtp_port','imap_port'] as $key) {
            $port = (int)($data[$key] ?? 0);
            if ($port < 1 || $port > 65535) { throw new InvalidArgumentException('ポート番号が不正です。'); }
        }
        TransportSecurity::validate($data, 'smtp');
        TransportSecurity::validate($data, 'imap');
        foreach (['folder_sent','folder_drafts','folder_trash'] as $key) {
            $folder = trim((string)($data[$key] ?? ''));
            if ($folder === '' || preg_match('/[\r\n\0{}]/', $folder)) { throw new InvalidArgumentException('IMAPフォルダ名が不正です。'); }
        }
        if (($data['auth_type'] ?? 'password') === 'password' && $passwordsRequired && (empty($data['smtp_password']) || empty($data['imap_password']))) {
            throw new InvalidArgumentException('SMTP・IMAPパスワードは必須です。');
        }
    }
}
