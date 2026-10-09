// CodeAlpha Match & Community — single-page client.
// Security notes: the browser never holds secret keys. Permission and feature checks are repeated here
// only for navigation; every decision is enforced again by the PHP API.
import { state, api, el, setView, toast, errorMessage, fmtDate, badge, statusBadge, table, form, toFormData,
  askReason, confirmDo, section, notice, list, ApiError } from './ui.js';
import { staffRoutes } from './staff.js';
import { startCall, incomingCallPrompt } from './calls.js';

const routes = new Map();
let pollTimer = null;
let chatTimer = null;
let chatCleanup = null;

/* ----------------------------------------------------------- bootstrap */
async function boot() {
  try {
    const session = await api('/api/auth/me');
    applySession(session);
  } catch (_) {
    state.me = null;
  }
  renderNav();
  window.addEventListener('hashchange', route);
  if (location.pathname.startsWith('/share/')) return renderShared(location.pathname.split('/')[2]);
  if (location.pathname.startsWith('/billing/return')) return renderBillingReturn();
  if (!location.hash) location.hash = state.me ? '#/discover' : '#/login';
  route();
  startPolling();
}

function applySession(session) {
  state.me = { ...session.user, profile: session.profile, settings: session.settings };
  state.csrf = session.csrf_token || '';
  state.features = session.features || {};
  state.perms = new Set(session.permissions || []);
  state.staffRoles = session.staff_roles || [];
  state.lockdown = !!(state.features['system.emergency_lockdown'] && state.features['system.emergency_lockdown'].enabled);
  document.getElementById('lockdown').hidden = !state.lockdown;
  document.getElementById('lockdown').textContent = 'The service is in maintenance mode. Some features are temporarily unavailable.';
}

function feature(key) {
  const f = state.features[key];
  return f ? !!f.enabled : true;
}

function can(perm) { return state.perms.has(perm); }
function isStaff() { return state.me && state.me.account_type === 'staff'; }

/* ----------------------------------------------------------- navigation */
function renderNav() {
  const nav = document.getElementById('nav');
  const tabbar = document.getElementById('tabbar');
  nav.replaceChildren();
  tabbar.replaceChildren();
  if (!state.me) {
    nav.append(el('a', { href: '#/login' }, 'Sign in'), el('a', { href: '#/register' }, 'Join'));
    tabbar.hidden = true;
    return;
  }
  const items = [
    ['#/discover', 'Discover', 'matchmaking.discovery'],
    ['#/matches', 'Matches', 'matchmaking.discovery'],
    ['#/messages', 'Messages', 'comm.messaging'],
    ['#/calls', 'Calls', 'comm.audio_calls'],
    ['#/community', 'Community', 'social.feed'],
    ['#/alerts', 'Alerts', null],
    ['#/profile', 'Profile', null],
    ['#/billing', 'Billing', 'business.plans'],
    ['#/support', 'Help', 'admin.support'],
    ['#/settings', 'Settings', null],
  ];
  // Staff accounts have no member profile, so they only see community, alerts and help.
  const staffNavKeys = new Set(['#/community', '#/alerts', '#/support', '#/settings']);
  const visible = items.filter(([href, , key]) => (!isStaff() || staffNavKeys.has(href)) && (!key || feature(key)));
  for (const [href, label] of visible) nav.append(el('a', { href, dataset: { route: href } }, label));
  if (isStaff()) {
    nav.append(el('a', { href: '#/staff', className: 'staff' }, 'Staff console'));
  }
  // Mobile bottom bar: the most used destinations.
  for (const [href, label] of visible.slice(0, 5)) tabbar.append(el('a', { href, dataset: { route: href } }, label));
  tabbar.hidden = false;
  nav.append(el('a', { href: '#', onclick: async (e) => { e.preventDefault(); await logout(); } }, 'Sign out'));
}

function highlightNav(hash) {
  const base = '#/' + (hash.split('/')[1] || '');
  document.querySelectorAll('[data-route]').forEach((a) => a.classList.toggle('active', a.dataset.route === base));
}

