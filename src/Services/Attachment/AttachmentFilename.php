<?php

namespace NexaPressMail\Services\Attachment;

final class AttachmentFilename
{
    public static function clean(string $name): string
    {
        $name = str_replace(["\0", '/', '\\'], '_', trim($name));
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '_', $name) ?: 'attachment';
        return mb_substr($name === '' ? 'attachment' : $name, 0, 180);
    }
}
