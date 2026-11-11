# KCB Buni STK Push Project Structure

## Overview
Simple PHP + HTML/CSS/JS app that triggers M-Pesa STK push payments via KCB Buni API. Stores transaction state in SQLite and waits for KCB's callback before marking success/failure.

## Directory Structure
```
kcb-buni-app/
├── index.html                 # Payment form UI + success/failure screens
├── transactions.html          # List of recent transactions
├── config.php                 # Loads .env, defines KCB constants
├── kcb_client.php            # KCB API client (token + STK push)
├── db.php                    # SQLite wrapper for transactions
├── router.php                # URL routing for local php -S dev server
├── .htaccess                 # Apache security config (blocks .env access)
├── .env                      # Secrets (git-ignored)
├── .env.example              # Template for .env
├── README.md                 # Setup & deployment guide
│
├── api/
│   ├── stk_push.php         # POST endpoint: triggers STK push, stores pending tx
│   ├── callback.php         # Receives KCB callback after user responds, updates tx
│   ├── status.php           # GET endpoint: frontend polls this for payment status
│   └── transactions.php     # GET endpoint: lists recent transactions
│
├── assets/
│   ├── script.js            # Browser logic (form submit, polling, UI state)
│   └── style.css            # Styling
│
└── data/
    ├── transactions.sqlite  # SQLite DB (created on first run)
    └── callbacks.log        # Raw callback JSON for debugging
```

## Core Integration Flow

### 1. Frontend sends payment request
```
User enters phone + amount → form submit → POST /api/stk_push.php
```

### 2. Backend triggers KCB STK push
```
stk_push.php:
  - Validate phone (254 format)
  - Validate amount (1-150,000)
  - Call kcb_get_access_token() → get OAuth token
  - Call kcb_stk_push() → send STK request to KCB
  - Store PENDING transaction in SQLite
  - Return checkoutRequestId to frontend
```

### 3. Frontend polls for result
```
Frontend: setInterval → GET /api/status.php?checkoutRequestId=...
  - Every 3 seconds
  - Max 40 attempts (~2 minutes)
  - Stops when status changes to SUCCESS or FAILED
```

### 4. KCB calls callback
```
KCB (after user enters PIN or timeout):
  - POST /api/callback.php/{CALLBACK_SECRET}
  - Backend validates secret in URL path
  - Extracts CheckoutRequestID, ResultCode, MpesaReceiptNumber
  - Updates matching transaction in SQLite
  - Status becomes SUCCESS (code 0) or FAILED (code ≠ 0)
```

### 5. Frontend shows result
```
Browser detects status change → stops polling → shows success/failure screen
```

## Key Files & Functions

### config.php
- Loads `.env` file with `load_env()`
- Defines KCB constants: `KCB_CONSUMER_KEY`, `KCB_CONSUMER_SECRET`, `KCB_BASE_URL`, etc.
- Sets security headers (X-Content-Type-Options, X-Frame-Options, etc.)
- Handles CORS if `ALLOWED_ORIGIN` is set

### kcb_client.php
- `kcb_get_access_token()`: Gets OAuth2 token using Consumer Key/Secret
- `kcb_stk_push()`: Builds payload and sends STK request to KCB

**Payload shape for shared shortcode mode:**
```php
[
  'phoneNumber' => '2547XXXXXXXX',
  'amount' => '100',
  'invoiceNumber' => 'INV...',
  'callbackUrl' => 'https://...',
  'transactionDescription' => 'Payment via app',
  'sharedShortCode' => '522533',  // Only if KCB_SHARED_SHORTCODE = true
]
```

**Payload shape for own shortcode mode:**
```php
[
  'phoneNumber' => '2547XXXXXXXX',
  'amount' => '100',
  'invoiceNumber' => 'INV...',
  'callbackUrl' => 'https://...',
  'transactionDescription' => 'Payment via app',
  'orgShortCode' => 'YOUR_SHORTCODE',  // Only if KCB_SHARED_SHORTCODE = false
  'orgPassKey' => 'YOUR_PASSKEY',      // Optional
]
```