function parseHash() {
  const raw = location.hash.replace(/^#\/?/, '');
  const [path, query = ''] = raw.split('?');
  return { parts: path.split('/').filter(Boolean), query: new URLSearchParams(query) };
}

const PUBLIC = new Set(['login', 'register', 'otp', 'forgot', 'reset', '']);

async function route() {
  stopChat();
  const { parts } = parseHash();
  const name = parts[0] || '';
  highlightNav(location.hash);
  if (!state.me && !PUBLIC.has(name)) {
    location.hash = '#/login';
    return;
  }
  if (state.me && state.me.must_change_password && name !== 'settings') {
    location.hash = '#/settings?password=1';
    return;
  }
  const handler = routes.get(name) || (name === 'staff' ? staffRoutes : null);
  if (!handler) {
    setView(notice('Page not found.', 'bad'));
    return;
  }
  setView(el('p', { className: 'muted center' }, 'Loading…'));
  try {
    await handler(parts.slice(1), parseHash().query);
  } catch (e) {
    setView(notice(errorMessage(e), 'bad'));
  }
}

function go(hash) { location.hash = hash; }

/* ----------------------------------------------------------- polling (alerts, incoming calls) */
function startPolling() {
  clearInterval(pollTimer);
  pollTimer = setInterval(async () => {
    if (!state.me || document.hidden) return;
    try {
      const n = await api('/api/notifications');
      const unread = (n.items || []).filter((i) => !i.read_at).length;
      const alerts = [...document.querySelectorAll('[data-route="#/alerts"]')];
      alerts.forEach((a) => { a.textContent = unread ? `Alerts (${unread})` : 'Alerts'; });
      const c = await api('/api/calls?limit=5');
      incomingCallPrompt(c.items || [], feature('comm.audio_calls') || feature('comm.video_calls'));
    } catch (_) { /* transient */ }
  }, 8000);
}

/* ----------------------------------------------------------- auth views */
async function logout() {
  try { await api('/api/auth/logout', { method: 'POST', body: {} }); } catch (_) { /* ignore */ }
  state.me = null; state.csrf = ''; state.perms = new Set();
  renderNav();
  go('#/login');
}

routes.set('', async () => {
  if (state.me) return go('#/discover');
  setView(
    el('section', { className: 'card', style: 'text-align:center;padding:2rem 1rem' },
      el('h1', {}, 'Meet people with real, checked profiles'),
      el('p', { className: 'muted' }, 'Verified profiles, mutual matches, safe messaging, and a community to share with.'),
      el('div', { className: 'row', style: 'justify-content:center' },
        el('a', { className: 'btn primary', href: '#/register' }, 'Create account'),
        el('a', { className: 'btn', href: '#/login' }, 'Sign in'))),
    el('p', { className: 'muted center' }, 'Verification means checks were completed. It is not an identity guarantee.'));
});

routes.set('login', async () => {
  setView(el('div', { className: 'card narrow' }, el('h1', {}, 'Sign in'),
    form({
      submit: 'Sign in',
      fields: [
        { name: 'identifier', label: 'Email, phone or username', required: true, autocomplete: 'username' },
        { name: 'password', label: 'Password', type: 'password', required: true, autocomplete: 'current-password' },
      ],
      onSubmit: async (v) => {
        const session = await api('/api/auth/login', { method: 'POST', body: v });
        applySession(session);
        renderNav();
        go('#/discover');
        if (state.me.must_change_password) toast('Please change your temporary password.');
      },
    }),
    el('p', {}, el('a', { href: '#/forgot' }, 'Forgot password?'), ' · ', el('a', { href: '#/register' }, 'Create an account'))));
});

let pendingContact = null;

routes.set('register', async () => {
  setView(el('div', { className: 'card narrow' }, el('h1', {}, 'Create your account'),
    el('p', { className: 'muted' }, 'You must be 18 or older. We will send a one-time code to confirm your contact.'),
    form({
      submit: 'Continue',
      fields: [
        { name: 'display_name', label: 'Display name', required: true, maxlength: 60 },
        { name: 'username', label: 'Username', required: true, help: '3–30 letters, numbers or underscores.' },
        { name: 'channel', label: 'Verify by', type: 'select', value: 'email', options: [['email', 'Email'], ['phone', 'Phone (SMS)']] },
        { name: 'email', label: 'Email', type: 'email' },
        { name: 'phone', label: 'Phone (international format, e.g. +234…)', type: 'tel' },
        { name: 'password', label: 'Password', type: 'password', required: true, help: 'At least 10 characters, with letters and numbers.' },
        { name: 'gender', label: 'I am', type: 'select', value: 'male', options: [['male', 'Man'], ['female', 'Woman'], ['other', 'Other']] },
        { name: 'looking_for_gender', label: 'Looking for', type: 'select', value: 'female', options: [['female', 'Women'], ['male', 'Men'], ['everyone', 'Everyone']] },
        { name: 'relationship_intent', label: 'Intention', type: 'select', value: 'serious', options: [['serious', 'Serious'], ['marriage', 'Marriage'], ['friendship', 'Friendship'], ['dating', 'Dating'], ['unsure', 'Not sure']] },
        { name: 'date_of_birth', label: 'Date of birth', type: 'date', required: true },
      ],
      onSubmit: async (v) => {
        if (v.channel === 'email' && !v.email) throw new ApiError(422, 'X', 'Enter an email address.');
        if (v.channel === 'phone' && !v.phone) throw new ApiError(422, 'X', 'Enter a phone number.');
        const dest = v.channel === 'email' ? v.email : v.phone;
        const payload = { ...v, email: v.channel === 'email' ? v.email : undefined, phone: v.channel === 'phone' ? v.phone : undefined };
        const res = await api('/api/auth/register', { method: 'POST', body: payload });
        pendingContact = { channel: v.channel, destination: dest };
        toast(res.message || 'Code sent.');
        go('#/otp');
      },
    }),
    el('p', {}, el('a', { href: '#/login' }, 'Already have an account?'))));
});

routes.set('otp', async () => {
  const c = pendingContact;
  if (!c) return setView(notice('Start by creating an account or resetting your password.', ''), el('p', {}, el('a', { href: '#/register' }, 'Register')));
  setView(el('div', { className: 'card narrow' }, el('h1', {}, 'Enter your code'),
    el('p', { className: 'muted' }, 'We sent a 6-digit code to ' + c.destination + '.'),
    form({
      submit: 'Verify',
      fields: [{ name: 'code', label: 'Code', required: true, maxlength: 6, autocomplete: 'one-time-code' }],
      onSubmit: async (v) => {
        const session = await api('/api/auth/otp/verify', { method: 'POST', body: { channel: c.channel, destination: c.destination, code: v.code } });
        pendingContact = null;
        applySession(session);
        renderNav();
        toast('Welcome! Complete your profile to start matching.');
        go('#/profile');
      },
    }),
    el('button', { className: 'small', onclick: async () => {
      try { await api('/api/auth/otp/resend', { method: 'POST', body: { channel: c.channel, destination: c.destination } }); toast('New code sent if the account is pending.'); }
      catch (e) { toast(errorMessage(e), true); }
    } }, 'Resend code')));
});

routes.set('forgot', async () => {
  setView(el('div', { className: 'card narrow' }, el('h1', {}, 'Reset password'),
    form({
      submit: 'Send code',
      fields: [
        { name: 'channel', label: 'Send to', type: 'select', value: 'email', options: [['email', 'Email'], ['phone', 'Phone']] },
        { name: 'destination', label: 'Email or phone', required: true },
      ],
      onSubmit: async (v) => {
        const res = await api('/api/auth/password/forgot', { method: 'POST', body: v });
        pendingContact = { channel: v.channel, destination: v.destination, reset: true };
        toast(res.message);
        go('#/reset');
      },
    })));
});

routes.set('reset', async () => {
  const c = pendingContact || {};
  setView(el('div', { className: 'card narrow' }, el('h1', {}, 'Choose a new password'),
    form({
      submit: 'Reset password',
      fields: [
        { name: 'destination', label: 'Email or phone', value: c.destination || '', required: true },
        { name: 'channel', label: 'Channel', type: 'select', value: c.channel || 'email', options: [['email', 'Email'], ['phone', 'Phone']] },
        { name: 'code', label: 'Code', required: true, maxlength: 6 },
        { name: 'new_password', label: 'New password', type: 'password', required: true },
      ],
      onSubmit: async (v) => {
        const res = await api('/api/auth/password/reset', { method: 'POST', body: v });
        pendingContact = null;
        toast(res.message);
        go('#/login');
      },
    })));
});

/* ----------------------------------------------------------- discovery & matches */
routes.set('discover', async (_p, q) => {
  if (!feature('matchmaking.discovery')) return setView(notice('Discovery is currently unavailable.', 'bad'));
  const filters = {
    min_age: q.get('min_age') || '', max_age: q.get('max_age') || '', interest: q.get('interest') || '',
    city: q.get('city') || '', intent: q.get('intent') || '',
  };
  const params = new URLSearchParams(Object.entries(filters).filter(([, v]) => v !== ''));
  const filterForm = form({
    submit: 'Apply filters',
    fields: [
      { name: 'min_age', label: 'Min age', type: 'number', min: 18, max: 99, value: filters.min_age },
      { name: 'max_age', label: 'Max age', type: 'number', min: 18, max: 99, value: filters.max_age },
      { name: 'interest', label: 'Interest', value: filters.interest, maxlength: 40 },
      { name: 'city', label: 'City', value: filters.city, maxlength: 100 },
      { name: 'intent', label: 'Intention', type: 'select', value: filters.intent, options: [['', 'Any'], ['serious', 'Serious'], ['marriage', 'Marriage'], ['friendship', 'Friendship'], ['dating', 'Dating'], ['unsure', 'Not sure']] },
    ],
    onSubmit: async (v) => {
      const p = new URLSearchParams(Object.entries(v).filter(([, x]) => x !== '' && x !== null));
      go('#/discover?' + p.toString());
    },
  });
  const wrap = el('div', {}, el('h1', {}, 'Discover'), el('details', { className: 'card' }, el('summary', {}, 'Filters'), filterForm));
  setView(wrap, el('p', { className: 'muted center' }, 'Finding people…'));
  let data;
  try {
    data = await api('/api/discovery?' + params.toString());
  } catch (e) {
    if (e.code === 'PROFILE_NOT_APPROVED') {
      const status = e.details.approval_status || 'draft';
      setView(wrap, notice(status === 'pending' || status === 'submitted'
        ? 'Your profile is awaiting verification review. You can still use the community while you wait.'
        : 'Complete your profile and verification to unlock discovery.', ''),
        el('p', {}, el('a', { className: 'btn primary', href: '#/verification' }, 'Verification status')));
      return;
    }
    throw e;
  }
  const cards = (data.items || []).map((p) => profileCard(p));
  setView(wrap, cards.length ? el('div', { className: 'grid' }, cards) : notice('No new profiles match right now. Try wider filters.', ''));
});

function profileCard(p) {
  return el('article', { className: 'card' },
    el('div', { className: 'row' },
      p.photo_url ? el('img', { className: 'avatar lg', src: p.photo_url, alt: 'Profile photo of ' + p.display_name }) : el('div', { className: 'avatar lg' }),
      el('div', {}, el('h2', {}, p.display_name + (p.age ? ', ' + p.age : '')),
        el('div', { className: 'muted' }, [p.city, p.country].filter(Boolean).join(', ')),
        p.relationship_intent ? badge(p.relationship_intent) : null)),
    p.bio ? el('p', { className: 'post-body' }, p.bio) : null,
    p.interests && p.interests.length ? el('div', { className: 'chips' }, p.interests.map((i) => el('span', { className: 'chip' }, i))) : null,
    el('div', { className: 'row', style: 'margin-top:.8rem' },
      el('button', { onclick: (e) => discoverAct(e, p, 'pass') }, 'Pass'),
      el('button', { className: 'primary', onclick: (e) => discoverAct(e, p, 'like') }, 'Like'),
      el('button', { onclick: async () => { try { await api('/api/favourites', { method: 'POST', body: { user_id: p.id } }); toast('Saved to favourites.'); } catch (e) { toast(errorMessage(e), true); } } }, '★ Favourite'),
      el('button', { className: 'small danger', onclick: () => reportOrBlock(p.id, 'user') }, 'Block / report')));
}

async function discoverAct(e, p, action) {
  const card = e.target.closest('article');
  try {
    const res = await api('/api/discovery/actions', { method: 'POST', body: { user_id: p.id, action } });
    card.remove();
    if (res.matched) {
      toast(`It's a match with ${p.display_name}!`);
      if (res.conversation_id) go('#/messages/' + res.conversation_id);
    }
  } catch (err) { toast(errorMessage(err), true); }
}

async function reportOrBlock(id, type) {
  const choice = window.prompt('Type "block" to block this member, or "report" to report.', 'report');
  if (choice === null) return;
  try {
    if (choice.trim().toLowerCase() === 'block') {
      await api('/api/blocks', { method: 'POST', body: { user_id: id } });
      toast('Blocked. You will no longer see each other.');
    } else {
      const reason = window.prompt('Reason: harassment, fake_profile, scam, nudity, hate, spam, underage, threat, other', 'harassment');
      if (!reason) return;
      const details = window.prompt('Add details (optional)', '') || '';
      const res = await api('/api/reports', { method: 'POST', body: { target_type: type, target_id: id, reason: reason.trim(), details } });
      toast(res.message);
    }
  } catch (err) { toast(errorMessage(err), true); }
}

routes.set('matches', async () => {
  if (!feature('matchmaking.discovery')) return setView(notice('Matches are currently unavailable.', 'bad'));
  const data = await api('/api/matches');
  setView(el('h1', {}, 'Your matches'), table([
    { label: 'Member', render: (r) => el('div', { className: 'row' }, r.photo_url ? el('img', { className: 'avatar', src: r.photo_url, alt: '' }) : null, r.display_name + (r.age ? ', ' + r.age : '')) },
    { label: 'Matched', render: (r) => fmtDate(r.matched_at) },
    { label: 'Last message', render: (r) => fmtDate(r.last_message_at) },
    { label: '', render: (r) => el('div', { className: 'row' },
      el('button', { className: 'small primary', onclick: () => go('#/messages/' + r.conversation_id) }, 'Message'),
      el('button', { className: 'small', onclick: () => callFrom(r.user_id, 'audio') }, 'Call'),
      el('button', { className: 'small danger', onclick: async () => {
        if (!confirmDo('Unmatch? The conversation stays readable but new messages and calls stop.')) return;
        try { await api(`/api/matches/${r.match_id}/unmatch`, { method: 'POST', body: {} }); toast('Unmatched.'); route(); }
        catch (e) { toast(errorMessage(e), true); }
      } }, 'Unmatch')) },
  ], data.items, 'No matches yet. Keep discovering!'));
});

/* ----------------------------------------------------------- messaging */
routes.set('messages', async (parts) => {
  if (!feature('comm.messaging')) return setView(notice('Messaging is currently unavailable.', 'bad'));
  if (parts[0]) return renderChat(Number(parts[0]));
  const data = await api('/api/conversations');
  setView(el('h1', {}, 'Messages'), list(data.items, (c) => el('a', { className: 'card row spread', href: '#/messages/' + c.id, style: 'color:inherit' },
    el('div', {}, el('strong', {}, c.other.display_name), el('div', { className: 'muted' },
      c.last_message ? (c.last_message.mine ? 'You: ' : '') + c.last_message.preview : 'Say hello 👋')),
    el('div', { className: 'row' }, c.unread ? badge(c.unread + ' new', 'ok') : null, c.match_status !== 'active' ? badge('read-only', 'warn') : null)),
    'No conversations yet. Matches open a conversation automatically.'));
});

function stopChat() {
  clearInterval(chatTimer);
  chatTimer = null;
  if (chatCleanup) { chatCleanup(); chatCleanup = null; }
}

async function renderChat(convId) {
  const panel = el('div', { className: 'chat', role: 'log', 'aria-live': 'polite' });
  const status = el('p', { className: 'muted' });
  const input = el('textarea', { placeholder: 'Write a message…', maxlength: 2000, rows: 2, 'aria-label': 'Message' });
  const sendBtn = el('button', { className: 'primary' }, 'Send');
  const imageInput = el('input', { type: 'file', accept: 'image/jpeg,image/png,image/webp', hidden: true });
  const recBtn = el('button', { type: 'button' }, '🎙 Hold to talk');
  let lastId = 0;
  let canSend = true;
  let me = state.me.id;
  let otherName = '';
  let convInfo = null;

  const header = el('div', { className: 'row spread' }, el('h1', {}, 'Conversation'), el('div', { className: 'row' }));
  setView(el('div', {}, el('p', {}, el('a', { href: '#/messages' }, '← All conversations')), header, panel, status));

  async function load(initial) {
    const data = await api(`/api/conversations/${convId}/messages`);
    canSend = !!data.can_send;
    const items = data.items || [];
    const atBottom = panel.scrollTop + panel.clientHeight >= panel.scrollHeight - 40;
    panel.replaceChildren(...items.map((m) => bubble(m, me)));
    if (items.length) lastId = items[items.length - 1].id;
    if (initial || atBottom) panel.scrollTop = panel.scrollHeight;
    input.disabled = !canSend; sendBtn.disabled = !canSend; recBtn.disabled = !canSend; imageInput.disabled = !canSend;
    status.textContent = canSend ? '' : 'This conversation is read-only. The match has ended or a profile is no longer approved.';
    status.className = canSend ? 'muted' : 'notice';
    if (lastId) await api(`/api/conversations/${convId}/read`, { method: 'POST', body: { message_id: lastId } }).catch(() => {});
  }

  async function sendText(payload) {
    await api(`/api/conversations/${convId}/messages`, { method: 'POST', body: payload });
    await load(false);
  }

  sendBtn.addEventListener('click', async () => {
    const text = input.value.trim();
    if (!text) return;
    try {
      await sendText({ type: 'text', body: text, client_uuid: crypto.randomUUID() });
      input.value = '';
    } catch (e) { toast(errorMessage(e), true); }
  });
  input.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendBtn.click(); } });

  imageInput.addEventListener('change', async () => {
    const file = imageInput.files[0];
    if (!file) return;
    const fd = toFormData({ type: 'image', client_uuid: crypto.randomUUID() }, { file: [file] });
    try { await api(`/api/conversations/${convId}/messages`, { method: 'POST', form: fd }); await load(false); }
    catch (e) { toast(errorMessage(e), true); }
    imageInput.value = '';
  });

  // Voice notes: record while the button is held, upload on release (max 300 s).
  let recorder = null; let chunks = []; let startedAt = 0;
  const startRec = async () => {
    if (!canSend || recorder) return;
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      recorder = new MediaRecorder(stream);
      chunks = [];
      startedAt = Date.now();
      recorder.ondataavailable = (ev) => { if (ev.data.size) chunks.push(ev.data); };
      recorder.onstop = async () => {
        stream.getTracks().forEach((t) => t.stop());
        const dur = Math.round((Date.now() - startedAt) / 1000);
        const type = recorder.mimeType || 'audio/webm';
        const blob = new Blob(chunks, { type });
        recorder = null;
        if (dur < 1) return toast('Voice note too short.', true);
        const file = new File([blob], 'voice.' + (type.includes('ogg') ? 'ogg' : 'webm'), { type });
        const fd = toFormData({ type: 'voice', duration_seconds: Math.min(300, dur), client_uuid: crypto.randomUUID() }, { file: [file] });
        try { await api(`/api/conversations/${convId}/messages`, { method: 'POST', form: fd }); await load(false); }
        catch (e) { toast(errorMessage(e), true); }
      };
      recorder.start();
    } catch (_) { toast('Microphone permission is needed for voice notes.', true); }
  };
  const stopRec = () => { if (recorder && recorder.state === 'recording') recorder.stop(); };
  recBtn.addEventListener('pointerdown', startRec);
  recBtn.addEventListener('pointerup', stopRec);
  recBtn.addEventListener('pointerleave', stopRec);

  const attachBtn = el('button', { type: 'button', onclick: () => imageInput.click() }, '🖼 Photo');
  panel.after(el('div', { className: 'row', style: 'margin-top:.6rem' }, input, sendBtn), el('div', { className: 'row' }, attachBtn, recBtn, imageInput));

  try {
    const list = await api('/api/conversations');
    convInfo = (list.items || []).find((c) => c.id === convId);
    if (!convInfo) throw new ApiError(404, 'NF', 'Conversation not found.');
    otherName = convInfo.other.display_name;
    header.replaceChildren(el('h1', {}, otherName), el('div', { className: 'row' },
      el('button', { className: 'small', onclick: () => callFrom(convInfo.other.user_id, 'audio') }, '📞 Audio'),
      el('button', { className: 'small', onclick: () => callFrom(convInfo.other.user_id, 'video') }, '🎥 Video'),
      el('button', { className: 'small danger', onclick: () => reportOrBlock(convInfo.other.user_id, 'user') }, 'Report')));
  } catch (e) { setView(notice(errorMessage(e), 'bad')); return; }

  await load(true);
  chatTimer = setInterval(() => { load(false).catch(() => {}); }, 4000);
  chatCleanup = () => {};
}

