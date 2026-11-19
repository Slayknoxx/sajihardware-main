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

Closed in Phase 6 — see "Completed in Phase 6" below.

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
- [x] **Test coverage** — `tests/analytics_smoke.php` (51 checks).

## Completed in Phase 4

- [x] **Restock flow** — Products → Restock adds received units to an existing
      product, updates live stock and supplied quantity, optionally refreshes the
      buying price, and writes a `RESTOCK` stock movement plus an audit entry.
- [x] **Stock movement ledger** — Recent stock movements shows opening stock,
      sales, voids, and restocks, newest first, with reference and user.
- [x] **Role enforcement** — Restocking and movement history are limited to
      ADMIN and MANAGER; employees see no restock controls.
- [x] **Test coverage** — `tests/restock_smoke.php` (38 checks).

## Completed in Phase 6

- [x] **G1 — Cost snapshot on `sale_items`** — Checkout and quotation conversion
      capture `cost_price` per line at sale time. Profit reporting uses the
      snapshot and falls back to the current `buying_price` for older sales.
- [x] **G2 — Branch-level analytics** — `sales.branch_id` is resolved from the
      logged-in user's branch at checkout and conversion; the analytics report
      has a by-branch revenue/transactions table (older sales read "Unassigned").
- [x] **G3 — Credit sales / receivables** — Credit checkout requires a saved
      customer, records the unpaid remainder on the customer balance, and
      payments (`create_customer_payment`, ADMIN/MANAGER) reduce it FIFO across
      the customer's unpaid credit sales; cash payments also log a cash income
      row. The Analytics and Cash Flow pages show outstanding credit, ageing
      buckets (0–30/31–60/61–90/90+ days), and a debtors table.
- [x] **G4 — Product-linked returns** — Returns are recorded against a sale line
      (`create_return`, ADMIN/MANAGER) with quantity caps, restock the units via
      a `RETURN` stock movement, and feed returns totals into analytics. Each
      return stores a refund method (defaulting to the sale's payment method,
      `None` for credit); cash refunds reduce the open shift's expected cash. The
      Sales page has a receipt-lookup returns panel and a recent-returns table.
- [x] **G5 — Purchase orders** — Orders carry line items (`purchase_order_items`)
      with per-line unit cost; receiving (full or partial, ADMIN/MANAGER) adds
      stock via `RESTOCK` movements, optionally updates the buying price, and
      flips the order OPEN → PARTIAL → RECEIVED. Untouched orders can be
      cancelled. The Products page has the order builder and a receive dialog.
- [x] **G6 — Cash shift reconciliation** — Any signed-in user can open/close
      their till shift from the Sales page. Expected cash = opening float + the
      cashier's completed cash sales and cash income − cash expenses paid from
      the till (manual EXPENSE movements and supplier payments) − cash refunds
      on returns; closing stores the declared cash and variance. Cash Flow shows
      the shift history.
- [x] **G7 — More payment methods** — Checkout accepts Cash, M-Pesa, Card, Bank,
      Mobile Money, Voucher, and Credit (`PAYMENT_METHODS` in `config.php`).
      Cash keeps change logic, M-Pesa runs the STK flow, Credit tracks the
      balance, and the rest are recorded as fully paid direct payments.
      Quotation conversion supports every method except M-Pesa.
- [x] **G8 — Named customers** — Checkout has a saved-customer picker
      (`customer_options`, available to every role) that snapshots the customer
      name and links `sales.customer_id`; free-text walk-in names still work.
      The People page shows credit balances and a customer payment form.
- [x] **Supplier credit** — Receiving a purchase order adds the received value
      to the supplier balance unless *Paid upfront* is ticked; the People page
      has a supplier payment form that reduces the balance and logs a cash
      expense (no audit entries yet, per the agreed scope).
- [x] **Shared restock path** — Manual restocking and purchase-order receiving
      both call `stock_add_units()` in `lib/stock.php`, so stock, supplied
      quantity, price, and movement history stay consistent.
