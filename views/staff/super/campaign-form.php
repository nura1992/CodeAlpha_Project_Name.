<?php $title='New campaign'; ?>
<div class="card"><h1>New campaign</h1>
<form method="post" action="/staff/super/campaigns">
<?= csrf_field() ?>
<div class="form-group"><label>Name</label><input name="name" required></div>
<div class="form-group"><label>Objective</label><input name="objective"></div>
<div class="grid grid-2"><div class="form-group"><label>Budget</label><input type="number" step="0.01" name="budget"></div><div class="form-group"><label>Currency</label><input name="currency" value="NGN"></div></div>
<div class="grid grid-2"><div class="form-group"><label>Starts at</label><input type="datetime-local" name="starts_at"></div><div class="form-group"><label>Ends at</label><input type="datetime-local" name="ends_at"></div></div>
<div class="form-group"><label>Status</label><select name="status"><option value="draft">Draft</option><option value="active">Active</option><option value="paused">Paused</option></select></div>
<div class="form-group"><label>Promo code (optional)</label><input name="promo_code" placeholder="WELCOME10"></div>
<button class="btn btn-primary">Create</button>
</form>
</div>
