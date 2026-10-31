<?php

namespace NexaPressMail\Services\Send;

use app\Core\Database;
use NexaPressMail\Models\AccountRepository;
use NexaPressMail\Models\AttachmentRepository;
use NexaPressMail\Models\MessageRepository;
use NexaPressMail\Services\Attachment\AttachmentPersister;
use NexaPressMail\Services\Attachment\AttachmentStorage;
use NexaPressMail\Services\Security\PrivateLock;

final class DraftService
{
    public function metadata(int $id): array
    {
        $table = Database::table('nexapress_mail_draft_state');
        $stmt = Database::connect()->prepare("SELECT * FROM {$table} WHERE message_id=?"); $stmt->execute([$id]);
        return $stmt->fetch() ?: ['revision'=>0,'client_key'=>bin2hex(random_bytes(16))];
    }

    public function lookupClient(int $userId, string $client): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $client)) { return null; }
        $table = Database::table('nexapress_mail_draft_state');
        $stmt = Database::connect()->prepare("SELECT * FROM {$table} WHERE user_id=? AND client_key=?");
        $stmt->execute([$userId,$client]); $state = $stmt->fetch();
        if (!$state) { return null; }
        $message = (new MessageRepository())->find((int)$state['message_id']);
        if (!$message || $message['folder'] !== 'drafts' || !empty($message['remote_missing'])) { return null; }
        return $this->response((int)$state['message_id'], $state);
    }

    public function save(int $userId, array $input, array $files = []): array
    {
        $client = (string)($input['client_key'] ?? ''); $request = (string)($input['request_id'] ?? '');
        if ($userId < 1 || !preg_match('/^[a-f0-9]{32}$/', $client) || !preg_match('/^[a-f0-9]{32}$/', $request)) {
            throw new \InvalidArgumentException('下書きの保存情報が不正です。画面を開き直してください。');
        }
        $limits = require NEXAPRESS_MAIL_PATH . '/config/limits.php';
        if (strlen((string)($input['body'] ?? '')) > $limits['receive_body_bytes'] || mb_strlen((string)($input['subject'] ?? '')) > 998) {
            throw new \InvalidArgumentException('下書きの本文または件名が保存上限を超えています。');
        }
        foreach (['to','cc','bcc'] as $key) {
            if (strlen((string)($input[$key] ?? '')) > 16384) { throw new \InvalidArgumentException('宛先が長すぎます。'); }
        }
        $account = (new AccountRepository())->find((int)($input['account_id'] ?? 0));
        if (!$account) { throw new \InvalidArgumentException('送信アカウントを選択してください。'); }
        $message = (new OutgoingMessageFactory())->fromRequest($input, false);
        $lock = new PrivateLock('draft-' . $userId . '-' . $client);
        $pdo = Database::connect(); $states = Database::table('nexapress_mail_draft_state');
        $createdFiles = []; $removeFiles = [];
        try {
            $pdo->beginTransaction();
            $id = (int)($input['draft_id'] ?? 0);
            if (!$id) {
                $lookup = $pdo->prepare("SELECT message_id FROM {$states} WHERE user_id=? AND client_key=?");
                $lookup->execute([$userId,$client]); $id = (int)$lookup->fetchColumn();
            }
            $repo = new MessageRepository(); $attachments = new AttachmentRepository();
            $current = $id ? $repo->find($id) : null;
            if ($id && (!$current || $current['folder'] !== 'drafts' || !empty($current['remote_missing']))) {
                throw new DraftConflictException('対象は編集可能な下書きではありません。下書き一覧から開き直してください。');
            }
            $copyFiles = [];
            if ($current && !empty($current['remote_uid'])) {
                $copyFiles = $this->files($id); $current = null; $id = 0;
            }
            $state = $id ? $this->metadata($id) : ['revision'=>0,'client_key'=>$client];
            if (($state['request_id'] ?? '') === $request) {
                $pdo->commit(); return $this->response($id, $state);
            }
            if ((int)$state['revision'] !== (int)($input['revision'] ?? 0)) {
                throw new DraftConflictException('別の画面で下書きが更新されました。この画面の入力は残っています。下書きを開き直して確認してください。');
            }
            $existingFiles = $id ? $attachments->forMessage($id) : [];
            $removeIds = array_map('intval', is_array($input['remove_attachments'] ?? null) ? $input['remove_attachments'] : []);
            $keep = array_filter($existingFiles, static fn($file) => !in_array((int)$file['id'], $removeIds, true));
            if (count($keep) + count($files) + count($copyFiles) > $limits['message_attachments']) {
                throw new \InvalidArgumentException('添付ファイルは合計10件までです。');
            }
            $record = ['account_id'=>(int)$account['id'],'folder'=>'drafts','remote_missing'=>0,
                'sender_name'=>$account['name'],'sender_email'=>$account['email'],
                'recipients_json'=>json_encode(['to'=>$message['to'],'cc'=>$message['cc'],'bcc'=>$message['bcc']], JSON_UNESCAPED_UNICODE),
                'subject'=>$message['subject'],'body_text'=>$message['body_text'],'body_html'=>$message['body_html'],
                'in_reply_to'=>$message['in_reply_to'] ?: null,'is_read'=>1,
                'has_attachments'=>(int)(count($keep) + count($files) + count($copyFiles) > 0)];
            if ($id) { $record['id'] = $id; }
            $id = $repo->save($record);
            if (!isset($state['message_id'])) {
                $pdo->prepare("INSERT INTO {$states} (message_id,user_id,client_key,revision) VALUES (?,?,?,0)")->execute([$id,$userId,$client]);
            }
            $update = $pdo->prepare("UPDATE {$states} SET revision=revision+1,request_id=? WHERE message_id=? AND revision=?");
            $update->execute([$request,$id,(int)$state['revision']]);
            if ($update->rowCount() !== 1) { throw new DraftConflictException('下書きの同時更新を検出しました。入力を確認してください。'); }
            foreach ($existingFiles as $file) {
                if (in_array((int)$file['id'], $removeIds, true)) { $attachments->delete((int)$file['id'], $id); $removeFiles[] = $file; }
            }
            (new AttachmentPersister())->persistFiles($id, [...$copyFiles,...$files]);
            $oldIds = array_column($existingFiles, 'id');
            $createdFiles = array_filter($attachments->forMessage($id), static fn($file) => !in_array($file['id'], $oldIds));
            $pdo->commit();
            foreach ($removeFiles as $file) { (new AttachmentStorage())->delete($file['storage_path']); }
            return $this->response($id, $this->metadata($id));
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) {
                if (isset($id, $existingFiles) && $id) {
                    $oldIds = array_column($existingFiles, 'id');
                    $createdFiles = array_filter((new AttachmentRepository())->forMessage($id), static fn($file) => !in_array($file['id'], $oldIds));
                }
                $pdo->rollBack();
            }
            foreach ($createdFiles as $file) { (new AttachmentStorage())->delete($file['storage_path']); }
            throw $error;
        } finally { $lock->release(); }
    }

    public function files(int $id): array
    {
        $storage = new AttachmentStorage();
        return array_map(static fn($file) => ['name'=>$file['filename'],'mime'=>$file['mime_type'],
            'size'=>(int)$file['size_bytes'],'path'=>$storage->path($file['storage_path'])], (new AttachmentRepository())->forMessage($id));
    }

    private function response(int $id, array $state): array
    {
        $files = array_map(static fn($file) => ['id'=>(int)$file['id'],'filename'=>$file['filename'],'size_bytes'=>(int)$file['size_bytes']],
            (new AttachmentRepository())->forMessage($id));
        return ['id'=>$id,'revision'=>(int)$state['revision'],'client_key'=>$state['client_key'],'attachments'=>$files];
    }
}
