<?php $title='Post'; ?>
<div class="card" id="single-post"></div>
<div class="card" id="single-comments"></div>
<script>
(async function(){
  const id=<?= (int)($post['id'] ?? 0) ?>;
  try{
    const data=await api('/api/community/posts/'+id);
    const p=data.post; const el=document.getElementById('single-post');
    el.innerHTML=`<div class="post-header"><div class="avatar">${p.author.avatar?`<img src="${p.author.avatar}">`: (p.author.display_name||'?').slice(0,1)}</div><div><strong>${esc(p.author.display_name||'Member')}</strong><br><span class="muted" style="font-size:12px">${esc(p.created_at||'')}</span></div></div>
      <div class="post-body">${esc(p.body||'')}</div>
      ${p.media_url?`<div class="post-media"><img src="${p.media_url}"></div>`:''}
      <div class="post-actions"><button class="btn-react">👍 ${p.reactions?.total||0}</button> <button onclick="sharePost('${p.share_url}','Post')">↗ Share</button></div>`;
    const cdata=await api('/api/community/posts/'+id+'/comments');
    document.getElementById('single-comments').innerHTML='<h3>Comments</h3>'+(cdata.comments||[]).map(c=>`<div style="padding:8px 0;border-bottom:1px solid #eee"><strong>${esc(c.author.display_name||'Member')}</strong>: ${esc(c.body)}</div>`).join('') +
      `<form id="cform" style="display:flex;gap:8px;margin-top:12px"><input id="cbody" placeholder="Write a comment…" style="flex:1" required><button class="btn btn-primary">Post</button></form>`;
    document.getElementById('cform').addEventListener('submit', async e=>{ e.preventDefault(); const body=document.getElementById('cbody').value.trim(); if(!body) return; await api('/api/community/posts/'+id+'/comments',{method:'POST', body:{body}}); location.reload(); });
  }catch(e){ document.getElementById('single-post').textContent=e.message; }
  function esc(s){ const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
})();
</script>
