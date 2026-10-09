<?php $title='Log in'; ?>
<div class="card">
<h1>Log in</h1>
<form method="post" action="/login">
<?= csrf_field() ?>
<div class="form-group"><label>Email or phone</label><input name="identifier" value="<?= old('identifier') ?>" required autocomplete="username"></div>
<div class="form-group"><label>Password</label><input type="password" name="password" required autocomplete="current-password"></div>
<button class="btn btn-primary" style="width:100%">Log in</button>
</form>
<p class="muted" style="margin-top:12px"><a href="/forgot-password">Forgot password?</a> · <a href="/register">Create account</a></p>
</div>
