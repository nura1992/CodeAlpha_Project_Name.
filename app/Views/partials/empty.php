<?php /** expects: $message, optional $cta ['href','label'] */ ?>
<div class="empty-state">
  <?= partial('partials/icon', ['name' => $icon ?? 'search']) ?>
  <p><?= e($message ?? 'Nothing here yet.') ?></p>
  <?php if (!empty($cta)): ?><a class="btn btn-primary btn-sm" href="<?= e($cta['href']) ?>"><?= e($cta['label']) ?></a><?php endif; ?>
</div>
