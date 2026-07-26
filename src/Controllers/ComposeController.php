<?php
namespace NexaPressMail\Controllers;

use NexaPressMail\Models\AccountRepository;
use NexaPressMail\Models\MessageRepository;
use NexaPressMail\Services\Attachment\UploadedAttachment;
use NexaPressMail\Services\Attachment\AttachmentStorage;
use NexaPressMail\Models\AttachmentRepository;
use NexaPressMail\Services\Send\MailSendService;
use NexaPressMail\Services\Send\OutgoingMessageFactory;

final class ComposeController extends BaseController
{
    public function form(): void
    {
        $source = !empty($_GET['message']) ? (new MessageRepository())->find((int)$_GET['message']) : null;
        $mode = in_array($_GET['mode'] ?? '', ['reply','reply-all','forward'], true) ? $_GET['mode'] : 'new';
        $draft = $mode === 'new' && $source && $source['folder'] === 'drafts' ? $source : null;
        $draftAttachments = $draft ? (new AttachmentRepository())->forMessage((int)$draft['id']) : [];
        $this->view('compose/create', ['accounts'=>(new AccountRepository())->all(true),
            'values'=>$this->prefill($source, $mode),'mode'=>$mode,'draft'=>$draft,
            'draftAttachments'=>$draftAttachments], 'メール作成');
    }
    public function send(): void
    {
        $this->csrf();
        try {
            $message = (new OutgoingMessageFactory())->fromRequest($_POST);
            $draftId = (int)($_POST['draft_id'] ?? 0);
            $files = [...$this->draftFiles($draftId), ...(new UploadedAttachment())->collect($_FILES['attachments'] ?? [])];
            $id = (new MailSendService())->send((int)($_POST['account_id'] ?? 0), $message, $files);
            if ($draftId > 0) { $this->deleteDraft($draftId); }
            $this->ok('メールを送信しました。', 'admin/extensions/nexapress-mail/message/' . $id);
        } catch (\Throwable $error) { $this->fail($error, 'admin/extensions/nexapress-mail/compose'); }
    }
    public function draft(): void
    {
        $this->csrf();
        try {
            $message = (new OutgoingMessageFactory())->fromRequest($_POST, false);
            $files = (new UploadedAttachment())->collect($_FILES['attachments'] ?? []);
            (new MailSendService())->saveDraft((int)($_POST['account_id'] ?? 0), $message, $files, (int)($_POST['draft_id'] ?? 0));
            $this->ok('下書きを保存しました。', 'admin/extensions/nexapress-mail/mailbox/drafts');
        } catch (\Throwable $error) { $this->fail($error, 'admin/extensions/nexapress-mail/compose'); }
    }
    private function prefill(?array $source, string $mode): array
    {
        if (!$source) { return ['to'=>'','cc'=>'','bcc'=>'','subject'=>'','body'=>'','format'=>'text','in_reply_to'=>'']; }
        $recipients = json_decode($source['recipients_json'], true) ?: [];
        if ($source['folder'] === 'drafts') {
            return ['to'=>$this->emails($recipients['to'] ?? []),'cc'=>$this->emails($recipients['cc'] ?? []),
                'bcc'=>$this->emails($recipients['bcc'] ?? []),'subject'=>$source['subject'],
                'body'=>$source['body_html'] ?: $source['body_text'],'format'=>$source['body_html'] ? 'html' : 'text','in_reply_to'=>$source['in_reply_to']];
        }
        $prefix = $mode === 'forward' ? 'Fwd: ' : 'Re: ';
        $quoted = "\n\n--- 元のメッセージ ---\nFrom: {$source['sender_email']}\n" . $source['body_text'];
        return ['to'=>$mode === 'forward' ? '' : $source['sender_email'],
            'cc'=>$mode === 'reply-all' ? $this->emails($recipients['to'] ?? []) : '', 'bcc'=>'',
            'subject'=>preg_match('/^(Re|Fwd):/i',$source['subject']) ? $source['subject'] : $prefix.$source['subject'],
            'body'=>$quoted,'format'=>'text','in_reply_to'=>$source['message_id']];
    }
    private function emails(array $items): string
    { return implode(', ', array_map(static fn($item) => is_array($item) ? ($item['email'] ?? '') : $item, $items)); }

    private function draftFiles(int $id): array
    {
        if ($id < 1) { return []; }
        $storage = new AttachmentStorage();
        return array_map(static fn($file) => ['name'=>$file['filename'],'mime'=>$file['mime_type'],
            'size'=>(int)$file['size_bytes'],'path'=>$storage->path($file['storage_path'])],
            (new AttachmentRepository())->forMessage($id));
    }

    private function deleteDraft(int $id): void
    {
        $storage = new AttachmentStorage();
        foreach ((new AttachmentRepository())->deleteForMessage($id) as $file) { $storage->delete($file['storage_path']); }
        (new MessageRepository())->delete($id);
    }
}
