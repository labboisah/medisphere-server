# Operational Summary Sync: Local Hospital to Platform

## Purpose

This guide proposes how to send privacy-preserving, package-aware operational summaries from a MediFlow hospital installation to that hospital's account on the MediFlow Platform. The Platform can use the summaries for monitoring, cross-period reporting, and decision support without receiving the hospital's patient-level records.

This is a design and implementation guide, not a description of a completed feature. The repository already has a separate bidirectional record-sync system and a newer operational-health connector. Neither currently sends the package-specific clinical, diagnostic, pharmacy, or financial aggregates described here.

## Existing implementation and boundaries

- Package definitions, module dependencies, labels, and default plan are in `config/mediflow_modules.php`.
- `LicenseService` resolves the selected packages and enabled modules for the legacy local-license path.
- The operational-health connector requires an enrolled installation and a usable central license. Enrollment binds the installation to a Platform URL and stores an encrypted credential.
- `OperationalHealthOutbox` currently queues a small health payload: application version, pending/failed outbox counts, and enabled module capability keys.
- `operational-sync:heartbeat` records health every five minutes; `operational-sync:deliver` sends the oldest unacknowledged event every minute. Failed or delayed events block later sequence numbers.
- The existing operational transport posts an event to `/operational-sync/events`, authenticates with the enrollment credential, and expects an event-ID acknowledgement.
- `config/sync.php`, `Syncable`, and `SyncRecordJob` describe a different record-level synchronization path. Do not send summary events through its patient/clinical record payloads or treat aggregate reporting as a new bidirectional sync.
- The local repository does not contain the Platform-side `/operational-sync/events` receiver or its account/dashboard implementation. The event schema and receiver behavior below must be agreed with and implemented on the Platform side too.

Keep the current connector health event distinct from the proposed reporting event. Prefer a versioned event type on the same ordered operational outbox/transport only if the Platform receiver can validate and persist both event types independently. Otherwise create a separate, authenticated summary endpoint and outbox. Do not silently change the current health payload contract.

## Design principles

1. **Aggregate only:** never include patient names, IDs, contact details, record IDs, free text, clinical notes, prescriptions, or row-level exports.
2. **Package-aware, not package-limited:** include only measures supported by enabled modules, while retaining universal operational health measures. Omit a disabled module's metric block rather than sending fake zeros.
3. **Account-bound:** associate the event with the enrolled installation and its Platform hospital account on the server. Do not trust a client-supplied hospital/account ID as proof of ownership.
4. **Idempotent and ordered:** retries must not duplicate a reporting period or corrupt a later period.
5. **Explicit semantics:** every metric needs a stable key, unit, period, timezone, and documented inclusion rules.
6. **Fail visibly:** expose stale, delayed, rejected, or invalid summary deliveries to authorized administrators and Platform operators.
7. **Separate authorization from reporting:** module entitlements determine what the installation may operate and report. Package labels are display metadata, not authorization keys.

## Package and module basis

Use the configured package and module slugs as stable identifiers. The current configured packages are:

| Package slug | Configured label | Configured modules |
|---|---|---|
| `specialist` | Care Network | `core`, `access_control`, `patient_records`, `clinical_care`, `specialist`, `billing`, `reports`, `partner_network` |
| `diagnostic_center` | Diagnostic Center | `core`, `patient_records`, `laboratory`, `radiology`, `billing`, `finance`, `reports`, `access_control` |
| `maternity_clinic` | Maternity Clinic | `core`, `patient_records`, `clinical_care`, `nursing`, `maternity`, `billing`, `reports`, `access_control` |
| `pharmacy` | Pharmacy | `core`, `pharmacy`, `billing`, `finance`, `reports`, `access_control` |
| `general_clinic` | General Clinic | `core`, `patient_records`, `clinical_care`, `doctor`, `nursing`, `billing`, `reports`, `access_control` |
| `hospital` | Hospital | `core`, `access_control`, `patient_records`, `clinical_care`, `doctor`, `nursing`, `maternity`, `laboratory`, `radiology`, `pharmacy`, `billing`, `finance`, `reports`, `wards_beds`, `department_management`, `maintenance`, `medical_director` |
| `enterprise_hospital` | Enterprise Hospital | All configured modules plus `branch_management` |

These are defaults from the current configuration. Runtime reporting must use the installation's effective activated/enabled modules, not assume a package's default module list is still enabled. An installation may have selected multiple packages or an explicitly adjusted module selection. Normalize legacy plan aliases before emitting a package slug. For enterprise's `*` module shorthand, expand to the actual known, enabled modules before building a summary.

## Recommended summary catalog and verified local sources

The sources below were checked against the current Eloquent models and, where needed to confirm physical column names, their migrations. They establish that candidate source fields exist; they do **not** by themselves establish a clinically or financially approved measure definition. The source models are local-only aggregation inputs: serialize aggregate values, never model rows or identifiers.

