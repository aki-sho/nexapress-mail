<?php
namespace NexaPressMail\Controllers;

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
        $this->view('accounts/edit', compact('account'), 'アカウント編集');
    }
    public function save(): void
    {
        $this->csrf();
        $id = (int)($_POST['id'] ?? 0);
        try {
            (new AccountValidator())->validate($_POST, $id === 0);
            (new AccountRepository())->save($_POST);
            $this->ok('メールアカウントを保存しました。', 'admin/extensions/nexapress-mail/accounts');
        } catch (\Throwable $error) { $this->fail($error, $id ? 'admin/extensions/nexapress-mail/accounts/edit/'.$id : 'admin/extensions/nexapress-mail/accounts'); }
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
