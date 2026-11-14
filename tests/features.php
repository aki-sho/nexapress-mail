<?php

use NexaPressMail\Database\FeatureMigration;
use NexaPressMail\Models\AccountRepository;
use NexaPressMail\Models\OAuthRepository;
use NexaPressMail\Models\MessageRepository;
use NexaPressMail\Models\SyncJobRepository;
use NexaPressMail\Models\SyncStateRepository;
use NexaPressMail\Services\OAuth\OAuthService;
use NexaPressMail\Services\OAuth\OAuthProvider;
use NexaPressMail\Services\OAuth\TokenHttpClient;
use NexaPressMail\Services\OAuth\SmtpTokenProvider;
use NexaPressMail\Services\Imap\ImapResponseParser;
use NexaPressMail\Services\Imap\ImapSocketClient;
use NexaPressMail\Services\Imap\MailReceiveLimitException;
use NexaPressMail\Services\Imap\ImapFlagService;
use NexaPressMail\Services\Receive\MailSyncService;
use NexaPressMail\Services\Receive\SyncOptions;
use NexaPressMail\Services\Security\PrivateLock;
use NexaPressMail\Services\Security\LockBusyException;
use NexaPressMail\Services\Send\DraftService;
use NexaPressMail\Services\Send\DraftConflictException;
use NexaPressMail\Services\Send\OutgoingMessageFactory;
use NexaPressMail\Tests\ImapFixture as Fixture;

final class FakeTokenHttpClient extends TokenHttpClient
{
    public array $calls = [];
    public array $response = ['access_token'=>'access-test','refresh_token'=>'refresh-test','expires_in'=>3600,'token_type'=>'Bearer'];
    public function post(string $url, array $fields): array { $this->calls[] = [$url,$fields]; return $this->response; }
}

$featureAccount = static function (array $overrides = []) use ($account): int {
    return (new AccountRepository())->save(array_replace($account, ['id'=>0,'is_active'=>1], $overrides));
};
$oauthConfig = ['oauth_provider'=>'google','oauth_client_id'=>'client-test','oauth_client_secret'=>'secret-test',
    'oauth_tenant'=>'common','oauth_redirect_uri'=>'https://example.com/admin/extensions/nexapress-mail/oauth/callback'];
$draftInput = static fn(int $id): array => ['account_id'=>$id,'draft_id'=>0,'client_key'=>bin2hex(random_bytes(16)),
    'request_id'=>bin2hex(random_bytes(16)),'revision'=>0,'to'=>'person@','cc'=>'','bcc'=>'','subject'=>'draft','body'=>'input in progress','format'=>'text'];
$syncFolder = static function (array $account, array $options = [], string $folder = 'inbox', string $remote = 'INBOX'): array {
    return (new ReflectionMethod(MailSyncService::class, 'syncFolder'))->invoke(new MailSyncService(), $account, $folder, $remote, $options);
};
$mailbox = static function (int $count, int $start = 1) use ($leaf): array {
    $box = ['overview'=>[],'structures'=>[],'bodies'=>[]];
    for ($uid = $start; $uid < $start + $count; $uid++) {
        $text = 'mail-' . $uid;
        $box['overview'][$uid] = (object)['uid'=>$uid,'size'=>200,'from'=>'sender@example.com','to'=>'test@example.com',
            'subject'=>'message-' . $uid,'message_id'=>'message-' . $uid . '@example.com',
            'udate'=>strtotime('2025-01-01 UTC') + ($uid - 1) * 86400,'seen'=>0,'flagged'=>0];
        $box['structures'][$uid] = $leaf(strlen($text)); $box['bodies'][$uid] = ['1'=>$text];
    }
    return $box;
};
$socketPair = static function (): array {
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$listener) { throw new RuntimeException('test socket unavailable'); }
    try {
        $client = stream_socket_client('tcp://' . stream_socket_get_name($listener, false));
        $server = stream_socket_accept($listener, 2);
        if (!$client || !$server) { throw new RuntimeException('test socket unavailable'); }
        return [$client,$server];
    } finally { fclose($listener); }
};

