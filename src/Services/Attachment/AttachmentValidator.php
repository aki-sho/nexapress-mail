<?php

namespace NexaPressMail\Services\Attachment;

use InvalidArgumentException;

final class AttachmentValidator
{
    public function validate(string $name, string $mime, int $size): void
    {
        $limits = require NEXAPRESS_MAIL_PATH . '/config/limits.php';
        if ($size < 0 || $size > $limits['attachment_bytes']) {
            throw new InvalidArgumentException('添付ファイルは1件あたり10MB以下にしてください。');
        }
        if (DangerousFileDetector::isBlocked($name, $mime)) {
            throw new InvalidArgumentException('危険な種類の添付ファイルは使用できません。');
        }
    }
}
