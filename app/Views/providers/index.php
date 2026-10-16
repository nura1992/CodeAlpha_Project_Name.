<div class="page-head">
  <div>
    <h1>Care providers</h1>
    <p class="muted">Bookable clinicians across your organizations.</p>
  </div>
  <?php if (\MedCore\Core\Authorization::can((int) $currentUser['id'], 'providers.create')): ?>
    <a class="btn btn-primary" href="/providers/create"><?= partial('partials/icon', ['name' => 'plus']) ?> Add provider</a>
  <?php endif; ?>
</div>

<section class="card">
  <div class="card-body">
    <form method="get" action="/providers" class="filters">
      <input type="search" name="q" placeholder="Search name, specialty or location…" value="<?= e($search) ?>">
      <button class="btn btn-secondary" type="submit">Search</button>
      <?php if ($search !== ''): ?><a class="btn btn-ghost" href="/providers">Clear</a><?php endif; ?>
    </form>
  </div>
  <div class="card-body table-wrap">
    <?php if ($result['rows'] === []): ?>
      <?= partial('partials/empty', ['message' => 'No providers found.']) ?>
    <?php else: ?>
    <table class="table">
      <thead><tr><th>Name</th><th>Specialty</th><th>Organization</th><th>Location</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($result['rows'] as $p): ?>
          <tr>
            <td><strong><?= e($p['full_name']) ?></strong></td>
            <td class="muted"><?= e($p['specialty']) ?></td>
            <td class="muted"><?= e($p['organization_name'] ?? '—') ?></td>
            <td class="muted"><?= e($p['location'] ?? '—') ?></td>
            <td><span class="<?= e(status_badge($p['status'])) ?>"><?= e($p['status']) ?></span></td>
            <td class="actions">
              <?php if ($canEdit): ?><a class="btn btn-ghost btn-sm" href="/providers/<?= (int) $p['id'] ?>/edit">Edit</a><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</section>
<?= partial('partials/pagination', ['result' => $result]) ?>
