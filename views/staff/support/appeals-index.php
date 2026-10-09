<?php $title='Appeals'; ?>
<div class="card"><h1>Appeals</h1></div>
<div class="card"><table class="table"><tr><th>ID</th><th>Subject</th><th>User</th><th>Status</th><th>Action</th></tr>
<?php foreach($appeals as $a): ?><tr><td>#<?= (int)$a['id'] ?></td><td><a href="/staff/support/tickets/<?= (int)$a['id'] ?>"><?= e($a['subject']) ?></a></td><td><?= e($a['user_email']??'') ?></td><td><?= e($a['status']) ?></td><td><form method="post" action="/staff/support/appeals/<?= (int)$a['id'] ?>/resolve"><?= csrf_field() ?><button class="btn btn-success" style="font-size:12px">Resolve</button></form></td></tr><?php endforeach; ?>
</table></div>
