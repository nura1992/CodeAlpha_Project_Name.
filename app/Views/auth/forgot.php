<h1 class="auth-title">Reset your password</h1>
<p class="auth-sub">Enter the email address on your account and we'll send a reset link.</p>

<form method="post" action="/password/forgot" class="form" novalidate>
  <?= csrf_field() ?>
  <label class="field">
    <span>Email address</span>
    <input type="email" name="email" value="<?= e(old('email')) ?>" required autocomplete="email" autofocus>
  </label>
  <button type="submit" class="btn btn-primary btn-block">Send reset link</button>
</form>

<div class="auth-links"><a href="/login">&larr; Back to sign in</a></div>
