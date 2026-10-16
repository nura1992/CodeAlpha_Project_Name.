<?php $editing = $org !== null; ?>
<div class="page-head">
  <div>
    <h1><?= $editing ? 'Edit organization' : 'Register organization' ?></h1>
    <p class="muted"><?= $editing ? e($org['name']) : 'Approve a new healthcare provider onto the platform.' ?></p>
  </div>
  <a class="btn btn-ghost" href="/admin/organizations"><?= partial('partials/icon', ['name' => 'chevron-left']) ?> All organizations</a>
</div>

<section class="card narrow">
  <div class="card-body">
    <form method="post" action="<?= $editing ? '/admin/organizations/' . (int) $org['id'] . '/update' : '/admin/organizations' ?>" class="form">
      <?= csrf_field() ?>
      <label class="field">
        <span>Organization name</span>
        <input type="text" name="name" value="<?= e(old('name', $org['name'] ?? '')) ?>" required maxlength="160">
      </label>
      <div class="form-row">
        <label class="field">
          <span>Type</span>
          <?php $t = old('type', $org['type'] ?? 'clinic'); ?>
          <select name="type">
            <?php foreach ($types as $type): ?>
              <option value="<?= $type ?>" <?= $t === $type ? 'selected' : '' ?>><?= ucfirst($type) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="field">
          <span>Status</span>
          <?php $s = old('status', $org['status'] ?? 'approved'); ?>
          <select name="status" <?= \MedCore\Core\Authorization::hasRole((int) $currentUser['id'], 'super_admin') ? '' : 'disabled' ?>>
            <?php foreach (['pending', 'approved', 'suspended'] as $st): ?>
              <option value="<?= $st ?>" <?= $s === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (!\MedCore\Core\Authorization::hasRole((int) $currentUser['id'], 'super_admin')): ?>
            <input type="hidden" name="status" value="<?= e($s) ?>">
            <small class="muted">Only a super administrator can change approval status.</small>
          <?php endif; ?>
        </label>
      </div>
      <div class="form-row">
        <label class="field"><span>Email</span><input type="email" name="email" value="<?= e(old('email', $org['email'] ?? '')) ?>"></label>
        <label class="field"><span>Phone</span><input type="tel" name="phone" value="<?= e(old('phone', $org['phone'] ?? '')) ?>"></label>
      </div>
      <label class="field"><span>Address</span><input type="text" name="address" value="<?= e(old('address', $org['address'] ?? '')) ?>" maxlength="255"></label>
      <div class="form-row third">
        <label class="field"><span>City</span><input type="text" name="city" value="<?= e(old('city', $org['city'] ?? '')) ?>"></label>
        <label class="field"><span>State</span><input type="text" name="state" value="<?= e(old('state', $org['state'] ?? '')) ?>"></label>
        <label class="field"><span>Country</span><input type="text" name="country" value="<?= e(old('country', $org['country'] ?? 'Nigeria')) ?>"></label>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= $editing ? 'Save changes' : 'Register' ?></button>
        <a class="btn btn-ghost" href="/admin/organizations">Cancel</a>
      </div>
    </form>
  </div>
</section>