function bubble(m, me) {
  const mine = m.sender_id === me;
  let content;
  if (m.type === 'image') content = el('img', { className: 'thumb', src: m.media_url, alt: 'Shared image', loading: 'lazy' });
  else if (m.type === 'voice') content = el('audio', { controls: true, preload: 'none', src: m.media_url });
  else content = m.body;
  const receipt = mine ? (m.read_at ? 'Read ' + fmtDate(m.read_at) : m.delivered_at ? 'Delivered' : 'Sent') : null;
  return el('div', { className: 'bubble' + (mine ? ' mine' : '') }, content, el('div', { className: 'meta' }, fmtDate(m.created_at), receipt ? ' · ' + receipt : ''));
}

/* ----------------------------------------------------------- calls */
function callFrom(userId, media) {
  startCall({ userId, media, onDone: () => { if (location.hash.startsWith('#/calls')) route(); } });
}

routes.set('calls', async () => {
  if (!feature('comm.audio_calls') && !feature('comm.video_calls')) return setView(notice('Calls are currently unavailable.', 'bad'));
  const data = await api('/api/calls');
  setView(el('h1', {}, 'Call history'), table([
    { label: 'Member', render: (c) => c.other.display_name },
    { label: 'Direction', render: (c) => c.direction + ' · ' + c.media },
    { label: 'When', render: (c) => fmtDate(c.created_at) },
    { label: 'Status', render: (c) => statusBadge(c.status) },
    { label: 'Duration', render: (c) => c.duration_seconds ? Math.floor(c.duration_seconds / 60) + 'm ' + (c.duration_seconds % 60) + 's' : '—' },
  ], data.items, 'No calls yet.'));
});

