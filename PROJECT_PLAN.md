# Clinic Visits & Billing API — implementation plan

Status: implementation started. Increment 1 prepares the seven business tables, branch assignments, role/active columns, UserRole enum, Sanctum user token support, PSR-12 formatting and Git. Syntax and route registration checks passed. No migrations, seeders or database-writing tests executed. Schema execution and integration verification remain pending. Next increment: API contract, domain models/factories, active-user/token middleware and branch Policies; summary and operational tables follow their features.

## 1. Decisions before implementation

- Database connection verified: `assignment`, server `12.2.2-MariaDB`. Use this existing connection for development as requested. Use a separate MariaDB database for tests. No database replacement or version-matching prerequisite.
- Use Laravel 13.43.0, PHP constraint `^8.4`, and installed PHP 8.4.19. Preserve PHP 8.5 compatibility. The user explicitly chose the current stack on 19 November 2026; proceed without downgrading or waiting for stack approval. Document actual versions with benchmark results.
- Core deliverable remains the assessment API. User-requested addition: a minimal authenticated clinic dashboard with muted backgrounds, using Blade, ordinary CSS and minimal JavaScript. Reuse the same services and Policies; no separate SPA or frontend framework is required. See section 9B.
- Keep one application, ordinary Eloquent models, Form Requests, API Resources, small injected services, and queued jobs. No repository interfaces, generic CRUD framework, or unnecessary abstractions.
- Dependencies verified installed: Sanctum 4.5.0 for tokens; S3 Flysystem adapter 3.35.3 and AWS SDK 3.405.0 for S3/SQS; Boost 2.10.4 for development guidelines/tools. PHPUnit/Pint are installed. Generated Boost guidelines have been read; MCP configuration exists, but Boost tools are not connected in this agent session.
- Prefer SQS for deployment queues and database-backed job progress. Redis is optional and unnecessary initially. Local SQS integration can use a development queue; tests fake queue/storage.
- No `.git` directory currently exists here. Initialize Git and commit each completed step during implementation; publishing and collaborator access are separate user actions.
- If received on 19 November 2026, the five-calendar-day deadline is 24 November 2026; confirm the submission time.

## 2. Code style review and rules to follow

This is a targeted style review, not a security audit of the reference application.

| Reference | Keep | Adapt for this assessment |
|---|---|---|
| `app/Services/PortalUserSyncService.php:16` | Named business methods, explicit arrays, Eloquent, parameter/return types | Use injected services and short methods |
| `app/Services/ActivityWriter.php:14` | Straightforward field mapping and defaults | Keep sensitive patient values out of logs |
| `app/Models/InvoicePayment.php:12` | Explicit fillable fields | Add casts, typed relationships, consistent spacing |
| `app/Models/MdrInvoice.php:32` | Normal Eloquent relationships | Consistent camelCase relationship names |
| `app/Http/Controllers/PortalUserSyncController.php:11` | Controller delegates business work | Form Request + Resource; do not expose exception messages as at line 27 |
| `app/Imports/DeviceImport.php:106` | Explicit validation and mapping | Replace per-row lookups/writes with bounded batch validation/upserts |
| `app/Jobs/FileUpload.php:25` | Separate background work | Queue record IDs/S3 keys, not uploaded-file objects/local request files |
| `tests/Feature/MdrCertificateDownloadTest.php` | Named tests and concrete assertions | Add real HTTP feature tests using an isolated MariaDB test database |

Use `index`, `show`, `store`; explicit `if` conditions; small methods; validated arrays; `$fillable`; PHP type hints; normal Laravel exception handling. Query Builder is appropriate for reports and bulk writes, inside services/commands. Use one service per business area, without adding a second action layer. Configure Pint for the assessment's PSR-12 requirement. Avoid copying legacy response wrappers when API Resources already provide the response structure.

## 3. Define the API contract and accounting rules

Write these assumptions into README/OpenAPI before coding:

- One healthcare group; clinic staff access visits/billing only within assigned branches, system admins access all branches. A minimal shared patient directory allows first visits at another branch; clinical history remains branch-scoped. Use `branch_user` and Policies; see the role matrix and patient visibility rules in section 4A.
- All assessment API endpoints use Sanctum token authentication, Form Requests, Resources, and rate limits. Issue the reviewer token through a CLI command. The additional Blade dashboard uses session authentication, CSRF protection and the same Policies. Public registration is unnecessary.
- MRN, CPR and phone are strings, preserving leading zeros. Exact normalized MRN/CPR/phone search; literal name-prefix search. Phone need not be unique. CPR uniqueness is an explicit assumption.
- Return masked CPR/phone by default. Use synthetic data only. Do not log search values, authorization headers, request bodies, or signed URLs; redact query strings in Nginx/application logs. Encrypt storage/backups and require TLS. Field-level encryption is an optional later requirement: encrypted casts alone cannot support indexed exact search.
- Use a single configured currency. BHD with three decimal places is a proposed assumption, not a PDF requirement. Store all monetary values as integer minor units in BIGINT columns; accept/return decimal strings. Integer quantities initially; reject excessive amounts/quantities before multiplication.
- Gross = sum(quantity × unit price); net = gross − discount + VAT; outstanding = net − paid. Validate discount ≤ gross; calculate VAT using integer basis points and a documented rounding rule. Never accept client-calculated totals. Currency/VAT configuration is not a legal tax determination.
- Store timestamps in UTC. Reports use the invoice billing date in a documented reporting timezone. Treat the inclusive `to` date as an exclusive next-day boundary. Support any rolling one-year range, including ranges crossing December/January; do not restrict both dates to the same calendar year. Define the maximum exclusive end as the start plus one calendar year, with documented leap-day handling.
- Revenue reports include non-void invoices billed in the selected range; paid is payments allocated to those invoices as of report generation, not cash received within the date range. Outstanding follows the same basis. Top patients uses the same net-billing inclusion rule.
- Cursor pagination means next/previous navigation, not arbitrary `page=10000` jumps. Document this explicitly.

## 4. Write schema files — do not execute them

Required business volume: **30,001,025 rows**, before summary/operational tables.

