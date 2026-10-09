<?php $title='Transaction '.e($tx['reference']); ?>
<div class="card">
<h1><?= e($tx['reference']) ?> <span class="badge"><?= e($tx['status']) ?></span></h1>
<p>Gateway: <?= e($tx['gateway']) ?> · Amount: <?= e(money($tx['amount'],$tx['currency'])) ?> · Created: <?= e($tx['created_at']) ?></p>
<p class="muted" style="font-size:12px">Gateway ref: <?= e($tx['gateway_reference']??'—') ?></p>
<?php if($tx['status']==='success'): ?>
<form method="post" action="/staff/finance/transactions/<?= (int)$tx['id'] ?>/refund" style="display:flex;gap:8px;align-items:end;margin-top:12px">
<?= csrf_field() ?>
<div class="form-group" style="flex:1"><label>Refund amount</label><input type="number" step="0.01" name="amount" value="<?= e($tx['amount']) ?>" required></div>
<div class="form-group" style="flex:2"><label>Reason</label><input name="reason" required></div>
<button class="btn btn-danger">Refund</button>
</form>
<?php endif; ?>
</div>
<?php if($refunds): ?>
<div class="card"><h3>Refunds</h3><table class="table"><tr><th>Amount</th><th>Reason</th><th>Status</th><th>When</th></tr>
<?php foreach($refunds as $r): ?><tr><td><?= e(money($r['amount'],$r['currency'])) ?></td><td><?= e($r['reason']) ?></td><td><?= e($r['status']) ?></td><td class="muted" style="font-size:12px"><?= e($r['created_at']) ?></td></tr><?php endforeach; ?>
</table></div>
<?php endif; ?>
