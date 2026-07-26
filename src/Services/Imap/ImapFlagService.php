<?php

namespace NexaPressMail\Services\Imap;

final class ImapFlagService
{
    public function apply(array $account, array $message, string $action): void
    {
        if (empty($message['remote_uid'])) { return; }
        $source = $this->remoteFolder($account, $message['folder']);
        $stream = ImapConnection::open($account, $source);
        try {
            $uid = (string)$message['remote_uid'];
            match ($action) {
                'read' => imap_setflag_full($stream, $uid, '\\Seen', ST_UID),
                'unread' => imap_clearflag_full($stream, $uid, '\\Seen', ST_UID),
                'star' => imap_setflag_full($stream, $uid, '\\Flagged', ST_UID),
                'unstar' => imap_clearflag_full($stream, $uid, '\\Flagged', ST_UID),
                'trash' => $this->move($stream, $uid, $account['folder_trash']),
                'restore' => $this->move($stream, $uid, 'INBOX'),
                'delete' => imap_delete($stream, $uid, FT_UID),
                default => true,
            };
        } finally { imap_close($stream, CL_EXPUNGE); }
    }

    private function move($stream, string $uid, string $folder): bool
    {
        if (!imap_mail_move($stream, $uid, $folder, CP_UID)) {
            throw new \RuntimeException('IMAP上でメールを移動できません: ' . (imap_last_error() ?: '不明なエラー'));
        }
        return imap_expunge($stream);
    }

    private function remoteFolder(array $account, string $folder): string
    {
        return match ($folder) {
            'sent'=>$account['folder_sent'],'drafts'=>$account['folder_drafts'],
            'trash'=>$account['folder_trash'],default=>'INBOX',
        };
    }
}
