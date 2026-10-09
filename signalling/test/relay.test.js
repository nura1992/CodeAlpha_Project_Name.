'use strict';
const test = require('node:test');
const assert = require('node:assert');
const crypto = require('crypto');
const { WebSocket } = require('ws');
const { createServer } = require('../server');

const SECRET = 'x'.repeat(40);
const b64 = (buf) => Buffer.from(buf).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
function ticket(cid, uid, ttl = 120, secret = SECRET) {
  const payload = b64(JSON.stringify({ cid, uid, exp: Math.floor(Date.now() / 1000) + ttl, n: 'abc' }));
  return payload + '.' + b64(crypto.createHmac('sha256', secret).update(payload).digest());
}
function open(port, t) {
  return new Promise((resolve, reject) => {
    const ws = new WebSocket(`ws://127.0.0.1:${port}/ws?ticket=${encodeURIComponent(t)}`);
    const inbox = [];
    ws.on('message', (d) => inbox.push(JSON.parse(d.toString())));
    ws.on('open', () => resolve({ ws, inbox }));
    ws.on('error', reject);
    ws.on('close', (code) => { ws.closeCode = code; });
  });
}
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
async function until(fn, ms = 2000) { const t0 = Date.now(); while (!fn()) { if (Date.now() - t0 > ms) throw new Error('timeout'); await wait(20); } }

test('relays offer/answer between two participants and reports presence', async () => {
  const { server } = createServer({ secret: SECRET });
  await new Promise((r) => server.listen(0, '127.0.0.1', r));
  const port = server.address().port;
  const caller = await open(port, ticket(7, 1));
  const callee = await open(port, ticket(7, 2));
  await until(() => caller.inbox.some((m) => m.type === 'peer-joined') && callee.inbox.some((m) => m.type === 'peer-present'));
  caller.ws.send(JSON.stringify({ type: 'offer', payload: { sdp: 'v=0' } }));
  await until(() => callee.inbox.some((m) => m.type === 'offer'));
  assert.deepStrictEqual(callee.inbox.find((m) => m.type === 'offer').payload, { sdp: 'v=0' });
  caller.ws.close(); callee.ws.close();
  server.close();
});

test('rejects bad signatures, expired tickets and third participants', async () => {
  const { server } = createServer({ secret: SECRET });
  await new Promise((r) => server.listen(0, '127.0.0.1', r));
  const port = server.address().port;
  const badSig = await new Promise((resolve) => {
    const ws = new WebSocket(`ws://127.0.0.1:${port}/ws?ticket=${encodeURIComponent(ticket(9, 1, 120, 'y'.repeat(40)))}`);
    ws.on('close', (code) => resolve(code)); ws.on('error', () => {});
  });
  assert.strictEqual(badSig, 4401);
  const expired = await new Promise((resolve) => {
    const ws = new WebSocket(`ws://127.0.0.1:${port}/ws?ticket=${encodeURIComponent(ticket(9, 1, -10))}`);
    ws.on('close', (code) => resolve(code)); ws.on('error', () => {});
  });
  assert.strictEqual(expired, 4401);
  const a = await open(port, ticket(9, 1));
  const b = await open(port, ticket(9, 2));
  const c = await new Promise((resolve) => {
    const ws = new WebSocket(`ws://127.0.0.1:${port}/ws?ticket=${encodeURIComponent(ticket(9, 3))}`);
    ws.on('close', (code) => resolve(code)); ws.on('error', () => {});
  });
  assert.strictEqual(c, 4409);
  a.ws.close(); b.ws.close();
  server.close();
});
