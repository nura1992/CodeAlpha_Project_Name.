<?php $title='Subscription plans'; ?>
<div class="card" style="display:flex;justify-content:space-between;align-items:center"><h1 style="margin:0">Subscription plans</h1></div>
<div class="card">
<h3>Create plan</h3>
<form method="post" action="/staff/super/plans">
<?= csrf_field() ?>
<div class="grid grid-2"><div class="form-group"><label>Name</label><input name="name" required></div><div class="form-group"><label>Slug</label><input name="slug" placeholder="auto from name"></div></div>
<div class="grid grid-2"><div class="form-group"><label>Price</label><input type="number" step="0.01" name="price" value="0" required></div><div class="form-group"><label>Currency</label><input name="currency" value="NGN"></div></div>
<div class="form-group"><label>Interval</label><select name="interval"><option value="month">Month</option><option value="year">Year</option></select></div>
<div class="form-group"><label>Description</label><textarea name="description" rows="2"></textarea></div>
<div class="form-group"><label>Features (comma separated)</label><input name="features" placeholder="Unlimited likes, See who liked you"></div>
<button class="btn btn-primary">Create plan</button>
</form>
</div>
<div class="card">
<table class="table"><tr><th>Name</th><th>Price</th><th>Interval</th><th>Active</th><th>Actions</th></tr>
<?php foreach($plans as $p): ?>
<tr><td><?= e($p['name']) ?><br><span class="muted" style="font-size:12px"><?= e($p['slug']) ?></span></td><td><?= e(money($p['price'],$p['currency'])) ?></td><td><?= e($p['interval']) ?></td><td><?= $p['is_active']?'Yes':'No' ?></td><td>
<form method="post" action="/staff/super/plans/<?= (int)$p['id'] ?>/toggle" style="display:inline"><?= csrf_field() ?><button class="btn btn-secondary" style="font-size:12px"><?= $p['is_active']?'Disable':'Enable' ?></button></form>
</td></tr>
<?php endforeach; ?>
</table>
</div>
