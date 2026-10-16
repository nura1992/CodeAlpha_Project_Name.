# Billing module (MediFund) — Phase 3

Payments and health financing. Will implement: `bills`, `payments`,
`payment_events`, `ledger_accounts`, `ledger_entries`, `refunds`.

Design rules from the blueprint:

- Auditable double-entry ledger; idempotency keys on every payment.
- Backend-verified payment confirmation (signed webhooks) — never trust a
  browser-supplied "success" message.
- Reconciliation views for the finance officer role.
