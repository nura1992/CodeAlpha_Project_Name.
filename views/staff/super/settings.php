<?php $title='System settings'; ?>
<div class="card"><h1>System settings</h1>
<form method="post" action="/staff/super/settings">
<?= csrf_field() ?>
<?php foreach($editable as $key=>$meta): $val=$settings[$key]['value']??''; $decoded=json_decode((string)$val,true); $display=is_string($decoded)||is_numeric($decoded)? (string)$decoded : (is_string($val)? trim($val,'"'): $val); if($display===null) $display=''; ?>
<div class="form-group"><label><?= e($meta['label']) ?> <span class="muted" style="font-weight:400">(<?= e($key) ?>)<?= $meta['protected']?' · protected':'' ?></span></label>
<?php if(strlen((string)$display)>120): ?><textarea name="<?= e($key) ?>" rows="3"><?= e((string)$display) ?></textarea><?php else: ?><input name="<?= e($key) ?>" value="<?= e((string)$display) ?>"><?php endif; ?>
</div>
<?php endforeach; ?>
<button class="btn btn-primary">Save settings</button>
</form>
</div>
