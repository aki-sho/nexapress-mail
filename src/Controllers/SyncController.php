<?php
namespace NexaPressMail\Controllers;

use NexaPressMail\Services\Receive\MailSyncService;

final class SyncController extends BaseController
{
    public function sync(): void
    {
        $this->csrf();
        try {
            $result = (new MailSyncService())->sync((int)($_POST['account_id'] ?? 0) ?: null);
            $message = "{$result['received']}件受信、{$result['skipped']}件確認済み";
            if ($result['errors']) { throw new \RuntimeException($message . '。' . implode(' / ', $result['errors'])); }
            $this->ok('同期完了: '.$message, 'admin/extensions/nexapress-mail/dashboard');
        } catch (\Throwable $error) { $this->fail($error, 'admin/extensions/nexapress-mail/dashboard'); }
    }
}
