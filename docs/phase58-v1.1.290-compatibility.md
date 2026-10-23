# Phase 5.8 — v1.1.290 Laravel / Flutter compatibility

Status: **audit baseline**, not a claim that the implementation is complete.

## Source of truth and scope

- UI, screen flow, wording, conditional visibility: uploaded `Probashi_RemitHisab_v1.1.290 (1).html`.
- Requirement delta: uploaded `ChatGPT_brief_v1.1.282-290.md`.
- Laravel validates and persists all authoritative records, quotas, access control, and decimal-safe calculations.
- Flutter implements native UI, device-only PIN, read-only offline cache, and local notifications.
- Do **not** copy browser localStorage or prototype test toggles into production.
- Student Fees are removed from the product; preserve any legacy fee-linked data until a safe retirement/migration is verified.
- Branch created from `feature/phase5-app-compatibility` at `c0c59ce` (562 tests / 9,233 assertions last confirmed by user).

## Verified compatibility matrix

| v1.1.290 requirement | Current backend evidence | Status / action |
|---|---|---|
| Free 20 sender + 20 manager transactions; 20 family + 10 customers | `PlanLimits`, `PlanQuotaService` | Existing |
| No Free edit quota | `EditQuotaEnforcementTest` asserts unrestricted edits | Existing |
| Seven-day trial, referrals, plan packages | `TrialService`, `ReferralService`, `PlanPackages` | Existing; reverify UX and access rules |
| No Student Fees | `routes/api.php` exposes `/student/fees`; `StudentService` retains fee accounting | **Gap**: retire API and fee UI; keep legacy data until migrated |
| Student money received from home | `StudentMoneyIn`, `StudentController` | Partial: confirm legacy relation identifiers, new optional relation and naming |
| 16 currencies and currency-dependent max/rate precision | `SenderOptions::CURRENCIES` has 9; `MoneyField` allows 2 decimals; financial rate DB columns use decimal(10,2) | **Critical gap**: shared policy + safe rate schema migration + all server validators/calculators |
| Work historical hourly rates by effective date and currency | `WorkSetting` has a single hourly rate; `WorkService::periodPay` applies current settings to past days | **Critical gap**: dated rate ledger, salary currency snapshots, multi-currency report grouping |
| Persist language/mode/default currency/is_student/intro_seen/features/reminders per account | `UserResource` lacks settings; `Business.default_currency` is business-wide | **Gap**: user-private settings API + login and `/me` response |
| 10-at-a-time lists with totals and newest-first | `Paging` supports `per_page` and `page`, defaults 20; family sorts by name | **Partial**: add `limit=10` alias, family recent sort, preserve `per_page` |
| Archive people, keep history, lifetime cap, restore, 10-minute clean-delete exception | Soft delete/restore and withTrashed quota counting exist; no distinct archive field or profile refund exception | **Partial**: decide archive vs delete API semantics; add one-time 10-minute exception with tests |
| Manager remittances new records always Bank; old methods readable/editable | `RemittanceService` accepts all `ManagerOptions::REMIT_METHODS` on create | **Gap**: Bank default and Bank-only for new records, legacy editing preserved |
| Monthly PDF Free; custom dates and CSV Unlimited | `PlanAllowanceService`: basic_pdf true, monthly_pdf Unlimited; report APIs have no export endpoint | **Gap**: align entitlements; implement actual export or explicitly assign Flutter local PDF; gate CSV and custom periods on server |
| Every write idempotent; offline read-only; safe retry | UUID idempotency exists on many financial creations; `EditGuard` supports optimistic conflict; no general operation-status API verified | **Partial**: endpoint-by-endpoint contract, safe retries, account-specific cache |
| No TrxID/reference field for new remittances | Sender/Manager remittance tables do not include trx; ManagerRemittance has `method` for historical records | **Mostly existing**: Bank-only creation still needed |
| No TTS, commission default, text-size/font-preview settings | Not required by backend | Flutter only; do not implement |
| PIN exactly four digits, device only | Backend has no PIN endpoints | Flutter only; never transmit PIN |
| Report grouping by currency | `SenderReport`, `ManagerReport` already group some totals; worker reports do not support historical mixed currencies | **Partial**: verify each output, no cross-currency summing |

## Recommended implementation order

1. **API compatibility foundation**: `limit=10` alias, stable newest-first order, contract tests.
2. **Financial correctness**: currency matrix, 4-decimal rate storage for KRW/JPY, server validation and decimal calculations, tests for both modes and reports.
3. **Worker rate history**: effective dates, currency snapshots, salary immutable currency, grouped reports; data-preserving migration and tests.
4. **Account preferences and Student Fees retirement**: user-private settings; disable fee endpoints in new client; preserve legacy data and ensure summary excludes obsolete fee indicators.
5. **Archive and lifetime cap exceptions**, Manager bank-only new remittances, student source identifiers.
6. **Exports and subscription gating**, end-to-end idempotency and offline contract.
7. Native Flutter v1.1.290 screen integration after backend API contract passes.

## Non-destructive migration rule

Never rewrite previously saved amounts or drop student fee tables in the first migration. Existing paid-fee linked expenses, historical remittance methods, and old currency records must remain readable. Any irreversible cleanup requires explicit migration tests and confirmation.

## Verification

Each implementation batch must include focused tests and a complete `php artisan test` run by the developer. The prior verified checkpoint (562 passing) is not automatically a passing claim for this branch.
