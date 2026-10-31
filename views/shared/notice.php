<?php if (!empty($flash)): ?>
  <div class="npm-notice npm-notice--<?= e($flash['type']) ?>" role="alert"><?= e($flash['message']) ?></div>
<?php endif; ?>
<?php if(!empty($_SESSION['npm_clear_compose'])): ?><span hidden data-clear-compose="<?= e($_SESSION['npm_clear_compose']) ?>"></span><?php unset($_SESSION['npm_clear_compose']); endif; ?>
