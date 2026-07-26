<?php

namespace NexaPressMail\Services\Attachment;

use finfo;
use InvalidArgumentException;

final class UploadedAttachment
{
    public function collect(array $files): array
    {
        if (!isset($files['name']) || !is_array($files['name'])) { return []; }
        $limits = require NEXAPRESS_MAIL_PATH . '/config/limits.php';
        if (count($files['name']) > $limits['message_attachments']) {
            throw new InvalidArgumentException('添付ファイルは10件までです。');
        }
        $items = [];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        foreach ($files['name'] as $i => $name) {
            $error = (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) { continue; }
            if ($error !== UPLOAD_ERR_OK) { throw new InvalidArgumentException('添付ファイルのアップロードに失敗しました。'); }
            $tmp = (string) $files['tmp_name'][$i];
            if (!is_uploaded_file($tmp) && PHP_SAPI !== 'cli') { throw new InvalidArgumentException('不正なアップロードです。'); }
            $mime = $finfo->file($tmp) ?: 'application/octet-stream';
            $clean = AttachmentFilename::clean((string) $name);
            (new AttachmentValidator())->validate($clean, $mime, (int) $files['size'][$i]);
            $items[] = ['name'=>$clean,'mime'=>$mime,'size'=>(int)$files['size'][$i],'path'=>$tmp];
        }
        return $items;
    }
}
