<?php

namespace NexaPressMail\Services\OAuth;

final class OAuthProvider
{
    public static function settings(string $provider, string $tenant = 'common'): array
    {
        if ($provider === 'google') {
            return ['authorize'=>'https://accounts.google.com/o/oauth2/v2/auth',
                'token'=>'https://oauth2.googleapis.com/token','scope'=>'https://mail.google.com/'];
        }
        if ($provider === 'microsoft' && preg_match('/^(common|organizations|consumers|[a-f0-9-]{36})$/i', $tenant)) {
            $base = 'https://login.microsoftonline.com/' . $tenant . '/oauth2/v2.0/';
            return ['authorize'=>$base . 'authorize','token'=>$base . 'token',
                'scope'=>'offline_access https://outlook.office.com/IMAP.AccessAsUser.All https://outlook.office.com/SMTP.Send'];
        }
        throw new \InvalidArgumentException('OAuthプロバイダーまたはテナントが不正です。');
    }

    public static function base64url(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
}
