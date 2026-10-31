<?php use app\Core\Csrf; ?>
<div class="npm-app">
  <?php require NEXAPRESS_MAIL_PATH . '/views/shared/nav.php'; ?>
  <main class="npm-main">
    <?php require NEXAPRESS_MAIL_PATH . '/views/shared/notice.php'; ?>
    <header class="npm-header"><div><h1>NexaPress Mail</h1><p>メールの送受信とアカウントを管理します。</p></div>
      <form method="post" action="<?= url('admin/extensions/nexapress-mail/sync') ?>">
        <input type="hidden" name="_csrf_token" value="<?= e(Csrf::token()) ?>">
        <button class="button" type="submit">↻ 手動同期</button>
      </form>
    </header>
    <?php if (!$accounts): ?><div class="npm-notice npm-notice--warning">最初にメールアカウントを登録してください。</div><?php endif; ?>
    <section class="npm-panel"><h2>過去メールを追加取得</h2>
      <form class="npm-form" method="post" action="<?= url('admin/extensions/nexapress-mail/sync') ?>">
        <input type="hidden" name="_csrf_token" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="mode" value="history">
        <label>アカウント<select name="account_id"><option value="0">すべての有効なアカウント</option><?php foreach($accounts as $account): ?><option value="<?= (int)$account['id'] ?>"><?= e($account['name']) ?></option><?php endforeach; ?></select></label>
        <div class="npm-grid"><label>開始日<input type="date" name="from"></label><label>終了日<input type="date" name="to"></label></div>
        <p><small>空欄は期間指定なし。同じ期間で繰り返すと続きから過去へ取得します。通常の手動同期は新着を優先します。</small></p>
        <button class="button secondary" type="submit">過去メールを続きから取得</button>
      </form>
    </section>
    <section class="npm-panel" data-sync-status-url="<?= e(url('admin/extensions/nexapress-mail/sync/status')) ?>"><h2>同期状況</h2>
      <div class="npm-table-wrap"><table class="npm-table"><thead><tr><th>アカウント</th><th>自動受信</th><th>状態</th><th>最終完了</th><th>次回予定</th></tr></thead><tbody>
      <?php foreach($accounts as $account): $job=$jobs[$account['id']]??[]; ?><tr data-job-account="<?= (int)$account['id'] ?>">
        <td><?= e($account['name']) ?></td><td><?= !empty($account['auto_sync_enabled'])?'有効（'.(int)$account['auto_sync_interval'].'秒）':'無効' ?></td>
        <td data-job-status><?= e(['idle'=>'未実行','running'=>'実行中','complete'=>'完了','failed'=>'失敗・再試行待ち'][$job['status']??'idle']??'未実行') ?></td>
        <td data-job-finished><?= !empty($job['finished_at'])?e(date('Y-m-d H:i:s',(int)$job['finished_at'])):'—' ?></td><td data-job-next><?= !empty($account['auto_sync_enabled'])&&!empty($job['next_run_at'])?e(date('Y-m-d H:i:s',(int)$job['next_run_at'])):'—' ?></td>
      </tr><?php endforeach; ?></tbody></table></div>
      <p><small>自動受信はサーバーの定期実行設定後に動作します。失敗の詳細は操作ログで確認できます。</small></p>
    </section>
    <section class="npm-stats">
      <?php foreach (['inbox'=>'受信トレイ','sent'=>'送信済み','drafts'=>'下書き','trash'=>'ゴミ箱'] as $key=>$label): ?>
        <a class="npm-card" href="<?= url('admin/extensions/nexapress-mail/mailbox/'.$key) ?>">
          <span><?= e($label) ?></span><strong><?= (int)$counts[$key]['total'] ?></strong>
          <?php if ($counts[$key]['unread']): ?><small>未読 <?= (int)$counts[$key]['unread'] ?></small><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </section>
    <section class="npm-panel"><h2>最近の受信メール</h2>
      <?php if (!$recent): ?><p class="npm-empty">同期されたメールはありません。</p><?php else: ?>
        <div class="npm-table-wrap"><table class="npm-table"><thead><tr><th>差出人</th><th>件名</th><th>受信日時</th></tr></thead><tbody>
        <?php foreach ($recent as $mail): ?><tr class="<?= $mail['is_read'] ? '' : 'is-unread' ?>">
          <td><?= e($mail['sender_name'] ?: $mail['sender_email']) ?></td>
          <td><a href="<?= url('admin/extensions/nexapress-mail/message/'.$mail['id']) ?>"><?= e($mail['subject'] ?: '(件名なし)') ?></a></td>
          <td><?= e($mail['received_at']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
      <?php endif; ?>
    </section>
  </main>
</div>
