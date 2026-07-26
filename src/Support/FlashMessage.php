<?php
namespace NexaPressMail\Support;

final class FlashMessage
{
    public static function set(string $type, string $message): void
    {
        $_SESSION['nexapress_mail_flash'] = ['type'=>$type,'message'=>$message];
    }

    public static function pull(): ?array
    {
        $flash = $_SESSION['nexapress_mail_flash'] ?? null;
        unset($_SESSION['nexapress_mail_flash']);
        return is_array($flash) ? $flash : null;
    }
}
