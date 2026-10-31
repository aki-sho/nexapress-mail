<?php
namespace NexaPressMail\Controllers;

use app\Core\Auth;
use NexaPressMail\Models\AccountRepository;
use NexaPressMail\Models\MessageRepository;
use NexaPressMail\Services\Attachment\UploadedAttachment;
use NexaPressMail\Services\Attachment\AttachmentStorage;
use NexaPressMail\Models\AttachmentRepository;
use NexaPressMail\Services\Send\MailSendService;
use NexaPressMail\Services\Send\OutgoingMessageFactory;
use NexaPressMail\Services\Send\DraftService;
use NexaPressMail\Services\Send\DraftConflictException;

final class ComposeController extends BaseController
{
    public function form(): void
    {
        $recovery = $_SESSION['npm_compose_recovery'] ?? null; unset($_SESSION['npm_compose_recovery']);
        $sourceId = (int)($recovery['draft_id'] ?? $_GET['message'] ?? 0);
        $source = $sourceId ? (new MessageRepository())->find($sourceId) : null;
        $mode = in_array($_GET['mode'] ?? '', ['reply','reply-all','forward'], true) ? $_GET['mode'] : 'new';
        $draft = $mode === 'new' && $source && $source['folder'] === 'drafts' ? $source : null;
        $draftAttachments = $draft ? (new AttachmentRepository())->forMessage((int)$draft['id']) : [];
        $draftMeta = (new DraftService())->metadata((int)($draft['id'] ?? 0));
        $values = $this->prefill($source, $mode);
        $values['account_id'] = (int)($source['account_id'] ?? 0);
        if (is_array($recovery)) {
            foreach (['to','cc','bcc','subject','body','format','in_reply_to','account_id'] as $key) {
                if (isset($recovery[$key]) && is_scalar($recovery[$key])) { $values[$key] = $recovery[$key]; }
            }
        }
        $this->view('compose/create', ['accounts'=>(new AccountRepository())->all(true),
            'values'=>$values,'mode'=>$mode,'draft'=>$draft,'draftMeta'=>$draftMeta,
            'userId'=>(int)(Auth::user()['id'] ?? 0),'sourceId'=>$sourceId,
            'draftAttachments'=>$draftAttachments], 'メール作成');
    }
    public function send(): void
    {
        $this->csrf();
        $saved = null;
        try {
            $saved = (new DraftService())->save((int)(Auth::user()['id'] ?? 0), $_POST,
                (new UploadedAttachment())->collect($_FILES['attachments'] ?? []));
            $message = (new OutgoingMessageFactory())->fromRequest($_POST);
            $draftId = $saved['id'];
            $files = (new DraftService())->files($draftId);
            $id = (new MailSendService())->send((int)($_POST['account_id'] ?? 0), $message, $files);
            if ($draftId > 0) { $this->deleteDraft($draftId); }
            $_SESSION['npm_clear_compose'] = $saved['client_key'];
            $this->ok('メールを送信しました。', 'admin/extensions/nexapress-mail/message/' . $id);
        } catch (\Throwable $error) { $this->recover($error, $saved); }
    }
    public function draft(): void
    {
        $this->csrf();
        try {
            $files = (new UploadedAttachment())->collect($_FILES['attachments'] ?? []);
            $saved = (new DraftService())->save((int)(Auth::user()['id'] ?? 0), $_POST, $files);
            $_SESSION['npm_clear_compose'] = $saved['client_key'];
            $this->ok('下書きを保存しました。', 'admin/extensions/nexapress-mail/mailbox/drafts');
        } catch (\Throwable $error) { $this->recover($error, null); }
    }

    public function autosave(): void
    {
        $this->csrf();
        try {
            $saved = (new DraftService())->save((int)(Auth::user()['id'] ?? 0), $_POST,
                (new UploadedAttachment())->collect($_FILES['attachments'] ?? []));
            $this->json($saved);
        } catch (\Throwable $error) { $this->json(['error'=>$error->getMessage()], $error instanceof DraftConflictException ? 409 : 422); }
    }

    public function recovery(): void
    {
        $this->json(['draft'=>(new DraftService())->lookupClient((int)(Auth::user()['id'] ?? 0), (string)($_GET['client_key'] ?? ''))]);
    }

    private function recover(\Throwable $error, ?array $saved): never
    {
        if (!$saved && !empty($_POST['client_key'])) {
            try { $saved = (new DraftService())->lookupClient((int)(Auth::user()['id'] ?? 0), (string)$_POST['client_key']); }
            catch (\Throwable $ignored) { /* Preserve the original failure and the user's input. */ }
        }
        $recovery = array_intersect_key($_POST, array_flip(['to','cc','bcc','subject','body','format','in_reply_to','account_id','draft_id']));
        if ($saved) { $recovery['draft_id'] = $saved['id']; }
        $_SESSION['npm_compose_recovery'] = $recovery;
        $this->fail($error, 'admin/extensions/nexapress-mail/compose' . (!empty($recovery['draft_id']) ? '?message=' . (int)$recovery['draft_id'] : ''));
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

    private function deleteDraft(int $id): void
    {
        $storage = new AttachmentStorage();
        foreach ((new AttachmentRepository())->deleteForMessage($id) as $file) { $storage->delete($file['storage_path']); }
        (new MessageRepository())->delete($id);
    }
}
