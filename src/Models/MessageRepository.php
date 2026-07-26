<?php

namespace NexaPressMail\Models;

use app\Core\Database;
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
        $allowed = ['folder', 'is_read', 'is_starred'];
        $changes = array_intersect_key($changes, array_flip($allowed));
        $sets = implode(',', array_map(static fn($k) => "{$k}=?", array_keys($changes)));
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $this->pdo->prepare("UPDATE {$this->table} SET {$sets},updated_at=? WHERE id IN ({$marks})")
            ->execute([...array_values($changes), date('Y-m-d H:i:s'), ...$ids]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare("DELETE FROM {$this->table} WHERE id=?")->execute([$id]);
    }

    public function counts(): array
    {
        $rows = $this->pdo->query("SELECT folder,COUNT(*) total,SUM(is_read=0) unread FROM {$this->table} GROUP BY folder")->fetchAll();
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
        $clauses = ['folder=?'];
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
}
