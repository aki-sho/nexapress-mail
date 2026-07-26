<?php
namespace NexaPressMail\Controllers;

use NexaPressMail\Models\AccountRepository;
use NexaPressMail\Models\AttachmentRepository;
use NexaPressMail\Models\MessageRepository;
use NexaPressMail\Services\Attachment\AttachmentStorage;
use NexaPressMail\Services\Imap\ImapFlagService;

final class ActionController extends BaseController
{
    public function message(string $id): void
    {
        $this->csrf();
        try { $this->apply((int)$id, (string)($_POST['action'] ?? '')); $this->ok('メールを更新しました。', 'admin/extensions/nexapress-mail/message/'.$id); }
        catch (\Throwable $error) { $this->fail($error, 'admin/extensions/nexapress-mail/message/'.$id); }
    }
    public function bulk(): void
    {
        $this->csrf();
        $ids = is_array($_POST['ids'] ?? null) ? $_POST['ids'] : [];
        try {
            if (!$ids) { throw new \InvalidArgumentException('メールを選択してください。'); }
            foreach ($ids as $id) { $this->apply((int)$id, (string)($_POST['action'] ?? '')); }
            $this->ok(count($ids).'件のメールを更新しました。', 'admin/extensions/nexapress-mail/mailbox/'.($_POST['folder'] ?? 'inbox'));
        } catch (\Throwable $error) { $this->fail($error, 'admin/extensions/nexapress-mail/mailbox/'.($_POST['folder'] ?? 'inbox')); }
    }
    private function apply(int $id, string $action): void
    {
        if (!in_array($action, ['read','unread','star','unstar','trash','restore','delete'], true)) {
            throw new \InvalidArgumentException('操作が不正です。');
        }
        $repo = new MessageRepository();
        $message = $repo->find($id);
        if (!$message) { throw new \RuntimeException('メールが見つかりません。'); }
        if ($action === 'delete') {
            if ($message['folder'] !== 'trash') { throw new \RuntimeException('完全削除はゴミ箱内だけで実行できます。'); }
            $account = (new AccountRepository())->find((int)$message['account_id']);
            if ($account) { (new ImapFlagService())->apply($account, $message, 'delete'); }
            foreach ((new AttachmentRepository())->deleteForMessage($id) as $file) { (new AttachmentStorage())->delete($file['storage_path']); }
            $repo->delete($id); return;
        }
        $account = (new AccountRepository())->find((int)$message['account_id']);
        if ($account) { (new ImapFlagService())->apply($account, $message, $action); }
        $changes = match ($action) {
            'read'=>['is_read'=>1],'unread'=>['is_read'=>0],'star'=>['is_starred'=>1],
            'unstar'=>['is_starred'=>0],'trash'=>['folder'=>'trash'],'restore'=>['folder'=>'inbox'],default=>[],
        };
        $repo->updateFlags([$id], $changes);
    }
}
