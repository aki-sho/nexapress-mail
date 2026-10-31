<?php
namespace NexaPressMail\Controllers;

use NexaPressMail\Services\Receive\MailSyncService;
use NexaPressMail\Models\SyncJobRepository;

final class SyncController extends BaseController
{
    public function sync(): void
    {
        $this->csrf();
        try {
            $result = (new MailSyncService())->sync((int)($_POST['account_id'] ?? 0) ?: null, $_POST);
            $message = "{$result['received']}件受信、{$result['skipped']}件確認済み";
            if ($result['rejected']) { $message .= "、{$result['rejected']}件を受信制限により除外（詳細はログ）"; }
            $message .= "、{$result['updated']}件の状態を確認";
            if ($result['busy']) { $message .= "、{$result['busy']}アカウントは実行中"; }
            if ($result['errors']) { throw new \RuntimeException($message . '。' . implode(' / ', $result['errors'])); }
            $this->ok('同期完了: '.$message, 'admin/extensions/nexapress-mail/dashboard');
        } catch (\Throwable $error) { $this->fail($error, 'admin/extensions/nexapress-mail/dashboard'); }
    }
    public function status(): void { $this->json(['jobs'=>(new SyncJobRepository())->all()]); }
}
