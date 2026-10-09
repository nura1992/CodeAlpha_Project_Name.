<?php $title= $editing ? 'Edit role' : 'Create role'; $permsByCat=[]; foreach($catalog as $slug=>$meta){ $permsByCat[$meta['category']][]=['slug'=>$slug]+$meta; } ?>
<div class="card">
<h1><?= e($title) ?></h1>
<form method="post" action="<?= $editing ? '/staff/super/roles/'.(int)$editing['id'] : '/staff/super/roles' ?>">
<?= csrf_field() ?>
<div class="grid grid-2">
<div class="form-group"><label>Name</label><input name="name" value="<?= e($editing['name']??'') ?>" required></div>
<div class="form-group"><label>Slug</label><input name="slug" value="<?= e($editing['slug']??'') ?>" <?= $editing?'readonly':'' ?> placeholder="verification_officer"></div>
</div>
<div class="form-group"><label>Dashboard</label><select name="dashboard"><option value="">None</option><option value="super_admin" <?= ($editing['dashboard']??'')==='super_admin'?'selected':'' ?>>Super Admin</option><option value="admin" <?= ($editing['dashboard']??'')==='admin'?'selected':'' ?>>Admin</option><option value="finance" <?= ($editing['dashboard']??'')==='finance'?'selected':'' ?>>Finance</option><option value="support" <?= ($editing['dashboard']??'')==='support'?'selected':'' ?>>Support</option></select></div>
<div class="form-group"><label>Description</label><input name="description" value="<?= e($editing['description']??'') ?>"></div>
<?php foreach($permsByCat as $cat=>$items): ?>
<fieldset style="border:1px solid #eee;border-radius:8px;padding:12px;margin-bottom:12px"><legend style="font-weight:700;font-size:13px"><?= e(ucfirst($cat)) ?></legend>
<?php foreach($items as $p): $checked=in_array($p['slug'],$assigned,true); ?>
<label style="font-weight:400;display:flex;gap:8px;align-items:start;margin-bottom:6px"><input type="checkbox" name="permissions[]" value="<?= e($p['slug']) ?>" <?= $checked?'checked':'' ?> style="width:auto;margin-top:4px"><span><strong style="font-size:13px"><?= e($p['name']) ?></strong> <code style="font-size:11px;background:#f0f2f5;padding:1px 6px;border-radius:4px"><?= e($p['slug']) ?></code><br><span class="muted" style="font-size:12px"><?= e($p['description']) ?></span></span></label>
<?php endforeach; ?>
</fieldset>
<?php endforeach; ?>
<button class="btn btn-primary">Save role</button>
</form>
</div>
