<?php

namespace NexaPressMail\Models;

use app\Core\Database;
use PDO;

final class LogRepository
{
    private PDO $pdo;
    private string $table;

    public function __construct()
    {
        $this->pdo = Database::connect();
        $this->table = Database::table('nexapress_mail_logs');
    }

    public function write(string $level, string $operation, string $message, ?int $accountId = null, array $context = []): void
    {
        $context = $this->sanitize($context);
        $stmt = $this->pdo->prepare("INSERT INTO {$this->table}
            (account_id,level,operation,message,context_json,created_at) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$accountId, $level, $operation, mb_substr($message, 0, 1000),
            $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null,
            date('Y-m-d H:i:s')]);
    }

    public function page(int $page, int $perPage, string $level = ''): array
    {
        $where = in_array($level, ['info','warning','error'], true) ? 'WHERE level=?' : '';
        $args = $where ? [$level] : [];
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->table} {$where}");
        $count->execute($args);
        $offset = max(0, ($page - 1) * $perPage);
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} {$where} ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}");
        $stmt->execute($args);
        return ['items'=>$stmt->fetchAll(), 'total'=>(int)$count->fetchColumn()];
    }

    private function sanitize(array $data): array
    {
        foreach ($data as $key => $value) {
            if (preg_match('/pass|secret|token|credential|body|content/i', (string) $key)) {
                $data[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $data[$key] = $this->sanitize($value);
            } elseif (is_string($value)) {
                $data[$key] = mb_substr($value, 0, 500);
            }
        }
        return $data;
    }
}
