<?php $title='Support'; ?>
<div class="card"><h1>Support</h1>
<form id="ticket-form">
<div class="form-group"><label>Subject</label><input name="subject" required></div>
<div class="grid grid-2"><div class="form-group"><label>Category</label><select name="category"><option value="other">Other</option><option value="account">Account</option><option value="payment">Payment</option><option value="verification">Verification</option><option value="community">Community</option><option value="safety">Safety</option></select></div><div class="form-group"><label>Priority</label><select name="priority"><option value="normal">Normal</option><option value="low">Low</option><option value="high">High</option><option value="urgent">Urgent</option></select></div></div>
<div class="form-group"><label>Message</label><textarea name="message" rows="4" required></textarea></div>
<button class="btn btn-primary">Open ticket</button>
</form>
</div>
<div id="tickets"></div>
<script>
async function load(){
  try{
    const data=await api('/api/support/tickets');
    const items=data.tickets||[]; const el=document.getElementById('tickets');
    if(items.length===0) el.innerHTML='<div class="card muted" style="text-align:center">No tickets yet</div>';
    else el.innerHTML='<div class="card"><h3>Your tickets</h3><table class="table"><tr><th>Subject</th><th>Status</th><th>Updated</th></tr>'+items.map(t=>`<tr><td><a href="/support/ticket/${t.id}">${esc(t.subject)}</a></td><td>${esc(t.status)}</td><td>${esc(t.updated_at||'')}</td></tr>`).join('')+'</table></div>';
  }catch(e){ toast(e.message); }
}
document.getElementById('ticket-form').addEventListener('submit', async e=>{
  e.preventDefault();
  const fd=new FormData(e.target); const body={subject: fd.get('subject'), category: fd.get('category'), priority: fd.get('priority'), message: fd.get('message')};
  try{ await api('/api/support/tickets',{method:'POST', body}); toast('Ticket opened'); e.target.reset(); load(); }catch(err){ toast(err.message); }
});
load();
function esc(s){ const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
</script>
