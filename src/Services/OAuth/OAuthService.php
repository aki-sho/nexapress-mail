<?php

namespace NexaPressMail\Services\OAuth;

use NexaPressMail\Models\OAuthRepository;
use NexaPressMail\Services\Security\PrivateLock;

final class OAuthService
{
    public function __construct(private ?OAuthRepository $repository = null, private ?TokenHttpClient $http = null)
    { $this->repository ??= new OAuthRepository(); $this->http ??= new TokenHttpClient(); }

    public function begin(int $accountId, int $userId): string
    {
        $config = $this->repository->find($accountId);
        if (!$config) { throw new \RuntimeException('先にOAuthアプリ設定を保存してください。'); }
        $provider = OAuthProvider::settings($config['provider'], $config['tenant']);
        $state = bin2hex(random_bytes(32)); $verifier = OAuthProvider::base64url(random_bytes(32));
        $_SESSION['npm_oauth'] = ['state'=>$state,'verifier'=>$verifier,'account_id'=>$accountId,
            'user_id'=>$userId,'expires'=>time() + 600,'fingerprint'=>$this->fingerprint($config)];
        $params = ['client_id'=>$config['client_id'],'redirect_uri'=>$config['redirect_uri'],'response_type'=>'code',
            'scope'=>$provider['scope'],'state'=>$state,'code_challenge'=>OAuthProvider::base64url(hash('sha256', $verifier, true)),
            'code_challenge_method'=>'S256','prompt'=>'consent'];
        if ($config['provider'] === 'google') { $params['access_type'] = 'offline'; }
        return $provider['authorize'] . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    public function complete(string $state, string $code, int $userId): int
    {
        $pending = $_SESSION['npm_oauth'] ?? null; unset($_SESSION['npm_oauth']);
        if (!$pending || !hash_equals($pending['state'], $state) || $pending['expires'] < time()
            || $pending['user_id'] !== $userId || $code === '' || strlen($code) > 8192) {
            throw new \RuntimeException('OAuth認証の状態が無効または期限切れです。接続をやり直してください。');
        }
        $lock = new PrivateLock('oauth-' . $pending['account_id']);
        try {
            $config = $this->repository->find($pending['account_id']);
            if (!$config || !hash_equals($pending['fingerprint'], $this->fingerprint($config))) {
                throw new \RuntimeException('OAuth設定が変更されました。接続をやり直してください。');
            }
            $provider = OAuthProvider::settings($config['provider'], $config['tenant']);
            $tokens = $this->http->post($provider['token'], ['grant_type'=>'authorization_code','code'=>$code,
                'client_id'=>$config['client_id'],'client_secret'=>$config['client_secret'],
                'redirect_uri'=>$config['redirect_uri'],'code_verifier'=>$pending['verifier']]);
            $this->repository->tokens($pending['account_id'], $tokens, $config['refresh_token']);
            return (int)$pending['account_id'];
        } finally { $lock->release(); }
    }

    public function accessToken(int $accountId, ?array $account = null, string $protocol = 'imap'): string
    {
        $lock = new PrivateLock('oauth-' . $accountId);
        try {
            $config = $this->repository->find($accountId);
            if (!$config || $config['refresh_token'] === '') { throw new \RuntimeException('OAuthアカウントを接続・再認証してください。'); }
            if ($account !== null) {
                $allowed = $config['provider'] === 'google' ? ['smtp'=>['smtp.gmail.com'],'imap'=>['imap.gmail.com']]
                    : ['smtp'=>['smtp.office365.com','smtp-mail.outlook.com'],'imap'=>['outlook.office365.com']];
                if (!in_array(strtolower($account[$protocol . '_host'] ?? ''), $allowed[$protocol] ?? [], true)) {
                    throw new \RuntimeException('OAuth接続先には選択したプロバイダーの公式メールサーバーを設定してください。');
                }
            }
            if ($config['access_token'] !== '' && (int)$config['expires_at'] > time() + 60) { return $config['access_token']; }
            $provider = OAuthProvider::settings($config['provider'], $config['tenant']);
            $tokens = $this->http->post($provider['token'], ['grant_type'=>'refresh_token','refresh_token'=>$config['refresh_token'],
                'client_id'=>$config['client_id'],'client_secret'=>$config['client_secret'],'scope'=>$provider['scope']]);
            $this->repository->tokens($accountId, $tokens, $config['refresh_token']);
            return (string)$tokens['access_token'];
        } finally { $lock->release(); }
    }

    private function fingerprint(array $config): string
    { return hash('sha256', implode("\0", array_map(static fn($key) => $config[$key], ['provider','client_id','client_secret','tenant','redirect_uri']))); }
}
