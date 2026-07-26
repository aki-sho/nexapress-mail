<?php

namespace NexaPressMail\Services\Imap;

use NexaPressMail\Services\Parser\CharsetConverter;
use NexaPressMail\Services\Parser\SubjectDecoder;

final class ImapPartReader
{
    public function read($stream, int $uid): array
    {
        $structure = imap_fetchstructure($stream, $uid, FT_UID);
        $out = ['text'=>'','html'=>'','attachments'=>[]];
        if (!$structure) { return $out; }
        $this->walk($stream, $uid, $structure, '', $out);
        return $out;
    }

    private function walk($stream, int $uid, object $part, string $number, array &$out): void
    {
        if (!empty($part->parts)) {
            foreach ($part->parts as $index => $child) {
                $this->walk($stream, $uid, $child, $number === '' ? (string)($index + 1) : $number . '.' . ($index + 1), $out);
            }
            return;
        }
        $raw = imap_fetchbody($stream, $uid, $number === '' ? '1' : $number, FT_UID | FT_PEEK);
        $bytes = $this->decode((string)$raw, (int)($part->encoding ?? 0));
        $params = $this->parameters($part);
        $type = strtolower(($this->primary((int)($part->type ?? 0))) . '/' . ($part->subtype ?? 'octet-stream'));
        $filename = $params['filename'] ?? $params['name'] ?? '';
        if ($filename !== '' || in_array(strtolower((string)($part->disposition ?? '')), ['attachment','inline'], true)) {
            $out['attachments'][] = ['name'=>SubjectDecoder::decode($filename ?: 'attachment'), 'mime'=>$type,
                'bytes'=>$bytes,'content_id'=>isset($part->id) ? trim($part->id, '<>') : null,
                'disposition'=>strtolower((string)($part->disposition ?? 'attachment'))];
        } elseif ($type === 'text/plain') {
            $out['text'] .= CharsetConverter::convert($bytes, $params['charset'] ?? null);
        } elseif ($type === 'text/html') {
            $out['html'] .= CharsetConverter::convert($bytes, $params['charset'] ?? null);
        }
    }

    private function parameters(object $part): array
    {
        $out = [];
        foreach (array_merge($part->parameters ?? [], $part->dparameters ?? []) as $item) {
            $out[strtolower($item->attribute)] = $item->value;
        }
        return $out;
    }

    private function decode(string $value, int $encoding): string
    {
        return match ($encoding) { 3 => base64_decode($value, true) ?: '', 4 => quoted_printable_decode($value), default => $value };
    }

    private function primary(int $type): string
    {
        return ['text','multipart','message','application','audio','image','video','other'][$type] ?? 'application';
    }
}
