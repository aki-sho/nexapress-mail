<?php

namespace app\Core {
    final class Auth { public static function user(): array { return ['id'=>1]; } }
    final class Permission { public static function require(string $permission): void {} }
    final class Csrf {
        public static function token(): string { return 'test-csrf'; }
        public static function requireValid($value): void {
            if ($value !== self::token()) { http_response_code(403); throw new \RuntimeException('CSRF validation failed'); }
        }
    }
    final class Database
    {
        public static ?\PDO $pdo = null;
        public static function connect(): \PDO { return self::$pdo; }
        public static function table(string $name): string { return $name; }
    }
}

namespace NexaPressMail\Tests {
    final class ImapFixture
    {
        public static array $structures = [];
        public static array $bodies = [];
        public static array $overview = [];
        public static int $fetches = 0;
        public static int $structureReads = 0;
        public static int $written = 0;
        public static string $folder = 'INBOX';
        public static array $mailboxes = [];
        public static array $validities = [];
        public static array $searches = [];
        public static int $flagCalls = 0;

        public static function reset(): void
        {
            self::$structures = self::$bodies = self::$overview = [];
            self::$fetches = self::$structureReads = self::$written = 0;
            self::$mailboxes = self::$validities = self::$searches = [];
            self::$folder = 'INBOX';
            self::$flagCalls = 0;
        }

