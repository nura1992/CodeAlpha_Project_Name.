<div class="page-head">
  <div>
    <h1><?= e($role['name']) ?></h1>
    <p class="muted"><?= e($role['description']) ?></p>
  </div>
  <a class="btn btn-ghost" href="/admin/roles"><?= partial('partials/icon', ['name' => 'chevron-left']) ?> All roles</a>
</div>

<?php if ($role['slug'] === 'super_admin'): ?>
  <div class="alert alert-warning">
    <span>The super administrator role is intentionally excluded from clinical-data permissions.
    Platform administration and patient-record access are separate concerns (blueprint security rule).</span>
  </div>
<?php endif; ?>

<form id="permMatrix" data-role-id="<?= (int) $role['id'] ?>" data-editable="<?= $canEdit ? '1' : '0' ?>">
  <?php foreach ($grouped as $group => $perms): ?>
    <section class="card">
      <div class="card-head"><h2><?= e($group) ?></h2></div>
      <div class="card-body">
        <div class="check-grid two">
          <?php foreach ($perms as $p): ?>
            <label class="check perm-item">
              <input type="checkbox" value="<?= (int) $p['id'] ?>"
                <?= in_array((int) $p['id'], array_map('intval', $granted), true) ? 'checked' : '' ?>
                <?= $canEdit ? '' : 'disabled' ?>>
              <span><strong><?= e($p['slug']) ?></strong><br><small class="muted"><?= e($p['name']) ?></small></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
  <?php endforeach; ?>

  <?php if ($canEdit): ?>
    <div class="sticky-actions">
      <span id="matrixStatus" class="muted">Changes are saved through the JSON API.</span>
      <button type="submit" class="btn btn-primary">Save permissions</button>
    </div>
  <?php else: ?>
    <p class="muted">You have read-only access to this matrix (<code>roles.permissions</code> required to edit).</p>
  <?php endif; ?>
</form>