The owner also requested separate report placeholders for all labour services and services in the surgery category. These are service-activity proposals, not counts of distinct surgical procedures; their source, category mapping, date and status rules, and overlap with services named labor require verification. No calculation or synchronization is enabled for either placeholder.

| Package / enabled module | Proposed summary | Verified local source model(s) and fields | Implementation notes / verification status |
|---|---|---|---|
| All packages / `core` | Active user count | `User`: `active`, `deleted_at`; user role relationship / `role_user` association | Count active, non-deleted accounts. Role-group breakdown needs an approved role mapping; do not send names, emails, or user IDs. No verified active flag was found on `Department`, so report configured department count only if desired, not “active departments.” |
| All packages / `core` | Application version and sync freshness | `config/operational_sync.php`: `version`; `operational_sync_outbox`: `status`, `occurred_at`, `acknowledged_at` | These are configuration/outbox sources, not clinical models. The existing operational-health payload already reports version and queue counts; do not duplicate them without a Platform contract decision. |
| All packages / `patient_records` | New patient registrations | `Patient`: `registration_date`, `deleted_at`; table `patients` | Supported as a count by registration period. Exclude soft-deleted rows consistently; avoid any demographic breakdown until disclosure and small-cell rules are approved. |
| All packages / `patient_records`, `clinical_care` | Visits/encounters | `PatientVisit`: `visit_date`, `visit_type`, `status`, `deleted_at`; `Patient` relation | Count visits by `visit_date`, optionally by a controlled `visit_type` allowlist. Do not send `reason_for_visit` or `clinical_notes`. Define whether status or deleted records affect the count. |
| Care Network / `specialist` | Specialist consultations and completion | `SpecialistConsultation`: `status`, `started_at`, `completed_at`; `PatientReferral` relation | Count consultations by a selected event time (e.g. `started_at` or completion period). Completion status values and treatment of cancelled/no-show records require domain confirmation. |
| Care Network / `specialist`, `partner_network` | Referral volume/status and turnaround | `PatientReferral`: `referral_date`, `status`, `accepted_date`, `completed_date`; table `patient_referrals` | Total and status counts are supported. Calculate turnaround only for completed referrals with both dates. “Sent” versus “received” is not yet a verified distinction in the source semantics; do not emit those separate measures until confirmed. Exclude reason, notes, patient ID, and destination detail. |
| Care Network / `partner_network` | Active partner organizations | `CollaborationPartner`: `is_active`; `PartnerMembership`: `is_active` | Count active organizations or memberships as separately named metrics. Do not include contact or organization names. Confirm which partner lifecycle states represent an operational partner. |
| Diagnostic Center / `laboratory`, `radiology` | Investigation request volume and status | `InvestigationRequest`: `requested_at`, `completed_at`, `status`, `deleted_at`, `investigation_id`, `patient_visit_id`; `InvestigationRequest::investigation()` relation | Request counts and elapsed time between request/completion dates are structurally possible. Do not group by clinical diagnosis, specimen, lab number, result value, or free-text category. Validate status values and ensure date-only fields are handled in the hospital timezone. |
| Diagnostic Center / `laboratory`, `radiology` | Reported-result volume / turnaround | `DiagnosticReport`: `status`, `performed_at`, `reported_at`; relation to `InvestigationRequest` | Count reports and calculate performed-to-reported time only when both timestamps exist. Do not transmit clinical note, findings, impression, report revisions, or staff IDs. Request-to-result latency can use request dates only after the two model timestamps are normalized and approved. |
| Maternity Clinic / `maternity` | Antenatal care records/visits | `AntenatalCare`: `patient_visit_id`, `status`, `created_at`; relation to `PatientVisit` | `AntenatalCare` has no dedicated encounter timestamp; `created_at` is only a provisional proxy. Prefer the linked visit's `visit_date` if it is consistently populated. Exclude clinical notes, risk factors, measurements, and identifiers. |
| Maternity Clinic / `maternity` | Labour episodes | `Labour`: `labour_onset_time`, `status`, `admission_id`, `deleted_at` | Count episodes by onset time; status values include ongoing/completed/complicated in the migration. “Labour admission” should not be counted separately from admissions unless duplicate counting rules are defined. |
| Maternity Clinic / `maternity` | Deliveries and approved outcome aggregates | `Delivery`: `delivery_date_time`, `delivery_status`, `delivery_type`, `number_of_babies`, `deleted_at` | Delivery counts and broad approved status/type totals are supported by fields. Outcome reporting requires clinical/privacy approval and suppression; never send complications, delivery summary, indications, or newborn data. |
| Maternity Clinic / `maternity`, `nursing` | Postnatal examinations | `PostnatalExamination`: `examination_date_time`, `delivery_id`, `recovery_status`, `deleted_at` | Count examinations by examination timestamp. Do not transmit examination findings, mood, depression indicators, clinical summary, or follow-up plans. |
| Pharmacy / `pharmacy` | Dispense, purchase, return, adjustment, and damage activity | `PharmacyDispense`: `type`, `quantity`, `created_at`; `StockTransaction`: `type`, `created_at`, `total_amount`; `StockTransactionItem`: `quantity`, `price`, `subtotal` | `PharmacyDispense.type` includes purchase/dispense/adjustment/return/damage. Choose one canonical event source for each metric to avoid double-counting between dispense and transaction records; confirm transaction lifecycle and avoid reference strings. |
| Pharmacy / `pharmacy` | Prescription and prescription-line counts | `Prescription`: `created_at`, `patient_visit_id`; `PrescriptionItem`: `prescription_id`, medication status fields | Counts are possible, but validate date semantics and whether stopped items count. Never send medicine names, dose/instructions, patient visit IDs, or prescriber identifiers. |
| Pharmacy / `pharmacy` | Stock reconciliation volume and variance | `PharmacyStockReconciliation`: `checked_date`, `total_batches_checked`, `total_system_quantity`, `total_physical_quantity`, `total_variance`, `total_variance_value` | Counts and aggregate variance are structurally available. Validate that variance values are not double-counted with linked stock transactions. Monetary valuation must not be sent until currency is established. |
| Pharmacy / `pharmacy` | Low/out-of-stock medicine counts | `Medicine::availableQuantity()` sums `MedicineBatch.quantity_remaining` for non-expired batches | “Out of stock” can mean available quantity equals zero. No verified low-stock threshold was found in the inspected model; omit low-stock counts until a threshold and scope are defined. Do not send medicine-level names or quantities. |
| General Clinic / `clinical_care`, `doctor`, `nursing` | General outpatient/encounter volume | `PatientVisit`: `visit_date`, `visit_type`, `status`, `deleted_at`; `Appointment`: `appointment_date`, `status`, `deleted_at` | Visits and appointments are separate concepts; do not add them together as a single consultation count. “New” versus “follow-up” is not a guaranteed normalized classification; use only after validating actual `visit_type` values. |
| Hospital / `wards_beds`, `clinical_care` | Admissions and discharges | `PatientAdmission`: `admission_date`, `discharge_date`, `status`, `deleted_at`; `Admission`: related `PatientVisit`, `Bed`, `Discharge` | The owner selected `PatientAdmission` as the admissions source. Do not sum both models. The local preview counts non-deleted `PatientAdmission` records by `admission_date`, with non-deleted patients and all statuses; reporting status rules and discharge semantics still need confirmation. |
| Hospital / `wards_beds` | Bed capacity / availability snapshot | `Bed`: `ward_id`, `status`; `Ward`: `capacity` | Bed status can support occupied/vacant counts after enumerating actual status values. `Ward.capacity` is a string, so validate numeric parsing and avoid assuming it matches bed-row count. Occupancy denominator and timestamp policy need approval. |
| Packages with `billing` | Bills issued, billed amount, outstanding amount, bill status | `Bill`: `issued_date`, `amount`, `due_amount`, `discount`, `status`, `deleted_at`; table `bills` | Count/sum by issue period and approved status filters. Money columns are present, but no currency column was verified; do not label or combine these totals as currency amounts until deployment currency is established. Exclude bill numbers, descriptions, notes, and patient relations. |
| Packages with `billing` | Payments and payment totals | `Payment`: `payment_date`, `amount`, `status`, `deleted_at`; table `payments` | Count/sum by payment date and status. Status includes pending/completed/failed/reversed. Decide whether reversed payments are excluded or separately reported; no currency column was verified. Exclude payment IDs, references, notes, and patient IDs. |
| Packages with `finance` | Revenue totals and broad category counts | `Revenue`: `revenue_date`, `amount`, `revenue_category_id`, `department_id`; `RevenueCategory` relation | Revenue date/amount/category fields are verified. No currency field was found; do not send monetary totals until account currency is configured and confirmed. Prefer approved category mapping over category labels or IDs. |
| Packages with `finance` | Expense totals and broad category counts | `Expense`: `expense_date`, `amount`, `expense_category_id`, `department_id`; `ExpenseCategory` relation | Expense date/amount/category fields are verified. No currency field was found; do not send monetary totals until account currency is configured and confirmed. Exclude descriptions, payee/vendor fields, and staff IDs. |
| Packages with `reports` | Saved report count | `Report`: `created_at`, `from_date`, `to_date` | A period count can use `created_at`. The model does not expose a verified report-family field; do not report counts by family. Do not include report reference, user ID, or `pdf_path`. |
| Enterprise / `branch_management` | Per-branch operational summaries | No branch model/table was identified in the inspected local model catalog | Not implementable from a verified local source yet. Exclude branch-level metrics until branch ownership, model/schema, and account-level privacy rules are established. |

