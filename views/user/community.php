<?php $title='Community'; ?>
<div id="community-app">
<div class="card">
<form id="post-form" enctype="multipart/form-data">
<div class="form-group"><textarea id="post-body" name="body" rows="3" placeholder="What's on your mind? Use #hashtags and @mentions"></textarea></div>
<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
<select name="type" id="post-type"><option value="text">Text</option><option value="image">Image</option><option value="video">Video</option><option value="link">Link</option></select>
<input type="file" name="media" id="post-media" accept="image/*,video/*" hidden>
<label for="post-media" class="btn btn-secondary" style="cursor:pointer">📎 Media</label>
<input name="link_url" id="post-link" placeholder="https://…" hidden style="flex:1">
<select name="visibility"><option value="public">Public</option><option value="followers">Followers only</option></select>
<input name="topic" placeholder="Topic (optional)" style="width:160px">
<button class="btn btn-primary" type="submit">Post</button>
</div>
</form>
</div>
<div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap">
<button class="btn btn-secondary filter-btn active" data-filter="all">All</button>
<button class="btn btn-secondary filter-btn" data-filter="following">Following</button>
<a href="/community/search" class="btn btn-secondary">Search</a>
<a href="/community/saved" class="btn btn-secondary">Saved</a>
</div>
<div id="feed"></div>
<div style="text-align:center;padding:12px"><button class="btn btn-secondary" id="btn-more-feed">Load more</button></div>
</div>
<script>
(function(){
  let page=1, filter='all';
  const feed=document.getElementById('feed');
  document.getElementById('post-type').addEventListener('change', e=>{
    document.getElementById('post-media').hidden = e.target.value==='text' || e.target.value==='link';
    document.getElementById('post-link').hidden = e.target.value!=='link';
  });
  document.getElementById('post-form').addEventListener('submit', async e=>{
    e.preventDefault();
    const fd=new FormData(e.target);
    try{ const data=await fetch('/api/community/posts',{method:'POST', headers:{'X-CSRF-Token': window.CSRF_TOKEN}, body: fd, credentials:'same-origin'}).then(r=>r.json()); if(data.error) throw new Error(data.error); toast('Posted'); e.target.reset(); load(true); }catch(err){ toast(err.message); }
  });
  function postCard(p){
    const d=document.createElement('div'); d.className='card post'; d.dataset.id=p.id;
    d.innerHTML=`
      <div class="post-header">
        <div class="avatar">${p.author.avatar?`<img src="${p.author.avatar}">`: (p.author.display_name||'?').slice(0,1)}</div>
        <div style="flex:1"><a href="/community/member/${p.author.id}"><strong>${esc(p.author.display_name||'Member')}</strong></a> ${p.author.verified?'✓':''} ${p.is_announcement?'<span class="badge">Announcement</span>':''}<br><span class="muted" style="font-size:12px">${esc(p.created_at||'')} ${p.topic? '· '+esc(p.topic):''}</span></div>
        <span class="muted" style="font-size:12px">${esc(p.visibility||'')}</span>
      </div>
      ${p.body?`<div class="post-body">${linkify(esc(p.body))}</div>`:''}
      ${p.media_url?`<div class="post-media">${p.media_mime&&p.media_mime.startsWith('video')?`<video src="${p.media_url}" controls></video>`:`<img src="${p.media_url}" alt="">`}</div>`:''}
      ${p.link_url?`<div style="margin-top:8px"><a href="${p.link_url}" target="_blank" rel="noopener">${esc(p.link_url)}</a></div>`:''}
      ${p.hashtags&&p.hashtags.length?`<div style="margin-top:6px;display:flex;gap:6px;flex-wrap:wrap">${p.hashtags.map(h=>`<a href="/community/search?q=%23${h}&tab=hashtags" style="font-size:13px">#${esc(h)}</a>`).join('')}</div>`:''}
      <div class="post-actions">
        <button class="btn-react ${p.my_reaction?'active':''}" data-id="${p.id}">👍 ${p.reactions?.total||0}</button>
        <button class="btn-comment" data-id="${p.id}">💬 ${p.comment_count||0}</button>
        <button class="btn-share" data-id="${p.id}">↗ Share</button>
        <button class="btn-save" data-id="${p.id}">${p.saved?'★ Saved':'☆ Save'}</button>
        <button class="btn-report" data-id="${p.id}" style="margin-left:auto;font-size:12px">Report</button>
      </div>
      <div class="comments" id="comments-${p.id}" hidden></div>
    `;
    return d;
  }
  function linkify(t){ return t.replace(/#(\w+)/g,'<a href="/community/search?q=%23$1&tab=hashtags">#$1</a>').replace(/@(\w+)/g,'@$1'); }
  function esc(s){ const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
  async function load(reset){
    if(reset){ page=1; feed.innerHTML=''; }
    const params=new URLSearchParams({page, following: filter==='following'? '1':''});
    try{
      const data=await api('/api/community/feed?'+params);
      (data.items||[]).forEach(p=> feed.appendChild(postCard(p)));
      if((data.items||[]).length<15) document.getElementById('btn-more-feed').hidden=true; else { document.getElementById('btn-more-feed').hidden=false; page++; }
      if(reset) page=2;
    }catch(e){ toast(e.message); }
  }
  document.getElementById('btn-more-feed').addEventListener('click', ()=> load(false));
  document.querySelectorAll('.filter-btn').forEach(b=> b.addEventListener('click', ()=>{ document.querySelectorAll('.filter-btn').forEach(x=> x.classList.remove('active')); b.classList.add('active'); filter=b.dataset.filter; load(true); }));
  feed.addEventListener('click', async e=>{
    const react=e.target.closest('.btn-react'), comment=e.target.closest('.btn-comment'), share=e.target.closest('.btn-share'), save=e.target.closest('.btn-save'), report=e.target.closest('.btn-report');
    if(react){
      const id=react.dataset.id;
      try{ const r=react.classList.contains('active')? await api('/api/community/posts/'+id+'/reactions',{method:'DELETE'}) : await api('/api/community/posts/'+id+'/reactions',{method:'POST', body:{type:'like'}});
        react.classList.toggle('active'); react.textContent='👍 '+(r.total??0);
      }catch(err){ toast(err.message); }
    }
    if(comment){
      const id=comment.dataset.id; const box=document.getElementById('comments-'+id);
      box.hidden=!box.hidden;
      if(!box.hidden && !box.dataset.loaded){
        try{
          const data=await api('/api/community/posts/'+id+'/comments');
          box.dataset.loaded=1;
          box.innerHTML=(data.comments||[]).map(c=>`<div style="padding:8px 0;border-top:1px solid #f0f2f5"><strong>${esc(c.author.display_name||'Member')}</strong> <span class="muted" style="font-size:12px">${esc(c.created_at||'')}</span><div>${esc(c.body)}</div></div>`).join('') +
            `<form class="comment-form" data-id="${id}" style="display:flex;gap:8px;margin-top:8px"><input name="body" placeholder="Write a comment…" style="flex:1" required><button class="btn btn-primary">Reply</button></form>`;
          box.querySelector('.comment-form').addEventListener('submit', async ev=>{
            ev.preventDefault(); const body=ev.target.body.value.trim(); if(!body) return;
            try{ await api('/api/community/posts/'+id+'/comments',{method:'POST', body:{body}}); box.dataset.loaded=''; box.hidden=true; toast('Comment added'); }catch(err){ toast(err.message); }
          });
        }catch(err){ toast(err.message); }
      }
    }
    if(share){ const id=share.dataset.id; try{ const r=await api('/api/community/posts/'+id+'/share',{method:'POST', body:{channel:'copy_link'}}); await sharePost(r.share_url, 'Check this post'); }catch(err){ toast(err.message); } }
    if(save){
      const id=save.dataset.id; const isSaved=save.textContent.includes('Saved');
      try{ if(isSaved) await api('/api/community/posts/'+id+'/save',{method:'DELETE'}); else await api('/api/community/posts/'+id+'/save',{method:'POST'}); save.textContent=isSaved?'☆ Save':'★ Saved'; }catch(err){ toast(err.message); }
    }
    if(report){
      const id=report.dataset.id; const reason=prompt('Reason for report?'); if(!reason) return;
      try{ await api('/api/community/posts/'+id+'/report',{method:'POST', body:{type:'other', reason}}); toast('Reported — thank you'); }catch(err){ toast(err.message); }
    }
  });
  load(true);
})();
</script>
