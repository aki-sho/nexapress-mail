<?php

use NexaPressMail\Database\StoredMailHtmlMigration;
use NexaPressMail\Models\MessageRepository;
use NexaPressMail\Services\Imap\ImapConnection;
use NexaPressMail\Services\Imap\ImapPartReader;
use NexaPressMail\Services\Imap\MailReceiveLimitException;
use NexaPressMail\Services\Receive\MailSyncService;
use NexaPressMail\Services\Security\EncryptionKeyManager;
use NexaPressMail\Services\Security\MailHtmlSanitizer;
use NexaPressMail\Services\Smtp\SmtpClient;
use NexaPressMail\Tests\ImapFixture as Fixture;
use NexaPressMail\Validation\AccountValidator;

require __DIR__ . '/support.php';
$limits = require NEXAPRESS_MAIL_PATH . '/config/limits.php';
$reject = static function (callable $callback, string $class) use ($assert): void {
    try { $callback(); }
    catch (Throwable $error) { $assert($error instanceof $class, get_class($error) . ': ' . $error->getMessage()); return; }
    throw new RuntimeException('unsafe input was accepted');
};
$leaf = static function (int $size, bool $attachment = false, int $encoding = 0, string $subtype = 'plain'): object {
    return (object)['type'=>$attachment ? 3 : 0,'subtype'=>$attachment ? 'octet-stream' : $subtype,
        'bytes'=>$size,'encoding'=>$encoding,'parameters'=>$attachment ? [(object)['attribute'=>'name','value'=>'safe.txt']] : []];
};
$multipart = static fn(array $parts): object => (object)['type'=>1,'subtype'=>'mixed','parts'=>$parts];
$account = ['id'=>1,'name'=>'Test','email'=>'test@example.com','smtp_host'=>'mail.example.com','smtp_port'=>587,
    'smtp_security'=>'tls','smtp_username'=>'test','smtp_password'=>'dummy','imap_host'=>'mail.example.com',
    'imap_port'=>993,'imap_security'=>'ssl','imap_username'=>'test','imap_password'=>'dummy',
    'folder_sent'=>'Sent','folder_drafts'=>'Drafts','folder_trash'=>'Trash'];

