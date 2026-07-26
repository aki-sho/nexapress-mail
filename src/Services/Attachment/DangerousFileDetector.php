<?php

namespace NexaPressMail\Services\Attachment;

final class DangerousFileDetector
{
    public static function isBlocked(string $filename, string $mime): bool
    {
        $config = require NEXAPRESS_MAIL_PATH . '/config/security.php';
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($extension, $config['blocked_extensions'], true)) { return true; }
        return in_array(strtolower($mime), [
            'application/x-dosexec','application/x-executable','application/x-msdownload',
            'application/x-httpd-php','text/x-php',
        ], true);
    }
}
