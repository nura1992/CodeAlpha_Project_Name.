'use strict';
/**
 * WebRTC signalling relay.
 *
 * - Clients connect with ?ticket=<payload>.<sig> issued by the PHP API (src/Core/Signalling.php).
 * - The ticket is HMAC-SHA256 signed with SIGNALLING_SECRET and expires after 120 seconds.
 * - A room is the call id. Only the two participants may join; the relay never stores media or messages.
 * - Only offer / answer / ice / hangup messages are relayed. Frames are size-limited and rate-limited.
 *
 * Env:
 *   SIGNALLING_SECRET  (required, >= 32 chars, must equal the PHP value)
 *   PORT               (default 8081)
 *   HOST               (default 0.0.0.0)
 *   ALLOWED_ORIGINS    (optional, comma separated; when set, other origins are refused)
 */
const http = require('http');
const crypto = require('crypto');
const { WebSocketServer } = require('ws');

const MAX_FRAME_BYTES = 64 * 1024;
const RATE_WINDOW_MS = 10000;
const RATE_MAX_FRAMES = 200;
const ALLOWED_TYPES = new Set(['offer', 'answer', 'ice', 'hangup']);
const HEARTBEAT_MS = 30000;
const LONELY_ROOM_MS = 90000;

function b64urlDecode(str) {
  return Buffer.from(str.replace(/-/g, '+').replace(/_/g, '/'), 'base64');
}

/** Returns the ticket payload, or null when the signature, format or expiry is wrong. */
function verifyTicket(ticket, secret, now = Date.now()) {
  if (typeof ticket !== 'string' || ticket.length > 2048) return null;
  const parts = ticket.split('.');
  if (parts.length !== 2) return null;
  const [payloadB64, sigB64] = parts;
  const expected = crypto.createHmac('sha256', secret).update(payloadB64).digest();
  const given = b64urlDecode(sigB64);
  if (given.length !== expected.length || !crypto.timingSafeEqual(given, expected)) return null;
  let payload;
  try { payload = JSON.parse(b64urlDecode(payloadB64).toString('utf8')); } catch (_) { return null; }
  if (!payload || !Number.isInteger(payload.cid) || !Number.isInteger(payload.uid)) return null;
  if (typeof payload.exp !== 'number' || payload.exp * 1000 < now) return null;
  return payload;
}

function createServer(options = {}) {
  const secret = options.secret || process.env.SIGNALLING_SECRET || '';
  if (secret.length < 32) throw new Error('SIGNALLING_SECRET must be at least 32 characters.');
  const allowedOrigins = (options.allowedOrigins || process.env.ALLOWED_ORIGINS || '')
    .split(',').map((s) => s.trim()).filter(Boolean);
  const rooms = new Map(); // callId -> { members: Map<uid, {ws, uid}>, timer }

  const server = http.createServer((req, res) => {
    // No HTTP API. Health probe only.
    if (req.url === '/healthz') { res.writeHead(200, { 'Content-Type': 'text/plain' }); res.end('ok'); return; }
    res.writeHead(404); res.end();
  });
  const wss = new WebSocketServer({ server, maxPayload: MAX_FRAME_BYTES });

  const send = (ws, obj) => { if (ws.readyState === ws.OPEN) ws.send(JSON.stringify(obj)); };

  function leave(ws) {
    const room = rooms.get(ws.callId);
    if (!room) return;
    if (room.members.get(ws.uid)?.ws === ws) room.members.delete(ws.uid);
    for (const peer of room.members.values()) send(peer.ws, { type: 'hangup', payload: { reason: 'peer_left' } });
    if (room.members.size === 0) { clearTimeout(room.timer); rooms.delete(ws.callId); }
    else if (room.members.size === 1) {
      clearTimeout(room.timer);
      room.timer = setTimeout(() => { // a lone participant left waiting for too long
        const r = rooms.get(ws.callId);
        if (r && r.members.size <= 1) { for (const p of r.members.values()) p.ws.close(4010, 'idle'); rooms.delete(ws.callId); }
      }, LONELY_ROOM_MS);
      room.timer.unref?.();
    }
  }

  wss.on('connection', (ws, req) => {
    const origin = req.headers.origin;
    if (allowedOrigins.length && (!origin || !allowedOrigins.includes(origin))) { ws.close(4403, 'origin'); return; }

    const url = new URL(req.url, 'http://x');
    const payload = verifyTicket(url.searchParams.get('ticket') || '', secret);
    if (!payload) { ws.close(4401, 'invalid ticket'); return; }

    const callId = String(payload.cid);
    let room = rooms.get(callId);
    if (!room) { room = { members: new Map(), timer: null }; rooms.set(callId, room); }
    if (room.members.has(payload.uid) || room.members.size >= 2) { ws.close(4409, 'room full or already joined'); return; }
    clearTimeout(room.timer);

    ws.uid = payload.uid;
    ws.callId = callId;
    ws.isAlive = true;
    ws.frames = [];
    room.members.set(payload.uid, { ws, uid: payload.uid });

    // Tell both sides who is already present so the caller can make the offer.
    for (const peer of room.members.values()) {
      if (peer.ws === ws) continue;
      send(ws, { type: 'peer-present' });
      send(peer.ws, { type: 'peer-joined' });
    }

    ws.on('pong', () => { ws.isAlive = true; });
    ws.on('message', (data, isBinary) => {
      if (isBinary) return;
      const now = Date.now();
      ws.frames = ws.frames.filter((t) => now - t < RATE_WINDOW_MS);
      if (ws.frames.length >= RATE_MAX_FRAMES) { ws.close(4429, 'rate limited'); return; }
      ws.frames.push(now);
      let msg;
      try { msg = JSON.parse(data.toString('utf8')); } catch (_) { return; }
      if (!msg || !ALLOWED_TYPES.has(msg.type)) return;
      const current = rooms.get(callId);
      if (!current) return;
      for (const peer of current.members.values()) {
        if (peer.ws !== ws) send(peer.ws, { type: msg.type, payload: msg.payload ?? null });
      }
      if (msg.type === 'hangup') ws.close(1000, 'hangup');
    });
    ws.on('close', () => leave(ws));
    ws.on('error', () => leave(ws));
  });

  const heartbeat = setInterval(() => {
    for (const ws of wss.clients) {
      if (!ws.isAlive) { ws.terminate(); continue; }
      ws.isAlive = false;
      ws.ping();
    }
  }, HEARTBEAT_MS);
  heartbeat.unref?.();
  wss.on('close', () => clearInterval(heartbeat));

  return { server, wss, rooms, verifyTicket: (t) => verifyTicket(t, secret) };
}

module.exports = { createServer, verifyTicket };

if (require.main === module) {
  const port = Number(process.env.PORT || 8081);
  const host = process.env.HOST || '0.0.0.0';
  const { server } = createServer();
  server.listen(port, host, () => console.log(`signalling listening on ${host}:${port}`));
}
