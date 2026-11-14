<?php

namespace NexaPressMail\Services\Parser;

final class CharsetConverter
{
    public static function convert(string $value, ?string $charset): string
    {
        $charset = trim((string) $charset, " \t\n\r\0\x0B\"");
        if ($charset !== '' && strcasecmp($charset, 'UTF-8') !== 0
            && preg_match('/\A[A-Za-z0-9_.:-]{1,64}\z/', $charset)) {
            try {
                $converted = @mb_convert_encoding($value, 'UTF-8', $charset);
                if (is_string($converted)) { return $converted; }
            } catch (\ValueError) {
                // An unrecognized MIME charset must not interrupt mailbox synchronization.
            }
        }
        return mb_scrub($value, 'UTF-8');
    }
}
