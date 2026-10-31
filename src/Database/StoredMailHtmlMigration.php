<?php

namespace NexaPressMail\Database;

use NexaPressMail\Services\Security\MailHtmlSanitizer;
use PDO;
use PDOException;

final class StoredMailHtmlMigration
{
    private const VERSION = 'mail-html-sanitizer-v2';

    public static function run(PDO $pdo, string $messages, string $migrations): void
    {
        $done = $pdo->prepare("SELECT version FROM {$migrations} WHERE version=?");
        $done->execute([self::VERSION]);
        if ($done->fetchColumn() !== false) { return; }

        $sanitizer = new MailHtmlSanitizer();
        $cursor = 0;
        $select = $pdo->prepare("SELECT id FROM {$messages}
            WHERE id>? AND body_html IS NOT NULL AND body_html<>'' ORDER BY id LIMIT 100");
        $body = $pdo->prepare("SELECT body_html FROM {$messages} WHERE id=?");
        // Do not overwrite a draft that was edited while the migration was running.
        $comparison = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'BINARY body_html=BINARY ?' : 'body_html=?';
        $update = $pdo->prepare("UPDATE {$messages} SET body_html=? WHERE id=? AND {$comparison}");
        do {
            $select->execute([$cursor]);
            $rows = $select->fetchAll(PDO::FETCH_ASSOC);
            $select->closeCursor();
            foreach ($rows as $row) {
                // Load one body at a time, regardless of the pagination batch size.
                $body->execute([$row['id']]);
                $html = $body->fetchColumn();
                $body->closeCursor();
                if (is_string($html)) {
                    $safe = $sanitizer->sanitize($html);
                    if ($safe !== $html) { $update->execute([$safe, $row['id'], $html]); }
                }
                $cursor = (int)$row['id'];
            }
        } while (count($rows) === 100);

        try {
            $pdo->prepare("INSERT INTO {$migrations} (version,applied_at) VALUES (?,?)")
                ->execute([self::VERSION, date('Y-m-d H:i:s')]);
        } catch (PDOException $error) {
            // Concurrent bootstraps may finish the same idempotent migration.
            $done->execute([self::VERSION]);
            if ($error->getCode() !== '23000' || $done->fetchColumn() === false) { throw $error; }
        }
    }
}
