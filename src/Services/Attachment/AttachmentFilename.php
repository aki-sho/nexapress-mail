<?php

namespace NexaPressMail\Services\Attachment;

final class AttachmentFilename
{
    public static function clean(string $name): string
    {
        $name = str_replace(["\0", '/', '\\'], '_', trim($name));
        $name = mb_scrub($name, 'UTF-8');
        $name = preg_replace('/[\x00-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', '_', $name) ?: 'attachment';
        return rtrim(mb_substr($name, 0, 180, 'UTF-8'), ' .') ?: 'attachment';
    }
}
