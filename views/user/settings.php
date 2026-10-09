<?php $title='Settings'; ?>
<div class="card">
<h1>Settings</h1>
<form id="settings-form">
<div class="form-group"><label>Privacy</label>
<label style="font-weight:400;display:flex;gap:8px;align-items:center"><input type="checkbox" name="privacy_show_age" style="width:auto" <?= (json_decode($profile['privacy']??'{}',true)['show_age']??true)?'checked':'' ?>> Show age</label>
<label style="font-weight:400;display:flex;gap:8px;align-items:center"><input type="checkbox" name="privacy_show_distance" style="width:auto" <?= (json_decode($profile['privacy']??'{}',true)['show_distance']??true)?'checked':'' ?>> Show distance</label>
<label style="font-weight:400;display:flex;gap:8px;align-items:center"><input type="checkbox" name="privacy_show_online" style="width:auto" <?= (json_decode($profile['privacy']??'{}',true)['show_online']??true)?'checked':'' ?>> Show online status</label>
</div>
<div class="form-group"><label>Notifications</label>
<label style="font-weight:400;display:flex;gap:8px;align-items:center"><input type="checkbox" name="notif_email" style="width:auto" <?= (json_decode($profile['notification_settings']??'{}',true)['email']??true)?'checked':'' ?>> Email</label>
<label style="font-weight:400;display:flex;gap:8px;align-items:center"><input type="checkbox" name="notif_push" style="width:auto" <?= (json_decode($profile['notification_settings']??'{}',true)['push']??true)?'checked':'' ?>> Push</label>
</div>
<button class="btn btn-primary">Save</button>
</form>
<div style="margin-top:24px;border-top:1px solid #eee;padding-top:16px">
<h3 style="color:#e41e3f">Danger zone</h3>
<form id="delete-form" style="margin-top:8px">
<p class="muted" style="font-size:13px">Delete your account and all associated data. This cannot be undone.</p>
<input name="confirm" placeholder="Type DELETE to confirm" style="max-width:260px">
<button class="btn btn-danger" style="margin-top:8px">Delete account</button>
</form>
</div>
</div>
<script>
document.getElementById('settings-form').addEventListener('submit', async e=>{
  e.preventDefault();
  const fd=new FormData(e.target);
  const privacy={show_age: fd.has('privacy_show_age'), show_distance: fd.has('privacy_show_distance'), show_online: fd.has('privacy_show_online')};
  const notification_settings={email: fd.has('notif_email'), push: fd.has('notif_push')};
  try{ await api('/api/profile',{method:'PUT', body:{privacy, notification_settings}}); toast('Saved'); }catch(err){ toast(err.message); }
});
document.getElementById('delete-form').addEventListener('submit', async e=>{
  e.preventDefault();
  const confirm=e.target.confirm.value.trim();
  if(confirm!=='DELETE') return toast('Type DELETE to confirm');
  if(!confirm('Really delete your account?')) return;
  try{ await api('/api/account',{method:'DELETE', body:{confirm}}); location.href='/'; }catch(err){ toast(err.message); }
});
if('Notification' in window && Notification.permission==='default'){
  Notification.requestPermission().then(async perm=>{
    if(perm==='granted' && 'serviceWorker' in navigator){
      try{
        const reg=await navigator.serviceWorker.register('/sw.js');
        const sub=await reg.pushManager.subscribe({userVisibleOnly:true, applicationServerKey: urlBase64ToUint8Array('<?= e(config('push.vapid_public','')) ?>')});
        await api('/api/push/subscribe',{method:'POST', body:{endpoint: sub.endpoint, keys: sub.toJSON().keys}});
      }catch{}
    }
  });
}
function urlBase64ToUint8Array(b64){ const p='='.repeat((4-b64.length%4)%4); const b=atob((b64+p).replace(/-/g,'+').replace(/_/g,'/')); const a=new Uint8Array(b.length); for(let i=0;i<b.length;i++) a[i]=b.charCodeAt(i); return a; }
</script>
