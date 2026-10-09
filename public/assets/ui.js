// Shared helpers: API client, safe DOM builder, toasts, tables and forms.
// All user-supplied text is inserted with textContent (never innerHTML).

export const state = { me: null, csrf: '', features: {}, perms: new Set(), staffRoles: [], lockdown: false };

export class ApiError extends Error {
  constructor(status, code, message, details) {
    super(message);
    this.status = status;
    this.code = code;
    this.details = details || {};
  }
}

export async function api(path, { method = 'GET', body, form, raw = false } = {}) {
  const headers = { Accept: 'application/json' };
  const init = { method, headers, credentials: 'same-origin' };
  if (method !== 'GET' && method !== 'HEAD') headers['X-CSRF-Token'] = state.csrf;
  if (form) {
    init.body = form; // FormData: browser sets multipart boundary
  } else if (body !== undefined) {
    headers['Content-Type'] = 'application/json';
    init.body = JSON.stringify(body);
  }
  const res = await fetch(path, init);
  if (raw) {
    if (!res.ok) throw new ApiError(res.status, 'HTTP_ERROR', 'Could not load that file.');
    return res;
  }
  let data = null;
  try { data = await res.json(); } catch (_) { data = null; }
  if (!res.ok) {
    const err = (data && data.error) || {};
    throw new ApiError(res.status, err.code || 'HTTP_' + res.status, err.message || 'Something went wrong.', err.details);
  }
  return data || {};
}

/** Element builder. attrs: className, text handled via children; on<event> handlers; other keys set as attributes. */
export function el(tag, attrs = {}, ...children) {
  const node = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs || {})) {
    if (v === null || v === undefined || v === false) continue;
    if (k === 'className') node.className = v;
    else if (k.startsWith('on') && typeof v === 'function') node.addEventListener(k.slice(2), v);
    else if (k === 'dataset') Object.assign(node.dataset, v);
    // CSSOM style assignment is allowed under CSP style-src 'self' (no inline style attributes).
    else if (k === 'style') node.style.cssText = v;
    else if (v === true) node.setAttribute(k, '');
    else node.setAttribute(k, String(v));
  }
  const add = (c) => {
    if (c === null || c === undefined || c === false) return;
    if (Array.isArray(c)) return c.forEach(add);
    node.appendChild(c instanceof Node ? c : document.createTextNode(String(c)));
  };
  children.forEach(add);
  return node;
}

export function $(sel, root = document) { return root.querySelector(sel); }

export function setView(...nodes) {
  const view = document.getElementById('view');
  const clean = nodes.flat().filter((n) => n !== null && n !== undefined && n !== false)
    .map((n) => (n instanceof Node ? n : document.createTextNode(String(n))));
  view.replaceChildren(...clean);
  view.focus({ preventScroll: true });
  window.scrollTo(0, 0);
}

export function toast(message, bad = false) {
  const t = document.getElementById('toast');
  t.textContent = message;
  t.className = 'toast' + (bad ? ' bad' : '');
  t.hidden = false;
  clearTimeout(toast._t);
  toast._t = setTimeout(() => { t.hidden = true; }, 3800);
}

export function errorMessage(e) {
  if (e instanceof ApiError) return e.message;
  if (e instanceof TypeError && /fetch|network|Failed to/i.test(e.message)) return 'Network problem. Please try again.';
  console.error(e);
  return 'Something went wrong. Please try again.';
}

export function fmtDate(v) {
  if (!v) return '—';
  const d = new Date(String(v).replace(' ', 'T'));
  return isNaN(d) ? String(v) : d.toLocaleString();
}

export function naira(minor, currency = 'NGN') {
  const n = Number(minor || 0) / 100;
  try { return new Intl.NumberFormat('en-NG', { style: 'currency', currency }).format(n); }
  catch (_) { return currency + ' ' + n.toFixed(2); }
}

export function badge(text, kind = '') {
  return el('span', { className: 'badge ' + kind }, text);
}

export function statusBadge(status) {
  const s = String(status || '');
  const good = ['approved', 'active', 'resolved', 'successful', 'success', 'refunded', 'processed', 'accepted', 'ended'];
  const warn = ['pending', 'submitted', 'in_review', 'open', 'requested', 'awaiting_user', 'ringing', 'draft', 'in_progress'];
  const bad = ['rejected', 'failed', 'suspended', 'declined', 'missed', 'closed', 'unmatched', 'removed'];
  const kind = good.includes(s) ? 'ok' : warn.includes(s) ? 'warn' : bad.includes(s) ? 'bad' : '';
  return badge(s.replace(/_/g, ' ') || '—', kind);
}

