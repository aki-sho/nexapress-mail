<?php

namespace NexaPressMail\Models;

use app\Core\Database;
use NexaPressMail\Security\CredentialCipher;
use NexaPressMail\Services\OAuth\OAuthProvider;

final class OAuthRepository
{
    private \PDO $pdo;
    private string $table;
    private CredentialCipher $cipher;

    public function __construct(?CredentialCipher $cipher = null)
    {
        $this->pdo = Database::connect(); $this->table = Database::table('nexapress_mail_oauth');
        $this->cipher = $cipher ?? new CredentialCipher();
    }

    public function find(int $accountId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE account_id=?"); $stmt->execute([$accountId]);
        $row = $stmt->fetch();
        if (!$row) { return null; }
        foreach (['client_secret','access_token','refresh_token'] as $key) {
            $row[$key] = empty($row[$key]) ? '' : $this->cipher->decrypt($row[$key]);
        }
        return $row;
    }

    public function save(int $accountId, array $input): void
    {
        $old = $this->find($accountId);
        $provider = (string)($input['oauth_provider'] ?? '');
        $tenant = trim((string)($input['oauth_tenant'] ?? 'common')) ?: 'common';
        OAuthProvider::settings($provider, $tenant);
        $clientId = trim((string)($input['oauth_client_id'] ?? ''));
        $redirect = trim((string)($input['oauth_redirect_uri'] ?? ''));
        $secret = (string)($input['oauth_client_secret'] ?? '');
        if ($secret === '') { $secret = $old['client_secret'] ?? ''; }
        if ($clientId === '' || strlen($clientId) > 255 || $secret === '' || strlen($secret) > 8192
            || strlen($redirect) > 1000 || !filter_var($redirect, FILTER_VALIDATE_URL)
            || parse_url($redirect, PHP_URL_SCHEME) !== 'https' || parse_url($redirect, PHP_URL_FRAGMENT)
            || parse_url($redirect, PHP_URL_USER) || parse_url($redirect, PHP_URL_QUERY)
            || !str_ends_with((string)parse_url($redirect, PHP_URL_PATH), '/admin/extensions/nexapress-mail/oauth/callback')) {
            throw new \InvalidArgumentException('OAuthのクライアントID・シークレット・HTTPSコールバックURLを正しく設定してください。');
        }
        $changed = !$old || $old['provider'] !== $provider || $old['tenant'] !== $tenant
            || $old['client_id'] !== $clientId || $old['client_secret'] !== $secret || $old['redirect_uri'] !== $redirect;
        $values = [$provider,$clientId,$this->cipher->encrypt($secret),$tenant,$redirect];
        if ($old) {
            $reset = $changed ? ',access_token=NULL,refresh_token=NULL,expires_at=0' : '';
            $this->pdo->prepare("UPDATE {$this->table} SET provider=?,client_id=?,client_secret=?,tenant=?,redirect_uri=?{$reset} WHERE account_id=?")
                ->execute([...$values,$accountId]);
        } else {
            $this->pdo->prepare("INSERT INTO {$this->table} (provider,client_id,client_secret,tenant,redirect_uri,account_id) VALUES (?,?,?,?,?,?)")
                ->execute([...$values,$accountId]);
        }
    }

    public function tokens(int $accountId, array $tokens, string $previousRefresh = ''): void
    {
        $access = (string)($tokens['access_token'] ?? '');
        $refresh = (string)($tokens['refresh_token'] ?? $previousRefresh);
        $expires = (int)($tokens['expires_in'] ?? 0);
        if ($access === '' || $refresh === '' || strlen($access) > 32768 || strlen($refresh) > 32768
            || preg_match('/[\r\n\0\x01]/', $access . $refresh) || $expires < 1 || $expires > 2592000) {
            throw new \RuntimeException('OAuthトークンの応答が不正です。再認証してください。');
        }
        $this->pdo->prepare("UPDATE {$this->table} SET access_token=?,refresh_token=?,expires_at=? WHERE account_id=?")
            ->execute([$this->cipher->encrypt($access),$this->cipher->encrypt($refresh),time() + $expires,$accountId]);
    }

    public function disconnect(int $id): void
    { $this->pdo->prepare("UPDATE {$this->table} SET access_token=NULL,refresh_token=NULL,expires_at=0 WHERE account_id=?")->execute([$id]); }
}
