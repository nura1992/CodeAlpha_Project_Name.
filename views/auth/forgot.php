<?php $title='Forgot password'; ?>
<div class="card">
<h1>Forgot password</h1>
<form method="post" action="/forgot-password">
<?= csrf_field() ?>
<div class="form-group"><label>Email or phone</label><input name="identifier" required></div>
<button class="btn btn-primary" style="width:100%">Send reset code</button>
</form>
<p class="muted" style="margin-top:12px"><a href="/login">Back to login</a></p>
</div>
