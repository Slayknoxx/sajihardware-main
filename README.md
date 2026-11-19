<img width="1920" height="1080" alt="Screenshot 2026-11-14 232736" src="https://github.com/user-attachments/assets/6bff37b8-2336-4c01-8196-444220945e94" /># SAJI Hardware Web POS

Local web-based POS for **SAJI HARDWARE LTD** . It uses PHP, HTML, CSS, JavaScript, SQLite, and a small optional Python backup helper.
<img width="1920" height="1080" alt="Screenshot 2026-11-14 233050" src="https://github.com/user-attachments/assets/123565fc-b754-4393-9dc7-46da34cb8742" />
<img width="1920" height="1080" alt="Screenshot 2026-11-14 233003" src="https://github.com/user-attachments/assets/36d8a3e5-f98d-45ee-80b3-d5444244c8bd" />
<img width="1920" height="1080" alt="Screenshot 2026-11-14 232736" src="https://github.com/user-attachments/assets/79ac91a7-f877-4cee-8977-ae6b61c63c83" />



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
- Restocking from the Products page, with an optional buying-price update and a visible stock movement ledger.
- Purchase orders with per-line unit costs, partial receiving, automatic PARTIAL/RECEIVED status, optional buying-price updates on delivery, and automatic supplier-credit posting (skippable with *Paid upfront*) plus a supplier payments form that reduces the balance and logs a cash expense.
- Returns recorded against a receipt line with quantity caps, automatic restocking, and a recent-returns table.
- Credit sales on a saved customer balance, customer payments with oldest-first settlement, receivables ageing on Analytics and Cash Flow, and supplier credit from purchase-order receiving with a supplier payments form.
- Cash shift reconciliation: opening float, expected cash from the cashier's cash sales plus cash income minus cash expenses, declared cash, and variance, with shift history on Cash Flow.
- Editing an existing product's selling price, buying price, VAT rate, category, supplier, and reorder level without changing stock; completed sales keep their original price snapshots.
- Admin-only full-field editing on the Admin page: every product column (SKU, barcode, images, warehouse, stock quantity, supplied quantity, prices, VAT, supplier) and every customer and supplier column (name, phone, email, KRA PIN, credit/owed balance), with changes written to the audit log and stock adjustments recorded as movements.
- Per-line cost snapshots at checkout so historical gross profit stays stable when buying prices change, plus per-branch sales analytics.
- Employee accounts are limited to Sales, the product catalogue, Quotations, and Settings — no Dashboard, Customers & Suppliers, or product-entry form.
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
- **Admin → Data exports** downloads current cash flow, analytics, quotation,
  customer, supplier, and product data as CSV. Analytics and cash flow use the
  selected report periods; exports include all matching records.
- Product buying prices and analytics cost figures remain restricted by the
  user's cost-view permission.

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
  VAT collected), top products by revenue and units, top cashiers, inventory,
  expenses by category (the Expenses form plus manual cash-movement expenses)
  using a fixed 21-line chart of accounts, category and brand sales mix,
  customer counts with top customers, and an eTIMS compliance strip. Admins
  also see the period's individual expense entries with an Edit action, so a
  wrong category or amount can be fixed without leaving the report.
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
breakdowns (including manual cash-movement expenses), compliance counts, and
empty-period behavior. 51 checks.

## Phase 4 — Completed

### Restocking And Stock Movements

- **Products → Restock** adds received units to an existing product. Select the
  product, enter units received, and optionally record a delivery note/invoice
  and a new buying price.
- Restocking updates `products.quantity` and `products.supplied_quantity` inside
  one transaction, so live stock and Stock Analytics sell-through stay aligned.
- Purchase-order receiving uses the **same primitive** (`stock_add_units()` in
  `lib/stock.php`), so both entry points move stock, supplied quantity, price,
  and movement history identically. PO receipts appear in Recent stock
  movements as `RESTOCK` rows tagged with the PO number.
- Every restock writes a `stock_movements` row with reason `RESTOCK`, the
  reference, the acting user, and a timestamp, plus a `RESTOCK_PRODUCT` audit
  entry (PO receipts audit as `RECEIVE_PURCHASE_ORDER` instead).
- **Recent stock movements** on the Products page shows opening stock, sales,
  voids, and restocks, newest first. This is also the quickest way to verify a
  restock was recorded.
- Optional buying-price updates apply to the product cost used by inventory and
  current profit reporting. Historical profit remains affected by the deferred
  cost snapshot (G1).
- Restocking and movement history are restricted to ADMIN and MANAGER; employees
  do not see the panels and the API rejects their requests. The Products page
  itself stays visible to employees for browsing.
