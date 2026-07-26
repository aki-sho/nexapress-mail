<?php

namespace NexaPressMail\Services\Parser;

final class SubjectDecoder
{
    public static function decode(string $subject): string
    {
        $decoded = @mb_decode_mimeheader($subject);
        return is_string($decoded) ? $decoded : $subject;
    }
}