### Source verification and metric readiness

- The local preview definitions have stable metric keys, version, module gate, source, timestamp, filters and unit in `app/Services/InsightMetricCatalog.php`. `InsightLocalPreview` rechecks the verified installation catalog before calculation and returns only the selected aggregate and definition metadata. This version describes local preview semantics; it is not an agreed Platform event schema and does not authorize synchronization.
- The installation-admin local preview also calculates labour episodes by `labour_onset_time`, deliveries by `delivery_date_time`, and postnatal examinations by `examination_date_time`. It includes all statuses, excludes deleted event and patient rows, and suppresses counts involving fewer than five distinct patients. These are local-only provisional calculations, not a reporting release. Labour episodes are not the same measure as services named labor; no outcomes or newborn records are shown.
- **Source verified** means the model/migration contains the listed field or relation. It does not mean the proposed metric's definition has been approved.
- **Ready to define**: simple period counts from `Patient.registration_date`, `PatientVisit.visit_date`, `InvestigationRequest.requested_at`, `Delivery.delivery_date_time`, `Bill.issued_date`, and similar explicit event dates, subject to status/deletion rules and tests.
- **Requires canonical source decision**: pharmacy transaction activity (overlapping dispense/transaction models), and visits versus appointments. For admissions, the owner selected `PatientAdmission`.
- **Requires business/data governance decision**: currency, stock thresholds, active partner definition, outcome reporting, role groupings, follow-up classification, and all small-cell reporting.
- **Not currently supported by a verified source**: branch-level reporting and report-family counts.

