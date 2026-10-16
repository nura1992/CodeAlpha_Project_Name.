<div class="page-head">
  <div>
    <h1>Users</h1>
    <p class="muted"><?= (int) $result['total'] ?> account<?= $result['total'] === 1 ? '' : 's' ?> in your scope.</p>
  </div>
  <?php if (\MedCore\Core\Authorization::can((int) $currentUser['id'], 'users.create')): ?>
    <a class="btn btn-primary" href="/admin/users/create"><?= partial('partials/icon', ['name' => 'plus']) ?> New user</a>
  <?php endif; ?>
</div>

<section class="card">
  <div class="card-body">
    <form method="get" action="/admin/users" class="filters">
      <input type="search" name="q" placeholder="Search name or email…" value="<?= e($search) ?>">
      <select name="role">
        <option value="">All roles</option>
        <?php foreach ($roles as $role): ?>
          <option value="<?= (int) $role['id'] ?>" <?= (int) ($activeRole ?? 0) === (int) $role['id'] ? 'selected' : '' ?>><?= e($role['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-secondary" type="submit">Filter</button>
      <?php if ($search !== '' || $activeRole): ?><a class="btn btn-ghost" href="/admin/users">Clear</a><?php endif; ?>
    </form>
  </div>
  <div class="card-body table-wrap">
    <?php if ($result['rows'] === []): ?>
      <?= partial('partials/empty', ['message' => 'No users match your filters.']) ?>
    <?php else: ?>
    <table class="table">
      <thead><tr><th>Name</th><th>Email</th><th>Roles</th><th>Status</th><th>Last login</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($result['rows'] as $u): ?>
          <tr>
            <td><strong><?= e($u['name']) ?></strong></td>
            <td class="muted"><?= e($u['email']) ?></td>
            <td>
              <?php foreach ($u['roles'] as $r): ?>
                <span class="badge badge-info"><?= e($r['name']) ?></span>
              <?php endforeach; ?>
            </td>
            <td><span class="<?= e(status_badge($u['status'])) ?>"><?= e($u['status']) ?></span></td>
            <td class="muted nowrap"><?= e(fdate($u['last_login_at'], 'j M Y, g:ia')) ?></td>
            <td class="actions">
              <?php if (\MedCore\Core\Authorization::can((int) $currentUser['id'], 'users.update')): ?>
                <a class="btn btn-ghost btn-sm" href="/admin/users/<?= (int) $u['id'] ?>/edit">Edit</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</section>
<?= partial('partials/pagination', ['result' => $result]) ?>
