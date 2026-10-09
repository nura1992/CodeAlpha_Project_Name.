<?php $title='Ticket #'.(int)$ticket['id']; ?>
<div class="card">
<h1><?= e($ticket['subject']) ?> <span class="badge"><?= e($ticket['status']) ?></span></h1>
<p class="muted" style="font-size:13px"><?= e($ticket['user_email']??'') ?> · <?= e($ticket['category']) ?> · <?= e($ticket['priority']) ?></p>
<form method="post" action="/staff/support/tickets/<?= (int)$ticket['id'] ?>/status" style="display:flex;gap:8px;margin-top:8px">
<?= csrf_field() ?>
<select name="status"><option value="open" <?= $ticket['status']==='open'?'selected':'' ?>>Open</option><option value="in_progress" <?= $ticket['status']==='in_progress'?'selected':'' ?>>In progress</option><option value="resolved" <?= $ticket['status']==='resolved'?'selected':'' ?>>Resolved</option><option value="closed" <?= $ticket['status']==='closed'?'selected':'' ?>>Closed</option></select>
<button class="btn btn-secondary">Update status</button>
</form>
</div>
<?php foreach($messages as $m): ?>
<div class="card" style="<?= $m['is_staff']?'background:#e7f3ff':'' ?>"><strong><?= e($m['display_name']?? ($m['is_staff']?'Staff':'Member')) ?></strong> <span class="muted" style="font-size:12px"><?= e($m['created_at']) ?></span><p style="white-space:pre-wrap"><?= e($m['body']) ?></p></div>
<?php endforeach; ?>
<div class="card">
<form method="post" action="/staff/support/tickets/<?= (int)$ticket['id'] ?>/reply">
<?= csrf_field() ?>
<textarea name="body" rows="3" placeholder="Write a reply…" required></textarea>
<button class="btn btn-primary" style="margin-top:8px">Reply</button>
</form>
</div>
