# SAJI Hardware Web POS

Local web-based POS for **SAJI HARDWARE LTD** . It uses PHP, HTML, CSS, JavaScript, SQLite, and a small optional Python backup helper.

## Features Included

- Responsive touchscreen-friendly sales screen.
- White-label branding: company name, logo, contact details, receipt footer, and
  brand colour are configurable and applied to the app shell and printed receipts.
- First-run setup wizard for the business profile, first branch, and optional
  license activation.
- CSV import for products, customers, and suppliers with preview, duplicate
  handling, and all-or-nothing commits.
- Product catalog with SKU, barcode, image path, barcode image path, category, branch, warehouse, buying price, selling price, stock, and reorder level.
- Barcode scanner support through the search box. Most USB scanners work like a keyboard, so click the search field and scan.
- Cart, discount, cash/M-Pesa/card/voucher/credit/mixed payment methods, VAT-inclusive totals, sale hold, proforma print, checkout, stock deduction, and receipt printing.
- Branded receipts using the configured company profile and logo (default
  `public/assets/saji-logo.png`).
- Admin, Manager, and Employee login roles.
- Admin user creation, employee records, branches, customers, suppliers, expenses, reports, sync queue, audit logs, and backups.
- Business analytics with selectable periods, comparisons, revenue trends, top products and cashiers, payment mix, inventory, expenses, sales mix, customers, and eTIMS compliance.
- Collapsible sidebar that remembers its state and switches to an icon rail on narrow or split windows.
- SQLite database stored locally in `data/saji_pos.sqlite3`, with WAL mode for better safety and concurrency.
- Atomic checkout transaction so stock and sale records are not partially saved.
- KCB Buni STK Push payment folder with MySQL payment records, callback signature validation, and pending-payment states.

## Phase 1 — Completed

Phase 1 delivered the core security, licensing, and backup work. White-label
branding, the setup wizard, and CSV import followed in Phase 2 (below). See
`BACKLOG.md` for everything still open.

### Security

- CSRF tokens on every mutating API call, origin checks, login throttling, and
  hardening headers (CSP, `X-Content-Type-Options: nosniff`, frame and referrer
  policy).
- Default and admin-issued reset passwords are active immediately. There is no
  forced or self-service password-change screen.
- Sessions are revalidated against the users table on every request, so deleted
  or deactivated accounts lose access. The session ID is regenerated on login to
  prevent fixation.
- Path-traversal protection in `router.php`: encoded `..` and NUL paths are
  rejected, static files are confined to `public/`, and only the browser-facing
  API files (`callback.php`, `payment_status.php`, `stk_push.php`) are reachable
  under `/api/`. This also applies to the PHP built-in server, where `.htaccess`
  rules do not run.
- Deny-all `.htaccess` files remain as defence in depth for Apache deployments.
- Database exception details are no longer returned to clients; they are written
  to `data/logs/app.log` instead.
- Blank `.env` entries no longer override built-in defaults (for example an empty
  `LICENSE_PUBLIC_KEY` falls back to the embedded key).

### Licensing

- 14-day local trial with a 7-day grace period after a paid license expires.
- Offline license keys are Ed25519-signed: `<payload>.<signature>` in base64url.
  The application ships only the public key in `config.php`; the private key stays
  in `tools/keys/` and must never be copied to a customer machine.
- Optional machine binding and an optional best-effort phone-home/revocation check
  through `LICENSE_SERVER_URL`. Leaving it blank keeps the install fully offline.
- Clock-rollback guard persists `license_clock_tampered`. The anchor advances at
  most one day at a time, so a forward clock jump followed by a correction is not
  misread as tampering. A valid activation clears the flag.
- Invalid, tampered, expired, revoked, wrong-machine, and trial-expired keys are
  all covered by `tests/license_states.php`.

### Backups And Restore

- Automatic daily backup on the first authenticated request, with pruning of old
  automatic backups (default keep: 30).
- Manual create, download, restore, and delete from **Reports & Backups**.
- Restore keeps a `pre_restore` safety copy, and now validates the candidate file
  first: `PRAGMA integrity_check` must pass and the required tables must exist.
  Corrupt or foreign files are rejected before the live database is touched.
- Verified end-to-end by `tests/restore_e2e.php`, including the corrupt-backup
  rejection path.

### Packaging And Release

- `powershell -ExecutionPolicy Bypass -File packaging\build-portable.ps1`
  creates `dist/SAJI-POS-<version>.zip` plus `dist/SHA256SUMS.txt`.
- Add `-BundlePhp` to produce `dist/SAJI-POS-<version>-with-php.zip` with a local
  PHP runtime included.
- The launcher starts `php.exe` minimized (not `php-win.exe`, which cannot host
  the built-in server on PHP 8.5), disables OPcache, and uses the `SAJIPOSID`
  session cookie.
