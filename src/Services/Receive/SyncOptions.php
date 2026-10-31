<?php

namespace NexaPressMail\Services\Receive;

final class SyncOptions
{
    public static function fromInput(array $input): array
    {
        $mode = $input['mode'] ?? 'delta';
        if (!in_array($mode, ['delta','history'], true)) { throw new \InvalidArgumentException('同期方式が不正です。'); }
        $from = trim((string)($input['from'] ?? '')); $to = trim((string)($input['to'] ?? ''));
        foreach ([$from,$to] as $date) {
            if ($date !== '') {
                $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                if (!$parsed || $parsed->format('Y-m-d') !== $date) { throw new \InvalidArgumentException('取得期間を正しく入力してください。'); }
            }
        }
        if ($from !== '' && $to !== '' && $from > $to) { throw new \InvalidArgumentException('開始日は終了日以前にしてください。'); }
        return compact('mode','from','to');
    }

    public static function criteria(array $options): string
    {
        $criteria = 'UNDELETED';
        if ($options['from'] !== '') { $criteria .= ' SINCE "' . (new \DateTimeImmutable($options['from']))->format('d-M-Y') . '"'; }
        if ($options['to'] !== '') { $criteria .= ' BEFORE "' . (new \DateTimeImmutable($options['to']))->modify('+1 day')->format('d-M-Y') . '"'; }
        return $criteria;
    }
}
