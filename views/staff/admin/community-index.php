<?php $title='Community moderation'; ?>
<div class="card"><h1>Community</h1></div>
<div class="card">
<h3>New announcement</h3>
<form method="post" action="/staff/admin/community/announcement">
<?= csrf_field() ?>
<textarea name="body" rows="3" placeholder="Announcement body…" required></textarea>
<button class="btn btn-primary" style="margin-top:8px">Post announcement</button>
</form>
</div>
<div class="card">
<h3>Guidelines</h3>
<form method="post" action="/staff/admin/community/guidelines">
<?= csrf_field() ?>
<textarea name="guidelines" rows="4"><?= e($guidelines) ?></textarea>
<button class="btn btn-secondary" style="margin-top:8px">Save guidelines</button>
</form>
</div>
<div class="card"><table class="table"><tr><th>Post</th><th>Author</th><th>Status</th><th>Actions</th></tr>
<?php foreach($posts as $p): ?>
<tr><td style="max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e(mb_substr($p['body']??'',0,80)) ?></td><td><?= e($p['display_name']??'#'.$p['user_id']) ?></td><td><?= e($p['status']) ?></td><td>
<?php if($p['status']==='published'): ?><form method="post" action="/staff/admin/community/posts/<?= (int)$p['id'] ?>/remove" style="display:inline"><?= csrf_field() ?><button class="btn btn-danger" style="font-size:11px">Remove</button></form><?php else: ?><form method="post" action="/staff/admin/community/posts/<?= (int)$p['id'] ?>/restore" style="display:inline"><?= csrf_field() ?><button class="btn btn-success" style="font-size:11px">Restore</button></form><?php endif; ?>
<form method="post" action="/staff/admin/community/posts/<?= (int)$p['id'] ?>/pin" style="display:inline"><?= csrf_field() ?><button class="btn btn-secondary" style="font-size:11px"><?= $p['is_pinned']?'Unpin':'Pin' ?></button></form>
</td></tr>
<?php endforeach; ?>
</table></div>