- Packages exclude `tools/`, `tests/`, `data/`, `.env`, signing keys, and
  `BACKLOG.md`.
- A signed Windows `.exe` installer is Phase 1.5.

### Phase 1 Test Suites

Run from the project root with the bundled runtime config:

```powershell
php -c .\php-runtime.ini tests\phase1_smoke.php
php -c .\php-runtime.ini tests\restore_e2e.php
php -c .\php-runtime.ini tests\license_states.php
```

The HTTP suite starts and stops its own server on port 8011:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\tests\run_http_smoke.ps1
```

To run it against an already-running server, start
`php -c .\php-runtime.ini -S 127.0.0.1:8011 router.php` and run
`tests\http_smoke.ps1` directly.

Coverage at completion: 17 Phase 1 smoke checks, 16 restore checks, 17 licensing
state checks, and HTTP checks (including traversal, CSRF, immediate login,
backup/restore, CSV import, and password-reset endpoints).

### Issue A License

```powershell
php -c .\php-runtime.ini tools\make_license.php keygen
php -c .\php-runtime.ini tools\make_license.php issue --customer="Shop Name" --pin=P051234567X --plan=standard --days=365
```

Paste the printed key into **Settings → License**. `keygen` is run once; keep
`tools/keys/private.key` secret and out of the packaged build. Move the private
key off the build machine and delete local copies once customer keys are issued.

## Phase 2 — Completed

### White-Label Branding

- Company name, email, phone, location, tagline, logo path, receipt footer, and
  brand colour are stored in `app_settings` and read through `company_profile()`.
- The app title, login screen, sidebar logo, header subtitle, CSS brand colour,
  receipts, and quotation receipts all use the saved profile.
- **Settings → Business profile & branding** edits the same fields later, and
  changes apply immediately without a restart.

### First-Run Setup Wizard

- After an admin signs in, the setup wizard opens while `setup_completed` is not `1`.
- It collects the business profile, the first branch, and an optional license key,
  then saves everything through the `save_setup` API action.
- **Later** hides the wizard without marking setup complete, so it returns on the
  next sign-in.

### CSV Import

- **Admin → CSV import** handles products, customers, and suppliers.
- Download a template, choose **Skip existing rows** or **Update existing rows**,
  preview every row first, then import. Nothing is written during preview.
- Validation errors block the whole file. Commits run in one transaction, so a
  failed import never leaves a partial file behind.
- Product updates change descriptive fields and prices but never overwrite stock.
- Limits: 5,000 data rows per file, 200 preview rows shown.

### Money And Checkout Safety

- Monetary values are stored as integer minor units (cents) to avoid floating
  point arithmetic errors; API responses convert amounts back to display units.
- Checkout recalculates prices, discounts, VAT, totals, and change on the server
  using current product records. It validates payment inputs and checks stock
  again inside the sale transaction before deducting inventory.
- Checkout requests require an idempotency key. Retrying the same request returns
  the original result instead of creating a duplicate sale; reusing a key with
  different sale details is rejected.
- Sales, stock deductions, stock ledger entries, and the saved checkout result
  are committed together, so failed checkouts do not leave partial updates.

### Roles, Sessions, And Audit History

- **Admin → Role & session settings** controls the idle session timeout
  (5–1,440 minutes) and Manager/Employee permissions for voiding sales, viewing
  cost prices, deleting records, and maximum discount percentages. Admin
  permissions remain unrestricted.
- Session permissions and account status are checked against the database on
  authenticated requests. Password resets invalidate existing sessions.
- Administrative and business actions—including sales, user/password changes,
  imports, settings, and backup operations—are recorded in the audit log.
  Admins can search and page through the log in the app.

### Health, Integrity, And Application Logs

- The health endpoint provides a basic availability check. Admin **System
  health** reports SQLite integrity, foreign-key violations, the latest
  integrity-check findings, the last backup, and application-log size.
- Scheduled integrity checks inspect SQLite, foreign keys, stock totals against
  the movement ledger, and negative stock; findings are stored and surfaced to
  admins.
- Application logs use structured JSON lines, redact sensitive messages, and
  rotate at a size limit.

### Account Recovery

- Admins can reset any user's password from **Admin → Employee accounts**.
  A strong temporary password is shown once and the user is signed out. Admins
  can also reset their own account and sign in again with the new password.
- Break-glass CLI recovery works without the web server or a session:

```powershell
php -c .\php-runtime.ini tools\reset_password.php --user=admin
php -c .\php-runtime.ini tools\reset_password.php --user=admin --password="NewSecret123!"
```

- Omitting `--password` generates a temporary password and prints it once. The
  script clears login lockouts, bumps `session_version`, and writes an audit entry.

### Phase 2 Test Suites

```powershell
php -c .\php-runtime.ini tests\phase2_smoke.php
php -c .\php-runtime.ini tests\cli_reset_e2e.php
powershell -NoProfile -ExecutionPolicy Bypass -File .\tests\run_http_smoke.ps1
```

The suites cover Phase 2 behavior, CLI password recovery, HTTP login without a
forced-change gate, CSRF, health headers, backups, imports, and password-reset
session invalidation. Phase 2 smoke checks also cover money conversion,
permissions, integrity checks, structured logging, and the one-time admin
credential reset.

## Phase 3 — Completed

### Business Analytics

- The Analytics screen is a period report driven by a single `analytics` API
  action (`lib/analytics.php`). Periods are **Today**, **7 days**, **This
  month**, **Last month**, **This quarter**, **This year**, plus a custom date
  range.
- Every figure uses confirmed money only (`sales.status = 'COMPLETED'`).
  Pending and failed M-Pesa payments, voids, and discounts are reported as
  separate exposure metrics and never inflate revenue.
- Each KPI shows a comparison (vs the previous period, same period last month,
  or same period last year) or an explicit **No comparison** label when there is
  no base to compare against.
- Report sections: sales summary, revenue trend (daily or monthly buckets) and
  hourly sales for today, money in (cash/M-Pesa/open payments/discounts/voids/
  VAT breakdown), top products by revenue and units, top cashiers, inventory,
  expenses by category, category and brand sales mix, customer counts with top
  customers, and an eTIMS compliance strip.
- Cost-derived figures — gross profit, gross margin, inventory at cost, and
  potential margin — require the `cost.view` permission. Managers and employees
  without it see **Restricted** tiles instead of numbers.
- Gross profit is estimated from each product's current `buying_price` and is
  labelled accordingly. Accurate historical profit needs the deferred
  `sale_items.cost_price` snapshot (see `BACKLOG.md`, G1).
- Inventory value is a snapshot at current prices, not period movement, and the
  page says so.
- Empty periods render explicit empty states rather than blank panels or zeros
  without context.
- Dates are grouped in the shop timezone (`Africa/Nairobi`), while timestamps
  remain stored in UTC, so late-evening and early-morning sales land on the
  correct local day.
- **Stock analytics** now shows a summary strip (products, low/out of stock,
  inventory at cost and retail, potential margin) above the supplier
  sell-through table, with the same cost permission gating.
- Indexes on `sales.created_at`, `sales(status, created_at)`, and
  `expenses.created_at` keep the period queries responsive.


### Phase 3 Test Suite

```powershell
php -c .\php-runtime.ini tests\analytics_smoke.php
```

Covers period maths (including quarter/last-month/custom ranges), shop-local day
boundaries, confirmed-money filtering, comparison deltas, cost-permission gating,
breakdowns, compliance counts, and empty-period behavior. 50 checks.

## Default Login

```text
Username: admin
Password: admin123
```

The app does not force a password change at login.
On the first app request after this update, a one-time migration resets the
existing `admin` account to these credentials and clears its login lockout.

## Run Locally

Install PHP for Windows from `https://windows.php.net/download/` or install XAMPP, Laragon, or WampServer.

