# SAJI Hardware Web POS

Local web-based POS for **SAJI HARDWARE LTD** . It uses PHP, HTML, CSS, JavaScript, SQLite, and a small optional Python backup helper.

## Features Included

- Responsive touchscreen-friendly sales screen.
- Product catalog with SKU, barcode, image path, barcode image path, category, branch, warehouse, buying price, selling price, stock, and reorder level.
- Barcode scanner support through the search box. Most USB scanners work like a keyboard, so click the search field and scan.
- Cart, discount, cash/M-Pesa/card/voucher/credit/mixed payment methods, VAT-inclusive totals, sale hold, proforma print, checkout, stock deduction, and receipt printing.
- Branded receipts using `public/assets/saji-logo.png`.
- Admin, Manager, and Employee login roles.
- Admin user creation, employee records, branches, customers, suppliers, expenses, reports, sync queue, audit logs, and backups.
- SQLite database stored locally in `data/saji_pos.sqlite3`, with WAL mode for better safety and concurrency.
- Atomic checkout transaction so stock and sale records are not partially saved.
- KCB Buni STK Push payment folder with MySQL payment records, callback signature validation, and pending-payment states.

## Licensing, Security And Backups (Phase 1)

- **Licensing**: 14-day local trial, then activate with an offline signed key.
  The public key ships in `config.php`; the private signing key stays in
  `tools/keys/` and must never be copied to a customer machine.
- **Hybrid check-in (optional)**: set `LICENSE_SERVER_URL` in `.env` to enable a
  daily best-effort revocation check. Leave it blank for fully offline use.
- **Security**: CSRF protection on every mutating API call, login throttling,
  forced password change for default/temporary passwords, Content-Security-Policy
  and hardening headers, and server-side error logging in `data/logs/app.log`.
- **Backups**: automatic daily backup on first authenticated request, plus manual
  create/download/restore/delete from Reports & Backups. Restores keep a
  `pre_restore` safety copy first.
- **Portable build**: `powershell -ExecutionPolicy Bypass -File packaging\build-portable.ps1`
  creates `dist/SAJI-POS-<version>.zip` plus `dist/SHA256SUMS.txt`. Add `-BundlePhp`
  to produce `dist/SAJI-POS-<version>-with-php.zip` that includes the local PHP
  runtime. The signed `.exe` installer is the next packaging step (Phase 1.5).

### Issue a license

```powershell
php -c .\php-runtime.ini tools\make_license.php keygen
php -c .\php-runtime.ini tools\make_license.php issue --customer="Shop Name" --pin=P051234567X --plan=standard --days=365
```

Paste the printed key into **Settings → License**. `keygen` is run once; keep
`tools/keys/private.key` secret and out of the packaged build.

## Default Login

```text
Username: admin
Password: admin123
```

The app now forces a password change on first login. Set a new password of at
least 10 characters in Settings before real use.

## Run Locally

Install PHP for Windows from `https://windows.php.net/download/` or install XAMPP, Laragon, or WampServer.

From PowerShell:

```powershell
cd "C:\xamppnew3\htdocs\sajihardware"
php -c .\php.ini -S localhost:8000 router.php
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
saji_hardware_pos_web\data\backups
```

## Build As A Local App

Option 1: run it as a browser app with PHP built-in server. Use `run-pos.bat` or the router command above so both `public/` and the root `api/` folder are reachable.

Option 2: install XAMPP/Laragon and put this folder in the local web root.

Option 3: wrap it as a Windows desktop app with NativePHP, Electron, or WebView2. The simplest packaging path is:

1. Install PHP locally.
2. Create a shortcut or `.bat` file that runs `php -S localhost:8000 -t public`.
3. Open `http://localhost:8000` in Edge/Chrome app mode.

Example `run-pos.bat`:

```bat
cd /d "C:\xamppnew3\htdocs\sajihardware"
php -c "%~dp0php.ini" -S localhost:8000 -t public
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
  config.php               Company details and integration placeholders
  lib.php                  SQLite connection, schema, auth helpers, backups
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
php -c .\php.ini -S localhost:8000 router.php
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
