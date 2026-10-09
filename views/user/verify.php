<?php $title='Verification'; ?>
<div class="card">
<h1>Profile verification</h1>
<p class="muted" style="font-size:13px">Submit a short video so staff can confirm your profile. Videos are stored securely and only visible to authorised reviewers. They are never shown publicly or to other members.</p>
<p><span class="badge" style="background:<?= $verification_status==='approved'?'#42b72a': ($verification_status==='pending'?'#f5a623':'#e4e6eb') ?>;color:<?= $verification_status==='approved'?'#fff':'#050505' ?>"><?= e($verification_status) ?></span> <?php if($verification_status==='approved'): ?>— you are discoverable (if your profile is complete).<?php endif; ?></p>
<?php if(in_array($verification_status,['unverified','rejected'],true)): ?>
<form id="verify-form" enctype="multipart/form-data">
<div class="form-group"><label>Verification video (mp4/webm, max 25 MB)</label><input type="file" name="video" accept="video/*" required></div>
<p class="muted" style="font-size:12px">Tip: record a 10-15 second video clearly showing your face. The badge means we completed our checks — it is not a guarantee of identity or intentions.</p>
<button class="btn btn-primary">Submit for review</button>
</form>
<?php elseif($verification_status==='pending'): ?>
<p class="muted">Your video is under review. You will be notified once a decision is made.</p>
<?php endif; ?>
<?php if($requests): ?>
<div style="margin-top:16px">
<h3>History</h3>
<table class="table"><tr><th>Date</th><th>Status</th><th>Reason</th></tr>
<?php foreach($requests as $r): ?><tr><td><?= e($r['submitted_at']) ?></td><td><?= e($r['status']) ?></td><td><?= e($r['review_reason']??'') ?></td></tr><?php endforeach; ?>
</table>
</div>
<?php endif; ?>
</div>
<script>
document.getElementById('verify-form')?.addEventListener('submit', async e=>{
  e.preventDefault();
  const fd=new FormData(e.target);
  try{
    const res=await fetch('/api/profile/verification',{method:'POST', headers:{'X-CSRF-Token': window.CSRF_TOKEN}, body: fd, credentials:'same-origin'});
    const j=await res.json(); if(!res.ok) throw new Error(j.error||'Upload failed');
    toast('Submitted for review'); location.reload();
  }catch(err){ toast(err.message); }
});
</script>
