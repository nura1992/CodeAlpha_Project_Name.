<?php if (!empty($messages)): ?>
  <div class="flash-stack" role="status" aria-live="polite">
    <?php foreach ($messages as $m): ?>
      <div class="alert alert-<?= e($m['type']) ?>">
        <span><?= e($m['message']) ?></span>
        <button type="button" class="alert-close" data-dismiss aria-label="Dismiss">&times;</button>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if (!empty(errors())): ?>
  <div class="alert alert-danger">
    <div>
      <strong>Please fix the following:</strong>
      <ul class="error-list">
        <?php foreach (errors() as $fieldErrors): foreach ($fieldErrors as $msg): ?>
          <li><?= e($msg) ?></li>
        <?php endforeach; endforeach; ?>
      </ul>
    </div>
  </div>
<?php endif; ?>