For each approved metric, record its stable key, source model/table and fields, module gate, event-time field, filters, aggregation, unit, currency if applicable, suppression rule, and owner in the metric catalog. Add query tests for soft deletes, boundary dates/timezones, reversed/cancelled records, null timestamps, duplicate source records, and disabled-module omission before enabling transmission.

## Proposed event contract

Use a schema version and stable metric keys. The exact JSON below is illustrative and needs agreement with the Platform receiver:

```json
{
  "event": {
    "event_id": "uuid",
    "sequence": 124,
    "event_type": "package_summary",
    "schema_version": 1,
    "installation_uuid": "installation-uuid",
    "package_slugs": ["hospital"],
    "enabled_modules": ["billing", "clinical_care", "patient_records"],
    "period": {
      "granularity": "day",
      "start": "2026-11-11T00:00:00Z",
      "end_exclusive": "2026-11-12T00:00:00Z",
      "timezone": "Africa/Lagos"
    },
    "generated_at": "2026-11-12T00:10:00Z",
    "metrics": {
      "patient_records.registrations": {"value": 12, "unit": "count"},
      "clinical_care.visits": {"value": 38, "unit": "count"},
      "billing.paid_total": {"value": "125000.00", "unit": "currency", "currency": "NGN"}
    },
    "payload_sha256": "sha256-of-canonical-payload"
  }
}
```

Contract requirements:

- The enrolled installation identity is authoritative. The Platform resolves its account/tenant from the authenticated credential and installation binding; it must reject mismatches.
- `event_id` is stable across retries. `sequence` is monotonic per installation and unique at the receiving side.
- `period.start` is inclusive and `period.end_exclusive` is exclusive. Store timestamps in UTC and include the source timezone for local-day interpretation.
- Each `metrics` entry has a stable key and type. Use integer counts; use decimal strings plus ISO currency codes for money to avoid floating-point rounding.
- Include only module slugs that are enabled for the period. Do not put PII or uncontrolled strings in metric keys or dimensions.
- Send a whole period snapshot, not an increment, where practical. The Platform can upsert by `(installation_uuid, period, schema_version)` and safely accept a retry.
- The acknowledgement must echo `event_id` and indicate accepted/duplicate, matching the existing transport's acknowledgement expectations.
- Validate payload size, metric count, allowed metric keys, numeric ranges, supported currency codes, period boundaries, module allowlist, hash, and schema version on both sides.
- Schema evolution must be additive where possible. Reject unknown schema versions clearly; retain old event readers while installations roll forward.

## Collection and aggregation

1. Build a metric catalog with one implementation owner, source model/table, event timestamp, filters, unit, module requirement, and test fixture for every metric.
2. Add a summary builder that checks the effective enabled module set before running each module's aggregate queries. Keep module-specific query logic out of controllers and dashboard code.
3. Aggregate locally using bounded SQL queries. Group only by approved low-cardinality dimensions (for example day, broad service family, or currency); do not group by patient, clinician, or free-text data.
4. Produce a complete daily snapshot in the configured hospital timezone, normalize its period to UTC, and define how late-arriving or backdated records are handled. A recommended starting policy is to re-create a recent rolling window (for example the previous seven days) with an idempotency key, then mark earlier periods final after an agreed correction window.
5. Record an outbox event transactionally after aggregation succeeds. Do not hold a database transaction open while making a network request.
6. Deliver via the enrolled connector, retry transient transport errors with bounded backoff, and keep the current ordered-delivery behavior unless the Platform explicitly supports independent streams. Ensure a permanently failed summary can be retried or skipped by an audited administrator action without silently losing later events.
7. Purge acknowledged payloads according to an approved retention period. Retain only the minimum delivery metadata required for troubleshooting and audit.

