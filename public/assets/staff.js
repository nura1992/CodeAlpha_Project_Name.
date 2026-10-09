// Staff console. Sections are shown only when the signed-in staff member holds the matching permission
// (taken from /api/auth/me). This is presentation only: every endpoint re-checks the permission server-side.
import { state, api, el, setView, toast, errorMessage, fmtDate, badge, statusBadge, table, form, toFormData,
  askReason, confirmDo, section, notice, list, blobUrl, ApiError } from './ui.js';

const SECTIONS = [
  ['verification', 'Verification', 'verification.review'],
  ['photos', 'Photo review', 'profiles.moderate'],
  ['moderation', 'Reports', 'moderation.review'],
  ['users', 'Members', 'users.view'],
  ['support', 'Support', 'support.view'],
  ['finance', 'Transactions', 'payments.view'],
  ['reconciliation', 'Reconciliation', 'payments.reconcile'],
  ['plans', 'Plans', 'payments.view'],
  ['gateways', 'Gateways', 'payments.configure'],
  ['campaigns', 'Campaigns', 'campaigns.manage'],
  ['advertisers', 'Advertisers', 'ads.manage'],
  ['ads', 'Ads', 'ads.manage'],
  ['announcements', 'Announcements', 'community.announce'],
  ['staff', 'Staff accounts', 'staff.manage'],
  ['roles', 'Roles', 'roles.view'],
  ['features', 'Feature centre', 'features.manage'],
  ['settings', 'Settings', 'settings.manage'],
  ['audit', 'Audit log', 'audit.view'],
  ['analytics', 'Analytics', 'analytics.view'],
];

const RENDER = {};

export async function staffRoutes(parts, q) {
  if (!state.me || state.me.account_type !== 'staff') return setView(notice('This area is for staff accounts only.', 'bad'));
  const allowed = SECTIONS.filter(([, , perm]) => state.perms.has(perm));
  if (allowed.length === 0) return setView(notice('Your staff account has no permissions yet. Ask a Super Admin to assign a role.', 'bad'));
  const current = parts[0] && allowed.some(([k]) => k === parts[0]) ? parts[0] : allowed[0][0];
  const nav = el('nav', { className: 'row', 'aria-label': 'Staff sections' },
    allowed.map(([k, label]) => el('a', { className: 'btn ' + (k === current ? 'primary' : ''), href: '#/staff/' + k }, label)));
  const body = el('div', {}, el('p', { className: 'muted' }, 'Loading…'));
  setView(el('h1', {}, 'Staff console'), notice('Every action here is checked on the server and written to the audit log.', ''), nav, el('div', { style: 'margin-top:1rem' }, body));
  const node = (await RENDER[current](q, parts.slice(1))) || el('p', {});
  body.replaceWith(node);
}

const perm = (p) => state.perms.has(p);
const btn = (label, onclick, cls = 'small') => el('button', { className: cls, onclick: async () => {
  try { await onclick(); } catch (e) { toast(errorMessage(e), true); }
} }, label);
const naira = (minor) => (Number(minor || 0) / 100).toLocaleString('en-NG', { style: 'currency', currency: 'NGN' });
const reloadSection = () => { const k = location.hash.split('/')[2] || ''; location.hash = '#/staff/' + k + '?r=' + Date.now(); };

/* --------------------------------------------------------- verification */
RENDER.verification = async (q) => {
  const status = q.get('status') || 'submitted';
  const data = await api('/api/staff/verifications?status=' + encodeURIComponent(status));
  const preview = el('div', {});
  return el('div', {},
    el('h2', {}, 'Verification queue'),
    el('p', {}, ...['submitted', 'in_review', 'approved', 'rejected'].map((s) =>
      el('a', { className: 'btn ' + (s === status ? 'primary' : ''), href: '#/staff/verification?status=' + s, style: 'margin-right:.3rem' }, s.replace('_', ' ')))),
    table([
      { label: '#', render: (r) => r.id },
      { label: 'Member', render: (r) => r.username || r.user_id },
      { label: 'Status', render: (r) => statusBadge(r.status) },
      { label: 'Submitted', render: (r) => fmtDate(r.submitted_at) },
      { label: 'Reviewer', render: (r) => r.reviewer_name || r.reviewed_by || r.claimed_by || '—' },
      { label: 'Actions', render: (r) => el('div', { className: 'row' },
        r.status === 'submitted' && perm('verification.review') ? btn('Claim', async () => { await api(`/api/staff/verifications/${r.id}/claim`, { method: 'POST', body: {} }); toast('Claimed.'); reloadSection(); }) : null,
        ['in_review', 'submitted'].includes(r.status) && perm('verification.review') ? btn('View video', async () => {
          const link = await api(`/api/staff/verifications/${r.id}/media-link`, { method: 'POST', body: {} });
          preview.replaceChildren(el('h3', {}, 'Video #' + r.id + ' (link expires ' + fmtDate(link.expires_at) + ')'),
            el('video', { controls: true, preload: 'metadata', src: link.url, className: 'thumb', style: 'max-height:420px' }));
          preview.scrollIntoView({ behavior: 'smooth' });
        }) : null,
        ['in_review', 'submitted'].includes(r.status) && perm('verification.approve') ? btn('Approve', async () => {
          if (!confirmDo('Approve this verification? Decision is logged with your name and time.')) return;
          await api(`/api/staff/verifications/${r.id}/approve`, { method: 'POST', body: {} }); toast('Approved.'); reloadSection();
        }) : null,
        ['in_review', 'submitted'].includes(r.status) && perm('verification.reject') ? btn('Reject', async () => {
          const reason = askReason('Reason for rejection (shown to the member):');
          if (!reason) return;
          await api(`/api/staff/verifications/${r.id}/reject`, { method: 'POST', body: { reason } }); toast('Rejected.'); reloadSection();
        }, 'small danger') : null) },
    ], r0(data.items), 'Nothing in this queue.'),
    preview);
};

