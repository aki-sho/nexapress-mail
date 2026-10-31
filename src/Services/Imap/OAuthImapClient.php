<?php

namespace NexaPressMail\Services\Imap;

use NexaPressMail\Services\Security\HeaderInjectionGuard;
use NexaPressMail\Services\Security\TransportSecurity;

final class OAuthImapClient
{
    private $socket;
    private int $sequence = 0;
    private array $capabilities = [];

    public function __construct(array $account, private string $folder, string $token)
    {
        $security = TransportSecurity::validate($account, 'imap');
        HeaderInjectionGuard::assertSafe($account['imap_username'], $token, $folder);
        $host = trim($account['imap_host'], '[]');
        $context = stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,
            'allow_self_signed'=>false,'peer_name'=>$host]]);
        $address = ($security === 'ssl' ? 'tls' : 'tcp') . '://' . (str_contains($host, ':') ? '[' . $host . ']' : $host)
            . ':' . (int)$account['imap_port'];
        $this->socket = @stream_socket_client($address, $errno, $error, 20, STREAM_CLIENT_CONNECT, $context);
        if (!$this->socket) { throw new \RuntimeException('OAuth IMAPサーバーへ接続できません。'); }
        stream_set_timeout($this->socket, 30);
        try {
            if (!str_starts_with($this->line(), '* OK')) { throw new \RuntimeException('IMAPサーバーの応答が不正です。'); }
            if ($security === 'tls') {
                $this->command('STARTTLS');
                if (!@stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('IMAPのTLS接続に失敗しました。');
                }
            }
            $response = $this->command('CAPABILITY');
            $this->capabilities = preg_split('/\s+/', strtoupper(implode(' ', $response['lines']))) ?: [];
            if (!in_array('AUTH=XOAUTH2', $this->capabilities, true)) { throw new \RuntimeException('IMAPサーバーがXOAUTH2に対応していません。'); }
            $payload = base64_encode('user=' . $account['imap_username'] . "\1auth=Bearer " . $token . "\1\1");
            $sent = false;
            $this->command('AUTHENTICATE XOAUTH2', function () use ($payload, &$sent): void {
                $this->write(($sent ? '' : $payload) . "\r\n"); $sent = true;
            });
            $this->command('SELECT ' . self::quote($this->folder));
        } catch (\Throwable $error) { $this->close(); throw $error; }
    }

    public function status(): object
    {
        $text = implode(' ', $this->command('STATUS ' . self::quote($this->folder) . ' (UIDVALIDITY UIDNEXT)')['lines']);
        preg_match('/UIDVALIDITY\s+(\d+)/i', $text, $validity); preg_match('/UIDNEXT\s+(\d+)/i', $text, $next);
        if (empty($validity[1]) || empty($next[1])) { throw new \RuntimeException('IMAPのUID状態を取得できません。'); }
        return (object)['uidvalidity'=>(int)$validity[1],'uidnext'=>(int)$next[1]];
    }

    public function search(string $criteria): array
    {
        HeaderInjectionGuard::assertSafe($criteria);
        $text = implode("\n", $this->command('UID SEARCH ' . $criteria)['lines']);
        preg_match('/\* SEARCH([^\r\n]*)/i', $text, $match);
        return empty(trim($match[1] ?? '')) ? [] : array_map('intval', preg_split('/\s+/', trim($match[1])));
    }

    public function flags(array $uids): array
    {
        if (!$uids) { return []; }
        $out = [];
        foreach ($this->fetchData('UID FETCH ' . implode(',', array_map('intval', $uids)) . ' (UID FLAGS)') as $data) {
            if (!isset($data['UID'])) { continue; }
            $flags = array_map('strtolower', $data['FLAGS'] ?? []);
            if (in_array('\\deleted', $flags, true)) { continue; }
            $out[(int)$data['UID']] = ['is_read'=>(int)in_array('\\seen', $flags, true),
                'is_starred'=>(int)in_array('\\flagged', $flags, true)];
        }
        return $out;
    }

    public function overview(int $uid): ?object
    {
        $data = null;
        foreach ($this->fetchData('UID FETCH ' . $uid . ' (UID FLAGS RFC822.SIZE INTERNALDATE BODY.PEEK[HEADER])', 256 * 1024) as $row) {
            if ((int)($row['UID'] ?? 0) === $uid) { $data = $row; break; }
        }
        if (!$data) { return null; }
        $header = (string)($data['BODY[HEADER]'] ?? '');
        $parsed = imap_rfc822_parse_headers($header);
        $flags = array_map('strtolower', $data['FLAGS'] ?? []);
        return (object)['uid'=>$uid,'size'=>(int)($data['RFC822.SIZE'] ?? 0),
            'from'=>$parsed->fromaddress ?? '', 'to'=>$parsed->toaddress ?? '', 'cc'=>$parsed->ccaddress ?? '',
            'subject'=>$parsed->subject ?? '', 'message_id'=>$parsed->message_id ?? '', 'in_reply_to'=>$parsed->in_reply_to ?? '',
            'udate'=>strtotime($data['INTERNALDATE'] ?? '') ?: time(),
            'seen'=>in_array('\\seen', $flags, true),'flagged'=>in_array('\\flagged', $flags, true)];
    }

    public function structure(int $uid): object
    {
        $data = null;
        foreach ($this->fetchData('UID FETCH ' . $uid . ' (UID BODYSTRUCTURE)') as $row) {
            if ((int)($row['UID'] ?? 0) === $uid) { $data = $row['BODYSTRUCTURE'] ?? null; break; }
        }
        if (!is_array($data)) { throw new \RuntimeException('MIME構造を取得できません。'); }
        return (new ImapResponseParser())->structure($data);
    }

    public function saveBody($target, int $uid, string $section, int $limit): bool
    {
        if (!preg_match('/^[0-9]+(?:\.[0-9]+)*$/', $section)) { throw new \InvalidArgumentException('MIMEパート番号が不正です。'); }
        $response = $this->command('UID FETCH ' . $uid . ' (BODY.PEEK[' . $section . '])', null, $target, $limit);
        $valid = false;
        foreach ($this->parseFetch($response) as $row) {
            if ((int)($row['UID'] ?? 0) === $uid && isset($row['BODY[' . $section . ']'])) { $valid = true; }
        }
        if ($response['literal_count'] !== 1 || !$valid) { throw new \RuntimeException('メール本文を取得できません。'); }
        return true;
    }

    public function flag(int $uid, string $flag, bool $add): void
    {
        if (!in_array($flag, ['\\Seen','\\Flagged','\\Deleted'], true)) { throw new \InvalidArgumentException('IMAPフラグが不正です。'); }
        $this->command('UID STORE ' . $uid . ($add ? ' +FLAGS.SILENT (' : ' -FLAGS.SILENT (') . $flag . ')');
    }

    public function move(int $uid, string $folder): ?int
    {
        $response = $this->command('UID COPY ' . $uid . ' ' . self::quote($folder));
        $this->flag($uid, '\\Deleted', true); $this->expunge($uid);
        preg_match('/\[COPYUID\s+\d+\s+\d+\s+(\d+)\]/i', $response['completion'], $match);
        return isset($match[1]) ? (int)$match[1] : null;
    }

    public function expunge(int $uid): void
    { if (in_array('UIDPLUS', $this->capabilities, true)) { $this->command('UID EXPUNGE ' . $uid); } }

    public function append(string $folder, string $mime): void
    {
        $this->command('APPEND ' . self::quote($folder) . ' (\\Seen) {' . strlen($mime) . '}',
            function () use ($mime): void { $this->write($mime . "\r\n"); });
    }

    public function close(): void { if (is_resource($this->socket)) { fclose($this->socket); } $this->socket = null; }
    public function connected(): bool { return is_resource($this->socket) && !feof($this->socket); }
    public function __destruct() { $this->close(); }

    private function fetchData(string $command, int $limit = 262144): array
    {
        $response = $this->command($command, null, null, $limit);
        return $this->parseFetch($response);
    }

    private function parseFetch(array $response): array
    {
        $text = implode('', $response['lines']); $out = [];
        foreach (preg_split('/(?:^|\r?\n)\*\s+\d+\s+FETCH\s+/i', $text) as $chunk) {
            $chunk = trim($chunk);
            if (!str_starts_with($chunk, '(')) { continue; }
            $values = (new ImapResponseParser())->parse($chunk, $response['literals'])[0] ?? [];
            $row = [];
            for ($i = 0; $i + 1 < count($values); $i += 2) { $row[strtoupper((string)$values[$i])] = $values[$i + 1]; }
            $out[] = $row;
        }
        return $out;
    }

    private function command(string $command, ?callable $continuation = null, $target = null, int $literalLimit = 262144): array
    {
        HeaderInjectionGuard::assertSafe($command);
        $tag = 'N' . ++$this->sequence; $this->write($tag . ' ' . $command . "\r\n");
        $result = ['lines'=>[],'literals'=>[],'literal_count'=>0,'completion'=>''];
        $responseBytes = 0; $literalBytes = 0; $continued = 0;
        while (true) {
            $line = $this->line(); $responseBytes += strlen($line);
            if ($responseBytes > 1024 * 1024) { $this->close(); throw new MailReceiveLimitException('IMAP応答が大きすぎます。'); }
            if (str_starts_with($line, $tag . ' ')) {
                if (!preg_match('/^' . $tag . ' OK\b/i', $line)) { throw new \RuntimeException('IMAP操作に失敗しました。接続設定と権限を確認してください。'); }
                $result['completion'] = $line; return $result;
            }
            if (str_starts_with($line, '+')) {
                if (!$continuation || ++$continued > 2) { $this->close(); throw new \RuntimeException('IMAP認証の応答が不正です。'); }
                $continuation(); continue;
            }
            if (str_starts_with(strtoupper($line), '* BYE')) { $this->close(); throw new \RuntimeException('IMAP接続が終了しました。'); }
            if (preg_match('/\{(\d+)\+?\}\r?\n$/', $line, $match)) {
                $size = (int)$match[1]; $literalBytes += $size; $result['literal_count']++;
                if ($size < 0 || $literalBytes > $literalLimit || ($target && $result['literal_count'] > 1)) {
                    $this->close(); throw new MailReceiveLimitException('IMAPデータが受信上限を超えています。');
                }
                $placeholder = '__npm_literal_' . $result['literal_count'] . '__';
                $line = substr($line, 0, -strlen($match[0])) . $placeholder . ' ';
                $value = '';
                while ($size > 0) {
                    $chunk = fread($this->socket, min(8192, $size));
                    if ($chunk === false || $chunk === '') { $this->close(); throw new \RuntimeException('IMAPデータの取得が中断されました。'); }
                    if ($target) {
                        if (fwrite($target, $chunk) !== strlen($chunk)) {
                            $this->close(); throw new MailReceiveLimitException('受信データが保存上限を超えています。');
                        }
                    } else { $value .= $chunk; }
                    $size -= strlen($chunk);
                }
                if (!$target) { $result['literals'][$placeholder] = $value; }
            }
            $result['lines'][] = $line;
        }
    }

    private function line(): string
    {
        if (!$this->connected()) { throw new \RuntimeException('IMAP接続が切断されています。'); }
        $line = fgets($this->socket, 65537);
        if (is_string($line) && strlen($line) >= 65536 && !str_ends_with($line, "\n")) {
            $this->close(); throw new MailReceiveLimitException('IMAP応答の1行が受信上限を超えています。');
        }
        if ($line === false || !str_ends_with($line, "\n")) {
            $this->close(); throw new \RuntimeException('IMAP応答が不正、またはタイムアウトしました。');
        }
        return $line;
    }

    private function write(string $data): void
    {
        $offset = 0;
        while ($offset < strlen($data)) {
            if (!$this->connected()) { throw new \RuntimeException('IMAP接続が切断されています。'); }
            $written = fwrite($this->socket, substr($data, $offset));
            if (!$written) { $this->close(); throw new \RuntimeException('IMAPコマンドを送信できません。'); }
            $offset += $written;
        }
    }

    private static function quote(string $value): string
    {
        HeaderInjectionGuard::assertSafe($value);
        $value = mb_convert_encoding($value, 'UTF7-IMAP', 'UTF-8');
        return '"' . addcslashes($value, '\\"') . '"';
    }
}