/* ----------------------------------------------------------- community */
const COMMUNITY_TABS = ['feed', 'trending', 'saved'];

routes.set('community', async (parts, q) => {
  if (!feature('social.feed')) return setView(notice('The community is currently unavailable.', 'bad'));
  if (parts[0] === 'post') return renderPost(Number(parts[1]));
  if (parts[0] === 'saved') return renderSaved();
  const tab = COMMUNITY_TABS.includes(parts[0]) ? parts[0] : 'feed';
  const query = q.get('q') || '';
  const tag = q.get('tag') || '';
  const composer = feature('social.feed') ? composerCard() : null;
  const tabs = el('div', { className: 'row' },
    el('a', { className: 'btn ' + (tab === 'feed' && !query && !tag ? 'primary' : ''), href: '#/community' }, 'Feed'),
    el('a', { className: 'btn ' + (tab === 'trending' ? 'primary' : ''), href: '#/community/trending' }, 'Trending'),
    el('a', { className: 'btn', href: '#/community/saved' }, 'Saved'),
    el('a', { className: 'btn', href: '#/community/rules' }, 'Guidelines'));
  const search = form({
    submit: 'Search', className: 'row',
    fields: [{ name: 'q', label: 'Search posts and people', value: query, maxlength: 100 }],
    onSubmit: async (v) => go('#/community?q=' + encodeURIComponent(v.q.trim())),
  });
  const feedBox = el('div', {}, el('p', { className: 'muted center' }, 'Loading…'));
  const trendingBox = el('div', {});
  const trend = await api('/api/community/trending').catch(() => ({ items: [] }));
  const tags = el('div', { className: 'chips' }, (trend.items || []).slice(0, 12).map((t) =>
    el('button', { className: 'chip', onclick: () => go('#/community?tag=' + encodeURIComponent(t.tag || t.name || '')) }, '#' + (t.tag || t.name || ''))));
  setView(el('h1', {}, 'Community'), composer, tabs, el('div', { style: 'margin:.6rem 0' }, search), el('div', { className: 'card' }, el('strong', {}, 'Trending hashtags'), tags), feedBox, trendingBox);

  if (tab === 'trending' && !query && !tag) {
    feedBox.replaceChildren(list(trend.items || [], (t) => el('div', { className: 'card' }, '#' + (t.tag || t.name), ' · ', (t.uses ?? t.count ?? 0) + ' posts')));
    return;
  }
  let data;
  if (query || tag) {
    data = await api('/api/community/search?' + new URLSearchParams(query ? { q: query } : { tag }).toString());
    data.items = data.posts || [];
  } else {
    data = await api('/api/community/feed');
  }
  feedBox.replaceChildren(...(data.items || []).map((p) => postCard(p)));
  if (!data.items || data.items.length === 0) feedBox.append(el('p', { className: 'muted center' }, 'Nothing here yet.'));
});