/* --------------------------------------------------------- photo review */
RENDER.photos = async () => {
  const data = await api('/api/staff/photos/pending');
  return el('div', {}, el('h2', {}, 'Pending profile photos'),
    el('div', { className: 'grid' }, (data.items || []).map((p) => el('div', { className: 'card' },
      el('img', { className: 'thumb', src: `/api/staff/photos/${p.id}/media`, alt: 'Pending photo', loading: 'lazy' }),
      el('p', {}, el('strong', {}, p.username || 'Member #' + p.user_id), ' · ', fmtDate(p.created_at)),
      el('div', { className: 'row' },
        btn('Approve', async () => { await api(`/api/staff/photos/${p.id}/review`, { method: 'POST', body: { decision: 'approve' } }); toast('Approved.'); reloadSection(); }, 'small primary'),
        btn('Reject', async () => {
          const reason = askReason('Reason (shown to the member):');
          if (!reason) return;
          await api(`/api/staff/photos/${p.id}/review`, { method: 'POST', body: { decision: 'reject', reason } }); toast('Rejected.'); reloadSection();
        }, 'small danger'))))),
    (data.items || []).length ? null : el('p', { className: 'muted' }, 'No photos waiting for review.'));
};

/* --------------------------------------------------------- reports */
RENDER.moderation = async (q) => {
  const status = q.get('status') || 'open';
  const data = await api('/api/staff/reports?status=' + encodeURIComponent(status));
  return el('div', {}, el('h2', {}, 'Reports'),
    el('p', {}, ...['open', 'reviewing', 'resolved'].map((s) => el('a', { className: 'btn ' + (s === status ? 'primary' : ''), href: '#/staff/moderation?status=' + s, style: 'margin-right:.3rem' }, s))),
    table([
      { label: '#', render: (r) => r.id },
      { label: 'Target', render: (r) => `${r.target_type} #${r.target_id}` },
      { label: 'Reason', render: (r) => r.reason },
      { label: 'Details', render: (r) => r.details || '—' },
      { label: 'Status', render: (r) => statusBadge(r.status) },
      { label: 'Created', render: (r) => fmtDate(r.created_at) },
      { label: 'Actions', render: (r) => el('div', { className: 'row' },
        r.status !== 'resolved' && perm('moderation.review') ? btn('Claim', async () => { await api(`/api/staff/reports/${r.id}/claim`, { method: 'POST', body: {} }); reloadSection(); }) : null,
        r.status !== 'resolved' && perm('moderation.review') ? btn('Resolve', async () => {
          const action = window.prompt('Action: dismiss, no_action, hide_content, remove_content, suspend_user', 'dismiss');
          if (!action) return;
          const note = askReason('Resolution note (required):');
          if (!note) return;
          await api(`/api/staff/reports/${r.id}/resolve`, { method: 'POST', body: { action: action.trim(), note } });
          toast('Resolved.'); reloadSection();
        }) : null) },
    ], data.items, 'No reports in this queue.'));
};

/* --------------------------------------------------------- members */
RENDER.users = async (q) => {
  const search = q.get('q') || '';
  const data = await api('/api/staff/users?q=' + encodeURIComponent(search));
  const detailBox = el('div', {});
  return el('div', {}, el('h2', {}, 'Members'),
    form({ submit: 'Search', className: 'row', fields: [{ name: 'q', label: 'Username, email or phone', value: search }],
      onSubmit: async (v) => { location.hash = '#/staff/users?q=' + encodeURIComponent(v.q.trim()); } }),
    table([
      { label: 'ID', render: (r) => r.id },
      { label: 'Username', render: (r) => r.username },
      { label: 'Contact', render: (r) => r.email || r.phone || 'hidden (no PII access)' },
      { label: 'Status', render: (r) => statusBadge(r.status) },
      { label: 'Approval', render: (r) => statusBadge(r.approval_status || '—') },
      { label: 'Actions', render: (r) => el('div', { className: 'row' },
        btn('View', async () => {
          const d = await api('/api/staff/users/' + r.id);
          detailBox.replaceChildren(section('Member #' + r.id, ...Object.entries(d.user || d).filter(([, v]) => typeof v !== 'object' || v === null)
            .map(([k, v]) => el('p', {}, el('strong', {}, k + ': '), String(v ?? '—')))));
        }),
        perm('users.suspend') && r.status !== 'suspended' ? btn('Suspend', async () => {
          const reason = askReason('Reason for suspension (required; the member is signed out):');
          if (!reason) return;
          await api(`/api/staff/users/${r.id}/suspend`, { method: 'POST', body: { reason } }); toast('Suspended.'); reloadSection();
        }, 'small danger') : null,
        perm('users.suspend') && r.status === 'suspended' ? btn('Restore', async () => {
          const reason = askReason('Reason for restoring:');
          if (!reason) return;
          await api(`/api/staff/users/${r.id}/restore`, { method: 'POST', body: { reason } }); toast('Restored.'); reloadSection();
        }) : null) },
    ], data.items, 'No members match.'), detailBox);
};

