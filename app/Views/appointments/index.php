<div class="page-head">
  <div>
    <h1>Appointments</h1>
    <p class="muted">Booking workflow: booked → confirmed → completed (or cancelled / declined / no-show).</p>
  </div>
  <?php if (\MedCore\Core\Authorization::can((int) $currentUser['id'], 'appointments.create')): ?>
    <a class="btn btn-primary" href="/appointments/create"><?= partial('partials/icon', ['name' => 'plus']) ?> Book appointment</a>
  <?php endif; ?>
</div>

<section class="card">
  <div class="card-body">
    <form method="get" action="/appointments" class="filters">
      <select name="status">
        <option value="">All statuses</option>
        <?php foreach ($statuses as $s): ?>
          <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $s))) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="date" name="date" value="<?= e($date) ?>">
      <button class="btn btn-secondary" type="submit">Filter</button>
      <?php if ($status !== '' || $date !== ''): ?><a class="btn btn-ghost" href="/appointments">Clear</a><?php endif; ?>
    </form>
  </div>
  <div class="card-body table-wrap">
    <?php if ($result['rows'] === []): ?>
      <?= partial('partials/empty', ['message' => 'No appointments match.']) ?>
    <?php else: ?>
    <table class="table">
      <thead><tr><th>Reference</th><th>When</th><th>Patient</th><th>Provider</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($result['rows'] as $a): ?>
          <tr>
            <td><code><?= e($a['reference']) ?></code></td>
            <td class="nowrap"><?= e(fdatetime($a['scheduled_at'])) ?><div class="muted small"><?= (int) $a['duration_min'] ?> min</div></td>
            <td><strong><?= e($a['last_name'] . ', ' . $a['first_name']) ?></strong><div class="muted small"><?= e($a['patient_code']) ?></div></td>
            <td><?= e($a['provider_name']) ?><div class="muted small"><?= e($a['provider_specialty']) ?></div></td>
            <td><span class="<?= e(status_badge($a['status'])) ?>"><?= e(str_replace('_', ' ', $a['status'])) ?></span></td>
            <td class="actions">
              <?php
                $transitions = match ($a['status']) {
                    'booked' => ['confirmed' => 'Confirm', 'cancelled' => 'Cancel', 'declined' => 'Decline'],
                    'confirmed' => ['completed' => 'Complete', 'no_show' => 'No-show', 'cancelled' => 'Cancel'],
                    default => [],
                };
              ?>
              <?php foreach ($transitions as $to => $label): ?>
                <?php
                  $perm = $to === 'cancelled' ? 'appointments.cancel' : 'appointments.update';
                  if (!\MedCore\Core\Authorization::can((int) $currentUser['id'], $perm)) continue;
                ?>
                <form method="post" action="/appointments/<?= (int) $a['id'] ?>/status" class="inline" data-confirm="Mark appointment <?= e($a['reference']) ?> as <?= e(str_replace('_', ' ', $to)) ?>?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="status" value="<?= e($to) ?>">
                  <button class="btn btn-ghost btn-sm <?= in_array($to, ['cancelled', 'declined', 'no_show'], true) ? 'danger' : '' ?>" type="submit"><?= e($label) ?></button>
                </form>
              <?php endforeach; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</section>
<?= partial('partials/pagination', ['result' => $result]) ?>
