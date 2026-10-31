<?php

namespace NexaPressMail\Services\Security;

use RuntimeException;

final class EncryptionKeyManager
{
    public function __construct(private ?string $directory = null) {}

    public function key(): string
    {
        $config = require NEXAPRESS_MAIL_PATH . '/config/storage.php';
        $directory = $this->directory ?? $config['private_directory'];
        $path = $directory . '/credential.key';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('非公開ストレージを作成できません。');
        }
        // Keep the lock file: unlinking it can split waiters across different locks.
        $lock = fopen($directory . '/credential.lock', 'c+b');
        if ($lock === false) { throw new RuntimeException('メール暗号鍵のロックを作成できません。'); }
        try {
            if (!flock($lock, LOCK_EX)) { throw new RuntimeException('メール暗号鍵をロックできません。'); }
            clearstatcache(true, $path);
            if (is_file($path)) {
                $key = file_get_contents($path);
                if (is_string($key) && strlen($key) === 32) { return $key; }
                throw new RuntimeException('メール暗号鍵が破損しています。');
            }
            $key = random_bytes(32);
            $file = fopen($path, 'x+b');
            if ($file === false) { throw new RuntimeException('メール暗号鍵を作成できません。'); }
            try {
                @chmod($path, 0600);
                if (fwrite($file, $key) !== 32 || !fflush($file)) {
                    throw new RuntimeException('メール暗号鍵を保存できません。');
                }
            } finally { fclose($file); }
            return $key;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