$test('nested disallowed HTML wrappers cannot bypass sanitization', static function () use ($assert): void {
    $html = (new MailHtmlSanitizer())->sanitize('<section><article><script>alert(1)</script><p onclick="x()">ok</p>'
        . '<img src="https://track.test/a" onerror="x()"><a href="javascript:alert(1)">link</a></article></section>');
    foreach (['script','onclick','onerror','track.test','javascript:'] as $unsafe) {
        $assert(!str_contains($html, $unsafe), $html);
    }
    $assert(str_contains($html, '<p>ok</p>'));
});
$test('HTML sanitizer handles parser edge cases and is idempotent', static function () use ($assert): void {
    $sanitizer = new MailHtmlSanitizer();
    foreach (['<section><svg><script>x()</script></svg><b>safe</b></section>',
        '<!-- <img src=x onerror=x()> --><p>safe</p>',
        '<section><a href="java&#x09;script:alert(1)" style="color:red">safe</a></section>',
        str_repeat('<section>', 100) . '<script>x()</script>' . str_repeat('</section>', 100)] as $input) {
        $safe = $sanitizer->sanitize($input);
        $assert(!preg_match('/<script|onerror|style=|javascript:/i', $safe), $safe);
        $assert($safe === $sanitizer->sanitize($safe));
    }
});
$test('stored HTML migration covers all batches and runs once', static function () use ($assert): void {
    $pdo = Fixture::database();
    $insert = $pdo->prepare('INSERT INTO nexapress_mail_messages (body_html) VALUES (?)');
    for ($i = 0; $i < 205; $i++) { $insert->execute(['<section><script>x()</script><p onclick="x()">safe</p></section>']); }
    StoredMailHtmlMigration::run($pdo, 'nexapress_mail_messages', 'nexapress_mail_migrations');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM nexapress_mail_messages WHERE body_html='<p>safe</p>'")->fetchColumn() === 205);
    $assert((int)$pdo->query('SELECT COUNT(*) FROM nexapress_mail_migrations')->fetchColumn() === 1);
    $insert->execute(['<p>after migration</p>']);
    StoredMailHtmlMigration::run($pdo, 'nexapress_mail_messages', 'nexapress_mail_migrations');
    $assert((int)$pdo->query('SELECT COUNT(*) FROM nexapress_mail_migrations')->fetchColumn() === 1);
});
$test('repository sanitizes both saved and previously stored HTML', static function () use ($assert): void {
    $pdo = Fixture::database();
    $repo = new MessageRepository();
    $input = '<section><script>x()</script><p onmouseover="x()">safe</p></section>';
    $id = $repo->save(['account_id'=>1,'folder'=>'drafts','body_html'=>$input]);
    $assert($pdo->query('SELECT body_html FROM nexapress_mail_messages')->fetchColumn() === '<p>safe</p>');
    $pdo->prepare('UPDATE nexapress_mail_messages SET body_html=? WHERE id=?')->execute([$input, $id]);
    $assert($repo->find($id)['body_html'] === '<p>safe</p>');
});
$test('interrupted HTML migration resumes without marking partial work complete', static function () use ($assert, $reject): void {
    $pdo = Fixture::database();
    $insert = $pdo->prepare('INSERT INTO nexapress_mail_messages (body_html) VALUES (?)');
    $insert->execute(['<section><script>x()</script><p>safe</p></section>']);
    $insert->execute(['<section><script>x()</script><p>safe</p></section>']);
    $pdo->exec("CREATE TRIGGER fail_second_update BEFORE UPDATE ON nexapress_mail_messages
        WHEN OLD.id=2 BEGIN SELECT RAISE(ABORT, 'test interruption'); END");
    $reject(static fn() => StoredMailHtmlMigration::run($pdo, 'nexapress_mail_messages', 'nexapress_mail_migrations'), PDOException::class);
    $assert((int)$pdo->query('SELECT COUNT(*) FROM nexapress_mail_migrations')->fetchColumn() === 0);
    $pdo->exec('DROP TRIGGER fail_second_update');
    StoredMailHtmlMigration::run($pdo, 'nexapress_mail_messages', 'nexapress_mail_migrations');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM nexapress_mail_messages WHERE body_html='<p>safe</p>'")->fetchColumn() === 2);
});
$test('oversized or unknown messages are rejected before fetching structure', static function () use ($assert, $reject, $limits): void {
    Fixture::reset();
    foreach ([0, -1, $limits['receive_message_bytes'] + 1] as $size) {
        $reject(static fn() => (new ImapPartReader())->read(null, 1, $size), MailReceiveLimitException::class);
    }
    $assert(Fixture::$structureReads === 0 && Fixture::$fetches === 0);
});
$test('oversized body and attachments are rejected before fetching bytes', static function () use ($assert, $reject, $limits, $leaf, $multipart): void {
    foreach ([$leaf($limits['receive_body_bytes'] + 1), $leaf($limits['attachment_bytes'] + 1, true)] as $oversized) {
        Fixture::reset();
        Fixture::$structures[1] = $multipart([$leaf(1), $oversized]);
        $reject(static fn() => (new ImapPartReader())->read(null, 1, $limits['receive_message_bytes']), MailReceiveLimitException::class);
        $assert(Fixture::$fetches === 0);
    }
});
$test('MIME part count and depth are bounded before fetching bytes', static function () use ($assert, $reject, $limits, $leaf, $multipart): void {
    $deep = $leaf(1);
    for ($i = 0; $i < $limits['receive_mime_depth']; $i++) { $deep = $multipart([$deep]); }
    foreach ([$deep, $multipart(array_fill(0, $limits['receive_mime_parts'], $leaf(1)))] as $structure) {
        Fixture::reset(); Fixture::$structures[1] = $structure;
        $reject(static fn() => (new ImapPartReader())->read(null, 1, 1000), MailReceiveLimitException::class);
        $assert(Fixture::$fetches === 0);
    }
});
$test('attachment count and aggregate size limits are enforced before fetch', static function () use ($assert, $reject, $limits, $leaf, $multipart): void {
    foreach ([$multipart(array_fill(0, $limits['message_attachments'] + 1, $leaf(1, true))),
        $multipart([$leaf(9 * 1024 * 1024, true), $leaf(9 * 1024 * 1024, true), $leaf(3 * 1024 * 1024, true)]),
        $multipart([$leaf($limits['receive_body_bytes']), $leaf(1, false, 0, 'html')])] as $structure) {
        Fixture::reset(); Fixture::$structures[1] = $structure;
        $reject(static fn() => (new ImapPartReader())->read(null, 1, $limits['receive_message_bytes']), MailReceiveLimitException::class);
        $assert(Fixture::$fetches === 0);
    }
});
$test('normal multipart mail is decoded without losing attachments', static function () use ($assert, $leaf, $multipart): void {
    Fixture::reset();
    Fixture::$structures[1] = $multipart([$leaf(8, false, 3), $leaf(8, false, 4, 'html'), $leaf(4, true, 3)]);
    Fixture::$bodies[1] = ['1'=>base64_encode('hello'),'2'=>'<b>x</b>','3'=>base64_encode('abc')];
    $parts = (new ImapPartReader())->read(null, 1, 1000);
    $assert($parts['text'] === 'hello' && $parts['html'] === '<b>x</b>');
    $assert($parts['attachments'][0]['bytes'] === 'abc' && Fixture::$fetches === 3);
});
$test('base64 attachment at the decoded size limit is accepted', static function () use ($assert, $leaf, $limits): void {
    Fixture::reset();
    $raw = chunk_split(base64_encode(str_repeat('a', $limits['attachment_bytes'])), 76, "\r\n");
    Fixture::$structures[1] = $leaf(strlen($raw), true, 3);
    Fixture::$bodies[1] = ['1'=>$raw];
    $parts = (new ImapPartReader())->read(null, 1, strlen($raw) + 100);
    $assert(strlen($parts['attachments'][0]['bytes']) === $limits['attachment_bytes']);
});
$test('decoded body aggregate is checked after transfer decoding', static function () use ($reject, $limits, $leaf, $multipart): void {
    Fixture::reset();
    $raw = base64_encode(str_repeat('a', intdiv($limits['receive_body_bytes'], 2) + 1));
    Fixture::$structures[1] = $multipart([$leaf(strlen($raw), false, 3), $leaf(strlen($raw), false, 3, 'html')]);
    Fixture::$bodies[1] = ['1'=>$raw,'2'=>$raw];
    $reject(static fn() => (new ImapPartReader())->read(null, 1, 4 * 1024 * 1024), MailReceiveLimitException::class);
});
$test('decoded attachment size cannot bypass the per-file limit', static function () use ($reject, $limits, $leaf): void {
    Fixture::reset();
    $raw = base64_encode(str_repeat('a', $limits['attachment_bytes'] + 1));
    Fixture::$structures[1] = $leaf(strlen($raw), true, 3);
    Fixture::$bodies[1] = ['1'=>$raw];
    $reject(static fn() => (new ImapPartReader())->read(null, 1, strlen($raw) + 100), MailReceiveLimitException::class);
});
$test('decoded attachment aggregate cannot bypass the total limit', static function () use ($reject, $limits, $leaf, $multipart): void {
    Fixture::reset();
    $size = 7 * 1024 * 1024;
    Fixture::$structures[1] = $multipart(array_fill(0, 3, $leaf($size, true, 4)));
    Fixture::$bodies[1] = ['1'=>$size,'2'=>$size,'3'=>$size];
    $reject(static fn() => (new ImapPartReader())->read(null, 1, $limits['receive_message_bytes']), MailReceiveLimitException::class);
});
$test('underreported body sizes cannot bypass the streaming byte limit', static function () use ($assert, $reject, $leaf, $limits): void {
    Fixture::reset(); Fixture::$structures[1] = $leaf(1);
    Fixture::$bodies[1] = ['1'=>20 * 1024 * 1024];
    $reject(static fn() => (new ImapPartReader())->read(null, 1, 100), MailReceiveLimitException::class);
    $assert(Fixture::$written <= $limits['receive_body_bytes'], 'unbounded transfer');
});
$test('UTF-8 conversion cannot bypass the body limit', static function () use ($reject, $leaf, $limits): void {
    Fixture::reset();
    $size = intdiv($limits['receive_body_bytes'], 2) + 1;
    Fixture::$structures[1] = $leaf($size);
    Fixture::$structures[1]->parameters = [(object)['attribute'=>'charset','value'=>'ISO-8859-1']];
    Fixture::$bodies[1] = ['1'=>str_repeat("\xE9", $size)];
    $reject(static fn() => (new ImapPartReader())->read(null, 1, $size + 100), MailReceiveLimitException::class);
});
$test('attachment validation uses content and checks previously saved files', static function () use ($assert, $reject): void {
    Fixture::database();
    $persister = new NexaPressMail\Services\Attachment\AttachmentPersister();
    $validator = new NexaPressMail\Services\Attachment\AttachmentValidator();
    $bytes = "#!/bin/sh\n# Attachment inspection fixture\n";
    $reject(static fn() => $persister->persistBytes(1, ['name'=>'notes.txt','mime'=>'text/plain','bytes'=>$bytes]), InvalidArgumentException::class);
    $reject(static fn() => $validator->inspectBytes('installer.exe. ', 'sample'), InvalidArgumentException::class);
    $assert((new NexaPressMail\Models\AttachmentRepository())->forMessage(1) === []);

    $storage = new NexaPressMail\Services\Attachment\AttachmentStorage();
    $savedPath = $storage->store($bytes);
    try {
        $reject(static fn() => $validator->inspectFile('notes.txt', $storage->path($savedPath)), InvalidArgumentException::class);
        $persister->persistBytes(1, ['name'=>'notes.txt','mime'=>'application/octet-stream','bytes'=>'ordinary text']);
        $saved = (new NexaPressMail\Models\AttachmentRepository())->forMessage(1)[0];
        $assert($saved['mime_type'] === 'text/plain');
    } finally {
        $storage->delete($savedPath);
    }
});
$test('sync skips a rejected message and imports the next one', static function () use ($assert, $leaf, $limits, $account): void {
    Fixture::reset(); $pdo = Fixture::database();
    Fixture::$overview = [1=>(object)['size'=>$limits['receive_message_bytes'] + 1],
        2=>(object)['size'=>100,'from'=>'sender@example.com','to'=>'test@example.com','subject'=>'normal']];
    Fixture::$structures[2] = $leaf(5); Fixture::$bodies[2] = ['1'=>'hello'];
    $method = new ReflectionMethod(MailSyncService::class, 'syncFolder');
    $result = $method->invoke(new MailSyncService(), $account, 'inbox', 'INBOX');
    $assert($result['received'] === 1 && $result['skipped'] === 0 && $result['rejected'] === 1);
    $assert($pdo->query('SELECT body_text FROM nexapress_mail_messages')->fetchColumn() === 'hello');
    $assert($pdo->query('SELECT operation FROM nexapress_mail_logs')->fetchColumn() === 'receive-limit');
});
$test('TLS and STARTTLS settings remain available with certificate verification', static function () use ($assert, $account): void {
    $method = new ReflectionMethod(SmtpClient::class, 'configured');
    foreach (['ssl','tls'] as $mode) {
        $secure = array_replace($account, ['smtp_security'=>$mode,'imap_security'=>$mode]);
        (new AccountValidator())->validate($secure, true);
        $mailbox = ImapConnection::mailbox($secure, 'INBOX');
        $assert(str_contains($mailbox, '/' . $mode . '/validate-cert/norsh'));
        $mail = $method->invoke(new SmtpClient(), $secure);
        $assert($mail->SMTPSecure === ($mode === 'ssl' ? 'ssl' : 'tls'));
        $assert($mail->SMTPOptions['ssl']['verify_peer'] && $mail->SMTPOptions['ssl']['verify_peer_name']);
    }
});
$test('plaintext settings are rejected when saving accounts', static function () use ($reject, $account): void {
    foreach (['smtp','imap'] as $protocol) {
        $unsafe = array_replace($account, [$protocol . '_security'=>'none']);
        $reject(static fn() => (new AccountValidator())->validate($unsafe, true), InvalidArgumentException::class);
    }
});
$test('legacy plaintext accounts are rejected at both connection entry points', static function () use ($reject, $account): void {
    $reject(static fn() => (new SmtpClient())->test(array_replace($account, ['smtp_security'=>'none'])), InvalidArgumentException::class);
    $reject(static fn() => ImapConnection::open(array_replace($account, ['imap_security'=>'none'])), InvalidArgumentException::class);
});
$test('host strings cannot inject TLS overrides or IMAP flags', static function () use ($reject, $account): void {
    foreach (['mail.example.com/novalidate-cert','mail.example.com}INBOX','ssl://mail.example.com','mail.example.com;localhost'] as $host) {
        $reject(static fn() => ImapConnection::mailbox(array_replace($account, ['imap_host'=>$host]), 'INBOX'), InvalidArgumentException::class);
        $reject(static fn() => (new SmtpClient())->test(array_replace($account, ['smtp_host'=>$host])), InvalidArgumentException::class);
    }
});
$test('existing and corrupt encryption keys are never regenerated', static function () use ($assert, $reject): void {
    $directory = sys_get_temp_dir() . '/npm-key-test-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    try {
        $manager = new EncryptionKeyManager($directory);
        $key = $manager->key();
        $assert(strlen($key) === 32 && $manager->key() === $key);
        file_put_contents($directory . '/credential.key', 'corrupt');
        $reject(static fn() => $manager->key(), RuntimeException::class);
        $assert(file_get_contents($directory . '/credential.key') === 'corrupt');
    } finally {
        foreach (['credential.key','credential.lock'] as $file) { if (is_file($directory . '/' . $file)) { unlink($directory . '/' . $file); } }
        rmdir($directory);
    }
});
$test('concurrent key initialization returns the same key to all processes', static function () use ($assert): void {
    $directory = sys_get_temp_dir() . '/npm-key-test-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $lock = fopen($directory . '/credential.lock', 'c+b');
    $workers = [];
    try {
        $assert(flock($lock, LOCK_EX));
        for ($i = 0; $i < 6; $i++) {
            $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/key-worker.php', $directory, (string)$i],
                [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
            $assert(is_resource($process), 'cannot start worker');
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            $workers[] = [$process, $pipes];
        }
        $deadline = microtime(true) + 10;
        do {
            clearstatcache();
            $ready = count(glob($directory . '/ready-*'));
            if ($ready === 6) { break; }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $assert($ready === 6, 'workers did not start');
        // Anonymous pipes cannot be read non-blocking reliably on Windows.
        usleep(100000);
        $assert(!is_file($directory . '/credential.key'), 'key access bypassed lock');
        flock($lock, LOCK_UN);
        $hashes = [];
        foreach ($workers as $index => [$process, $pipes]) {
            stream_set_blocking($pipes[1], true);
            $hashes[] = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $code = proc_close($process); unset($workers[$index]);
            $assert($code === 0, $errors);
        }
        $expected = hash('sha256', file_get_contents($directory . '/credential.key'));
        $assert(count(array_unique($hashes)) === 1 && $hashes[0] === $expected);
    } finally {
        flock($lock, LOCK_UN); fclose($lock);
        foreach ($workers as [$process, $pipes]) {
            proc_terminate($process);
            fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
        }
        foreach (['credential.key','credential.lock','ready-0','ready-1','ready-2','ready-3','ready-4','ready-5'] as $file) {
            if (is_file($directory . '/' . $file)) { unlink($directory . '/' . $file); }
        }
        rmdir($directory);
    }
});
