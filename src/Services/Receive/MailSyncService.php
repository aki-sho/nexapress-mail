<?php

namespace NexaPressMail\Services\Receive;

use app\Core\Database;
use NexaPressMail\Models\AccountRepository;
use NexaPressMail\Models\AttachmentRepository;
use NexaPressMail\Models\LogRepository;
use NexaPressMail\Models\MessageRepository;
use NexaPressMail\Models\SyncStateRepository;
use NexaPressMail\Models\SyncJobRepository;
use NexaPressMail\Services\Attachment\AttachmentPersister;
use NexaPressMail\Services\Attachment\AttachmentStorage;
use NexaPressMail\Services\Imap\ImapConnection;
use NexaPressMail\Services\Imap\ImapPartReader;
use NexaPressMail\Services\Imap\MailReceiveLimitException;
use NexaPressMail\Services\Imap\OAuthImapClient;
use NexaPressMail\Services\Parser\AddressParser;
use NexaPressMail\Services\Parser\SubjectDecoder;
use NexaPressMail\Services\Security\PrivateLock;
use NexaPressMail\Services\Security\LockBusyException;

final class MailSyncService
{
    public function sync(?int $onlyAccount = null, array $input = [], bool $dueOnly = false): array
    {
        $options = SyncOptions::fromInput($input);
        $accounts = new AccountRepository(); $jobs = new SyncJobRepository(); $logs = new LogRepository();
        $result = ['received'=>0,'skipped'=>0,'rejected'=>0,'updated'=>0,'busy'=>0,'errors'=>[]];
        foreach ($accounts->all(true) as $account) {
            $id = (int)$account['id'];
            if ($onlyAccount && $id !== $onlyAccount) { continue; }
            if ($dueOnly && (empty($account['auto_sync_enabled']) || (int)($jobs->find($id)['next_run_at'] ?? 0) > time())) { continue; }
            try { $lock = new PrivateLock('sync-' . $id, false); }
            catch (LockBusyException $error) { $result['busy']++; continue; }
            $partResult = ['received'=>0,'skipped'=>0,'rejected'=>0,'updated'=>0];
            try {
                // Recheck due state under the same lock used by manual synchronization.
                if ($dueOnly && (int)($jobs->find($id)['next_run_at'] ?? 0) > time()) { continue; }
                $jobs->start($id);
                foreach ($this->folders($account) as $local=>$remote) {
                    $part = $this->syncFolder($account, $local, $remote, $options);
                    foreach (array_keys($partResult) as $key) { $partResult[$key] += $part[$key]; }
                }
                $accounts->touchSync($id);
                $jobs->finish($id, (int)$account['auto_sync_interval'], $partResult, null);
                $logs->write('info', 'sync', "同期完了: {$partResult['received']}件取得、{$partResult['updated']}件状態更新", $id);
            } catch (\Throwable $error) {
                $result['errors'][] = $account['name'] . ': ' . $error->getMessage();
                $jobs->finish($id, (int)$account['auto_sync_interval'], $partResult, $error->getMessage());
                $logs->write('error', 'sync', $error->getMessage(), $id);
            } finally {
                foreach (array_keys($partResult) as $key) { $result[$key] += $partResult[$key]; }
                $lock->release();
            }
        }
        return $result;
    }

    private function syncFolder(array $account, string $local, string $remote, array $input = []): array
    {
        $options = SyncOptions::fromInput($input);
        $states = new SyncStateRepository(); $repo = new MessageRepository();
        $state = $states->get((int)$account['id'], $local);
        $stream = ImapConnection::open($account, $remote);
        try {
            $status = ImapConnection::status($stream, $account, $remote);
            $serverKey = hash('sha256', implode('|', [$account['imap_host'],$account['imap_port'],$account['imap_username'],$remote]));
            if ((int)$state['uidvalidity'] !== (int)$status->uidvalidity || $state['server_key'] !== $serverKey) {
                $repo->invalidateRemote((int)$account['id'], $local, (int)$state['uidvalidity'] > 0 || $local === 'drafts');
                $state = array_replace($state, ['uidvalidity'=>(int)$status->uidvalidity,'server_key'=>$serverKey,'high_uid'=>0,'history_uid'=>0,
                    'history_done'=>0,'reconcile_cursor'=>0]);
                $states->save($state);
            }
            $limits = require NEXAPRESS_MAIL_PATH . '/config/limits.php';
            $updated = $this->reconcile($stream, $state, $limits['sync_reconcile_limit']);
            $history = $options['mode'] === 'history';
            if ($history && ($state['history_from'] !== $options['from'] || $state['history_to'] !== $options['to'])) {
                $state = array_replace($state, ['history_from'=>$options['from'],'history_to'=>$options['to'],'history_uid'=>0,'history_done'=>0]);
            }
            $criteria = $history ? SyncOptions::criteria($options) : 'UNDELETED';
            $initial = !$history && (int)$state['high_uid'] === 0;
            $empty = false;
            if ($history) {
                if ((int)$state['history_done'] || (int)$state['history_uid'] === 1) { $empty = true; }
                elseif ((int)$state['history_uid'] > 1) { $criteria .= ' UID 1:' . ((int)$state['history_uid'] - 1); }
            } elseif (!$initial) {
                $last = (int)$status->uidnext - 1;
                if ((int)$state['high_uid'] >= $last) { $empty = true; }
                else { $criteria .= ' UID ' . ((int)$state['high_uid'] + 1) . ':' . $last; }
            }
            $uids = $empty ? [] : ImapConnection::search($stream, $criteria);
            if (!$history && !$initial) { $uids = array_values(array_filter($uids, static fn($uid) => $uid > (int)$state['high_uid'])); }
            $totalCandidates = count($uids);
            if ($history || $initial) { rsort($uids, SORT_NUMERIC); } else { sort($uids, SORT_NUMERIC); }
            $uids = array_slice($uids, 0, $limits['sync_limit']);
            // Import selected UIDs in ascending order, but only advance the delta cursor for delta batches.
            sort($uids, SORT_NUMERIC);
            $received = $skipped = $rejected = 0;
            foreach ($uids as $uid) {
                if ($repo->existsRemote((int)$account['id'], $local, (int)$uid)) { $skipped++; }
                else {
                    try { $this->import($stream, $account, $local, (int)$uid); $received++; }
                    catch (MailReceiveLimitException $error) {
                        $rejected++;
                        (new LogRepository())->write('warning', 'receive-limit', $error->getMessage(), (int)$account['id'], ['folder'=>$local,'uid'=>(int)$uid]);
                        if ($stream instanceof OAuthImapClient && !$stream->connected()) {
                            ImapConnection::close($stream); $stream = ImapConnection::open($account, $remote);
                            if ((int)ImapConnection::status($stream, $account, $remote)->uidvalidity !== (int)$state['uidvalidity']) {
                                throw new \RuntimeException('同期中にUIDVALIDITYが変わりました。もう一度同期してください。');
                            }
                        }
                    }
                }
                if (!$history) { $state['high_uid'] = max((int)$state['high_uid'], (int)$uid); }
                $states->save($state);
            }
            if ($history || $initial) {
                if ($uids) { $state['history_uid'] = min($uids); }
                $state['history_done'] = (int)($totalCandidates <= $limits['sync_limit']);
            }
            $states->save($state);
            return compact('received','skipped','rejected','updated');
        } finally { ImapConnection::close($stream); }
    }