/* --------------------------------------------------------- support */
RENDER.support = async (q, rest) => {
  if (rest[0]) return renderStaffTicket(Number(rest[0]));
  const status = q.get('status') || 'open';
  const data = await api('/api/staff/support/tickets?status=' + encodeURIComponent(status));
  return el('div', {}, el('h2', {}, 'Support queue'),
    el('p', {}, ...['open', 'awaiting_user', 'resolved', 'closed'].map((s) => el('a', { className: 'btn ' + (s === status ? 'primary' : ''), href: '#/staff/support?status=' + s, style: 'margin-right:.3rem' }, s.replace('_', ' ')))),
    table([
      { label: '#', render: (t) => el('a', { href: '#/staff/support/' + t.id }, String(t.id)) },
      { label: 'Subject', render: (t) => t.subject },
      { label: 'Category', render: (t) => t.category },
      { label: 'Priority', render: (t) => t.priority || 'normal' },
      { label: 'Status', render: (t) => statusBadge(t.status) },
      { label: 'Updated', render: (t) => fmtDate(t.updated_at) },
    ], data.items, 'No tickets in this queue.'));
};


/* --------------------------------------------------------- finance: transactions */
RENDER.finance = async (q, rest) => {
  if (rest[0]) return renderTransaction(Number(rest[0]));
  const filters = { status: q.get('status') || '', gateway: q.get('gateway') || '', q: q.get('q') || '' };
  const data = await api('/api/staff/transactions?' + new URLSearchParams(Object.entries(filters).filter(([, v]) => v)).toString());
  const detail = el('div', {});
  const csvFrom = new Date(Date.now() - 30 * 864e5).toISOString().slice(0, 10);
  const csvTo = new Date().toISOString().slice(0, 10);
  const csvBtn = perm('payments.reports') ? btn('Download CSV (last 30 days)', async () => {
    const url = await blobUrl(`/api/staff/reports/payments.csv?from=${csvFrom}&to=${csvTo}`);
    const a = el('a', { href: url, download: `payments-${csvFrom}-${csvTo}.csv` });
    document.body.append(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 30000);
  }, 'small') : null;
  return el('div', {}, el('h2', {}, 'Transactions'),
    form({ submit: 'Filter', className: 'row', fields: [
      { name: 'q', label: 'Reference or member', value: filters.q },
      { name: 'status', label: 'Status', type: 'select', value: filters.status, options: [['', 'Any'], ['pending', 'Pending'], ['successful', 'Successful'], ['failed', 'Failed'], ['abandoned', 'Abandoned'], ['refunded', 'Refunded'], ['partially_refunded', 'Partly refunded']] },
      { name: 'gateway', label: 'Gateway', type: 'select', value: filters.gateway, options: [['', 'Any'], ['paystack', 'Paystack'], ['flutterwave', 'Flutterwave']] },
    ], onSubmit: async (v) => { location.hash = '#/staff/finance?' + new URLSearchParams(Object.entries(v).filter(([, x]) => x)).toString(); } }),
    el('p', {}, csvBtn),
    table([
      { label: 'Reference', render: (t) => el('a', { href: '#/staff/finance/' + t.id }, t.reference) },
      { label: 'Member', render: (t) => t.username || t.user_id },
      { label: 'Gateway', render: (t) => t.gateway },
      { label: 'Amount', render: (t) => naira(t.amount_minor) },
      { label: 'Status', render: (t) => statusBadge(t.status) },
      { label: 'Created', render: (t) => fmtDate(t.created_at) },
    ], data.items, 'No transactions match.'), detail);
};


/* --------------------------------------------------------- reconciliation */
RENDER.reconciliation = async (q) => {
  const date = q.get('date') || new Date().toISOString().slice(0, 10);
  const data = await api('/api/staff/reconciliation?date=' + date);
  const sumRows = Object.entries(data.summary || {}).map(([k, v]) => ({ k, v }));
  return el('div', {}, el('h2', {}, 'Reconciliation'),
    form({ submit: 'Show day', className: 'row', fields: [{ name: 'date', label: 'Date', type: 'date', value: date }],
      onSubmit: async (v) => { location.hash = '#/staff/reconciliation?date=' + v.date; } }),
    section('Summary', table([{ label: 'Metric', render: (r) => r.k }, { label: 'Value', render: (r) => typeof r.v === 'object' ? JSON.stringify(r.v) : String(r.v) }], sumRows)),
    section('Pending for more than 1 hour', table([{ label: 'Reference', render: (r) => r.reference }, { label: 'Created', render: (r) => fmtDate(r.created_at) }], data.stale_pending, 'None.')),
    section('Paid without an active subscription', table([{ label: 'Reference', render: (r) => r.reference }, { label: 'Status', render: (r) => r.status }], data.paid_without_subscription, 'None.')),
    section('Amount mismatches (never activated)', table([{ label: 'Reference', render: (r) => r.reference }, { label: 'Event', render: (r) => r.event_type || r.details || '—' }], data.amount_mismatches, 'None.')));
};

/* --------------------------------------------------------- gateways */
RENDER.gateways = async () => {
  const data = await api('/api/staff/gateways');
  const rows = data.items || [data.gateway].filter(Boolean);
  return el('div', {}, el('h2', {}, 'Payment gateways'),
    notice('Secret keys are write-only. Once saved they are encrypted on the server and never shown again. Use test mode until you are ready to go live.', ''),
    ...rows.map((g) => section(g.label || g.code || g.gateway || 'Gateway',
      el('p', {}, 'Mode: ', badge(g.mode || 'test'), ' · Enabled: ', badge(g.is_enabled || g.enabled ? 'yes' : 'no'),
        ' · Secret: ', badge(g.secret_configured || g.has_secret ? 'configured' : 'missing')),
      form({ submit: 'Save gateway', fields: [
        { name: 'enabled', label: 'Enabled for checkout', type: 'checkbox', value: !!(g.is_enabled || g.enabled) },
        { name: 'mode', label: 'Mode', type: 'select', value: g.mode || 'test', options: [['test', 'Test'], ['live', 'Live']] },
        { name: 'public_key', label: 'Public key', value: g.public_key || '' },
        { name: 'secret_key', label: 'Secret key (leave blank to keep current)', type: 'password', autocomplete: 'off' },
        { name: 'webhook_secret', label: 'Webhook secret (leave blank to keep current)', type: 'password', autocomplete: 'off' },
        { name: 'confirm_live', label: 'I confirm switching to LIVE money movement', type: 'checkbox', value: false },
        { name: 'reason', label: 'Reason for change (audited)', value: '' },
      ], onSubmit: async (v) => {
        const body = { ...v };
        if (!body.secret_key) delete body.secret_key;
        if (!body.webhook_secret) delete body.webhook_secret;
        await api('/api/staff/gateways/' + (g.code || g.gateway), { method: 'PUT', body });
        toast('Gateway saved.'); reloadSection();
      } }))));
};

