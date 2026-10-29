# Phase 5.8 — v1.1.290 Laravel / Flutter compatibility

Status: **backend compatibility audit updated after Student Fee removal**.
Branch: `feature/phase58-prototype-v1290`.
Cleanup commit: `45542ad`; developer-confirmed test suite: **597 passed, 9,649 assertions**.

## Sources and boundaries

- UI prototype: `Probashi_RemitHisab_v1.1.290 (1).html`.
- Requirement delta: `ChatGPT_brief_v1.1.282-290.md`.
- Laravel is authoritative for persisted money, validation, access, quotas and calculations.
- Flutter owns native UI, device-only PIN, offline read-only cache, notifications and locally generated PDF if selected.
- Student Fees and TrxID are permanently excluded; do not rebuild removed features.
- Do not copy prototype localStorage or testing toggles to production.

## Current backend compatibility matrix

| Requirement | Backend evidence | Status |
|---|---|---|
| Free quotas (20 sender, 20 manager, 20 family, 10 customers) | `PlanLimits`, quota tests | Implemented |
| No Free edit quota | `EditQuotaEnforcementTest` | Implemented |
| Trial, referral and plan packages | Services and feature tests | Implemented; Flutter integration remains |
| Student Fees completely removed | Cleanup commit `45542ad`, migration `2026_10_24_000006` | Implemented; migration required on deployed databases |
| Student money received and legacy family-source identifiers | `StudentService`, `StudentApiTest` | Implemented |
| 16 currencies, validation limits and 4-decimal KRW/JPY rates | `Phase58CurrencyTest`, precision migration | Implemented |
| Worker historical rates, effective dates, salary currency | `Phase58WorkRatesTest`, rate migration | Implemented |
| Per-user settings and reminders | `AppSettingsController`, `Phase58AppSettingsTest` | Implemented |
| Limit=10, newest-first lists | `Phase58PaginationTest` | Implemented |
| Archive, unarchive, 10-minute new-profile deletion | `Phase58ProfileArchiveTest` | Implemented |
| New Manager remittances Bank-only, legacy methods readable | `Phase58BankOnlyRemittanceTest` | Implemented |
| Free monthly PDF; Unlimited custom date range and CSV | `PlanAllowanceService` entitlements | Flags aligned; export implementation pending |
| Monthly PDF creation | No PDF export endpoint in `ReportController` | Pending Flutter-local PDF or server endpoint |
| CSV creation (UTF-8 BOM, CRLF, injection-safe) | No CSV export endpoint in `ReportController` | Pending |
| Retry-safe saves and idempotency | `FinancialCreationIdempotencyTest` covers selected mutations | Partial; audit remaining mutation routes |
| Offline read-only, 48h cache lock, device PIN | Device responsibilities | Flutter Phase 6 |
| Currency-separated reports | `SenderReport`, `ManagerReport`, Student summary | Existing; verify generated exports separately |

## Export entitlement contract

`GET /api/v1/me` exposes:

- `plan.entitlements.basic_pdf = true` for every tier (backward compatibility).
- `plan.entitlements.monthly_pdf = true` for every tier, including Free.
- `plan.entitlements.custom_date_range = true` only for Unlimited.
- `plan.entitlements.csv_export = true` only for Unlimited.

These flags describe eligibility; they do not themselves create a file.
Existing report queries accept `from/to` for month-specific Flutter views. Do not block all Free `from/to` queries indiscriminately, because they can represent a permitted monthly report. Enforce premium access on a dedicated premium export workflow.

## Remaining work, in order

1. **CSV export**: authenticated sender and manager endpoints, Unlimited access check, explicit datasets/periods, BOM + CRLF, CSV formula escaping, private data boundaries, tests.
2. **Monthly PDF**: decide Flutter-local generation from server data versus authenticated backend PDF generation. Must be available on Free.
3. **Idempotency and timeout reconciliation**: inventory mutation endpoints, stable operation IDs, safe retries and destructive-action semantics.
4. **Flutter Phase 6**: native screens, online-only mutations, per-account cache, four-digit local PIN, reports and export UI, notification scheduling.

## Verification

After each batch, run focused tests and `php artisan test`. The 597-test baseline belongs to cleanup commit `45542ad`; new code is not verified until the developer runs tests.