If reusing `operational_sync_outbox`, add a discriminator/schema validation and ensure existing health events remain backwards-compatible. Consider separate sequence streams or a documented shared ordering contract before introducing large summary events; a failed health event should not indefinitely block unrelated business summaries without an explicit decision.

## Platform receiver and reporting

The Platform-side implementation should:

- Authenticate the installation credential, resolve the hospital account, and verify installation status before accepting an event.
- Validate the envelope and payload schema; enforce size/rate limits and reject PII-shaped or unknown metrics where feasible.
- Store raw immutable event metadata separately from normalized per-period metric rows, or use another design with equivalent idempotency, audit, and migration guarantees.
- Enforce uniqueness for installation + event ID and installation + sequence. Return `duplicate` for an already accepted event only when its hash matches; reject a reused ID/sequence with different content.
- Expose received time, period, package/modules, last successful period, delivery lag, and rejected/failed counts to authorized monitoring.
- Scope every dashboard, export, and API query to the authenticated Platform account. Platform users must never see another hospital's summaries without a separately authorized cross-account role and audit trail.
- Show missing data as missing/stale, not as zero activity. Distinguish zero measures from absent modules or a failed collection.
- Preserve the metric schema version and definitions used to create each historical row, so later code changes do not silently rewrite old periods.

## Privacy, security, and governance

### Proposed end-to-end data-protection path

This section is a design, not an implemented release pipeline or privacy approval. The existing approval permits design and non-production staging only; external aggregate releases, including corrections, remain disabled. The local preview is exact, installation-admin-only data and must never be treated as an input ready for transmission.

| Stage and boundary | Required protection and failure behavior |
|---|---|
| 1. Authorization and scope | A hospital-authorized administrator must explicitly authorize the reporting purpose, installation, metric categories, destination account and policy version before collection for release. Record who authorized it and when, using an auditable, revocable authorization. Check the current verified license and enabled modules at collection and again before dispatch; package names alone do not authorize a metric. Unavailable, expired or revoked authorization blocks collection and dispatch, rather than producing zeros. |
| 2. Local collection | Query only approved metric sources for a completed local period. Keep patient identifiers and event rows in Clinic; use them only transiently to compute aggregates and enforce contribution bounds. Do not write raw rows, patient identifiers, exact counts, arbitrary dimensions or clinical text to an outbox, logs, metrics, errors or Platform payloads. Validate local-day boundaries, timezone and source timestamp semantics before implementation. |
| 3. Contribution bounding | For the initially selected protected measures, bound each patient to one contribution per metric per local day. This yields a patient count, not the existing event count. A privacy specialist must also define and verify bounds across metrics, dates, installations and corrections under the chosen adjacency model; the daily cap alone does not establish a lifetime guarantee. Unsupported metrics or missing identity links must fail closed, not be silently counted. |
| 4. Privacy accounting and release | A specialist must approve the protected unit, adjacency, sensitivity, randomized mechanism, epsilon/delta units, composition, lifetime budget, and treatment of thresholds and overlapping reports. Enforce that reviewed policy at release time; do not transmit the local preview or its exact `available`/`suppressed` decision. The current reservation ledger is unprovisioned, isolated and not sufficient to authorize a release. Missing, invalid, exhausted or inconsistent policy/ledger state blocks release. |
| 5. Atomic release and retries | Define a canonical identity from the installation binding, metric definition and version, period/timezone, approved privacy policy and correction revision. In one transaction, lock and validate authorization and budget state, reserve the required budget, persist the exact randomized result and its release identity, and create its delivery outbox item. A retry of the same identity returns the same persisted output without re-randomization or another charge; conflicting input fails. Do not refund budget because delivery fails, authorization is withdrawn, or a result is deleted. Network calls must occur outside the transaction. |
| 6. Transport and Platform receipt | Send only the allowlisted, versioned protected output and necessary period/release metadata through the approved installation-bound connector. Require HTTPS in production, reject redirects, encrypt credentials at rest and never log secrets or payloads. Authenticate the installation, check its current account/link and status, validate schema, sizes, identity and hash, then persist an idempotent acknowledgement. A replay with a conflicting identity or hash is rejected; a failed health event must not silently block summaries unless the stream-ordering contract explicitly says so. |
| 7. Storage, reporting and audit | Treat even approximate summaries as sensitive. Encrypt Clinic and Platform databases, outboxes and backups at rest with managed keys, limit service and human access, and scope every Platform query and export to the authorized organization and account link. Audit authorization, policy and budget administration, releases, retries, access, withdrawal and deletion using minimal metadata; do not log source rows, exact values, noisy payload contents or credentials. Reports must label values as approximate and distinguish missing, stale and unavailable data from zero. |
| 8. Corrections and overlapping reports | A correction is a new revision, not a retry. Apply the reviewed privacy policy and charge the budget for each newly randomized revision; persist it independently and preserve the original release's accounting. If budget, consent or disclosure safeguards are unavailable, withhold the correction and show an actionable status. Review the composition and inference risk across prior releases, overlapping periods, categories, revisions and exports before enabling reporting. |
| 9. Withdrawal, deletion and retention | Withdrawal stops new collection and dispatch and initiates a durable, authenticated, idempotent Platform deletion request scoped to the authorized installation and categories. Coordinate withdrawal with in-flight deliveries so an accepted event cannot silently recreate deleted data; keep deletion pending and retry until the Platform confirms the applicable data is removed. Define handling of acknowledged, pending and leased outbox items, copies/exports and unavoidable backup expiry in the approved retention policy. Retain only the minimum non-payload audit, deletion-tombstone and privacy-accounting records required for governance; deletion must not replenish the budget. Surface pending or failed deletion to authorized administrators. |
| 10. Backup, recovery and incident response | Protect backups and keys with access controls and an approved retention schedule. Before resuming after restore, reconcile release IDs, persisted outputs, reservations, spent budget, authorization, deletion tombstones and Platform acknowledgements against an authoritative durable record; never roll back or reset spent privacy budget to make releases possible. If reconciliation, key access or integrity fails, keep releases stopped and escalate through an audited incident process. Document breach notification and jurisdiction-specific obligations with the data controller. |

