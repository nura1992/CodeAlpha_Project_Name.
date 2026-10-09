<?php $title='Campaigns'; ?>
<div class="card" style="display:flex;justify-content:space-between;align-items:center"><h1 style="margin:0">Campaigns</h1><a href="/staff/super/campaigns/create" class="btn btn-primary">New campaign</a></div>
<div class="card"><table class="table"><tr><th>Name</th><th>Status</th><th>Budget</th><th>Promo</th><th>Stats</th></tr>
<?php foreach($campaigns as $c): ?>
<tr><td><a href="/staff/super/campaigns/<?= (int)$c['id'] ?>"><?= e($c['name']) ?></a></td><td><?= e($c['status']) ?></td><td><?= $c['budget']!==null? e(money($c['budget'],$c['currency'])):'—' ?></td><td><?= e($c['promo_code']??'—') ?></td><td class="muted" style="font-size:12px"><?= (int)$c['redemptions'] ?> redemptions · <?= (int)$c['ad_impressions'] ?> imp · <?= (float)$c['ctr'] ?>% CTR</td></tr>
<?php endforeach; ?>
</table></div>
