<?php $title='My profile'; ?>
<div class="card">
<h1>My profile</h1>
<div style="display:flex;gap:16px;align-items:start;flex-wrap:wrap">
<div style="flex:1;min-width:280px">
<form id="profile-form">
<div class="form-group"><label>Display name</label><input name="display_name" value="<?= e($profile['display_name']??'') ?>" required></div>
<div class="form-group"><label>Bio</label><textarea name="bio" rows="3"><?= e($profile['bio']??'') ?></textarea></div>
<div class="grid grid-2">
<div class="form-group"><label>City</label><input name="location_city" value="<?= e($profile['location_city']??'') ?>"></div>
<div class="form-group"><label>Country</label><input name="location_country" value="<?= e($profile['location_country']??'') ?>"></div>
</div>
<div class="form-group"><label>Interests (comma separated)</label><input name="interests" value="<?= e(implode(', ', json_decode($profile['interests']??'[]',true)?:[])) ?>"></div>
<div class="grid grid-2">
<div class="form-group"><label>Relationship intention</label><select name="relationship_intention"><option value="">Select</option><option value="casual" <?= ($profile['relationship_intention']??'')==='casual'?'selected':'' ?>>Casual</option><option value="relationship" <?= ($profile['relationship_intention']??'')==='relationship'?'selected':'' ?>>Relationship</option><option value="marriage" <?= ($profile['relationship_intention']??'')==='marriage'?'selected':'' ?>>Marriage</option><option value="friendship" <?= ($profile['relationship_intention']??'')==='friendship'?'selected':'' ?>>Friendship</option></select></div>
<div class="form-group"><label>Height (cm)</label><input type="number" name="height_cm" value="<?= e($profile['height_cm']??'') ?>"></div>
</div>
<div class="grid grid-2">
<div class="form-group"><label>Education</label><input name="education" value="<?= e($profile['education']??'') ?>"></div>
<div class="form-group"><label>Occupation</label><input name="occupation" value="<?= e($profile['occupation']??'') ?>"></div>
</div>
<div class="form-group"><label>Profile visible in discovery</label><label style="display:flex;gap:8px;align-items:center;font-weight:400"><input type="checkbox" name="hidden" value="1" <?= !empty($profile['hidden'])?'checked':'' ?> style="width:auto"> Hidden (not discoverable)</label></div>
<button class="btn btn-primary">Save profile</button>
</form>
</div>
<div style="flex:1;min-width:260px">
<div class="card" style="background:#f5f6f7">
<h3>Photos (max 6)</h3>
<div id="photo-grid" style="display:grid;grid-template-columns:repeat(2,1fr);gap:8px">
<?php foreach($photos as $ph): ?>
<div style="position:relative"><img src="/uploads/<?= e($ph['path']) ?>" style="width:100%;height:140px;object-fit:cover;border-radius:8px">
<?php if($ph['is_avatar']): ?><span class="badge" style="position:absolute;top:6px;left:6px">Avatar</span><?php endif; ?>
<div style="display:flex;gap:4px;margin-top:4px"><button class="btn btn-secondary btn-avatar" data-id="<?= (int)$ph['id'] ?>" style="font-size:11px;flex:1">Avatar</button><button class="btn btn-secondary btn-del" data-id="<?= (int)$ph['id'] ?>" style="font-size:11px">✕</button></div>
</div>
<?php endforeach; ?>
</div>
<form id="photo-form" enctype="multipart/form-data" style="margin-top:12px">
<input type="file" name="photo" accept="image/*" required>
<button class="btn btn-secondary" style="margin-top:8px;width:100%">Upload photo</button>
</form>
<p class="muted" style="font-size:12px;margin-top:8px">Score: <?= (int)($profile['profile_score']??0) ?>/100 · Status: <?= e($profile['verification_status']??'unverified') ?><?= !empty($profile['verified_at'])?' ✓':'' ?></p>
<?php if($verification): ?><p class="muted" style="font-size:12px">Last verification: <?= e($verification['status']) ?> — <?= e($verification['review_reason']??'') ?></p><?php endif; ?>
</div>
</div>
</div>
</div>
<script>
(function(){
  document.getElementById('profile-form').addEventListener('submit', async e=>{
    e.preventDefault();
    const fd=new FormData(e.target);
    const data={};
    for(const [k,v] of fd.entries()) data[k]=v;
    data.hidden = fd.has('hidden');
    if(data.interests) data.interests = data.interests.split(',').map(s=>s.trim()).filter(Boolean);
    try{ await api('/api/profile',{method:'PUT', body:data}); toast('Profile saved'); }catch(err){ toast(err.message); }
  });
  document.getElementById('photo-form').addEventListener('submit', async e=>{
    e.preventDefault();
    const fd=new FormData(e.target);
    try{ await fetch('/api/profile/photos',{method:'POST', headers:{'X-CSRF-Token': window.CSRF_TOKEN}, body: fd, credentials:'same-origin'}).then(async r=>{ const j=await r.json(); if(!r.ok) throw new Error(j.error||'Upload failed'); return j; }); toast('Photo uploaded'); location.reload(); }catch(err){ toast(err.message); }
  });
  document.getElementById('photo-grid').addEventListener('click', async e=>{
    const del=e.target.closest('.btn-del'), av=e.target.closest('.btn-avatar');
    if(del){ if(!confirm('Delete photo?')) return; try{ await api('/api/profile/photos/'+del.dataset.id,{method:'DELETE'}); del.closest('div').parentElement.remove(); toast('Deleted'); }catch(err){ toast(err.message); }}
    if(av){ try{ await api('/api/profile/photos/'+av.dataset.id+'/avatar',{method:'POST'}); toast('Avatar set'); location.reload(); }catch(err){ toast(err.message); }}
  });
})();
</script>