        public static function database(string $dsn = 'sqlite::memory:'): \PDO
        {
            $pdo = new \PDO($dsn, null, null, [\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC]);
            if ((int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='nexapress_mail_messages'")->fetchColumn()) {
                \app\Core\Database::$pdo = $pdo; return $pdo;
            }
            $pdo->exec("CREATE TABLE nexapress_mail_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER NOT NULL DEFAULT 1, folder TEXT DEFAULT 'inbox',
                remote_uid INTEGER, message_id TEXT, in_reply_to TEXT, sender_name TEXT DEFAULT '', sender_email TEXT DEFAULT '',
                recipients_json TEXT DEFAULT '{}', subject TEXT DEFAULT '', body_text TEXT, body_html TEXT,
                is_read INTEGER DEFAULT 0, is_starred INTEGER DEFAULT 0, has_attachments INTEGER DEFAULT 0,
                received_at TEXT, sent_at TEXT, created_at TEXT, updated_at TEXT,
                UNIQUE(account_id,folder,remote_uid))");
            $pdo->exec("CREATE TABLE nexapress_mail_accounts (id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,email TEXT NOT NULL,smtp_host TEXT NOT NULL,smtp_port INTEGER NOT NULL,
                smtp_security TEXT NOT NULL,smtp_username TEXT NOT NULL,smtp_password TEXT NOT NULL,
                imap_host TEXT NOT NULL,imap_port INTEGER NOT NULL,imap_security TEXT NOT NULL,
                imap_username TEXT NOT NULL,imap_password TEXT NOT NULL,folder_sent TEXT NOT NULL,
                folder_drafts TEXT NOT NULL,folder_trash TEXT NOT NULL,is_active INTEGER DEFAULT 1,
                is_default INTEGER DEFAULT 0,last_sync_at TEXT,created_at TEXT,updated_at TEXT)");
            $pdo->exec('CREATE TABLE nexapress_mail_attachments (id INTEGER PRIMARY KEY AUTOINCREMENT,
                message_id INTEGER,filename TEXT,storage_path TEXT,mime_type TEXT,size_bytes INTEGER,
                content_id TEXT,disposition TEXT,created_at TEXT)');
            $pdo->exec('CREATE TABLE nexapress_mail_migrations (version TEXT PRIMARY KEY, applied_at TEXT NOT NULL)');
            $pdo->exec('CREATE TABLE nexapress_mail_logs (id INTEGER PRIMARY KEY, account_id INTEGER, level TEXT,
                operation TEXT, message TEXT, context_json TEXT, created_at TEXT)');
            \app\Core\Database::$pdo = $pdo;
            \NexaPressMail\Database\FeatureMigration::run($pdo);
            return $pdo;
        }

        public static function messages(): array { return self::$mailboxes[self::$folder]['overview'] ?? self::$overview; }
    }
}

namespace NexaPressMail\Services\Imap {
    use NexaPressMail\Tests\ImapFixture as Fixture;
    function imap_fetchstructure($stream, $uid, $flags) {
        Fixture::$structureReads++;
        return Fixture::$mailboxes[Fixture::$folder]['structures'][$uid] ?? Fixture::$structures[$uid] ?? false;
    }
    function imap_savebody($stream, $file, $uid, $section, $flags): bool {
        Fixture::$fetches++;
        $body = Fixture::$mailboxes[Fixture::$folder]['bodies'][$uid][$section] ?? Fixture::$bodies[$uid][$section] ?? '';
        $length = is_int($body) ? $body : strlen($body);
        for ($offset = 0; $offset < $length; $offset += 8192) {
            $chunk = is_int($body) ? str_repeat('x', min(8192, $length - $offset)) : substr($body, $offset, 8192);
            $written = fwrite($file, $chunk);
            if ($written === false || $written !== strlen($chunk)) { return false; }
            Fixture::$written += $written;
        }
        return true;
    }
    function imap_timeout($type, $timeout): bool { return true; }
    function imap_open($mailbox, $username, $password, $flags, $retries) {
        Fixture::$folder = substr($mailbox, strpos($mailbox, '}') + 1); return 'fixture-stream';
    }
    function imap_status($stream, $mailbox, $flags): object {
        return (object)['uidvalidity'=>Fixture::$validities[Fixture::$folder] ?? 1,
            'uidnext'=>Fixture::messages() ? max(array_keys(Fixture::messages())) + 1 : 1];
    }
    function imap_search($stream, $criteria, $flags): array {
        Fixture::$searches[] = $criteria; $out = [];
        foreach (Fixture::messages() as $uid=>$message) {
            if (!empty($message->deleted)) { continue; }
            if (preg_match('/UID (\d+):(\d+)/', $criteria, $range) && ($uid < (int)$range[1] || $uid > (int)$range[2])) { continue; }
            if (preg_match('/SINCE "([^"]+)"/', $criteria, $date) && ($message->udate ?? 0) < strtotime($date[1])) { continue; }
            if (preg_match('/BEFORE "([^"]+)"/', $criteria, $date) && ($message->udate ?? 0) >= strtotime($date[1])) { continue; }
            $out[] = $uid;
        }
        return $out;
    }
    function imap_fetch_overview($stream, $uids, $flags): array {
        $out = [];
        foreach (explode(',', (string)$uids) as $uid) {
            if (isset(Fixture::messages()[(int)$uid])) { $row = clone Fixture::messages()[(int)$uid]; $row->uid = (int)$uid; $out[] = $row; }
        }
        return $out;
    }
    function imap_close($stream): bool { return true; }
    function imap_mail_move($stream, $uid, $folder, $flags): bool
    { return $uid === '31' && $folder === 'Trash' && $flags === CP_UID; }
    function imap_expunge($stream): never
    { throw new \LogicException('Native IMAP must not issue an unscoped expunge'); }
    function imap_setflag_full($stream, $uid, $flag, $options): bool { Fixture::$flagCalls++; return true; }
    function imap_clearflag_full($stream, $uid, $flag, $options): bool { Fixture::$flagCalls++; return true; }
}

namespace {
    if (!defined('BASE_PATH')) {
        $testBase = sys_get_temp_dir() . '/npm-tests-' . bin2hex(random_bytes(8));
        mkdir($testBase, 0700); define('BASE_PATH', $testBase);
        register_shutdown_function(static function () use ($testBase): void {
            $root = realpath($testBase); $temporary = realpath(sys_get_temp_dir());
            if (!$root || dirname($root) !== $temporary || !preg_match('/^npm-tests-[a-f0-9]{16}$/', basename($root))) { return; }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $item) {
                $resolved = $item->getRealPath();
                if (!$resolved || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) { continue; }
                if ($item->isDir()) { rmdir($resolved); } else { unlink($resolved); }
            }
            rmdir($root);
        });
    }
}

namespace NexaPressMail\Services\Receive {
    use NexaPressMail\Tests\ImapFixture as Fixture;
    function imap_search($stream, $criteria, $flags): array { return array_keys(Fixture::$overview); }
    function imap_fetch_overview($stream, $uid, $flags): array { return [Fixture::$overview[(int)$uid]]; }
    function imap_close($stream): bool { return true; }
}