- Indexes on `stock_movements(product_id)` and `stock_movements(created_at)` keep
  the ledger and integrity checks responsive.
- Duplicate submissions are limited by disabling the submit button while saving;
  there is no server-side idempotency key. Restocking does not create purchase
  orders or supplier invoices; that remains the deferred G5 work.

### Phase 4 Test Suite

```powershell
php -c .\php-runtime.ini tests\restock_smoke.php
```

Covers stock and supplied-quantity updates, movement and audit entries, optional
buying-price changes, invalid input handling with no partial writes, movement
history, role checks, and the analytics inventory link. 38 checks.

## Phase 5 — Completed

### Employee Workspace

- Employee accounts now open on **Sales** and only see **Sales**, **Products**,
  **Quotations**, and **Settings** in the sidebar. **Dashboard** and
  **Customers & Suppliers** are ADMIN/MANAGER pages.
- The Products page is catalogue-only for employees: the **Add product** form is
  hidden, while the product list stays visible. Restock and stock movement panels
  remain hidden as before.
- The **Recent sales** panel on the Sales page is limited to ADMIN and MANAGER;
  employees see the checkout and held sales only.
- **Customers & Suppliers** records can be edited in place. The **Edit** action
  opens a small form for name, phone, email, and KRA PIN, then refreshes the
  table. Sales keep their original customer-name snapshot, so receipts do not
  change retroactively.
- **Quotations** can be edited by ADMIN and MANAGER. The **Edit** action changes
  the customer name and KRA PIN, adds or removes products, and adjusts
  quantities. Existing lines keep their name/price snapshots, new lines use the
  current product price, and totals/VAT are recalculated. Converted quotations
  are locked because they already produced a sale.
- **Cash Flow** shows a combined **Expenses & cash movements** table for the
  selected period. Admins can edit an expense or manual cash movement in place;
  managers can read the table but see no Edit action. Summary cards and the
  chart refresh after each edit.
- The **Manual cash movement** form on Cash Flow has a category dropdown.
  Choosing **EXPENSE** lists the 21 standard business expense categories (Stock
  Purchase, Freight & Clearing, Rent & Rates, Utilities, and the rest); choosing
  **INCOME** swaps it for a free-text category. The same list drives the Admin
  expense form and the entry editor, so Analytics → Expenses groups spending
  consistently and lists the categories that had no spend in the period.
- **Employees and Branches** on the Admin page can be edited by ADMIN and
  MANAGER, with **Delete** reserved for admins. Managers can open the Admin page
  for these tables, while account creation, expense entry, reset, CSV import,
  system health, and audit history stay hidden and API-protected for admins.
  The duplicate expenses table was removed from the Admin page.
- **Products** rows have an **Edit** action for ADMIN and MANAGER. It changes
  the selling price, buying price, VAT rate, name, SKU, category, brand, branch,
  supplier, and reorder level while leaving stock quantity, supplied quantity,
  and stock movements untouched. Buying price is only shown and changed for
  roles with `cost.view`, so managers keep an unseen cost intact. Completed
  sales and quotations keep their own price/name snapshots, and each edit
  writes an `UPDATE_PRODUCT` audit entry with the old and new values plus an
  optional note. Stock still moves only through Restock.
- Products can be **deleted** by ADMIN and MANAGER from the Delete button next
  to Edit. The confirmation names any stock that will be discarded. A product
  is only removable while no sale, quotation, purchase order, or return refers
  to it, so receipts, quotations, and orders never point at a missing row;
  deleting writes a `DELETE_PRODUCT` audit entry including the SKU, name, and
  discarded units.
- **Quotations can be deleted** by roles with the `records.delete` permission
  via the **Delete** action in the quotation history. Deleting an unprocessed
  quotation removes its lines; deleting a converted quotation keeps the sale
  and its receipt but clears the stale quotation link first, so the sale stays
  intact. Each deletion writes a `DELETE_QUOTATION` audit entry.
- **Data exports** on the Admin page download the current data as CSV: cash
  flow, analytics, quotations, customers, suppliers, and products. Analytics
  and cash-flow exports follow the selected report periods, and every matching
  record is included — not just the 300 rows the on-screen tables show.
  Buying prices and analytics cost figures stay hidden for roles without
  `cost.view`, amounts are written in display units, spreadsheet-formula
  strings are escaped, and each export writes an `EXPORT_CSV` audit entry.
- The allowed page list is issued by the server (`role_pages()` in `lib.php`) at
  login and on session refresh. The browser also re-checks it, so a stale or
  manually opened page falls back to the role's first allowed page.
