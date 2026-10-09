<?php $title='Finance — Overview'; ?>
<div class="card"><h1>Finance overview</h1></div>
<div class="kpi">
<div class="card"><strong><?= (int)($summary['rows'][0]['count']??0) ?></strong>Transactions (sample)</div>
<div class="card"><strong><?= e(money($summary['refunded_total']??0)) ?></strong>Refunded</div>
<div class="card"><strong><?= (int)$activeSubs ?></strong>Active subscriptions</div>
</div>
<div class="card"><h3>Recent transactions</h3><table class="table"><tr><th>Ref</th><th>Gateway</th><th>Amount</th><th>Status</th></tr>
<?php foreach($recent as $t): ?><tr><td><a href="/staff/finance/transactions/<?= (int)$t['id'] ?>"><?= e($t['reference']) ?></a></td><td><?= e($t['gateway']) ?></td><td><?= e(money($t['amount'],$t['currency'])) ?></td><td><?= e($t['status']) ?></td></tr><?php endforeach; ?>
</table></div>