- [x] **Test coverage** - `tests/phase6_smoke.php` (96 checks),
      `tests/admin_edit_smoke.php` (47 checks for the admin-only full-field
      product, supplier, and customer editors, including balance corrections).

## Completed in Phase 5

- [x] **Employee workspace** — Employees land on Sales and see only Sales,
      Products (catalogue), Quotations, and Settings. Dashboard and Customers &
      Suppliers are limited to ADMIN and MANAGER.
- [x] **Catalogue-only Products page** — The Add product form is hidden for
      employees; the product list remains visible, and restock/stock movement
      panels stay hidden.
- [x] **Recent sales limited** — The Sales page hides the recent-sales history
      from employees; only ADMIN and MANAGER see it.
- [x] **Editable customer/supplier details** — The People page Edit action
      updates name, phone, email, and KRA PIN through `update_person`, with an
      audit entry and no change to historical sale snapshots.
- [x] **Editable quotations** — Admins and managers can change a quotation's
      customer name and KRA PIN, add or remove products, and adjust quantities.
      Existing lines keep their price/name snapshots, totals and VAT are
      recalculated, and converted quotations are locked.
- [x] **Cash-flow ledger** — The Cash Flow page lists recorded expenses and
      manual cash movements for the selected period. Admins can edit either
      kind of entry; managers see the table read-only.
- [x] **Expense categories** — The manual cash-movement form offers the 21
      standard business expense categories when EXPENSE is selected (and a
      free-text category for INCOME). The Admin expense form and the cash-entry
      editor share the list, and Analytics → Expenses groups spending by those
      names and reports the categories with no spend for the period. The same
      section lists the period's entries; admins can edit an entry in place while
      managers see it read-only.
- [x] **Editable employees and branches** — Employees and branches on the Admin
      page can be edited by ADMIN and MANAGER; only admins can delete them.
      Managers can open the Admin page for these tables, with admin-only panels
      hidden. The duplicate expenses table was removed from the Admin page.
- [x] **Editable product prices** — The Products table has an Edit action for
      ADMIN and MANAGER covering selling price, buying price, VAT rate, and
      catalogue details. Stock, supplied quantity, and stock movements stay
      untouched, completed sales keep their price snapshots, and buying price
      remains hidden from roles without `cost.view`. Each change writes an
      `UPDATE_PRODUCT` audit entry with old and new values.
- [x] **Product deletion** — ADMIN and MANAGER can delete a product from the
      Products table with a confirmation that names any discarded stock. The
      API refuses when a sale, quotation, purchase order, or return refers to
      the product, and writes a `DELETE_PRODUCT` audit entry with the SKU, name,
      and units removed.
- [x] **Quotation deletion** — Roles with `records.delete` can delete a
      quotation from its history. Unprocessed quotes are removed with their
      lines; converted quotes keep the sale and receipt while the stale
      quotation link is cleared first. Deletions are audited
      (`DELETE_QUOTATION`).
- [x] **CSV data exports** — The Admin page has a **Data exports** panel with
      six downloads: cash flow, analytics, quotations, customers, suppliers,
      and products. Exports are built live from the database, follow the
      selected report periods, include every matching record (not just the
      300-row on-screen limit), escape spreadsheet-formula strings, write
      amounts in display units, hide buying price and cost figures without
      `cost.view`, and record an `EXPORT_CSV` audit entry per download.
- [x] **Server-published pages** — `role_pages()` supplies the allowed page ids at
      login and session refresh, and the browser re-checks page access.
- [x] **API enforcement** — Employees cannot add products, create suppliers, or
      list customers, suppliers, sales, or sale items.
- [x] **Test coverage** — `tests/phase5_smoke.php` (22 checks),
      `tests/people_smoke.php` (27 checks),
      `tests/quotation_edit_smoke.php` (47 checks),
      `tests/cashflow_smoke.php` (49 checks),
      `tests/staff_smoke.php` (52 checks),
      `tests/product_edit_smoke.php` (69 checks), and
      `tests/csv_export_smoke.php` (15 checks).

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