**Design acceptance evidence before external release:** independent privacy and data-controller sign-off; a documented retention/deletion policy; tests for contribution bounds, threshold leakage, overlapping periods and corrections; atomic crash recovery and identical retry output; consent-withdrawal/in-flight-delivery races; tenant isolation, duplicate/conflicting receipt and deletion replay; backup/restore reconciliation; and concurrent accounting tests against the actual production database engine. Neither a passing ledger reservation nor the existing five-person local preview satisfies these requirements.

### Reviewer re-assessment and release decision — 2026-11-12

This is an engineering review of the implementation visible in this repository, not a formal sign-off by an appointed privacy specialist, legal adviser, or hospital data controller.

**Re-review decision: APPROVED WITH SCOPE LIMITATION — design and non-production ledger staging only.** The current ledger changes and focused tests improve confidence in the accounting primitive. This approval does **not** approve provisioning a production privacy budget, transmitting any patient-derived aggregate, or enabling external summary releases. Keep the release gate closed. The ledger is not a differential-privacy mechanism and the local preview is not safe to transmit.

**Positive findings**

- `InsightPrivacyBudget::reserve()` requires a pre-provisioned budget and rejects invalid inputs, exhausted or corrupt budget state, and conflicting retries.
- Reservation insertion and spent-unit increment are in one database transaction, serialized against the existing budget row. Identical request ID/hash/cost retries are idempotent and do not charge twice; reservations are not refunded.
- The migration stores a UUID reservation key, fixed-length request hash, and integer cost. The service now rejects unexpected budget rows, invalid reservation costs, and any mismatch between spent units and the reservation-cost sum; budget updates also compare the previously read limit and balance.
- The migration refuses rollback if either accounting table contains data, protecting populated ledger tables from a normal migration rollback.
- `InsightReleaseReadiness` keeps `can_release` false and explicitly lists implementation blockers. Enabling sync configuration flags does not lift that gate.
- Re-review validation: the focused ledger and local aggregate-preview tests passed on SQLite: 19 tests, 101 assertions. This does not verify production-database locking/concurrency or an external release path.

**Release-blocking findings**

