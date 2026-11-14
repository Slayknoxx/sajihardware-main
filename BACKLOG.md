# Backlog

Remaining deferred work for SAJI Hardware POS. Completed work is summarized at
the bottom for reference.

## Licensing

- [ ] **License server / phone-home endpoint**
  - Client check-in exists but `LICENSE_SERVER_URL` is blank, so installs stay
    fully offline. Decide whether to run a server or keep offline-only.
  - If added: revoke-by-license-id, reissue, and an audit trail per check-in.

- [ ] **Machine binding policy**
  - Binding is implemented but optional (`machine` field in the signed payload).
    Decide whether new keys bind by default and how re-hosting is handled.

## Packaging

- [ ] **Signed Windows installer (.exe) — Phase 1.5**
  - Wrap the portable build in an installer (Inno Setup or similar), sign it,
    and publish `SHA256SUMS.txt` alongside.

## Analytics follow-ups

- [ ] **G1 — Cost snapshot on `sale_items`**
  - Gross profit currently uses each product's current `buying_price`, so past
    periods change when prices are edited. Add `sale_items.cost_price` captured
    at checkout and switch profit reporting to it. This is the only blocker for
    accurate historical margin.

- [ ] **G2 — Branch-level analytics**
  - `sales` has no `branch_id`. Add it (populated from the logged-in user) before
    any per-branch KPI or comparison.

- [ ] **G3 — Credit sales / receivables**
  - No credit checkout path exists, so debtor ageing and receivables analytics are
    deferred until credit sales are a real workflow.

- [ ] **G4 — Returns linked to products**
  - `returns` has no product link and no API/UI, so return rate and net revenue by
    product are unavailable.

- [ ] **G5 — Purchase-order lines and receiving**
  - `purchase_orders` has no line items or received quantity, blocking landed
    cost, supplier price trends, and true margin. Phase 4 restocking records
    units received for an existing product, but it does not create or receive a
    purchase order, so this remains open.

- [ ] **G6 — Cash shift reconciliation**
  - `cash_shifts` is unused. Till short/over and cashier cash variance need
    open/close screens.

- [ ] **G7 — More payment methods**
  - Only Cash and M-Pesa are accepted; card/bank/mobile-money splits need an
    extended payment-method allow-list.

- [ ] **G8 — Named customers**
  - Walk-in sales dominate. Analytics labels walk-in figures as approximate;
    reliable retention needs phone capture or named customers at checkout.

## Completed in Phase 2

- [x] **Admin password reset (any user)** — **Admin → Employee accounts → Reset
      password** issues a temporary password, clears the forced-change flag,
      bumps `session_version` so existing sessions
      are signed out, and writes a `PASSWORD_RESET` audit entry.
- [x] **Break-glass admin recovery (CLI)** — `tools/reset_password.php --user=admin
      [--password=...]`       resets the hash and clears login
      lockouts, and writes an audit entry. No web server or session required.
      Covered by `tests/cli_reset_e2e.php`.
- [x] **Automated HTTP test in CI** — `tests/run_http_smoke.ps1` starts the server
      on port 8011, waits for health, runs `tests/http_smoke.ps1`, and always
      stops the server.
- [x] **White-label branding** — company profile fields (name, email, phone,
      location, tagline, logo path, receipt footer, brand colour) are stored in
      `app_settings` and rendered by the app shell, receipts, and quotations.
- [x] **First-run setup wizard** — collected business profile, first branch, and
      optional license activation; auto-opens for admins until `setup_completed`
      is set.
- [x] **CSV import** — products, customers, and suppliers with templates,
      preview, skip/update duplicate handling, and transactional commits.
- [x] **Test coverage** — `tests/phase2_smoke.php`,
      `tests/cli_reset_e2e.php`, and the HTTP smoke suite.

## Completed in Phase 3

- [x] **Business analytics rebuild** — period reports (today/7d/MTD/last
      month/QTD/YTD/custom) from confirmed sales only, with comparisons, trends,
      payment mix, voids, VAT, products, cashiers, inventory, expenses, sales
      mix, customers, and eTIMS compliance. Cost tiles gated by `cost.view`.
- [x] **Stock analytics summary** — inventory value, low/out of stock, and
      potential margin above the supplier sell-through table.
- [x] **Collapsible sidebar** — Menu toggle, persisted preference, submenu
      layout, and automatic icon rail on split/narrow windows.
- [x] **Test coverage** — `tests/analytics_smoke.php` (50 checks).

## Completed in Phase 4

- [x] **Restock flow** — Products → Restock adds received units to an existing
      product, updates live stock and supplied quantity, optionally refreshes the
      buying price, and writes a `RESTOCK` stock movement plus an audit entry.
- [x] **Stock movement ledger** — Recent stock movements shows opening stock,
      sales, voids, and restocks, newest first, with reference and user.
- [x] **Role enforcement** — Restocking and movement history are limited to
      ADMIN and MANAGER; employees see no restock controls.
- [x] **Test coverage** — `tests/restock_smoke.php` (38 checks).

## Completed in Phase 5

- [x] **Employee workspace** — Employees land on Sales and see only Sales,
      Products (catalogue), Quotations, and Settings. Dashboard and Customers &
      Suppliers are limited to ADMIN and MANAGER.
- [x] **Catalogue-only Products page** — The Add product form is hidden for
      employees; the product list remains visible, and restock/stock movement
      panels stay hidden.
- [x] **Server-published pages** — `role_pages()` supplies the allowed page ids at
      login and session refresh, and the browser re-checks page access.
- [x] **API enforcement** — Employees cannot add products, create suppliers, or
      list customers/suppliers.
- [x] **Test coverage** — `tests/phase5_smoke.php` (19 checks).

## Completed in Phase 1 (reference)

- Default admin credentials (`admin` / `admin123`) are reset once during the
  authentication update; login no longer forces a password-change screen.
- Clock-rollback guard (`license_clock_tampered`), with a capped anchor so a
  forward clock jump followed by a correction is not misread as a rollback.
- Router denies path traversal and only exposes an explicit API whitelist,
  including when running under the PHP built-in server (no `.htaccess`).
- Backup restore validates SQLite integrity and required tables before the live
  database is touched; corrupt or foreign files are rejected.
- Sessions are revalidated against the users table on every request, and the
  session ID is regenerated on login (session-fixation guard).
- Database error details (`PDOException`) are no longer returned to clients.
- Blank `.env` entries fall back to built-in defaults instead of overriding them.
- End-to-end restore test (`tests/restore_e2e.php`).
- Licensing state test (`tests/license_states.php`).
- Deny-all `.htaccess` rules for `data/`, `tools/`, `scripts/`, `lib/`, `tests/`,
  `packaging/`, `api/config.php`, and the project root.
- Portable build: `SHA256SUMS.txt`, bundled-PHP ZIP.
- Portable launcher no longer uses `php-win.exe` (it cannot host PHP's built-in
  server on PHP 8.5); it starts `php.exe` minimized instead.
