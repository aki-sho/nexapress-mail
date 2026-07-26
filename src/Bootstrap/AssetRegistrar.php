<?php

namespace NexaPressMail\Bootstrap;

final class AssetRegistrar
{
    public static function register(): void
    {
        if (!function_exists('add_action')) {
            return;
        }
        $matches = static fn(): bool => str_contains(
            (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH),
            '/admin/extensions/nexapress-mail'
        );
        add_action('admin_head', static function () use ($matches): void {
            if ($matches()) {
                echo '<style>';
                readfile(NEXAPRESS_MAIL_PATH . '/assets/css/mail.css');
                echo '</style>';
            }
        });
        add_action('admin_footer', static function () use ($matches): void {
            if ($matches()) {
                echo '<script>';
                readfile(NEXAPRESS_MAIL_PATH . '/assets/js/mail.js');
                echo '</script>';
            }
        });
    }
}
