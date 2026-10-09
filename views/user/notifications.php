<?php $title='Notifications'; ?>
<div class="card" style="display:flex;justify-content:space-between;align-items:center"><h1 style="margin:0">Notifications</h1><button class="btn btn-secondary" id="btn-read-all">Mark all read</button></div>
<div id="notif-list"></div>
<script>
async function load(){
  try{
    const data=await api('/api/notifications');
    const items=data.notifications||[]; const el=document.getElementById('notif-list');
    if(items.length===0) el.innerHTML='<div class="card muted" style="text-align:center;padding:24px">No notifications</div>';
    else el.innerHTML=items.map(n=>`<div class="card" style="${n.read_at?'opacity:.6':''}"><strong>${esc(n.title)}</strong> <span class="muted" style="font-size:12px">${esc(n.created_at||'')}</span><p style="margin:6px 0 0">${esc(n.body||'')}</p>${!n.read_at?`<button class="btn btn-secondary btn-read" data-id="${n.id}" style="margin-top:8px;font-size:12px">Mark read</button>`:''}</div>`).join('');
  }catch(e){ toast(e.message); }
}
document.getElementById('btn-read-all').addEventListener('click', async ()=>{ await api('/api/notifications/read-all',{method:'POST'}); load(); });
document.getElementById('notif-list').addEventListener('click', async e=>{
  const b=e.target.closest('.btn-read'); if(!b) return;
  await api('/api/notifications/'+b.dataset.id+'/read',{method:'POST'}); b.closest('.card').style.opacity='.6'; b.remove();
});
load();
function esc(s){ const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
</script>
