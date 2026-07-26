<?php

namespace NexaPressMail\Services\Receive;

use NexaPressMail\Models\AccountRepository;
use NexaPressMail\Models\LogRepository;
use NexaPressMail\Models\MessageRepository;
use NexaPressMail\Services\Attachment\AttachmentPersister;
use NexaPressMail\Services\Imap\ImapConnection;
use NexaPressMail\Services\Imap\ImapPartReader;
use NexaPressMail\Services\Parser\AddressParser;
use NexaPressMail\Services\Parser\SubjectDecoder;
use NexaPressMail\Services\Security\MailHtmlSanitizer;
use Throwable;

final class MailSyncService
{
    public function sync(?int $onlyAccount = null): array
    {
        $accounts = new AccountRepository();
        $logs = new LogRepository();
        $result = ['received'=>0,'skipped'=>0,'errors'=>[]];
        foreach ($accounts->all(true) as $account) {
            if ($onlyAccount && (int)$account['id'] !== $onlyAccount) { continue; }
            try {
                foreach ($this->folders($account) as $local => $remote) {
                    $part = $this->syncFolder($account, $local, $remote);
                    $result['received'] += $part['received']; $result['skipped'] += $part['skipped'];
                }
                $accounts->touchSync((int)$account['id']);
                $logs->write('info', 'sync', "同期完了: {$result['received']}件取得", (int)$account['id']);
            } catch (Throwable $exception) {
                $result['errors'][] = $account['name'] . ': ' . $exception->getMessage();
                $logs->write('error', 'sync', $exception->getMessage(), (int)$account['id']);
            }
        }
        return $result;
    }

    private function syncFolder(array $account, string $local, string $remote): array
    {
        $stream = ImapConnection::open($account, $remote);
        try {
            $uids = imap_search($stream, 'ALL', SE_UID) ?: [];
            rsort($uids, SORT_NUMERIC);
            $limits = require NEXAPRESS_MAIL_PATH . '/config/limits.php';
            $uids = array_slice($uids, 0, $limits['sync_limit']);
            $received = $skipped = 0;
            foreach (array_reverse($uids) as $uid) {
                if ((new MessageRepository())->existsRemote((int)$account['id'], $local, (int)$uid)) { $skipped++; continue; }
                $this->import($stream, $account, $local, (int)$uid); $received++;
            }
            return compact('received','skipped');
        } finally { imap_close($stream); }
    }

    private function import($stream, array $account, string $folder, int $uid): void
    {
        $overview = imap_fetch_overview($stream, (string)$uid, FT_UID)[0] ?? null;
        if (!$overview) { throw new \RuntimeException("UID {$uid} のヘッダーを取得できません。"); }
        $parts = (new ImapPartReader())->read($stream, $uid);
        $from = AddressParser::parse((string)($overview->from ?? ''))[0] ?? ['name'=>'','email'=>''];
        $recipients = [
            'to'=>AddressParser::parse((string)($overview->to ?? '')),
            'cc'=>AddressParser::parse((string)($overview->cc ?? '')),
            'bcc'=>[],
        ];
        $timestamp = isset($overview->udate) ? date('Y-m-d H:i:s', (int)$overview->udate) : date('Y-m-d H:i:s');
        $id = (new MessageRepository())->save([
            'account_id'=>(int)$account['id'],'folder'=>$folder,'remote_uid'=>$uid,
            'message_id'=>trim((string)($overview->message_id ?? ''), '<>') ?: null,
            'in_reply_to'=>trim((string)($overview->in_reply_to ?? ''), '<>') ?: null,
            'sender_name'=>$from['name'],'sender_email'=>$from['email'],
            'recipients_json'=>json_encode($recipients, JSON_UNESCAPED_UNICODE),
            'subject'=>SubjectDecoder::decode((string)($overview->subject ?? '')),
            'body_text'=>$parts['text'],'body_html'=>(new MailHtmlSanitizer())->sanitize($parts['html']),
            'is_read'=>empty($overview->seen) ? 0 : 1,'is_starred'=>empty($overview->flagged) ? 0 : 1,
            'has_attachments'=>$parts['attachments'] ? 1 : 0,
            'received_at'=>$folder === 'inbox' ? $timestamp : null,
            'sent_at'=>$folder !== 'inbox' ? $timestamp : null,
        ]);
        foreach ($parts['attachments'] as $attachment) {
            try { (new AttachmentPersister())->persistBytes($id, $attachment); }
            catch (\InvalidArgumentException $exception) {
                (new LogRepository())->write('warning', 'attachment', $exception->getMessage(), (int)$account['id'], ['filename'=>$attachment['name']]);
            }
        }
    }

    private function folders(array $account): array
    {
        return ['inbox'=>'INBOX','sent'=>$account['folder_sent'],'drafts'=>$account['folder_drafts'],'trash'=>$account['folder_trash']];
    }
}
