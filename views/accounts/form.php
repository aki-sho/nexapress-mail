<?php use app\Core\Csrf; $account=$account??[]; $editing=!empty($account['id']); ?>
<form class="npm-panel npm-form" method="post" action="<?= url('admin/extensions/nexapress-mail/accounts/save') ?>">
 <input type="hidden" name="_csrf_token" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="id" value="<?= (int)($account['id']??0) ?>">
 <div class="npm-grid"><label>表示名<input required name="name" value="<?= e($account['name']??'') ?>"></label><label>メールアドレス<input required type="email" name="email" value="<?= e($account['email']??'') ?>"></label></div>
 <label>認証方式<select name="auth_type" data-auth-type><option value="password" <?= ($account['auth_type']??'password')==='password'?'selected':'' ?>>パスワード／アプリパスワード</option><option value="oauth2" <?= ($account['auth_type']??'')==='oauth2'?'selected':'' ?>>OAuth 2.0</option></select></label>
 <fieldset data-oauth-settings <?= ($account['auth_type']??'password')==='oauth2'?'':'hidden' ?>><legend>OAuthアプリ設定</legend>
  <label>プロバイダー<select name="oauth_provider" data-oauth-provider><option value="google" <?= ($oauth['provider']??'google')==='google'?'selected':'' ?>>Google / Gmail</option><option value="microsoft" <?= ($oauth['provider']??'')==='microsoft'?'selected':'' ?>>Microsoft 365 / Outlook</option></select></label>
  <button type="button" class="button secondary" data-provider-defaults>メールサーバーの推奨設定を入力</button>
  <label>クライアントID<input name="oauth_client_id" value="<?= e($oauth['client_id']??'') ?>" autocomplete="off"></label>
  <label>クライアントシークレット<input type="password" name="oauth_client_secret" autocomplete="new-password" placeholder="<?= !empty($oauth['client_secret'])?'保存済み。変更時のみ入力':'' ?>"></label>
  <label>Microsoftテナント<input name="oauth_tenant" value="<?= e($oauth['tenant']??'common') ?>"><small>common、organizations、consumers、またはテナントID。</small></label>
  <label>コールバックURL<input type="url" name="oauth_redirect_uri" value="<?= e($oauth['redirect_uri']??'') ?>" placeholder="https://example.com/admin/extensions/nexapress-mail/oauth/callback"><small>アプリ登録と同じHTTPS URLを指定してください。CMSがサブディレクトリにある場合はそのパスも含めます。</small></label>
  <p>設定を保存してから「OAuthに接続」を実行してください。</p>
 </fieldset>
 <h2>SMTP（送信）</h2><div class="npm-grid npm-grid--3"><label>ホスト<input required name="smtp_host" value="<?= e($account['smtp_host']??'') ?>"></label>
 <label>ポート<input required type="number" min="1" max="65535" name="smtp_port" value="<?= (int)($account['smtp_port']??587) ?>"></label>
 <label>暗号化<select name="smtp_security" required><?php if(isset($account['smtp_security']) && !in_array($account['smtp_security'],['ssl','tls'],true)): ?><option value="" disabled selected>暗号化方式を再選択してください</option><?php endif; ?><option value="tls" <?= ($account['smtp_security']??'tls')==='tls'?'selected':'' ?>>STARTTLS</option><option value="ssl" <?= ($account['smtp_security']??'')==='ssl'?'selected':'' ?>>SSL/TLS</option></select></label></div>
 <div class="npm-grid"><label>ユーザー名<input required name="smtp_username" value="<?= e($account['smtp_username']??'') ?>"></label><label>パスワード<input <?= !$editing && ($account['auth_type']??'password')==='password'?'required':'' ?> type="password" autocomplete="new-password" name="smtp_password" placeholder="<?= $editing?'変更時のみ入力':'' ?>"></label></div>
 <h2>IMAP（受信）</h2><div class="npm-grid npm-grid--3"><label>ホスト<input required name="imap_host" value="<?= e($account['imap_host']??'') ?>"></label>
 <label>ポート<input required type="number" min="1" max="65535" name="imap_port" value="<?= (int)($account['imap_port']??993) ?>"></label>
 <label>暗号化<select name="imap_security" required><?php if(isset($account['imap_security']) && !in_array($account['imap_security'],['ssl','tls'],true)): ?><option value="" disabled selected>暗号化方式を再選択してください</option><?php endif; ?><option value="ssl" <?= ($account['imap_security']??'ssl')==='ssl'?'selected':'' ?>>SSL/TLS</option><option value="tls" <?= ($account['imap_security']??'')==='tls'?'selected':'' ?>>STARTTLS</option></select></label></div>
 <div class="npm-grid"><label>ユーザー名<input required name="imap_username" value="<?= e($account['imap_username']??'') ?>"></label><label>パスワード<input <?= !$editing && ($account['auth_type']??'password')==='password'?'required':'' ?> type="password" autocomplete="new-password" name="imap_password" placeholder="<?= $editing?'変更時のみ入力':'' ?>"></label></div>
 <h2>IMAPフォルダ名</h2><div class="npm-grid npm-grid--3"><label>送信済み<input required name="folder_sent" value="<?= e($account['folder_sent']??'Sent') ?>"></label><label>下書き<input required name="folder_drafts" value="<?= e($account['folder_drafts']??'Drafts') ?>"></label><label>ゴミ箱<input required name="folder_trash" value="<?= e($account['folder_trash']??'Trash') ?>"></label></div>
 <div class="npm-checks"><label><input type="checkbox" name="is_default" value="1" <?= !empty($account['is_default'])?'checked':'' ?>>既定の送信元</label><label><input type="checkbox" name="is_active" value="1" <?= !isset($account['is_active'])||$account['is_active']?'checked':'' ?>>有効</label></div>
 <div class="npm-grid"><label><input type="checkbox" name="auto_sync_enabled" value="1" <?= !empty($account['auto_sync_enabled'])?'checked':'' ?>>自動受信を有効にする</label><label>受信間隔（秒）<input type="number" name="auto_sync_interval" min="60" max="86400" step="60" value="<?= (int)($account['auto_sync_interval']??300) ?>"></label></div>
 <p><small>自動受信にはサーバーの定期実行設定が必要です。設定方法はREADMEの「自動受信」を参照してください。</small></p>
 <div class="npm-actions"><button class="button" type="submit">保存</button>
  <button class="button secondary" type="submit" formaction="<?= url('admin/extensions/nexapress-mail/accounts/test/smtp') ?>">SMTP接続テスト</button>
  <button class="button secondary" type="submit" formaction="<?= url('admin/extensions/nexapress-mail/accounts/test/imap') ?>">IMAP接続テスト</button></div>
</form>
<?php if($editing && ($account['auth_type']??'')==='oauth2'): ?>
 <section class="npm-panel"><p>OAuth接続: <?= !empty($oauth['refresh_token'])?'接続済み':'未接続' ?></p>
  <form method="post" action="<?= url('admin/extensions/nexapress-mail/oauth/connect/'.(int)$account['id']) ?>"><input type="hidden" name="_csrf_token" value="<?= e(Csrf::token()) ?>"><button class="button" type="submit">OAuthに接続／再認証</button></form>
  <?php if(!empty($oauth['refresh_token'])): ?><form method="post" action="<?= url('admin/extensions/nexapress-mail/oauth/disconnect/'.(int)$account['id']) ?>"><input type="hidden" name="_csrf_token" value="<?= e(Csrf::token()) ?>"><button class="button secondary" type="submit">このアプリの接続を解除</button></form><?php endif; ?>
 </section>
<?php endif; ?>
