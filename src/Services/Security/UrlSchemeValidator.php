<?php

namespace NexaPressMail\Services\Security;

final class UrlSchemeValidator
{
    public static function safe(string $url, bool $image = false): bool
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($image) { return str_starts_with(strtolower($url), 'cid:'); }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http','https','mailto'], true);
    }
}
