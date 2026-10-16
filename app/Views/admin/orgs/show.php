<div class="page-head">
  <div>
    <h1><?= e($org['name']) ?></h1>
    <p class="muted"><?= e(ucfirst($org['type'])) ?> · <?= e(trim(($org['city'] ?? '') . ', ' . ($org['state'] ?? ''), ' ,')) ?>
      <span class="<?= e(status_badge($org['status'])) ?>"><?= e($org['status']) ?></span></p>
  </div>
  <div class="btn-row">
    <?php if ($canManage): ?><a class="btn btn-secondary" href="/admin/organizations/<?= (int) $org['id'] ?>/edit">Edit</a><?php endif; ?>
    <a class="btn btn-ghost" href="/admin/organizations"><?= partial('partials/icon', ['name' => 'chevron-left']) ?> Back</a>
  </div>
</div>

<div class="grid-2">
  <section class="card">
    <div class="card-head"><h2>Details</h2></div>
    <div class="card-body">
      <dl class="detail-list">
        <div><dt>Contact</dt><dd><?= e($org['email'] ?? '—') ?><br><?= e($org['phone'] ?? '') ?></dd></div>
        <div><dt>Address</dt><dd><?= e($org['address'] ?? '—') ?></dd></div>
        <div><dt>Registered</dt><dd><?= e(fdate($org['created_at'])) ?></dd></div>
      </dl>
    </div>
  </section>

  <section class="card">
    <div class="card-head"><h2>Staff members (<?= count($members) ?>)</h2></div>
    <div class="card-body">
      <?php if ($members === []): ?>
        <p class="muted">No members yet.</p>
      <?php else: ?>
        <ul class="simple-list">
          <?php foreach ($members as $m): ?>
            <li>
              <div>
                <strong><?= e($m['name']) ?></strong>
                <div class="muted small"><?= e($m['email']) ?><?= $m['job_title'] ? ' · ' . e($m['job_title']) : '' ?></div>
              </div>
              <?php if ($canManage && (int) $m['user_id'] !== (int) $currentUser['id']): ?>
                <form method="post" action="/admin/organizations/<?= (int) $org['id'] ?>/members/<?= (int) $m['user_id'] ?>/remove" data-confirm="Remove <?= e($m['name']) ?> from this organization?">
                  <?= csrf_field() ?>
                  <button class="btn btn-ghost btn-sm danger" type="submit">Remove</button>
                </form>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
    <?php if ($canManage): ?>
      <div class="card-body border-top">
        <form method="post" action="/admin/organizations/<?= (int) $org['id'] ?>/members" class="form-inline">
          <?= csrf_field() ?>
          <input type="email" name="email" placeholder="Existing user's email" required>
          <input type="text" name="job_title" placeholder="Job title (optional)" maxlength="80">
          <button class="btn btn-primary btn-sm" type="submit">Add member</button>
        </form>
      </div>
    <?php endif; ?>
  </section>
</div>
