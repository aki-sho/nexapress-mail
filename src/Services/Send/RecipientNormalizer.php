<?php

namespace NexaPressMail\Services\Send;

use InvalidArgumentException;
use NexaPressMail\Services\Security\HeaderInjectionGuard;

final class RecipientNormalizer
{
    public function normalize(string $value): array
    {
        HeaderInjectionGuard::assertSafe($value);
        $items = preg_split('/[,;]+/', $value) ?: [];
        $out = [];
        foreach ($items as $item) {
            $email = trim($item);
            if ($email === '') { continue; }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException("メールアドレスが不正です: {$email}");
            }
            $out[strtolower($email)] = $email;
        }
        return array_values($out);
    }
}
