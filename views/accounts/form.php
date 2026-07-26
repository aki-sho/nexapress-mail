<?php use app\Core\Csrf; $account=$account??[]; $editing=!empty($account['id']); ?>
<form class="npm-panel npm-form" method="post" action="<?= url('admin/extensions/nexapress-mail/accounts/save') ?>">
 <input type="hidden" name="_csrf_token" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="id" value="<?= (int)($account['id']??0) ?>">
 <div class="npm-grid"><label>表示名<input required name="name" value="<?= e($account['name']??'') ?>"></label><label>メールアドレス<input required type="email" name="email" value="<?= e($account['email']??'') ?>"></label></div>
 <h2>SMTP（送信）</h2><div class="npm-grid npm-grid--3"><label>ホスト<input required name="smtp_host" value="<?= e($account['smtp_host']??'') ?>"></label>
 <label>ポート<input required type="number" min="1" max="65535" name="smtp_port" value="<?= (int)($account['smtp_port']??587) ?>"></label>
 <label>暗号化<select name="smtp_security"><option value="tls" <?= ($account['smtp_security']??'tls')==='tls'?'selected':'' ?>>STARTTLS</option><option value="ssl" <?= ($account['smtp_security']??'')==='ssl'?'selected':'' ?>>SSL/TLS</option><option value="none" <?= ($account['smtp_security']??'')==='none'?'selected':'' ?>>なし</option></select></label></div>
 <div class="npm-grid"><label>ユーザー名<input required name="smtp_username" value="<?= e($account['smtp_username']??'') ?>"></label><label>パスワード<input <?= $editing?'':'required' ?> type="password" autocomplete="new-password" name="smtp_password" placeholder="<?= $editing?'変更時のみ入力':'' ?>"></label></div>
 <h2>IMAP（受信）</h2><div class="npm-grid npm-grid--3"><label>ホスト<input required name="imap_host" value="<?= e($account['imap_host']??'') ?>"></label>
 <label>ポート<input required type="number" min="1" max="65535" name="imap_port" value="<?= (int)($account['imap_port']??993) ?>"></label>
 <label>暗号化<select name="imap_security"><option value="ssl" <?= ($account['imap_security']??'ssl')==='ssl'?'selected':'' ?>>SSL/TLS</option><option value="tls" <?= ($account['imap_security']??'')==='tls'?'selected':'' ?>>STARTTLS</option><option value="none" <?= ($account['imap_security']??'')==='none'?'selected':'' ?>>なし</option></select></label></div>
 <div class="npm-grid"><label>ユーザー名<input required name="imap_username" value="<?= e($account['imap_username']??'') ?>"></label><label>パスワード<input <?= $editing?'':'required' ?> type="password" autocomplete="new-password" name="imap_password" placeholder="<?= $editing?'変更時のみ入力':'' ?>"></label></div>
 <h2>IMAPフォルダ名</h2><div class="npm-grid npm-grid--3"><label>送信済み<input required name="folder_sent" value="<?= e($account['folder_sent']??'Sent') ?>"></label><label>下書き<input required name="folder_drafts" value="<?= e($account['folder_drafts']??'Drafts') ?>"></label><label>ゴミ箱<input required name="folder_trash" value="<?= e($account['folder_trash']??'Trash') ?>"></label></div>
 <div class="npm-checks"><label><input type="checkbox" name="is_default" value="1" <?= !empty($account['is_default'])?'checked':'' ?>>既定の送信元</label><label><input type="checkbox" name="is_active" value="1" <?= !isset($account['is_active'])||$account['is_active']?'checked':'' ?>>有効</label></div>
 <div class="npm-actions"><button class="button" type="submit">保存</button>
  <button class="button secondary" type="submit" formaction="<?= url('admin/extensions/nexapress-mail/accounts/test/smtp') ?>">SMTP接続テスト</button>
  <button class="button secondary" type="submit" formaction="<?= url('admin/extensions/nexapress-mail/accounts/test/imap') ?>">IMAP接続テスト</button></div>
</form>
