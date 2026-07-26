<?php
namespace NexaPressMail\Controllers;

use NexaPressMail\Models\AccountRepository;
use NexaPressMail\Models\MessageRepository;
use NexaPressMail\Support\AdminView;
use NexaPressMail\Support\FlashMessage;

final class DashboardController extends BaseController
{
    public function content(): void
    {
        $messages = new MessageRepository();
        AdminView::display('dashboard/index', [
            'counts'=>$messages->counts(), 'accounts'=>(new AccountRepository())->all(),
            'recent'=>$messages->page(['folder'=>'inbox'], 1, 8)['items'],
            'flash'=>FlashMessage::pull(),
        ]);
    }
}
