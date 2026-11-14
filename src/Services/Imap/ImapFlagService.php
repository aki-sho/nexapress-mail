<?php

namespace NexaPressMail\Services\Imap;

use NexaPressMail\Models\SyncStateRepository;
use NexaPressMail\Services\Security\PrivateLock;

final class ImapFlagService
{
    public function apply(array $account, array $message, string $action): array
    {
        if (empty($message['remote_uid']) || !empty($message['remote_missing'])) { return []; }
        $source = $this->remoteFolder($account, $message['folder']);
        $lock = new PrivateLock('sync-' . (int)$account['id'], false);
        $stream = null;
        try {
            $stream = ImapConnection::open($account, $source, $action === 'delete');
            $state = (new SyncStateRepository())->get((int)$account['id'], $message['folder']);
            $status = ImapConnection::status($stream, $account, $source);
            $serverKey = hash('sha256', implode('|', [$account['imap_host'],$account['imap_port'],$account['imap_username'],$source]));
            if ((int)$state['uidvalidity'] !== (int)$status->uidvalidity || $state['server_key'] !== $serverKey) {
                throw new \RuntimeException('サーバーのメール識別情報が変わっています。先に手動同期を実行してください。');
            }
            $uid = (int)$message['remote_uid'];
            if ($action === 'trash' || $action === 'restore') {
                $target = $action === 'trash' ? $account['folder_trash'] : 'INBOX';
                return ['remote_uid'=>ImapConnection::move($stream, $uid, $target),'remote_missing'=>0];
            }
            if ($action === 'delete') { ImapConnection::delete($stream, $uid); }
            elseif (in_array($action, ['read','unread','star','unstar'], true)) {
                ImapConnection::flag($stream, $uid, in_array($action, ['read','unread'], true) ? '\\Seen' : '\\Flagged',
                    in_array($action, ['read','star'], true));
            }
            return [];
        } finally { if ($stream !== null) { ImapConnection::close($stream); } $lock->release(); }
    }

    private function remoteFolder(array $account, string $folder): string
    {
        return match ($folder) {
            'sent'=>$account['folder_sent'],'drafts'=>$account['folder_drafts'],
            'trash'=>$account['folder_trash'],default=>'INBOX',
        };
    }
}
