<?php $title='Saved posts'; ?>
<div class="card"><h1>Saved posts</h1></div>
<div id="saved"></div>
<script>
(async function(){
  try{
    const data=await api('/api/community/saved');
    const items=data.posts||[]; const el=document.getElementById('saved');
    if(items.length===0) el.innerHTML='<div class="card muted" style="text-align:center;padding:24px">No saved posts</div>';
    else el.innerHTML=items.map(p=>`<div class="card"><div class="post-body">${esc(p.body||'')}</div><div class="muted" style="font-size:12px">${esc(p.created_at||'')}</div><div style="margin-top:8px"><a href="/community/post/${p.id}" class="btn btn-secondary">View</a> <button class="btn btn-secondary btn-unsave" data-id="${p.id}">Unsave</button></div></div>`).join('');
    el.addEventListener('click', async e=>{
      const b=e.target.closest('.btn-unsave'); if(!b) return;
      await api('/api/community/posts/'+b.dataset.id+'/save',{method:'DELETE'}); b.closest('.card').remove(); toast('Unsaved');
    });
  }catch(e){ toast(e.message); }
  function esc(s){ const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
})();
</script>
