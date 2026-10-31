<?php
namespace NexaPressMail\Controllers;

use app\Core\Database;
use NexaPressMail\Models\OAuthRepository;
use NexaPressMail\Services\Security\PrivateLock;
use NexaPressMail\Models\AccountRepository;
use NexaPressMail\Models\LogRepository;
use NexaPressMail\Services\Imap\ImapConnectionTester;
use NexaPressMail\Services\Smtp\SmtpClient;
use NexaPressMail\Validation\AccountValidator;

final class AccountController extends BaseController
{
    public function index(): void { $this->view('accounts/index', ['accounts'=>(new AccountRepository())->all()], 'メールアカウント'); }
    public function edit(string $id): void
    {
        $account = (new AccountRepository())->find((int)$id);
        if (!$account) { http_response_code(404); echo 'アカウントが見つかりません。'; return; }
        $oauth = (new OAuthRepository())->find((int)$id);
        $this->view('accounts/edit', compact('account','oauth'), 'アカウント編集');
    }
    public function save(): void
    {
        $this->csrf();
        $id = (int)($_POST['id'] ?? 0);
        $originalId = $id;
        $pdo = Database::connect(); $lock = null;
        try {
            if ($id) { $lock = new PrivateLock('oauth-' . $id); }
            (new AccountValidator())->validate($_POST, $id === 0);
            $pdo->beginTransaction();
            $id = (new AccountRepository())->save($_POST);
            if (($_POST['auth_type'] ?? 'password') === 'oauth2') { (new OAuthRepository())->save($id, $_POST); }
            else { (new OAuthRepository())->disconnect($id); }
            $pdo->commit();
            $this->ok('メールアカウントを保存しました。', 'admin/extensions/nexapress-mail/accounts');
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $this->fail($error, $originalId ? 'admin/extensions/nexapress-mail/accounts/edit/'.$originalId : 'admin/extensions/nexapress-mail/accounts');
        } finally { if ($lock) { $lock->release(); } }
    }
    public function delete(string $id): void
    {
        $this->csrf();
        try {
            $repo = new AccountRepository();
            if ($repo->hasMessages((int)$id)) { throw new \RuntimeException('メールが残っているアカウントは削除できません。無効化してください。'); }
            $repo->delete((int)$id);
            $this->ok('アカウントを削除しました。', 'admin/extensions/nexapress-mail/accounts');
        } catch (\Throwable $error) { $this->fail($error, 'admin/extensions/nexapress-mail/accounts'); }
    }
    public function test(string $protocol): void
    {
        $this->csrf();
        try {
            $id = (int)($_POST['id'] ?? 0);
            $stored = $id ? (new AccountRepository())->find($id) : [];
            $account = array_merge($stored ?: [], $_POST);
            foreach (['smtp_password','imap_password'] as $key) {
                if (($account[$key] ?? '') === '' && isset($stored[$key])) { $account[$key] = $stored[$key]; }
            }
            (new AccountValidator())->validate($account, true);
            if ($protocol === 'smtp') { (new SmtpClient())->test($account); }
            elseif ($protocol === 'imap') { (new ImapConnectionTester())->test($account); }
            else { throw new \InvalidArgumentException('接続方式が不正です。'); }
            (new LogRepository())->write('info', 'connection-test', strtoupper($protocol).'接続テスト成功', $id ?: null);
            $this->ok(strtoupper($protocol).'接続に成功しました。', $id ? 'admin/extensions/nexapress-mail/accounts/edit/'.$id : 'admin/extensions/nexapress-mail/accounts');
        } catch (\Throwable $error) {
            (new LogRepository())->write('error', 'connection-test', $error->getMessage(), (int)($_POST['id'] ?? 0) ?: null);
            $this->fail($error, 'admin/extensions/nexapress-mail/accounts');
        }
    }
}
