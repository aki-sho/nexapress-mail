<?php

namespace NexaPressMail\Services\Imap;

use RuntimeException;

final class ImapConnection
{
    public static function open(array $account, string $folder = 'INBOX')
    {
        if (!function_exists('imap_open')) {
            throw new RuntimeException('PHP IMAP拡張が有効ではありません。');
        }
        imap_timeout(IMAP_OPENTIMEOUT, 30);
        $flags = '/imap';
        $security = $account['imap_security'];
        if ($security === 'ssl') { $flags .= '/ssl'; }
        elseif ($security === 'tls') { $flags .= '/tls'; }
        else { $flags .= '/notls'; }
        $mailbox = self::mailbox($account, $folder);
        $stream = @imap_open($mailbox, $account['imap_username'], $account['imap_password'], 0, 1);
        if ($stream === false) {
            $error = imap_last_error() ?: '不明なIMAPエラー';
            throw new RuntimeException('IMAP接続に失敗しました: ' . $error);
        }
        return $stream;
    }

    public static function mailbox(array $account, string $folder): string
    {
        $flags = '/imap';
        $security = $account['imap_security'];
        if ($security === 'ssl') { $flags .= '/ssl'; }
        elseif ($security === 'tls') { $flags .= '/tls'; }
        else { $flags .= '/notls'; }
        return '{' . $account['imap_host'] . ':' . (int)$account['imap_port'] . $flags . '}' . $folder;
    }
}
