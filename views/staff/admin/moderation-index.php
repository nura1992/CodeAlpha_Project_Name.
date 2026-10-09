<?php $title='Moderation'; ?>
<div class="card"><h1>Moderation queue</h1>
<div style="display:flex;gap:8px">
<a href="/staff/admin/moderation?status=open" class="btn <?= $status==='open'?'btn-primary':'btn-secondary' ?>" style="font-size:12px">Open</a>
<a href="/staff/admin/moderation?status=in_review" class="btn <?= $status==='in_review'?'btn-primary':'btn-secondary' ?>" style="font-size:12px">In review</a>
</div>
</div>
<div class="card"><table class="table"><tr><th>ID</th><th>Reporter</th><th>Type</th><th>Reason</th><th>Actions</th></tr>
<?php foreach($reports as $r): ?>
<tr><td>#<?= (int)$r['id'] ?></td><td><?= e($r['reporter_name']??$r['reporter_email']) ?></td><td><?= e($r['type']) ?></td><td><?= e($r['reason']) ?><br><span class="muted" style="font-size:11px"><?= e(mb_substr($r['details']??'',0,80)) ?></span></td><td>
<form method="post" action="/staff/admin/moderation/<?= (int)$r['id'] ?>/resolve" style="display:inline"><?= csrf_field() ?><button class="btn btn-success" style="font-size:11px">Resolve</button></form>
<form method="post" action="/staff/admin/moderation/<?= (int)$r['id'] ?>/dismiss" style="display:inline"><?= csrf_field() ?><button class="btn btn-secondary" style="font-size:11px">Dismiss</button></form>
<form method="post" action="/staff/admin/moderation/<?= (int)$r['id'] ?>/action" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="remove_post"><button class="btn btn-danger" style="font-size:11px">Remove post</button></form>
</td></tr>
<?php endforeach; ?>
</table></div>