From PowerShell:

```powershell
cd "C:\xamppnew3\htdocs\sajihardware"
php -c .\php-runtime.ini -S 127.0.0.1:8000 router.php
```

Open:

```text
http://localhost:8000
```

## Printer And Barcode Scanner

- Receipt printer: use the browser print dialog after checkout. Set your receipt printer as the default printer or choose it in the print dialog.
- Barcode scanner: plug in a USB barcode scanner, focus the POS search field, then scan. Keyboard-wedge scanners work without extra drivers.
- For direct silent printing, you can later add a local printer bridge such as RawBT, QZ Tray, or a Windows service. Browser security normally prevents silent printing without a helper.

## Backups

From the Reports & Backups screen, click **Create backup**.

Optional Python backup:

```powershell
cd "C:\xamppnew3\htdocs\sajihardware"
python scripts\backup.py
```

Backups are saved in:

```text
data\backups
```

## Build As A Local App

Option 1: run it as a browser app with PHP built-in server. Use `run-pos.bat` or the router command above so both `public/` and the root `api/` folder are reachable.

Option 2: install XAMPP/Laragon and put this folder in the local web root.

Option 3: wrap it as a Windows desktop app with NativePHP, Electron, or WebView2. The simplest packaging path is:

1. Install PHP locally.
2. Create a shortcut or `.bat` file that runs `php -c .\php-runtime.ini -S 127.0.0.1:8000 router.php`.
3. Open `http://127.0.0.1:8000` in Edge/Chrome app mode.

