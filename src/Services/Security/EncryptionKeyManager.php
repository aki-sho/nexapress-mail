<?php

namespace NexaPressMail\Services\Security;

use RuntimeException;

final class EncryptionKeyManager
{
    public function key(): string
    {
        $config = require NEXAPRESS_MAIL_PATH . '/config/storage.php';
        $directory = $config['private_directory'];
        $path = $directory . '/credential.key';
        if (is_file($path)) {
            $key = file_get_contents($path);
            if (is_string($key) && strlen($key) === 32) {
                return $key;
            }
            throw new RuntimeException('メール暗号鍵が破損しています。');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('非公開ストレージを作成できません。');
        }
        $key = random_bytes(32);
        if (file_put_contents($path, $key, LOCK_EX) !== 32) {
            throw new RuntimeException('メール暗号鍵を保存できません。');
        }
        @chmod($path, 0600);
        return $key;
    }
}