| Table | Required rows | Main columns/constraints |
|---|---:|---|
| branches | 25 | id, name, city, timezone |
| doctors | 1,000 | id, branch_id FK, name, specialty |
| patients | 1,000,000 | id, unique MRN, unique normalized CPR, phone, name, DOB DATE, gender |
| visits | 5,000,000 | id, patient_id/doctor_id/branch_id FKs, visit_date DATETIME, status |
| invoices | 5,000,000 | id, unique visit_id FK, billing_date, currency, total/discount/VAT/net minor units, status |
| invoice_items | 15,000,000 | id, invoice_id FK, service_code, integer qty, unit_price minor units |
| payments | 4,000,000 | id, invoice_id FK, method, amount minor units, paid_at |

Use matching unsigned BIGINT FK types, bounded strings, explicit nullability, and restrictive deletion for clinical/billing history. Validate that the doctor belongs to the chosen branch. Doctor branch membership is fixed for this assessment; visits retain their historical branch.

Initial indexes, verified later against actual query plans:

- Patients: unique MRN/CPR; phone; `(name, id)` for prefix search.
- Visits: `(patient_id, visit_date, id)`, `(visit_date, id)`, `(branch_id, visit_date, id)`, `(doctor_id, visit_date, id)`, `(status, visit_date, id)`.
- Benchmark branch+status and doctor+status filters; add corresponding equality-column/date/id composites only when necessary. One wide composite does not solve all optional-filter combinations.
- Invoices: unique visit_id; `(billing_date, id)` for report rebuilds.
- Items/payments: invoice_id indexes for invoice detail; other indexes only when a query needs them.

Operational tables: token/branch access tables, idempotency records, seed progress, imports, exports, and bounded chunk progress. Job rows include owner, status, progress, retry state, timestamps, safe error code, and private S3 object key.

Summary tables:

- `daily_branch_revenue`: unique `(billing_date, branch_id)`.
- `daily_doctor_revenue`: unique `(billing_date, doctor_id)` plus branch_id for access filtering; verify indexes for scoped reports.
- `patient_year_totals`: unique `(year, patient_id)` and `(year, net_minor DESC, patient_id)` for top 100. Top-patients reporting is system-admin-only initially, avoiding incorrect cross-branch totals.

### 4A. User accounts and authorization

Proposed application roles below are design decisions; the PDF requires authorization but does not prescribe role names. Use three string values in `users.role`, backed by one PHP enum and Policies. No roles/permissions package or separate admin login table is needed.

| Role | Purpose | Data scope |
|---|---|---|
| `admin` | System/group administrator and assessment reviewer | All branches |
| `clinic_admin` | Clinic manager | Branches explicitly assigned through `branch_user` |
| `clinic_user` | Reception/billing staff | Branches explicitly assigned through `branch_user` |

| Capability | admin | clinic_admin | clinic_user |
|---|---|---|---|
| Search minimal patient directory | Yes | Yes | Yes |
| Read patient visits and invoices | All branches | Assigned branches | Assigned branches |
| Create visit with invoice/items | All branches | Assigned branches | Assigned branches |
| Revenue by branch/doctor/month | All branches | Assigned branches | No |
| Top 100 patients across group | Yes | No | No |
| Export visits | All branches | Assigned branches | No |
| Import/upsert shared patient master data | Yes | No | No |
| Manage accounts/branch assignments | Yes, via CLI initially | No | No |
| Change roles or grant global access | Yes, via CLI initially | No | No |

- One role per user, consistent across all their assigned branches. `branch_user` supports multiple branches without putting a comma-separated list in `users`. Global admins need no branch assignments.
- Clinic admins manage clinic operations/reporting, not global identities. User-management HTTP endpoints/UI are outside this assessment; create/deactivate accounts and assign branches through a small authenticated-operator CLI workflow.
- Patients and doctors are business records, not `users`. No patient portal, doctor login, separate finance role, or public registration in the initial scope.
- Shared patient search returns only ID, MRN, name and masked CPR/phone; DOB/gender/full identifiers are omitted by default. Exact identifier search is rate-limited. This shared directory is an explicit single-group assumption, not permission to read another clinic's clinical history.
- `/patients/{id}/visits` always filters visits by allowed branch IDs. An existing patient with no visible visits yields an empty collection. Invoice/item/payment access follows the invoice's visit branch. Out-of-scope invoice/visit IDs return 404; forbidden capabilities return 403.
- Branch filters narrow existing authorization; they never grant access. Validate doctor/branch pairing. Submitted `role`, `user_id`, `created_by` and unrestricted branch lists are never trusted.
- Recheck current `is_active`, role, branch scope and token abilities on every request. Jobs check current authorization at processing time; scope changes invalidate/restart affected exports. Downloads require current authorization, ownership (or global admin), and a short-lived URL. Already-issued URLs remain usable until expiry; keep expiry short.
- Jobs contain the requesting user's ID and authorized scope, never a serialized authenticated session. Store original scope for auditing; reject an export if its original scope is no longer authorized rather than exposing a partially broader file.
- Initial manual demo accounts: one system admin, one clinic admin and one clinic user for branch A, plus a clinic user for branch B to test isolation. Creating one admin per each of 25 branches is optional, not an assignment requirement. Use unique passwords/tokens; do not put credentials in the plan or Git.
- Account deactivation replaces deletion, preserves attribution and denies existing tokens; revoke tokens as part of the same management workflow. Add Sanctum `HasApiTokens` to User during implementation.

### 4B. Column definitions and constraints

Notation: `id` = auto-increment unsigned BIGINT primary key; FK columns use unsigned BIGINT. Fields are NOT NULL unless marked nullable. `timestamps` = `created_at`, `updated_at`, UTC DATETIME, populated on every insert including bulk inserts. InnoDB/utf8mb4; use a documented case-insensitive collation for human names and normalized, consistently compared identifiers. Keep existing Laravel framework-table conventions where already generated.

Money: all `*_minor` fields are signed BIGINT with nonnegative validation/CHECK constraints on source amounts. Signed storage permits safe intermediate subtraction; outstanding is calculated, not a second writable column. Enforce practical upper bounds in Form Requests/services. All data uses one currency/scale; do not mix currencies in summaries. The proposed BHD scale is 3.

