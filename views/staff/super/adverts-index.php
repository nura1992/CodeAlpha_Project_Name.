<?php $title='Adverts'; ?>
<div class="card" style="display:flex;justify-content:space-between;align-items:center"><h1 style="margin:0">Adverts</h1><a href="/staff/super/advertisements/create" class="btn btn-primary">New advert</a></div>
<div class="card"><table class="table"><tr><th>Advertiser</th><th>Type</th><th>Placement</th><th>Status</th><th>Actions</th></tr>
<?php foreach($adverts as $a): ?>
<tr><td><?= e($a['advertiser_name']) ?><br><span class="muted" style="font-size:12px"><?= e($a['headline']??'') ?></span></td><td><?= e($a['type']) ?></td><td><?= e($a['placement']) ?></td><td><?= e($a['status']) ?></td><td>
<?php if($a['status']==='pending'): ?>
<form method="post" action="/staff/super/advertisements/<?= (int)$a['id'] ?>/review" style="display:inline"><?= csrf_field() ?><input type="hidden" name="decision" value="approve"><button class="btn btn-success" style="font-size:12px">Approve</button></form>
<form method="post" action="/staff/super/advertisements/<?= (int)$a['id'] ?>/review" style="display:inline"><?= csrf_field() ?><input type="hidden" name="decision" value="reject"><button class="btn btn-danger" style="font-size:12px">Reject</button></form>
<?php elseif($a['status']==='approved'): ?>
<form method="post" action="/staff/super/advertisements/<?= (int)$a['id'] ?>/review" style="display:inline"><?= csrf_field() ?><input type="hidden" name="decision" value="suspend"><button class="btn btn-secondary" style="font-size:12px">Suspend</button></form>
<?php endif; ?>
</td></tr>
<?php endforeach; ?>
</table></div>
