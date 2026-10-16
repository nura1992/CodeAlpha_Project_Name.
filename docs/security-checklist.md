# MedCore security verification checklist — Phase 1

Run through this list **before** pointing the app at any real data.
Automated coverage lives in `tests/run.php`; this checklist is the manual
pass recommended by the blueprint. References: OWASP Session Management,
CSRF Prevention, and Cryptographic Storage cheat sheets.

## Authentication & sessions
- [ ] Wrong password → generic "Invalid email or password." (no account enumeration).
- [ ] 5+ failed logins for one email/IP → lockout message; visible in audit log and `login_attempts`.
- [ ] After login, the session id in the browser cookie CHANGES (fixation defence: `session_regenerate_id`).
- [ ] Session cookie has HttpOnly + SameSite=Lax (+ Secure in production when `SESSION_SECURE_COOKIE=true`).
- [ ] Idle beyond `SESSION_IDLE_TIMEOUT` → forced re-login with "session expired" message.
- [ ] Logout invalidates the session (back button does NOT restore authenticated pages).

## CSRF
- [ ] Submitting any POST form without `_token` → rejected (redirect + message / API 419).
- [ ] Cross-origin form post with a copy of the HTML from another origin → rejected.
- [ ] `fetch()` calls include `X-CSRF-TOKEN` and succeed (e.g. role matrix save).

## Authorization (server-side — hide buttons is not enforcement)
- [ ] Patient opens `/patients/<another patients id>` → **403** AND a `patients.access_denied` audit row appears.
- [ ] Super admin (`admin@medboat.test`) opens any patient record → **403**
      (platform admin ≠ clinical access — the blueprint's critical rule).
- [ ] Org admin A cannot view/edit users/patients/providers of Org B (every list + direct URL).
- [ ] Doctor can view patients of their org; cannot open `/admin/users` (403).
- [ ] Org admin cannot assign themselves `super_admin` (checkbox absent + forged POST rejected).

## Input handling & output
- [ ] `<script>alert(1)</script>` as a patient name renders inert everywhere (list, detail, audit).
- [ ] SQL attempts (`' OR '1'='1`) in search fields → no error, no extra rows (PDO prepared statements).
- [ ] Oversized inputs (10 000-char name) rejected or truncated by validation.
- [ ] Error pages in production mode (`APP_DEBUG=false`) show no paths, SQL or stack traces.

## Passwords & recovery
- [ ] Passwords < min length rejected on register/reset/change.
- [ ] Reset link single-use: second request with same token fails; links expire after 1 hour.
- [ ] Reset flow does not reveal whether an email exists (same flash message either way).
- [ ] `password_hash()` values in the DB start with `$2y$` (bcrypt) — never plaintext/MD5.

## Data & infrastructure
- [ ] `.env`, `storage/`, `app/`, `config/` are NOT reachable over HTTP (web root = `public/`).
- [ ] Response headers present: `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, CSP.
- [ ] Audit log contains: logins (success/fail), lockouts, patient record views, role/permission changes, org membership changes — with actor, IP and timestamp.
- [ ] Audit details never include passwords, tokens or full form payloads (spot-check a few rows).
- [ ] Database user on production is least-privilege (no DROP/ALTER for the app account in a hardened setup).
- [ ] Backups encrypted at rest; restoration tested on a schedule (procedure outside Phase 1 code).

## Before REAL patient data (hard gate)
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `DEV_SHOW_LINKS=false`, `SESSION_SECURE_COOKIE=true`, HTTPS everywhere.
- [ ] Qualified review of Nigeria data-protection (NDPA/NDPR), health-sector, and where applicable
      payment/insurance regulatory requirements.
- [ ] Remove/rotate ALL demo accounts (`.*@medboat.test`) and re-seed without demo data.
- [ ] Retention, correction, export and deletion policies documented and agreed.
