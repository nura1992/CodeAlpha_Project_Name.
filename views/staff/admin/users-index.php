<?php $title='Users'; ?>
<div class="card"><h1>Users</h1><form method="get" style="display:flex;gap:8px"><input name="q" value="<?= e($q) ?>" placeholder="Search email or name"><button class="btn btn-secondary">Search</button></form></div>
<div class="card"><table class="table"><tr><th>ID</th><th>Display name</th><th>Email</th><th>Status</th><th>Verification</th></tr>
<?php foreach($users as $u): ?>
<tr><td><?= (int)$u['id'] ?></td><td><a href="/staff/admin/users/<?= (int)$u['id'] ?>"><?= e($u['display_name']??'—') ?></a></td><td><?= e($u['email']??$u['phone']??'—') ?></td><td><?= e($u['status']) ?></td><td><?= e($u['verification_status']??'—') ?></td></tr>
<?php endforeach; ?>
</table></div>
