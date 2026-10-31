<?php

namespace NexaPressMail\Services\Send;

use NexaPressMail\Models\AccountRepository;
use NexaPressMail\Models\LogRepository;
use NexaPressMail\Models\MessageRepository;
use NexaPressMail\Services\Attachment\AttachmentPersister;
use NexaPressMail\Services\Imap\ImapConnection;
use NexaPressMail\Services\Smtp\SmtpClient;
use Throwable;

final class MailSendService
{
    public function send(int $accountId, array $message, array $attachments): int
    {
        $limits = require NEXAPRESS_MAIL_PATH . '/config/limits.php';
        if (count($attachments) > $limits['message_attachments']) { throw new \InvalidArgumentException('添付ファイルは10件までです。'); }
        $account = (new AccountRepository())->find($accountId);
        if (!$account || empty($account['is_active'])) { throw new \InvalidArgumentException('送信アカウントが無効です。'); }
        $logs = new LogRepository();
        try {
            $result = (new SmtpClient())->send($account, $message, $attachments);
            $id = (new MessageRepository())->save($this->record($account, $message, $result['message_id'], 'sent', (bool)$attachments));
            (new AttachmentPersister())->persistFiles($id, $attachments);
            $this->appendSent($account, $result['mime']);
            $logs->write('info', 'send', 'メールを送信しました。', $accountId,
                ['message_id'=>$result['message_id'],'recipients'=>count($message['to'])]);
            return $id;
        } catch (Throwable $exception) {
            $logs->write('error', 'send', $exception->getMessage(), $accountId);
            throw $exception;
        }
    }

    private function record(array $account, array $message, ?string $messageId, string $folder, bool $hasAttachments): array
    {
        $recipients = ['to'=>$message['to'],'cc'=>$message['cc'],'bcc'=>$message['bcc']];
        return [
            'account_id'=>(int)$account['id'],'folder'=>$folder,'message_id'=>$messageId,
            'in_reply_to'=>$message['in_reply_to'] ?: null,'sender_name'=>$account['name'],
            'sender_email'=>$account['email'],'recipients_json'=>json_encode($recipients, JSON_UNESCAPED_UNICODE),
            'subject'=>$message['subject'],'body_text'=>$message['body_text'],'body_html'=>$message['body_html'],
            'is_read'=>1,'is_starred'=>0,'has_attachments'=>$hasAttachments ? 1 : 0,
            'received_at'=>null,'sent_at'=>$folder === 'sent' ? date('Y-m-d H:i:s') : null,
        ];
    }

    private function appendSent(array $account, string $mime): void
    {
        try {
            $stream = ImapConnection::open($account, $account['folder_sent']);
            try { ImapConnection::append($stream, $account, $account['folder_sent'], $mime); }
            finally { ImapConnection::close($stream); }
        } catch (Throwable $exception) {
            (new LogRepository())->write('warning', 'sent-copy', '送信済みIMAP保存に失敗: ' . $exception->getMessage(), (int)$account['id']);
        }
    }
}
