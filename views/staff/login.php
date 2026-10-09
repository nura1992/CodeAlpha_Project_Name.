<?php $title='Staff login'; ?>
<div class="card">
<h1>Staff login</h1>
<form method="post" action="/staff/login">
<?= csrf_field() ?>
<div class="form-group"><label>Staff email or phone</label><input name="identifier" required autocomplete="username"></div>
<div class="form-group"><label>Password</label><input type="password" name="password" required autocomplete="current-password"></div>
<button class="btn btn-primary" style="width:100%">Log in</button>
</form>
</div>