/** table({columns:[{label, render(row)}], rows, empty}) */
export function table(columns, rows, empty = 'Nothing here yet.') {
  if (!rows || rows.length === 0) return el('p', { className: 'muted' }, empty);
  return el('div', { className: 'tablewrap' },
    el('table', { className: 'table' },
      el('thead', {}, el('tr', {}, columns.map((c) => el('th', {}, c.label)))),
      el('tbody', {}, rows.map((r) => el('tr', {}, columns.map((c) => el('td', {}, c.render(r))))))));
}

/**
 * form({fields, submit, onSubmit}) → <form>
 * field: {name, label, type ('text'|'email'|'password'|'number'|'date'|'textarea'|'select'|'checkbox'|'file'|'tel'),
 *         options:[[value,label]], value, required, help, accept, min, max, maxlength, autocomplete}
 */
export function form({ fields = [], submit = 'Save', onSubmit, className = '', resetOnSuccess = false, note = null }) {
  const status = el('p', { className: 'muted', role: 'status' });
  const btn = el('button', { type: 'submit', className: 'primary' }, submit);
  const f = el('form', { className, novalidate: false });
  const inputs = {};
  for (const field of fields) {
    const id = 'f_' + Math.random().toString(36).slice(2, 9);
    let input;
    if (field.type === 'textarea') {
      input = el('textarea', { id, name: field.name, required: field.required, maxlength: field.maxlength }, field.value ?? '');
    } else if (field.type === 'select') {
      input = el('select', { id, name: field.name, required: field.required },
        (field.options || []).map(([v, l]) => el('option', { value: v, selected: String(field.value ?? '') === String(v) }, l)));
    } else if (field.type === 'checkbox') {
      input = el('input', { id, name: field.name, type: 'checkbox', checked: !!field.value });
      f.appendChild(el('label', { className: 'check', for: id }, input, field.label));
      inputs[field.name] = { input, field };
      continue;
    } else {
      input = el('input', {
        id, name: field.name, type: field.type || 'text', required: field.required, placeholder: field.placeholder,
        value: field.value ?? '', min: field.min, max: field.max, maxlength: field.maxlength, accept: field.accept,
        autocomplete: field.autocomplete, multiple: field.multiple, step: field.step,
      });
    }
    inputs[field.name] = { input, field };
    f.appendChild(el('div', {}, el('label', { for: id }, field.label), input,
      field.help ? el('div', { className: 'muted' }, field.help) : null));
  }
  f.append(note ? el('p', { className: 'muted' }, note) : '', el('div', { className: 'row', style: 'margin-top:.8rem' }, btn), status);
  f.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    if (!f.reportValidity()) return;
    const values = {};
    const files = {};
    for (const [name, { input, field }] of Object.entries(inputs)) {
      if (field.type === 'checkbox') values[name] = input.checked;
      else if (field.type === 'file') files[name] = input.files;
      else if (field.type === 'number') values[name] = input.value === '' ? null : Number(input.value);
      else values[name] = input.value;
    }
    btn.disabled = true;
    status.textContent = 'Working…';
    status.className = 'muted';
    try {
      await onSubmit(values, files, f);
      status.textContent = '';
      if (resetOnSuccess) f.reset();
    } catch (e) {
      status.textContent = errorMessage(e);
      status.className = 'notice bad';
    } finally {
      btn.disabled = false;
    }
  });
  return f;
}

/** Builds a FormData from plain values and file lists. */
export function toFormData(values = {}, files = {}) {
  const fd = new FormData();
  for (const [k, v] of Object.entries(values)) fd.append(k, v === null || v === undefined ? '' : String(v));
  for (const [k, list] of Object.entries(files)) {
    if (!list) continue;
    for (const file of list) fd.append(k + (list.length > 1 ? '[]' : ''), file);
  }
  return fd;
}

/** Confirm via native dialog; prompt for reason. */
export function askReason(message, required = true) {
  const v = window.prompt(message, '');
  if (v === null) return null;
  const t = v.trim();
  if (required && t.length < 3) { toast('Please give a reason (at least 3 characters).', true); return null; }
  return t;
}

export function confirmDo(message) { return window.confirm(message); }

/** Loads a protected binary (blob URL) using the session cookie. Caller must revoke it. */
export async function blobUrl(path) {
  const res = await api(path, { raw: true });
  return URL.createObjectURL(await res.blob());
}

/** Simple DOM-based pagination helper for cursor APIs. */
export function list(items, render, empty) {
  if (!items || items.length === 0) return el('p', { className: 'muted' }, empty || 'Nothing here yet.');
  return el('div', {}, items.map(render));
}

export function section(title, ...children) {
  return el('section', { className: 'card' }, el('h2', {}, title), ...children);
}

export function notice(text, kind = '') {
  return el('div', { className: 'notice ' + kind }, text);
}
