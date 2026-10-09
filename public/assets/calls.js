// WebRTC audio/video calls. Signalling runs over WebSocket (signalling/server.js).
// Flow: caller rings (poll) → callee accepts → both fetch a fresh short-lived ticket → join the room
// → the caller creates the SDP offer as soon as the peer is present. Media is peer-to-peer, with
// STUN/TURN servers supplied by the API (TURN credentials are time-limited and never stored in JS).
import { api, el, toast, errorMessage } from './ui.js';

let call = null;           // active call session
const shownIncoming = new Set();
let incomingId = null;

function overlay(node) {
  const o = document.getElementById('overlay');
  o.replaceChildren(node);
  o.hidden = false;
}
function closeOverlay() {
  const o = document.getElementById('overlay');
  o.hidden = true;
  o.replaceChildren();
}

function wsUrl(base, ticket) {
  const u = new URL(base, location.href);
  if (u.protocol === 'https:') u.protocol = 'wss:';
  else if (u.protocol === 'http:') u.protocol = 'ws:';
  u.searchParams.set('ticket', ticket);
  return u.toString();
}

/** Caller: places a call to a matched member. */
export async function startCall({ userId, media, onDone }) {
  if (call) return toast('Finish your current call first.', true);
  if (!navigator.mediaDevices || !window.RTCPeerConnection) return toast('This browser cannot place calls.', true);
  let res;
  try {
    res = await api('/api/calls', { method: 'POST', body: { user_id: userId, media } });
  } catch (e) { return toast(errorMessage(e), true); }
  call = { id: res.id, role: 'caller', media, onDone, offered: false, connected: false };
  const statusText = el('p', { role: 'status' }, 'Ringing…');
  overlay(el('div', { className: 'callbox', role: 'dialog', 'aria-label': 'Outgoing call' },
    el('h2', {}, media === 'video' ? 'Video call' : 'Audio call'), statusText,
    el('div', { className: 'controls' }, el('button', { className: 'end', onclick: () => endCall('cancelled') }, 'Cancel'))));
  call.statusText = statusText;
  call.pollTimer = setInterval(async () => {
    if (!call || call.connected) return;
    try {
      const c = await api(`/api/calls/${call.id}`);
      if (c.status === 'accepted') {
        call.connected = true;
        await connect();
      } else if (['declined', 'missed', 'ended', 'cancelled'].includes(c.status)) {
        teardown(c.status === 'declined' ? 'The call was declined.' : c.status === 'missed' ? 'No answer.' : 'Call ended.');
      }
    } catch (_) { /* keep polling */ }
  }, 2000);
  return call;
}

/** Callee: shows an incoming ringing call once and offers accept / decline. */
export function incomingCallPrompt(items, enabled) {
  const ringing = (items || []).filter((c) => c.direction === 'incoming' && c.status === 'ringing');
  if (call || !enabled) return;
  if (incomingId && !ringing.some((c) => c.id === incomingId)) {
    incomingId = null;
    closeOverlay();
  }
  const next = ringing.find((c) => !shownIncoming.has(c.id));
  if (!next || incomingId) return;
  shownIncoming.add(next.id);
  incomingId = next.id;
  overlay(el('div', { className: 'callbox', role: 'alertdialog', 'aria-label': 'Incoming call' },
    el('h2', {}, `Incoming ${next.media} call`),
    el('p', {}, next.other.display_name + ' is calling you.'),
    el('div', { className: 'controls' },
      el('button', { onclick: () => declineIncoming(next) }, 'Decline'),
      el('button', { className: 'end', style: 'background:#15803d', onclick: () => acceptIncoming(next) }, 'Accept'))));
}

async function declineIncoming(c) {
  incomingId = null;
  closeOverlay();
  try { await api(`/api/calls/${c.id}/decline`, { method: 'POST', body: {} }); } catch (_) { /* already ended */ }
}

async function acceptIncoming(c) {
  try {
    await api(`/api/calls/${c.id}/accept`, { method: 'POST', body: {} });
  } catch (e) {
    incomingId = null;
    closeOverlay();
    return toast(errorMessage(e), true);
  }
  incomingId = null;
  call = { id: c.id, role: 'callee', media: c.media, onDone: null, offered: false, connected: true };
  try { await connect(); }
  catch (e) { toast(errorMessage(e), true); endCall('media_error'); }
}

