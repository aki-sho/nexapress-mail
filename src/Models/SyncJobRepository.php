<?php

namespace NexaPressMail\Models;

use app\Core\Database;

final class SyncJobRepository
{
    private \PDO $pdo;
    private string $table;
    public function __construct() { $this->pdo = Database::connect(); $this->table = Database::table('nexapress_mail_sync_jobs'); }
    public function all(): array { return $this->pdo->query("SELECT * FROM {$this->table} ORDER BY account_id")->fetchAll(); }
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE account_id=?"); $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
    public function start(int $id): void
    {
        if (!$this->find($id)) { $this->pdo->prepare("INSERT INTO {$this->table} (account_id) VALUES (?)")->execute([$id]); }
        $this->pdo->prepare("UPDATE {$this->table} SET status='running',started_at=?,last_error=NULL WHERE account_id=?")->execute([time(),$id]);
    }
    public function finish(int $id, int $interval, array $result, ?string $error): void
    {
        $old = $this->find($id);
        $failures = $error === null ? 0 : min(10, (int)($old['failures'] ?? 0) + 1);
        $delay = $failures ? min(3600, 60 * (2 ** ($failures - 1))) : $interval;
        $this->pdo->prepare("UPDATE {$this->table} SET status=?,finished_at=?,next_run_at=?,failures=?,result_json=?,last_error=? WHERE account_id=?")
            ->execute([$error === null ? 'complete' : 'failed',time(),time() + $delay,$failures,
                json_encode($result, JSON_UNESCAPED_UNICODE),$error === null ? null : mb_substr($error, 0, 1000),$id]);
    }
}
