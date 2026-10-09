# CodeAlpha Match & Community

A matchmaking and social community platform, inspired by Muzz (verified, mutual-match discovery) and Facebook (posts, reactions, follows, sharing).

- **Frontend:** HTML, CSS and vanilla JavaScript ES modules (`public/`). No frameworks, no build step.
- **Backend:** raw PHP 8.1+ (no framework), JSON API under `/api/*`.
- **Database:** MySQL 8.0+ (InnoDB, utf8mb4).
- **Real-time calls:** WebRTC for media, a Node.js WebSocket signalling relay (`signalling/`), and STUN/TURN (coturn recommended).
- **Payments:** Paystack and Flutterwave (NGN first), with server-side verification and webhooks.

> **Status:** the code is complete for every module in the brief, but it has been checked less than it should be. See [Verification status](#verification-status) before deploying.

## Contents

- [Features](#features)
- [Repository layout](#repository-layout)
- [Quick start (development)](#quick-start-development)
- [Production deployment](#production-deployment)
- [Roles and permissions](#roles-and-permissions)
- [Security model](#security-model)
- [Payments](#payments)
- [Verification media](#verification-media)
- [Feature centre](#feature-centre)
- [Testing](#testing)
- [Verification status](#verification-status)
- [Known limitations](#known-limitations)

## Features

**Accounts and safety**
- Registration by email or phone with a one-time code (OTP), password reset, 18+ date-of-birth check enforced on the server.
- Privacy settings (calls, notifications), password change, self-service account deletion.
- Block (bidirectional, ends matches and calls), report (users, posts, comments, messages, calls), unmatch (history kept read-only).

**Matchmaking**
- Discovery is available only to members whose profile is **approved** after staff review. Pending users can still use the community.
- Like / pass, mutual matches open a conversation, favourites, filters (age, interest, city, intention).
- Gender visibility is enforced in SQL on the server: men see women's profiles and women see men's, according to each user's preference.
- Profile editing. Sensitive changes (name, bio, photos, interests) send the profile back for re-review.

**Messaging and calls**
- Text, image and voice-note messages, with delivered and read receipts. Media is served only to conversation members.
- Audio and video calls over WebRTC. Calls need an active match, both parties approved, and neither blocked. Calls ring for 45 seconds (configurable), are rate-limited, and auto-close after 4 hours.
- Call history.

**Community**
- Text, image and video posts with visibility (public, followers, private). Reactions, comments and replies.
- Share: internal, copy link and native share. Share links expose only the post body and username, never profiles or verification media.
- Follows, hashtags, trending, search, saved posts, notifications, mentions, reports, moderation queues, announcements, and guidelines.

**Verification**
- Members submit a short video. Staff claim, view through a short-lived signed link, then approve or reject with a logged reason.
- Rejected members can resubmit. Videos are encrypted at rest and stored outside the web root. Retention and secure deletion are handled by `bin/purge_verification.php`.
- The "verified" badge means checks were completed. It is **not** a guarantee of identity.

**Staff, RBAC and administration**
- Staff dashboards (Admin, Finance, Support, Verification, Moderation, Campaigns). Every endpoint is guarded by permission on the server. The UI only hides what the user cannot use.
- Super Admin manages staff accounts, custom roles, feature toggles, settings, audit logs, analytics, and announcements.

**Campaigns and advertising**
- Campaigns with budgets, promo codes, and in-app delivery to opted-in members only.
- Advertisers and ads with frequency caps, maker-checker review, and a "Sponsored" label. Advertisers see only aggregate delivery numbers, never member data.

**Payments**
- Paystack and Flutterwave, test and live mode per gateway. Credentials are encrypted in MySQL and are write-only in the UI.
- Subscription activates only after server-side verification of reference, amount and currency. Webhooks are signature-checked and idempotent. Every transaction has an append-only audit trail.
- Finance reports (CSV), reconciliation, refunds with maker-checker approval.

## Repository layout

```
bin/                     CLI: create_super_admin.php, purge_verification.php
config/.env.example      Environment template (copy to config/.env, never commit)
database/schema.sql      MySQL schema (tables, FKs, indexes, append-only triggers)
database/seed.sql        Permissions, system roles, role grants, default settings
public/                  Web root. index.php (front controller), index.html, assets/
public/assets/           app.js (router, member views), staff.js (staff console),
                         calls.js (WebRTC), ui.js (API client and DOM helpers), app.css
signalling/              WebSocket signalling relay (Node.js, ws) and tests
src/bootstrap.php        Autoloader, env loading, error handling
src/routes.php           Single route table; guards declared per route
src/Core/                Router, Auth, Rbac, Features, Settings, Crypto, SecureStorage, Otp, ...
src/Controllers/         Feature controllers (auth, profile, discovery, messages, calls,
                         community, payments, support, moderation, admin, finance, campaigns)
src/Payments/            Gateway interface, Paystack and Flutterwave adapters, fulfilment
storage/                 Created at runtime. Private files. Keep outside the web root in production.
```

## Quick start (development)

Requirements: PHP 8.1+ with `pdo_mysql`, `openssl`, `fileinfo`, `sodium` optional; MySQL 8.0+; Node.js 18+.

1. **Create the database**
   ```bash
   mysql -u root -p -e "CREATE DATABASE codealpha_matchcom CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
   mysql -u root -p codealpha_matchcom < database/schema.sql
   mysql -u root -p codealpha_matchcom < database/seed.sql
   ```
2. **Configure**
   ```bash
   cp config/.env.example config/.env
   php -r "echo 'base64:'.base64_encode(random_bytes(32)), PHP_EOL;"   # paste as APP_KEY
   ```
   Set `DB_*`, `APP_URL`, `STORAGE_PATH` (outside `public/`), and `SIGNALLING_SECRET` (at least 32 characters, the same value for PHP and the signalling service).
3. **Create the first Super Admin** (the password is read from the environment or STDIN, never from argv)
   ```bash
   SUPER_ADMIN_PASSWORD='use-a-long-passphrase' php bin/create_super_admin.php --username=admin --email=ops@example.com
   ```
   They must change the password on first sign-in.
4. **Run the web app** (router script handles `/api/*` and the SPA shell)
   ```bash
   php -S 0.0.0.0:8000 -t public public/index.php
   ```
5. **Run signalling** in a second terminal
   ```bash
   cd signalling && npm install && PORT=8081 node server.js
   ```
   In production, reverse-proxy `wss://your-domain/signal` to this port, and set `SIGNALLING_URL` to match.

Payment gateway keys are entered in **Staff console → Gateways** and stored encrypted. They do not go in `.env`.

## Production deployment

- **Web root:** point the vhost at `public/` only. `public/.htaccess` routes non-file requests to `index.php` and blocks `.env`, `.log`, `.sql`, `.ini`, `.md` and `.lock` files. Serve over HTTPS only.
- **Private storage:** `STORAGE_PATH` must be outside the web root and writable only by the PHP user. Verification videos, message media, ad media, and logs live there.
- **TURN:** run coturn with `use-auth-secret` and the same `TURN_SECRET`. The API issues credentials valid for one hour.
- **Cron:** `15 3 * * * php /path/to/bin/purge_verification.php` for retention.
- **Webhooks:** point Paystack and Flutterwave webhooks at `https://your-domain/api/webhooks/paystack` and `.../flutterwave`. Set the webhook secret in the gateway screen.
- **Email and SMS:** `OTP_DRIVER=log` writes codes to the private log for development only. Connect a provider in `src/Core/Notifier.php` and `src/Core/Otp.php` before launch.
- **Session cookies:** HttpOnly, SameSite, and Secure when served over HTTPS. Every state-changing request needs the `X-CSRF-Token` header.
- **Content Security Policy:** `script-src 'self'`, `style-src 'self'`. There are no inline scripts or inline style attributes, so the policy holds.

## Roles and permissions

Built-in system roles (seeded): **Super Admin** (locked, has every permission), **Admin**, **Finance**, **Support**, plus custom roles that are seeded for the common cases: **Verification Officer**, **Community Moderator**, **Campaign Manager**. Super Admins can create more roles from the Roles screen.

Permission codes (from `database/seed.sql`):

| Group | Codes |
|---|---|
| Users | `users.view`, `users.view_pii`, `users.suspend` |
| Profiles | `profiles.moderate` |
| Verification | `verification.review`, `verification.approve`, `verification.reject` |
| Moderation | `moderation.review` |
| Community | `community.moderate`, `community.announce` |
| Support | `support.view`, `support.manage` |
| Payments | `payments.view`, `payments.refund`, `payments.refund_approve`, `payments.reconcile`, `payments.reports`, `payments.configure`, `payments.plans` |
| Campaigns and ads | `campaigns.manage`, `ads.manage`, `ads.review` |
| System | `features.manage`, `settings.manage`, `audit.view`, `analytics.view` |
| Access | `roles.view`, `roles.manage`, `staff.manage` |

Rules enforced on the server:
- You cannot grant a permission you do not hold (`PRIVILEGE_ESCALATION`).
- Only a Super Admin can grant the Super Admin role, delete staff accounts, or suspend another Super Admin.
- The last active Super Admin cannot be removed or suspended. Self-suspension is blocked.
- New staff accounts must change their password on first sign-in.
- Refunds are maker-checker: the requester cannot approve their own refund.
- Ad review is maker-checker: the creator cannot approve their own ad.

## Security model

- **Server-side authorisation.** Every route in `src/routes.php` declares its guard (`auth`, `perm`, `feature`). `Router::guard()` enforces these before the controller runs. Controllers also re-check ownership and membership.
- **Feature gating.** Disabled modules return an error from the server. The UI hides them only for display.
- **Gender and eligibility.** Discovery, profile views, messages and calls check eligibility in SQL or in `Eligibility::canCommunicate()`.
- **Secrets.** `APP_KEY` derives per-purpose AES-256-GCM keys. Gateway secrets, webhook secrets and any other encrypted fields use these keys. Secrets are never sent to the browser. The gateway status endpoint returns only "configured" flags.
- **Passwords.** `password_hash` (Argon2id when available, otherwise bcrypt). Login throttling and lockout.
- **Sessions.** Random token stored hashed. CSRF token per session. Password changes and resets sign out all other sessions (`Auth::revokeAll`).
- **Uploads.** MIME type is checked from file content (not the client's header). Files are renamed, stored outside the web root, and served only through authorised endpoints with `Content-Security-Policy: sandbox` and `nosniff`.
- **Audit.** `audit_logs`, `transaction_events`, and `verification_decisions` are append-only: database triggers block UPDATE and DELETE. Audit entries record actor, action, target, reason, before and after values (secrets excluded), and IP.
- **Signalling.** Tickets are HMAC-SHA256 signed, expire after 120 seconds, and are bound to one call and one user. The relay allows only two peers per room, relays only `offer`, `answer`, `ice` and `hangup` frames, and enforces frame size and rate limits. It does not store media or messages.
- **Errors.** Stack traces are logged server-side. Clients receive a generic message.
- **Headers.** `X-Content-Type-Options`, `X-Frame-Options: DENY`, `Referrer-Policy`, `Permissions-Policy`, and a strict CSP.

## Payments

1. The member chooses a plan and gateway. `POST /api/payments/checkout` creates a **pending** transaction with a unique reference (the gateway reference equals the transaction reference), then returns the gateway checkout URL.
2. The member is redirected to the gateway, then returns to `/billing/return?reference=…`. **The redirect never grants access.** The return page only starts verification.
3. Access is granted only by **server-side verification**: either the signed webhook (`POST /api/webhooks/{gateway}`) or `GET /api/payments/verify/{reference}`, which calls the gateway's API.
4. `Fulfilment::apply()` is the only code path that sets a transaction to successful and activates a subscription. It locks the row (`SELECT … FOR UPDATE`), is idempotent, and checks reference, amount and currency. A mismatch is logged and never activates.
5. Renewals extend from the current end date. Periods: monthly (P1M), quarterly (P3M), yearly (P1Y).
6. **Amounts:** Paystack uses kobo. Flutterwave uses major units, converted from kobo in the adapter. Plans store `amount_minor` in kobo. Staff enter Naira.
7. **Webhook authentication:** Paystack signs the raw body with HMAC-SHA512 (`x-paystack-signature`). Flutterwave sends the secret as `verif-hash`, compared in constant time.
8. **Refunds:** `requestRefund` (payments.refund) creates a request. `approveRefund` (payments.refund_approve, a different person) runs under a row lock and calls the gateway. A gateway failure leaves the refund in `requested`. When refunds reach the full amount, the transaction becomes `refunded` and the linked subscription becomes `refunded`.
9. **Reconciliation:** `GET /api/staff/reconciliation` reports stale pending transactions, paid transactions without an active subscription, and amount mismatches. The CSV export neutralises spreadsheet formula injection.

Official docs: [Paystack](https://paystack.com/docs/) and [Flutterwave](https://developer.flutterwave.com/docs).

## Verification media

- Uploads are stored encrypted in `STORAGE_PATH/private/verification`, not under `public/`.
- Staff claim a request. Viewing needs `verification.review`, an assigned reviewer, and a signed link that expires in 5 minutes and is bound to that reviewer.
- Each approve or reject writes `verification_decisions` with the reviewer, timestamp and reason. Rejected members can resubmit.
- Retention is `verification.retention_days` (default 30). `bin/purge_verification.php` securely deletes media past retention (overwrite, then unlink).

## Feature centre

Super Admins toggle modules from **Staff console → Feature centre**. The state is stored in `feature_flags`. Each toggle requires a reason and is audited.

- **Dependencies:** a child module (for example `social.reactions`) cannot run unless its parent (`social.feed`) is on.
- **Locked modules:** the 18+ age check (`account.age_eligibility`) cannot be disabled.
- **Emergency lockdown:** `system.emergency_lockdown` disables all non-account modules at once.
- **Disabling never deletes data** (payments, conversations, posts stay in MySQL). It also **never auto-approves** pending profiles, and it does not cancel subscriptions.

## Testing

Automated and manual checks that have been run:

- **Static PHP parse** of all 43 files in `src/`, plus `bin/` and `public/`, using php-parser. All pass.
- **Static cross-check:** every `Class::method()` call resolves to a defined method (41 classes checked).
- **Signalling relay tests:** `cd signalling && npm test` (offer/answer relay, presence events, bad signature, expired ticket, third participant rejected). Both pass.
- **Front-end smoke test (jsdom, mocked API):** all staff console sections render with representative data without runtime errors. Member views (discovery, community, profile, billing, settings, support, verification, login and register) render.

## Verification status

**Not run in this environment, and you should run these before deploying:**
- The PHP code has **not been executed**. PHP was not available. Parse checks and method cross-checks passed, but runtime behaviour, SQL against real MySQL, and the `schema.sql` / `seed.sql` import have **not** been tested.
- `schema.sql` has not been imported into a real MySQL server.
- WebRTC calls have **not** been tested between two real browsers, with real STUN/TURN.
- Paystack and Flutterwave flows have **not** been tested against their sandboxes.
- Webhook signature checks have not been exercised with real payloads.

Recommended first steps: import the schema on a clean MySQL 8, run the Super Admin script, then walk through registration, OTP, verification review, a match, a call, and a sandbox payment.

## Known limitations

- OTP and email/SMS delivery use the log driver by default. A provider must be connected before launch.
- Push notifications (`comm.push`) are a feature flag, not implemented.
- Group calls are not supported. Calls are one-to-one only.
- Video transcoding is not implemented. Uploads are validated and stored as-is.
- Search is SQL `LIKE` over public content. Use a search index at scale.
- Face or identity matching is out of scope. Verification is a manual video review.