    private function reconcile($stream, array &$state, int $limit): int
    {
        $repo = new MessageRepository();
        $rows = $repo->remotePage((int)$state['account_id'], $state['folder'], (int)$state['reconcile_cursor'], $limit);
        $flags = ImapConnection::flags($stream, array_column($rows, 'remote_uid'));
        foreach ($rows as $row) {
            $changes = $flags[(int)$row['remote_uid']] ?? ['remote_missing'=>1];
            if (isset($flags[(int)$row['remote_uid']])) { $changes['remote_missing'] = 0; }
            $repo->updateFlags([(int)$row['id']], $changes);
        }
        $state['reconcile_cursor'] = count($rows) === $limit ? (int)end($rows)['id'] : 0;
        return count($rows);
    }

    private function import($stream, array $account, string $folder, int $uid): void
    {
        $overview = ImapConnection::overview($stream, $uid);
        if (!$overview) { throw new \RuntimeException("UID {$uid} のヘッダーを取得できません。"); }
        $parts = (new ImapPartReader())->read($stream, $uid, (int)($overview->size ?? 0));
        $from = AddressParser::parse((string)($overview->from ?? ''))[0] ?? ['name'=>'','email'=>''];
        $recipients = ['to'=>AddressParser::parse((string)($overview->to ?? '')),
            'cc'=>AddressParser::parse((string)($overview->cc ?? '')),'bcc'=>[]];
        $timestamp = date('Y-m-d H:i:s', (int)($overview->udate ?? time()));
        $pdo = Database::connect(); $pdo->beginTransaction(); $createdFiles = []; $oldFileIds = [];
        try {
            $result = (new MessageRepository())->importRecord([
                'account_id'=>(int)$account['id'],'folder'=>$folder,'remote_uid'=>$uid,
                'message_id'=>trim((string)($overview->message_id ?? ''), '<>') ?: null,
                'in_reply_to'=>trim((string)($overview->in_reply_to ?? ''), '<>') ?: null,
                'sender_name'=>$from['name'],'sender_email'=>$from['email'],
                'recipients_json'=>json_encode($recipients, JSON_UNESCAPED_UNICODE),
                'subject'=>SubjectDecoder::decode((string)($overview->subject ?? '')),
                'body_text'=>$parts['text'],'body_html'=>$parts['html'],
                'is_read'=>empty($overview->seen) ? 0 : 1,'is_starred'=>empty($overview->flagged) ? 0 : 1,
                'has_attachments'=>$parts['attachments'] ? 1 : 0,
                'received_at'=>$folder === 'inbox' ? $timestamp : null,'sent_at'=>$folder !== 'inbox' ? $timestamp : null,
            ]);
            $id = $result['id']; $attachments = new AttachmentRepository();
            $oldFileIds = array_column($attachments->forMessage($id), 'id');
            if (!$result['reused'] || !$oldFileIds) {
                foreach ($parts['attachments'] as $attachment) {
                    try { (new AttachmentPersister())->persistBytes($id, $attachment); }
                    catch (\InvalidArgumentException $error) {
                        (new LogRepository())->write('warning', 'attachment', $error->getMessage(), (int)$account['id']);
                    }
                }
                $createdFiles = array_filter($attachments->forMessage($id), static fn($file) => !in_array($file['id'], $oldFileIds));
            }
            $pdo->commit();
        } catch (\Throwable $error) {
            if (isset($id) && !$createdFiles) {
                $createdFiles = array_filter((new AttachmentRepository())->forMessage($id), static fn($file) => !in_array($file['id'], $oldFileIds));
            }
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            foreach ($createdFiles as $file) { (new AttachmentStorage())->delete($file['storage_path']); }
            throw $error;
        }
    }

    private function folders(array $account): array
    { return ['inbox'=>'INBOX','sent'=>$account['folder_sent'],'drafts'=>$account['folder_drafts'],'trash'=>$account['folder_trash']]; }
}
