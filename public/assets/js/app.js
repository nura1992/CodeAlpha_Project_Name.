/* CodeAlpha — shared frontend helpers */
(function(){
  const CSRF = window.CSRF_TOKEN || document.querySelector('meta[name="csrf-token"]')?.content || '';
  const $ = (s, r=document)=>r.querySelector(s);
  const $$ = (s, r=document)=>[...r.querySelectorAll(s)];

  // Mobile nav toggle
  const ham = $('#hamburger'), mob = $('#mobile-nav');
  if(ham && mob){ ham.addEventListener('click', ()=> mob.hidden = !mob.hidden); }

  // Toast
  window.toast = function(msg, type='info'){
    const c = $('#toast-container'); if(!c) return alert(msg);
    const t = document.createElement('div'); t.className='toast'; t.textContent=msg; c.appendChild(t);
    setTimeout(()=> t.remove(), 3500);
  };

  // Generic API helper with CSRF + JSON
  window.api = async function(path, opts={}){
    opts.headers = opts.headers || {};
    if(CSRF) opts.headers['X-CSRF-Token'] = CSRF;
    opts.headers['Accept'] = 'application/json';
    if(opts.body && typeof opts.body === 'object' && !(opts.body instanceof FormData)){
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(opts.body);
    }
    opts.credentials = 'same-origin';
    const res = await fetch(path, opts);
    let data; const ct = res.headers.get('content-type')||'';
    if(ct.includes('application/json')) data = await res.json();
    else data = { raw: await res.text() };
    if(!res.ok) throw Object.assign(new Error(data.error || data.message || res.statusText), {status:res.status, data});
    return data;
  };

  // Notifications bell polling
  const bell = $('#notif-count');
  async function pollNotif(){
    if(!bell) return;
    try{
      const d = await api('/api/notifications/unread-count');
      const n = d.unread ?? 0;
      if(n>0){ bell.textContent = n>99?'99+':n; bell.hidden=false; } else bell.hidden=true;
    }catch{}
  }
  if(bell){ pollNotif(); setInterval(pollNotif, 30000); }

  // Share helper: native share with fallback
  window.sharePost = async function(url, title){
    if(navigator.share){
      try{ await navigator.share({title, url}); return; }catch{}
    }
    try{ await navigator.clipboard.writeText(url); toast('Link copied'); }catch{ prompt('Copy link:', url); }
  };

  // Auto-hide flashes
  setTimeout(()=> $$('.flash').forEach(el=> el.style.display='none'), 6000);

  // Expose helpers for page scripts
  window.$ = $; window.$$ = $$;
})();
