<div class="page-head">
  <div>
    <h1><?= e($patient['first_name'] . ' ' . $patient['last_name']) ?></h1>
    <p class="muted">MediID <code><?= e($patient['patient_code']) ?></code>
      <span class="<?= e(status_badge($patient['status'])) ?>"><?= e($patient['status']) ?></span></p>
  </div>
  <div class="btn-row">
    <?php if ($canEdit): ?><a class="btn btn-secondary" href="/patients/<?= (int) $patient['id'] ?>/edit">Edit record</a><?php endif; ?>
    <?php if (\MedCore\Core\Authorization::can((int) $currentUser['id'], 'appointments.create')): ?>
      <a class="btn btn-primary" href="/appointments/create?patient_id=<?= (int) $patient['id'] ?>">Book appointment</a>
    <?php endif; ?>
  </div>
</div>

<div class="grid-2">
  <section class="card">
    <div class="card-head"><h2>Identity &amp; demographics</h2></div>
    <div class="card-body">
      <dl class="detail-list">
        <div><dt>Full name</dt><dd><?= e($patient['first_name'] . ' ' . $patient['last_name']) ?></dd></div>
        <div><dt>Date of birth</dt><dd><?= e(fdate($patient['date_of_birth'])) ?><?= $patient['date_of_birth'] ? ' (' . (int) ((time() - strtotime($patient['date_of_birth'])) / 31557600) . ' yrs)' : '' ?></dd></div>
        <div><dt>Sex</dt><dd><?= e($patient['sex'] ? ucfirst($patient['sex']) : '—') ?></dd></div>
        <div><dt>Phone</dt><dd><?= e($patient['phone'] ?? '—') ?></dd></div>
        <div><dt>Primary organization</dt><dd><?= e($org['name'] ?? '—') ?></dd></div>
        <div><dt>Consent</dt><dd><?= $patient['consent_recorded_at'] ? 'Recorded ' . e(fdate($patient['consent_recorded_at'], 'j M Y, g:ia')) : 'Not recorded' ?></dd></div>
      </dl>
    </div>
  </section>

  <section class="card">
    <div class="card-head"><h2>Clinical summary</h2></div>
    <div class="card-body">
      <dl class="detail-list">
        <div><dt>Blood group</dt><dd><?= e($patient['blood_group'] ?? '—') ?></dd></div>
        <div><dt>Allergies</dt><dd><?= $patient['allergies'] ? '<span class="flag-danger">' . e($patient['allergies']) . '</span>' : '—' ?></dd></div>
        <div><dt>Emergency contact</dt><dd><?= e($patient['emergency_contact'] ?? '—') ?></dd></div>
      </dl>
      <p class="fine-print">Encounters, prescriptions and lab results arrive with the Clinical Records module (Phase 2). Every access to this record is audit-logged.</p>
    </div>
  </section>
</div>

<section class="card">
  <div class="card-head"><h2>Upcoming appointments</h2></div>
  <div class="card-body">
    <?php if ($upcoming === []): ?>
      <p class="muted">No upcoming appointments.</p>
    <?php else: ?>
      <ul class="simple-list">
        <?php foreach ($upcoming as $a): ?>
          <li>
            <div>
              <strong><?= e($a['provider_name']) ?></strong> <span class="muted">· <?= e($a['provider_specialty']) ?></span>
              <div class="muted small"><?= e(fdatetime($a['scheduled_at'])) ?> · <?= e($a['organization_name'] ?? '') ?> · <?= e($a['reference']) ?></div>
            </div>
            <span class="<?= e(status_badge($a['status'])) ?>"><?= e(str_replace('_', ' ', $a['status'])) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</section>
