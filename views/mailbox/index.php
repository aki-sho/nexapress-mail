<?php use app\Core\Csrf; $labels=['inbox'=>'受信トレイ','sent'=>'送信済み','drafts'=>'下書き','trash'=>'ゴミ箱']; ?>
<div class="npm-app">
 <?php require NEXAPRESS_MAIL_PATH . '/views/shared/nav.php'; ?>
 <main class="npm-main">
  <?php require NEXAPRESS_MAIL_PATH . '/views/shared/notice.php'; ?>
  <header class="npm-header"><h1><?= e($labels[$folder]) ?></h1></header>
  <form class="npm-filters" method="get">
   <input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="件名、差出人、本文を検索">
   <select name="account"><option value="0">すべてのアカウント</option><?php foreach($accounts as $account): ?>
    <option value="<?= (int)$account['id'] ?>" <?= $filters['account_id']==$account['id']?'selected':'' ?>><?= e($account['name']) ?></option><?php endforeach; ?></select>
   <select name="status"><option value="">すべて</option><option value="unread" <?= $filters['status']==='unread'?'selected':'' ?>>未読</option>
    <option value="starred" <?= $filters['status']==='starred'?'selected':'' ?>>スター</option></select>
   <button class="button secondary">絞り込む</button>
  </form>
  <form method="post" action="<?= url('admin/extensions/nexapress-mail/bulk') ?>" data-bulk-form>
   <input type="hidden" name="_csrf_token" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="folder" value="<?= e($folder) ?>">
   <div class="npm-bulk"><select name="action" required><option value="">一括操作</option><option value="read">既読</option><option value="unread">未読</option>
    <option value="star">スター</option><option value="unstar">スター解除</option><?php if($folder==='trash'): ?><option value="restore">復元</option><option value="delete">完全削除</option><?php else: ?><option value="trash">ゴミ箱へ</option><?php endif; ?></select>
    <button class="button secondary" type="submit">適用</button></div>
   <div class="npm-table-wrap"><table class="npm-table"><thead><tr><th><input type="checkbox" data-select-all aria-label="すべて選択"></th><th>★</th><th>差出人 / 宛先</th><th>件名</th><th>日時</th></tr></thead><tbody>
   <?php if(!$result['items']): ?><tr><td colspan="5" class="npm-empty">該当するメールはありません。</td></tr><?php endif; ?>
   <?php foreach($result['items'] as $mail): ?><tr class="<?= $mail['is_read']?'':'is-unread' ?>">
    <td><input type="checkbox" name="ids[]" value="<?= (int)$mail['id'] ?>"></td><td><?= $mail['is_starred']?'★':'☆' ?></td>
    <td><?= e($folder==='sent' ? $mail['sender_email'] : ($mail['sender_name'] ?: $mail['sender_email'])) ?></td>
    <td><a href="<?= url('admin/extensions/nexapress-mail/message/'.$mail['id']) ?>"><?= e($mail['subject'] ?: '(件名なし)') ?></a><?= $mail['has_attachments']?' 📎':'' ?></td>
    <td><?= e($mail['received_at'] ?: ($mail['sent_at'] ?: $mail['created_at'])) ?></td></tr><?php endforeach; ?>
   </tbody></table></div>
  </form>
  <?php $pages=max(1,(int)ceil($result['total']/$perPage)); if($pages>1): ?><nav class="npm-pages" aria-label="ページ">
   <?php for($i=max(1,$page-2);$i<=min($pages,$page+2);$i++): ?><a class="<?= $i===$page?'is-current':'' ?>" href="?page=<?= $i ?>&amp;q=<?= rawurlencode($filters['q']) ?>&amp;account=<?= $filters['account_id'] ?>&amp;status=<?= e($filters['status']) ?>"><?= $i ?></a><?php endfor; ?></nav><?php endif; ?>
 </main>
</div>
