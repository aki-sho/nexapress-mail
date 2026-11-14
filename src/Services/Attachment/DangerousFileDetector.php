<?php

namespace NexaPressMail\Services\Attachment;

final class DangerousFileDetector
{
    public static function isBlocked(string $filename, string $mime): bool
    {
        $config = require NEXAPRESS_MAIL_PATH . '/config/security.php';
        $extension = strtolower(pathinfo(rtrim($filename, " .\t"), PATHINFO_EXTENSION));
        if (in_array($extension, $config['blocked_extensions'], true)) { return true; }
        return in_array(strtolower($mime), [
            'application/x-dosexec','application/x-executable','application/x-msdownload',
            'application/x-httpd-php','text/x-php','application/x-pie-executable','application/x-sharedlib',
            'application/x-mach-binary','application/vnd.microsoft.portable-executable','application/x-shellscript','text/x-shellscript',
        ], true);
    }
}