/* --------------------------------------------------------- plans */
RENDER.plans = async () => {
  const data = await api('/api/staff/plans');
  return el('div', {}, el('h2', {}, 'Subscription plans'),
    table([
      { label: 'Code', render: (p) => p.code },
      { label: 'Name', render: (p) => p.name },
      { label: 'Price', render: (p) => naira(p.amount_minor) + ' / ' + p.billing_period },
      { label: 'Active', render: (p) => statusBadge(p.is_active ? 'active' : 'closed') },
    ], data.items, 'No plans yet.'),
    perm('payments.plans') ? section('Create or update a plan (leave ID empty to create)', form({ submit: 'Save plan', fields: [
      { name: 'id', label: 'Plan ID (to update)', type: 'number', min: 1 },
      { name: 'code', label: 'Code', required: true, maxlength: 40, help: 'e.g. premium_monthly' },
      { name: 'name', label: 'Name', required: true, maxlength: 120 },
      { name: 'description', label: 'Description', maxlength: 255 },
      { name: 'amount_naira', label: 'Price (NGN, minimum 100)', type: 'number', min: 100, required: true },
      { name: 'billing_period', label: 'Billing period', type: 'select', value: 'monthly', options: [['monthly', 'Monthly'], ['quarterly', 'Quarterly'], ['yearly', 'Yearly']] },
      { name: 'entitlements', label: 'Entitlements (comma separated)', value: 'priority_discovery' },
      { name: 'is_active', label: 'Active', type: 'checkbox', value: true },
    ], onSubmit: async (v) => {
      const body = { ...v, entitlements: String(v.entitlements || '').split(',').map((s) => s.trim()).filter(Boolean) };
      const id = body.id; delete body.id;
      await api(id ? `/api/staff/plans/${id}` : '/api/staff/plans', { method: id ? 'PUT' : 'POST', body });
      toast('Plan saved.'); reloadSection();
    } })) : null);
};

/* --------------------------------------------------------- campaigns */
RENDER.campaigns = async () => {
  const data = await api('/api/staff/campaigns');
  return el('div', {}, el('h2', {}, 'Campaigns'),
    notice('Campaign messages go only to members who opted in, and every send is recorded. Advertisers never see member data.', ''),
    table([
      { label: 'Name', render: (c) => c.name },
      { label: 'Status', render: (c) => statusBadge(c.status) },
      { label: 'Budget', render: (c) => naira(c.budget_minor) },
      { label: 'Promo', render: (c) => c.promo_code || '—' },
      { label: 'Actions', render: (c) => el('div', { className: 'row' },
        perm('campaigns.manage') ? btn('Send in-app', async () => {
          if (!confirmDo('Send this campaign to all active members who allow notifications?')) return;
          const r = await api(`/api/staff/campaigns/${c.id}/send`, { method: 'POST', body: {} });
          toast('Sent to ' + (r.in_app_recipients ?? r.sent ?? 0) + ' members.');
        }) : null,
        btn('Analytics', async () => {
          const a = await api(`/api/staff/campaigns/${c.id}/analytics`);
          toast('Impressions: ' + (a.impressions ?? 0) + ' · Clicks: ' + (a.clicks ?? 0));
        })) },
    ], data.items, 'No campaigns yet.'),
    section('New campaign', campaignForm()));
};

function campaignForm() {
  return form({ submit: 'Create campaign', resetOnSuccess: true, fields: [
    { name: 'name', label: 'Name', required: true, maxlength: 120 },
    { name: 'objective', label: 'Objective', maxlength: 255 },
    { name: 'budget_naira', label: 'Budget (NGN)', type: 'number', min: 0 },
    { name: 'promo_code', label: 'Promo code', maxlength: 40 },
    { name: 'discount_percent', label: 'Discount %', type: 'number', min: 1, max: 90 },
    { name: 'starts_at', label: 'Starts', type: 'date' },
    { name: 'ends_at', label: 'Ends', type: 'date' },
    { name: 'status', label: 'Status', type: 'select', value: 'draft', options: [['draft', 'Draft'], ['scheduled', 'Scheduled'], ['active', 'Active'], ['paused', 'Paused']] },
    { name: 'ch_in_app', label: 'In-app notification', type: 'checkbox', value: true },
    { name: 'ch_email', label: 'Email (opted-in members)', type: 'checkbox', value: false },
  ], onSubmit: async (v) => {
    const channels = [v.ch_in_app && 'in_app', v.ch_email && 'email'].filter(Boolean);
    const body = { name: v.name, objective: v.objective, budget_naira: v.budget_naira || 0, promo_code: v.promo_code || null,
      discount_percent: v.discount_percent || null, starts_at: v.starts_at || null, ends_at: v.ends_at || null, status: v.status, channels, audience: [] };
    await api('/api/staff/campaigns', { method: 'POST', body });
    toast('Campaign created.'); reloadSection();
  } });
}

