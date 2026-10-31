<?php

namespace NexaPressMail\Services\Imap;

final class ImapConnectionTester
{
    public function test(array $account): void
    {
        $stream = ImapConnection::open($account);
        ImapConnection::close($stream);
    }
}
