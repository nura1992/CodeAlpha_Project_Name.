<div class="page-head">
  <div>
    <h1>Notifications</h1>
    <p class="muted">In-app alerts for your account.</p>
  </div>
  <form method="post" action="/notifications/read-all">
    <?= csrf_field() ?>
    <button class="btn btn-ghost" type="submit">Mark all as read</button>
  </form>
</div>

<section class="card">
  <div class="card-body">
    <?php if ($notifications === []): ?>
      <?= partial('partials/empty', ['message' => 'You have no notifications yet.', 'icon' => 'bell']) ?>
    <?php else: ?>
      <ul class="notif-list">
        <?php foreach ($notifications as $n): ?>
          <li class="notif <?= $n['read_at'] ? '' : 'unread' ?>">
            <span class="notif-dot <?= e($n['type']) ?>"></span>
            <div class="notif-body">
              <strong><?= e($n['title']) ?></strong>
              <?php if (!empty($n['body'])): ?><p><?= e($n['body']) ?></p><?php endif; ?>
              <span class="muted small"><?= e(fdatetime($n['created_at'])) ?></span>
            </div>
            <div class="notif-actions">
              <?php if (!empty($n['link'])): ?><a class="btn btn-ghost btn-sm" href="<?= e($n['link']) ?>">Open</a><?php endif; ?>
              <?php if (!$n['read_at']): ?>
                <form method="post" action="/notifications/<?= (int) $n['id'] ?>/read">
                  <?= csrf_field() ?>
                  <button class="btn btn-ghost btn-sm" type="submit">Mark read</button>
                </form>
              <?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</section>
