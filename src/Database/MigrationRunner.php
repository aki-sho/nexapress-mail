<?php

namespace NexaPressMail\Database;

use app\Core\Database;
use PDO;

final class MigrationRunner
{
    public static function run(): void
    {
        $pdo = Database::connect();
        foreach (self::statements() as $sql) {
            $pdo->exec($sql);
        }
        StoredMailHtmlMigration::run($pdo, Database::table('nexapress_mail_messages'),
            Database::table('nexapress_mail_migrations'));
        FeatureMigration::run($pdo);
    }

    private static function statements(): array
    {
        $accounts = Database::table('nexapress_mail_accounts');
        $messages = Database::table('nexapress_mail_messages');
        $attachments = Database::table('nexapress_mail_attachments');
        $logs = Database::table('nexapress_mail_logs');
        $migrations = Database::table('nexapress_mail_migrations');
        return [
            "CREATE TABLE IF NOT EXISTS {$migrations} (
                version VARCHAR(100) PRIMARY KEY, applied_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS {$accounts} (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL, email VARCHAR(254) NOT NULL,
                smtp_host VARCHAR(255) NOT NULL, smtp_port SMALLINT UNSIGNED NOT NULL,
                smtp_security VARCHAR(10) NOT NULL, smtp_username VARCHAR(255) NOT NULL,
                smtp_password TEXT NOT NULL, imap_host VARCHAR(255) NOT NULL,
                imap_port SMALLINT UNSIGNED NOT NULL, imap_security VARCHAR(10) NOT NULL,
                imap_username VARCHAR(255) NOT NULL, imap_password TEXT NOT NULL,
                folder_sent VARCHAR(255) NOT NULL DEFAULT 'Sent',
                folder_drafts VARCHAR(255) NOT NULL DEFAULT 'Drafts',
                folder_trash VARCHAR(255) NOT NULL DEFAULT 'Trash',
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                last_sync_at DATETIME NULL, created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL, INDEX idx_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS {$messages} (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                account_id BIGINT UNSIGNED NOT NULL, folder VARCHAR(20) NOT NULL,
                remote_uid BIGINT UNSIGNED NULL, message_id VARCHAR(255) NULL,
                in_reply_to VARCHAR(255) NULL, sender_name VARCHAR(255) NOT NULL DEFAULT '',
                sender_email VARCHAR(254) NOT NULL DEFAULT '', recipients_json LONGTEXT NOT NULL,
                subject VARCHAR(998) NOT NULL DEFAULT '', body_text LONGTEXT NULL,
                body_html LONGTEXT NULL, is_read TINYINT(1) NOT NULL DEFAULT 0,
                is_starred TINYINT(1) NOT NULL DEFAULT 0, has_attachments TINYINT(1) NOT NULL DEFAULT 0,
                received_at DATETIME NULL, sent_at DATETIME NULL,
                created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_remote (account_id, folder, remote_uid),
                INDEX idx_box (account_id, folder, received_at), INDEX idx_message_id (message_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS {$attachments} (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                message_id BIGINT UNSIGNED NOT NULL, filename VARCHAR(255) NOT NULL,
                storage_path VARCHAR(500) NOT NULL, mime_type VARCHAR(150) NOT NULL,
                size_bytes BIGINT UNSIGNED NOT NULL, content_id VARCHAR(255) NULL,
                disposition VARCHAR(20) NOT NULL DEFAULT 'attachment', created_at DATETIME NOT NULL,
                INDEX idx_message (message_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS {$logs} (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                account_id BIGINT UNSIGNED NULL, level VARCHAR(10) NOT NULL,
                operation VARCHAR(30) NOT NULL, message VARCHAR(1000) NOT NULL,
                context_json LONGTEXT NULL, created_at DATETIME NOT NULL,
                INDEX idx_created (created_at), INDEX idx_level (level)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    }
}
