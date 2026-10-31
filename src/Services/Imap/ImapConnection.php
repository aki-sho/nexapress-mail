<?php

namespace NexaPressMail\Services\Imap;

use RuntimeException;
use NexaPressMail\Services\Security\TransportSecurity;
use NexaPressMail\Services\OAuth\OAuthService;

final class ImapConnection
{
    public static function open(array $account, string $folder = 'INBOX')
    {
        $mailbox = self::mailbox($account, $folder);
        if (($account['auth_type'] ?? 'password') === 'oauth2') {
            return new OAuthImapClient($account, $folder, (new OAuthService())->accessToken((int)$account['id'], $account, 'imap'));
        }
        if (!function_exists('imap_open')) {
            throw new RuntimeException('PHP IMAP拡張が有効ではありません。');
        }
        imap_timeout(IMAP_OPENTIMEOUT, 30);
        $stream = @imap_open($mailbox, $account['imap_username'], $account['imap_password'], 0, 1);
        if ($stream === false) {
            $error = imap_last_error() ?: '不明なIMAPエラー';
            throw new RuntimeException('IMAP接続に失敗しました: ' . $error);
        }
        return $stream;
    }

    public static function mailbox(array $account, string $folder): string
    {
        $security = TransportSecurity::validate($account, 'imap');
        $flags = '/imap/' . ($security === 'ssl' ? 'ssl' : 'tls') . '/validate-cert/norsh';
        return '{' . $account['imap_host'] . ':' . (int)$account['imap_port'] . $flags . '}' . $folder;
    }

    public static function search($stream, string $criteria): array
    { return $stream instanceof OAuthImapClient ? $stream->search($criteria) : (imap_search($stream, $criteria, SE_UID) ?: []); }

    public static function overview($stream, int $uid): ?object
    { return $stream instanceof OAuthImapClient ? $stream->overview($uid) : (imap_fetch_overview($stream, (string)$uid, FT_UID)[0] ?? null); }

    public static function status($stream, array $account, string $folder): object
    {
        $status = $stream instanceof OAuthImapClient ? $stream->status()
            : imap_status($stream, self::mailbox($account, $folder), SA_UIDVALIDITY | SA_UIDNEXT);
        if (!$status || empty($status->uidvalidity) || empty($status->uidnext)) { throw new RuntimeException('IMAPのUID状態を取得できません。'); }
        return $status;
    }

    public static function flags($stream, array $uids): array
    {
        if ($stream instanceof OAuthImapClient) { return $stream->flags($uids); }
        if (!$uids) { return []; }
        $rows = imap_fetch_overview($stream, implode(',', $uids), FT_UID);
        if ($rows === false) { throw new RuntimeException('IMAPのフラグ状態を取得できません。'); }
        $out = [];
        foreach ($rows as $row) {
            if (!empty($row->deleted)) { continue; }
            $out[(int)$row->uid] = ['is_read'=>empty($row->seen) ? 0 : 1,'is_starred'=>empty($row->flagged) ? 0 : 1];
        }
        return $out;
    }

    public static function close($stream): void
    { if ($stream instanceof OAuthImapClient) { $stream->close(); } else { imap_close($stream); } }

    public static function append($stream, array $account, string $folder, string $mime): void
    {
        if ($stream instanceof OAuthImapClient) { $stream->append($folder, $mime); }
        elseif (!imap_append($stream, self::mailbox($account, $folder), $mime, '\\Seen')) { throw new RuntimeException('送信済みメールを保存できません。'); }
    }

    public static function flag($stream, int $uid, string $flag, bool $add): void
    {
        if ($stream instanceof OAuthImapClient) { $stream->flag($uid, $flag, $add); return; }
        $ok = $add ? imap_setflag_full($stream, (string)$uid, $flag, ST_UID) : imap_clearflag_full($stream, (string)$uid, $flag, ST_UID);
        if (!$ok) { throw new RuntimeException('IMAPのフラグを更新できません。'); }
    }

    public static function move($stream, int $uid, string $folder): ?int
    {
        if ($stream instanceof OAuthImapClient) { return $stream->move($uid, $folder); }
        if (!imap_mail_move($stream, (string)$uid, $folder, CP_UID) || !imap_expunge($stream)) {
            throw new RuntimeException('IMAP上でメールを移動できません。');
        }
        return null;
    }

    public static function delete($stream, int $uid): void
    {
        if ($stream instanceof OAuthImapClient) { $stream->flag($uid, '\\Deleted', true); $stream->expunge($uid); }
        elseif (!imap_delete($stream, (string)$uid, FT_UID) || !imap_expunge($stream)) { throw new RuntimeException('IMAPメールを削除できません。'); }
    }
}
