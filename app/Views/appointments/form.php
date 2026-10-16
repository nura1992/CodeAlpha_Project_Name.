<?php $isPatient = \MedCore\Core\Authorization::hasRole((int) $currentUser['id'], 'patient'); ?>
<div class="page-head">
  <div>
    <h1>Book appointment</h1>
    <p class="muted"><?= $isPatient ? 'Choose a provider and a time that works for you.' : 'Schedule a visit for a registered patient.' ?></p>
  </div>
  <a class="btn btn-ghost" href="/appointments"><?= partial('partials/icon', ['name' => 'chevron-left']) ?> All appointments</a>
</div>

<section class="card narrow">
  <div class="card-body">
    <form method="post" action="/appointments" class="form">
      <?= csrf_field() ?>

      <?php if ($isPatient): ?>
        <input type="hidden" name="patient_id" value="<?= (int) ($myPatientId ?? 0) ?>">
        <p class="notice">Booking for your own linked MediID record.</p>
      <?php else: ?>
        <label class="field">
          <span>Patient</span>
          <?php $pp = (int) old('patient_id', $prefillPatient ?? 0); ?>
          <select name="patient_id" required>
            <option value="">— select patient —</option>
            <?php foreach ($patients ?? [] as $p): ?>
              <option value="<?= (int) $p['id'] ?>" <?= $pp === (int) $p['id'] ? 'selected' : '' ?>>
                <?= e($p['last_name'] . ', ' . $p['first_name'] . ' (' . $p['patient_code'] . ')') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
      <?php endif; ?>

      <label class="field">
        <span>Provider</span>
        <?php $pv = (int) old('provider_id'); ?>
        <select name="provider_id" required>
          <option value="">— select provider —</option>
          <?php foreach ($providers as $prov): ?>
            <option value="<?= (int) $prov['id'] ?>" <?= $pv === (int) $prov['id'] ? 'selected' : '' ?>>
              <?= e($prov['full_name'] . ' — ' . $prov['specialty']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <div class="form-row">
        <label class="field">
          <span>Date &amp; time</span>
          <input type="datetime-local" name="scheduled_at" value="<?= e(old('scheduled_at')) ?>" required min="<?= date('Y-m-d\TH:i') ?>">
        </label>
        <label class="field">
          <span>Duration</span>
          <?php $dur = (int) old('duration_min', 30); ?>
          <select name="duration_min">
            <?php foreach ([15, 30, 45, 60, 90, 120] as $d): ?><option value="<?= $d ?>" <?= $dur === $d ? 'selected' : '' ?>><?= $d ?> minutes</option><?php endforeach; ?>
          </select>
        </label>
      </div>

      <label class="field">
        <span>Reason for visit <em class="opt">optional</em></span>
        <textarea name="reason" rows="3" maxlength="500" placeholder="Brief reason the provider should know about"><?= e(old('reason')) ?></textarea>
      </label>

      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Book appointment</button>
        <a class="btn btn-ghost" href="/appointments">Cancel</a>
      </div>
    </form>
  </div>
</section>
