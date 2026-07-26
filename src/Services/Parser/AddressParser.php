<?php

namespace NexaPressMail\Services\Parser;

final class AddressParser
{
    public static function parse(string $value): array
    {
        if ($value === '' || !function_exists('imap_rfc822_parse_adrlist')) { return []; }
        $items = @imap_rfc822_parse_adrlist($value, 'invalid.local');
        if (!is_array($items)) { return []; }
        $out = [];
        foreach ($items as $item) {
            if (($item->host ?? 'invalid.local') === 'invalid.local' || empty($item->mailbox)) { continue; }
            $email = $item->mailbox . '@' . $item->host;
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $out[] = ['email'=>$email, 'name'=>SubjectDecoder::decode((string)($item->personal ?? ''))];
            }
        }
        return $out;
    }
}
