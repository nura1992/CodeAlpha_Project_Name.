<?php $title='Discover'; ?>
<div id="discover-app">
<div class="card">
<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:end">
<div class="form-group" style="flex:1;min-width:140px"><label>Min age</label><input type="number" id="f-min-age" value="18" min="18" max="99"></div>
<div class="form-group" style="flex:1;min-width:140px"><label>Max age</label><input type="number" id="f-max-age" value="99" min="18" max="99"></div>
<div class="form-group" style="flex:1;min-width:160px"><label>Intention</label><select id="f-intention"><option value="">Any</option><option value="casual">Casual</option><option value="relationship">Relationship</option><option value="marriage">Marriage</option><option value="friendship">Friendship</option></select></div>
<div class="form-group" style="flex:1;min-width:160px"><label>Interests (comma)</label><input id="f-interests" placeholder="music, travel"></div>
<button class="btn btn-primary" id="btn-search">Search</button>
</div>
</div>
<div id="discover-grid" class="discover-grid"></div>
<div id="discover-empty" hidden class="card" style="text-align:center;padding:32px"><p class="muted">No profiles found. Try adjusting your filters.</p></div>
<div style="text-align:center;padding:16px"><button class="btn btn-secondary" id="btn-more" hidden>Load more</button></div>
</div>
<script>
(function(){
  let page=1, loading=false, done=false;
  const grid=document.getElementById('discover-grid'), empty=document.getElementById('discover-empty'), more=document.getElementById('btn-more');
  function card(p){
    const d=document.createElement('div'); d.className='card profile-card';
    d.innerHTML=`
      ${p.avatar?`<img src="${p.avatar}" alt="" onerror="this.style.display='none'">`:`<div style="height:240px;background:#e4e6eb;display:flex;align-items:center;justify-content:center;font-size:48px">${(p.display_name||'?').slice(0,1)}</div>`}
      <div class="info">
        <div style="display:flex;justify-content:space-between;align-items:start">
          <strong>${esc(p.display_name||'Member')}${p.age?`, ${p.age}`:''} ${p.verified?'✓':''}</strong>
          ${p.distance_km!=null?`<span class="muted" style="font-size:12px">${p.distance_km} km</span>`:''}
        </div>
        <div class="muted" style="font-size:13px">${esc(p.location_city||'')}${p.location_city&&p.location_country?', ':''}${esc(p.location_country||'')}</div>
        ${p.relationship_intention?`<span class="badge" style="margin-top:6px;display:inline-block">${esc(p.relationship_intention)}</span>`:''}
        ${p.bio?`<p class="muted" style="font-size:13px;margin:8px 0 0;white-space:pre-wrap">${esc(p.bio.slice(0,120))}</p>`:''}
        ${p.interests&&p.interests.length?`<div style="margin-top:6px;display:flex;gap:6px;flex-wrap:wrap">${p.interests.slice(0,6).map(i=>`<span style="background:#f0f2f5;padding:2px 8px;border-radius:999px;font-size:12px">${esc(i)}</span>`).join('')}</div>`:''}
        <div style="display:flex;gap:8px;margin-top:12px">
          <button class="btn btn-secondary btn-pass" data-id="${p.id}" style="flex:1">✕ Pass</button>
          <button class="btn btn-primary btn-like" data-id="${p.id}" style="flex:1">♥ Like</button>
        </div>
        <div style="margin-top:8px;display:flex;gap:8px">
          <a href="/profile/${p.id}" class="btn btn-secondary" style="flex:1;text-align:center">View profile</a>
          <button class="btn btn-secondary btn-fav" data-id="${p.id}" style="padding:8px">☆</button>
        </div>
      </div>`;
    return d;
  }
  function esc(s){ const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
  async function load(reset){
    if(loading||done&&!reset) return; loading=true;
    if(reset){ page=1; done=false; grid.innerHTML=''; empty.hidden=true; }
    const params=new URLSearchParams({
      page, min_age: document.getElementById('f-min-age').value,
      max_age: document.getElementById('f-max-age').value,
      relationship_intention: document.getElementById('f-intention').value,
      interests: document.getElementById('f-interests').value,
    });
    try{
      const data=await api('/api/discovery?'+params);
      const items=data.items||[];
      if(page===1 && items.length===0) empty.hidden=false;
      items.forEach(p=> grid.appendChild(card(p)));
      if(items.length<20) { done=true; more.hidden=true; } else { more.hidden=false; page++; }
      if(reset) page=2;
      else page++;
    }catch(e){ toast(e.message); }
    loading=false;
  }
  document.getElementById('btn-search').addEventListener('click', ()=> load(true));
  more.addEventListener('click', ()=> load(false));
  grid.addEventListener('click', async e=>{
    const like=e.target.closest('.btn-like'), pass=e.target.closest('.btn-pass'), fav=e.target.closest('.btn-fav');
    if(like){ const id=like.dataset.id; like.disabled=true; try{ const r=await api('/api/discovery/like',{method:'POST',body:{user_id:id}}); if(r.matched) toast('It\'s a match! 🎉'); else toast('Liked'); like.closest('.profile-card').remove(); }catch(err){ toast(err.message); like.disabled=false; } }
    if(pass){ const id=pass.dataset.id; pass.disabled=true; try{ await api('/api/discovery/pass',{method:'POST',body:{user_id:id}}); pass.closest('.profile-card').remove(); }catch(err){ toast(err.message); pass.disabled=false; } }
    if(fav){ const id=fav.dataset.id; try{ await api('/api/discovery/favourite',{method:'POST',body:{user_id:id}}); fav.textContent='★'; toast('Added to favourites'); }catch(err){ toast(err.message); } }
  });
  load(true);
})();
</script>
