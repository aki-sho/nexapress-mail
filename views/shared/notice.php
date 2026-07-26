<?php if (!empty($flash)): ?>
  <div class="npm-notice npm-notice--<?= e($flash['type']) ?>" role="alert"><?= e($flash['message']) ?></div>
<?php endif; ?>
