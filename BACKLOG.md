# Backlog

Deferred work for SAJI Hardware POS. Everything here is still open.

## Authentication / accounts

- [ ] **Admin password reset (any user)**
  - Admin can reset another user's password from **Admin → Employee accounts**.
  - Generate a strong temporary password (or accept one), store only its hash.
  - Set `must_change_password = 1` on the target user so they must choose a new
    password at next login.
  - Invalidate the target user's active sessions (session version/token bump or
    session table) so a reset logs them out.
  - Write an `audit_logs` entry (`PASSWORD_RESET`) with actor and target.
  - No email dependency; the admin reads/copies the temporary password once.

- [ ] **Break-glass admin recovery (CLI)**
  - A command-line script (e.g. `tools/reset_password.php --user=admin`) that
    works even when no one can log in.
  - Resets the password hash, sets `must_change_password = 1`, clears
    `login_attempts` lockouts, and prints confirmation.
  - Must not require the web server or a valid session.

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

## Tests

- [ ] **Automated HTTP test in CI**
  - `tests/http_smoke.ps1` needs a running server on port 8011. Add a wrapper
    that starts/stops the server so the suite can run unattended.

## Completed in Phase 1 (reference)

- Server-side forced password change (`403 PASSWORD_CHANGE_REQUIRED`).
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
