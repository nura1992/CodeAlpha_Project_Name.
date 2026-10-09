<?php $title='Messages'; ?>
<div class="chat-layout" id="chat-app">
  <div class="chat-list" id="chat-list"><div class="muted" style="padding:16px">Loading…</div></div>
  <div style="display:flex;flex-direction:column;flex:1;min-height:0">
    <div class="chat-messages" id="chat-messages"><div class="muted" style="text-align:center;padding:32px">Select a conversation</div></div>
    <form class="chat-input" id="chat-form" hidden>
      <input id="chat-text" placeholder="Type a message…" autocomplete="off">
      <label class="btn btn-secondary" style="cursor:pointer">📷<input type="file" id="chat-image" accept="image/*" hidden></label>
      <button class="btn btn-primary" type="submit">Send</button>
    </form>
  </div>
</div>
<script>
(function(){
  let activeId = <?= (int)($conversation_id ?? 0) ?> || null;
  let pollTimer=null;
  const listEl=document.getElementById('chat-list'), msgsEl=document.getElementById('chat-messages'), form=document.getElementById('chat-form'), input=document.getElementById('chat-text');
  async function loadConversations(){
    try{
      const data=await api('/api/conversations');
      const items=data.conversations||[];
      listEl.innerHTML='';
      if(items.length===0) listEl.innerHTML='<div class="muted" style="padding:16px">No conversations yet. Get a match to start chatting.</div>';
      items.forEach(c=>{
        const d=document.createElement('div'); d.className='chat-list-item'+(c.id===activeId?' active':''); d.dataset.id=c.id;
        d.innerHTML=`<div class="avatar">${c.other.avatar?`<img src="${c.other.avatar}">`: (c.other.display_name||'?').slice(0,1)}</div>
          <div style="flex:1;min-width:0"><strong>${esc(c.other.display_name||'Member')}</strong><br><span class="muted" style="font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block">${esc(c.last_message?.body || c.last_message?.type || '')}</span></div>
          ${c.unread_count?`<span class="badge">${c.unread_count}</span>`:''}`;
        d.addEventListener('click', ()=> openChat(c.id));
        listEl.appendChild(d);
      });
      if(activeId) openChat(activeId);
    }catch(e){ listEl.innerHTML='<div class="muted" style="padding:16px">'+esc(e.message)+'</div>'; }
  }
  async function openChat(id){
    activeId=id; document.querySelectorAll('.chat-list-item').forEach(el=> el.classList.toggle('active', parseInt(el.dataset.id)===id));
    form.hidden=false; msgsEl.innerHTML='<div class="muted" style="padding:16px">Loading…</div>';
    if(pollTimer) clearInterval(pollTimer);
    await loadMessages();
    pollTimer=setInterval(loadMessages, 3000);
    api('/api/conversations/'+id+'/read',{method:'POST'}).catch(()=>{});
  }
  async function loadMessages(){
    if(!activeId) return;
    try{
      const data=await api('/api/conversations/'+activeId+'/messages');
      const msgs=data.messages||[];
      msgsEl.innerHTML='';
      if(msgs.length===0) msgsEl.innerHTML='<div class="muted" style="text-align:center;padding:16px">No messages yet. Say hi!</div>';
      msgs.forEach(m=>{
        const d=document.createElement('div'); d.className='bubble '+(m.mine?'mine':'theirs');
        let html='';
        if(m.type==='text') html=esc(m.body||'');
        else if(m.media_url) html=`<img src="${m.media_url}" alt="">${m.body?'<div>'+esc(m.body)+'</div>':''}`;
        else html='['+esc(m.type)+']';
        html+=`<div style="font-size:11px;opacity:.7;margin-top:4px">${esc(m.created_at||'')} ${m.read_at?'✓✓': m.delivered_at?'✓':''}</div>`;
        d.innerHTML=html; msgsEl.appendChild(d);
      });
      msgsEl.scrollTop=msgsEl.scrollHeight;
    }catch(e){ toast(e.message); }
  }
  form.addEventListener('submit', async e=>{
    e.preventDefault(); const text=input.value.trim(); const file=document.getElementById('chat-image').files[0];
    if(!text && !file) return;
    input.value=''; document.getElementById('chat-image').value='';
    try{
      if(file){
        const fd=new FormData(); fd.append('media', file); fd.append('type','image');
        await fetch('/api/conversations/'+activeId+'/messages',{method:'POST', headers:{'X-CSRF-Token': window.CSRF_TOKEN}, body: fd, credentials:'same-origin'});
      } else {
        await api('/api/conversations/'+activeId+'/messages',{method:'POST', body:{type:'text', body:text}});
      }
      await loadMessages();
    }catch(err){ toast(err.message); }
  });
  function esc(s){ const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
  loadConversations();
})();
</script>
