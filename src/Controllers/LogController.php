<?php
namespace NexaPressMail\Controllers;

use NexaPressMail\Models\LogRepository;

final class LogController extends BaseController
{
    public function index(): void
    {
        $page = max(1, (int)($_GET['page'] ?? 1)); $level = (string)($_GET['level'] ?? ''); $perPage = 50;
        $result = (new LogRepository())->page($page, $perPage, $level);
        $this->view('logs/index', compact('page','level','perPage','result'), '送受信ログ');
    }
}
