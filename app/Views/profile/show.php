<div class="page-head">
  <div>
    <h1>My profile</h1>
    <p class="muted">Account details, roles and credential management.</p>
  </div>
</div>

<div class="grid-2">
  <section class="card">
    <div class="card-head"><h2>Account details</h2></div>
    <div class="card-body">
      <form method="post" action="/profile" class="form">
        <?= csrf_field() ?>
        <label class="field"><span>Full name</span><input type="text" name="name" value="<?= e(old('name', $user['name'])) ?>" required maxlength="120"></label>
        <label class="field"><span>Email address</span><input type="email" name="email" value="<?= e(old('email', $user['email'])) ?>" required></label>
        <label class="field"><span>Phone</span><input type="tel" name="phone" value="<?= e(old('phone', $user['phone'] ?? '')) ?>"></label>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Save changes</button></div>
      </form>
    </div>
  </section>

  <div>
    <section class="card">
      <div class="card-head"><h2>Change password</h2></div>
      <div class="card-body">
        <form method="post" action="/profile/password" class="form">
          <?= csrf_field() ?>
          <label class="field"><span>Current password</span><input type="password" name="current_password" required autocomplete="current-password"></label>
          <label class="field"><span>New password <em class="opt">min <?= (int) config('app.auth.password_min_length', 10) ?> characters</em></span><input type="password" name="password" required autocomplete="new-password"></label>
          <label class="field"><span>Confirm new password</span><input type="password" name="password_confirmation" required autocomplete="new-password"></label>
          <div class="form-actions"><button class="btn btn-primary" type="submit">Update password</button></div>
        </form>
      </div>
    </section>

    <section class="card">
      <div class="card-head"><h2>Access &amp; verification</h2></div>
      <div class="card-body">
        <dl class="detail-list">
          <div><dt>Roles</dt><dd><?php foreach ($roles as $r): ?><span class="badge badge-info"><?= e($r['name']) ?></span> <?php endforeach; ?></dd></div>
          <div><dt>Email verification</dt><dd><?= $user['email_verified_at'] ? 'Verified ' . e(fdate($user['email_verified_at'])) : '<span class="flag-danger">Not verified</span>' ?></dd></div>
          <div><dt>Last login</dt><dd><?= e(fdatetime($user['last_login_at'])) ?></dd></div>
          <div><dt>Member since</dt><dd><?= e(fdate($user['created_at'])) ?></dd></div>
        </dl>
      </div>
    </section>
  </div>
</div>
