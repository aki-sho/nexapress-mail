<?php

namespace NexaPressMail\Services\Send;

use NexaPressMail\Services\Security\MailHtmlSanitizer;

final class OutgoingMessageFactory
{
    public function fromRequest(array $input, bool $strict = true): array
    {
        $normalizer = new RecipientNormalizer();
        $format = ($input['format'] ?? 'text') === 'html' ? 'html' : 'text';
        $body = (string)($input['body'] ?? '');
        $message = [
            'to'=>$normalizer->normalize((string)($input['to'] ?? '')),
            'cc'=>$normalizer->normalize((string)($input['cc'] ?? '')),
            'bcc'=>$normalizer->normalize((string)($input['bcc'] ?? '')),
            'subject'=>trim((string)($input['subject'] ?? '')),
            'body_text'=>$format === 'text' ? $body : trim(html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            'body_html'=>$format === 'html' ? (new MailHtmlSanitizer())->sanitize($body) : '',
            'in_reply_to'=>trim((string)($input['in_reply_to'] ?? '')),
        ];
        if ($strict) { (new MailSendValidator())->validate($message); }
        return $message;
    }
}