| Table | Required columns | Nullable columns / defaults | Constraints and indexes |
|---|---|---|---|
| `users` | id, name VARCHAR(150), email VARCHAR(255), password VARCHAR(255), role VARCHAR(20), is_active BOOLEAN, timestamps | email_verified_at DATETIME, remember_token VARCHAR(100); role=`clinic_user`, is_active=true | Unique email; role allowlist; server-controlled role/is_active |
| `branches` | id, code VARCHAR(20), name VARCHAR(150), city VARCHAR(100), timezone VARCHAR(64), is_active BOOLEAN, timestamps | is_active=true | Unique code; valid IANA timezone |
| `branch_user` | user_id FK users, branch_id FK branches, timestamps | None | Composite PK `(user_id, branch_id)`; reverse index `(branch_id, user_id)` |
| `doctors` | id, branch_id FK branches, name VARCHAR(150), specialty VARCHAR(100), is_active BOOLEAN, timestamps | is_active=true | Index branch_id; unique `(id, branch_id)` supports visit branch-consistency FK |
| `patients` | id, mrn VARCHAR(32), cpr VARCHAR(32), name VARCHAR(150), phone VARCHAR(32), dob DATE, gender VARCHAR(20), timestamps | None | Unique mrn; unique cpr; phone index; `(name, id)` index; DOB not in future |
| `visits` | id, patient_id FK patients, doctor_id, branch_id FK branches, visit_date DATETIME, status VARCHAR(20), timestamps | created_by FK users nullable for bulk synthetic records | Composite FK `(doctor_id, branch_id)` to doctors; patient/branch FKs; query indexes listed above; FK index `(doctor_id, branch_id)` |
| `invoices` | id, visit_id FK visits, billing_date DATE, currency CHAR(3), total_minor BIGINT, discount_minor BIGINT, vat_rate_bps UNSIGNED SMALLINT, vat_minor BIGINT, net_minor BIGINT, status VARCHAR(20), timestamps | discount_minor=0, vat_minor=0; amounts/rate/status explicitly derived by writer | Unique visit_id; `(billing_date, id)`; discount<=total; net=total-discount+VAT |
| `invoice_items` | id, invoice_id FK invoices, service_code VARCHAR(40), qty UNSIGNED INT, unit_price_minor BIGINT, timestamps | None | invoice_id index; qty>0; unit_price>=0; bounded item count per invoice |
| `payments` | id, invoice_id FK invoices, method VARCHAR(20), amount_minor BIGINT, paid_at DATETIME, timestamps | reference VARCHAR(100) nullable | invoice_id index; amount>0; method allowlist; sum(payments)<=invoice.net under invoice lock |

- `cpr` is a normalized national-ID string, not an integer; use synthetic values for the assessment. Do not assume every country has a nine-digit national ID. Phone normalization uses a documented country-code rule. Shared phone numbers are allowed.
- Gender values: `male`, `female`, `other`, `unknown`; visit status: `scheduled`, `completed`, `cancelled`; invoice status: `unpaid`, `partially_paid`, `paid`, `void`; payment method: `cash`, `card`, `insurance`. Validate allowlists through PHP enums/Form Requests and explicit DB CHECK constraints where appropriate.
- Every created visit receives one invoice in the same transaction; unique `visit_id` enforces at most one. The database alone cannot enforce that every visit has an invoice, so test transactional creation and seed reconciliation.
- Invoice numbers are not required: expose invoice ID initially. Item totals are computed from integer qty/unit price; do not store duplicate mutable line totals.
- `billing_date` is set from visit time in the configured reporting timezone and remains stable. Branch timezone is descriptive and used for local display, not to silently change the reporting basis.
- A cancelled visit has a void invoice under the initial assumption. No payment on a void invoice. Refunds, write-offs, overpayments and billing edits are outside initial endpoints; they require an explicit later accounting design.
- Zero-net non-void invoices are `paid`. Otherwise status derives from allocated payment totals. Any future payment API locks the invoice and updates summaries in the same transaction; no payment-create endpoint is required now.
- Branches/doctors/users deactivate instead of deleting; existing history remains readable. Reject new visits for inactive branches/doctors. Source clinical/billing FKs use RESTRICT on delete. No automatic cascade deleting visits, invoices, items or payments. Avoid soft-delete columns on the large source tables initially.
- `created_by` on visits records the authenticated creator; invoices/items inherit attribution through the visit. Do not duplicate patient_id/branch_id/doctor_id on invoices: reach them through visit. Summary branch_id is deliberate denormalization.

### 4C. Summary and operational columns

These are supporting tables in addition to the seven required business tables. Create job-related tables only when implementing that feature.

