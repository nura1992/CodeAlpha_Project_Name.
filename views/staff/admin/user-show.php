<?php $title='User #'.(int)$user['id']; ?>
<div class="card">
<h1><?= e($user['display_name']??'Member') ?> <span class="muted" style="font-size:14px">#<?= (int)$user['id'] ?></span></h1>
<p><?= e($user['email']??$user['phone']??'') ?> · <?= e($user['status']) ?> · <?= e($user['verification_status']) ?> <?= !empty($user['verified_at'])?'✓':'' ?></p>
<p class="muted" style="font-size:13px">Gender: <?= e($user['gender']??'—') ?> · Birthdate: <?= e($user['birthdate']??'—') ?> · Bio: <?= e($user['bio']??'—') ?></p>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:8px;margin-top:12px">
<?php foreach($photos as $ph): ?><img src="/uploads/<?= e($ph['path']) ?>" style="width:100%;height:120px;object-fit:cover;border-radius:8px"><?php endforeach; ?>
</div>
<div style="display:flex;gap:8px;margin-top:16px">
<form method="post" action="/staff/admin/users/<?= (int)$user['id'] ?>/<?= $user['status']==='suspended'?'activate':'suspend' ?>"><?= csrf_field() ?><button class="btn <?= $user['status']==='suspended'?'btn-primary':'btn-danger' ?>"><?= $user['status']==='suspended'?'Activate':'Suspend' ?></button></form>
</div>
</div>