routes.set('community/rules', async () => {
  const data = await api('/api/community/rules');
  setView(el('h1', {}, 'Community guidelines'), section('Rules', el('ul', {}, (data.rules || data.items || []).map((r) => el('li', {}, typeof r === 'string' ? r : r.text || r.title || '')))));
});

function composerCard() {
  return el('section', { className: 'card' },
    form({
      submit: 'Post', resetOnSuccess: true,
      fields: [
        { name: 'body', label: 'Share something', type: 'textarea', maxlength: 2000, required: false },
        { name: 'visibility', label: 'Who can see this', type: 'select', value: 'public', options: [['public', 'Everyone'], ['followers', 'Followers only'], ['private', 'Only me']] },
        { name: 'media', label: 'Photo or video (optional)', type: 'file', accept: 'image/jpeg,image/png,image/webp,video/mp4,video/webm' },
      ],
      onSubmit: async (v, files, f) => {
        if (!v.body.trim() && !(files.media && files.media.length)) throw new ApiError(422, 'X', 'Write something or add media.');
        const fd = toFormData({ body: v.body, visibility: v.visibility }, { media: files.media });
        await api('/api/community/posts', { method: 'POST', form: fd });
        toast('Posted.');
        route();
      },
    }));
}

function postCard(p, { detail = false } = {}) {
  const reactBtn = el('button', { className: 'small', onclick: () => reactMenu(p) }, p.my_reaction ? '👍 ' + p.my_reaction : '👍 React');
  const commentsBox = el('div', {});
  const media = p.media ? (p.media.type === 'video'
    ? el('video', { className: 'thumb', controls: true, preload: 'metadata', src: p.media.url })
    : el('img', { className: 'thumb', src: p.media.url, alt: 'Post image', loading: 'lazy' })) : null;
  const shared = p.shared ? el('blockquote', { className: 'card', style: 'background:#fafafa' },
    p.shared.unavailable ? el('em', {}, 'Original post is no longer available.') : [el('strong', {}, p.shared.author), el('p', {}, p.shared.body)]) : null;
  const card = el('article', { className: 'card', dataset: { post: p.id } },
    el('div', { className: 'post-head' },
      el('div', {}, el('strong', {}, p.author.username), el('div', { className: 'muted' }, fmtDate(p.created_at), ' · ', p.visibility)),
      el('span', { style: 'margin-left:auto' }, detail ? null : el('a', { href: '#/community/post/' + p.id }, 'Open'))),
    p.body ? el('p', { className: 'post-body' }, p.body) : null,
    media, shared,
    p.hashtags && p.hashtags.length ? el('div', { className: 'chips' }, p.hashtags.map((t) => el('a', { className: 'chip', href: '#/community?tag=' + encodeURIComponent(t) }, '#' + t))) : null,
    el('p', { className: 'muted' }, `${p.reaction_count} reactions · ${p.comment_count} comments · ${p.share_count} shares`),
    el('div', { className: 'row reactions' },
      reactBtn,
      el('button', { className: 'small', onclick: async () => {
        commentsBox.hidden = !commentsBox.hidden;
        if (!commentsBox.hidden) await loadComments(p.id, commentsBox);
      } }, '💬 Comments'),
      el('button', { className: 'small', onclick: () => sharePost(p) }, '↗ Share'),
      el('button', { className: 'small', onclick: async () => {
        try {
          await api(`/api/community/posts/${p.id}/save`, { method: p.saved ? 'DELETE' : 'POST', body: p.saved ? undefined : {} });
          p.saved = !p.saved; toast(p.saved ? 'Saved.' : 'Removed from saved.'); route();
        } catch (e) { toast(errorMessage(e), true); }
      } }, p.saved ? '🔖 Saved' : '🔖 Save'),
      !p.author.is_me ? el('button', { className: 'small', onclick: async () => {
        try { await api('/api/community/follows', { method: 'POST', body: { user_id: p.author.user_id } }); toast('Following ' + p.author.username + '.'); }
        catch (e) { toast(errorMessage(e), true); }
      } }, 'Follow') : null,
      !p.author.is_me ? el('button', { className: 'small danger', onclick: () => reportOrBlock(p.id, 'post') }, 'Report') : null,
      p.author.is_me ? el('button', { className: 'small danger', onclick: async () => {
        if (!confirmDo('Delete this post?')) return;
        try { await api(`/api/community/posts/${p.id}`, { method: 'DELETE' }); toast('Post deleted.'); route(); }
        catch (e) { toast(errorMessage(e), true); }
      } }, 'Delete') : null),
    commentsBox);
  commentsBox.hidden = true;
  if (detail) {
    commentsBox.hidden = false;
    loadComments(p.id, commentsBox);
    commentsBox.append(commentForm(p.id, commentsBox));
  }
  return card;
}

