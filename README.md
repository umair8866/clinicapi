# Clinic Visits & Billing

Implementation is incremental. The assessment endpoints, Policies, Resources, request validation, summary reports, transactional visit creation, resumable synthetic seed command and queued import/export code are implemented. Full-volume benchmarks, S3 integration tests and AWS deployment remain pending. Apply the new idempotency/import/export migrations before using their endpoints.

## API contract

- One group-wide patient identity; MRN/CPR are unique normalized strings. Phone is not unique. Exact identifier search and literal name-prefix search are planned. Directory responses expose ID, MRN, name and masked identifiers only.
- Active `admin` users access all branches; `clinic_admin` and `clinic_user` access assigned branches. Patient directory access does not grant access to another branch's visits or invoices. Unauthorized record access returns 404; forbidden capabilities return 403.
- APIs require Sanctum bearer tokens. Token abilities restrict role permissions: `patients:read`, `visits:read`, `visits:write`, `invoices:read`, `reports:read`. `*` grants token abilities only, never additional branch access.
- Money uses integer minor units internally and decimal strings at the API boundary. Initial currency is BHD, scale 3. Gross is quantity times unit price; net is gross minus discount plus VAT. VAT is rounded half-up on the invoice's discounted subtotal using integer basis points. Services must enforce bounds before multiplication.
- Store instants in UTC. Initial reporting timezone is Asia/Bahrain; branch timezone controls display and schedules. Invoice billing date derives from visit time in the reporting timezone. Inclusive `to` becomes the next day's exclusive boundary; maximum range is one calendar year from `from`, clamping February 29 to February 28 when necessary.
- Revenue includes non-void invoices billed in the range. Paid means payments allocated to those invoices as of report generation. Top-patient totals combine branches and are admin-only. Cursor pagination supports next/previous navigation, not arbitrary page-number jumps.
- Visit creation requires an `Idempotency-Key` header of 16–128 letters, digits, underscores or hyphens. Successful keys are retained; changed payload with the same key returns 409. Visit, invoice, items, summaries and response snapshot commit together. Monetary input uses BHD decimal strings with exactly three digits after the decimal.

## Assessment API

Issue a private reviewer token for an active account: `php artisan reviewer:token admin@gmail.com --days=7`. Send it as `Authorization: Bearer TOKEN`. The command prints the token once; do not commit or share it publicly.

| Method | Path | Input |
|---|---|---|
| GET | `/api/patients?search=` | Exact MRN/CPR/phone or name prefix |
| GET | `/api/patients/{id}/visits` | `per_page`, `cursor` and optional visit filters |
| GET | `/api/visits` | `branch_id`, `doctor_id`, `status`, `from`, `to`, `per_page`, `cursor` |
| GET | `/api/invoices/{id}` | Invoice ID |
| GET | `/api/reports/revenue` | `from`, `to`, `group_by=branch|doctor|month`, optional `branch_id` |
| GET | `/api/reports/top-patients` | `year` (admin only) |
| POST | `/api/visits` | Patient/branch/doctor, UTC `visit_date`, status, discount, items and Idempotency-Key |
| POST | `/api/exports/visits` | Same filters as GET visits; clinic admin/admin |
| GET | `/api/exports/{id}` | Status, progress, five-minute download URL when ready |
| POST | `/api/imports/patients` | Multipart `file` CSV; admin only, 100 MiB and 500,000 rows max |
| GET | `/api/imports/{id}` | Status, progress, five-minute error-report URL when ready |

Sample patient import files: `GET /samples/patients-import/csv` downloads the CSV accepted by `POST /api/imports/patients`; `GET /samples/patients-import/xlsx` downloads the same rows as an Excel sheet for reference. The import API accepts CSV only.

The small database HTTP tests are written in `tests/Feature/AssessmentApiTest.php` but await an isolated MariaDB test database. Performance targets remain unmeasured.

