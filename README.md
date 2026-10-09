# CodeAlpha — Matchmaking & Community Platform

A modular matchmaking and social community platform inspired by **Muzz** (matching) and **Facebook** (community). Built with **HTML/CSS/JavaScript** (frontend), **raw PHP** (backend) and **MySQL** (production) / **SQLite** (development).

> **Live preview** (when the dev server is running): `http://localhost:8091` — see [Development server](#development-server) below.

---

## Features

### Accounts & security
- Email or phone registration with OTP verification (log transport by default, SMTP/HTTP pluggable)
- 18+ age gate enforced server-side
- Gender selection and opposite-gender visibility rule (server-side SQL, not JS)
- Profile approval via video verification (protected storage, expiring signed URLs)
- RBAC with granular permissions (`users.view`, `verification.approve`, `payments.refund`, …)
- Audit log for every sensitive action

### Matchmaking
- Discovery feed: like / pass, mutual matches → conversation
- Favourites, block / report, unmatch
- Filters: age, interests, location, intention, distance (haversine)
- Profile visibility defaults to **denied** until `approved`

### Messaging & calls
- One-to-one messaging (text, image, voice) with read/delivered status
- WebRTC audio/video calls with STUN/TURN, WebSocket signalling (`tools/signaling-server.mjs`) + REST fallback
- Call history, block, and abuse controls. Calls only between eligible, approved, matched members.

### Community (Facebook-inspired, independent of matchmaking)
- Text / image / video / link posts, nested comments, reactions, follows
- Hashtags, topics, search, trending, saved posts, share (native + copy-link)
- Reports and moderation queues. Pending members can still use community.

### Business & revenue
- Subscription plans, **Paystack** and **Flutterwave** pre-integrated (encrypted at rest, never exposed to client)
- Full checkout → gateway → server-side verify → subscription flow; redirect alone is never proof
- Mock mode (`PAYMENT_MOCK=true`) exercises the whole flow offline
- Campaigns, promo codes, ad placements with frequency caps and labelled paid content

### Administration
- **Super Admin**: staff, roles, feature toggles, gateways, plans, campaigns, audit, analytics, emergency lockdown
- **Admin**: users, verification queue (protected video streaming), moderation, community
- **Finance**: transactions, refunds, subscriptions, reconciliation, CSV export (no verification video access)
- **Support**: tickets, appeals, escalations

### Feature toggles
Every major module has a global flag in **Super Admin → Feature Management**. Disabling a module hides it in the UI **and** rejects it server-side (`FeatureMiddleware`). Data is never deleted when a feature is disabled. An emergency lockdown disables all non-critical features.

---

## Architecture

```
public/index.php          → front controller (all non-file requests)
src/bootstrap.php         → autoloader, config, error handling, session, security headers
src/Core/*                → Database, Router, Auth, Csrf, Validator, Encryption, FeatureFlags, …
src/Http/Controllers/*    → Page, Auth, Profile, Discovery, Message, Call, Community, Payment, …
src/Http/Middleware/*     → Auth, Staff, Permission, Feature, Csrf, Verified
src/Services/*            → Matching (gender rule), Verification, Messaging, Call, Community,
                            Payment (Paystack/Flutterwave), Marketing, Support, Analytics
database/schema.mysql.sql → authoritative MySQL schema (translated to SQLite for dev)
views/*                   → PHP templates + layouts (app, guest, staff)
public/assets/*           → CSS (mobile-first) + JS (api helper, share, push, WebRTC)
tools/signaling-server.mjs→ WebSocket signalling for WebRTC
bin/*                     → installer, dev server (WASM), cleanup
```

---

## Installation

### Requirements
- **Production**: PHP 8.1+, MySQL 8.0+ / MariaDB 10.4+, Apache/Nginx, Node 18+ (for signalling server only)
- **Development** (no PHP/MySQL needed): Node 18+ — the bundled WASM dev server runs real PHP 8.3 and SQLite

### Development (WASM, no system PHP/MySQL)

```bash
npm install
node bin/install.mjs --refresh   # creates storage/database.sqlite and seeds demo data
npm run dev                      # http://localhost:8000  (PORT=4000 npm run dev)
# In another terminal, optionally:
node tools/signaling-server.mjs  # ws://localhost:8090 for WebRTC
```

Demo accounts after install:

| Role | Email | Password |
|------|-------|----------|
| Super Admin | `super@codealpha.local` | `SuperAdmin123!` |
| Admin | `admin@codealpha.local` | `Admin123!` |
| Finance | `finance@codealpha.local` | `Finance123!` |
| Support | `support@codealpha.local` | `Support123!` |
| Member (female) | `ada@example.com` | `Demo1234!` |
| Member (male) | `chinedu@example.com` | `Demo1234!` |

- `GET /dev/inbox` (debug mode) shows logged emails/SMS.
- Payments run in **mock mode** by default — use the mock checkout page to simulate success/failure and then verify server-side.

### Production (MySQL)

```bash
cp config/config.php config/config.local.php  # edit DB credentials, APP_KEY, APP_URL, etc.
# Or set env vars: DB_DRIVER=mysql DB_HOST=… DB_NAME=… DB_USER=… DB_PASSWORD=… APP_KEY=base64:… 
php bin/install.php --refresh
# Point your web server's document root at /public
# Ensure storage/ and public/uploads are writable (0770) and protected storage is outside the web root
# Run the signalling server separately:
#   npm install --production
#   SIGNALING_PORT=8090 node tools/signaling-server.mjs
# Schedule cleanup:
#   0 3 * * * php bin/cleanup.php >> storage/logs/cleanup.log 2>&1
```

Apache is ready via `public/.htaccess` (rewrite to `index.php`, block dotfiles/storage, harden uploads). For Nginx:

```nginx
root /path/to/public;
location / { try_files $uri $uri/ /index.php?$query_string; }
location ~ /\. { deny all; }
location ~ ^/storage/ { deny all; }
```

---

## Configuration

All values in `config/config.php` can be overridden by env vars or by `config/config.local.php` (git-ignored). Key vars:

- `APP_KEY` — 32+ random bytes, `base64:` encoded (encrypts gateway secrets, signs media URLs)
- `APP_URL` — canonical URL (used for share links, callbacks)
- `DB_DRIVER` — `mysql` (prod) or `sqlite` (dev)
- `PAYMENT_MOCK` — `true` for offline flow testing
- `MAIL_TRANSPORT` — `log` or `smtp`; `SMS_TRANSPORT` — `log` or `http`
- `SIGNALING_URL`, `STUN_SERVERS`, `TURN_SERVER` — WebRTC

Gateway credentials are stored **encrypted at rest** (`Encryption` AES-256-CBC + HMAC) and are only editable by `payments.configure`.

---

## Security notes

- **Gender visibility** and **profile visibility** are server-side SQL rules — never JS filters. A male user cannot retrieve female-only data outside the allowed matching relationship.
- **Verification videos** live outside the web root (`storage/protected/verifications`) and are served only through `AdminMediaController` after `verification.review` + `verification.approve/reject` checks, with expiring HMAC URLs, retention and secure deletion.
- **CSRF** via synchronizer token (hidden field + `X-CSRF-Token` header) for all state-changing, cookie-authenticated requests.
- **RBAC** is checked on every request in PHP, not just hidden in the UI. Audit log records actor, target, IP, user-agent.
- **Payments**: secret keys never leave the server; amount/currency/reference/status are verified server-side; webhooks validate `x-paystack-signature` / `verif-hash`; idempotency via unique `reference`; redirect alone is never proof.

---

## Database

`database/schema.mysql.sql` is the authoritative schema (≈30 tables: `users`, `roles`, `permissions`, `profiles`, `verification_requests`, `likes`, `matches`, `conversations`, `messages`, `calls`, `community_posts`, `feature_flags`, `transactions`, `subscription_plans`, `campaigns`, `advertisements`, `support_tickets`, `audit_logs`, …). The installer translates it to SQLite for local dev (ENUM→TEXT, etc.) and seeds roles/permissions/plans/demo data.

---

## Development server

`bin/server.mjs` uses `@php-wasm/node` (real PHP 8.3 in WASM) + `PHPRequestHandler` + `useHostFilesystem` so file changes are live. It mounts the project, handles sessions, and falls back unknown paths to `public/index.php`. No system PHP or MySQL is required.

---

## License

MIT — do not use the `CodeAlpha` name or demo seed data to impersonate a real service. The verified badge means the platform completed its stated checks; it is not a guarantee of identity or intentions.
