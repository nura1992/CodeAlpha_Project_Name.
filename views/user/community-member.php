<?php $title=e($member['display_name']??'Member'); ?>
<div class="card" style="display:flex;gap:16px;align-items:center">
<div class="avatar" style="width:80px;height:80px;font-size:32px"><?php if($member['avatar']): ?><img src="<?= e($member['avatar']) ?>"><?php else: ?><?= e(initials($member['display_name']??'?')) ?><?php endif; ?></div>
<div style="flex:1"><h1 style="margin:0"><?= e($member['display_name']??'Member') ?></h1><p class="muted"><?= e($member['location_city']??'') ?> · <?= e($member['bio']??'') ?></p><p class="muted" style="font-size:12px"><?= (int)($member['followers']??0) ?> followers</p></div>
<button class="btn <?= $member['is_following']?'btn-secondary':'btn-primary' ?>" id="btn-follow" data-id="<?= (int)$member['id'] ?>"><?= $member['is_following']?'Following':'Follow' ?></button>
</div>
<div id="member-posts"></div>
<script>
const memberId=<?= (int)$member['id'] ?>;
document.getElementById('btn-follow').addEventListener('click', async e=>{
  const btn=e.target; const following=btn.textContent.trim()==='Following';
  try{ if(following) await api('/api/community/users/'+memberId+'/follow',{method:'DELETE'}); else await api('/api/community/users/'+memberId+'/follow',{method:'POST'}); btn.textContent=following?'Follow':'Following'; btn.className='btn '+(following?'btn-primary':'btn-secondary'); }catch(err){ toast(err.message); }
});
(async function(){
  try{
    const data=await api('/api/community/users/'+memberId);
    const posts=data.posts||[]; const el=document.getElementById('member-posts');
    if(posts.length===0) el.innerHTML='<div class="card muted" style="text-align:center;padding:24px">No posts yet</div>';
    else el.innerHTML=posts.map(p=>`<div class="card"><div class="post-body">${esc(p.body||'')}</div><div class="muted" style="font-size:12px">${esc(p.created_at||'')}</div></div>`).join('');
  }catch(e){ toast(e.message); }
  function esc(s){ const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
})();
</script>