Transfers use the `s3` disk and queue worker. Configure a private S3 bucket and `QUEUE_CONNECTION=sqs` for AWS; the worker timeout is 3600 seconds, so the SQS visibility timeout must exceed it. Run workers under Supervisor and `php artisan schedule:run` every minute for queued/stalled transfer recovery. Export writes deterministic 1,000-row S3 parts, checkpoints each part, then assembles the CSV using a temporary stream. Import checkpoints every 500 CSV rows and persists safe error chunks on S3. The import CSV header is exactly `mrn,cpr,name,phone,dob,gender`. Temporary disk space must accommodate the full source/final CSV; memory remains bounded. Do not treat an untested full-volume export or import as a measured success.

## Verification and seeding status

`php artisan test --compact tests/Feature/AccessFoundationTest.php` runs without migrations or database writes. BranchIsolationTest uses RefreshDatabase: run it only after manually configuring an isolated MariaDB test database; the scaffold's current SQLite configuration is not suitable for these migrations. Database-backed verification remains pending.

The `data:seed` command commits each bounded batch and its checkpoint together. Visit batches include invoices, three items per invoice, payments and all summaries. A stopped or failed run resumes from the committed checkpoint; completed runs are no-ops. The named database lock is held on the writing session, with reconnect disabled during the run. A lost process/connection releases the lock after the server detects disconnect; an uncommitted transaction rolls back. Rerun after the old connection closes.

Use a dedicated, empty, offline synthetic-data database. Existing business/summary rows without a matching seed run are rejected. Do not accept application writes while seeding or validating. DatabaseSeeder intentionally creates nothing; factories serve small test fixtures only. No sample login account or credentials are created.

After manually selecting the target database and running the migrations:

```powershell
php artisan data:seed --patients=1000 --visits=5000 --batch=1000
# Optional deliberate stop after three committed batches:
php artisan data:seed --patients=1000 --visits=5000 --batch=1000 --max-batches=3
# Resume with identical patients/visits/batch/seed, omitting max-batches:
php artisan data:seed --patients=1000 --visits=5000 --batch=1000
```

For the required full run, use a different empty database and run `php artisan data:seed`. Defaults: 1,000,000 patients, 5,000,000 visits, 1,000 rows/visits per batch and deterministic seed 1729. Patient/visit counts, batch size, generator version and random seed are immutable within a run. The five-year UTC range is frozen on the first invocation. Visits must be at least twice the patient count. At default volumes, 1% of patients have 50+ visits; most have 4–5. Every fifth patient visits two branches. Four payments per five visits include two payments on some invoices, partial payment, unpaid and void invoices. A final partial group preserves exactly floor(visits * 0.8) payment rows.

Summary/validation work is included in measured wall time. Metrics persist phase durations, committed counts, memory, monthly distribution, patient history counts and information_schema sizes in bytes. Active timing has checkpoint precision; an abrupt kill cannot persist time spent in its rolled-back batch. Only a single-attempt run can report the under-30-minute flag. Final aggregate verification may take time after insertion reaches 100%. No throughput or database correctness claim is made until the real integration run.

Seven generator unit tests run without a database: `vendor/bin/phpunit tests/Unit/ClinicSeedGeneratorTest.php`. Five integration tests cover stop/resume, rollback after summary writes, changed parameters, nonempty targets and concurrent sessions. They are not executed by the agent. To run manually, configure an isolated MySQL/MariaDB test connection/database ending in `_test` or `_tests` in phpunit.xml (replacing the SQLite scaffold settings), then:

```powershell
$env:RUN_SEED_INTEGRATION_TESTS = '1'
php artisan test --compact tests/Feature/ClinicSeedResumeTest.php
```

These integration tests use RefreshDatabase and execute migrations. Never point them at development or production data. Actual worker-process termination and full-volume timing remain manual checks.

All performance targets remain unmeasured. Laravel 13/MariaDB is the user-approved local stack; the assessment specifies Laravel 11/12 and MySQL 8.

---

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
