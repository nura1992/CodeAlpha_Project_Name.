<?php $title='Feature management'; ?>
<div class="card"><h1>Feature management</h1><p class="muted" style="font-size:13px">Disabling a module does not delete its data. Verification disabled does not auto-approve pending profiles.</p>
<form method="post" action="/staff/super/features/lockdown" style="margin-top:12px">
<?= csrf_field() ?>
<input type="hidden" name="enable" value="<?= $lockdown?'0':'1' ?>">
<button class="btn <?= $lockdown?'btn-danger':'btn-secondary' ?>"><?= $lockdown?'Lift emergency lockdown':'Enable emergency lockdown' ?></button>
<?php if($lockdown): ?><span class="muted" style="font-size:12px;margin-left:8px">Only critical features are active.</span><?php endif; ?>
</form>
</div>
<?php foreach($registry as $category=>$features): ?>
<div class="card">
<h3><?= e($category) ?></h3>
<?php foreach($features as $key=>$f): $enabled=$states[$key]??false; $dependsOk=true; foreach($f['depends_on'] as $dep){ if(empty($states[$dep])) $dependsOk=false; } ?>
<div style="display:flex;gap:12px;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid #f0f2f5">
<div style="flex:1"><strong><?= e($f['label']) ?></strong> <code style="font-size:11px;background:#f0f2f5;padding:1px 6px;border-radius:4px"><?= e($key) ?></code><?= !empty($f['critical'])?' <span class="badge">critical</span>':'' ?><br><span class="muted" style="font-size:12px"><?= e($f['description']) ?></span><?php if(!$dependsOk): ?><br><span style="font-size:12px;color:#e41e3f">Dependency disabled — this feature is inactive regardless of its toggle.</span><?php endif; ?></div>
<form method="post" action="/staff/super/features/toggle" style="margin:0">
<?= csrf_field() ?>
<input type="hidden" name="feature" value="<?= e($key) ?>">
<input type="hidden" name="enabled" value="<?= $enabled?'0':'1' ?>">
<label class="switch"><input type="checkbox" <?= $enabled?'checked':'' ?> onchange="this.form.submit()"><span class="slider"></span></label>
</form>
</div>
<?php endforeach; ?>
</div>
<?php endforeach; ?>
