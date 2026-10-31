<?php

namespace NexaPressMail\Services\OAuth;

class TokenHttpClient
{
    public function post(string $url, array $fields): array
    {
        $curl = curl_init($url);
        $response = ''; $overflow = false;
        try {
            curl_setopt_array($curl, [CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
                CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded','Accept: application/json'],
                CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,
                CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>20,
                CURLOPT_WRITEFUNCTION=>static function ($curl, string $data) use (&$response, &$overflow): int {
                    if (strlen($response) + strlen($data) > 1024 * 1024) { $overflow = true; return 0; }
                    $response .= $data; return strlen($data);
                }]);
            if (!curl_exec($curl) || $overflow || curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 200) {
                throw new \RuntimeException('OAuthトークンを取得できません。アプリ設定と同意状態を確認し、必要なら再認証してください。');
            }
            $tokens = json_decode($response, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($tokens) || strtolower($tokens['token_type'] ?? '') !== 'bearer'
                || empty($tokens['access_token']) || empty($tokens['expires_in'])) {
                throw new \RuntimeException('OAuthトークンの応答が不正です。');
            }
            return $tokens;
        } finally { curl_close($curl); }
    }
}
