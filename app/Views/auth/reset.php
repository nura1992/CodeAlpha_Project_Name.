<h1 class="auth-title">Choose a new password</h1>
<p class="auth-sub">For <?= e($email ?? 'your account') ?>.</p>

<form method="post" action="/password/reset" class="form" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="token" value="<?= e($token ?? '') ?>">
  <input type="hidden" name="email" value="<?= e($email ?? '') ?>">
  <label class="field">
    <span>New password <em class="opt">min <?= (int) config('app.auth.password_min_length', 10) ?> characters</em></span>
    <input type="password" name="password" required autocomplete="new-password" autofocus>
  </label>
  <label class="field">
    <span>Confirm new password</span>
    <input type="password" name="password_confirmation" required autocomplete="new-password">
  </label>
  <button type="submit" class="btn btn-primary btn-block">Update password</button>
</form>

<div class="auth-links"><a href="/login">&larr; Back to sign in</a></div>
