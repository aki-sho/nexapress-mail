<?php
return [
    'attachment_bytes' => 10 * 1024 * 1024,
    'message_attachments' => 10,
    // Receive limits: wire message, decoded UTF-8 body, decoded attachments.
    'receive_message_bytes' => 25 * 1024 * 1024,
    'receive_body_bytes' => 2 * 1024 * 1024,
    'receive_attachments_bytes' => 20 * 1024 * 1024,
    'receive_mime_parts' => 100,
    'receive_mime_depth' => 20,
    'page_size' => 25,
    'sync_limit' => 100,
    'sync_reconcile_limit' => 200,
    'log_retention_days' => 90,
];
