<?php $isPatient = \MedCore\Core\Authorization::hasRole((int) $currentUser['id'], 'patient'); ?>

<div class="page-head">
  <div>
    <h1>Welcome back, <?= e(explode(' ', $currentUser['name'])[0]) ?></h1>
    <p class="muted"><?= date('l, j F Y') ?> — here's what's happening across your MedCore workspace.</p>
  </div>
  <?php if (\MedCore\Core\Authorization::can((int) $currentUser['id'], 'appointments.create')): ?>
    <a class="btn btn-primary" href="/appointments/create"><?= partial('partials/icon', ['name' => 'plus']) ?> Book appointment</a>
  <?php endif; ?>
</div>

<?php if (!$isPatient && array_filter($stats)): ?>
<div class="stat-grid" id="statGrid">
  <?php if ($stats['users'] !== null): ?>
    <a class="stat-card" href="/admin/users"><span class="stat-num"><?= (int) $stats['users'] ?></span><span class="stat-label">Users</span></a>
  <?php endif; ?>
  <?php if ($stats['organizations'] !== null): ?>
    <a class="stat-card" href="/admin/organizations"><span class="stat-num"><?= (int) $stats['organizations'] ?></span><span class="stat-label">Organizations</span></a>
  <?php endif; ?>
  <?php if ($stats['patients'] !== null): ?>
    <a class="stat-card" href="/patients"><span class="stat-num"><?= (int) $stats['patients'] ?></span><span class="stat-label">Patients</span></a>
  <?php endif; ?>
  <?php if ($stats['providers'] !== null): ?>
    <a class="stat-card" href="/providers"><span class="stat-num"><?= (int) $stats['providers'] ?></span><span class="stat-label">Providers</span></a>
  <?php endif; ?>
  <?php if ($stats['today'] !== null): ?>
    <a class="stat-card" href="/appointments"><span class="stat-num"><?= (int) $stats['today'] ?></span><span class="stat-label">Appointments today</span></a>
  <?php endif; ?>
  <?php if ($stats['failed_logins'] !== null): ?>
    <a class="stat-card warn" href="/admin/audit"><span class="stat-num"><?= (int) $stats['failed_logins'] ?></span><span class="stat-label">Failed logins (24h)</span></a>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($isPatient): ?>
  <div class="grid-2">
    <section class="card">
      <div class="card-head"><h2>My health record</h2></div>
      <div class="card-body">
        <?php if ($myPatientRecord): ?>
          <p><strong><?= e($myPatientRecord['first_name'] . ' ' . $myPatientRecord['last_name']) ?></strong></p>
          <p class="muted">MediID: <code><?= e($myPatientRecord['patient_code']) ?></code></p>
          <?php if ($myPatientRecord['blood_group']): ?><p class="muted">Blood group: <?= e($myPatientRecord['blood_group']) ?></p><?php endif; ?>
          <a class="btn btn-secondary btn-sm" href="/patients/<?= (int) $myPatientRecord['id'] ?>">Open record</a>
        <?php else: ?>
          <p class="muted">No linked health record yet — contact your care organization.</p>
        <?php endif; ?>
      </div>
    </section>
    <section class="card">
      <div class="card-head"><h2>Upcoming appointments</h2><a class="btn btn-ghost btn-sm" href="/appointments/create">Book</a></div>
      <div class="card-body">
        <?php if ($upcoming === []): ?>
          <p class="muted">No upcoming appointments.</p>
        <?php else: ?>
          <ul class="simple-list">
            <?php foreach ($upcoming as $a): ?>
              <li>
                <div>
                  <strong><?= e($a['provider_name']) ?></strong>
                  <span class="muted"> · <?= e($a['provider_specialty']) ?></span>
                  <div class="muted small"><?= e(fdatetime($a['scheduled_at'])) ?> · <?= e($a['organization_name'] ?? '') ?></div>
                </div>
                <span class="<?= e(status_badge($a['status'])) ?>"><?= e(str_replace('_', ' ', $a['status'])) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </section>
  </div>
<?php else: ?>
  <div class="grid-2">
    <section class="card chart-card">
      <div class="card-head"><h2>Appointments — last 14 days</h2></div>
      <div class="card-body">
        <div class="bar-chart" id="apptChart" role="img" aria-label="Appointments per day chart"></div>
      </div>
    </section>
    <section class="card">
      <div class="card-head"><h2>Today's appointments</h2><a class="btn btn-ghost btn-sm" href="/appointments">All appointments</a></div>
      <div class="card-body">
        <?php if ($today === []): ?>
          <p class="muted">Nothing scheduled for today.</p>
        <?php else: ?>
          <ul class="simple-list">
            <?php foreach (array_slice($today, 0, 8) as $a): ?>
              <li>
                <div>
                  <strong><?= e(date('g:ia', strtotime($a['scheduled_at']))) ?></strong>
                  — <?= e($a['first_name'] . ' ' . $a['last_name']) ?>
                  <div class="muted small"><?= e($a['provider_name']) ?></div>
                </div>
                <span class="<?= e(status_badge($a['status'])) ?>"><?= e(str_replace('_', ' ', $a['status'])) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </section>
  </div>
<?php endif; ?>

<?php if ($can['audits'] && $recentAudit !== []): ?>
<section class="card">
  <div class="card-head"><h2>Latest security activity</h2><a class="btn btn-ghost btn-sm" href="/admin/audit">Full audit log</a></div>
  <div class="card-body table-wrap">
    <table class="table">
      <thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Entity</th><th>IP</th></tr></thead>
      <tbody>
        <?php foreach ($recentAudit as $log): ?>
          <tr>
            <td class="nowrap"><?= e(fdatetime($log['created_at'])) ?></td>
            <td><?= e($log['user_name'] ?? 'system') ?></td>
            <td><code><?= e($log['action']) ?></code></td>
            <td class="muted"><?= e($log['entity_type'] . ($log['entity_id'] ? ' #' . $log['entity_id'] : '')) ?></td>
            <td class="muted"><?= e($log['ip']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>
