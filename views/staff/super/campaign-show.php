<?php $title=e($campaign['name']); ?>
<div class="card"><h1><?= e($campaign['name']) ?> <span class="badge"><?= e($campaign['status']) ?></span></h1><p class="muted"><?= e($campaign['objective']??'') ?></p>
<p class="muted" style="font-size:13px">Budget: <?= $campaign['budget']!==null? e(money($campaign['budget'],$campaign['currency'])):'—' ?> · Promo: <?= e($campaign['promo_code']??'—') ?> · <?= (int)$campaign['redemptions'] ?> redemptions · <?= (int)$campaign['ad_impressions'] ?> impressions · <?= (float)$campaign['ctr'] ?>% CTR</p>
</div>
<div class="card">
<h3>Edit</h3>
<form method="post" action="/staff/super/campaigns/<?= (int)$campaign['id'] ?>">
<?= csrf_field() ?>
<div class="form-group"><label>Name</label><input name="name" value="<?= e($campaign['name']) ?>"></div>
<div class="form-group"><label>Status</label><select name="status"><option value="draft" <?= $campaign['status']==='draft'?'selected':'' ?>>Draft</option><option value="active" <?= $campaign['status']==='active'?'selected':'' ?>>Active</option><option value="paused" <?= $campaign['status']==='paused'?'selected':'' ?>>Paused</option><option value="completed" <?= $campaign['status']==='completed'?'selected':'' ?>>Completed</option></select></div>
<button class="btn btn-primary">Save</button>
</form>
</div>
