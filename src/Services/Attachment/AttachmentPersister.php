<?php

namespace NexaPressMail\Services\Attachment;

use NexaPressMail\Models\AttachmentRepository;

final class AttachmentPersister
{
    public function persistFiles(int $messageId, array $files): void
    {
        $storage = new AttachmentStorage();
        $repo = new AttachmentRepository();
        foreach ($files as $file) {
            $bytes = file_get_contents($file['path']);
            if ($bytes === false) { throw new \RuntimeException('添付ファイルを読み込めません。'); }
            $repo->create([
                'message_id'=>$messageId,'filename'=>$file['name'],'storage_path'=>$storage->store($bytes),
                'mime_type'=>$file['mime'],'size_bytes'=>$file['size'],'content_id'=>null,'disposition'=>'attachment',
            ]);
        }
    }

    public function persistBytes(int $messageId, array $item): void
    {
        (new AttachmentValidator())->validate($item['name'], $item['mime'], strlen($item['bytes']));
        (new AttachmentRepository())->create([
            'message_id'=>$messageId,'filename'=>AttachmentFilename::clean($item['name']),
            'storage_path'=>(new AttachmentStorage())->store($item['bytes']),
            'mime_type'=>$item['mime'],'size_bytes'=>strlen($item['bytes']),
            'content_id'=>$item['content_id'] ?? null,'disposition'=>$item['disposition'] ?? 'attachment',
        ]);
    }
}
