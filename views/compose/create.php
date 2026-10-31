<?php use app\Core\Csrf; ?>
<div class="npm-app"><?php require NEXAPRESS_MAIL_PATH . '/views/shared/nav.php'; ?><main class="npm-main">
 <?php require NEXAPRESS_MAIL_PATH . '/views/shared/notice.php'; ?>
 <header class="npm-header"><h1><?= $mode==='new'?'新規メール':($mode==='forward'?'転送':'返信') ?></h1></header>
 <?php if(!$accounts): ?><div class="npm-notice npm-notice--error">有効なアカウントがありません。</div><?php else: ?>
 <form class="npm-panel npm-form" method="post" enctype="multipart/form-data" action="<?= url('admin/extensions/nexapress-mail/send') ?>" data-compose data-autosave-url="<?= e(url('admin/extensions/nexapress-mail/draft/autosave')) ?>" data-recovery-url="<?= e(url('admin/extensions/nexapress-mail/draft/recovery')) ?>" data-user="<?= (int)$userId ?>" data-context="<?= e($mode.':'.$sourceId) ?>">
  <input type="hidden" name="_csrf_token" value="<?= e(Csrf::token()) ?>">
  <input type="hidden" name="draft_id" value="<?= (int)($draft['id']??0) ?>"><input type="hidden" name="in_reply_to" value="<?= e($values['in_reply_to']) ?>">
  <input type="hidden" name="client_key" value="<?= e($draftMeta['client_key']) ?>"><input type="hidden" name="revision" value="<?= (int)$draftMeta['revision'] ?>"><input type="hidden" name="request_id" value="<?= bin2hex(random_bytes(16)) ?>">
  <p data-save-status role="status" aria-live="polite">入力内容を自動保存します。</p><button type="button" class="button secondary" data-restore-compose hidden>このタブに残っている入力を復元</button>
  <label>送信アカウント<select name="account_id" required><?php foreach($accounts as $account): ?><option value="<?= (int)$account['id'] ?>" <?= !empty($values['account_id']) ? ((int)$values['account_id']===(int)$account['id']?'selected':'') : (!empty($account['is_default'])?'selected':'') ?>><?= e($account['name'].' <'.$account['email'].'>') ?></option><?php endforeach; ?></select></label>
  <label>宛先 <span>（複数はカンマ区切り）</span><input type="text" name="to" value="<?= e($values['to']) ?>"></label>
  <div class="npm-grid"><label>CC<input type="text" name="cc" value="<?= e($values['cc']) ?>"></label><label>BCC<input type="text" name="bcc" value="<?= e($values['bcc']) ?>"></label></div>
  <label>件名<input type="text" name="subject" maxlength="998" value="<?= e($values['subject']) ?>"></label>
  <label>形式<select name="format" data-format><option value="text" <?= $values['format']==='text'?'selected':'' ?>>プレーンテキスト</option><option value="html" <?= $values['format']==='html'?'selected':'' ?>>HTML</option></select></label>
  <label>本文<textarea name="body" rows="16"><?= e($values['body']) ?></textarea><small>HTML形式は安全化後に送信されます。外部画像は除去されます。</small></label>
  <label>添付ファイル<input type="file" name="attachments[]" multiple><small>1件10MB、合計10件まで。実行形式・スクリプトは拒否されます。</small></label>
  <div class="npm-draft-files" data-draft-files><?php foreach($draftAttachments as $file): ?><label><input type="checkbox" name="remove_attachments[]" value="<?= (int)$file['id'] ?>"> 📎 <?= e($file['filename']) ?>（チェックして削除）</label><?php endforeach; ?></div>
  <div class="npm-actions"><button class="button" type="submit">送信</button><button class="button secondary" type="submit" formaction="<?= url('admin/extensions/nexapress-mail/draft') ?>">下書き保存</button></div>
 </form><?php endif; ?>
</main></div>
