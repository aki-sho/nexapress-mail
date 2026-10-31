<?php

namespace NexaPressMail\Database;

use app\Core\Database;
use PDO;
use PDOException;

final class FeatureMigration
{
    public static function run(PDO $pdo): void
    {
        $accounts = Database::table('nexapress_mail_accounts');
        $messages = Database::table('nexapress_mail_messages');
        foreach ([$accounts=>['auth_type'=>"VARCHAR(20) NOT NULL DEFAULT 'password'",
            'auto_sync_enabled'=>'INTEGER NOT NULL DEFAULT 0','auto_sync_interval'=>'INTEGER NOT NULL DEFAULT 300'],
            $messages=>['remote_missing'=>'INTEGER NOT NULL DEFAULT 0']] as $table=>$columns) {
            foreach ($columns as $column=>$definition) {
                $exists = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
                    ? in_array($column, array_column($pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC), 'name'), true)
                    : (bool)$pdo->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'")->fetch(PDO::FETCH_ASSOC);
                if (!$exists) {
                    try { $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}"); }
                    catch (PDOException $error) {
                        if (($error->errorInfo[1] ?? null) !== 1060) { throw $error; }
                    }
                }
            }
        }
        foreach (self::definitions() as $name=>$definition) {
            $table = Database::table('nexapress_mail_' . $name);
            $suffix = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
                ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
            $pdo->exec("CREATE TABLE IF NOT EXISTS {$table} ({$definition}){$suffix}");
        }
    }

    private static function definitions(): array
    {
        return [
            'oauth'=>"account_id BIGINT PRIMARY KEY, provider VARCHAR(20) NOT NULL, client_id VARCHAR(255) NOT NULL,
                client_secret TEXT NOT NULL, tenant VARCHAR(100) NOT NULL DEFAULT 'common', redirect_uri VARCHAR(1000) NOT NULL,
                access_token TEXT NULL, refresh_token TEXT NULL, expires_at BIGINT NOT NULL DEFAULT 0",
            'sync_state'=>"account_id BIGINT NOT NULL, folder VARCHAR(20) NOT NULL, uidvalidity BIGINT NOT NULL DEFAULT 0,
                server_key VARCHAR(64) NOT NULL DEFAULT '',
                high_uid BIGINT NOT NULL DEFAULT 0, history_uid BIGINT NOT NULL DEFAULT 0,
                history_done INTEGER NOT NULL DEFAULT 0, history_from VARCHAR(10) NOT NULL DEFAULT '',
                history_to VARCHAR(10) NOT NULL DEFAULT '', reconcile_cursor BIGINT NOT NULL DEFAULT 0,
                PRIMARY KEY (account_id,folder)",
            'sync_jobs'=>"account_id BIGINT PRIMARY KEY, next_run_at BIGINT NOT NULL DEFAULT 0,
                started_at BIGINT NULL, finished_at BIGINT NULL, status VARCHAR(20) NOT NULL DEFAULT 'idle',
                failures INTEGER NOT NULL DEFAULT 0, result_json TEXT NULL, last_error VARCHAR(1000) NULL",
            'draft_state'=>"message_id BIGINT PRIMARY KEY, user_id BIGINT NOT NULL, client_key VARCHAR(64) NOT NULL,
                revision INTEGER NOT NULL DEFAULT 0, request_id VARCHAR(64) NOT NULL DEFAULT '',
                UNIQUE (user_id,client_key)",
        ];
    }
}
