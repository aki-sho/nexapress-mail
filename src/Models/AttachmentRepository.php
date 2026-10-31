<?php

namespace NexaPressMail\Models;

use app\Core\Database;
use PDO;

final class AttachmentRepository
{
    private PDO $pdo;
    private string $table;

    public function __construct()
    {
        $this->pdo = Database::connect();
        $this->table = Database::table('nexapress_mail_attachments');
    }

    public function forMessage(int $messageId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE message_id=? ORDER BY id");
        $stmt->execute([$messageId]);
        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE id=?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function create(array $data): int
    {
        $data['created_at'] = date('Y-m-d H:i:s');
        $keys = array_keys($data);
        $sql = "INSERT INTO {$this->table} (" . implode(',', $keys) . ') VALUES ('
            . implode(',', array_map(static fn($key) => ':' . $key, $keys)) . ')';
        $this->pdo->prepare($sql)->execute($data);
        return (int) $this->pdo->lastInsertId();
    }

    public function deleteForMessage(int $messageId): array
    {
        $items = $this->forMessage($messageId);
        $this->pdo->prepare("DELETE FROM {$this->table} WHERE message_id=?")->execute([$messageId]);
        return $items;
    }

    public function delete(int $id, int $messageId): void
    { $this->pdo->prepare("DELETE FROM {$this->table} WHERE id=? AND message_id=?")->execute([$id,$messageId]); }
}