### db.php
- `get_db()`: Returns PDO singleton (SQLite)
- `create_transaction()`: Insert PENDING transaction
- `update_transaction_from_callback()`: Update with callback results
- `get_transaction()`: Fetch by CheckoutRequestID
- `list_transactions()`: List all (optional status filter)

**Schema:**
```sql
CREATE TABLE transactions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  merchant_request_id TEXT,
  checkout_request_id TEXT UNIQUE,
  phone_number TEXT NOT NULL,
  amount TEXT NOT NULL,
  invoice_number TEXT,
  status TEXT DEFAULT 'PENDING',  -- PENDING | SUCCESS | FAILED
  result_code TEXT,
  result_desc TEXT,
  mpesa_receipt TEXT,
  raw_callback TEXT,
  created_at TEXT,
  updated_at TEXT
)
```

### api/stk_push.php
- Validates phone format (0712... → 2547... format normalization)
- Validates amount (1-150,000)
- Rate limits: 5 requests per minute per IP
- Calls `kcb_stk_push()` and flattens response (handles multiple response formats)
- Returns error if `ResponseCode ≠ 0` or missing `CheckoutRequestID`

### api/callback.php
- Validates `CALLBACK_SECRET` in URL path using `hash_equals()`
- Logs raw JSON to `data/callbacks.log`
- Extracts: `CheckoutRequestID`, `ResultCode`, `ResultDesc`, `MpesaReceiptNumber`
- Only updates transactions that already exist in DB (security check)
- Returns 200 OK so KCB doesn't retry

### api/status.php
- Returns transaction status + amount + receipt + timestamps
- Masks phone number (shows only last 4 digits)

### assets/script.js
- Form submission → fetch `/api/stk_push.php`
- On success: show pending card, start polling
- Poll loop: every 3s, check `/api/status.php?checkoutRequestId=...`
- On SUCCESS: show receipt
- On FAILED: show error reason
- Max poll attempts: 40 (~2 min timeout)

## .env Variables

```env
# KCB credentials
KCB_CONSUMER_KEY=...
KCB_CONSUMER_SECRET=...
KCB_BASE_URL=https://uat.buni.kcbgroup.com  # sandbox

# Merchant config
KCB_ORG_SHORT_CODE=522533
KCB_ORG_PASSKEY=  # empty for shared shortcode
KCB_SHARED_SHORTCODE=true

# Callback security
CALLBACK_SECRET=<long random hex string>
CALLBACK_URL=https://<ngrok-url>/api/callback.php/<CALLBACK_SECRET>

# App security
APP_SECRET=<long random hex string>
ALLOWED_ORIGIN=  # optional, for CORS
```

## Common Issues & Fixes

### "Merchant does not exist" (code 1)
- Check `KCB_ORG_SHORT_CODE` matches KCB portal assignment
- If using own shortcode, set `KCB_SHARED_SHORTCODE=false`
- Verify credentials are for the same environment (sandbox vs production)

### cURL extension not loaded
- Use XAMPP PHP binary directly: `C:\xamppnew3\php\php.exe`
- NOT the system `php` command

### Callback never arrives
- Verify `CALLBACK_URL` in `.env` matches current ngrok URL
- Check callback secret in URL path
- Ensure app is subscribed to STK Push product in KCB portal

## Porting to Another App

**Reusable components:**
1. `config.php` → loads `.env`
2. `kcb_client.php` → OAuth + STK request logic
3. `db.php` → transaction storage (can adapt to any DB)
4. `api/stk_push.php` → payment trigger endpoint
5. `api/callback.php` → callback handler
6. `api/status.php` → status query endpoint

**For non-PHP apps:**
- Implement the same functions in your framework
- Keep the same `.env` variable names
- Maintain the same SQLite schema (or equivalent DB)
- Use same OAuth + STK payload logic

**For Node/Express, Laravel, etc.:**
- Adapter: `kcb_get_access_token()` → `getKcbToken()`
- Adapter: `kcb_stk_push()` → `initiateKcbPush()`
- Use framework's HTTP client (axios, fetch, http module, etc.)
