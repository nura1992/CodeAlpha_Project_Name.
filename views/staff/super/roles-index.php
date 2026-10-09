<?php $title='Roles'; ?>
<div class="card" style="display:flex;justify-content:space-between;align-items:center"><h1 style="margin:0">Roles</h1><a href="/staff/super/roles/create" class="btn btn-primary">Create role</a></div>
<div class="grid" style="gap:12px">
<?php foreach($roles as $r): ?>
<div class="card">
<div style="display:flex;justify-content:space-between;align-items:start">
<div><strong><?= e($r['name']) ?></strong> <span class="muted" style="font-size:12px"><?= e($r['slug']) ?><?= $r['is_system']?' · system':'' ?><?= $r['is_super']?' · super':'' ?></span><br><span class="muted" style="font-size:13px"><?= e($r['description']??'') ?></span><br><span class="muted" style="font-size:12px"><?= (int)$r['members'] ?> members · <?= e($r['dashboard']??'no dashboard') ?></span></div>
<div style="display:flex;gap:6px"><a href="/staff/super/roles/<?= (int)$r['id'] ?>/edit" class="btn btn-secondary" style="font-size:12px">Edit</a></div>
</div>
</div>
<?php endforeach; ?>
</div>
