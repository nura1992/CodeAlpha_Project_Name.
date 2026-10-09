<?php $title= $editing ? 'Edit staff' : 'Add staff'; ?>
<div class="card">
<h1><?= e($title) ?></h1>
<form method="post" action="<?= $editing ? '/staff/super/staff/'.(int)$editing['id'] : '/staff/super/staff' ?>">
<?= csrf_field() ?>
<div class="form-group"><label>Email</label><input type="email" name="email" value="<?= e($editing['email']??'') ?>" <?= $editing?'':'required' ?>></div>
<div class="form-group"><label>Password <?= $editing?'(leave blank to keep)':'' ?></label><input type="password" name="password" <?= $editing?'':'required' ?>></div>
<div class="form-group"><label>Roles</label>
<?php foreach($roles as $r): $checked = isset($assigned) && in_array($r['id'], $assigned, true); ?>
<label style="font-weight:400;display:flex;gap:8px"><input type="checkbox" name="roles[]" value="<?= (int)$r['id'] ?>" <?= $checked?'checked':'' ?> style="width:auto"> <?= e($r['name']) ?> <span class="muted" style="font-size:12px">(<?= e($r['slug']) ?>)</span></label>
<?php endforeach; ?>
</div>
<button class="btn btn-primary"><?= $editing?'Save':'Create' ?></button>
</form>
<?php if($editing): ?>
<form method="post" action="/staff/super/staff/<?= (int)$editing['id'] ?>/roles" style="margin-top:16px;border-top:1px solid #eee;padding-top:16px">
<?= csrf_field() ?>
<div class="form-group"><label>Quick role slugs (comma)</label><input name="roles" placeholder="admin, finance"></div>
<button class="btn btn-secondary">Assign by slug</button>
</form>
<?php endif; ?>
</div>