| Table | Columns | Keys / behavior |
|---|---|---|
| `daily_branch_revenue` | billing_date DATE, branch_id FK, invoice_count UNSIGNED BIGINT, gross_minor/discount_minor/vat_minor/net_minor/paid_minor BIGINT, updated_at DATETIME | Composite PK `(billing_date, branch_id)`; `(branch_id, billing_date)` for scoped reports; zero defaults on counters; outstanding=net-paid |
| `daily_doctor_revenue` | billing_date DATE, doctor_id FK, branch_id FK, same count/money fields as branch summary, updated_at DATETIME | Composite PK `(billing_date, doctor_id)`; `(branch_id, billing_date, doctor_id)`; consistent doctor/branch pair |
| `patient_year_totals` | year UNSIGNED SMALLINT, patient_id FK, invoice_count UNSIGNED BIGINT, net_minor BIGINT, updated_at DATETIME | Composite PK `(year, patient_id)`; `(year, net_minor DESC, patient_id)`; global-admin reporting only |
| `idempotency_keys` | id, user_id FK, endpoint VARCHAR(80), key_hash CHAR(64), request_hash CHAR(64), response_status UNSIGNED SMALLINT, response_body JSON, timestamps | Unique `(user_id, endpoint, key_hash)`; response fields nullable only while transaction is open; failed transaction rolls back reservation; no medical identifiers in response snapshot |
| `seed_runs` | id, run_key VARCHAR(64), config JSON, config_hash CHAR(64), phase VARCHAR(30), last_committed_id UNSIGNED BIGINT, row_counts JSON, metrics JSON, status VARCHAR(20), started_at DATETIME, timestamps | Unique run_key; completed_at DATETIME and error_code VARCHAR(80) nullable; configuration includes generator version/random seed/frozen five-year range/counts; metrics include active_elapsed_ms, phase timings and peak_memory_bytes; checkpoint/counters/metrics updated in batch transaction |
| `exports` | id, user_id FK, filters JSON, branch_scope JSON, status VARCHAR(20), rows_processed UNSIGNED BIGINT, max_visit_id UNSIGNED BIGINT, disk VARCHAR(20), object_key VARCHAR(512), timestamps | Nullable last_visit_date DATETIME, last_visit_id UNSIGNED BIGINT, multipart_upload_id TEXT, started_at/completed_at/heartbeat_at DATETIME, lease_token CHAR(36), lease_expires_at DATETIME, error_code VARCHAR(80); unique object_key; index `(user_id, created_at)`, `(status, updated_at)` |
| `export_parts` | id, export_id FK, part_number UNSIGNED INT, etag VARCHAR(128), byte_count UNSIGNED BIGINT, row_count UNSIGNED INT, end_visit_date DATETIME, end_visit_id UNSIGNED BIGINT, created_at DATETIME | Unique `(export_id, part_number)`; checkpoint and part ledger commit together after S3 upload; record first/start cursor when needed for deterministic replay |
| `imports` | id, user_id FK, disk VARCHAR(20), object_key VARCHAR(512), source_bytes UNSIGNED BIGINT, source_etag VARCHAR(128), status VARCHAR(20), next_byte_offset UNSIGNED BIGINT, processed_rows/valid_rows/invalid_rows UNSIGNED BIGINT, timestamps | Nullable source_version_id TEXT, source_checksum VARCHAR(128), error_object_key VARCHAR(512), started_at/completed_at/heartbeat_at DATETIME, lease_token CHAR(36), lease_expires_at DATETIME, error_code VARCHAR(80); index `(user_id, created_at)`, `(status, updated_at)` |
| `import_chunks` | id, import_id FK, chunk_number UNSIGNED INT, start_byte/end_byte UNSIGNED BIGINT, first_row UNSIGNED BIGINT, row_count/valid_rows/invalid_rows UNSIGNED INT, created_at DATETIME | Unique `(import_id, chunk_number)`; error_object_key VARCHAR(512) nullable; committed row means patient changes and progress committed together |

- Summary amounts are nonnegative committed totals, initially zero. Exclude void invoices; apply deltas only once. The canonical source remains invoices/items/payments. Summary rows can be rebuilt and have RESTRICT FKs to their dimensions.
- Operational job status values are `queued`, `processing`, `done`, `failed`. Counters default to zero. S3 keys store paths, not presigned URLs; generate URLs on authorized retrieval. JSON stores only bounded configuration/filters, never CSV rows or whole datasets.
- Lease token/expiry identify the current worker; checkpoint updates require matching ownership. Serialize imports with one shared DB-backed lock in addition to per-import leases. Seed command also holds a shared lock so a second run cannot overlap the same target database.
- A seed configuration change requires a different empty target/run, not resetting the checkpoint. Final exact source counts and relationships are checked before marking done.
- Retain Laravel's generated `personal_access_tokens` schema: id, tokenable_type/id, name, unique token hash, nullable abilities/last_used_at/expires_at, timestamps. Add token abilities only as a restriction on role permissions, never a privilege grant. Sanctum's polymorphic token ownership has no normal users FK; deactivate/revoke explicitly.
- Keep existing `cache`, `cache_locks`, `failed_jobs` and `job_batches` support tables if used. Database `jobs` can remain unused with SQS. Use the existing `sessions` table for the additional Blade dashboard; keep the assessment API token-authenticated. Public registration/password-reset screens remain outside the initial scope.
- Job chunk FKs may cascade only when explicitly purging expired operational jobs; clinical data never cascades. S3 cleanup is a separate retryable operation. User references on jobs/idempotency use RESTRICT; deactivate accounts instead of deleting them.

### 4D. Relationships and creation order

```mermaid
erDiagram
    USERS ||--o{ BRANCH_USER : assigned
    BRANCHES ||--o{ BRANCH_USER : includes
    BRANCHES ||--o{ DOCTORS : employs
    BRANCHES ||--o{ VISITS : hosts
    PATIENTS ||--o{ VISITS : attends
    DOCTORS ||--o{ VISITS : treats
    USERS o|--o{ VISITS : creates
    VISITS ||--|| INVOICES : billed_by
    INVOICES ||--|{ INVOICE_ITEMS : contains
    INVOICES ||--o{ PAYMENTS : receives
    USERS ||--o{ EXPORTS : requests
    EXPORTS ||--o{ EXPORT_PARTS : uploads
    USERS ||--o{ IMPORTS : requests
    IMPORTS ||--o{ IMPORT_CHUNKS : processes
    USERS ||--o{ IDEMPOTENCY_KEYS : owns
    BRANCHES ||--o{ DAILY_BRANCH_REVENUE : summarizes
    DOCTORS ||--o{ DAILY_DOCTOR_REVENUE : summarizes
    BRANCHES ||--o{ DAILY_DOCTOR_REVENUE : scopes
    PATIENTS ||--o{ PATIENT_YEAR_TOTALS : summarizes
```

The diagram expresses business invariants; one invoice per visit and at least one item are enforced by transactional application code as well as the relevant unique/FK constraints. A patient has many visits across the five-year period, one invoice per visit, and zero or more payments per invoice. Thus one patient can have many invoices and many payments, including multiple partial payments on one invoice. Payments belong to an invoice, not directly to the patient; resolve patient ownership through invoice -> visit -> patient and prevent mismatched ownership.

