<h1 class="auth-title">Sign in</h1>
<p class="auth-sub">Access your MedCore workspace.</p>

<form method="post" action="/login" class="form" novalidate>
  <?= csrf_field() ?>
  <label class="field">
    <span>Email address</span>
    <input type="email" name="email" value="<?= e(old('email')) ?>" required autocomplete="email" autofocus>
  </label>
  <label class="field">
    <span>Password</span>
    <input type="password" name="password" required autocomplete="current-password">
  </label>
  <button type="submit" class="btn btn-primary btn-block">Sign in</button>
</form>

<div class="auth-links">
  <a href="/password/forgot">Forgot your password?</a>
  <span>·</span>
  <a href="/register">Create a patient account</a>
</div>

<?php if (config('app.debug')): ?>
<details class="dev-accounts">
  <summary>Demo accounts (dev only)</summary>
  <p>Password for all: <code>MedCore#2026</code></p>
  <ul>
    <li><code>admin@medboat.test</code> — super admin</li>
    <li><code>orgadmin@medboat.test</code> — organization admin</li>
    <li><code>doctor@medboat.test</code> — doctor</li>
    <li><code>nurse@medboat.test</code> — nurse</li>
    <li><code>patient@medboat.test</code> — patient</li>
    <li><code>auditor@medboat.test</code> — auditor</li>
  </ul>
</details>
<?php endif; ?>