/* --------------------------------------------------------- advertisers & ads */
RENDER.advertisers = async () => {
  const data = await api('/api/staff/advertisers');
  return el('div', {}, el('h2', {}, 'Advertisers'),
    table([
      { label: 'Company', render: (a) => a.company_name },
      { label: 'Contact', render: (a) => a.contact_email },
      { label: 'Status', render: (a) => statusBadge(a.status) },
      { label: 'Actions', render: (a) => el('div', { className: 'row' },
        a.status !== 'approved' && perm('ads.review') ? btn('Approve', async () => { await api(`/api/staff/advertisers/${a.id}/status`, { method: 'POST', body: { status: 'approved' } }); reloadSection(); }) : null,
        a.status !== 'suspended' && perm('ads.review') ? btn('Suspend', async () => { await api(`/api/staff/advertisers/${a.id}/status`, { method: 'POST', body: { status: 'suspended' } }); reloadSection(); }, 'small danger') : null) },
    ], data.items, 'No advertisers yet.'),
    section('Add advertiser', form({ submit: 'Add advertiser', resetOnSuccess: true, fields: [
      { name: 'company_name', label: 'Company name', required: true, maxlength: 150 },
      { name: 'contact_email', label: 'Contact email', type: 'email', required: true },
    ], onSubmit: async (v) => { await api('/api/staff/advertisers', { method: 'POST', body: v }); toast('Advertiser added.'); reloadSection(); } })));
};

RENDER.ads = async () => {
  const data = await api('/api/staff/ads');
  return el('div', {}, el('h2', {}, 'Advertisements'),
    notice('Ads are labelled "Sponsored". The advertiser sees only aggregate delivery numbers. Review checks copy, link and media.', ''),
    table([
      { label: 'Title', render: (a) => a.title },
      { label: 'Advertiser', render: (a) => a.company_name || a.advertiser_id },
      { label: 'Placement', render: (a) => a.placement },
      { label: 'Budget', render: (a) => naira(a.budget_minor) },
      { label: 'Spent', render: (a) => naira(a.spent_minor) },
      { label: 'Status', render: (a) => statusBadge(a.status) },
      { label: 'Actions', render: (a) => el('div', { className: 'row' },
        ['draft', 'rejected'].includes(a.status) && perm('ads.manage') ? btn('Submit', async () => { await api(`/api/staff/ads/${a.id}/submit`, { method: 'POST', body: {} }); reloadSection(); }) : null,
        a.status === 'in_review' && perm('ads.review') ? btn('Approve', async () => { await api(`/api/staff/ads/${a.id}/review`, { method: 'POST', body: { decision: 'approve', note: 'Approved after review' } }); reloadSection(); }) : null,
        a.status === 'in_review' && perm('ads.review') ? btn('Reject', async () => {
          const note = askReason('Reason for rejection (at least 10 characters, shown to advertiser):');
          if (!note) return;
          await api(`/api/staff/ads/${a.id}/review`, { method: 'POST', body: { decision: 'reject', note } }); reloadSection();
        }, 'small danger') : null,
        a.status === 'approved' && perm('ads.review') ? btn('Suspend', async () => {
          const reason = askReason('Reason for suspending this ad:');
          if (!reason) return;
          await api(`/api/staff/ads/${a.id}/suspend`, { method: 'POST', body: { reason } }); reloadSection();
        }, 'small danger') : null) },
    ], data.items, 'No ads yet.'),
    perm('ads.manage') ? section('Create ad', adForm()) : null);
};

function adForm() {
  return form({ submit: 'Create ad', resetOnSuccess: true, fields: [
    { name: 'advertiser_id', label: 'Advertiser ID', type: 'number', min: 1, required: true },
    { name: 'campaign_id', label: 'Campaign ID (optional)', type: 'number', min: 1 },
    { name: 'title', label: 'Title', required: true, maxlength: 120 },
    { name: 'body', label: 'Copy', type: 'textarea', maxlength: 500 },
    { name: 'click_url', label: 'Destination (https://)', type: 'url', required: true },
    { name: 'media_type', label: 'Media', type: 'select', value: 'text', options: [['text', 'Text only'], ['image', 'Image'], ['video', 'Video']] },
    { name: 'media', label: 'Image or video file (optional)', type: 'file', accept: 'image/jpeg,image/png,image/webp,video/mp4' },
    { name: 'placement', label: 'Placement', type: 'select', value: 'community_feed', options: [['community_feed', 'Community feed'], ['discovery', 'Discovery']] },
    { name: 'budget_naira', label: 'Budget (NGN)', type: 'number', min: 1, required: true },
    { name: 'cpm_naira', label: 'CPM (NGN per 1000 impressions)', type: 'number', min: 0 },
    { name: 'cpc_naira', label: 'CPC (NGN per click)', type: 'number', min: 0 },
    { name: 'daily_frequency_cap', label: 'Max shows per member per day', type: 'number', min: 1, max: 10, value: 3 },
  ], onSubmit: async (v, files) => {
    const fields = {};
    for (const [k, val] of Object.entries(v)) if (val !== '' && val !== null) fields[k] = val;
    await api('/api/staff/ads', { method: 'POST', form: toFormData(fields, files.media && files.media.length ? { media: files.media } : {}) });
    toast('Ad created as draft. Submit it for review when ready.'); reloadSection();
  } });
}

