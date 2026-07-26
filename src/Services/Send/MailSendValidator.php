<?php

namespace NexaPressMail\Services\Send;

use InvalidArgumentException;
use NexaPressMail\Services\Security\HeaderInjectionGuard;

final class MailSendValidator
{
    public function validate(array $message): void
    {
        HeaderInjectionGuard::assertSafe((string)($message['subject'] ?? ''));
        if (empty($message['to'])) { throw new InvalidArgumentException('宛先を入力してください。'); }
        if (mb_strlen((string)$message['subject']) > 998) { throw new InvalidArgumentException('件名が長すぎます。'); }
        if (trim((string)$message['body_text']) === '' && trim((string)$message['body_html']) === '') {
            throw new InvalidArgumentException('本文を入力してください。');
        }
    }
}
