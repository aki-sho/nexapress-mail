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
