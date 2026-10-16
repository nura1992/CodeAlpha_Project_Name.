# MedCore Platform — Phase 1 (Core)

**Medboat** ecosystem · modular healthcare operating platform · raw PHP 8 + MySQL + HTML/CSS/vanilla JS.

MedCore is built as a **modular monolith**: one application, one database, clearly
separated modules that can later split into services. Phase 1 delivers the secure
core platform the rest of the ecosystem plugs into:

| Product | Phase 1 status |
|---|---|
| **MedCore Core** — auth, RBAC, API, audit logs, notifications | ✅ Live |
| **MediID** — patient identity & records (consent-first registry) | ✅ Registry live (clinical encounters in Phase 2) |
| **MediLink** — providers, directory, appointments workflow | ✅ Live |
| **MediFund** — payments & ledger | ⏳ Phase 3 (scaffolded placeholder) |
| **MediBridge** — integrations | ⏳ Phase 4 (scaffolded placeholder) |
| **MedIntellect AI** — assisted insights | ⏳ Phase 5 (scaffolded placeholder) |

---

## Quick start (XAMPP + VS Code)

1. **Clone / open the project** in VS Code. Place it anywhere — Apache only needs `public/`.
2. **Configure** — copy `.env.example` to `.env`:
   ```bash
   cp .env.example .env
   ```
   For XAMPP, keep `DB_DRIVER=mysql` and set `DB_NAME=medcore_db`, `DB_USER`, `DB_PASS`.
3. **Create the database** in phpMyAdmin or the MySQL CLI:
   ```sql
   CREATE DATABASE medcore_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
4. **Migrate + seed** (synthetic demo data):
   ```bash
   php scripts/migrate.php
   php scripts/seed.php
   ```
5. **Serve** — either point an Apache vhost document root at `public/`
   (recommended; `public/.htaccess` handles routing), or use the built-in server:
   ```bash
   php -S localhost:8000 -t public
   ```
6. Open **http://localhost:8000** and sign in with a demo account below.

### Zero-config demo (no MySQL)
Set `DB_DRIVER=sqlite` in `.env`, run steps 4–5. A SQLite file is created at
`storage/database/medcore.sqlite`. Great for evaluation; **use MySQL for anything real.**

### Demo accounts (synthetic; password for all: `MedCore#2026`)

| Account | Role | Can do |
|---|---|---|
| `admin@medboat.test` | Super admin | Users, roles, orgs, settings, audit — **no clinical access, by design** |
| `orgadmin@medboat.test` | Org admin | Manage its org, staff, providers, patients, appointments |
| `doctor@medboat.test` | Doctor | Patients (scoped), appointments, providers |
| `nurse@medboat.test` | Nurse | Patient views, appointment check-in |
| `patient@medboat.test` | Patient | Own record, own appointments, booking |
| `auditor@medboat.test` | Auditor | Read-only audit log |

---

## What's inside

- **Secure authentication** — `password_hash()`, session regeneration after login,
  HttpOnly/SameSite cookies, idle + absolute session timeouts, DB-backed login
  rate limiting (5 fails → 15 min lockout), email-verification and password-reset
  flows (token-hashed, single-use, expiring).
- **Central RBAC** — one auth service; permissions checked on **every** backend
  request (`RequirePermission` middleware), never by hiding UI alone. 11 roles,
  22 permissions, JSON permission-matrix editor with audit trail.
- **The critical boundary** — super admins *cannot* open patient records;
  clinical access needs `patients.view` **plus** a care relationship
  (same organization or appointment link). See `app/Core/Authorization.php`.
- **Audit logging** — logins, lockouts, record views, role/permission changes,
  org membership changes, settings edits; actor + IP + timestamp; no secrets logged.
- **Web security defaults** — global CSRF shield (forms + `X-CSRF-TOKEN` for fetch),
  context-aware output escaping, strict security headers incl. CSP, PDO prepared
  statements, transactions for multi-write flows, append-only audit table.
- **Versioned JSON API** — `/api/v1/me`, `/api/v1/summary` (session + CSRF),
  used by the dashboard chart via `fetch()`.

## Project structure

```
├── app/
│   ├── Core/          Router, Database (PDO), Auth, Authorization, Validator,
│   │                  Csrf, Response, Request, Session, View, Config, Env, Route
│   ├── Middleware/    RequireLogin, GuestOnly, RequirePermission, VerifyCsrf
│   ├── Modules/       Identity · Organizations · Patients (MediID) · Providers
│   │                  Appointments (MediLink) · Admin · Api + phase placeholders
│   ├── Services/      AuditLogger, NotificationService
│   ├── Repositories/  one per aggregate (users, roles, orgs, patients, …)
│   ├── Views/         layouts + page templates (escaping via e())
│   ├── helpers.php    view + URL + formatting helpers
│   └── bootstrap.php  autoload, env, config, error handling
├── config/            app + database config (env-driven)
├── database/
│   ├── migrations/    001 identity/access · 002 care ops (MySQL + SQLite SQL)
│   └── seeders/       synthetic demo dataset
├── docs/              security-checklist.md (required manual pass)
├── public/            web root ONLY: index.php, assets, .htaccess, logo
├── routes/            web.php · api.php (versioned /api/v1)
├── scripts/           migrate.php · seed.php
├── storage/           logs · private · exports · database (never web-accessible)
└── tests/             run.php (dependency-free unit checks)
```

## Everyday commands

```bash
php scripts/migrate.php     # apply pending migrations (safe to re-run)
php scripts/seed.php        # seed synthetic demo data (first run only)
php tests/run.php           # unit-level checks
php -S localhost:8000 -t public   # dev server without Apache
```

## Conventions for the next phases

- **One migration per step.** Add `database/migrations/003_*.php` returning both
  `mysql` and `sqlite` statement arrays — never hand-edit the schema in phpMyAdmin.
- **Permissions before features.** New module ⇒ new permission slugs ⇒ seed grants ⇒
  middleware on routes ⇒ record-level checks in the controller.
- **Clinical access is audited.** Any screen that reads clinical data must log a
  `*.viewed` event; exports and amendments log too.
- **Never trust the browser.** Amounts, statuses and ownership are decided server-side.
- Keep `APP_DEBUG=false`, `DEV_SHOW_LINKS=false`, `SESSION_SECURE_COOKIE=true` in production,
  and run `docs/security-checklist.md` before touching real data.

## Notes on the bundled sandbox preview

This workspace was verified live through a WebAssembly PHP 8.5 runtime
(`@php-wasm/node`) serving the unmodified `public/` tree with SQLite — proof the
exact same code runs on plain PHP; XAMPP/MySQL remains the reference stack.

---

_Medboat · MedCore Platform — Phase 1 of the ecosystem blueprint. All seeded data is synthetic._