$test('feature schema upgrades existing tables and is repeatable', static function () use ($assert): void {
    $pdo = Fixture::database(); FeatureMigration::run($pdo);
    $assert(in_array('auth_type', array_column($pdo->query('PRAGMA table_info(nexapress_mail_accounts)')->fetchAll(), 'name'), true));
    $assert(in_array('remote_missing', array_column($pdo->query('PRAGMA table_info(nexapress_mail_messages)')->fetchAll(), 'name'), true));
    $assert((int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name LIKE 'nexapress_mail_%'")->fetchColumn() >= 9);
});
$test('OAuth account configuration and tokens are encrypted at rest', static function () use ($assert, $featureAccount, $oauthConfig): void {
    $pdo = Fixture::database(); $id = $featureAccount(['auth_type'=>'oauth2','smtp_password'=>'','imap_password'=>'']);
    $repo = new OAuthRepository(); $repo->save($id, $oauthConfig);
    $repo->tokens($id, ['access_token'=>'a-test','refresh_token'=>'r-test','expires_in'=>3600]);
    $raw = $pdo->query('SELECT * FROM nexapress_mail_oauth')->fetch();
    foreach (['client_secret','access_token','refresh_token'] as $field) { $assert(str_starts_with($raw[$field], 'npm1:')); }
    $assert($repo->find($id)['refresh_token'] === 'r-test');
    $assert((new AccountRepository())->find($id)['smtp_password'] === '');
    $assert((new AccountRepository())->find($id)['auth_type'] === 'oauth2');
});
$test('authorization uses PKCE and consumes session-bound state once', static function () use ($assert, $reject, $featureAccount, $oauthConfig): void {
    Fixture::database(); $id = $featureAccount(['auth_type'=>'oauth2']); $repo = new OAuthRepository(); $repo->save($id, $oauthConfig);
    $http = new FakeTokenHttpClient(); $service = new OAuthService($repo, $http);
    $url = $service->begin($id, 1); parse_str(parse_url($url, PHP_URL_QUERY), $query);
    $verifier = $_SESSION['npm_oauth']['verifier'];
    $assert($query['code_challenge_method'] === 'S256' && $query['access_type'] === 'offline');
    $assert($query['code_challenge'] === OAuthProvider::base64url(hash('sha256', $verifier, true)));
    $assert($service->complete($query['state'], 'code-test', 1) === $id);
    $assert($http->calls[0][1]['code_verifier'] === $verifier && !isset($_SESSION['npm_oauth']));
    $reject(static fn() => $service->complete($query['state'], 'code-test', 1), RuntimeException::class);
    $service->begin($id, 1); $state = $_SESSION['npm_oauth']['state'];
    $reject(static fn() => $service->complete($state, 'code-test', 2), RuntimeException::class);
    $assert(count($http->calls) === 1);
});
$test('changed OAuth configuration invalidates pending authorization and cached tokens', static function () use ($assert, $reject, $featureAccount, $oauthConfig): void {
    Fixture::database(); $id = $featureAccount(); $repo = new OAuthRepository(); $repo->save($id, $oauthConfig);
    $repo->tokens($id, ['access_token'=>'old','refresh_token'=>'old-refresh','expires_in'=>3600]);
    $service = new OAuthService($repo, new FakeTokenHttpClient()); $service->begin($id, 1); $state = $_SESSION['npm_oauth']['state'];
    $repo->save($id, array_replace($oauthConfig, ['oauth_client_id'=>'changed']));
    $assert($repo->find($id)['refresh_token'] === '');
    $reject(static fn() => $service->complete($state, 'code', 1), RuntimeException::class);
});
$test('expired access tokens refresh once and retain rotated refresh tokens', static function () use ($assert, $reject, $featureAccount, $oauthConfig): void {
    $pdo = Fixture::database(); $id = $featureAccount(); $repo = new OAuthRepository(); $repo->save($id, $oauthConfig);
    $repo->tokens($id, ['access_token'=>'old','refresh_token'=>'old-refresh','expires_in'=>1]);
    $pdo->exec('UPDATE nexapress_mail_oauth SET expires_at=0');
    $http = new FakeTokenHttpClient(); $service = new OAuthService($repo, $http);
    $assert($service->accessToken($id) === 'access-test'); $assert($service->accessToken($id) === 'access-test');
    $assert(count($http->calls) === 1 && $http->calls[0][1]['grant_type'] === 'refresh_token');
    $assert($repo->find($id)['refresh_token'] === 'refresh-test');
    $reject(static fn() => $service->accessToken($id, ['imap_host'=>'attacker.example'], 'imap'), RuntimeException::class);
    $provider = new SmtpTokenProvider(['id'=>$id,'smtp_host'=>'smtp.gmail.com','smtp_username'=>'test@example.com']);
    $assert(base64_decode($provider->getOauth64()) === "user=test@example.com\1auth=Bearer access-test\1\1");
});
$test('OAuth configuration rejects insecure callbacks and untrusted tenants', static function () use ($reject, $featureAccount, $oauthConfig): void {
    Fixture::database(); $id = $featureAccount(); $repo = new OAuthRepository();
    foreach (['http://example.com/admin/extensions/nexapress-mail/oauth/callback',
        'https://example.com/other','https://example.com/admin/extensions/nexapress-mail/oauth/callback?x=1'] as $url) {
        $reject(static fn() => $repo->save($id, array_replace($oauthConfig, ['oauth_redirect_uri'=>$url])), InvalidArgumentException::class);
    }
    $reject(static fn() => OAuthProvider::settings('microsoft', 'evil.example/redirect'), InvalidArgumentException::class);
});
$test('IMAP BODYSTRUCTURE parser preserves multipart and attachment metadata', static function () use ($assert, $reject): void {
    $wire = '(("TEXT" "PLAIN" ("CHARSET" "UTF-8") NIL NIL "7BIT" 5 1 NIL NIL NIL)'
        . '("APPLICATION" "OCTET-STREAM" ("NAME" "a.txt") NIL NIL "BASE64" 4 NIL ("ATTACHMENT" ("FILENAME" "a.txt")) NIL) "MIXED")';
    $parser = new ImapResponseParser(); $structure = $parser->structure($parser->parse($wire)[0]);
    $assert(count($structure->parts) === 2 && $structure->parts[0]->parameters[0]->value === 'UTF-8');
    $assert($structure->parts[1]->encoding === 3 && $structure->parts[1]->dparameters[0]->value === 'a.txt');
    $reject(static fn() => $parser->parse(str_repeat('(', 70) . str_repeat(')', 70)), MailReceiveLimitException::class);
    $reject(static fn() => $parser->parse('(incomplete'), RuntimeException::class);
});
$test('OAuth IMAP protocol reads headers, flags and body without setting Seen', static function () use ($assert, $socketPair): void {
    $pair = $socketPair();
    $assert($pair !== false, 'socket pair unavailable');
    $reflection = new ReflectionClass(ImapSocketClient::class); $client = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('socket')->setValue($client, $pair[0]); $reflection->getProperty('folder')->setValue($client, 'INBOX');
    $header = "From: sender@example.com\r\nTo: test@example.com\r\nSubject: hello\r\nMessage-ID: <a@example.com>\r\n\r\n";
    fwrite($pair[1], "* STATUS INBOX (UIDVALIDITY 55 UIDNEXT 8)\r\nN1 OK done\r\n"
        . "* 99 FETCH (UID 6 FLAGS (\\Seen))\r\n"
        . '* 1 FETCH (UID 7 FLAGS (\\Seen \\Flagged) RFC822.SIZE 200 INTERNALDATE "01-Jan-2026 00:00:00 +0000" BODY[HEADER] {' . strlen($header) . "}\r\n"
        . $header . ")\r\nN2 OK done\r\n"
        . "* 1 FETCH (BODYSTRUCTURE (\"TEXT\" \"PLAIN\" (\"CHARSET\" \"UTF-8\") NIL NIL \"7BIT\" 5 1) UID 7)\r\nN3 OK done\r\n"
        . "* 1 FETCH (UID 7 BODY[1] {5}\r\nhello)\r\nN4 OK done\r\n"
        . "* 1 FETCH (FLAGS (\\Seen) UID 7)\r\nN5 OK done\r\n");
    $file = tmpfile();
    try {
        $assert($client->status()->uidvalidity === 55);
        $overview = $client->overview(7); $assert($overview->subject === 'hello' && $overview->seen && $overview->flagged);
        $assert(str_contains($overview->from, 'sender@example.com'));
        $assert($client->structure(7)->bytes === '5');
        $assert($client->saveBody($file, 7, '1', 5)); rewind($file); $assert(stream_get_contents($file) === 'hello');
        $assert($client->flags([7]) === [7=>['is_read'=>1,'is_starred'=>0]]);
        stream_set_blocking($pair[1], false); $requests = stream_get_contents($pair[1]);
        $assert(str_contains($requests, 'BODY.PEEK[HEADER]') && str_contains($requests, 'BODY.PEEK[1]'));
    } finally { fclose($file); $client->close(); fclose($pair[1]); }
});
$test('OAuth IMAP rejects oversized literals before reading them', static function () use ($assert, $reject, $socketPair): void {
    $pair = $socketPair();
    $reflection = new ReflectionClass(ImapSocketClient::class); $client = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('socket')->setValue($client, $pair[0]);
    fwrite($pair[1], "* 1 FETCH (BODY[1] {1000000000}\r\n"); $file = tmpfile();
    try {
        $reject(static fn() => $client->saveBody($file, 1, '1', 100), MailReceiveLimitException::class);
        $assert(!$client->connected() && fstat($file)['size'] === 0);
    } finally { fclose($file); $client->close(); fclose($pair[1]); }
});
$test('history pages reach older mail while delta synchronization catches new UIDs', static function () use ($assert, $syncFolder, $mailbox, $account): void {
    Fixture::reset(); $pdo = Fixture::database(); Fixture::$mailboxes['INBOX'] = $mailbox(230);
    $assert($syncFolder($account)['received'] === 100);
    $assert($syncFolder($account)['received'] === 0);
    $assert($syncFolder($account, ['mode'=>'history'])['received'] === 100);
    $assert($syncFolder($account, ['mode'=>'history'])['received'] === 30);
    $assert($syncFolder($account, ['mode'=>'history'])['received'] === 0);
    $new = $mailbox(2, 231);
    foreach (['overview','structures','bodies'] as $key) { Fixture::$mailboxes['INBOX'][$key] += $new[$key]; }
    $assert($syncFolder($account)['received'] === 2);
    $assert((int)$pdo->query('SELECT COUNT(*) FROM nexapress_mail_messages WHERE remote_missing=0')->fetchColumn() === 232);
    $assert(str_contains(end(Fixture::$searches), 'UID 231:232'));
});
$test('date-range history uses inclusive end dates and resumes its own cursor', static function () use ($assert, $reject, $syncFolder, $mailbox, $account): void {
    Fixture::reset(); $pdo = Fixture::database(); Fixture::$mailboxes['INBOX'] = $mailbox(90);
    $options = ['mode'=>'history','from'=>'2025-02-01','to'=>'2025-02-05'];
    $assert($syncFolder($account, $options)['received'] === 5 && $syncFolder($account, $options)['received'] === 0);
    $assert((int)$pdo->query('SELECT COUNT(*) FROM nexapress_mail_messages')->fetchColumn() === 5);
    $assert(str_contains(Fixture::$searches[0], 'BEFORE "06-Feb-2025"'));
    $reject(static fn() => SyncOptions::fromInput(['from'=>'2025-02-30']), InvalidArgumentException::class);
    $reject(static fn() => SyncOptions::fromInput(['from'=>'2026-01-01','to'=>'2025-01-01']), InvalidArgumentException::class);
});
$test('remote read flags, removals and moves reconcile with cached messages', static function () use ($assert, $syncFolder, $mailbox, $account): void {
    Fixture::reset(); $pdo = Fixture::database(); Fixture::$mailboxes['INBOX'] = $mailbox(2); $syncFolder($account);
    Fixture::$mailboxes['INBOX']['overview'][1]->seen = 1; Fixture::$mailboxes['INBOX']['overview'][1]->flagged = 1;
    unset(Fixture::$mailboxes['INBOX']['overview'][2]); $syncFolder($account);
    $row = $pdo->query('SELECT * FROM nexapress_mail_messages WHERE remote_uid=1')->fetch();
    $assert((int)$row['is_read'] === 1 && (int)$row['is_starred'] === 1);
    $assert((int)$pdo->query('SELECT remote_missing FROM nexapress_mail_messages WHERE remote_uid=2')->fetchColumn() === 1);
    Fixture::$mailboxes['Trash'] = ['overview'=>[9=>Fixture::$mailboxes['INBOX']['overview'][1]],
        'structures'=>[9=>Fixture::$mailboxes['INBOX']['structures'][1]],'bodies'=>[9=>Fixture::$mailboxes['INBOX']['bodies'][1]]];
    Fixture::$mailboxes['INBOX']['overview'] = []; $syncFolder($account);
    $assert($syncFolder($account, [], 'trash', 'Trash')['received'] === 1);
    $moved = $pdo->query('SELECT * FROM nexapress_mail_messages WHERE remote_missing=0')->fetchAll();
    $assert(count($moved) === 1 && (int)$moved[0]['id'] === (int)$row['id'] && $moved[0]['folder'] === 'trash');
});
$test('UIDVALIDITY changes cannot bind old content to reused remote UIDs', static function () use ($assert, $syncFolder, $mailbox, $account): void {
    Fixture::reset(); $pdo = Fixture::database(); Fixture::$mailboxes['INBOX'] = $mailbox(1, 5); $syncFolder($account);
    Fixture::$validities['INBOX'] = 2;
    Fixture::$mailboxes['INBOX']['overview'][5]->message_id = 'new@example.com';
    Fixture::$mailboxes['INBOX']['overview'][5]->subject = 'new content'; $syncFolder($account);
    $rows = $pdo->query('SELECT remote_uid,remote_missing,subject FROM nexapress_mail_messages ORDER BY id')->fetchAll();
    $assert(count($rows) === 2 && $rows[0]['remote_uid'] === null && (int)$rows[0]['remote_missing'] === 1);
    $assert((int)$rows[1]['remote_uid'] === 5 && $rows[1]['subject'] === 'new content');
});
$test('first delta migration keeps older cached mail visible until it is rebound', static function () use ($assert, $syncFolder, $mailbox, $account): void {
    Fixture::reset(); $pdo = Fixture::database(); Fixture::$mailboxes['INBOX'] = $mailbox(230);
    $id = (new MessageRepository())->save(['account_id'=>1,'folder'=>'inbox','remote_uid'=>1,'body_text'=>'cached history',
        'message_id'=>'message-1@example.com','sender_email'=>'sender@example.com','subject'=>'message-1']);
    $syncFolder($account);
    $cached = (new MessageRepository())->find($id);
    $assert($cached['remote_uid'] === null && (int)$cached['remote_missing'] === 0 && $cached['body_text'] === 'cached history');
    $assert((int)$pdo->query('SELECT COUNT(*) FROM nexapress_mail_messages WHERE remote_missing=0')->fetchColumn() === 101);
});
$test('message actions reject changed UIDVALIDITY or servers before touching flags', static function () use ($assert, $reject, $syncFolder, $mailbox, $account): void {
    Fixture::reset(); $pdo = Fixture::database(); Fixture::$mailboxes['INBOX'] = $mailbox(1); $syncFolder($account);
    $message = $pdo->query('SELECT * FROM nexapress_mail_messages')->fetch();
    Fixture::$validities['INBOX'] = 2;
    $reject(static fn() => (new ImapFlagService())->apply($account, $message, 'read'), RuntimeException::class);
    $assert(Fixture::$flagCalls === 0);
    Fixture::$validities['INBOX'] = 1;
    $reject(static fn() => (new ImapFlagService())->apply(array_replace($account, ['imap_host'=>'other.example.com']), $message, 'read'), RuntimeException::class);
    $assert(Fixture::$flagCalls === 0);
    (new ImapFlagService())->apply($account, $message, 'read'); $assert(Fixture::$flagCalls === 1);
});
$test('permanent IMAP deletion is scoped to the requested UID', static function () use ($assert, $reject, $socketPair): void {
    [$socket, $peer] = $socketPair();
    $class = new ReflectionClass(ImapSocketClient::class);
    $client = $class->newInstanceWithoutConstructor();
    $class->getProperty('socket')->setValue($client, $socket);
    try {
        $reject(static fn() => $client->delete(31), RuntimeException::class);
        stream_set_blocking($peer, false);
        $assert(stream_get_contents($peer) === '', 'Unsupported servers must not receive a deletion command');

        $class->getProperty('capabilities')->setValue($client, ['IMAP4REV1', 'UIDPLUS']);
        fwrite($peer, "N1 OK flag saved\r\nN2 OK deletion complete\r\n");
        NexaPressMail\Services\Imap\ImapConnection::delete($client, 31);
        $assert(stream_get_contents($peer) === "N1 UID STORE 31 +FLAGS.SILENT (\\Deleted)\r\nN2 UID EXPUNGE 31\r\n");
    } finally {
        $client->close();
        fclose($peer);
    }
});
$test('native IMAP move never expunges unrelated deleted messages', static function () use ($assert): void {
    $assert(NexaPressMail\Services\Imap\ImapConnection::move('fixture-stream', 31, 'Trash') === null);
});
$test('mail with unsupported charset and oversized headers leaves later mail available', static function () use ($assert, $syncFolder, $mailbox, $account): void {
    Fixture::reset();
    $pdo = Fixture::database();
    $folder = $mailbox(2);
    $folder['overview'][1]->message_id = str_repeat('a', 256);
    $folder['overview'][1]->subject = str_repeat('s', 1000);
    $folder['structures'][1]->parameters = [(object)['attribute'=>'charset', 'value'=>'unknown-charset']];
    $folder['bodies'][1]['1'] = "plain\xff";
    Fixture::$mailboxes['INBOX'] = $folder;

    $assert($syncFolder($account)['received'] === 2);
    $row = $pdo->query('SELECT * FROM nexapress_mail_messages WHERE remote_uid=1')->fetch();
    $assert($row['message_id'] === null && mb_strlen($row['subject'], 'UTF-8') === 998);
    $assert(mb_check_encoding($row['body_text'], 'UTF-8') && str_starts_with($row['body_text'], 'plain'));
});
$test('sent-mail reconciliation retains locally stored BCC recipients', static function () use ($assert): void {
    Fixture::database(); $repo = new MessageRepository();
    $record = ['account_id'=>1,'folder'=>'sent','message_id'=>'sent@example.com','sender_email'=>'test@example.com',
        'subject'=>'sent','recipients_json'=>json_encode(['to'=>['person@example.com'],'cc'=>[],'bcc'=>['hidden@example.com']])];
    $id = $repo->save($record);
    $record['remote_uid'] = 7; $record['recipients_json'] = json_encode(['to'=>['person@example.com'],'cc'=>[],'bcc'=>[]]);
    $result = $repo->importRecord($record);
    $assert($result['id'] === $id && $result['reused']);
    $assert(json_decode($repo->find($id)['recipients_json'], true)['bcc'] === ['hidden@example.com']);
});
$test('IMAP FETCH parsing ignores command-like text inside attachment names', static function () use ($assert): void {
    $reflection = new ReflectionClass(ImapSocketClient::class); $client = $reflection->newInstanceWithoutConstructor();
    $response = ['lines'=>["* 1 FETCH (UID 7 BODYSTRUCTURE (\"APPLICATION\" \"OCTET-STREAM\" (\"NAME\" \"a * 99 FETCH test.txt\") NIL NIL \"BASE64\" 4))\r\n"],'literals'=>[]];
    $rows = $reflection->getMethod('parseFetch')->invoke($client, $response);
    $assert(count($rows) === 1 && $rows[0]['UID'] === '7');
    $assert($rows[0]['BODYSTRUCTURE'][2][1] === 'a * 99 FETCH test.txt');
});
$test('scheduler retries back off and success resets the retry state', static function () use ($assert): void {
    Fixture::database(); $jobs = new SyncJobRepository();
    for ($i = 1; $i <= 3; $i++) { $jobs->start(1); $jobs->finish(1, 300, [], 'temporary failure'); }
    $job = $jobs->find(1);
    $assert((int)$job['failures'] === 3 && $job['status'] === 'failed' && (int)$job['next_run_at'] >= time() + 239);
    $jobs->start(1); $jobs->finish(1, 300, ['received'=>2], null); $job = $jobs->find(1);
    $assert((int)$job['failures'] === 0 && $job['status'] === 'complete' && $job['last_error'] === null);
});
$test('manual and scheduled sync share a lock and respect due times', static function () use ($assert, $featureAccount): void {
    Fixture::reset(); Fixture::database(); $id = $featureAccount(['auto_sync_enabled'=>1]);
    $lock = new PrivateLock('sync-' . $id, false);
    try { $assert((new MailSyncService())->sync($id)['busy'] === 1); } finally { $lock->release(); }
    $result = (new MailSyncService())->sync($id, [], true); $assert(!$result['errors']);
    $before = count(Fixture::$searches);
    (new MailSyncService())->sync($id, [], true); $assert(count(Fixture::$searches) === $before);
});
$test('autosave accepts incomplete recipients and retries without duplicate drafts', static function () use ($assert, $reject, $featureAccount, $draftInput): void {
    $pdo = Fixture::database(); $id = $featureAccount(); $input = $draftInput($id); $service = new DraftService();
    $first = $service->save(1, $input); $retry = $service->save(1, $input);
    $assert($first === $retry && $first['revision'] === 1);
    $assert((int)$pdo->query('SELECT COUNT(*) FROM nexapress_mail_messages')->fetchColumn() === 1);
    $assert((new MessageRepository())->find($first['id'])['body_text'] === $input['body']);
    $assert($service->lookupClient(1, $input['client_key'])['id'] === $first['id']);
    $assert($service->lookupClient(2, $input['client_key']) === null);
    $reject(static fn() => (new OutgoingMessageFactory())->fromRequest($input), InvalidArgumentException::class);
});
$test('autosave conflicts preserve the newer draft and reject non-draft IDs', static function () use ($assert, $reject, $featureAccount, $draftInput): void {
    Fixture::database(); $id = $featureAccount(); $service = new DraftService(); $input = $draftInput($id); $saved = $service->save(1, $input);
    $update = array_replace($input, ['draft_id'=>$saved['id'],'revision'=>1,'request_id'=>bin2hex(random_bytes(16)),'body'=>'new version']);
    $assert($service->save(1, $update)['revision'] === 2);
    $reject(static fn() => $service->save(1, array_replace($update, ['request_id'=>bin2hex(random_bytes(16)),'body'=>'stale'])), DraftConflictException::class);
    $assert((new MessageRepository())->find($saved['id'])['body_text'] === 'new version');
    $inbox = (new MessageRepository())->save(['account_id'=>$id,'folder'=>'inbox','body_text'=>'preserve']);
    $reject(static fn() => $service->save(1, array_replace($input, ['draft_id'=>$inbox])), DraftConflictException::class);
    $assert((new MessageRepository())->find($inbox)['body_text'] === 'preserve');
});
$test('autosave retains and removes only the selected drafts attachments', static function () use ($assert, $featureAccount, $draftInput): void {
    Fixture::database(); $id = $featureAccount(); $input = $draftInput($id); $service = new DraftService();
    $path = tempnam(BASE_PATH, 'upload-'); file_put_contents($path, 'attachment');
    try {
        $first = $service->save(1, $input, [['name'=>'test.txt','mime'=>'text/plain','size'=>10,'path'=>$path]]);
        $update = array_replace($input, ['draft_id'=>$first['id'],'revision'=>1,'request_id'=>bin2hex(random_bytes(16)),'body'=>'edited']);
        $second = $service->save(1, $update); $assert(count($second['attachments']) === 1);
        $files = $service->files($second['id']); $assert(file_get_contents($files[0]['path']) === 'attachment');
        $third = $service->save(1, array_replace($update, ['revision'=>2,'request_id'=>bin2hex(random_bytes(16)),
            'remove_attachments'=>[$second['attachments'][0]['id']]]));
        $assert(!$third['attachments'] && !is_file($files[0]['path']));
    } finally { unlink($path); }
});
$test('autosaved HTML is sanitized and compose byte limits are enforced', static function () use ($assert, $reject, $featureAccount, $draftInput, $limits): void {
    Fixture::database(); $id = $featureAccount(); $input = array_replace($draftInput($id), ['format'=>'html','body'=>'<section><script>x()</script><p onclick="x()">safe</p></section>']);
    $saved = (new DraftService())->save(1, $input);
    $assert((new MessageRepository())->find($saved['id'])['body_html'] === '<p>safe</p>');
    $reject(static fn() => (new DraftService())->save(1, array_replace($input, ['body'=>str_repeat('a', $limits['receive_body_bytes'] + 1)])), InvalidArgumentException::class);
});