- The API enforces the same boundary: employees cannot add products, create
  suppliers, or list customers, suppliers, sales, or sale items. Those requests
  are rejected with 403.
- Managers and admins otherwise keep their existing workspace.

### Phase 5 Test Suites

```powershell
php -c .\php-runtime.ini tests\phase5_smoke.php
php -c .\php-runtime.ini tests\people_smoke.php
php -c .\php-runtime.ini tests\quotation_edit_smoke.php
php -c .\php-runtime.ini tests\cashflow_smoke.php
php -c .\php-runtime.ini tests\staff_smoke.php
php -c .\php-runtime.ini tests\product_edit_smoke.php
php -c .\php-runtime.ini tests\csv_export_smoke.php
```

The first covers employee page restrictions, manager/admin access, unknown roles,
and the API role gates for product creation, supplier creation, customer/supplier
lists, and sales history (22 checks). The second covers customer and supplier
editing, audit entries, validation, and no-change behavior on rejected edits
(27 checks). The third covers quotation line add/remove, quantity changes, price
snapshots, customer name/KRA PIN updates, totals, converted-quote locking,
quotation deletion with preserved sales, and role enforcement (47 checks). The
fourth covers the combined cash-flow ledger, expense and cash-movement edits,
audit entries, validation, admin-only editing, and the report/ledger link used
by exports (49 checks). The fifth covers employee and branch edits, unique branch
names, validation, admin-only deletion, and manager access (52 checks). The
sixth covers product price and detail edits, preserved stock and sale snapshots,
partial updates, manager cost restrictions, SKU uniqueness, validation, and API
role gates (69 checks). The seventh covers live CSV exports: full record sets,
report periods, cost visibility, formula escaping, money units, validation, and
API role gates (15 checks).

## Phase 6 — Completed

### Cost Snapshots And Branch Analytics

- Every sale line now stores a **cost snapshot** (`sale_items.cost_price`),
  captured at checkout and at quotation conversion from the product's buying
  price at the moment of sale. Gross profit uses the snapshot first and falls
  back to the current buying price for older sales, so historical margins no
  longer move when prices are edited.
- Every sale also records the **branch** (`sales.branch_id`), resolved from the
  signed-in user's branch. The Analytics page has a **by-branch** table with
  transactions and revenue per branch; sales made before this release read as
  "Unassigned".

### Payment Methods And Credit

- Checkout accepts **Cash, M-Pesa, Card, Bank, Mobile Money, Voucher, and
  Credit** (the shared `PAYMENT_METHODS` list in `config.php`). Cash keeps its
  change logic, M-Pesa still runs the STK push flow, and the remaining methods
  are recorded as fully paid direct payments.
