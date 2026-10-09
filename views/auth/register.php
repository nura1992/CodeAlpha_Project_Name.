<?php $title='Create account'; ?>
<div class="card">
<h1>Create account</h1>
<p class="muted" style="font-size:13px">You must be 18 or older. Verification is required before you can enter discovery; community is available right away.</p>
<form method="post" action="/register">
<?= csrf_field() ?>
<div class="form-group"><label>Display name</label><input name="display_name" value="<?= old('display_name') ?>" required></div>
<div class="grid grid-2" style="gap:12px">
<div class="form-group"><label>Email</label><input type="email" name="email" value="<?= old('email') ?>"></div>
<div class="form-group"><label>Phone</label><input name="phone" value="<?= old('phone') ?>" placeholder="+234..."></div>
</div>
<p class="muted" style="font-size:12px">Provide at least one of email or phone.</p>
<div class="grid grid-2" style="gap:12px">
<div class="form-group"><label>Gender</label><select name="gender" required><option value="">Select</option><option value="male">Male</option><option value="female">Female</option></select></div>
<div class="form-group"><label>Birthdate</label><input type="date" name="birthdate" required max="<?= date('Y-m-d', strtotime('-18 years')) ?>"></div>
</div>
<div class="form-group"><label>Password</label><input type="password" name="password" required minlength="8" autocomplete="new-password"></div>
<div class="form-group"><label>Confirm password</label><input type="password" name="password_confirmation" required></div>
<button class="btn btn-primary" style="width:100%">Create account</button>
</form>
<p class="muted" style="margin-top:12px">Already have an account? <a href="/login">Log in</a></p>
</div>
