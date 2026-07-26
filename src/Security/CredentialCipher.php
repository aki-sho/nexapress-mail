<?php

namespace NexaPressMail\Security;

use NexaPressMail\Services\Security\EncryptionKeyManager;
use RuntimeException;

final class CredentialCipher
{
    private const PREFIX = 'npm1:';
    private ?string $testKey;

    public function __construct(private ?EncryptionKeyManager $keys = null, ?string $key = null)
    {
        $this->keys ??= new EncryptionKeyManager();
        $this->testKey = $key;
    }

    public function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new RuntimeException('認証情報を暗号化できません。');
        }
        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    public function decrypt(string $payload): string
    {
        if (!str_starts_with($payload, self::PREFIX)) {
            throw new RuntimeException('認証情報の暗号形式が不正です。');
        }
        $raw = base64_decode(substr($payload, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 28) {
            throw new RuntimeException('認証情報が破損しています。');
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->key(),
            OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) {
            throw new RuntimeException('認証情報を復号できません。暗号鍵を確認してください。');
        }
        return $plain;
    }

    private function key(): string
    {
        if ($this->testKey !== null) {
            if (strlen($this->testKey) !== 32) { throw new RuntimeException('暗号鍵は32バイト必要です。'); }
            return $this->testKey;
        }
        return $this->keys->key();
    }
}
