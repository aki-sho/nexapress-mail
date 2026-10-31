<?php
namespace NexaPressMail\Controllers;

use NexaPressMail\Models\AccountRepository;
use NexaPressMail\Models\AttachmentRepository;
use NexaPressMail\Models\MessageRepository;
use NexaPressMail\Services\Imap\ImapFlagService;
use NexaPressMail\Support\FlashMessage;

final class MailboxController extends BaseController
{
    private const FOLDERS = ['inbox','sent','drafts','trash'];
    public function index(string $folder): void
    {
        $folder = in_array($folder, self::FOLDERS, true) ? $folder : 'inbox';
        $page = max(1, (int)($_GET['page'] ?? 1));
        $filters = ['folder'=>$folder,'account_id'=>(int)($_GET['account'] ?? 0),
            'status'=>(string)($_GET['status'] ?? ''),'q'=>trim((string)($_GET['q'] ?? ''))];
        $perPage = 25;
        $result = (new MessageRepository())->page($filters, $page, $perPage);
        $this->view('mailbox/index', compact('folder','filters','page','perPage','result') +
            ['accounts'=>(new AccountRepository())->all()], 'メール - ' . $folder);
    }
    public function show(string $id): void
    {
        $message = (new MessageRepository())->find((int)$id);
        if (!$message) { http_response_code(404); echo 'メールが見つかりません。'; return; }
        if ($message['folder'] === 'inbox' && !$message['is_read']) {
            try {
                $account = (new AccountRepository())->find((int)$message['account_id']);
                if ($account) { (new ImapFlagService())->apply($account, $message, 'read'); }
                (new MessageRepository())->updateFlags([(int)$id], ['is_read'=>1]); $message['is_read'] = 1;
            } catch (\Throwable $error) { FlashMessage::set('warning', 'メールは表示できますが、サーバー上の既読更新に失敗しました。'); }
        }
        $this->view('message/show', ['message'=>$message,
            'attachments'=>(new AttachmentRepository())->forMessage((int)$id)], 'メール詳細');
    }
}