| Model | Planned relationship methods |
|---|---|
| User | `branches(): BelongsToMany`, `createdVisits(): HasMany`, `imports(): HasMany`, `exports(): HasMany`; Sanctum `tokens()` |
| Branch | `users(): BelongsToMany`, `doctors(): HasMany`, `visits(): HasMany` |
| Doctor | `branch(): BelongsTo`, `visits(): HasMany` |
| Patient | `visits(): HasMany`, `invoices(): HasManyThrough` through visits, `yearTotals(): HasMany`; patient payments retrieved through invoice/visit joins in the service |
| Visit | `patient(): BelongsTo`, `doctor(): BelongsTo`, `branch(): BelongsTo`, `creator(): BelongsTo`, `invoice(): HasOne` |
| Invoice | `visit(): BelongsTo`, `items(): HasMany`, `payments(): HasMany` |
| InvoiceItem / Payment | `invoice(): BelongsTo` |
| Import / Export | `user(): BelongsTo`, `chunks(): HasMany` / `parts(): HasMany` respectively |

Use explicit `created_by` foreign key for `Visit::creator`. Composite primary-key summary/pivot tables can use Query Builder for writes; do not invent unsupported composite-key Eloquent behavior. No patient-branch ownership FK: patients are group-wide, visits establish clinical branch access.

Prepare migrations in dependency order: (1) users adjustments + branches, (2) branch_user + doctors + patients, (3) visits, (4) invoices, (5) items + payments, (6) summaries, (7) idempotency + seed progress, (8) import/export records then chunk tables. Existing framework/token migrations remain. Review generated SQL/types/indexes before you manually run migrations; no schema or seed execution by the agent.

## 5. Build and benchmark the seed command early

Implement `data:seed --patients=1000000 --visits=5000000`, with optional documented batch size/run identifier.

- Deterministic synthetic data covering the last FIVE YEARS up to the start of the seed run. Capture `range_end=now()` once and `range_start=range_end minus five calendar years`, store both in `seed_runs.config`, and reuse them unchanged on resume. Example for a run started on 19 November 2026: 19 November 2021 through the captured time on 19 November 2026. Never recalculate the range during individual batches.
- Spread visits/invoices/payments and their applicable business timestamps across all years/months in that window, through the current cutoff; no future transactions. Parent registration/creation dates precede dependent visits; invoice/item creation follows the visit; payments follow the invoice and never exceed the cutoff. Keep `updated_at >= created_at`. DOB represents a realistic birth date and can precede the five-year window; it must precede the patient's visits. This avoids incorrectly making every patient five years old or younger.
- Allocate visits so most patients have 2–5 and a small documented tail has 50+; verify the distribution and exact requested total.
- One invoice and three items per visit. Generate at least 4,000,000 payment rows for the default 5,000,000 visits, distributed over eligible invoices with zero, one or multiple payments. For scaled trials, target `floor(visits * 0.8)` payment rows; this is a row count, not an assumption that exactly 80% of invoices have one payment. Include unpaid, partially paid and fully paid invoices without overpayment. Distribute repeat patients' visits, invoices and payments across different months/years; totals, ownership and statuses must agree.
- Stream bounded batches, initially 1,000–5,000 rows. Generate IDs without loading all patients/visits. Avoid per-record factories/events and query logging.
- Console progress is required on local and server runs: current table/phase, committed rows versus target, percentage, total committed rows, elapsed time, rows/second and estimated remaining time. Refresh once per batch or at a bounded time interval, not once per row. Show resumed committed counts immediately; rolled-back/retried rows must not inflate progress.
- On completion print and persist a summary: rows per table, combined business rows, date range, total duration in seconds and HH:MM:SS, phase durations, peak memory, and whether the full run was below 1,800 seconds. Report success only after required counts, ownership relationships and monetary totals reconcile. Failure/interruption reports the last committed checkpoint and elapsed duration; resume does not label existing rows as newly inserted.
- Store cumulative active elapsed milliseconds/phase timings in the seed-run metrics and retain first-start/completion times. Report active execution time separately from end-to-end wall time on resumed runs. The under-30-minute assessment result must come from a fresh uninterrupted full run, including generation, inserts, required indexes/summaries and final validation; do not claim compliance by excluding slow phases or pauses.
- Save a seed-run configuration and next committed batch. Commit each batch and its checkpoint together. A rerun resumes the same configuration or reports completion; reject changed parameters and overlapping runs. Never truncate an existing populated database.
- Parent rows precede child rows; reconcile all FK relationships. Use a dedicated empty synthetic-data database/run so seed IDs cannot overwrite application data.
- First benchmark Query Builder multi-row inserts. If throughput is inadequate, implement a dedicated CLI-only `LOAD DATA LOCAL INFILE` path using bounded generated files and validated staging data; include staging/index/summary work in measurements. RDS supports this approach, subject to client/server configuration. [AWS import guidance](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/mysql-importing-data-any-source.html)
- Target is roughly 16,667 business rows/second for 30 million rows in 30 minutes. This is a benchmark target, not a promise. Keep backups/durability enabled; document hardware, storage/IOPS, CPU credits, peak memory, generation/load/index/summary times, and full elapsed time.
- Run small, medium, then full-volume trials manually. Record exact committed row counts separately from InnoDB `information_schema` estimates; record data/index MB from `information_schema`.

## 6. Implement the four read endpoints

| Endpoint | Implementation | p95 target |
|---|---|---:|
| GET /api/patients?search= | Indexed exact identifiers/name prefix; bounded results; escape LIKE wildcards; EXPLAIN the combined predicates and split into indexed candidate queries if needed | <200 ms |
| GET /api/patients/{id}/visits | Authorization scope, cursor pagination; return visit date/status, doctor, branch, invoice ID and invoice total explicitly; eager-load selected relations | <150 ms |
| GET /api/visits | Shared VisitService filter builder, validated optional filters, stable `(visit_date DESC, id DESC)` cursor order; default 50/max 100 | <300 ms |
| GET /api/invoices/{id} | InvoiceResource with bounded related items/payments loaded explicitly; authorization and query-count check | <100 ms |

