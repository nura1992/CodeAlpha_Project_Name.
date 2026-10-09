<?php $title='Verification #'.(int)$req['id']; ?>
<div class="card">
<h1>Verification #<?= (int)$req['id'] ?> <span class="badge"><?= e($req['status']) ?></span></h1>
<p>Member: <?= e($req['display_name']??'#'.$req['user_id']) ?> — <?= e($req['email']??$req['phone']??'') ?></p>
<p class="muted" style="font-size:13px">Submitted: <?= e($req['submitted_at']) ?><?= $req['reviewed_at']?' · Reviewed: '.e($req['reviewed_at']):'' ?><?= $req['review_reason']?' · Reason: '.e($req['review_reason']):'' ?></p>
<?php if($req['status']==='submitted' || $req['status']==='under_review'): ?>
<div style="margin-top:16px">
<video src="/staff/admin/media/verification/<?= (int)$req['id'] ?>" controls style="max-width:100%;max-height:400px;background:#000;border-radius:8px"></video>
<p class="muted" style="font-size:12px">Video is served via an authorised endpoint and is only visible to staff with verification.review. It is never exposed to members or advertisers.</p>
</div>
<div style="display:flex;gap:8px;margin-top:16px">
<form method="post" action="/staff/admin/verification/<?= (int)$req['id'] ?>/approve"><?= csrf_field() ?><button class="btn btn-success">Approve</button></form>
<form method="post" action="/staff/admin/verification/<?= (int)$req['id'] ?>/reject" style="display:flex;gap:8px;flex:1"><?= csrf_field() ?><input name="reason" placeholder="Rejection reason (required)" required style="flex:1"><button class="btn btn-danger">Reject</button></form>
</div>
<?php endif; ?>
</div>
