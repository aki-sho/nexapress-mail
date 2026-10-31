<?php

namespace NexaPressMail\Services\Imap;

final class BoundedBodyFilter extends \php_user_filter
{
    public const NAME = 'nexapress-mail.receive-limit';
    private int $bytes = 0;

    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $this->bytes += $bucket->datalen;
            if ($this->bytes > $this->params->limit) {
                $this->params->exceeded = true;
                return PSFS_ERR_FATAL;
            }
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }
        return PSFS_PASS_ON;
    }
}