Use direct timestamp ranges, not functions wrapped around indexed date columns. Paginate before expensive related loading. Do not calculate total-count queries for every cursor page. Validate malformed cursors and prohibit unsupported sort columns. Deep-page measurement uses a cursor at equivalent depth, with cursor discovery measured separately. [Laravel cursor pagination](https://laravel.com/framework/docs/13.x/pagination)

## 7. Implement transactional visit creation

`POST /api/visits` → StoreVisitRequest → VisitService → VisitResource.

- Validate patient, doctor/branch membership, permissions, status, date, item limits, service codes, prices and discount.
- Require bounded `Idempotency-Key`; unique scope `(user_id, endpoint, key_hash)` and normalized validated-payload hash.
- Within one DB transaction: reserve key, create visit/invoice/items, update affected summaries, and save the stable response/status.
- Same key + same payload replays the original result; same key + changed payload returns 409. Concurrent identical requests must create exactly one visit/invoice. Rollback must leave no orphan records or summary increments.
- Resolve duplicate-key races from committed state after rollback; retry transient deadlocks only within a bounded policy. Do not implement idempotency as an unlocked check-then-insert.
- Define key retention/replay behavior explicitly; retain keys throughout the assessment.

## 8. Implement the two reports

Use the assignment's exact routes and parameter names:

| Method and route | Required query parameters | Response / p95 target |
|---|---|---|
| `GET /api/reports/revenue` | `from=YYYY-MM-DD`, `to=YYYY-MM-DD`, `group_by=branch` or `doctor` or `month` | Gross, discount, VAT, net, paid, outstanding per group; <1 s for any one-year range |
| `GET /api/reports/top-patients` | `year=YYYY` | Top 100 patients by net billing, stable tie order; <1 s |

Document these exact paths in OpenAPI/Postman and README; test them as HTTP endpoints. Revenue example: `/api/reports/revenue?from=2025-07-01&to=2026-06-30&group_by=branch`. Top-patients example: `/api/reports/top-patients?year=2026`. Partial current-year results stop at available data; do not generate future records to fill a year.

- Revenue by branch/month reads daily branch summaries; by doctor reads daily doctor summaries. Include gross, discount, VAT, net, paid and outstanding, plus reporting basis/as-of time.
- Top patients reads indexed annual totals, then fetches names for only the selected 100 IDs; deterministic ties by patient ID.
- For this small write API, update summaries synchronously inside the billing transaction. This avoids scheduler lag in normal writes. Any future payment/status writer must use the same summary-update service.
- Bulk seed initializes/reconciles summaries before success. Provide a manual, resumable rebuild that builds replacement summaries without exposing half-built results; document whether writes must pause during rebuild.
- Do not join raw invoice_items and payments together before summing: multiple child rows multiply amounts.
- Both targets are <1 second. Reconcile summaries against source records on fixtures and representative full-data ranges. Caching is an optional measured optimization, not a substitute for correct summaries.

## 9. Implement resumable export/import

S3 purpose:

- Store uploaded patient CSV files for background import.
- Store generated visit CSV exports, including files with 2M+ rows.
- Store downloadable import error reports.
- Keep files private; grant short-lived upload/download access through presigned URLs.
- Keep source/result files available independently of a web request, worker restart, or application release. Job progress and billing/patient records remain in the database; SQS delivers background jobs.
- S3 is object storage, not the application database. Start core API development without AWS credentials; use Laravel storage fakes for tests and connect a real private bucket when implementing the AWS transfer flow.

Export:

- `POST /api/exports/visits` validates the same filters as VisitService, saves the authorized scope, returns 202, then dispatches after commit.
- `GET /api/exports/{id}` enforces ownership/permission and returns queued/processing/done/failed plus an expiring download URL when done.
- Chunk by stable visit cursor with a persisted maximum visit ID; newer visits are excluded. Document that related mutable values reflect processing time, not a database-wide historical snapshot.
- Stream CSV to bounded multipart S3 parts; persist upload ID, part number/ETag and next cursor. Retry replaces the same part rather than appending rows twice. Reconcile completion after a crash using the deterministic object key and S3 metadata.
- Respect S3 minimum part sizes and part-count limits; tune by bytes, not only row count. Keep a single final CSV with one header. [S3 multipart limits](https://docs.aws.amazon.com/AmazonS3/latest/userguide/qfacts.html?trk=public_post_comment-text)

Import:

- Add an authenticated upload-URL endpoint for a server-generated owner-scoped S3 key; then `POST /api/imports/patients` accepts that completed object reference and returns 202. Add `GET /api/imports/{id}` for progress/error download.
- Verify ownership, object size/type, immutable version/checksum and required CSV headers. Reject arbitrary URLs/foreign object keys. Cap at 500,000 rows and document maximum bytes/field lengths.
- Parse uncompressed UTF-8 CSV with streaming CSV functions, including quoted multiline fields. Checkpoint at complete record boundaries; use S3 byte ranges to resume an immutable source.
- Validate in bounded batches, upsert strictly by MRN, preserve existing patient IDs, and treat CPR collisions with another MRN as row errors. Do not let MariaDB/MySQL upsert accidentally update a different unique-key match.
- Process each import sequentially; last valid duplicate MRN within that file wins. Serialize patient imports initially to make conflicts predictable.
- Commit patient changes and chunk progress together. Store error chunks under deterministic private S3 keys before committing their references; retry overwrites them. Produce a downloadable CSV of row number and safe error reason, with sensitive values masked.

Both flows: bounded job duration, persisted progress, overlap protection, safe retries/backoff, failure status, and cleanup of abandoned multipart uploads. Sanitize spreadsheet-formula cells in downloadable CSV. Never put CSV contents in queue payloads. A scheduler recovers queued/stale records if dispatch or a worker fails. Dispatch-after-commit alone is not atomic delivery; recovery closes that gap. Set worker timeout below SQS visibility timeout. [Laravel queue guidance](https://laravel.com/framework/docs/13.x/queues)

### 9A. Application security and response headers

User-requested security requirements apply to the API and added dashboard. Headers supplement validation, escaping and authorization; a middleware that strips tags from every request is not an XSS solution and would corrupt legitimate data.

- Add `SecurityHeaders` middleware and register it centrally. Ensure policies also cover authentication failures and rendered 403/404/422/500 responses; verify error paths rather than assuming a normal response middleware catches every exception.
- Production HTML CSP baseline: `default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'`. Self-host CSS/JS; avoid inline handlers, `eval`, external fonts and broad wildcards. If browser uploads directly to S3, permit only the configured bucket origin in `connect-src` on the relevant pages. Permit local Vite/HMR origins only in development. [CSP reference](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy)
- Set `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`, and `Permissions-Policy: camera=(), microphone=(), geolocation=()`. Use `Cache-Control: no-store` on sensitive HTML/JSON/download-URL responses; static versioned assets may remain cacheable.
- Enable HSTS only on the HTTPS deployment after HTTPS works; do not force HSTS for local HTTP. Keep production/dev header settings in configuration.
- Do not use legacy `X-XSS-Protection: 1; mode=block` as the defense; leave it absent or explicitly disabled. Use contextual output escaping and CSP instead. [Deprecated XSS filter guidance](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/X-XSS-Protection?region=united-states)
- Blade displays untrusted data with escaped `{{ }}` output. No raw `{!! !!}` for patient names, imported values or errors. JavaScript uses `textContent` for untrusted strings. JSON responses use the correct content type; CSV exports neutralize formula-like cells. Do not inject JSON/CSV values into HTML unsafely.
- Authentication: rate-limited login with generic credential errors, session regeneration after login, session invalidation and CSRF-token regeneration at logout, HttpOnly/SameSite cookies and Secure cookies under HTTPS. Every state-changing dashboard request has CSRF protection. Bind SQL values and allowlist report grouping/sorting.
- Authorization: active-user middleware plus Policies and branch-scoped queries. Hiding a dashboard button is not authorization; direct requests must enforce the same rules. Validate identifiers, file ownership and roles on both web and API paths. Restrict CORS to intended origins if needed; no permissive credentialed wildcard.
- Hide PHP version disclosure using `expose_php=Off` in the active web PHP configuration and remove `X-Powered-By` at the response/web-server layer. Middleware alone may not remove a header added outside Laravel. Verify actual HTTP headers after the configuration is applied. Also suppress detailed web-server version banners where configurable; this does not make the technology impossible to identify. [PHP configuration reference](https://www.php.net/manual/en/ini.core.php)
- Use clean Laravel routes, never public `.php` feature URLs. Web document root must be `public`; no exposed `.env`, vendor/source files, database files, phpinfo/debug pages or exception traces. Production `APP_DEBUG=false`; return safe messages and log redacted diagnostics privately. Server configuration remains a later manual/deployment task; no host configuration is changed during planning.

### 9B. Minimal clinic dashboard — user-requested addition

This is additional scope requested by the user, not a replacement for any assessment API endpoint. Build after the schema/core services are available; do not present a static mockup as a completed working dashboard.

- Use simple Blade layouts/components, a small locally built CSS file and minimal JavaScript. Reuse business services, Form Requests and Policies from the API. No React/Vue, paid admin template, new component framework or unnecessary chart package.
- Visual direction: quiet clinical workspace, precise table alignment, soft gray-green background `#F3F5F4`, surfaces `#FAFBFA`, border `#DDE4DF`, dark text `#26352E`, muted secondary text `#5B6B62`, restrained accent `#536E66`. Human-readable system sans-serif, 14–16px body text, compact 8–12px corners, subtle borders, little or no shadow. User preference for soft colors takes priority over any skill's bright accent palette. Check text/control contrast; soft backgrounds must not mean faint text.
- Layout: small sidebar, compact top bar with authorized branch selector and account menu, page title/date filters, limited summary cards, and one primary table. Responsive sidebar and horizontally scrollable tables; visible keyboard focus, labels and useful empty/loading/error states. No gradients, neon colors, large decorative illustrations or fabricated statistics.
- Authentication screens: minimal sign-in and POST logout. No public registration. Same User records/roles as the API; the dashboard uses secure sessions without storing bearer tokens in browser local storage or rendering them into pages. The required `/api/*` routes continue to require bearer tokens; Blade controllers call shared services directly.
- Main navigation: Overview, Patients, Visits, Invoices; Revenue and Exports for admins/clinic admins; Patient Imports for system admin only. No account-management screens initially.
- Overview: selected branch/date range, recent visits, and visit counts. Admin/clinic-admin cards may show net billing, paid and outstanding from the existing summaries. Clinic users do not receive aggregate financial/report data they are forbidden to access, even if the UI would hide it.
- Patients: MRN/name search and masked identifiers; selected patient's chronological visit history with doctor, branch, invoice total, and invoice detail/payments. Apply branch rules to every visit/invoice; never aggregate another branch's history into a clinic user's view.
- Visits: same branch/doctor/status/date filters as `/api/visits`, cursor navigation, and a small create-visit form with invoice items, integer-safe amounts and idempotency support. Reuse the same transactional service; do not duplicate billing logic in Blade/controllers.
- Invoices: invoice details reached from visit/patient history, with items, payments and outstanding. No new global invoice-list API or payment-entry feature is needed for this dashboard.
- Revenue: exact report grouping/date/year contracts from section 8; show a simple result table first. Top-patients visible only to system admin. Use the existing summaries rather than scanning all raw invoices for cards.
- Transfers: eligible users submit exports and view progress/downloads; system admins upload patient CSVs and retrieve validation reports. Use authorized temporary links and existing job-status records. Display real statuses only; an unavailable data source shows an explicit empty/error state rather than placeholder totals.
- Initial web routes: `GET/POST /login`, `POST /logout`, `GET /dashboard`, `/dashboard/patients`, `/dashboard/patients/{patient}/visits`, `/dashboard/visits`, `/dashboard/visits/create`, `/dashboard/invoices/{invoice}`, `/dashboard/reports/revenue`, `/dashboard/reports/top-patients`, `/dashboard/exports`, `/dashboard/imports`. Add only the POST actions needed by those screens; name routes and protect them with web auth, active-user checks and Policies. Use the existing sessions table, without automatically running its migration.
- Completion checks: sign-in/out, role/branch restrictions, actual DB-backed lists/forms, error handling, narrow/mobile layout, keyboard access, XSS payload rendered as text, CSP compatibility and no PHP/version header disclosure. Do not mark the dashboard built until these flows work.

## 10. Tests and performance evidence

Write tests with implementation, not at the end. Required feature suites:

- Visits: each filter and combinations; invalid dates/status; authorization; equal timestamps; cursor boundaries without duplicates; bounded page size.
- Revenue: exact amounts, partial/unpaid/void invoices, multiple payments/items, all three groupings, date boundaries, leap years, empty results, permissions and summary/source reconciliation.
- Seed verification: five-year coverage through frozen cutoff, plausible DOB/parent dates, repeated patient histories, multiple payments per invoice, committed-only progress, persisted completion timing and restart accuracy. Check monthly/yearly distribution without loading all rows into PHP.
- Security/dashboard verification: guest/disabled-user rejection, session login/logout and CSRF, branch isolation, role-restricted cards/actions, escaped imported text, response headers on success/errors, and clean PHP-header output on actual HTTP responses. Do not execute database-migrating tests without the user's manual database setup.
- Create visit: 201 success, integer rounding, invalid foreign relationships, rollback, replay, payload conflict, concurrent duplicate requests and exactly-once summary effects.
- Add focused import/export retry tests: crash after DB/S3 writes, duplicate deliveries, ownership, CPR conflicts, malformed CSV, formula injection and worker restart.
- PHP 8.4 and 8.5 CI matrix; isolated MariaDB integration database matching the development server. PHPUnit for behavior; Pint PSR-12 for formatting. Verify the chosen AWS database separately at deployment time; local measurements do not establish performance on a different server/engine.
- Tests using RefreshDatabase run migrations internally. Do not execute them under the current no-migration instruction; provide the commands for manual execution against a dedicated test database.
- Benchmark on the full required dataset and documented AWS setup, with concurrency, request counts, warm/cold cache, p50/p95, failures and query counts. Include selective/nonselective filters, equivalent page 10,000, one-year reports and a 2M+ export.
- README must contain real EXPLAIN/EXPLAIN ANALYZE evidence for endpoints 1, 3 and 5. Record seed time, memory and DB sizes. Leave results marked unmeasured until actually collected.

## 11. AWS delivery plan

- Ubuntu 24.04 EC2, Nginx, PHP-FPM, Supervisor, cron; same tested PHP release as local/CI.
- Select the RDS engine/version during deployment planning; the PDF requests MySQL 8, while current development uses MariaDB by user choice. This does not block local implementation. Keep RDS in private subnets with no public access; port 3306 accepts only the app security group. Backups on, TLS, encryption, and a documented custom parameter group. Use the assessment's t3.medium/db.t3.medium for seed timing and record the actual engine/version.
- SSH restricted to your IP; HTTPS with a domain/subdomain and valid certificate. Nginx root points to `public`; production debug disabled.
- Private S3 with Block Public Access, encryption and lifecycle cleanup; presigned transfers only. SQS with retry/dead-letter handling. EC2 IAM role restricted to required bucket prefixes/queues; no AWS access keys in `.env`.
- CloudWatch receives redacted Nginx/Laravel logs, with retention and at least one alarm. Track queue failures/age and RDS storage/CPU/IOPS.
- Repeatable release-directory deploy: install locked production dependencies, cache config/routes, switch symlink, gracefully restart workers, health check, rollback symlink if needed. Database changes remain an explicitly documented manual step, never hidden in the deploy script.
- Estimate cost using the selected region and actual EC2/RDS/storage/backups/S3/SQS/logs/IP usage before provisioning. Do not assume 30M rows fit free-tier allowances. Docker, replicas, ElastiCache, IaC and load balancers are optional extras, not initial scope.

## 12. Execution order and manual checkpoints

| Day | Work | Exit condition |
|---|---|---|
| 1 | Use existing Laravel/MariaDB setup; Git/Boost/API setup; contract/schema files; seed prototype | Schema reviewed; small benchmark strategy ready |
| 2 | Seed correctness/throughput tuning; four read endpoints; transaction/idempotency | Required volumes achievable; core feature tests written |
| 3 | Reports and summaries; import/export with retry checkpoints; security middleware | Accounting reconciles; restart/security tests written |
| 4 | AWS setup/deploy; manual full seed; performance measurements/tuning | Live HTTPS API; measured targets and restart checks |
| 5 | Remaining fixes; README/OpenAPI; answers; submission rehearsal | Evidence, meaningful Git history, reviewer token and URLs ready |

The PDF estimates 12–16 hours. Full-volume tuning and AWS provisioning are the largest uncertainties; protect the final day and prioritize mandatory features. Bootstrap is done, but the domain implementation has not started.

The requested dashboard adds work beyond the PDF's API-only scope. Build a minimal functional interface after core services, without delaying full-volume seeding/performance checks; revise the time estimate once the backend is ready. Do not silently drop required endpoints, retry guarantees or benchmark evidence to fit UI work.

Manual commands — only after the corresponding code exists and the intended database is verified:

```powershell
# Development tooling, before application implementation
composer require laravel/boost --dev
php artisan boost:install

# After migration files are reviewed; run manually
php artisan migrate

# Small trial on a separate empty synthetic-data database
php artisan data:seed --patients=1000 --visits=5000

# Full trial on its own empty synthetic-data database
php artisan data:seed --patients=1000000 --visits=5000000

# After .env.testing/phpunit.xml target an isolated MariaDB test database
# Feature tests may run migrations internally
php artisan test
vendor/bin/pint --test
```

Do not use `composer run setup`: its existing script executes migrations. Do not use `migrate:fresh`, `db:wipe`, or `db:seed` as shortcuts. Small/full seed runs above use different databases because seed parameters are immutable within a resumable run.

Final submission: GitHub repository, live HTTPS base URL, privately shared reviewer token, README with architecture/commands/measurements/index rationale/package justification/costs, OpenAPI or Postman, and five answers of at most about 150 words each. Answer topics: 200M-row evolution; deep OFFSET versus cursors; expand/backfill/validate/contract for a NOT NULL column; RDS query diagnosis; shared state/queues/scheduling for three EC2 instances. Do not publish tokens, SQL dumps, private assignment contents, or other-project source code.

**Next implementation step:** use the existing Laravel 13/MariaDB connection and installed packages; prepare schema and API skeleton files for review, including User token support and the roles defined in section 4A. Provide migration commands to you without executing them. No migrations or seeders may be run automatically.
