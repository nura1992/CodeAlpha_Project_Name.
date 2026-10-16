<div class="page-head">
  <div>
    <h1>Organizations</h1>
    <p class="muted">Hospitals, clinics, laboratories and pharmacies on the platform.</p>
  </div>
  <?php if (\MedCore\Core\Authorization::can((int) $currentUser['id'], 'organizations.create')): ?>
    <a class="btn btn-primary" href="/admin/organizations/create"><?= partial('partials/icon', ['name' => 'plus']) ?> Register organization</a>
  <?php endif; ?>
</div>

<section class="card">
  <div class="card-body">
    <form method="get" action="/admin/organizations" class="filters">
      <input type="search" name="q" placeholder="Search name, city or state…" value="<?= e($search) ?>">
      <button class="btn btn-secondary" type="submit">Filter</button>
      <?php if ($search !== ''): ?><a class="btn btn-ghost" href="/admin/organizations">Clear</a><?php endif; ?>
    </form>
  </div>
  <div class="card-body table-wrap">
    <?php if ($result['rows'] === []): ?>
      <?= partial('partials/empty', ['message' => 'No organizations found.']) ?>
    <?php else: ?>
    <table class="table">
      <thead><tr><th>Name</th><th>Type</th><th>Location</th><th>Members</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($result['rows'] as $org): ?>
          <tr>
            <td><strong><?= e($org['name']) ?></strong></td>
            <td class="muted"><?= e(ucfirst($org['type'])) ?></td>
            <td class="muted"><?= e(trim(($org['city'] ?? '') . ', ' . ($org['state'] ?? ''), ' ,')) ?></td>
            <td><?= (int) $org['member_count'] ?></td>
            <td><span class="<?= e(status_badge($org['status'])) ?>"><?= e($org['status']) ?></span></td>
            <td class="actions"><a class="btn btn-ghost btn-sm" href="/admin/organizations/<?= (int) $org['id'] ?>">View</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</section>
<?= partial('partials/pagination', ['result' => $result]) ?>
