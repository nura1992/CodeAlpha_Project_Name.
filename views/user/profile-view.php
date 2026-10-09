<?php $title=e($profile['display_name']??'Profile'); ?>
<div class="card">
<div style="display:flex;gap:20px;align-items:start;flex-wrap:wrap">
<div style="flex:1;min-width:260px">
<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:8px">
<?php foreach(($profile['photos']??[]) as $ph): ?><img src="<?= e($ph['url']) ?>" style="width:100%;height:200px;object-fit:cover;border-radius:8px"><?php endforeach; ?>
<?php if(empty($profile['photos'])): ?><div class="avatar" style="width:120px;height:120px;font-size:48px"><?= e(initials($profile['display_name']??'?')) ?></div><?php endif; ?>
</div>
</div>
<div style="flex:1;min-width:280px">
<h1 style="margin-top:0"><?= e($profile['display_name']??'Member') ?><?= !empty($profile['verified'])?' ✓ Verified':'' ?> <?= !empty($profile['matched'])?'<span class="badge">Matched</span>':'' ?></h1>
<p class="muted"><?= e($profile['age']??'') ?><?= !empty($profile['age'])?' years · ':'' ?><?= e($profile['location_city']??'') ?><?= !empty($profile['location_city']) && !empty($profile['location_country'])?', ':'' ?><?= e($profile['location_country']??'') ?></p>
<?php if(!empty($profile['bio'])): ?><p style="white-space:pre-wrap"><?= e($profile['bio']) ?></p><?php endif; ?>
<?php if(!empty($profile['interests'])): ?><div style="display:flex;gap:6px;flex-wrap:wrap;margin:8px 0"><?php foreach($profile['interests'] as $i): ?><span style="background:#f0f2f5;padding:4px 10px;border-radius:999px;font-size:13px"><?= e($i) ?></span><?php endforeach; ?></div><?php endif; ?>
<table style="font-size:14px;margin-top:12px">
<?php foreach(['relationship_intention'=>'Intention','height_cm'=>'Height','education'=>'Education','occupation'=>'Occupation','religion'=>'Religion','smoking'=>'Smoking','drinking'=>'Drinking'] as $k=>$label): if(!empty($profile[$k])): ?><tr><td class="muted" style="padding:4px 12px 4px 0"><?= e($label) ?></td><td><?= e((string)$profile[$k]) ?></td></tr><?php endif; endforeach; ?>
</table>
<?php if(!$is_staff_view): ?>
<div style="display:flex;gap:8px;margin-top:16px">
<button class="btn btn-primary" id="btn-like" style="flex:1">♥ Like</button>
<button class="btn btn-secondary" id="btn-pass" style="flex:1">✕ Pass</button>
</div>
<div style="display:flex;gap:8px;margin-top:8px">
<a href="/chat" class="btn btn-secondary" style="flex:1;text-align:center">Message</a>
<button class="btn btn-secondary" id="btn-fav" style="flex:1">☆ Favourite</button>
<button class="btn btn-secondary" id="btn-block" style="font-size:13px">Block</button>
</div>
<?php endif; ?>
</div>
</div>
</div>
<script>
const profileId=<?= (int)($profile['id']??0) ?>;
document.getElementById('btn-like')?.addEventListener('click', async ()=>{ try{ const r=await api('/api/discovery/like',{method:'POST', body:{user_id:profileId}}); toast(r.matched?'It\'s a match! 🎉':'Liked'); }catch(e){ toast(e.message); }});
document.getElementById('btn-pass')?.addEventListener('click', async ()=>{ try{ await api('/api/discovery/pass',{method:'POST', body:{user_id:profileId}}); toast('Passed'); }catch(e){ toast(e.message); }});
document.getElementById('btn-fav')?.addEventListener('click', async e=>{ try{ await api('/api/discovery/favourite',{method:'POST', body:{user_id:profileId}}); e.target.textContent='★ Favourited'; }catch(err){ toast(err.message); }});
document.getElementById('btn-block')?.addEventListener('click', async ()=>{ if(!confirm('Block this member?')) return; try{ await api('/api/users/'+profileId+'/block',{method:'POST'}); toast('Blocked'); location.href='/discover'; }catch(e){ toast(e.message); }});
</script>
