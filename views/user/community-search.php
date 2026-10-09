<?php $title='Search'; ?>
<div class="card">
<div style="display:flex;gap:8px">
<input id="q" placeholder="Search posts, members, #hashtags" style="flex:1">
<select id="tab"><option value="posts">Posts</option><option value="members">Members</option><option value="hashtags">Hashtags</option></select>
<button class="btn btn-primary" id="btn-search">Search</button>
</div>
</div>
<div id="results"></div>
<div class="card" id="trending-card"><h3>Trending</h3><div id="trending"></div></div>
<script>
async function doSearch(){
  const q=document.getElementById('q').value.trim(), tab=document.getElementById('tab').value;
  if(!q) return;
  try{
    const data=await api('/api/community/search?q='+encodeURIComponent(q)+'&tab='+tab);
    const items=data.results||[]; const el=document.getElementById('results');
    if(tab==='members') el.innerHTML=items.map(m=>`<div class="card" style="display:flex;gap:12px;align-items:center"><div class="avatar">${m.avatar?`<img src="${m.avatar}">`: (m.display_name||'?').slice(0,1)}</div><div style="flex:1"><a href="/community/member/${m.id}"><strong>${esc(m.display_name||'Member')}</strong></a> <span class="muted">${esc(m.bio||'')}</span></div><span class="muted">${m.followers} followers</span></div>`).join('') || '<div class="card muted">No results</div>';
    else if(tab==='hashtags') el.innerHTML=items.map(h=>`<div class="card"><a href="/community/search?q=%23${h.tag}&tab=hashtags">#${esc(h.tag)}</a> — ${h.posts} posts</div>`).join('') || '<div class="card muted">No results</div>';
    else el.innerHTML=items.map(p=>`<div class="card"><div class="post-body">${esc(p.body||'')}</div><div class="muted" style="font-size:12px">${esc(p.created_at||'')}</div></div>`).join('') || '<div class="card muted">No results</div>';
  }catch(e){ toast(e.message); }
}
document.getElementById('btn-search').addEventListener('click', doSearch);
document.getElementById('q').addEventListener('keydown', e=>{ if(e.key==='Enter') doSearch(); });
(async function(){
  try{ const data=await api('/api/community/trending'); const t=document.getElementById('trending'); t.innerHTML=(data.trending||[]).map(h=>`<a href="/community/search?q=%23${h.tag}&tab=hashtags" style="display:inline-block;margin:4px">#${esc(h.tag)} (${h.score})</a>`).join('') || '<span class="muted">No trending tags yet</span>'; }catch{}
  function esc(s){ const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
})();
function esc(s){ const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
</script>
