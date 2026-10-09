<?php $title='Checkout'; $ref=$transaction['reference']??''; ?>
<div class="card" style="max-width:480px;margin:24px auto;text-align:center;padding:32px">
<h1>Mock checkout</h1>
<p class="muted">Reference: <code><?= e($ref) ?></code></p>
<p>Amount: <?= e(money($transaction['amount']??0,$transaction['currency']??'NGN')) ?></p>
<p class="muted" style="font-size:13px">This is a simulated payment page used when PAYMENT_MOCK=1. Choose an outcome to test the full verify → subscription flow.</p>
<div style="display:flex;gap:12px;justify-content:center;margin-top:16px">
<button class="btn btn-success" onclick="complete('success')">Pay (success)</button>
<button class="btn btn-danger" onclick="complete('failed')">Fail</button>
</div>
</div>
<script>
async function complete(status){
  await fetch('/api/payments/verify?reference='+encodeURIComponent('<?= e($ref) ?>'), {headers:{'X-CSRF-Token': window.CSRF_TOKEN}});
  // Record mock outcome then verify
  await fetch('/api/payments/verify?reference='+encodeURIComponent('<?= e($ref) ?>'));
  // Use server helper via direct cache file is not needed; mock gateway reads via PaymentService::mockLookup which is populated by client? Instead simulate via webhook
  try{
    await fetch('/api/webhooks/paystack', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({reference:'<?= e($ref) ?>', status}) });
  }catch{}
  location.href='/payment/return?reference='+encodeURIComponent('<?= e($ref) ?>');
}
</script>
