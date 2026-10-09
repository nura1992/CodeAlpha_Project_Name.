<?php $title='Favourites'; ?>
<div class="card"><h1>Favourites</h1></div>
<div id="fav-grid" class="discover-grid"></div>
<div id="fav-empty" hidden class="card" style="text-align:center;padding:32px"><p class="muted">No favourites yet.</p></div>
<script>
(async function(){
  try{
    const data=await api('/api/favourites');
    const items=data.favourites||[]; const grid=document.getElementById('fav-grid'), empty=document.getElementById('fav-empty');
    if(items.length===0) empty.hidden=false;
    items.forEach(p=>{
      const d=document.createElement('div'); d.className='card';
      d.innerHTML=`<div style="display:flex;gap:12px;align-items:center"><div class="avatar">${p.avatar?`<img src="${p.avatar}">`: (p.display_name||'?').slice(0,1)}</div><div><strong>${esc(p.display_name||'Member')}</strong><br><span class="muted">${esc(p.location_city||'')}</span></div></div>
      <div style="margin-top:12px;display:flex;gap:8px"><a href="/profile/${p.id}" class="btn btn-secondary" style="flex:1;text-align:center">Profile</a><button class="btn btn-secondary btn-unfav" data-id="${p.id}">Remove</button></div>`;
      grid.appendChild(d);
    });
    grid.addEventListener('click', async e=>{
      const b=e.target.closest('.btn-unfav'); if(!b) return;
      await api('/api/discovery/favourite/'+b.dataset.id,{method:'DELETE'}); b.closest('.card').remove(); toast('Removed');
    });
  }catch(e){ toast(e.message); }
  function esc(s){ const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
})();
</script>