- **Credit** requires a saved customer and records any unpaid remainder on the
  customer's balance. The **People** page shows each customer's credit balance
  and a **Customer payments** form (ADMIN/MANAGER): a payment reduces the
  balance, is allocated oldest-first across the customer's unpaid credit sales
  (so each sale's paid amount tracks money actually received), and cash
  payments also log a cash income entry in the Cash Flow ledger.
- Quotation conversion supports every method except M-Pesa, with the same
  Cash/Credit amount rules.
- The checkout form has a **saved-customer picker** fed by a lightweight
  `customer_options` API (id, name, phone only). Picking a customer snapshots
  the name and links the sale; typing a name still works for walk-ins.
  Analytics keeps its walk-in estimate for unnamed sales.

### Returns

- Returns are now recorded against a specific sale line with quantity caps so
  a line cannot be returned more times than it sold. Each return restocks the
  units through a `RETURN` stock movement, keeps the product link for
  analytics, and writes an audit entry. ADMIN/MANAGER only.
- Every return records a **refund method** (`Cash`, `M-Pesa`, `Card`, `Bank`,
  `Mobile Money`, `Voucher`, or `None` for an exchange). The panel defaults to
  the way the customer paid; credit sales default to `None` because they never
  took money in. Cash refunds are subtracted from the open shift's expected
  cash.
- The Sales page has a **Returns** panel: enter a receipt number, pick the line
  and quantity, add a reason, choose the refund method, and record. A
  recent-returns table sits below with the refund method per row.

### Purchase Orders

- Purchase orders carry **line items** with per-line unit cost. Create an order
  from the Products page: pick a supplier, add product lines (defaulting to the
  product's buying price), set an expected date and notes.
- **Receiving** is per line and can be partial: units go into live stock
  through the shared restock primitive (the same one the Products page uses),
  supplied quantity increases, and you can
  optionally update the buying price. The order moves OPEN → PARTIAL →
  RECEIVED automatically. Orders that have not received anything can be
  cancelled. ADMIN/MANAGER only.
- **Supplier credit is automatic.** Unless the receiving dialog's *Paid upfront*
  box is ticked, each receipt adds the received value (units × the line's unit
  cost, or the new buying price when one is entered) to the supplier's balance,
  so goods taken on credit create a payable. The People page has a **Supplier
  payments** form: pick a supplier, enter the amount and channel, and the
  balance drops straight away. Cash payments also log a `Supplier payment`
  cash expense so the Cash Flow ledger shows the money leaving the till.
  Payments above the balance, and suppliers with nothing owed, are rejected.
  These balance changes are not audited yet.

### Supplier Payments

- The **Supplier payments** form on Customers & Suppliers lists suppliers the
  business owes first (`Nairobi Cement Ltd · we owe KES 5,000.00`) and settled
  suppliers as `nothing owed`. Every payment is stored in `supplier_payments`
  with its method and reference. ADMIN/MANAGER only.

### Cash Shifts

- Any signed-in user can open a **cash shift** with an opening float from the
  Sales page and close it by declaring the cash in the till. Expected cash is
  the opening float plus that cashier's completed cash sales and cash income
  (manual INCOME movements), minus cash paid out of the till during the shift
  (manual EXPENSE movements, supplier payments, and cash refunds on returns).
  The close records the variance (short/over). One open shift per user.
- Returns recorded before refund methods existed have no method stored, so they
  are not counted against expected cash.
- The Cash Flow page shows the **shift history** with float, expected,
  declared, and variance per shift.

### Receivables And Analytics

- The Analytics report and the Cash Flow page both show **receivables**:
  outstanding credit, ageing buckets (0–30, 31–60, 61–90, and 90+ days), and
  the largest debtors.
- Analytics also gained a **by payment method** mix table, **returns refunded**
  figures for the period, and the branch table described above.

### Full-Field Product And Supplier Editing (Admin Only)

- The Admin page has an **Edit products & suppliers** panel (admins only).
  Pick a product and every column is editable: SKU, barcode, name, category,
  brand, image paths, warehouse, branch, supplier, buying and selling prices,
  VAT rate, reorder level, stock quantity, and supplied quantity. A stock
  quantity change is recorded as an `ADJUSTMENT` movement with the note as the
  reference, so the movement ledger stays truthful.
- Suppliers can be edited the same way: name, phone, email, KRA PIN, and the
  balance the business owes them. Customers get the same treatment: name,
  phone, email, KRA PIN, and the **credit balance** they owe the business —
  so admins can add credit to a customer or correct a balance without running
  a Credit sale (a blank balance keeps the current value).
- Every change is written to the audit log with the note attached. The six
  backing API actions (`admin_product_detail`, `admin_update_product`,
  `admin_supplier_detail`, `admin_update_supplier`, `admin_customer_detail`,
  `admin_update_customer`) are gated to ADMIN and reject managers and
  employees with 403.

### Phase 6 Test Suite

```powershell
php -c .\php-runtime.ini tests\phase6_smoke.php
php -c .\php-runtime.ini tests\admin_edit_smoke.php
```

Covers the cost snapshot and buying-price fallback, branch capture and the
"Unassigned" bucket, credit remainder arithmetic, FIFO payment allocation with
sale settlement, balance/cash-transaction effects, receivables ageing, return
recording with restock and movement caps, purchase order create/receive/cancel
with stock and price effects, supplier balance crediting on receipt (including
the paid-upfront skip), supplier payments with cash expenses, the shared
restock primitive behind receiving, shift open/close with expected cash
(including cash in, cash out, and cash refunds) and variance, refund methods on
returns, the payment-method allow-list,
customer capture in checkout and the idempotency hash, API role gates, the
business reset (children-first deletes under enforced foreign keys), and the
analytics payload shape (96 checks). The admin
editor suite covers the ADMIN-only gates, detail loading, full-column product
edits with ADJUSTMENT movements, supplier and customer edits including the
balances, validation failures, duplicate SKU handling, no-change saves, and
audit entries (47 checks).

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
    exports.php           Current-data CSV exports
    import.php            CSV templates, parsing, validation/preview, commit
    license.php            License states, clock guard, activation, check-in
    cashflow.php           Cash-flow ledger and admin entry editing
    people.php             Customer and supplier record editing
    products.php           Existing product price and detail editing
    quotations.php         Quotation line and customer detail editing
    security.php           CSRF, security headers, origin checks, throttling
    staff.php              Employee and branch record editing
    stock.php              Restocking and stock movement history
  tests/                   Phase 1–5 regression suites and helpers
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