async function loadComments(postId, box) {
  const data = await api(`/api/community/posts/${postId}/comments`);
  const items = data.items || [];
  const nodes = items.map((c) => el('div', { className: 'comment' + (c.parent_id ? ' reply' : '') },
    el('strong', {}, c.username), el('div', {}, c.body),
    el('div', { className: 'row' },
      el('span', { className: 'muted' }, fmtDate(c.created_at)),
      el('button', { className: 'small', onclick: () => replyTo(postId, c, box) }, 'Reply'),
      c.is_me ? el('button', { className: 'small danger', onclick: async () => {
        if (!confirmDo('Delete this comment?')) return;
        await api(`/api/community/comments/${c.id}`, { method: 'DELETE' }); loadComments(postId, box);
      } }, 'Delete') : null)));
  box.replaceChildren(...nodes, commentForm(postId, box));
}

function commentForm(postId, box, parentId = null) {
  return form({
    submit: parentId ? 'Reply' : 'Comment', resetOnSuccess: true, className: 'row',
    fields: [{ name: 'body', label: parentId ? 'Your reply' : 'Add a comment', maxlength: 1000, required: true }],
    onSubmit: async (v) => {
      await api(`/api/community/posts/${postId}/comments`, { method: 'POST', body: { body: v.body, parent_id: parentId } });
      await loadComments(postId, box);
    },
  });
}

function replyTo(postId, c, box) {
  const existing = box.querySelector('.reply-box');
  if (existing) existing.remove();
  box.append(el('div', { className: 'reply-box' }, commentForm(postId, box, c.id)));
}

async function reactMenu(p) {
  const choice = window.prompt('React: like, love, haha, wow, sad, angry (leave blank to remove your reaction)', p.my_reaction || 'like');
  if (choice === null) return;
  try {
    const v = choice.trim().toLowerCase();
    if (v === '') await api(`/api/community/posts/${p.id}/reactions`, { method: 'DELETE' });
    else await api(`/api/community/posts/${p.id}/reactions`, { method: 'POST', body: { reaction: v } });
    route();
  } catch (e) { toast(errorMessage(e), true); }
}

async function sharePost(p) {
  const choice = window.prompt('Share: type "link" for a copy link, "native" for your device share sheet, or a member user ID to share privately in-app.', 'link');
  if (choice === null) return;
  const c = choice.trim().toLowerCase();
  if (p.visibility !== 'public' && c !== '' && (c === 'link' || c === 'native')) {
    return toast('Only public posts can be shared by link.', true);
  }
  try {
    if (c === 'link' || c === 'native') {
      const res = await api(`/api/community/posts/${p.id}/share`, { method: 'POST', body: { channel: c } });
      const url = res.url;
      if (c === 'native' && navigator.share) { await navigator.share({ title: 'CodeAlpha Match', url }); }
      else if (navigator.clipboard) { await navigator.clipboard.writeText(url); toast('Link copied.'); }
      else window.prompt('Copy this link', url);
    } else if (/^\d+$/.test(c)) {
      await api(`/api/community/posts/${p.id}/share`, { method: 'POST', body: { channel: 'internal', user_id: Number(c) } });
      toast('Shared privately.');
    } else {
      toast('Unknown option.', true);
    }
    route();
  } catch (e) { toast(errorMessage(e), true); }
}

async function renderPost(id) {
  const data = await api(`/api/community/posts/${id}`);
  setView(el('p', {}, el('a', { href: '#/community' }, '← Community')), postCard(data.post, { detail: true }));
}

async function renderSaved() {
  const data = await api('/api/community/saved');
  setView(el('h1', {}, 'Saved posts'), list((data.items || []), (p) => postCard(p), 'You have not saved any posts.'));
}

async function renderShared(token) {
  try {
    const data = await api(`/api/community/shared/${encodeURIComponent(token)}`);
    setView(el('article', { className: 'card' }, el('p', { className: 'muted' }, 'Shared post by ' + data.post.author),
      el('p', { className: 'post-body' }, data.post.body), el('p', { className: 'muted' }, fmtDate(data.post.created_at)),
      el('a', { className: 'btn primary', href: '#/' }, 'Join the community')));
  } catch (e) { setView(notice('This link is not available.', 'bad')); }
}

routes.set('alerts', async () => {
  const data = await api('/api/notifications');
  setView(el('div', { className: 'row spread' }, el('h1', {}, 'Alerts'),
    el('button', { className: 'small', onclick: async () => { await api('/api/notifications/read', { method: 'POST', body: {} }); route(); } }, 'Mark all read')),
    list(data.items, (n) => el('div', { className: 'card' + (n.read_at ? '' : ' ') }, el('strong', {}, n.title || n.type), el('div', {}, n.body || ''),
      el('span', { className: 'muted' }, fmtDate(n.created_at)))), 'No notifications.');
});

