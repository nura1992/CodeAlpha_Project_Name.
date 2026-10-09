<?php $title='Payment gateways'; ?>
<div class="card"><h1>Payment gateways</h1><p class="muted" style="font-size:13px">Credentials are encrypted at rest and never exposed to the client. Configure test/live mode, keys and webhook secrets here. Payments run in mock mode when PAYMENT_MOCK=1 or when no secret is configured — useful for full flow testing without network.</p></div>
<div class="grid grid-2">
<?php foreach(['paystack','flutterwave'] as $name): $gw=null; foreach($gateways as $g){ if($g['name']===$name) $gw=$g; } ?>
<div class="card">
<h3><?= e(ucfirst($name)) ?></h3>
<form method="post" action="/staff/super/gateways">
<?= csrf_field() ?>
<input type="hidden" name="gateway" value="<?= e($name) ?>">
<div class="form-group"><label>Public key</label><input name="public_key" value="<?= e($gw['public_key']??'') ?>"></div>
<div class="form-group"><label>Secret key (leave blank to keep)</label><input name="secret_key" type="password" placeholder="••••••••"></div>
<div class="form-group"><label>Webhook secret (leave blank to keep)</label><input name="webhook_secret" type="password" placeholder="••••••••"></div>
<div class="form-group"><label>Mode</label><select name="mode"><option value="test" <?= ($gw['mode']??'test')==='test'?'selected':'' ?>>Test</option><option value="live" <?= ($gw['mode']??'')==='live'?'selected':'' ?>>Live</option></select></div>
<label style="display:flex;gap:8px;font-weight:400"><input type="checkbox" name="is_active" value="1" <?= !empty($gw['is_active'])?'checked':'' ?> style="width:auto"> Active</label>
<button class="btn btn-primary" style="margin-top:12px">Save <?= e(ucfirst($name)) ?></button>
</form>
</div>
<?php endforeach; ?>
</div>
