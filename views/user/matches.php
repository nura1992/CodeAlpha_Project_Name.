<?php $title='Matches'; ?>
<div class="card"><h1>Matches</h1><p class="muted" style="font-size:13px">People who liked you back. Start a conversation or a call.</p></div>
<div id="matches-grid" class="discover-grid"></div>
<div id="matches-empty" hidden class="card" style="text-align:center;padding:32px"><p class="muted">No matches yet. Keep discovering!</p><p><a href="/discover" class="btn btn-primary">Discover</a></p></div>
<script>
(async function(){
  try{
    const data=await api('/api/matches');
    const items=data.matches||[]; const grid=document.getElementById('matches-grid'), empty=document.getElementById('matches-empty');
    if(items.length===0) empty.hidden=false;
    items.forEach(m=>{
      const d=document.createElement('div'); d.className='card';
      d.innerHTML=`<div style="display:flex;gap:12px;align-items:center">
        <div class="avatar">${m.avatar?`<img src="${m.avatar}">`: (m.display_name||'?').slice(0,1)}</div>
        <div style="flex:1"><strong>${esc(m.display_name||'Member')}${m.age?`, ${m.age}`:''} ${m.verified?'✓':''}</strong><br><span class="muted" style="font-size:13px">${esc(m.location_city||'')}</span></div>
      </div>
      <div style="display:flex;gap:8px;margin-top:12px">
        <a href="/chat" class="btn btn-primary" style="flex:1;text-align:center">Chat</a>
        <a href="/profile/${m.id}" class="btn btn-secondary" style="flex:1;text-align:center">Profile</a>
        <button class="btn btn-secondary btn-unmatch" data-id="${m.match_id}">Unmatch</button>
      </div>`;
      grid.appendChild(d);
    });
    grid.addEventListener('click', async e=>{
      const b=e.target.closest('.btn-unmatch'); if(!b) return;
      if(!confirm('Unmatch this person?')) return;
      try{ await api('/api/matches/'+b.dataset.id+'/unmatch',{method:'POST'}); b.closest('.card').remove(); toast('Unmatched'); }catch(err){ toast(err.message); }
    });
  }catch(e){ toast(e.message); }
  function esc(s){ const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
})();
</script>
