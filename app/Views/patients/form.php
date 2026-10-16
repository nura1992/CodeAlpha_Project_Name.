<?php $editing = $patient !== null; ?>
<div class="page-head">
  <div>
    <h1><?= $editing ? 'Edit patient' : 'Register patient' ?></h1>
    <p class="muted"><?= $editing ? 'MediID: ' . e($patient['patient_code']) : 'Create a MediID health record. Collect only necessary information.' ?></p>
  </div>
  <a class="btn btn-ghost" href="<?= $editing ? '/patients/' . (int) $patient['id'] : '/patients' ?>"><?= partial('partials/icon', ['name' => 'chevron-left']) ?> Back</a>
</div>

<section class="card narrow">
  <div class="card-body">
    <form method="post" action="<?= $editing ? '/patients/' . (int) $patient['id'] . '/update' : '/patients' ?>" class="form">
      <?= csrf_field() ?>
      <div class="form-row">
        <label class="field"><span>First name</span><input type="text" name="first_name" value="<?= e(old('first_name', $patient['first_name'] ?? '')) ?>" required maxlength="80"></label>
        <label class="field"><span>Last name</span><input type="text" name="last_name" value="<?= e(old('last_name', $patient['last_name'] ?? '')) ?>" required maxlength="80"></label>
      </div>
      <div class="form-row third">
        <label class="field"><span>Date of birth</span><input type="date" name="date_of_birth" value="<?= e(old('date_of_birth', $patient['date_of_birth'] ?? '')) ?>" max="<?= date('Y-m-d') ?>"></label>
        <label class="field"><span>Sex</span>
          <?php $sx = old('sex', $patient['sex'] ?? ''); ?>
          <select name="sex">
            <option value="">—</option>
            <?php foreach (['female', 'male', 'other'] as $s): ?><option value="<?= $s ?>" <?= $sx === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?>
          </select>
        </label>
        <label class="field"><span>Blood group</span>
          <?php $bg = old('blood_group', $patient['blood_group'] ?? ''); ?>
          <select name="blood_group">
            <option value="">—</option>
            <?php foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $g): ?><option value="<?= $g ?>" <?= $bg === $g ? 'selected' : '' ?>><?= $g ?></option><?php endforeach; ?>
          </select>
        </label>
      </div>
      <div class="form-row">
        <label class="field"><span>Phone</span><input type="tel" name="phone" value="<?= e(old('phone', $patient['phone'] ?? '')) ?>"></label>
        <label class="field"><span>Emergency contact</span><input type="text" name="emergency_contact" value="<?= e(old('emergency_contact', $patient['emergency_contact'] ?? '')) ?>" maxlength="160" placeholder="Name (phone)"></label>
      </div>
      <label class="field">
        <span>Known allergies <em class="opt">optional</em></span>
        <input type="text" name="allergies" value="<?= e(old('allergies', $patient['allergies'] ?? '')) ?>" maxlength="500" placeholder="e.g. Penicillin, or 'None known'">
      </label>
      <label class="field">
        <span>Primary organization</span>
        <?php $po = (int) old('primary_organization_id', $patient['primary_organization_id'] ?? 0); ?>
        <select name="primary_organization_id" required>
          <option value="">— select —</option>
          <?php foreach ($orgs as $org): ?><option value="<?= (int) $org['id'] ?>" <?= $po === (int) $org['id'] ? 'selected' : '' ?>><?= e($org['name']) ?></option><?php endforeach; ?>
        </select>
      </label>
      <?php if (!$editing): ?>
        <label class="check">
          <input type="checkbox" name="consent" value="1" <?= old('consent') ? 'checked' : '' ?> required>
          <span>The patient (or guardian) consented to creating this MediID record and to its use for their care. Consent is timestamped and auditable.</span>
        </label>
      <?php endif; ?>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= $editing ? 'Save changes' : 'Register patient' ?></button>
        <a class="btn btn-ghost" href="<?= $editing ? '/patients/' . (int) $patient['id'] : '/patients' ?>">Cancel</a>
      </div>
    </form>
  </div>
</section>