/* --------------------------------------------------------- announcements */
RENDER.announcements = async () => {
  const data = await api('/api/staff/announcements');
  return el('div', {}, el('h2', {}, 'Announcements'),
    table([
      { label: 'Title', render: (a) => a.title },
      { label: 'Status', render: (a) => statusBadge(a.status) },
      { label: 'Published', render: (a) => fmtDate(a.published_at) },
    ], data.items, 'No announcements.'),
    perm('community.announce') ? section('New announcement', form({ submit: 'Save', resetOnSuccess: true, fields: [
      { name: 'title', label: 'Title', required: true, maxlength: 160 },
      { name: 'body', label: 'Message', type: 'textarea', required: true, maxlength: 5000 },
      { name: 'publish', label: 'Publish now (otherwise saved as draft)', type: 'checkbox', value: false },
    ], onSubmit: async (v) => { await api('/api/staff/announcements', { method: 'POST', body: v }); toast('Saved.'); reloadSection(); } })) : null);
};

/* --------------------------------------------------------- staff accounts */
RENDER.staff = async () => {
  const [accounts, roles] = await Promise.all([api('/api/staff/accounts'), api('/api/staff/roles')]);
  const roleBoxes = (roles.items || []).map((r) => ['role_' + r.id, r.name]);
  const isSuper = state.staffRoles.some((r) => r.slug === 'super_admin');
  return el('div', {}, el('h2', {}, 'Staff accounts'),
    table([
      { label: 'ID', render: (a) => a.id },
      { label: 'Username', render: (a) => a.username },
      { label: 'Email', render: (a) => a.email || '—' },
      { label: 'Roles', render: (a) => (a.roles || []).join(', ') || '—' },
      { label: 'Status', render: (a) => statusBadge(a.status) },
      { label: 'Actions', render: (a) => el('div', { className: 'row' },
        btn('Edit roles', async () => {
          const ids = window.prompt('Role IDs, comma separated (see the Roles page for IDs):', '');
          if (ids === null) return;
          const role_ids = ids.split(',').map((s) => Number(s.trim())).filter(Boolean);
          await api('/api/staff/accounts/' + a.id, { method: 'PUT', body: { role_ids } }); toast('Roles updated.'); reloadSection();
        }),
        a.status !== 'suspended' ? btn('Suspend', async () => {
          const reason = askReason('Reason for suspension:');
          if (!reason) return;
          await api(`/api/staff/accounts/${a.id}/suspend`, { method: 'POST', body: { reason } }); reloadSection();
        }, 'small danger') : btn('Restore', async () => { await api(`/api/staff/accounts/${a.id}/restore`, { method: 'POST', body: {} }); reloadSection(); }),
        isSuper ? btn('Delete', async () => {
          const reason = askReason('Reason for deleting this staff account (audited):');
          if (!reason || !confirmDo('Delete this staff account?')) return;
          await api(`/api/staff/accounts/${a.id}`, { method: 'DELETE', body: { reason } }); reloadSection();
        }, 'small danger') : null) },
    ], accounts.items, 'No staff accounts.'),
    section('Create staff account', form({ submit: 'Create account', resetOnSuccess: true, fields: [
      { name: 'username', label: 'Username', required: true },
      { name: 'email', label: 'Work email', type: 'email', required: true },
      { name: 'password', label: 'Temporary password (they must change it)', type: 'password', required: true },
      ...roleBoxes.map(([name, label]) => ({ name, label: 'Role: ' + label, type: 'checkbox', value: false })),
    ], onSubmit: async (v) => {
      const role_ids = roleBoxes.filter(([n]) => v[n]).map(([n]) => Number(n.slice(5)));
      await api('/api/staff/accounts', { method: 'POST', body: { username: v.username, email: v.email, password: v.password, role_ids } });
      toast('Staff account created.'); reloadSection();
    } })));
};

/* --------------------------------------------------------- roles */
RENDER.roles = async () => {
  const [roles, perms] = await Promise.all([api('/api/staff/roles'), api('/api/staff/permissions')]);
  const canManage = perm('roles.manage');
  const permBoxes = (perms.items || []).map((p) => ['perm_' + p.code, p.code + ' — ' + (p.description || p.group_name)]);
  return el('div', {}, el('h2', {}, 'Roles & permissions'),
    notice('The Super Admin role is locked. You cannot grant a permission you do not hold yourself.', ''),
    table([
      { label: 'ID', render: (r) => r.id },
      { label: 'Role', render: (r) => r.name + (r.is_system ? ' (system)' : '') },
      { label: 'Permissions', render: (r) => (r.permissions || []).join(', ') || '—' },
      { label: 'Actions', render: (r) => canManage && r.slug !== 'super_admin' ? el('div', { className: 'row' },
        btn('Edit', async () => {
          const name = window.prompt('Role name', r.name);
          if (name === null) return;
          const codes = window.prompt('Permission codes, comma separated', (r.permissions || []).join(','));
          if (codes === null) return;
          await api('/api/staff/roles/' + r.id, { method: 'PUT', body: { name, permissions: codes.split(',').map((s) => s.trim()).filter(Boolean) } });
          toast('Role updated.'); reloadSection();
        }),
        !r.is_system ? btn('Delete', async () => {
          if (!confirmDo('Delete this role? It must not be assigned to anyone.')) return;
          await api('/api/staff/roles/' + r.id, { method: 'DELETE' }); reloadSection();
        }, 'small danger') : null) : '—' },
    ], roles.items, 'No roles.'),
    canManage ? section('Create custom role', form({ submit: 'Create role', resetOnSuccess: true, fields: [
      { name: 'name', label: 'Role name (e.g. Verification Officer)', required: true, maxlength: 100 },
      { name: 'description', label: 'Description', maxlength: 255 },
      ...permBoxes.map(([name, label]) => ({ name, label, type: 'checkbox', value: false })),
    ], onSubmit: async (v) => {
      const permissions = permBoxes.filter(([n]) => v[n]).map(([n]) => n.slice(5));
      await api('/api/staff/roles', { method: 'POST', body: { name: v.name, description: v.description, permissions } });
      toast('Role created.'); reloadSection();
    } })) : null);
};

