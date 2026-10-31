<?php

namespace NexaPressMail\Models;

use app\Core\Database;
use NexaPressMail\Services\Security\MailHtmlSanitizer;
use PDO;

final class MessageRepository
{
    private PDO $pdo;
    private string $table;
    private const FOLDERS = ['inbox', 'sent', 'drafts', 'trash'];

    public function __construct()
    {
        $this->pdo = Database::connect();
        $this->table = Database::table('nexapress_mail_messages');
    }

    public function page(array $filters, int $page, int $perPage): array
    {
        [$where, $args] = $this->where($filters);
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->table} {$where}");
        $count->execute($args);
        $total = (int) $count->fetchColumn();
        $offset = max(0, ($page - 1) * $perPage);
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} {$where}
            ORDER BY COALESCE(received_at,sent_at,created_at) DESC LIMIT {$perPage} OFFSET {$offset}");
        $stmt->execute($args);
        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE id=?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (is_array($row) && !empty($row['body_html'])) {
            $row['body_html'] = (new MailHtmlSanitizer())->sanitize($row['body_html']);
        }
        return is_array($row) ? $row : null;
    }

    public function existsRemote(int $accountId, string $folder, int $uid): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM {$this->table} WHERE account_id=? AND folder=? AND remote_uid=?");
        $stmt->execute([$accountId, $folder, $uid]);
        return (bool) $stmt->fetchColumn();
    }

    public function save(array $data): int
    {
        if (isset($data['body_html'])) {
            $data['body_html'] = (new MailHtmlSanitizer())->sanitize((string)$data['body_html']);
        }
        $data = array_merge([
            'remote_uid'=>null,'message_id'=>null,'in_reply_to'=>null,'sender_name'=>'',
            'sender_email'=>'','recipients_json'=>'{}','subject'=>'','body_text'=>null,
            'body_html'=>null,'is_read'=>0,'is_starred'=>0,'has_attachments'=>0,
            'received_at'=>null,'sent_at'=>null,
        ], $data);
        $id = (int) ($data['id'] ?? 0);
        unset($data['id']);
        $data['updated_at'] = date('Y-m-d H:i:s');
        if ($id) {
            $sets = implode(',', array_map(static fn($k) => "{$k}=:{$k}", array_keys($data)));
            $data['id'] = $id;
            $this->pdo->prepare("UPDATE {$this->table} SET {$sets} WHERE id=:id")->execute($data);
            return $id;
        }
        $data['created_at'] = $data['updated_at'];
        $keys = array_keys($data);
        $this->pdo->prepare("INSERT INTO {$this->table} (" . implode(',', $keys) . ') VALUES ('
            . implode(',', array_map(static fn($k) => ':' . $k, $keys)) . ')')->execute($data);
        return (int) $this->pdo->lastInsertId();
    }

    public function updateFlags(array $ids, array $changes): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn($id) => $id > 0));
        if (!$ids || !$changes) {
            return;
        }
        $allowed = ['folder', 'is_read', 'is_starred', 'remote_uid', 'remote_missing'];
        $changes = array_intersect_key($changes, array_flip($allowed));
        $sets = implode(',', array_map(static fn($k) => "{$k}=?", array_keys($changes)));
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $this->pdo->prepare("UPDATE {$this->table} SET {$sets},updated_at=? WHERE id IN ({$marks})")
            ->execute([...array_values($changes), date('Y-m-d H:i:s'), ...$ids]);
    }

    public function delete(int $id): void
    {
        $drafts = Database::table('nexapress_mail_draft_state');
        $this->pdo->prepare("DELETE FROM {$drafts} WHERE message_id=?")->execute([$id]);
        $this->pdo->prepare("DELETE FROM {$this->table} WHERE id=?")->execute([$id]);
    }

    public function counts(): array
    {
        $rows = $this->pdo->query("SELECT folder,COUNT(*) total,SUM(is_read=0) unread FROM {$this->table} WHERE remote_missing=0 GROUP BY folder")->fetchAll();
        $out = array_fill_keys(self::FOLDERS, ['total'=>0,'unread'=>0]);
        foreach ($rows as $row) {
            if (isset($out[$row['folder']])) {
                $out[$row['folder']] = ['total'=>(int)$row['total'],'unread'=>(int)$row['unread']];
            }
        }
        return $out;
    }

    private function where(array $filters): array
    {
        $folder = in_array($filters['folder'] ?? '', self::FOLDERS, true) ? $filters['folder'] : 'inbox';
        $clauses = ['folder=?','remote_missing=0'];
        $args = [$folder];
        if ((int) ($filters['account_id'] ?? 0) > 0) {
            $clauses[] = 'account_id=?'; $args[] = (int) $filters['account_id'];
        }
        if (($filters['status'] ?? '') === 'unread') {$clauses[] = 'is_read=0';}
        if (($filters['status'] ?? '') === 'starred') {$clauses[] = 'is_starred=1';}
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $clauses[] = '(subject LIKE ? OR sender_email LIKE ? OR sender_name LIKE ? OR body_text LIKE ?)';
            $like = '%' . str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], $q) . '%';
            array_push($args, $like, $like, $like, $like);
        }
        return ['WHERE ' . implode(' AND ', $clauses), $args];
    }

    public function invalidateRemote(int $accountId, string $folder, bool $hide = true): void
    {
        $this->pdo->prepare("UPDATE {$this->table} SET remote_uid=NULL,remote_missing=? WHERE account_id=? AND folder=? AND remote_uid IS NOT NULL")
            ->execute([(int)$hide,$accountId,$folder]);
    }

    public function remotePage(int $accountId, string $folder, int $cursor, int $limit): array
    {
        $stmt = $this->pdo->prepare("SELECT id,remote_uid FROM {$this->table} WHERE account_id=? AND folder=? AND remote_uid IS NOT NULL AND id>? ORDER BY id LIMIT {$limit}");
        $stmt->execute([$accountId,$folder,$cursor]); return $stmt->fetchAll();
    }

    public function importRecord(array $record): array
    {
        $id = 0;
        if (!empty($record['message_id'])) {
            $stmt = $this->pdo->prepare("SELECT id,recipients_json FROM {$this->table} WHERE account_id=? AND message_id=? AND sender_email=? AND subject=?
                AND (remote_missing=1 OR (remote_uid IS NULL AND folder=? AND folder<>'drafts')) ORDER BY id LIMIT 1");
            $stmt->execute([$record['account_id'],$record['message_id'],$record['sender_email'],$record['subject'],$record['folder']]);
            $cached = $stmt->fetch(); $id = (int)($cached['id'] ?? 0);
            if ($id && $record['folder'] === 'sent') {
                $oldRecipients = json_decode($cached['recipients_json'], true) ?: [];
                $recipients = json_decode($record['recipients_json'], true) ?: [];
                if (!empty($oldRecipients['bcc']) && empty($recipients['bcc'])) {
                    $recipients['bcc'] = $oldRecipients['bcc']; $record['recipients_json'] = json_encode($recipients, JSON_UNESCAPED_UNICODE);
                }
            }
        }
        $record['remote_missing'] = 0;
        if ($id) { $record['id'] = $id; }
        return ['id'=>$this->save($record),'reused'=>(bool)$id];
    }
}
