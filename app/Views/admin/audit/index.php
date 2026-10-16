<div class="page-head">
  <div>
    <h1>Audit log</h1>
    <p class="muted">Append-only record of security events, administrative actions and clinical-record access.</p>
  </div>
</div>

<section class="card">
  <div class="card-body">
    <form method="get" action="/admin/audit" class="filters">
      <input type="search" name="q" placeholder="User or entity…" value="<?= e($search) ?>">
      <select name="action">
        <option value="">All actions</option>
        <?php foreach ($actions as $a): ?>
          <option value="<?= e($a) ?>" <?= $action === $a ? 'selected' : '' ?>><?= e($a) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="date" name="date" value="<?= e($date) ?>">
      <button class="btn btn-secondary" type="submit">Filter</button>
      <?php if ($search !== '' || $action !== '' || $date !== ''): ?><a class="btn btn-ghost" href="/admin/audit">Clear</a><?php endif; ?>
    </form>
  </div>
  <div class="card-body table-wrap">
    <?php if ($result['rows'] === []): ?>
      <?= partial('partials/empty', ['message' => 'No audit events match your filters.']) ?>
    <?php else: ?>
    <table class="table">
      <thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Entity</th><th>Details</th><th>IP</th></tr></thead>
      <tbody>
        <?php foreach ($result['rows'] as $log): ?>
          <tr>
            <td class="nowrap"><?= e(fdatetime($log['created_at'])) ?></td>
            <td><?= e($log['user_name'] ?? 'system') ?><?php if (!empty($log['user_email'])): ?><div class="muted small"><?= e($log['user_email']) ?></div><?php endif; ?></td>
            <td><code><?= e($log['action']) ?></code></td>
            <td class="muted nowrap"><?= e($log['entity_type'] . ($log['entity_id'] ? ' #' . $log['entity_id'] : '')) ?></td>
            <td class="muted details-cell"><?= e($log['details'] ?? '') ?></td>
            <td class="muted"><?= e($log['ip'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</section>
<?= partial('partials/pagination', ['result' => $result]) ?>
