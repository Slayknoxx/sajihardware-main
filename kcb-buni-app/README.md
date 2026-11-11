# KCB Buni STK Push App

A simple PHP + HTML/CSS/JS app: enter a phone number and amount, trigger an
M-Pesa/KCB STK push, and see a live "Success" or "Failed" screen once the
customer responds.

## What's included

```
kcb-buni-app/
├── index.html          Form + success/failure UI
├── transactions.html   Successful/unsuccessful transaction list
├── assets/
│   ├── style.css
│   └── script.js        Sends the request and polls for the result
├── api/
│   ├── stk_push.php      Starts the STK push (called by the browser)
│   ├── callback.php       KCB calls this when the customer responds
│   ├── status.php         Browser polls this to know when it's done
│   └── transactions.php   Lists recent transaction results
├── config.php            Loads your .env secrets
├── kcb_client.php         Talks to the KCB Buni API (token + STK push)
├── db.php                 Stores transactions in SQLite
├── router.php             Only used for local testing (php -S)
├── .htaccess              Apache security/config
├── .env.example           Copy to .env and fill in your real values
└── data/                  SQLite DB + callback logs get created here
```

---

## Step 1 — Get your KCB Buni API credentials

1. Go to **https://sandbox.buni.kcbgroup.com/devportal** and create a free
   developer account (email + verification).
2. Log in, go to **Apps / My Apps**, and create a new application.
3. Subscribe your app to the **M-PESA Express (STK Push)** API product in
   the catalog (sometimes listed as "Lipa na KCB" or "MM STK Push API").
4. Once subscribed, open your app's page — you'll see a **Consumer Key**
   and **Consumer Secret**. Copy both.
5. Note the sandbox test **short code** shown in the API documentation
   page for STK Push (a shared test paybill, e.g. `522522`). You'll use
   this while testing; you get your own paybill/till only after go-live.
6. That's it for sandbox — no paperwork needed to start testing.

**Going live later:** once you're ready for production, KCB requires a
signed request letter emailed to **buni@kcbgroup.com** (template is on the
same API documentation page in the dev portal) along with your real
paybill/till number. They will then switch your app to the production
base URL (`https://api.buni.kcbgroup.com`) and enable real transactions.

---

## Step 2 — Configure the app

1. Copy `.env.example` to `.env`:
   ```bash
   cp .env.example .env
   ```
2. Open `.env` and fill in:
   - `KCB_CONSUMER_KEY` / `KCB_CONSUMER_SECRET` — from Step 1.
   - `KCB_ORG_SHORT_CODE` — the sandbox short code from Step 1 (e.g. `522522`).
   - Leave `KCB_ORG_PASSKEY` blank unless KCB's docs tell you otherwise for
     your specific product.
   - Generate two random secrets:
     ```bash
     php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
     ```
     Use one for `CALLBACK_SECRET` and a different one for `APP_SECRET`.
   - Leave `CALLBACK_URL` for now — you'll fill it in once ngrok is running
     (Step 3 below).

**Never commit `.env` to git or send it to anyone.** `.gitignore` already
excludes it.

---

## Step 3 — Run the app locally

You need PHP 8+ with the `curl`, `pdo_sqlite`, and `json` extensions
(these ship with PHP by default on most systems).

```bash
cd kcb-buni-app
//memories/session/kcb-buni-project-structure.md```

Use the PHP bundled with XAMPP. A separate PHP installation may not have the
`pdo_sqlite` and `curl` extensions required by this app. If Apache is already
running in XAMPP, you can instead open `http://localhost/kcb-buni-app/`.

Visit `http://localhost:8000` — you should see the form (STK push won't
work yet until ngrok + `.env` are fully set, but the page should load).

---

## Step 4 — Expose your local server with ngrok (so KCB can reach your callback)

KCB's servers need to reach your `callback.php` over the public internet,
so your `localhost:8000` must be tunnelled through ngrok.

1. Install ngrok: https://ngrok.com/download (or `brew install ngrok`,
   `choco install ngrok`, etc.)
2. Sign up for a free ngrok account and connect your authtoken once:
   ```bash
   ngrok config add-authtoken YOUR_NGROK_AUTHTOKEN
   ```
