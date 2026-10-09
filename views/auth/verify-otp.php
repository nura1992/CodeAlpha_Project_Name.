<?php $title='Verify account'; ?>
<div class="card">
<h1>Verify your account</h1>
<p class="muted">We sent a 6-digit code to <?= e($pending['destination'] ?? '') ?>. It expires in 10 minutes.</p>
<form method="post" action="/verify-otp">
<?= csrf_field() ?>
<div class="form-group"><label>Verification code</label><input name="code" required pattern="[0-9]{6}" maxlength="6" inputmode="numeric" autocomplete="one-time-code"></div>
<button class="btn btn-primary" style="width:100%">Verify</button>
</form>
</div>
