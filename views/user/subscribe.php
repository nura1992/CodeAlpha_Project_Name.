<?php $title='Subscription'; ?>
<div class="card"><h1>Subscription</h1>
<?php if($subscription): ?><div style="background:#e7f3ff;padding:12px;border-radius:8px;margin-bottom:12px"><strong>Active:</strong> <?= e($subscription['plan_name']) ?> · <?= e($subscription['interval']) ?> · ends <?= e($subscription['current_period_end']) ?> <button class="btn btn-secondary" id="btn-cancel" style="margin-left:12px">Cancel</button></div><?php endif; ?>
</div>
<div class="grid grid-3">
<?php foreach($plans as $plan): ?>
<div class="card" style="text-align:center">
<h3><?= e($plan['name']) ?></h3><p class="muted"><?= e($plan['description']??'') ?></p>
<div style="font-size:24px;font-weight:800;margin:8px 0"><?= e(money($plan['price'],$plan['currency'])) ?> <span style="font-size:13px;font-weight:400" class="muted">/ <?= e($plan['interval']) ?></span></div>
<?php if(!empty($plan['features'])): ?><ul style="text-align:left;font-size:13px"><?php foreach($plan['features'] as $f): ?><li><?= e($f) ?></li><?php endforeach; ?></ul><?php endif; ?>
<div style="margin-top:12px">
<select class="gateway-select" data-plan="<?= (int)$plan['id'] ?>"><option value="">Select gateway</option><?php foreach($gateways as $gw): ?><option value="<?= e($gw['name']) ?>"><?= e(ucfirst($gw['name'])) ?> (<?= e($gw['mode']) ?>)</option><?php endforeach; ?></select>
<button class="btn btn-primary btn-subscribe" data-plan="<?= (int)$plan['id'] ?>" style="margin-top:8px;width:100%">Subscribe</button>
</div>
</div>
<?php endforeach; ?>
</div>
<?php if(empty($plans)): ?><div class="card muted" style="text-align:center;padding:24px">No plans available.</div><?php endif; ?>
<script>
document.querySelectorAll('.btn-subscribe').forEach(btn=> btn.addEventListener('click', async ()=>{
  const planId=parseInt(btn.dataset.plan), sel=document.querySelector('.gateway-select[data-plan="'+planId+'"]'), gateway=sel?.value;
  if(!gateway) return toast('Select a payment gateway');
  btn.disabled=true;
  try{
    const data=await api('/api/payments/initialize',{method:'POST', body:{plan_id: planId, gateway}});
    if(data.authorization_url) location.href=data.authorization_url;
    else toast('Checkout URL not returned');
  }catch(e){ toast(e.message); btn.disabled=false; }
}));
document.getElementById('btn-cancel')?.addEventListener('click', async ()=>{
  if(!confirm('Cancel subscription?')) return;
  try{ await api('/api/payments/cancel-subscription',{method:'POST'}); toast('Cancelled'); location.reload(); }catch(e){ toast(e.message); }
});
</script>
