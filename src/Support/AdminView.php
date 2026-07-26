<?php
namespace NexaPressMail\Support;

final class AdminView
{
    public static function render(string $view, array $data = [], string $title = 'NexaPress Mail'): void
    {
        ob_start();
        self::display($view, $data);
        $content = (string) ob_get_clean();
        require BASE_PATH . '/app/Views/admin/layout.php';
    }

    public static function display(string $view, array $data = []): void
    {
        extract($data, EXTR_SKIP);
        require NEXAPRESS_MAIL_PATH . '/views/' . $view . '.php';
    }
}
