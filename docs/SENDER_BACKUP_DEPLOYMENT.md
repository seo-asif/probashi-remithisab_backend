# Sender Backup & Restore — Deployment Readiness

Status: **development implementation complete; production deployment not yet verified**.

This document covers the API endpoints merged in PR #14. It does not deploy code, modify hosting, or authorize deletion of live records. Verify these items in a **staging** environment first.

## 1. Existing behavior (do not weaken without a separate design)

| Property | Implementation |
| --- | --- |
| Export | \`GET /api/v1/account/backup/sender\` |
| Preview | \`POST /api/v1/account/backup/sender/preview\` |
| Restore | \`POST /api/v1/account/backup/sender/restore\` |
| Authentication | Sanctum + current business context; owner-private model scopes |
| Rate limiting | Shared \`throttle:api\` middleware |
| Transport | JSON over authenticated HTTPS |
| Maximum *serialized JSON* | **5 MiB = 5,242,880 bytes**, both export and restore/preview |
| Restore strategy | **Merge only:** skip an existing UUID; never overwrite or undelete it |
| Concurrency | Transaction + business-row lock; tested with two separate MySQL processes |
| Quota | Each **newly imported** remittance consumes a **current-month** Sender slot, including historical and soft-deleted entries |
| Family quota | Soft-deleted family profiles continue counting toward the lifetime-created limit |
| Dates | Original event date (\`occurred_at\`) and soft-deletion state retained; newly restored row creation/update timestamps are new |
| Preview | Structural count only (\`restore_ready=false\`); does not promise that an import can succeed |

**Known limitation:** Genuine recovery of more than the remaining Free-plan monthly allowance can fail with HTTP 409. Do not exempt untrusted/self-editable JSON by changing the supplied event date or quota counters. A verified recovery mechanism requires a separate feature.

Backup files contain **unencrypted** personal and financial information. HTTPS protects data in transit, not an exported file saved or shared by the user.

## 2. Deployment environment

Required by this repository:

- PHP **8.5+** (see \`composer.json\`), with the Laravel and MySQL-required extensions, including \`bcmath\` and \`pdo_mysql\`.
- MySQL with deployed schema/migrations; do not run migrations or database-reset commands against a production database without backups and a rollout plan.
- Consistent application secrets and encryption keys across deployments. Use secret storage/environment variables, not Git.
- On production, set \`APP_ENV=production\`, \`APP_DEBUG=false\`, a correct HTTPS \`APP_URL\`, and working Sanctum authentication.
- Configure the queue/mail providers required by other backend flows. This backup feature itself does not need a background queue.

Inspect **the actual web-serving PHP configuration**, not only the CLI result (\`php -i\` on Windows/XAMPP or shell may load a different \`php.ini\` than PHP-FPM/Apache).

### HTTP body and PHP settings

The API itself refuses JSON payloads over **5 MiB** with \`BACKUP_TOO_LARGE\` (HTTP 413). The proxy/web server must pass requests **at least this large** to PHP; a modest higher infrastructure ceiling, for example **8 MiB**, permits Laravel to return its own standard JSON 413 for over-limit requests.

Example *staging* configuration, subject to the hosting topology:

\`\`\`nginx
# In the server/location block serving the Laravel API:
client_max_body_size 8m;
\`\`\`

\`\`\`ini
; In the PHP configuration used by the web worker, not just CLI:
post_max_size = 8M
memory_limit = 256M
\`\`\`

These are starting points, **not** proof that 256 MiB suffices for all possible datasets. Benchmark near the 5 MiB boundary in staging, then tune memory/worker limits and timeouts based on peak resource use. The app currently parses a bounded JSON body and validates records in memory, so payload record count and record shape matter as well as byte count.

For Apache, set an appropriate \`LimitRequestBody\` for the API virtual host/location if it is constrained; for other proxies/CDNs, check their documented request-size ceiling. Do **not** assume that changing Nginx or CLI \`php.ini\` changes a separate proxy or PHP-FPM pool. \`upload_max_filesize\` applies to PHP file uploads, whereas these endpoints accept JSON request bodies; \`post_max_size\` and upstream request-size limits remain relevant.

If an upstream proxy returns its own HTML 413, Android may not receive the app's normal JSON error. Test that behavior and ensure the Flutter UI handles gateway errors safely.

### Response size and privacy

The export service checks **serialized UTF-8 response bytes** against the same 5 MiB limit and sets \`Cache-Control: private, no-store\`, \`Pragma: no-cache\`, and \`X-Content-Type-Options: nosniff\`. Verify intermediate caches/CDNs respect \`no-store\`, and do not log JSON request bodies, exported responses, bearer tokens or user file contents.

Serve the application over HTTPS, never expose \`.env\` or private backup files from the web root, and avoid sending backup JSON to third-party analytics or crash reporters. There is no server-side encrypted backup archive in this feature.

## 3. Safe preflight (no database writes)

On the intended **staging** deployment, use its own credentials and configuration:

\`\`\`bash
php -v
php artisan about
php artisan route:list --path=api/v1/account/backup/sender
\`\`\`

Confirm the three authenticated routes are present, PHP and database services are healthy, and HTTPS requests reach the correct deployed API version. Avoid pasting \`php artisan about\` or server configuration output publicly if it reveals infrastructure details. Remember that the first two commands usually describe the **CLI** PHP process, not the live web worker.

Keep a database backup/rollback plan before applying any new deployment migrations. This feature has no requirement to erase existing account data.

## 4. Staging API + Samsung test matrix

Use disposable staging accounts and **synthetic** remittance records. Never deliberately delete real account data to simulate data loss.

| Scenario | Expected |
| --- | --- |
| Unauthenticated export, preview or restore | 401 |
| \`text/plain\` sent to preview/restore | 415 (\`BACKUP_JSON_REQUIRED\`) |
| Malformed JSON | 422 validation error |
| JSON larger than 5,242,880 bytes but below proxy limit | 413 (\`BACKUP_TOO_LARGE\`) |
| Normal export under 5 MiB | 200 JSON attachment, private/no-store headers |
| Preview of exported JSON | 200 count summary; **no data written** |
| Restore records absent from **staging** account | 200, accurate inserted counts, relationships and financial totals |
| Restore the same JSON again | 200, created total zero, duplicates skipped, no extra quota |
| A second account attempts a private UUID | Rejected; no cross-account data visible or writes |
| Restore with insufficient Free quota | 409; no partial inserts or quota increments |
| Samsung device with HTTPS/mobile data | Save, select, preview, confirm restore, reopen records, verify amounts and deletion state |
| Proxy/app limit boundary | Reject safely, no blank or misleading success screen |

The merged development-branch checks included **715 Laravel tests / 10,381 assertions** and separate real-MySQL concurrency races (user-reported). All six Samsung workflow checks also passed on the user's development environment. **Those are not production-server measurements.**

### Secure user-facing behavior

- Explain the merge-only behavior before confirmation: restoring does **not** overwrite or undelete records with existing UUIDs.
- Tell Free users that imported historical records count against the current month's quota; \`PLAN_SENDER_MONTHLY_LIMIT\` may stop a valid backup recovery.
- If a backup exceeds 5 MiB, explain that it cannot be imported via the current v1 endpoint. Do not suggest modifying JSON to evade the limit.
- Treat a saved JSON backup as private financial data; prefer device-local, access-controlled storage and avoid public cloud links.
- Never automatically retry a failed restore with altered UUIDs, dates, ownership or quotas.

## 5. Release gate

- [ ] Staging and production topology documented (Nginx/Apache/proxy/CDN/PHP worker).
- [ ] HTTPS, production secrets, \`APP_DEBUG=false\`, and API authentication checked.
- [ ] Actual web-server limits tested with realistic JSON near 5 MiB.
- [ ] Peak PHP memory/worker time measured under large preview/restore requests.
- [ ] Unauthorized, malformed, too-large, conflict/quota and happy-path results checked over the deployed HTTPS API.
- [ ] No backup request/response bodies or bearer tokens captured in logs/telemetry.
- [ ] Android release build tested against the **deployed** staging API using synthetic accounts.
- [ ] Restore Free-plan limitation disclosed; support procedure documented.
- [ ] Rollback/database backup strategy ready and deployment smoke checks assigned.
- [ ] GitHub CI/branch protection status reviewed (PR #14 had **no reported Actions status**; prior tests were run locally).

**Do not mark the production release complete until the applicable items above are independently verified on the actual hosting stack.**