3. With your PHP server still running on port 8000, open a **second**
   terminal and run:
   ```bash
   ngrok http 8000
   ```
4. Ngrok will print something like:
   ```
   Forwarding   https://abcd-12-34-56-78.ngrok-free.app -> http://localhost:8000
   ```
   Copy that `https://...ngrok-free.app` URL.
5. Update `.env`:
   ```
   CALLBACK_URL=https://abcd-12-34-56-78.ngrok-free.app/api/callback.php/PASTE_YOUR_CALLBACK_SECRET_HERE
   ```
   Use the same `CALLBACK_SECRET` value you generated in Step 2.
6. Restart `php -S` after editing `.env` so the new value is picked up.

Now open the app through the **ngrok URL** (not localhost) in your
browser, e.g. `https://abcd-12-34-56-78.ngrok-free.app`, so everything —
including your own page load — goes through the tunnel exactly like it
will in production.

---

## Step 5 — Test a payment

1. On the page, enter a phone number registered on M-Pesa (sandbox test
   numbers are provided in the KCB dev portal, e.g. `254708374149`) and
   an amount (e.g. `1`).
2. Click **Send STK Push**.
3. In the sandbox, you won't get a real prompt on a real phone — KCB's
   sandbox simulates the response and calls your `callback.php`
   automatically after a few seconds. Watch the terminal running
   `php -S` or check `data/callbacks.log` to see the callback arrive.
4. The page should automatically move to the **Success** or **Failed**
   screen once the callback updates the transaction.

If nothing happens after ~30–60 seconds:
- Check `data/callbacks.log` — if it's empty, KCB never reached your
  callback. Double check `CALLBACK_URL` in `.env` matches your current
  ngrok URL exactly (ngrok URLs change every time you restart ngrok on
  the free plan).
- Check your PHP server terminal for errors.
- Confirm your app is actually subscribed to the STK Push product in the
  KCB dev portal.

---

## Security notes (read before going live)

- **Secrets live only in `.env`**, which is git-ignored and blocked from
  direct web access by `.htaccess`. Never hard-code your Consumer
  Key/Secret in any file that could end up in a repo or browser.
- **The callback URL is protected by a long random secret** in its path
  (`CALLBACK_SECRET`). KCB's STK callback has no built-in signature, so
  this secret is your main defense — keep it private and rotate it if you
  ever suspect it leaked.
- **`callback.php` only updates transactions that already exist** in your
  own database (created by your own `stk_push.php` call), so a stranger
  who somehow found your callback URL still can't fabricate a fake
  "success" for a transaction they didn't request — they'd need a valid
  `CheckoutRequestID` that matches a real pending transaction.
- **Always serve the real app over HTTPS.** ngrok gives you HTTPS for
  free while testing; in production use a real TLS certificate (e.g. via
  Let's Encrypt).
- **Rate limiting** is applied per session/IP on `stk_push.php` (5
  requests/minute) to reduce abuse; consider stronger limits (e.g. Redis
  or a firewall rule) for production traffic.
- **Input is validated and normalised** server-side (phone format,
  amount range) — never trust the frontend alone.
- **Don't log full request/response bodies** anywhere they could be
  publicly exposed; `data/callbacks.log` and the SQLite DB should stay
  outside your public web root in production, or blocked via
  `.htaccess`/nginx config the same way `.env` is blocked here.
- **Use environment-specific credentials** — never reuse your production
  Consumer Key/Secret in a sandbox/testing setup, and vice versa.

---

## Going to production

1. Get your go-live approval from KCB (see Step 1).
2. Update `.env`:
   - `KCB_BASE_URL=https://api.buni.kcbgroup.com`
   - Your real production Consumer Key/Secret and paybill/till number.
   - `CALLBACK_URL` pointing at your real HTTPS domain, not ngrok.
3. Deploy the app behind a real web server (Apache/Nginx) with HTTPS,
   keep `.env` and `data/` outside the publicly served directory or
   blocked as shown in `.htaccess`.
4. Remove/disable `router.php` (only needed for local `php -S` testing).
