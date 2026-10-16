<?php $editing = $provider !== null; ?>
<div class="page-head">
  <div>
    <h1><?= $editing ? 'Edit provider' : 'Add provider' ?></h1>
    <p class="muted"><?= $editing ? e($provider['full_name']) : 'Add a bookable clinician to an organization.' ?></p>
  </div>
  <a class="btn btn-ghost" href="/providers"><?= partial('partials/icon', ['name' => 'chevron-left']) ?> All providers</a>
</div>

<section class="card narrow">
  <div class="card-body">
    <form method="post" action="<?= $editing ? '/providers/' . (int) $provider['id'] . '/update' : '/providers' ?>" class="form">
      <?= csrf_field() ?>
      <div class="form-row">
        <label class="field"><span>Full name</span><input type="text" name="full_name" value="<?= e(old('full_name', $provider['full_name'] ?? '')) ?>" required maxlength="120"></label>
        <label class="field"><span>Title</span>
          <?php $t = old('title', $provider['title'] ?? ''); ?>
          <select name="title">
            <option value="">—</option>
            <?php foreach (['Dr.', 'Nurse', 'Pharm.', 'Midwife', 'Physio', 'Lab Sci.'] as $tt): ?><option value="<?= $tt ?>" <?= $t === $tt ? 'selected' : '' ?>><?= $tt ?></option><?php endforeach; ?>
          </select>
        </label>
      </div>
      <div class="form-row">
        <label class="field"><span>Specialty</span><input type="text" name="specialty" value="<?= e(old('specialty', $provider['specialty'] ?? '')) ?>" required maxlength="80" placeholder="e.g. General Medicine"></label>
        <label class="field"><span>Organization</span>
          <?php $po = (int) old('organization_id', $provider['organization_id'] ?? 0); ?>
          <select name="organization_id" required>
            <option value="">— select —</option>
            <?php foreach ($orgs as $org): ?><option value="<?= (int) $org['id'] ?>" <?= $po === (int) $org['id'] ? 'selected' : '' ?>><?= e($org['name']) ?></option><?php endforeach; ?>
          </select>
        </label>
      </div>
      <div class="form-row third">
        <label class="field"><span>Email</span><input type="email" name="email" value="<?= e(old('email', $provider['email'] ?? '')) ?>"></label>
        <label class="field"><span>Phone</span><input type="tel" name="phone" value="<?= e(old('phone', $provider['phone'] ?? '')) ?>"></label>
        <label class="field"><span>Location</span><input type="text" name="location" value="<?= e(old('location', $provider['location'] ?? '')) ?>" maxlength="120" placeholder="e.g. Lagos — Ikeja"></label>
      </div>
      <label class="field"><span>Public bio <em class="opt">shown in the directory</em></span>
        <textarea name="bio" rows="3" maxlength="1000"><?= e(old('bio', $provider['bio'] ?? '')) ?></textarea>
      </label>
      <?php if ($editing): ?>
        <label class="field"><span>Status</span>
          <?php $st = old('status', $provider['status']); ?>
          <select name="status">
            <option value="active" <?= $st === 'active' ? 'selected' : '' ?>>Active (bookable)</option>
            <option value="inactive" <?= $st === 'inactive' ? 'selected' : '' ?>>Inactive (hidden)</option>
          </select>
        </label>
      <?php endif; ?>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= $editing ? 'Save changes' : 'Add provider' ?></button>
        <a class="btn btn-ghost" href="/providers">Cancel</a>
      </div>
    </form>
  </div>
</section>