/* --------------------------------------------------------- feature centre */
RENDER.features = async () => {
  const data = await api('/api/staff/features');
  const items = data.items || [];
  const lockdown = items.find((f) => f.key === 'system.emergency_lockdown');
  const groups = {};
  for (const f of items) (groups[f.group] = groups[f.group] || []).push(f);
  return el('div', {}, el('h2', {}, 'Feature centre'),
    notice('Turning a module off hides it and blocks its endpoints. It does not delete data, cancel subscriptions or approve pending profiles. Changes are audited.', ''),
    lockdown ? notice(lockdown.effective ? 'EMERGENCY LOCKDOWN IS ACTIVE. Non-account modules are disabled.' : 'Emergency lockdown is off.', lockdown.effective ? 'bad' : '') : null,
    perm('features.manage') ? el('div', { className: 'row' },
      btn('Enable all unlocked modules', async () => {
        const reason = askReason('Reason for enabling all modules:');
        if (!reason) return;
        await api('/api/staff/features/enable-all', { method: 'POST', body: { reason } }); toast('All unlocked modules enabled.'); reloadSection();
      }, 'small')) : null,
    ...Object.entries(groups).map(([group, rows]) => section(group, ...rows.map((f) => el('div', { className: 'feature-row' },
      el('div', {}, el('strong', {}, f.label), el('div', { className: 'muted' }, f.key + (f.parent ? ' · requires ' + f.parent : '')),
        f.locked ? badge('locked', 'warn') : null),
      el('div', { className: 'row' }, statusBadge(f.effective ? 'active' : 'closed'),
        f.locked || !perm('features.manage') ? null : btn(f.effective ? 'Disable' : 'Enable', async () => {
          const reason = askReason(`Reason for ${f.effective ? 'disabling' : 'enabling'} ${f.label}:`, false);
          if (reason === null) return;
          if (f.key === 'system.emergency_lockdown' && !confirmDo('Toggle the emergency lockdown for the whole platform?')) return;
          await api('/api/staff/features/' + encodeURIComponent(f.key), { method: 'PUT', body: { enabled: !f.effective, reason } });
          toast('Saved.'); reloadSection();
        }, f.effective ? 'small danger' : 'small primary')))))));
};

/* --------------------------------------------------------- settings */
RENDER.settings = async () => {
  const data = await api('/api/staff/settings');
  return el('div', {}, el('h2', {}, 'Platform settings'),
    table([
      { label: 'Key', render: (s) => s.key },
      { label: 'Value', render: (s) => String(s.value) },
      { label: 'Description', render: (s) => s.description || '' },
      { label: '', render: (s) => btn('Edit', async () => {
        const v = window.prompt(`New value for ${s.key}`, String(s.value));
        if (v === null) return;
        const reason = askReason('Reason (audited):');
        if (!reason) return;
        await api('/api/staff/settings/' + encodeURIComponent(s.key), { method: 'PUT', body: { value: v, reason } });
        toast('Saved.'); reloadSection();
      }) },
    ], data.items, 'No settings.'));
};

/* --------------------------------------------------------- audit log */
RENDER.audit = async (q) => {
  const filters = { action: q.get('action') || '', target_type: q.get('target_type') || '', actor_id: q.get('actor_id') || '' };
  const params = new URLSearchParams(Object.entries(filters).filter(([, v]) => v));
  const data = await api('/api/staff/audit-logs?' + params.toString());
  return el('div', {}, el('h2', {}, 'Audit log'),
    form({ submit: 'Filter', className: 'row', fields: [
      { name: 'action', label: 'Action (e.g. feature.toggled)', value: filters.action },
      { name: 'target_type', label: 'Target type', value: filters.target_type },
      { name: 'actor_id', label: 'Actor ID', type: 'number', value: filters.actor_id },
    ], onSubmit: async (v) => { location.hash = '#/staff/audit?' + new URLSearchParams(Object.entries(v).filter(([, x]) => x !== '' && x !== null)).toString(); } }),
    table([
      { label: 'When', render: (a) => fmtDate(a.created_at) },
      { label: 'Actor', render: (a) => a.actor_username || a.actor_id || 'system' },
      { label: 'Action', render: (a) => a.action },
      { label: 'Target', render: (a) => `${a.target_type || ''} ${a.target_id ?? ''}` },
      { label: 'Reason', render: (a) => a.reason || '—' },
      { label: 'IP', render: (a) => a.actor_ip || '—' },
    ], data.items, 'No entries.'));
};

/* --------------------------------------------------------- analytics */
RENDER.analytics = async () => {
  const data = await api('/api/staff/analytics');
  const groups = Object.entries(data).filter(([, v]) => v && typeof v === 'object');
  return el('div', {}, el('h2', {}, 'Analytics'),
    el('div', { className: 'grid' }, groups.map(([name, metrics]) => section(name.replace(/_/g, ' '),
      ...Object.entries(metrics).map(([k, v]) => el('p', {}, el('strong', {}, k.replace(/_/g, ' ') + ': '), typeof v === 'object' ? JSON.stringify(v) : String(v)))))));
};

/* --------------------------------------------------------- helpers */
function r0(items) { return items || []; }

