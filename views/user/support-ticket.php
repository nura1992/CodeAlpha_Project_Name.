<?php $title='Ticket #'.(int)$ticket['id']; ?>
<div class="card">
<h1><?= e($ticket['subject']) ?> <span class="badge"><?= e($ticket['status']) ?></span></h1>
<p class="muted" style="font-size:13px"><?= e($ticket['category']) ?> · <?= e($ticket['created_at']) ?></p>
</div>
<div id="messages"></div>
<form id="reply-form" style="display:flex;gap:8px;margin-top:12px"><input name="body" placeholder="Write a reply…" style="flex:1" required><button class="btn btn-primary">Send</button></form>
<script>
const ticketId=<?= (int)$ticket['id'] ?>;
async function load(){
  try{
    const data=await api('/api/support/tickets/'+ticketId);
    const msgs=data.messages||[]; const el=document.getElementById('messages');
    el.innerHTML=msgs.map(m=>`<div class="card" style="${m.is_staff?'background:#e7f3ff':''}"><strong>${esc(m.display_name|| (m.is_staff?'Staff':'You'))}</strong> <span class="muted" style="font-size:12px">${esc(m.created_at||'')}</span><p style="white-space:pre-wrap">${esc(m.body)}</p></div>`).join('') || '<div class="card muted">No messages</div>';
  }catch(e){ toast(e.message); }
}
document.getElementById('reply-form').addEventListener('submit', async e=>{
  e.preventDefault(); const body=e.target.body.value.trim(); if(!body) return;
  try{ await api('/api/support/tickets/'+ticketId+'/messages',{method:'POST', body:{body}}); e.target.reset(); load(); }catch(err){ toast(err.message); }
});
load();
function esc(s){ const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
</script>
