<div class="npm-app"><?php require NEXAPRESS_MAIL_PATH . '/views/shared/nav.php'; ?><main class="npm-main">
 <?php require NEXAPRESS_MAIL_PATH . '/views/shared/notice.php'; ?><header class="npm-header"><h1>送受信ログ</h1></header>
 <form class="npm-filters" method="get"><select name="level"><option value="">すべて</option><?php foreach(['info'=>'情報','warning'=>'警告','error'=>'エラー'] as $key=>$label): ?><option value="<?= $key ?>" <?= $level===$key?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select><button class="button secondary">絞り込む</button></form>
 <div class="npm-table-wrap"><table class="npm-table"><thead><tr><th>日時</th><th>レベル</th><th>処理</th><th>内容</th></tr></thead><tbody>
 <?php if(!$result['items']): ?><tr><td colspan="4" class="npm-empty">ログはありません。</td></tr><?php endif; ?>
 <?php foreach($result['items'] as $log): ?><tr><td><?= e($log['created_at']) ?></td><td><span class="npm-badge npm-badge--<?= e($log['level']) ?>"><?= e($log['level']) ?></span></td><td><?= e($log['operation']) ?></td><td><?= e($log['message']) ?></td></tr><?php endforeach; ?></tbody></table></div>
 <?php $pages=max(1,(int)ceil($result['total']/$perPage)); if($pages>1): ?><nav class="npm-pages"><?php for($i=max(1,$page-2);$i<=min($pages,$page+2);$i++): ?><a class="<?= $i===$page?'is-current':'' ?>" href="?page=<?= $i ?>&amp;level=<?= e($level) ?>"><?= $i ?></a><?php endfor; ?></nav><?php endif; ?>
</main></div>
