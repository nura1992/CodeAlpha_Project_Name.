<div class="page-head">
  <div>
    <h1>Roles &amp; permissions</h1>
    <p class="muted">Central RBAC definitions shared by every MedCore module.</p>
  </div>
</div>

<div class="role-grid">
  <?php foreach ($roles as $role): ?>
    <a class="role-card" href="/admin/roles/<?= (int) $role['id'] ?>/edit">
      <h3><?= e($role['name']) ?></h3>
      <p class="muted"><?= e($role['description']) ?></p>
      <div class="role-meta">
        <span class="badge badge-info"><?= (int) $role['permission_count'] ?> permissions</span>
        <span class="badge badge-muted"><?= (int) $role['user_count'] ?> users</span>
      </div>
      <?php if ($role['slug'] === 'super_admin'): ?>
        <p class="fine-print">Note: super admin has no clinical-record permissions by design.</p>
      <?php endif; ?>
    </a>
  <?php endforeach; ?>
</div>
