<?php $title='Subscriptions'; ?>
<div class="card"><h1>Subscriptions</h1></div>
<div class="card"><table class="table"><tr><th>User</th><th>Plan</th><th>Status</th><th>Period end</th></tr>
<?php foreach($subs as $s): ?><tr><td><?= e($s['email']) ?></td><td><?= e($s['plan_name']) ?></td><td><?= e($s['status']) ?></td><td><?= e($s['current_period_end']) ?></td></tr><?php endforeach; ?>
</table></div>
