<?php $title='Super Admin — Overview'; ?>
<div class="card"><h1>Super Admin overview</h1></div>
<div class="kpi">
<div class="card"><strong><?= (int)($overview['users']['total']??0) ?></strong><span class="muted">Total users</span></div>
<div class="card"><strong><?= (int)($overview['users']['verified']??0) ?></strong><span class="muted">Verified</span></div>
<div class="card"><strong><?= (int)($overview['matching']['matches_active']??0) ?></strong><span class="muted">Active matches</span></div>
<div class="card"><strong><?= e(money($overview['revenue']['revenue_total']??0)) ?></strong><span class="muted">Revenue</span></div>
</div>
<div class="grid grid-2">
<div class="card"><h3>Sign-ups (14 days)</h3><canvas id="c-signups" height="120"></canvas></div>
<div class="card"><h3>Revenue (14 days)</h3><canvas id="c-revenue" height="120"></canvas></div>
</div>
<script>
const signups=<?= json_encode($signups) ?>, revenue=<?= json_encode($revenue) ?>;
function draw(canvas, data, key){
  const ctx=canvas.getContext('2d'); const w=canvas.width=canvas.offsetWidth, h=canvas.height;
  const max=Math.max(1, ...data.map(d=> d[key]));
  ctx.strokeStyle='#1877f2'; ctx.lineWidth=2; ctx.beginPath();
  data.forEach((d,i)=>{ const x= (i/(data.length-1))* (w-20) +10, y= h-20 - (d[key]/max)*(h-30); if(i===0) ctx.moveTo(x,y); else ctx.lineTo(x,y); });
  ctx.stroke();
}
draw(document.getElementById('c-signups'), signups, 'count');
draw(document.getElementById('c-revenue'), revenue, 'total');
</script>