/* ----------------------------------------------------------- profile & verification */
routes.set('profile', async () => {
  const data = await api('/api/profile');
  const p = data.profile || {};
  const interests = (p.interests || []).join(', ');
  const editForm = form({
    submit: 'Save profile',
    fields: [
      { name: 'display_name', label: 'Display name', value: p.display_name || '', required: true, maxlength: 60 },
      { name: 'bio', label: 'About you', type: 'textarea', value: p.bio || '', maxlength: 500 },
      { name: 'city', label: 'City', value: p.city || '', maxlength: 100 },
      { name: 'state', label: 'State / region', value: p.state || '', maxlength: 100 },
      { name: 'relationship_intent', label: 'Intention', type: 'select', value: p.relationship_intent || 'serious', options: [['serious', 'Serious'], ['marriage', 'Marriage'], ['friendship', 'Friendship'], ['dating', 'Dating'], ['unsure', 'Not sure']] },
      { name: 'looking_for_gender', label: 'Looking for', type: 'select', value: p.looking_for_gender || 'female', options: [['female', 'Women'], ['male', 'Men'], ['everyone', 'Everyone']] },
      { name: 'age_pref_min', label: 'Preferred min age', type: 'number', min: 18, max: 99, value: p.age_pref_min || 18 },
      { name: 'age_pref_max', label: 'Preferred max age', type: 'number', min: 18, max: 99, value: p.age_pref_max || 60 },
      { name: 'interests', label: 'Interests (comma separated)', value: interests, maxlength: 300 },
    ],
    note: 'Changing your name, photos, bio or interests may send your profile back for review. Your profile is hidden from discovery until re-approved.',
    onSubmit: async (v) => {
      const body = { ...v, interests: v.interests.split(',').map((s) => s.trim()).filter(Boolean) };
      const res = await api('/api/profile', { method: 'PUT', body });
      toast(res.reverification_required ? 'Saved. Your profile needs re-review before it shows in discovery.' : 'Profile saved.');
      route();
    },
  });
  const photoForm = form({
    submit: 'Upload photo', resetOnSuccess: true,
    fields: [{ name: 'photo', label: 'Photo (JPEG, PNG or WebP, max 8 MB)', type: 'file', accept: 'image/jpeg,image/png,image/webp', required: true }],
    onSubmit: async (v, files) => {
      await api('/api/profile/photos', { method: 'POST', form: toFormData({}, { photo: files.photo }) });
      toast('Photo received and awaiting review.');
      route();
    },
  });
  const photos = (data.photos || []).map((ph) => el('div', { className: 'card' },
    el('img', { className: 'thumb', src: ph.url, alt: 'Your photo', loading: 'lazy' }),
    el('div', { className: 'row', style: 'margin-top:.5rem' }, statusBadge(ph.moderation_status), ph.is_primary ? badge('primary', 'ok') : null),
    ph.rejection_reason ? el('p', { className: 'muted' }, 'Reason: ' + ph.rejection_reason) : null,
    el('div', { className: 'row' },
      ph.moderation_status === 'approved' && !ph.is_primary ? el('button', { className: 'small', onclick: async () => { await api(`/api/profile/photos/${ph.id}/primary`, { method: 'POST', body: {} }); route(); } }, 'Make primary') : null,
      el('button', { className: 'small danger', onclick: async () => {
        if (!confirmDo('Delete this photo?')) return;
        await api(`/api/profile/photos/${ph.id}`, { method: 'DELETE' }); route();
      } }, 'Delete'))));
  setView(el('h1', {}, 'Your profile'),
    notice(data.discovery_eligible ? 'Your profile is approved and visible in discovery.'
      : 'Status: ' + (data.approval_status || 'draft').replace(/_/g, ' ') + '. Discovery and matching open once your profile is approved.', data.discovery_eligible ? 'ok' : ''),
    section('Photos', el('div', { className: 'grid' }, photos), photoForm),
    section('Details', editForm));
});

routes.set('verification', async () => {
  const data = await api('/api/verification');
  const last = (data.requests || [])[0];
  const canResubmit = !last || last.status === 'rejected';
  setView(el('h1', {}, 'Verification'),
    notice('Verification means your submitted video was checked by our team. It is not a guarantee of identity.', ''),
    section('Status', el('p', {}, 'Profile: ', statusBadge(data.approval_status)),
      data.rejection_reason ? notice('Reason: ' + data.rejection_reason, 'bad') : null,
      table([
        { label: 'Submitted', render: (r) => fmtDate(r.submitted_at) },
        { label: 'Status', render: (r) => statusBadge(r.status) },
        { label: 'Decided', render: (r) => fmtDate(r.decided_at) },
        { label: 'Reason', render: (r) => r.decision_reason || '—' },
      ], data.requests, 'No verification submitted yet.')),
    canResubmit && feature('account.verification') ? section('Submit a verification video',
      el('p', { className: 'muted' }, 'Record a short video (10–30 seconds) of yourself saying the phrase shown in your app notification. Do not include other people, screens or private information. Videos are encrypted, reviewed by staff and deleted after the retention period.'),
      form({
        submit: 'Submit video',
        fields: [
          { name: 'video', label: 'Video (MP4 or WebM, max 50 MB)', type: 'file', accept: 'video/mp4,video/webm', required: true },
          { name: 'consent', label: 'I consent to a manual review of this video and understand a verified badge means checks were completed, not an identity guarantee.', type: 'checkbox', value: false, required: true },
        ],
        onSubmit: async (v, files) => {
          if (!v.consent) throw new ApiError(422, 'X', 'Please confirm consent.');
          await api('/api/verification', { method: 'POST', form: toFormData({ consent: '1' }, { video: files.video }) });
          toast('Video submitted for review.');
          route();
        },
      })) : null);
});

/* ----------------------------------------------------------- billing */
routes.set('billing', async () => {
  if (!feature('business.plans')) return setView(notice('Subscriptions are currently unavailable.', 'bad'));
  const [plans, history] = await Promise.all([api('/api/plans'), api('/api/payments/history')]);
  const gateways = ['paystack', 'flutterwave'].filter((g) => feature('business.' + g));
  const sub = history.subscription;
  setView(el('h1', {}, 'Subscriptions'),
    notice(sub ? `Active plan until ${fmtDate(sub.ends_at)}` : 'No active subscription.', sub ? 'ok' : ''),
    el('div', { className: 'grid' }, (plans.items || []).map((pl) => el('article', { className: 'card' },
      el('h2', {}, pl.name), el('p', {}, el('strong', {}, (Number(pl.amount_minor) / 100).toLocaleString('en-NG', { style: 'currency', currency: pl.currency })), ' / ', pl.billing_period),
      el('p', { className: 'muted' }, pl.description || ''),
      form({
        submit: 'Subscribe', fields: [
          { name: 'gateway', label: 'Pay with', type: 'select', value: gateways[0], options: gateways.map((g) => [g, g[0].toUpperCase() + g.slice(1)]) },
          { name: 'promo_code', label: 'Promo code (optional)', maxlength: 40 },
        ],
        onSubmit: async (v) => {
          const res = await api('/api/payments/checkout', { method: 'POST', body: { plan_code: pl.code, gateway: v.gateway, promo_code: v.promo_code || null } });
          // Redirect only starts checkout. Access is granted only after the server verifies the payment.
          window.location.href = res.checkout_url;
        },
      })))),
    section('Payment history', table([
      { label: 'Date', render: (t) => fmtDate(t.created_at) },
      { label: 'Reference', render: (t) => t.reference },
      { label: 'Amount', render: (t) => (Number(t.amount_minor) / 100).toLocaleString('en-NG', { style: 'currency', currency: t.currency }) },
      { label: 'Status', render: (t) => statusBadge(t.status) },
      { label: '', render: (t) => t.status === 'pending' ? el('button', { className: 'small', onclick: () => verifyPayment(t.reference) }, 'Check status') : '' },
    ], history.items, 'No payments yet.')));
});

