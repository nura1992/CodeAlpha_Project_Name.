<?php $title='Reset password'; ?>
<div class="card">
<h1>Reset password</h1>
<form method="post" action="/reset-password">
<?= csrf_field() ?>
<div class="form-group"><label>Code</label><input name="code" required pattern="[0-9]{6}" maxlength="6"></div>
<div class="form-group"><label>New password</label><input type="password" name="password" required minlength="8"></div>
<div class="form-group"><label>Confirm password</label><input type="password" name="password_confirmation" required></div>
<button class="btn btn-primary" style="width:100%">Reset password</button>
</form>
</div>
