<?php

namespace NexaPressMail\Services\Security;

final class PrivateLock
{
    private $handle;

    public function __construct(string $name, bool $blocking = true, ?string $directory = null)
    {
        if (!preg_match('/^[a-z0-9-]+$/', $name)) { throw new \InvalidArgumentException('ロック名が不正です。'); }
        $config = require NEXAPRESS_MAIL_PATH . '/config/storage.php';
        $directory ??= $config['private_directory'];
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('非公開ストレージを作成できません。');
        }
        $this->handle = fopen($directory . '/' . $name . '.lock', 'c+b');
        if (!$this->handle) { throw new \RuntimeException('処理用ロックを作成できません。'); }
        if (!flock($this->handle, LOCK_EX | ($blocking ? 0 : LOCK_NB), $wouldBlock)) {
            $this->release();
            if ($wouldBlock) { throw new LockBusyException('この処理は実行中です。完了後に再試行してください。'); }
            throw new \RuntimeException('処理用ロックを取得できません。');
        }
    }

    public function release(): void
    {
        if (is_resource($this->handle)) { flock($this->handle, LOCK_UN); fclose($this->handle); }
        $this->handle = null;
    }

    public function __destruct() { $this->release(); }
}
