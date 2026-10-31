<?php

namespace NexaPressMail\Services\Imap;

final class ImapResponseParser
{
    private int $offset = 0;
    private int $tokens = 0;

    public function parse(string $input, array $literals = []): array
    {
        if (strlen($input) > 1024 * 1024) { throw new MailReceiveLimitException('IMAP応答が大きすぎます。'); }
        $this->offset = $this->tokens = 0;
        return $this->list($input, $literals, 0);
    }

    private function list(string $input, array $literals, int $depth): array
    {
        if ($depth > 64) { throw new MailReceiveLimitException('IMAP応答の階層が深すぎます。'); }
        $out = []; $length = strlen($input);
        while ($this->offset < $length) {
            $c = $input[$this->offset++];
            if (ctype_space($c)) { continue; }
            if (++$this->tokens > 10000) { throw new MailReceiveLimitException('IMAP応答の要素が多すぎます。'); }
            if ($c === ')') {
                if ($depth === 0) { throw new \RuntimeException('IMAP応答の形式が不正です。'); }
                return $out;
            }
            if ($c === '(') { $out[] = $this->list($input, $literals, $depth + 1); continue; }
            if ($c === '"') {
                $value = ''; $closed = false;
                while ($this->offset < $length) {
                    $c = $input[$this->offset++];
                    if ($c === '"') { $closed = true; break; }
                    if ($c === '\\' && $this->offset < $length) { $c = $input[$this->offset++]; }
                    $value .= $c;
                }
                if (!$closed) { throw new \RuntimeException('IMAP応答の文字列が不正です。'); }
                $out[] = $value; continue;
            }
            $start = $this->offset - 1;
            while ($this->offset < $length && !ctype_space($input[$this->offset]) && $input[$this->offset] !== ')') { $this->offset++; }
            $value = substr($input, $start, $this->offset - $start);
            $out[] = array_key_exists($value, $literals) ? $literals[$value] : (strtoupper($value) === 'NIL' ? null : $value);
        }
        if ($depth !== 0) { throw new \RuntimeException('IMAP応答が途中で終了しました。'); }
        return $out;
    }

    public function structure(array $data, int $depth = 1, int &$count = 0): object
    {
        $limits = require NEXAPRESS_MAIL_PATH . '/config/limits.php';
        if (++$count > $limits['receive_mime_parts'] || $depth > $limits['receive_mime_depth']) {
            throw new MailReceiveLimitException('MIME構造が受信上限を超えています。');
        }
        if (is_array($data[0] ?? null)) {
            $parts = []; $i = 0;
            while (is_array($data[$i] ?? null)) { $parts[] = $this->structure($data[$i++], $depth + 1, $count); }
            return (object)['type'=>1,'subtype'=>$data[$i] ?? 'mixed','parts'=>$parts];
        }
        $type = strtolower((string)($data[0] ?? 'application'));
        $types = ['text','multipart','message','application','audio','image','video','model'];
        $encoding = array_search(strtoupper((string)($data[5] ?? '7BIT')), ['7BIT','8BIT','BINARY','BASE64','QUOTED-PRINTABLE'], true);
        $disposition = $data[$type === 'text' ? 9 : ($type === 'message' && strtolower((string)($data[1] ?? '')) === 'rfc822' ? 11 : 8)] ?? null;
        $part = (object)['type'=>array_search($type, $types, true) === false ? 3 : array_search($type, $types, true),
            'subtype'=>$data[1] ?? 'octet-stream','encoding'=>$encoding === false ? 0 : $encoding,
            'bytes'=>$data[6] ?? null,'id'=>$data[3] ?? null,'parameters'=>$this->parameters($data[2] ?? null),
            'disposition'=>is_array($disposition) ? ($disposition[0] ?? '') : '',
            'dparameters'=>$this->parameters(is_array($disposition) ? ($disposition[1] ?? null) : null)];
        return $part;
    }

    private function parameters($values): array
    {
        $out = [];
        if (is_array($values)) {
            for ($i = 0; $i + 1 < count($values); $i += 2) {
                $out[] = (object)['attribute'=>(string)$values[$i],'value'=>(string)$values[$i + 1]];
            }
        }
        return $out;
    }
}