Example `run-pos.bat`:

```bat
cd /d "C:\xamppnew3\htdocs\sajihardware"
php -c "%~dp0php-runtime.ini" -S 127.0.0.1:8000 "%~dp0router.php"
```

## Project Structure

```text
saji_hardware_pos_web/
  api.php                  JSON API for login, sales, products, reports, backups
  api/
    config.php             KCB Buni and XAMPP MySQL payment settings
    token.php              KCB token test endpoint
    stk_push.php           Sends STK Push after local checkout creates a pending sale
    callback.php           KCB callback endpoint; only this marks M-Pesa sales paid
    payment_status.php     Frontend polling endpoint
    schema.sql             MySQL database tables for payment verification
  config.php               Company details, licensing constants, integration placeholders
  lib.php                  SQLite connection, schema, auth helpers, backups
  router.php               Router for the built-in server with traversal guard
  lib/
    analytics.php         Business analytics periods, KPIs, and reports
    import.php            CSV templates, parsing, validation/preview, commit
    license.php            License states, clock guard, activation, check-in
    security.php           CSRF, security headers, origin checks, throttling
  tests/                   Phase 1–3 regression suites and helpers
  tools/
    make_license.php       Issues signed offline license keys
    reset_password.php     Break-glass CLI password recovery
    phase1_snapshot.php    Backup/integrity/key snapshot helper
  packaging/
    build-portable.ps1     Portable ZIP builder (optional bundled PHP)
  .env.example             Environment template (no secrets)
  BACKLOG.md               Deferred work and completed-work reference
  data/
    saji_pos.sqlite3       Local secure database file created on first run
    backups/               Manual and Python backups
  public/
    index.php              Web app shell
    assets/
      app.js               POS frontend logic
      styles.css           Responsive/touch CSS
      saji-logo.png        Company logo for app and receipts
    uploads/               Future product image storage
  scripts/
    backup.py              Optional Python backup helper
```

## KCB Buni M-Pesa Setup

1. Start Apache and MySQL in XAMPP.
2. Open phpMyAdmin and import `api/schema.sql`, or run it in the SQL tab.
4. Edit `api/config.php` on the server only:
   - Set `PAYMENT_DB_*` for your XAMPP MySQL user.
   - Set `KCB_BUNI_ENABLED` to `true`.
  - Paste the Buni consumer key, consumer secret, `orgShortCode`, `sharedShortCode`, OAuth token URL, and STK Push URL supplied for your KCB environment.
  - Replace `KCB_CALLBACK_TOKEN` with a long random value. It is added to the callback URL and is checked before any callback is processed.
  - Set `KCB_CALLBACK_SECRET` only when KCB documents and sends an `X-KCB-Signature` header for your account. The POS browser does not receive or send KCB credentials.
3. Start the project server from PowerShell:

```powershell
cd "C:\xamppnew3\htdocs\sajihardware"
php -c .\php-runtime.ini -S 127.0.0.1:8000 router.php
```

4. In a second PowerShell window, start ngrok:

```powershell
ngrok http 8000
```

5. Copy the HTTPS forwarding URL from ngrok and set:

```php
const KCB_CALLBACK_URL = 'https://YOUR-NGROK-DOMAIN.ngrok-free.app/api/callback.php';
```

6. Set `KCB_CALLBACK_URL` to the exact HTTPS URL, including the token query string:

```php
const KCB_CALLBACK_URL = 'https://YOUR-NGROK-DOMAIN.ngrok-free.app/api/callback.php';
```

The application sends `?token=KCB_CALLBACK_TOKEN` when it creates the STK request. Register the resulting full callback URL in the KCB Buni portal if KCB requires pre-registration. Keep ngrok running while testing; its URL must remain reachable by KCB.

7. Test a sale with payment method `M-Pesa`. The sale is `PENDING_PAYMENT`; `api/stk_push.php` sends the prompt; `api/callback.php` marks it `PAID` only after the callback token, success result code, checkout reference, amount, phone, and provider receipt all match the pending MySQL payment record. Duplicate callbacks are ignored.

8. Confirm the callback endpoint is reachable before a payment:

```powershell
curl.exe -i "https://YOUR-NGROK-DOMAIN.ngrok-free.app/api/callback.php?token=WRONG_TOKEN"
```

It must return `401`. Do not expose `api/config.php`, callback tokens, consumer secrets, or MySQL credentials in the `public` directory or frontend JavaScript.

To check that PHP has the required extensions:

```powershell
php -m | findstr /i "curl pdo_mysql sqlite pdo"
```

Never treat `STK_SENT` as paid. Receipt printing remains blocked until the local sale is `COMPLETED`, `mpesa_status` is `PAID`, and the amount paid equals the sale total.
