<div class="page-head">
  <div>
    <h1>Patients</h1>
    <p class="muted">MediID registry — records in your care relationship scope.</p>
  </div>
  <?php if (\MedCore\Core\Authorization::can((int) $currentUser['id'], 'patients.create')): ?>
    <a class="btn btn-primary" href="/patients/create"><?= partial('partials/icon', ['name' => 'plus']) ?> Register patient</a>
  <?php endif; ?>
</div>

<section class="card">
  <div class="card-body">
    <form method="get" action="/patients" class="filters">
      <input type="search" name="q" placeholder="Search name, MediID or phone…" value="<?= e($search) ?>">
      <button class="btn btn-secondary" type="submit">Search</button>
      <?php if ($search !== ''): ?><a class="btn btn-ghost" href="/patients">Clear</a><?php endif; ?>
    </form>
  </div>
  <div class="card-body table-wrap">
    <?php if ($result['rows'] === []): ?>
      <?= partial('partials/empty', ['message' => 'No patient records in your scope.', 'cta' => \MedCore\Core\Authorization::can((int) $currentUser['id'], 'patients.create') ? ['href' => '/patients/create', 'label' => 'Register the first patient'] : null]) ?>
    <?php else: ?>
    <table class="table">
      <thead><tr><th>MediID</th><th>Name</th><th>Sex</th><th>Phone</th><th>Primary organization</th><th>Registered</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($result['rows'] as $p): ?>
          <tr>
            <td><code><?= e($p['patient_code']) ?></code></td>
            <td><strong><?= e($p['last_name'] . ', ' . $p['first_name']) ?></strong></td>
            <td class="muted"><?= e($p['sex'] ? ucfirst($p['sex']) : '—') ?></td>
            <td class="muted"><?= e($p['phone'] ?? '—') ?></td>
            <td class="muted"><?= e($p['organization_name'] ?? '—') ?></td>
            <td class="muted nowrap"><?= e(fdate($p['created_at'])) ?></td>
            <td class="actions"><a class="btn btn-ghost btn-sm" href="/patients/<?= (int) $p['id'] ?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</section>
<?= partial('partials/pagination', ['result' => $result]) ?>
