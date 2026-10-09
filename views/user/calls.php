<?php $title='Calls'; ?>
<div class="card">
<h1>Calls</h1>
<p class="muted" style="font-size:13px">Audio & video calls with your matches. WebRTC with WebSocket signalling; TURN is used when configured.</p>
<div style="display:flex;gap:8px;margin-top:12px">
<select id="call-type"><option value="audio">Audio</option><option value="video">Video</option></select>
<input type="number" id="call-user" placeholder="Member ID to call">
<button class="btn btn-primary" id="btn-call">Call</button>
</div>
<div id="call-area" hidden class="card" style="margin-top:16px">
<div style="display:flex;gap:12px">
<video id="localVideo" autoplay muted playsinline class="call-video" style="flex:1;max-height:240px"></video>
<video id="remoteVideo" autoplay playsinline class="call-video" style="flex:1;max-height:240px"></video>
</div>
<div class="call-controls">
<button class="btn btn-secondary" id="btn-mute">Mute</button>
<button class="btn btn-secondary" id="btn-camera">Camera off</button>
<button class="btn btn-secondary" id="btn-speaker">Speaker</button>
<button class="btn btn-danger" id="btn-end">End call</button>
</div>
<div class="muted" id="call-status" style="text-align:center;font-size:13px"></div>
</div>
</div>
<div class="card"><h3>Call history</h3><div id="call-history"><span class="muted">Loading…</span></div></div>
<script>
(function(){
  const localVideo=document.getElementById('localVideo'), remoteVideo=document.getElementById('remoteVideo'), statusEl=document.getElementById('call-status'), area=document.getElementById('call-area');
  let pc=null, localStream=null, currentCall=null, ws=null, signalPoll=null;

  async function loadHistory(){
    try{
      const data=await api('/api/calls');
      const items=data.calls||[]; const el=document.getElementById('call-history');
      if(items.length===0) el.innerHTML='<span class="muted">No calls yet</span>';
      else el.innerHTML='<table class="table"><tr><th>With</th><th>Direction</th><th>Type</th><th>Status</th><th>When</th></tr>'+items.map(c=>`<tr><td>${esc(c.other.display_name||'#'+c.other.id)}</td><td>${c.direction}</td><td>${c.type}</td><td>${c.status}</td><td>${c.created_at}</td></tr>`).join('')+'</table>';
    }catch(e){ document.getElementById('call-history').textContent=e.message; }
  }
  loadHistory();

  document.getElementById('btn-call').addEventListener('click', async ()=>{
    const callee=document.getElementById('call-user').value, type=document.getElementById('call-type').value;
    if(!callee) return toast('Enter a member ID');
    try{
      const data=await api('/api/calls',{method:'POST', body:{callee_id: parseInt(callee), type}});
      currentCall=data.call; toast('Calling…'); area.hidden=false; statusEl.textContent='Ringing…';
      await startCall(currentCall.id, true, type);
    }catch(e){ toast(e.message); }
  });
  document.getElementById('btn-end').addEventListener('click', endCall);
  let muted=false, camOff=false;
  document.getElementById('btn-mute').addEventListener('click', ()=>{ if(localStream) localStream.getAudioTracks().forEach(t=> t.enabled = !(muted=!muted)); document.getElementById('btn-mute').textContent=muted?'Unmute':'Mute'; });
  document.getElementById('btn-camera').addEventListener('click', ()=>{ if(localStream) localStream.getVideoTracks().forEach(t=> t.enabled = !(camOff=!camOff)); document.getElementById('btn-camera').textContent=camOff?'Camera on':'Camera off'; });

  async function startCall(callId, isCaller, type){
    const isVideo = type==='video';
    try{ localStream = await navigator.mediaDevices.getUserMedia({audio:true, video:isVideo}); }catch(e){ toast('Media error: '+e.message); return; }
    localVideo.srcObject=localStream;
    const iceServers=[{urls: 'stun:stun.l.google.com:19302'}];
    pc=new RTCPeerConnection({iceServers});
    localStream.getTracks().forEach(t=> pc.addTrack(t, localStream));
    pc.ontrack = e=> { remoteVideo.srcObject=e.streams[0]; statusEl.textContent='Connected'; };
    pc.onicecandidate = e=>{ if(e.candidate) sendSignal(callId,'ice', e.candidate); };
    // Get token and try WebSocket
    let tokenData=null;
    try{ tokenData=await api('/api/calls/'+callId+'/signal-token',{method:'POST'}); }catch{}
    if(tokenData && tokenData.signaling_url){
      try{ ws=new WebSocket(tokenData.signaling_url.replace('http','ws')+'?token='+encodeURIComponent(tokenData.token)); ws.onmessage = async ev=>{
        try{ const msg=JSON.parse(ev.data); if(msg.kind==='offer') await handleOffer(msg.payload, callId); if(msg.kind==='answer') await pc.setRemoteDescription(new RTCSessionDescription(msg.payload)); if(msg.kind==='ice') await pc.addIceCandidate(new RTCIceCandidate(msg.payload));
        }catch{}
      }; }catch{}
    }
    // Fallback polling
    let since=0;
    signalPoll=setInterval(async ()=>{
      try{
        const d=await api('/api/calls/'+callId+'/signals?since='+since);
        (d.signals||[]).forEach(async s=>{
          since=Math.max(since, s.id);
          if(s.kind==='offer' && !isCaller) await handleOffer(s.payload, callId);
          if(s.kind==='answer' && isCaller) await pc.setRemoteDescription(new RTCSessionDescription(s.payload));
          if(s.kind==='ice') try{ await pc.addIceCandidate(new RTCIceCandidate(s.payload)); }catch{}
          if(s.kind==='end'||s.kind==='decline'||s.kind==='cancel') endCall();
        });
      }catch{}
    }, 1500);

    if(isCaller){
      const offer=await pc.createOffer(); await pc.setLocalDescription(offer);
      await sendSignal(callId,'offer', offer);
    }
  }
  async function handleOffer(offer, callId){
    if(!pc) return;
    await pc.setRemoteDescription(new RTCSessionDescription(offer));
    const answer=await pc.createAnswer(); await pc.setLocalDescription(answer);
    await sendSignal(callId,'answer', answer);
  }
  async function sendSignal(callId, kind, payload){
    try{ await api('/api/calls/'+callId+'/signal',{method:'POST', body:{kind, payload}}); }catch{}
    if(ws && ws.readyState===1) ws.send(JSON.stringify({kind, payload}));
  }
  function endCall(){
    if(currentCall) api('/api/calls/'+currentCall.id+'/end',{method:'POST'}).catch(()=>{});
    if(pc) pc.close(); pc=null;
    if(localStream) localStream.getTracks().forEach(t=> t.stop()); localStream=null;
    if(ws) ws.close(); ws=null;
    if(signalPoll) clearInterval(signalPoll);
    area.hidden=true; statusEl.textContent=''; loadHistory();
  }
  function esc(s){ const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
})();
</script>
