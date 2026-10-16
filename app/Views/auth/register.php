<h1 class="auth-title">Create your account</h1>
<p class="auth-sub">Register once — your MediID health identity works across the Medboat ecosystem.</p>

<form method="post" action="/register" class="form" novalidate>
  <?= csrf_field() ?>
  <label class="field">
    <span>Full name</span>
    <input type="text" name="name" value="<?= e(old('name')) ?>" class="<?= has_error('name') ?>" required autocomplete="name">
  </label>
  <div class="form-row">
    <label class="field">
      <span>Email address</span>
      <input type="email" name="email" value="<?= e(old('email')) ?>" required autocomplete="email">
    </label>
    <label class="field">
      <span>Phone <em class="opt">optional</em></span>
      <input type="tel" name="phone" value="<?= e(old('phone')) ?>" autocomplete="tel">
    </label>
  </div>
  <div class="form-row">
    <label class="field">
      <span>Date of birth <em class="opt">optional</em></span>
      <input type="date" name="date_of_birth" value="<?= e(old('date_of_birth')) ?>" max="<?= date('Y-m-d') ?>">
    </label>
    <label class="field">
      <span>Sex <em class="opt">optional</em></span>
      <select name="sex">
        <option value="">—</option>
        <?php foreach (['female', 'male', 'other'] as $s): ?>
          <option value="<?= $s ?>" <?= old('sex') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </div>
  <div class="form-row">
    <label class="field">
      <span>Password <em class="opt">min <?= (int) config('app.auth.password_min_length', 10) ?> characters</em></span>
      <input type="password" name="password" required autocomplete="new-password" data-min="<?= (int) config('app.auth.password_min_length', 10) ?>">
    </label>
    <label class="field">
      <span>Confirm password</span>
      <input type="password" name="password_confirmation" required autocomplete="new-password">
    </label>
  </div>
  <label class="check">
    <input type="checkbox" name="consent" value="1" required>
    <span>I consent to MedCore creating my MediID health identity and storing the information I provide, so care providers can serve me. I can withdraw consent at any time.</span>
  </label>
  <button type="submit" class="btn btn-primary btn-block">Create account</button>
</form>

<div class="auth-links">
  <a href="/login">Already have an account? Sign in</a>
</div>
