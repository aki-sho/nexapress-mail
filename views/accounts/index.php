<?php use app\Core\Csrf; ?>
<div class="npm-app"><?php require NEXAPRESS_MAIL_PATH . '/views/shared/nav.php'; ?><main class="npm-main">
 <?php require NEXAPRESS_MAIL_PATH . '/views/shared/notice.php'; ?><header class="npm-header"><h1>メールアカウント</h1></header>
 <?php if($accounts): ?><section class="npm-account-list"><?php foreach($accounts as $item): ?><article class="npm-card"><strong><?= e($item['name']) ?></strong><span><?= e($item['email']) ?></span><small><?= $item['is_active']?'有効':'無効' ?><?= $item['is_default']?'・既定':'' ?></small>
  <div class="npm-actions"><a class="button secondary" href="<?= url('admin/extensions/nexapress-mail/accounts/edit/'.$item['id']) ?>">編集</a>
  <form method="post" action="<?= url('admin/extensions/nexapress-mail/accounts/delete/'.$item['id']) ?>"><input type="hidden" name="_csrf_token" value="<?= e(Csrf::token()) ?>"><button class="button npm-danger" data-confirm="削除しますか？">削除</button></form></div></article><?php endforeach; ?></section><?php endif; ?>
 <h2>新しいアカウント</h2><?php $account=[]; require NEXAPRESS_MAIL_PATH . '/views/accounts/form.php'; ?>
</main></div>
