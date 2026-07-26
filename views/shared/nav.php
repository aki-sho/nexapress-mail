<?php $base = 'admin/extensions/nexapress-mail'; ?>
<nav class="npm-nav" aria-label="メール機能">
  <a class="npm-compose" href="<?= url($base . '/compose') ?>">＋ 新規作成</a>
  <a href="<?= url($base . '/dashboard') ?>">概要</a>
  <a href="<?= url($base . '/mailbox/inbox') ?>">受信トレイ</a>
  <a href="<?= url($base . '/mailbox/sent') ?>">送信済み</a>
  <a href="<?= url($base . '/mailbox/drafts') ?>">下書き</a>
  <a href="<?= url($base . '/mailbox/trash') ?>">ゴミ箱</a>
  <a href="<?= url($base . '/accounts') ?>">アカウント</a>
  <a href="<?= url($base . '/logs') ?>">ログ</a>
</nav>
