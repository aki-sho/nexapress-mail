<?php

namespace NexaPressMail\Controllers;

use app\Core\Auth;
use NexaPressMail\Models\AccountRepository;
use NexaPressMail\Models\OAuthRepository;
use NexaPressMail\Services\OAuth\OAuthService;
use NexaPressMail\Services\Security\PrivateLock;

final class OAuthController extends BaseController
{
    public function begin(string $id): void
    {
        $this->csrf(); header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer');
        try {
            $account = (new AccountRepository())->find((int)$id);
            if (!$account || $account['auth_type'] !== 'oauth2') { throw new \RuntimeException('OAuthアカウントが見つかりません。'); }
            $location = (new OAuthService())->begin((int)$id, (int)(Auth::user()['id'] ?? 0));
            header('Location: ' . $location); exit;
        } catch (\Throwable $error) { $this->fail($error, 'admin/extensions/nexapress-mail/accounts/edit/' . (int)$id); }
    }

    public function callback(): void
    {
        header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer');
        try {
            if (isset($_GET['error'])) { unset($_SESSION['npm_oauth']); throw new \RuntimeException('OAuth接続が許可されませんでした。'); }
            $id = (new OAuthService())->complete((string)($_GET['state'] ?? ''), (string)($_GET['code'] ?? ''), (int)(Auth::user()['id'] ?? 0));
            $this->ok('OAuth接続が完了しました。SMTP・IMAP接続テストを実行してください。', 'admin/extensions/nexapress-mail/accounts/edit/' . $id);
        } catch (\Throwable $error) { $this->fail($error, 'admin/extensions/nexapress-mail/accounts'); }
    }

    public function disconnect(string $id): void
    {
        $this->csrf();
        try {
            $lock = new PrivateLock('oauth-' . (int)$id);
            try { (new OAuthRepository())->disconnect((int)$id); } finally { $lock->release(); }
            $this->ok('このアプリのOAuthトークンを削除しました。', 'admin/extensions/nexapress-mail/accounts/edit/' . (int)$id);
        } catch (\Throwable $error) { $this->fail($error, 'admin/extensions/nexapress-mail/accounts'); }
    }
}
