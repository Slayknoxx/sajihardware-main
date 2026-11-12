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
