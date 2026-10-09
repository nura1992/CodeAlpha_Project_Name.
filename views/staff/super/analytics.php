<?php $title='Analytics'; ?>
<div class="card"><h1>Analytics</h1></div>
<div class="kpi">
<div class="card"><strong><?= (int)($overview['users']['total']??0) ?></strong>Users</div>
<div class="card"><strong><?= (int)($overview['matching']['matches_active']??0) ?></strong>Matches</div>
<div class="card"><strong><?= e(money($overview['revenue']['revenue_total']??0)) ?></strong>Revenue</div>
<div class="card"><strong><?= (int)($overview['community']['posts']??0) ?></strong>Posts</div>
</div>
<div class="grid grid-2">
<div class="card"><h3>Gender distribution</h3><table class="table"><tr><th>Gender</th><th>Count</th></tr><?php foreach($gender as $g): ?><tr><td><?= e($g['gender']) ?></td><td><?= (int)$g['n'] ?></td></tr><?php endforeach; ?></table></div>
<div class="card"><h3>Verification funnel</h3><table class="table"><tr><th>Status</th><th>Count</th></tr><?php foreach($funnel as $f): ?><tr><td><?= e($f['verification_status']) ?></td><td><?= (int)$f['n'] ?></td></tr><?php endforeach; ?></table></div>
</div>