/* --------------------------------------------------------- support detail */
async function renderStaffTicket(id) {
  const data = await api(`/api/staff/support/tickets/${id}`);
  const t = data.ticket;
  const open = !['closed', 'resolved'].includes(t.status);
  return el('div', {}, el('p', {}, el('a', { href: '#/staff/support' }, '← Queue')),
    el('h2', {}, `#${t.id} ${t.subject}`),
    el('p', {}, statusBadge(t.status), ' · ', t.category, ' · member: ', String(t.username || t.user_id),
      ' · assigned: ', String(t.assigned_to || 'nobody')),
    t.category === 'appeal' ? notice('Appeal: uphold restores the member account. This needs users.suspend.', 'bad') : null,
    el('div', { className: 'row' },
      open && perm('support.manage') ? btn('Assign to me', async () => { await api(`/api/staff/support/tickets/${id}/assign`, { method: 'POST', body: { user_id: state.me.id } }); reloadSection(); }) : null,
      open && t.category === 'appeal' && perm('support.manage') ? btn('Uphold appeal', async () => {
        if (!confirmDo('Uphold this appeal and restore the account?')) return;
        await api(`/api/staff/support/tickets/${id}/uphold-appeal`, { method: 'POST', body: {} }); toast('Appeal upheld.'); reloadSection();
      }) : null,
      open && perm('support.manage') ? btn('Close', async () => {
        await api(`/api/staff/support/tickets/${id}/close`, { method: 'POST', body: { status: 'resolved' } }); reloadSection();
      }) : null),
    el('div', { className: 'chat', style: 'margin-top:1rem' }, (data.messages || []).map((m) => el('div', { className: 'bubble' + (m.is_staff ? ' mine' : '') },
      el('strong', {}, m.is_staff ? 'Staff' : 'Member'), el('div', {}, m.body), el('div', { className: 'meta' }, fmtDate(m.created_at))))),
    open && perm('support.manage') ? form({ submit: 'Reply to member', resetOnSuccess: true, fields: [{ name: 'body', label: 'Reply', type: 'textarea', required: true, maxlength: 4000 }],
      onSubmit: async (v) => { await api(`/api/staff/support/tickets/${id}/messages`, { method: 'POST', body: { body: v.body } }); reloadSection(); } }) : null);
}

/* --------------------------------------------------------- transaction detail */
async function renderTransaction(id) {
  const data = await api(`/api/staff/transactions/${id}`);
  const tx = data.transaction;
  const refundable = ['successful', 'partially_refunded'].includes(tx.status);
  const refunded = Number(tx.refunded_minor || 0);
  return el('div', {}, el('p', {}, el('a', { href: '#/staff/finance' }, '← Transactions')),
    el('h2', {}, tx.reference),
    el('p', {}, statusBadge(tx.status), ' · ', tx.gateway, ' · ', el('strong', {}, naira(tx.amount_minor)), ' · refunded ', naira(refunded), ' · member ', String(tx.username || tx.user_id)),
    notice('Amounts are verified against the gateway. A redirect back to the site never marks a payment as successful.', ''),
    el('div', { className: 'row' },
      perm('payments.reconcile') ? btn('Re-verify with gateway', async () => {
        const r = await api(`/api/staff/transactions/${id}/reverify`, { method: 'POST', body: {} });
        toast('Gateway status: ' + r.status + (r.changed ? ' (updated)' : '')); reloadSection();
      }) : null),
    perm('payments.refund') && refundable ? section('Request refund (maker)', form({ submit: 'Request refund', fields: [
      { name: 'amount_minor', label: 'Amount in kobo (max ' + (Number(tx.amount_minor) - refunded) + ')', type: 'number', min: 1, max: Number(tx.amount_minor) - refunded, required: true },
      { name: 'reason', label: 'Reason', required: true, maxlength: 500 },
    ], onSubmit: async (v) => { await api(`/api/staff/transactions/${id}/refunds`, { method: 'POST', body: { amount_minor: v.amount_minor, reason: v.reason } }); toast('Refund requested. A different staff member must approve it.'); reloadSection(); } })) : null,
    section('Refunds', table([
      { label: 'ID', render: (r) => r.id },
      { label: 'Amount', render: (r) => naira(r.amount_minor) },
      { label: 'Status', render: (r) => statusBadge(r.status) },
      { label: 'Reason', render: (r) => r.reason || '—' },
      { label: 'Actions', render: (r) => r.status === 'requested' && perm('payments.refund_approve') && Number(r.requested_by) !== state.me.id ? el('div', { className: 'row' },
        btn('Approve (checker)', async () => { if (!confirmDo('Approve and send the refund to the gateway?')) return; await api(`/api/staff/refunds/${r.id}/approve`, { method: 'POST', body: {} }); reloadSection(); }),
        btn('Reject', async () => { const reason = askReason('Reason for rejecting:'); if (!reason) return; await api(`/api/staff/refunds/${r.id}/reject`, { method: 'POST', body: { reason } }); reloadSection(); }, 'small danger')) : '—' },
    ], data.refunds, 'No refunds.')),
    section('Subscriptions', table([
      { label: 'ID', render: (s) => s.id }, { label: 'Status', render: (s) => statusBadge(s.status) },
      { label: 'Starts', render: (s) => fmtDate(s.starts_at) }, { label: 'Ends', render: (s) => fmtDate(s.ends_at) },
    ], data.subscriptions, 'None linked.')),
    section('Immutable event trail', table([
      { label: 'When', render: (e) => fmtDate(e.created_at) }, { label: 'Event', render: (e) => e.event_type },
      { label: 'Source', render: (e) => e.source }, { label: 'Status', render: (e) => `${e.status_before || '—'} → ${e.status_after || '—'}` },
    ], data.events, 'No events.')));
}
