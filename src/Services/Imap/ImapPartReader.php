<?php

namespace NexaPressMail\Services\Imap;

use NexaPressMail\Services\Parser\CharsetConverter;
use NexaPressMail\Services\Parser\SubjectDecoder;
use RuntimeException;

final class ImapPartReader
{
    public function read($stream, int $uid, int $messageSize): array
    {
        $limits = require NEXAPRESS_MAIL_PATH . '/config/limits.php';
        if ($messageSize < 1 || $messageSize > $limits['receive_message_bytes']) {
            throw new MailReceiveLimitException('メール全体のサイズが不明、または受信上限を超えています。');
        }
        $structure = $stream instanceof OAuthImapClient ? $stream->structure($uid) : imap_fetchstructure($stream, $uid, FT_UID);
        if (!$structure) { throw new RuntimeException('メールのMIME構造を取得できません。'); }

        // Validate the entire tree before fetching even the first body section.
        $plan = [];
        $budget = ['parts'=>0,'wire'=>0,'body'=>0,'attachments'=>0,'attachment_count'=>0];
        $this->plan($structure, '', 1, $limits, $budget, $plan);
        $out = ['text'=>'','html'=>'','attachments'=>[]];
        $bodyBytes = $attachmentBytes = $wireBytes = 0;
        foreach ($plan as $item) {
            $raw = $this->fetch($stream, $uid, $item['number'], $item['wire_limit']);
            $wireBytes += strlen($raw);
            $this->check($wireBytes, $limits['receive_message_bytes'], 'メール全体');
            $bytes = $this->decode($raw, $item['encoding']);
            unset($raw);
            if ($item['kind'] === 'attachment') {
                $this->check(strlen($bytes), $limits['attachment_bytes'], '添付ファイル');
                $attachmentBytes += strlen($bytes);
                $this->check($attachmentBytes, $limits['receive_attachments_bytes'], '添付ファイル合計');
                $out['attachments'][] = ['name'=>SubjectDecoder::decode($item['name']), 'mime'=>$item['mime'],
                    'bytes'=>$bytes,'content_id'=>$item['content_id'],'disposition'=>$item['disposition']];
            } else {
                $this->check(strlen($bytes), $limits['receive_body_bytes'], 'メール本文');
                $bytes = CharsetConverter::convert($bytes, $item['charset']);
                $bodyBytes += strlen($bytes);
                $this->check($bodyBytes, $limits['receive_body_bytes'], 'メール本文合計');
                $out[$item['kind']] .= $bytes;
            }
        }
        return $out;
    }

    private function plan(object $part, string $number, int $depth, array $limits, array &$budget, array &$plan): void
    {
        $this->check(++$budget['parts'], $limits['receive_mime_parts'], 'MIMEパート数');
        $this->check($depth, $limits['receive_mime_depth'], 'MIME階層');
        if (!empty($part->parts)) {
            foreach ($part->parts as $index => $child) {
                $this->plan($child, $number === '' ? (string)($index + 1) : $number . '.' . ($index + 1),
                    $depth + 1, $limits, $budget, $plan);
            }
            return;
        }
        if (!isset($part->bytes) || !is_numeric($part->bytes) || $part->bytes < 0) {
            throw new MailReceiveLimitException('MIMEパートのサイズを確認できません。');
        }
        $wireSize = (int)$part->bytes;
        $budget['wire'] += $wireSize;
        $this->check($budget['wire'], $limits['receive_message_bytes'], 'MIMEパート合計');
        $params = $this->parameters($part);
        $mime = strtolower($this->primary((int)($part->type ?? 0)) . '/' . ($part->subtype ?? 'octet-stream'));
        $name = $params['filename'] ?? $params['name'] ?? '';
        $disposition = strtolower((string)($part->disposition ?? ''));
        if ($name !== '' || in_array($disposition, ['attachment','inline'], true)) {
            $kind = 'attachment';
            $this->check(++$budget['attachment_count'], $limits['message_attachments'], '添付ファイル数');
            $decodedLimit = $limits['attachment_bytes'];
        } elseif ($mime === 'text/plain' || $mime === 'text/html') {
            $kind = $mime === 'text/plain' ? 'text' : 'html';
            $decodedLimit = $limits['receive_body_bytes'];
        } else { return; }
        $encoding = (int)($part->encoding ?? 0);
        // Allow standard Base64 line wrapping and quoted-printable expansion.
        $encodedLimit = match ($encoding) {
            3 => (int)(ceil($decodedLimit / 3) * 4 + ceil(ceil($decodedLimit / 3) * 4 / 76) * 2),
            4 => $decodedLimit * 4,
            default => $decodedLimit,
        };
        $encodedLimit = min($encodedLimit, $limits['receive_message_bytes']);
        $this->check($wireSize, $encodedLimit, $kind === 'attachment' ? '添付ファイル' : 'メール本文');
        if ($encoding !== 3 && $encoding !== 4) {
            $key = $kind === 'attachment' ? 'attachments' : 'body';
            $budget[$key] += $wireSize;
            $this->check($budget[$key], $limits[$key === 'body' ? 'receive_body_bytes' : 'receive_attachments_bytes'],
                $key === 'body' ? 'メール本文合計' : '添付ファイル合計');
        }
        $plan[] = ['number'=>$number === '' ? '1' : $number,'kind'=>$kind,'mime'=>$mime,
            'name'=>$name ?: 'attachment','charset'=>$params['charset'] ?? null,'encoding'=>$encoding,
            'wire_limit'=>$encodedLimit,'content_id'=>isset($part->id) ? trim($part->id, '<>') : null,
            'disposition'=>$disposition ?: 'attachment'];
    }

    private function fetch($stream, int $uid, string $number, int $limit): string
    {
        if (!in_array(BoundedBodyFilter::NAME, stream_get_filters(), true)
            && !stream_filter_register(BoundedBodyFilter::NAME, BoundedBodyFilter::class)) {
            throw new RuntimeException('受信サイズ検査を初期化できません。');
        }
        $file = tmpfile();
        if ($file === false) { throw new RuntimeException('受信用一時ファイルを作成できません。'); }
        try {
            $state = (object)['limit'=>$limit,'exceeded'=>false];
            if (stream_filter_append($file, BoundedBodyFilter::NAME, STREAM_FILTER_WRITE, $state) === false) {
                throw new RuntimeException('受信サイズ検査を開始できません。');
            }
            // Spool to a bounded file instead of materializing an unchecked IMAP body.
            $saved = $stream instanceof OAuthImapClient ? $stream->saveBody($file, $uid, $number, $limit)
                : @imap_savebody($stream, $file, $uid, $number, FT_UID | FT_PEEK);
            if ($state->exceeded) { throw new MailReceiveLimitException('取得したMIMEパートが受信上限を超えています。'); }
            if (!$saved || !fflush($file)) { throw new RuntimeException('メール本文を取得できません。'); }
            rewind($file);
            $raw = stream_get_contents($file, $limit + 1);
            if ($raw === false) { throw new RuntimeException('メール本文を読み込めません。'); }
            $this->check(strlen($raw), $limit, 'MIMEパート');
            return $raw;
        } finally { fclose($file); }
    }

    private function check(int $value, int $limit, string $label): void
    {
        if ($value > $limit) { throw new MailReceiveLimitException($label . 'が受信上限を超えています。'); }
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
        return ['text','multipart','message','application','audio','image','video','model','other'][$type] ?? 'application';
    }
}
