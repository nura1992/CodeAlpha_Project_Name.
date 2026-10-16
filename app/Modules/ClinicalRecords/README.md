# Clinical Records module — Phase 2

Part of **MediID**. Will implement: `encounters`, `prescriptions`,
`lab_results`, `consents`, `medical_documents` with:

- `clinical.view` / `clinical.write` permissions, separate from `patients.*`
  directory-level access (blueprint rule: directory access ≠ clinical access)
- amendments-with-history (no silent overwrites)
- access + export auditing
- clinician review workflow

Do not build these tables ad hoc — add them via a new migration
(`003_clinical_records.php`).
