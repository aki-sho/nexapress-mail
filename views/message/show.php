<?php use app\Core\Csrf; $recipients=json_decode($message['recipients_json'],true)?:[]; ?>
<div class="npm-app"><?php require NEXAPRESS_MAIL_PATH . '/views/shared/nav.php'; ?><main class="npm-main">
 <?php require NEXAPRESS_MAIL_PATH . '/views/shared/notice.php'; ?>
 <article class="npm-panel npm-message"><header><h1><?= e($message['subject'] ?: '(件名なし)') ?></h1>
  <p><strong>差出人:</strong> <?= e($message['sender_name']) ?> &lt;<?= e($message['sender_email']) ?>&gt;</p>
  <p><strong>日時:</strong> <?= e($message['received_at'] ?: ($message['sent_at'] ?: $message['created_at'])) ?></p></header>
  <div class="npm-actions"><a class="button" href="<?= url('admin/extensions/nexapress-mail/compose?mode=reply&message='.$message['id']) ?>">返信</a>
   <a class="button secondary" href="<?= url('admin/extensions/nexapress-mail/compose?mode=reply-all&message='.$message['id']) ?>">全員に返信</a>
   <a class="button secondary" href="<?= url('admin/extensions/nexapress-mail/compose?mode=forward&message='.$message['id']) ?>">転送</a>
   <?php if($message['folder']==='drafts'): ?><a class="button" href="<?= url('admin/extensions/nexapress-mail/compose?message='.$message['id']) ?>">下書きを編集</a><?php endif; ?>
  </div>
  <div class="npm-body"><?php if($message['body_html']): ?><?= $message['body_html'] ?><?php else: ?><pre><?= e($message['body_text']) ?></pre><?php endif; ?></div>
  <?php if($attachments): ?><section class="npm-attachments"><h2>添付ファイル</h2><?php foreach($attachments as $file): ?>
   <a href="<?= url('admin/extensions/nexapress-mail/attachment/'.$file['id']) ?>">📎 <?= e($file['filename']) ?> (<?= number_format($file['size_bytes']/1024,1) ?> KB)</a><?php endforeach; ?></section><?php endif; ?>
  <form class="npm-actions" method="post" action="<?= url('admin/extensions/nexapress-mail/message/'.$message['id'].'/action') ?>">
   <input type="hidden" name="_csrf_token" value="<?= e(Csrf::token()) ?>">
   <button name="action" value="<?= $message['is_starred']?'unstar':'star' ?>" class="button secondary"><?= $message['is_starred']?'スター解除':'スター' ?></button>
   <button name="action" value="<?= $message['is_read']?'unread':'read' ?>" class="button secondary"><?= $message['is_read']?'未読にする':'既読にする' ?></button>
   <button name="action" value="<?= $message['folder']==='trash'?'restore':'trash' ?>" class="button secondary"><?= $message['folder']==='trash'?'復元':'ゴミ箱へ' ?></button>
   <?php if($message['folder']==='trash'): ?><button name="action" value="delete" class="button npm-danger" data-confirm="完全に削除しますか？">完全削除</button><?php endif; ?>
  </form>
 </article>
</main></div>
