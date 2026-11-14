<?php

namespace NexaPressMail\Services\Attachment;

use NexaPressMail\Models\AttachmentRepository;

final class AttachmentPersister
{
    public function persistFiles(int $messageId, array $files): void
    {
        $storage = new AttachmentStorage();
        $repo = new AttachmentRepository();
        $validator = new AttachmentValidator();
        $limits = require NEXAPRESS_MAIL_PATH . '/config/limits.php';
        foreach ($files as $file) {
            $name = AttachmentFilename::clean($file['name']);
            $bytes = file_get_contents($file['path'], false, null, 0, $limits['attachment_bytes'] + 1);
            if ($bytes === false) { throw new \RuntimeException('添付ファイルを読み込めません。'); }
            $mime = $validator->inspectBytes($name, $bytes, $file['mime']);
            $repo->create([
                'message_id'=>$messageId,'filename'=>$name,'storage_path'=>$storage->store($bytes),
                'mime_type'=>$mime,'size_bytes'=>strlen($bytes),'content_id'=>null,'disposition'=>'attachment',
            ]);
        }
    }

    public function persistBytes(int $messageId, array $item): void
    {
        $name = AttachmentFilename::clean($item['name']);
        $size = strlen($item['bytes']);
        $mime = (new AttachmentValidator())->inspectBytes($name, $item['bytes'], $item['mime']);
        $contentId = (string)($item['content_id'] ?? '');
        if (strlen($contentId) > 255 || preg_match('/[^\x21-\x7e]/', $contentId)) { $contentId = ''; }
        (new AttachmentRepository())->create([
            'message_id'=>$messageId,'filename'=>$name,
            'storage_path'=>(new AttachmentStorage())->store($item['bytes']),
            'mime_type'=>$mime,'size_bytes'=>$size,
            'content_id'=>$contentId !== '' ? $contentId : null,
            'disposition'=>($item['disposition'] ?? '') === 'inline' ? 'inline' : 'attachment',
        ]);
    }
}
