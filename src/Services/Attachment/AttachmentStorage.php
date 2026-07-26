<?php

namespace NexaPressMail\Services\Attachment;

use RuntimeException;

final class AttachmentStorage
{
    private string $directory;

    public function __construct()
    {
        $config = require NEXAPRESS_MAIL_PATH . '/config/storage.php';
        $this->directory = $config['private_directory'] . '/attachments';
    }

    public function store(string $bytes): string
    {
        $sub = date('Y/m');
        $dir = $this->directory . '/' . $sub;
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('添付ファイル保存先を作成できません。');
        }
        $name = bin2hex(random_bytes(20)) . '.bin';
        $relative = $sub . '/' . $name;
        if (file_put_contents($dir . '/' . $name, $bytes, LOCK_EX) === false) {
            throw new RuntimeException('添付ファイルを保存できません。');
        }
        @chmod($dir . '/' . $name, 0600);
        return $relative;
    }

    public function path(string $relative): string
    {
        if (!preg_match('#^\d{4}/\d{2}/[a-f0-9]{40}\.bin$#', $relative)) {
            throw new RuntimeException('添付ファイルのパスが不正です。');
        }
        return $this->directory . '/' . $relative;
    }

    public function delete(string $relative): void
    {
        $path = $this->path($relative);
        if (is_file($path)) { @unlink($path); }
    }
}
