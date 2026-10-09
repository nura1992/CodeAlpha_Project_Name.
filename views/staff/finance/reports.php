<?php $title='Reports'; ?>
<div class="card"><h1>Reports</h1>
<form method="get" style="display:flex;gap:8px"><input type="date" name="from" value="<?= e($from) ?>"><input type="date" name="to" value="<?= e($to) ?>"><button class="btn btn-secondary">Filter</button><a href="/staff/finance/reports/export?from=<?= e($from) ?>&to=<?= e($to) ?>" class="btn btn-primary">Export CSV</a></form>
</div>
<div class="card"><table class="table"><tr><th>Gateway</th><th>Status</th><th>Count</th><th>Total</th></tr>
<?php foreach($summary['rows'] as $r): ?><tr><td><?= e($r['gateway']) ?></td><td><?= e($r['status']) ?></td><td><?= (int)$r['count'] ?></td><td><?= e(money($r['total'],$r['currency'])) ?></td></tr><?php endforeach; ?>
</table><p class="muted" style="font-size:13px">Refunded total: <?= e(money($summary['refunded_total'])) ?></p></div>
