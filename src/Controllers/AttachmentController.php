<?php
namespace NexaPressMail\Controllers;

use NexaPressMail\Models\AttachmentRepository;
use NexaPressMail\Services\Attachment\AttachmentFilename;
use NexaPressMail\Services\Attachment\AttachmentStorage;
use NexaPressMail\Services\Attachment\AttachmentValidator;

final class AttachmentController extends BaseController
{
    public function download(string $id): void
    {
        $file = (new AttachmentRepository())->find((int)$id);
        if (!$file) { http_response_code(404); echo '添付ファイルが見つかりません。'; return; }
        $path = (new AttachmentStorage())->path($file['storage_path']);
        if (!is_file($path)) { http_response_code(404); echo '添付ファイルの実体がありません。'; return; }
        $name = AttachmentFilename::clean($file['filename']);
        try {
            (new AttachmentValidator())->inspectFile($name, $path);
        } catch (\InvalidArgumentException $error) {
            http_response_code(403);
            echo 'この添付ファイルはダウンロードできません。';
            return;
        }
        header('Content-Type: application/octet-stream'); header('Content-Length: ' . filesize($path));
        header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($name));
        header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store');
        readfile($path); exit;
    }
}
