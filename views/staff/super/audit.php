<?php $title='Audit log'; ?>
<div class="card"><h1>Audit log</h1>
<form method="get" style="display:flex;gap:8px;margin-bottom:12px"><input name="q" value="<?= e($q) ?>" placeholder="Search action or target"><button class="btn btn-secondary">Search</button></form>
<table class="table"><tr><th>When</th><th>Actor</th><th>Action</th><th>Target</th><th>Details</th></tr>
<?php foreach($logs as $l): ?>
<tr><td class="muted" style="font-size:12px"><?= e($l['created_at']) ?></td><td style="font-size:12px"><?= e($l['actor_email']?? ('#'.$l['actor_id'])) ?></td><td><code style="font-size:11px"><?= e($l['action']) ?></code></td><td style="font-size:12px"><?= e($l['target_type']??'') ?> <?= e($l['target_id']??'') ?></td><td class="muted" style="font-size:11px;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e(mb_substr($l['details']??'',0,120)) ?></td></tr>
<?php endforeach; ?>
</table>
</div>
