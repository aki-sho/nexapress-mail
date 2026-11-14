<?php

namespace NexaPressMail\Services\Parser;

final class SubjectDecoder
{
    public static function decode(string $subject): string
    {
        $decoded = @mb_decode_mimeheader($subject);
        return mb_scrub(is_string($decoded) ? $decoded : $subject, 'UTF-8');
    }
}
