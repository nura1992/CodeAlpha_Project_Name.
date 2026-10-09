<?php $title='Staff accounts'; ?>
<div class="card" style="display:flex;justify-content:space-between;align-items:center"><h1 style="margin:0">Staff accounts</h1><a href="/staff/super/staff/create" class="btn btn-primary">Add staff</a></div>
<div class="card">
<table class="table"><tr><th>Email</th><th>Status</th><th>Roles</th><th>Actions</th></tr>
<?php foreach($staff as $u): ?>
<tr>
<td><?= e($u['email']) ?></td>
<td><?= e($u['status']) ?></td>
<td class="muted" style="font-size:12px"><?= e($u['roles']??'') ?></td>
<td><a href="/staff/super/staff/<?= (int)$u['id'] ?>/edit" class="btn btn-secondary" style="font-size:12px">Edit</a>
<form method="post" action="/staff/super/staff/<?= (int)$u['id'] ?>/<?= $u['status']==='suspended'?'activate':'suspend' ?>" style="display:inline"><?= csrf_field() ?><button class="btn btn-secondary" style="font-size:12px"><?= $u['status']==='suspended'?'Activate':'Suspend' ?></button></form>
</td>
</tr>
<?php endforeach; ?>
</table>
</div>
