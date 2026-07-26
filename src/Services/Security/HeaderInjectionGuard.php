<?php

namespace NexaPressMail\Services\Security;

use InvalidArgumentException;

final class HeaderInjectionGuard
{
    public static function assertSafe(string ...$values): void
    {
        foreach ($values as $value) {
            if (preg_match('/[\r\n\0]/', $value)) {
                throw new InvalidArgumentException('メールヘッダーに使用できない改行文字が含まれています。');
            }
        }
    }
}
