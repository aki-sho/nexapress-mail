<?php

namespace NexaPressMail\Models;

use app\Core\Database;

final class SyncStateRepository
{
    private \PDO $pdo;
    private string $table;
    public function __construct() { $this->pdo = Database::connect(); $this->table = Database::table('nexapress_mail_sync_state'); }

    public function get(int $accountId, string $folder): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE account_id=? AND folder=?"); $stmt->execute([$accountId,$folder]);
        $row = $stmt->fetch();
        if ($row) { return $row; }
        $this->pdo->prepare("INSERT INTO {$this->table} (account_id,folder) VALUES (?,?)")->execute([$accountId,$folder]);
        $stmt->execute([$accountId,$folder]); return $stmt->fetch();
    }

    public function save(array $state): void
    {
        $keys = ['uidvalidity','server_key','high_uid','history_uid','history_done','history_from','history_to','reconcile_cursor'];
        $sets = implode(',', array_map(static fn($key) => $key . '=?', $keys));
        $this->pdo->prepare("UPDATE {$this->table} SET {$sets} WHERE account_id=? AND folder=?")
            ->execute([...array_map(static fn($key) => $state[$key], $keys),$state['account_id'],$state['folder']]);
    }
}
