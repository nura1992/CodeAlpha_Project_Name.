<?php $title='Dev Inbox'; ?>
<div class="card"><h1>Dev Inbox</h1><p class="muted">Logged emails, SMS and push dispatches (development only).</p></div>
<div class="grid grid-2">
<div class="card"><h3>Emails (<?= count($mail) ?>)</h3><?php foreach(array_slice($mail,0,30) as $m): ?><div style="border-bottom:1px solid #eee;padding:8px 0"><strong><?= e($m['subject']??'') ?></strong> → <?= e($m['to']??'') ?><br><span class="muted" style="font-size:12px"><?= e(mb_substr($m['body']??'',0,240)) ?></span></div><?php endforeach; ?></div>
<div class="card"><h3>SMS (<?= count($sms) ?>)</h3><?php foreach(array_slice($sms,0,30) as $m): ?><div style="border-bottom:1px solid #eee;padding:8px 0"><?= e($m['to']??'') ?>: <?= e($m['message']??'') ?></div><?php endforeach; ?></div>
</div>
<div class="card"><h3>Push (<?= count($push) ?>)</h3><?php foreach(array_slice($push,0,30) as $m): ?><div style="border-bottom:1px solid #eee;padding:8px 0"><?= e(json_encode($m)) ?></div><?php endforeach; ?></div>
