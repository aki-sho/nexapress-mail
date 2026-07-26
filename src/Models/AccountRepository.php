<?php

namespace NexaPressMail\Models;

use app\Core\Database;
use NexaPressMail\Security\CredentialCipher;
use PDO;

final class AccountRepository
{
    private PDO $pdo;
    private string $table;

    public function __construct(private ?CredentialCipher $cipher = null)
    {
        $this->pdo = Database::connect();
        $this->table = Database::table('nexapress_mail_accounts');
        $this->cipher ??= new CredentialCipher();
    }

    public function all(bool $activeOnly = false): array
    {
        $sql = "SELECT * FROM {$this->table}" . ($activeOnly ? ' WHERE is_active=1' : '')
            . ' ORDER BY is_default DESC, name ASC';
        return array_map([$this, 'decrypt'], $this->pdo->query($sql)->fetchAll());
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE id=?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->decrypt($row) : null;
    }

    public function save(array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $id = (int) ($data['id'] ?? 0);
        if (!empty($data['is_default'])) {
            $this->pdo->exec("UPDATE {$this->table} SET is_default=0");
        }
        $values = $this->values($data);
        if ($id > 0) {
            $current = $this->find($id);
            if (!$current) {
                throw new \RuntimeException('アカウントが見つかりません。');
            }
            foreach (['smtp_password', 'imap_password'] as $key) {
                if ($values[$key] === '') {
                    $values[$key] = $this->cipher->encrypt($current[$key]);
                }
            }
            $sets = implode(', ', array_map(static fn($key) => "{$key}=:{$key}", array_keys($values)));
            $values['updated_at'] = $now;
            $values['id'] = $id;
            $this->pdo->prepare("UPDATE {$this->table} SET {$sets}, updated_at=:updated_at WHERE id=:id")
                ->execute($values);
            return $id;
        }
        if ($values['smtp_password'] === '' || $values['imap_password'] === '') {
            throw new \InvalidArgumentException('SMTP・IMAPパスワードは必須です。');
        }
        $values['created_at'] = $now;
        $values['updated_at'] = $now;
        $columns = implode(',', array_keys($values));
        $params = implode(',', array_map(static fn($k) => ':' . $k, array_keys($values)));
        $this->pdo->prepare("INSERT INTO {$this->table} ({$columns}) VALUES ({$params})")->execute($values);
        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE id=?");
        $stmt->execute([$id]);
    }

    public function hasMessages(int $id): bool
    {
        $messages = Database::table('nexapress_mail_messages');
        $stmt = $this->pdo->prepare("SELECT 1 FROM {$messages} WHERE account_id=? LIMIT 1");
        $stmt->execute([$id]);
        return (bool)$stmt->fetchColumn();
    }

    public function touchSync(int $id): void
    {
        $this->pdo->prepare("UPDATE {$this->table} SET last_sync_at=? WHERE id=?")
            ->execute([date('Y-m-d H:i:s'), $id]);
    }

    private function values(array $data): array
    {
        $out = [];
        foreach (['name','email','smtp_host','smtp_security','smtp_username','imap_host',
            'imap_security','imap_username','folder_sent','folder_drafts','folder_trash'] as $key) {
            $out[$key] = trim((string) ($data[$key] ?? ''));
        }
        $out['smtp_port'] = (int) ($data['smtp_port'] ?? 587);
        $out['imap_port'] = (int) ($data['imap_port'] ?? 993);
        foreach (['smtp_password','imap_password'] as $key) {
            $plain = (string) ($data[$key] ?? '');
            $out[$key] = $plain === '' ? '' : $this->cipher->encrypt($plain);
        }
        $out['is_default'] = empty($data['is_default']) ? 0 : 1;
        $out['is_active'] = empty($data['is_active']) ? 0 : 1;
        return $out;
    }

    private function decrypt(array $row): array
    {
        $row['smtp_password'] = $this->cipher->decrypt((string) $row['smtp_password']);
        $row['imap_password'] = $this->cipher->decrypt((string) $row['imap_password']);
        return $row;
    }
}