async function verifyPayment(reference) {
  try {
    const res = await api(`/api/payments/verify/${encodeURIComponent(reference)}`);
    toast(res.status === 'successful' || res.status === 'success' ? 'Payment confirmed. Thank you!' : 'Payment status: ' + res.status);
    route();
  } catch (e) { toast(errorMessage(e), true); }
}

function renderBillingReturn() {
  const ref = new URLSearchParams(location.search).get('reference') || new URLSearchParams(location.search).get('trxref') || '';
  setView(el('div', { className: 'card narrow' }, el('h1', {}, 'Checking your payment…'), el('p', { className: 'muted' }, 'We are confirming the payment with the payment provider. This does not complete until the server verifies it.')));
  if (!ref) return setView(notice('No payment reference was found.', 'bad'));
  const attempt = async (n) => {
    try {
      const res = await api(`/api/payments/verify/${encodeURIComponent(ref)}`);
      if (res.status === 'successful' || res.status === 'success') {
        setView(notice('Payment confirmed. Your subscription is active.', 'ok'), el('a', { className: 'btn primary', href: '#/billing' }, 'Go to billing'));
      } else if (res.status === 'pending' && n < 5) {
        setTimeout(() => attempt(n + 1), 3000);
      } else {
        setView(notice('Payment status: ' + res.status + '. If money left your account, contact support.', 'bad'), el('a', { href: '#/support' }, 'Contact support'));
      }
    } catch (e) { setView(notice(errorMessage(e), 'bad')); }
  };
  if (!state.me) return setView(notice('Sign in to confirm your payment.', ''), el('a', { className: 'btn primary', href: '#/login' }, 'Sign in'));
  attempt(0);
}

/* ----------------------------------------------------------- support */
routes.set('support', async (parts) => {
  if (!feature('admin.support')) return setView(notice('Support is currently unavailable.', 'bad'));
  if (parts[0]) return renderTicket(Number(parts[0]));
  const data = await api('/api/support/tickets');
  setView(el('h1', {}, 'Help & support'),
    section('Open a ticket', form({
      submit: 'Send', resetOnSuccess: true,
      fields: [
        { name: 'category', label: 'Topic', type: 'select', value: 'general', options: [['general', 'General'], ['account_recovery', 'Account recovery'], ['appeal', 'Appeal a decision'], ['safety', 'Safety'], ['payments', 'Payments']] },
        { name: 'subject', label: 'Subject', required: true, maxlength: 150 },
        { name: 'body', label: 'Message', type: 'textarea', required: true, maxlength: 4000 },
      ],
      onSubmit: async (v) => { const r = await api('/api/support/tickets', { method: 'POST', body: v }); toast('Ticket #' + r.id + ' created.'); go('#/support/' + r.id); },
    })),
    section('Your tickets', table([
      { label: '#', render: (t) => el('a', { href: '#/support/' + t.id }, String(t.id)) },
      { label: 'Subject', render: (t) => t.subject },
      { label: 'Status', render: (t) => statusBadge(t.status) },
      { label: 'Updated', render: (t) => fmtDate(t.updated_at) },
    ], data.items, 'No tickets yet.')));
});

async function renderTicket(id) {
  const data = await api(`/api/support/tickets/${id}`);
  const msgs = (data.messages || []).map((m) => el('div', { className: 'bubble' + (m.is_staff ? '' : ' mine') },
    el('strong', {}, m.is_staff ? 'Support' : 'You'), el('div', {}, m.body), el('div', { className: 'meta' }, fmtDate(m.created_at))));
  setView(el('p', {}, el('a', { href: '#/support' }, '← Tickets')), el('h1', {}, data.ticket.subject),
    el('p', {}, statusBadge(data.ticket.status)), el('div', { className: 'chat' }, msgs),
    data.ticket.status === 'closed' || data.ticket.status === 'resolved' ? notice('This ticket is closed. Open a new ticket if you still need help.', '') :
      form({ submit: 'Reply', resetOnSuccess: true, fields: [{ name: 'body', label: 'Reply', type: 'textarea', required: true, maxlength: 4000 }],
        onSubmit: async (v) => { await api(`/api/support/tickets/${id}/messages`, { method: 'POST', body: { body: v.body } }); renderTicket(id); } }));
}

/* ----------------------------------------------------------- settings & account */
routes.set('settings', async (_p, q) => {
  const data = await api('/api/account/settings');
  const s = data.settings || data;
  const pwForce = q.get('password') === '1' || !!(state.me && state.me.must_change_password);
  setView(el('h1', {}, 'Settings'),
    pwForce ? notice('Please choose a new password to continue.', 'bad') : null,
    section('Privacy & notifications', form({
      submit: 'Save', fields: [
        { name: 'allow_calls', label: 'Allow calls from matches', type: 'checkbox', value: !!s.allow_calls },
        { name: 'notify_messages', label: 'Notify me about messages', type: 'checkbox', value: s.notify_messages !== 0 && s.notify_messages !== false },
        { name: 'notify_calls', label: 'Notify me about calls', type: 'checkbox', value: s.notify_calls !== 0 && s.notify_calls !== false },
        { name: 'notify_community', label: 'Notify me about community activity', type: 'checkbox', value: s.notify_community !== 0 && s.notify_community !== false },
      ],
      onSubmit: async (v) => { await api('/api/account/settings', { method: 'PUT', body: v }); toast('Settings saved.'); },
    })),
    section('Change password', form({
      submit: 'Change password', resetOnSuccess: true, fields: [
        { name: 'current_password', label: 'Current password', type: 'password', required: true },
        { name: 'new_password', label: 'New password', type: 'password', required: true },
      ],
      onSubmit: async (v) => { const r = await api('/api/auth/password/change', { method: 'POST', body: v }); toast(r.message); if (state.me) state.me.must_change_password = false; },
    })),
    section('Delete account', el('p', { className: 'muted' }, 'Deleting your account removes your profile, photos and messages from view and ends all matches. Some records are kept as required by law or for fraud and payment audit.'),
      form({ submit: 'Delete my account', className: 'row', fields: [{ name: 'password', label: 'Confirm your password', type: 'password', required: true }],
        onSubmit: async (v) => {
          if (!confirmDo('This cannot be undone. Delete your account?')) return;
          await api('/api/account/delete', { method: 'POST', body: { password: v.password } });
          state.me = null; renderNav(); go('#/'); toast('Your account has been deleted.');
        } })));
});

/* ----------------------------------------------------------- start */
// Exposed for the staff module and for tests that rely on the same helpers.
export { state, api, el, setView, toast, errorMessage, go, route, applySession, renderNav, feature, can, isStaff };

boot();
