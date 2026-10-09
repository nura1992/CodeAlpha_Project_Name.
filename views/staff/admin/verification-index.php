<?php $title='Verification queue'; ?>
<div class="card"><h1>Verification queue</h1>
<div style="display:flex;gap:8px">
<a href="/staff/admin/verification" class="btn <?= !$status?'btn-primary':'btn-secondary' ?>" style="font-size:12px">All</a>
<a href="/staff/admin/verification?status=submitted" class="btn <?= $status==='submitted'?'btn-primary':'btn-secondary' ?>" style="font-size:12px">Submitted</a>
<a href="/staff/admin/verification?status=approved" class="btn <?= $status==='approved'?'btn-primary':'btn-secondary' ?>" style="font-size:12px">Approved</a>
<a href="/staff/admin/verification?status=rejected" class="btn <?= $status==='rejected'?'btn-primary':'btn-secondary' ?>" style="font-size:12px">Rejected</a>
</div>
</div>
<div class="card"><table class="table"><tr><th>ID</th><th>Member</th><th>Status</th><th>Submitted</th></tr>
<?php foreach($queue as $r): ?>
<tr><td><a href="/staff/admin/verification/<?= (int)$r['id'] ?>">#<?= (int)$r['id'] ?></a></td><td><?= e($r['display_name']??'#'.$r['user_id']) ?><br><span class="muted" style="font-size:12px"><?= e($r['email']??$r['phone']??'') ?></span></td><td><?= e($r['status']) ?></td><td class="muted" style="font-size:12px"><?= e($r['submitted_at']) ?></td></tr>
<?php endforeach; ?>
</table></div>