async function connect() {
  const ticket = await api(`/api/calls/${call.id}/ticket`);
  const wantVideo = call.media === 'video';
  call.localStream = await navigator.mediaDevices.getUserMedia({ audio: true, video: wantVideo });
  showInCall();
  const pc = new RTCPeerConnection({ iceServers: ticket.ice_servers || [] });
  call.pc = pc;
  call.localStream.getTracks().forEach((t) => pc.addTrack(t, call.localStream));
  pc.ontrack = (ev) => {
    const remote = document.getElementById('remote-video');
    if (remote) remote.srcObject = ev.streams[0] || new MediaStream([ev.track]);
  };
  pc.onicecandidate = (ev) => { if (ev.candidate) send('ice', ev.candidate.toJSON()); };
  pc.onconnectionstatechange = () => {
    if (!call) return;
    const s = pc.connectionState;
    setStatus(s === 'connected' ? 'Connected' : s === 'connecting' ? 'Connecting…' : s === 'disconnected' ? 'Reconnecting…' : '');
    if (s === 'failed') { toast('The connection failed. Check your network.', true); endCall('failed'); }
  };
  const ws = new WebSocket(wsUrl(ticket.signalling_url, ticket.ticket));
  call.ws = ws;
  ws.onmessage = (ev) => onSignal(ev);
  ws.onclose = () => { if (call && call.ws === ws && call.connected) teardown('The call connection closed.'); };
  ws.onerror = () => toast('Signalling connection problem.', true);
}

function send(type, payload) {
  if (call && call.ws && call.ws.readyState === WebSocket.OPEN) call.ws.send(JSON.stringify({ type, payload }));
}

async function onSignal(ev) {
  if (!call || !call.pc) return;
  let msg;
  try { msg = JSON.parse(ev.data); } catch (_) { return; }
  const pc = call.pc;
  try {
    switch (msg.type) {
      case 'peer-present':
      case 'peer-joined':
        // Only the caller offers, once, when the callee is in the room.
        if (call.role === 'caller' && !call.offered) {
          call.offered = true;
          const offer = await pc.createOffer();
          await pc.setLocalDescription(offer);
          send('offer', pc.localDescription.toJSON());
        }
        break;
      case 'offer':
        await pc.setRemoteDescription(msg.payload);
        await pc.setLocalDescription(await pc.createAnswer());
        send('answer', pc.localDescription.toJSON());
        break;
      case 'answer':
        await pc.setRemoteDescription(msg.payload);
        break;
      case 'ice':
        await pc.addIceCandidate(msg.payload).catch(() => {});
        break;
      case 'hangup':
        teardown('The other person left the call.');
        break;
      default:
        break;
    }
  } catch (e) {
    toast('Call setup problem: ' + (e.message || 'unknown'), true);
  }
}

function showInCall() {
  const local = el('video', { id: 'local-video', autoplay: true, playsinline: true, muted: true, style: 'width:30%;position:absolute;right:1rem;bottom:1rem;border-radius:10px' });
  local.srcObject = call.localStream;
  const remote = el('video', { id: 'remote-video', autoplay: true, playsinline: true });
  const status = el('p', { id: 'call-status', role: 'status', className: 'muted' }, 'Connecting…');
  const muteBtn = el('button', { onclick: (e) => {
    const on = call.localStream.getAudioTracks().some((t) => !t.enabled);
    call.localStream.getAudioTracks().forEach((t) => { t.enabled = on; });
    e.target.textContent = on ? 'Mute' : 'Unmute';
  } }, 'Mute');
  const camBtn = el('button', { hidden: call.media !== 'video', onclick: (e) => {
    const on = call.localStream.getVideoTracks().some((t) => !t.enabled);
    call.localStream.getVideoTracks().forEach((t) => { t.enabled = on; });
    e.target.textContent = on ? 'Camera off' : 'Camera on';
  } }, 'Camera off');
  overlay(el('div', { className: 'callbox', role: 'dialog', 'aria-label': 'Call in progress', style: 'position:relative' },
    el('h2', {}, call.media === 'video' ? 'Video call' : 'Audio call'),
    remote, local, status,
    el('div', { className: 'controls' }, muteBtn, camBtn,
      el('button', { className: 'end', onclick: () => endCall('hangup') }, 'Hang up'))));
  call.statusEl = status;
}

function setStatus(text) {
  if (call && call.statusEl) call.statusEl.textContent = text;
  if (call && call.statusText) call.statusText.textContent = text;
}

/** Ends the call on the server (if still open) and tears down local media. */
async function endCall(reason) {
  const current = call;
  if (!current) return;
  send('hangup', { reason });
  try { await api(`/api/calls/${current.id}/end`, { method: 'POST', body: {} }); } catch (_) { /* already ended */ }
  teardown(reason === 'cancelled' ? 'Call cancelled.' : null);
}

function teardown(message) {
  const current = call;
  if (!current) return;
  call = null;
  clearInterval(current.pollTimer);
  try { if (current.ws) current.ws.close(); } catch (_) { /* ignore */ }
  try { if (current.pc) current.pc.close(); } catch (_) { /* ignore */ }
  if (current.localStream) current.localStream.getTracks().forEach((t) => t.stop());
  closeOverlay();
  if (message) toast(message);
  if (typeof current.onDone === 'function') current.onDone();
}
