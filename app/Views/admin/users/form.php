<?php $editing = $user !== null; ?>
<div class="page-head">
  <div>
    <h1><?= $editing ? 'Edit user' : 'New user' ?></h1>
    <p class="muted"><?= $editing ? e($user['email']) : 'Create an account and assign roles.' ?></p>
  </div>
  <a class="btn btn-ghost" href="/admin/users"><?= partial('partials/icon', ['name' => 'chevron-left']) ?> Back to users</a>
</div>

<section class="card narrow">
  <div class="card-body">
    <form method="post" action="<?= $editing ? '/admin/users/' . (int) $user['id'] . '/update' : '/admin/users' ?>" class="form">
      <?= csrf_field() ?>
      <div class="form-row">
        <label class="field">
          <span>Full name</span>
          <input type="text" name="name" value="<?= e(old('name', $user['name'] ?? '')) ?>" required>
        </label>
        <label class="field">
          <span>Phone</span>
          <input type="tel" name="phone" value="<?= e(old('phone', $user['phone'] ?? '')) ?>">
        </label>
      </div>
      <label class="field">
        <span>Email address</span>
        <input type="email" name="email" value="<?= e(old('email', $user['email'] ?? '')) ?>" required>
      </label>
      <div class="form-row">
        <label class="field">
          <span><?= $editing ? 'New password (leave blank to keep current)' : 'Temporary password' ?></span>
          <input type="password" name="password" <?= $editing ? '' : 'required' ?> autocomplete="new-password" minlength="<?= (int) config('app.auth.password_min_length', 10) ?>">
        </label>
        <label class="field">
          <span>Status</span>
          <?php $st = old('status', $user['status'] ?? 'active'); ?>
          <select name="status">
            <?php foreach (['active', 'suspended', 'deactivated'] as $s): ?>
              <option value="<?= $s ?>" <?= $st === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>

      <div class="field">
        <span>Roles</span>
        <div class="check-grid">
          <?php foreach ($roles as $role): ?>
            <label class="check">
              <input type="checkbox" name="roles[]" value="<?= (int) $role['id'] ?>"
                <?= in_array((int) $role['id'], array_map('intval', (array) old('roles', $userRoleIds ?? [])), true) ? 'checked' : '' ?>>
              <span><strong><?= e($role['name']) ?></strong><br><small class="muted"><?= e($role['description']) ?></small></span>
            </label>
          <?php endforeach; ?>
        </div>
        <?php if (error_for('roles')): ?><small class="field-error"><?= e(error_for('roles')) ?></small><?php endif; ?>
      </div>

      <?php if (!$editing && $orgs !== []): ?>
        <div class="form-row">
          <label class="field">
            <span>Organization <em class="opt">optional</em></span>
            <select name="organization_id">
              <option value="">— none —</option>
              <?php foreach ($orgs as $org): ?>
                <option value="<?= (int) $org['id'] ?>" <?= (int) old('organization_id') === (int) $org['id'] ? 'selected' : '' ?>><?= e($org['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="field">
            <span>Job title <em class="opt">optional</em></span>
            <input type="text" name="job_title" value="<?= e(old('job_title')) ?>" maxlength="80">
          </label>
        </div>
      <?php endif; ?>

      <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= $editing ? 'Save changes' : 'Create user' ?></button>
        <a class="btn btn-ghost" href="/admin/users">Cancel</a>
      </div>
    </form>
  </div>
</section>