1. **No defined privacy accounting unit or guarantee.** `limit_units` and `cost_units` are arbitrary integers: no mapping to epsilon/delta, adjacency model, sensitivity, noise mechanism, composition theorem, or approved lifetime allocation is implemented. A passing reservation is not evidence that a release is private.
2. **No protected release path.** The reservation service is not called by a release pipeline. There is no noise generation, contribution-bound enforcement at release time, persisted private output, or atomic linkage between reservation, output, and delivery outbox. The local preview can expose exact event counts or exact distinct-patient counts to installation administrators and must remain local-only.
3. **Suppression is not a privacy mechanism.** The preview applies a five-distinct-person threshold, but returns exact counts above that threshold. Sending `available` versus `suppressed` also discloses a threshold bit. Repeated and overlapping daily metrics can reveal suppressed or differenced values. Suppression and any threshold decision need to be included in the reviewed privacy mechanism.
4. **Budget governance and authoritative recovery are not implemented.** Runtime reservation now detects unexpected budget rows and mismatches between the balance and reservation-cost sum, but the schema itself still allows multiple budget rows and does not make ledger rows immutable. There is no reviewed provisioning workflow, authorized actor/audit record, immutable budget/policy version, or tested reset/restore/reconciliation procedure. A privileged direct edit that keeps the row and reservation sums internally consistent can still change accounting state. Migration rollback protection does not prevent manual deletion, backup rollback, partial loss, or older migration code. Do not initialize, reset, edit, or rebuild the ledger to bypass the lifetime budget.
5. **Retry identity is not yet a release identity.** The ledger checks only that a caller repeats the supplied UUID, request hash, and cost. It does not derive or validate that hash against a canonical metric/period/query/policy, nor ensure retries deliver the same persisted randomized result. A new reservation ID could account for the same query again.
6. **Production concurrency evidence is insufficient.** Current tests use in-memory SQLite and do not exercise concurrent independent transactions against the intended production database. Verify row-lock semantics, retry/deadlock behavior, and no overspend under concurrent reservations using the production database engine.
7. **Consent and platform controls remain absent.** Hospital authorization/withdrawal, Platform ingestion, tenant isolation, deletion, retention, and audit of actual released payloads are not implemented.

**Conditions to reconsider approval**

- Have an independent privacy specialist document and approve the threat model, protected unit, adjacency, contribution bounds across all overlapping metrics and corrections, mechanism, composition/accounting method, lifetime budget, and suppression policy.
- Implement a reviewed and auditable budget provisioning/change process; enforce the intended single-budget invariant at the database and application boundaries, and reconcile spent units against tamper-evident reservations.
- Derive the request identity from canonical release inputs; atomically reserve budget and persist the exact randomized output so every retry serves the same release without spending again.
- Implement and test noise, contribution limiting, suppression/complementary suppression, corrections, idempotency, and concurrent budget accounting end to end on the production database engine.
- Complete hospital consent/withdrawal and Platform authorization, tenant isolation, deletion, retention, and audit controls; reconcile the externally transmitted result against the approved release schema.
- Keep exact local preview results clearly marked as local-only and ensure no scheduler, API, log, or Platform route can transmit them before the release gate is explicitly approved.

**Engineering follow-up:** the reservation service checks that only the expected budget row exists, rejects invalid reservation costs, reconciles the recorded spent units against reservation costs before accepting a new reservation or retry, and checks the previous balance when updating it. Tests cover these fail-closed cases. These controls detect several inconsistent states but do not make database rows immutable, enforce a database-level singleton, establish safe provisioning or restore, or replace production-engine concurrency testing. External release remains **NOT APPROVED**.

**Additional staging safeguard:** reservation costs outside the permitted positive integer range are rejected even when their sum matches the balance. The ledger migration refuses rollback if either accounting table contains data; rollback of empty tables remains possible. Run migrations with writers stopped. This guard does not prevent manual database changes, older migration code, backup rollback, partial database loss or administrative deletion; it is not an authoritative recovery mechanism and does not change the release decision.

**Re-review approval note:** `APPROVED WITH SCOPE LIMITATION — design, local-only preview, and non-production ledger staging may proceed. Focused SQLite tests passed, but they do not establish production-database concurrency safety. This is not production privacy approval and does not authorize external summary transmission. Keep aggregate external synchronization disabled until privacy accounting and release generation, budget governance, production-database concurrency, consent/withdrawal, and Platform controls are implemented and independently reviewed by a privacy specialist.`

- The owner selected approximate reporting with a controlled privacy budget, with at most one contribution per patient per metric per local day. Such measures represent distinct patients, not raw event totals. The local preview can show these exact distinct-patient counts to installation administrators; this preview is not a private release.
- Releases, including corrections, remain disabled until a privacy specialist chooses and reviews the lifetime budget and the complete disclosure design. The design must account for repeated releases and overlapping metrics/periods, enforce contribution bounds across those releases, protect retry/idempotency and concurrent budget accounting, and assess the interaction with suppression. Do not send exact values, unprotected threshold decisions, or independently re-noised retries.
- Obtain explicit hospital/account authorization for each reporting purpose and document retention, access, deletion, and applicable jurisdictional requirements.
- Do not include direct identifiers, patient-level timestamps, record UUIDs, notes, diagnosis strings, medicine names, individual staff identifiers, or arbitrary filter values.
- Apply small-cell suppression before transmission for sensitive metrics. Agree a minimum cell threshold with the data owner (a starting recommendation is fewer than five observations), and use complementary suppression where totals would reveal a suppressed cell.
- Use only HTTPS in production; keep redirect following disabled. Keep enrollment credentials encrypted at rest and out of logs, telemetry, exception text, and payloads.
- Reuse installation-bound credentials only for the approved reporting API. Apply least privilege, rate limits, credential revocation/rotation, and audit records for enrollment and manual retries.
- Log event IDs, sequence, period, status, error code, and counts of metrics, but not payload contents or secrets.
- Treat operational summaries as potentially sensitive health/business information even when de-identified.

