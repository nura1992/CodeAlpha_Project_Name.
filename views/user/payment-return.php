<?php $title='Payment'; ?>
<div class="card" id="payment-result" style="text-align:center;padding:32px"><h1>Verifying payment…</h1><p class="muted">Please wait while we confirm your payment with the gateway.</p></div>
<script>
(async function(){
  const ref=new URLSearchParams(location.search).get('reference') || '<?= e($reference) ?>';
  const el=document.getElementById('payment-result');
  if(!ref){ el.innerHTML='<h1>No reference</h1>'; return; }
  // Poll verification (gateway verification is server-side; this just checks our transaction)
  for(let i=0;i<6;i++){
    try{
      const data=await api('/api/payments/verify?reference='+encodeURIComponent(ref));
      const tx=data.transaction;
      if(tx && tx.status==='success'){
        el.innerHTML='<h1 style="color:#42b72a">Payment successful ✓</h1><p>Your subscription is now active.</p><p><a href="/subscribe" class="btn btn-primary">View subscription</a> <a href="/community" class="btn btn-secondary">Go to community</a></p>';
        return;
      }
      if(tx && tx.status==='failed'){
        el.innerHTML='<h1 style="color:#e41e3f">Payment failed</h1><p>Please try again or contact support.</p><p><a href="/subscribe" class="btn btn-primary">Try again</a></p>';
        return;
      }
    }catch(e){}
    await new Promise(r=> setTimeout(r, 2000));
  }
  el.innerHTML='<h1>Payment pending</h1><p class="muted">Your payment is still being verified. You will be notified once confirmed.</p><p><a href="/subscribe" class="btn btn-secondary">View subscription</a></p>';
})();
</script>
