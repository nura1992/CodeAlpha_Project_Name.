<?php $title='Reports'; ?>
<div class="card"><h1>Escalated reports</h1></div>
<div class="card"><table class="table"><tr><th>ID</th><th>Reporter</th><th>Type</th><th>Reason</th><th>Action</th></tr>
<?php foreach($reports as $r): ?><tr><td>#<?= (int)$r['id'] ?></td><td><?= e($r['reporter_email']) ?></td><td><?= e($r['type']) ?></td><td><?= e($r['reason']) ?></td><td><form method="post" action="/staff/support/reports/escalate"><?= csrf_field() ?><input type="hidden" name="report_id" value="<?= (int)$r['id'] ?>"><button class="btn btn-secondary" style="font-size:12px">Escalate to moderation</button></form></td></tr><?php endforeach; ?>
</table></div>