## Configuration and feature gating

Use explicit configuration flags and ship the feature disabled until both sides are deployed and tested. Suggested switches:

```env
OPERATIONAL_SUMMARY_SYNC_ENABLED=false
OPERATIONAL_SUMMARY_PERIOD=daily
OPERATIONAL_SUMMARY_TIMEZONE=Africa/Lagos
OPERATIONAL_SUMMARY_CORRECTION_DAYS=7
```

These names are suggestions, not existing configuration. Align names with the repository's existing `OPERATIONAL_SYNC_*` settings. Keep summary collection independently controllable from the current health heartbeat and from legacy bidirectional record sync. Gate metrics by enabled modules and central-license status; do not infer that a deployment is licensed solely because it sets a package name.

## Implementation plan

### Phase 1: Product and data approval

- Confirm target Platform account ownership and hospital consent.
- Approve metric names, definitions, periods, currencies, timezone, suppression threshold, retention, and supported packages.
- Agree API path, authentication scope, payload limits, idempotency rules, error codes, and schema-version policy with the Platform team.
- Map each metric to real schema fields and establish owner-reviewed definitions.

### Phase 2: Local summary generation

- Add a typed metric catalog and module-aware aggregation service.
- Add unit/feature tests for each metric's date boundaries, soft deletes, reversals, module gating, currencies, zero values, and disabled-module omission.
- Add a preview/diagnostic view or command that shows metric keys and values locally to an authorized installation administrator, with no external delivery.
- Add privacy tests asserting that serialized payloads contain no IDs, names, notes, or row-level records.

### Phase 3: Outbox and delivery

- Add a versioned summary event schema and persistent outbox path with stable event IDs, sequence, checksum, attempts, leases, and acknowledgement.
- Add a scheduled daily collection command and delivery/retry command, both behind the new disabled-by-default feature gate.
- Handle late-arriving data, replay, permanent failure, operator retry, retention, and queue backlog visibility.
- Keep health heartbeat events and legacy record-sync jobs behavior unchanged.

### Phase 4: Platform ingestion and dashboards

- Implement authenticated receiver, validation, idempotent persistence, account scoping, monitoring status, and schema-version support.
- Build account-level trend/report views that label metric periods and stale data clearly.
- Test unauthorized account access, tampering, duplicate delivery, out-of-order events, invalid hashes, unknown schema versions, and revoked credentials.

### Phase 5: Controlled rollout

- Deploy schema and receiver support before enabling local senders.
- Use synthetic fixtures and a test Platform account first; never use production patient-level data as test payload.
- Enable for a small opt-in cohort, compare local report totals to transmitted aggregates, inspect delivery lag and rejection rates, and obtain hospital sign-off.
- Expand gradually. Provide a kill switch that stops collection and delivery without stopping unrelated record sync or local clinical workflows.

## Operational monitoring and acceptance criteria

The feature is ready for production only when:

- Every configured package has a reviewed metric-to-module catalog; unsupported measures are explicitly omitted.
- A disabled module produces no corresponding metrics, and enabling a module does not expose unrelated data.
- Re-running a period produces the same event identity/content unless the approved correction process changes the snapshot.
- Duplicate and retry deliveries do not double-count; mismatched duplicate hashes are rejected and alerted.
- Account isolation, credential revocation, TLS, payload validation, retention, and audit behavior pass security tests.
- Administrators and Platform operators can identify last successful period, current lag, pending/retry/failed counts, and actionable error codes.
- A stale or missing period is distinguishable from a valid period with zero activity.
- Aggregate values reconcile to the approved local reports for a representative test window, including reversals, timezone boundaries, and supported currencies.
- No local clinical workflow depends on successful Platform connectivity.

## Related repository references

- Package and module catalog: `config/mediflow_modules.php`
- Current legacy record-sync configuration: `config/sync.php`
- Operational connector settings: `config/operational_sync.php`
- Current operational health payload and outbox: `app/Services/OperationalHealthSource.php`, `app/Services/OperationalHealthOutbox.php`
- Current delivery, transport, and enrollment: `app/Services/OperationalHealthDelivery.php`, `app/Services/OperationalHealthTransport.php`, `app/Services/OperationalSyncEnrollment.php`
- Existing sync background: `docs/SYNC_IMPLEMENTATION_GUIDE.md`, `docs/SYNC_IMPLEMENTATION_CHECKLIST.md`
- Account activation and licensing boundary: `docs/CENTRALIZED_ACTIVATION_AND_LICENSING.md`
