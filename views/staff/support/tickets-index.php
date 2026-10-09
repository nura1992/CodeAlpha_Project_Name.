<?php $title='Tickets'; ?>
<div class="card"><h1>Tickets</h1></div>
<div class="card"><table class="table"><tr><th>ID</th><th>Subject</th><th>User</th><th>Status</th><th>Updated</th></tr>
<?php foreach($tickets as $t): ?><tr><td>#<?= (int)$t['id'] ?></td><td><a href="/staff/support/tickets/<?= (int)$t['id'] ?>"><?= e($t['subject']) ?></a></td><td><?= e($t['user_email']??'') ?></td><td><?= e($t['status']) ?></td><td class="muted" style="font-size:12px"><?= e($t['updated_at']) ?></td></tr><?php endforeach; ?>
</table></div>
