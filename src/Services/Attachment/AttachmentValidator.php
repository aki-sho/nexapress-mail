<?php

namespace NexaPressMail\Services\Attachment;

use InvalidArgumentException;
use finfo;

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

    public function inspectFile(string $name, string $path): string
    {
        $size = is_file($path) ? filesize($path) : false;
        if ($size === false) { throw new InvalidArgumentException('添付ファイルを読み込めません。'); }
        $this->validate($name, '', $size);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
        $this->validate($name, $mime, $size);
        return $mime;
    }

    public function inspectBytes(string $name, string $bytes, string $declaredMime = ''): string
    {
        $size = strlen($bytes);
        $this->validate($name, $declaredMime, $size);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream';
        $this->validate($name, $mime, $size);
        return $mime;
    }
}
