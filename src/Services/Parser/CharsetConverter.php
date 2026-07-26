<?php

namespace NexaPressMail\Services\Parser;

final class CharsetConverter
{
    public static function convert(string $value, ?string $charset): string
    {
        $charset = trim((string) $charset, " \t\n\r\0\x0B\"");
        if ($charset === '' || strcasecmp($charset, 'UTF-8') === 0) { return $value; }
        $result = @mb_convert_encoding($value, 'UTF-8', $charset);
        return is_string($result) ? $result : $value;
    }
}
