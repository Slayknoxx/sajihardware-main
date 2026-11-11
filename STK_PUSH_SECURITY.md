# STK Push Integration Security and Working Flow

## Overview

This project includes a working KCB Buni M-Pesa STK push flow for the Saji Hardware POS. The implementation follows the same structure as the standalone KCB demo app and is designed to:

- trigger an STK push to a customer phone
- store a pending payment record
- wait for the KCB callback
- mark the sale as paid only after a valid callback matches the pending payment
- protect callback access with a secret and validation checks

## Working Flow

1. The user selects `M-Pesa` in the POS checkout screen.
2. The frontend sends the sale ID, payment reference, phone number, and amount to `api/stk_push.php`.
3. The server validates the payload, phone number, and amount.
4. The server obtains an OAuth token from KCB using Consumer Key and Consumer Secret.
5. The server sends the STK push request to KCB with:
   - `phoneNumber`
   - `amount`
   - `invoiceNumber`
   - `callbackUrl`
   - `transactionDescription`
   - `sharedShortCode` or `orgShortCode`
6. If KCB accepts the request, the app saves a pending record and receives a `CheckoutRequestID`.
7. The frontend polls the payment status endpoint while waiting for the customer to complete the transaction.
8. Once the customer approves or rejects the prompt, KCB sends a callback to:
   - `/api/callback.php/<CALLBACK_SECRET>`
9. The callback handler validates the secret in the URL path, checks the payload, and updates the payment record.
10. The sale is only marked as paid if the callback matches the expected payment reference and amount.

## Key Files

- `api/stk_push.php` — sends STK push requests
- `api/callback.php` — validates callback and updates payment status
- `api/payment_status.php` — returns the pending/success/failure state
- `api/config.php` — KCB configuration and credentials
- `kcb_client.php` — KCB OAuth and STK push client
- `router.php` — routes `/api/callback.php/<secret>` correctly
- `public/assets/app.js` — POS frontend flow and polling logic

## Security Controls in Place

### 1. Secret-based callback validation
The callback URL includes a random secret in the path, for example:

```text
https://your-ngrok-domain.ngrok-free.app/api/callback.php/<CALLBACK_SECRET>
```

The server checks the path value against `CALLBACK_SECRET` using `hash_equals()` before processing the request.

### 2. Callback token protection
The application allows callback requests only when the expected secret or token matches. Requests without the valid token are rejected with `401`.

### 3. One-time pending payment matching
The callback is only accepted if it matches a real pending payment record. The code checks:

- payment reference
- checkout request ID
- amount
- phone
- valid result code

This prevents a fake callback from marking an unrelated sale as paid.

### 4. Status is not treated as paid unless it is fully matched
The POS does not mark a transaction as paid simply because KCB responded with `STK_SENT` or any intermediate state. The final sale is only completed when the callback data matches the pending payment record and the sale total is confirmed.

### 5. Raw callback logging
The callback endpoint logs the raw callback payload to `data/callbacks.log` for debugging and audit review.

### 6. Credential separation
Secrets are loaded from environment variables and not embedded in frontend JavaScript. The app avoids exposing KCB credentials, callback tokens, or internal payment settings in the public web UI.

### 7. PHP SSL certificate fix for XAMPP
The project uses the XAMPP certificate bundle from:

```text
C:/xampp/apache/bin/curl-ca-bundle.crt
```

This avoids the earlier cURL certificate-file errors when connecting to the KCB HTTPS endpoints.

### 8. Safe routing
The project router supports the KCB callback route format:

```text
/api/callback.php/<secret>
```

This ensures the secreted callback URL is handled properly instead of being treated as a missing route.

## Security Notes

- Keep `.env` values private.
- Use HTTPS for all callback traffic.
- Keep `CALLBACK_SECRET` long and random.
- Do not expose the payment API config in the public web directory.
- Test callbacks over ngrok before production deployment.
- Only accept KCB callbacks that match the pending payment record.

## Conclusion

The STK push implementation is built to work like a secure production-style KCB flow, with callback validation, pending-payment verification, and restricted callback access. The system only marks a sale as completed when the KCB response matches the actual transaction being processed.
