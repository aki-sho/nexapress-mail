<?php

namespace NexaPressMail\Services\Security;

use InvalidArgumentException;

final class TransportSecurity
{
    public static function validate(array $account, string $protocol): string
    {
        $mode = $account[$protocol . '_security'] ?? '';
        if (!in_array($mode, ['ssl', 'tls'], true)) {
            throw new InvalidArgumentException(strtoupper($protocol) . 'はSSL/TLSまたはSTARTTLSが必須です。アカウントの暗号化方式を再設定してください。');
        }
        $host = trim((string)($account[$protocol . '_host'] ?? ''));
        // Hosts must not inject IMAP flags or override PHPMailer's TLS settings.
        if (!filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)
            && !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw new InvalidArgumentException(strtoupper($protocol) . 'ホスト名が不正です。');
        }
        if ($host === '') { throw new InvalidArgumentException('ホスト名は必須です。'); }
        return $mode;
    }
}
