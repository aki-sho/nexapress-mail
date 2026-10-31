<?php

namespace NexaPressMail\Services\OAuth;

use PHPMailer\PHPMailer\OAuthTokenProvider;

final class SmtpTokenProvider implements OAuthTokenProvider
{
    public function __construct(private array $account) {}
    public function getOauth64(): string
    {
        $token = (new OAuthService())->accessToken((int)$this->account['id'], $this->account, 'smtp');
        return base64_encode('user=' . $this->account['smtp_username'] . "\1auth=Bearer " . $token . "\1\1");
    }
}
