<?php
declare(strict_types=1);

/**
 * Licensing: offline signed license key with optional hybrid check-in.
 *
 * A license key is "<base64url(payload-json)>.<base64url(ed25519-signature)>".
 * The application only embeds the PUBLIC key; the private key stays in tools/keys
 * and is used by tools/make_license.php to issue customer licenses.
 */

function license_public_key_raw(): string
{
    $encoded = trim((string)LICENSE_PUBLIC_KEY);
    if ($encoded === '') {
        return '';
    }
    $raw = base64_decode($encoded, true);
    return is_string($raw) ? $raw : '';
}

function license_machine_id(): string
{
    $installId = (string)(settings_get('install_id', '') ?? '');
    return hash('sha256', $installId . '|' . php_uname('n'));
}

function license_b64url_decode(string $value): string|false
{
    return base64_decode(strtr($value, '-_', '+/'), true);
}

function license_b64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

/**
 * Verify and decode a license key. Returns null when invalid or unverifiable.
 */
function license_decode(string $key): ?array
{
    $key = trim(preg_replace('/\s+/', '', $key) ?? '');
    if ($key === '' || !str_contains($key, '.')) {
        return null;
    }
    [$payloadB64, $signatureB64] = explode('.', $key, 2);
    $payloadJson = license_b64url_decode($payloadB64);
    $signature = license_b64url_decode($signatureB64);
    if ($payloadJson === false || $signature === false) {
        return null;
    }
    $publicKey = license_public_key_raw();
    if ($publicKey === '' || !function_exists('sodium_crypto_sign_verify_detached')) {
        return null;
    }
    if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
        return null;
    }
    if (!sodium_crypto_sign_verify_detached($signature, $payloadJson, $publicKey)) {
        return null;
    }
    $payload = json_decode($payloadJson, true);
    return is_array($payload) ? $payload : null;
}

/**
 * Current licensing state. Never throws.
 */
function license_status(?int $now = null): array
{
    $now ??= time();
    $installId = (string)(settings_get('install_id', '') ?? '');
    $status = [
        'state' => 'trial',
        'valid' => false,
        'plan' => 'trial',
        'customer' => '',
        'expires_at' => null,
        'days_left' => 0,
        'message' => '',
        'machine_id' => license_machine_id(),
        'install_id' => $installId,
        'trial_ends_at' => null,
        'edition' => '',
        'seats' => 0,
        'clock_tampered' => false,
    ];

    // Clock-rollback guard: a backwards jump larger than a day means the
    // trial/expiry clock is being rewound. Persist the flag so it survives
    // restarts; only activating a valid license clears it.
    try {
        $lastSeenRaw = (string)(settings_get('license_last_seen_at', '') ?? '');
        $lastSeen = $lastSeenRaw !== '' ? (int)strtotime($lastSeenRaw) : 0;
        if ((string)(settings_get('license_clock_tampered', '0') ?? '0') === '1'
            || ($lastSeen > 0 && $now < $lastSeen - 86400)) {
            settings_set('license_clock_tampered', '1');
            $status['state'] = 'clock_tampered';
            $status['valid'] = false;
            $status['clock_tampered'] = true;
            $status['message'] = 'The system clock appears to have been moved backwards. Correct the date and time, then enter a valid license key to continue.';
            return $status;
        }
        if ($lastSeen === 0) {
            settings_set('license_last_seen_at', gmdate('c', $now));
        } elseif ($now > $lastSeen + 60) {
            // Advance the anchor but cap each step at one day. Storing a large
            // forward jump as-is would make a later clock correction look like
            // a rollback, which would wrongly lock the application.
            settings_set('license_last_seen_at', gmdate('c', min($now, $lastSeen + 86400)));
        }
    } catch (Throwable $e) {
        app_log('Clock guard check failed: ' . $e->getMessage());
    }

    $storedKey = trim((string)(settings_get('license_key', '') ?? ''));
    if ((string)(settings_get('license_revoked', '0') ?? '0') === '1') {
        $status['state'] = 'revoked';
        $status['valid'] = false;
        $status['message'] = 'This license has been revoked. Contact your vendor.';
        return $status;
    }

    if ($storedKey !== '') {
        $payload = license_decode($storedKey);
        if ($payload === null) {
            $status['state'] = 'invalid';
            $status['valid'] = false;
            $status['message'] = 'The installed license key is not valid for this application.';
            return $status;
        }

        $status['plan'] = (string)($payload['plan'] ?? 'standard');
        $status['edition'] = (string)($payload['edition'] ?? '');
        $status['customer'] = (string)($payload['customer'] ?? '');
        $status['seats'] = (int)($payload['seats'] ?? 0);

        $boundMachine = trim((string)($payload['machine'] ?? ''));
        if ($boundMachine !== '' && !hash_equals($boundMachine, license_machine_id())) {
            $status['state'] = 'invalid';
            $status['valid'] = false;
            $status['message'] = 'This license is bound to a different machine.';
            return $status;
        }

        $expiresRaw = $payload['expires_at'] ?? null;
        $expires = is_string($expiresRaw) && $expiresRaw !== '' ? (int)strtotime($expiresRaw) : 0;
        $graceEnd = $expires > 0 ? $expires + (LICENSE_GRACE_DAYS * 86400) : 0;
        $status['expires_at'] = $expires > 0 ? gmdate('c', $expires) : null;

        if ($expires === 0) {
            $status['state'] = 'active';
            $status['valid'] = true;
            $status['days_left'] = 0;
            $status['message'] = 'Licensed (perpetual).';
            return $status;
        }

        $daysLeft = (int)ceil(($expires - $now) / 86400);
        $status['days_left'] = max(0, $daysLeft);
        if ($now <= $expires) {
            $status['state'] = 'active';
            $status['valid'] = true;
            $status['message'] = 'Licensed.';
        } elseif ($now <= $graceEnd) {
            $status['state'] = 'grace';
            $status['valid'] = true;
            $status['days_left'] = max(0, (int)ceil(($graceEnd - $now) / 86400));
            $status['message'] = 'License expired; running in grace period.';
        } else {
            $status['state'] = 'expired';
            $status['valid'] = false;
            $status['message'] = 'Your license has expired. Enter a renewed license key.';
        }
        return $status;
    }

    // No license installed: fall back to the local trial.
    $trialStartRaw = (string)(settings_get('trial_started_at', '') ?? '');
    $trialStart = $trialStartRaw !== '' ? (int)strtotime($trialStartRaw) : $now;
    if ($trialStart <= 0) {
        $trialStart = $now;
    }
    $trialEnd = $trialStart + (LICENSE_TRIAL_DAYS * 86400);
    $status['trial_ends_at'] = gmdate('c', $trialEnd);
    $status['plan'] = 'trial';

    if ($now <= $trialEnd) {
        $status['state'] = 'trial';
        $status['valid'] = true;
        $status['days_left'] = max(0, (int)ceil(($trialEnd - $now) / 86400));
        $status['message'] = 'Trial in progress.';
    } else {
        $status['state'] = 'trial_expired';
        $status['valid'] = false;
        $status['days_left'] = 0;
        $status['message'] = 'Your free trial has ended. Enter a license key to continue.';
    }
    return $status;
}

/**
 * Guard used by the API. Roles are not considered here; only license validity.
 */
function license_require(): array
{
    $status = license_status();
    if (!$status['valid']) {
        json_response([
            'error' => $status['message'] !== '' ? $status['message'] : 'A valid license is required.',
            'code' => 'LICENSE_REQUIRED',
            'license' => $status,
        ], 402);
    }
    return $status;
}

/**
 * Activate a license key for this installation.
 */
function license_activate(string $key): array
{
    $key = trim($key);
    if ($key === '') {
        throw new InvalidArgumentException('Paste a license key first.');
    }
    if (LICENSE_PUBLIC_KEY === '') {
        throw new RuntimeException('This build has no license public key configured.');
    }
    $payload = license_decode($key);
    if ($payload === null) {
        throw new InvalidArgumentException('That license key could not be verified.');
    }

    $boundMachine = trim((string)($payload['machine'] ?? ''));
    if ($boundMachine !== '' && !hash_equals($boundMachine, license_machine_id())) {
        throw new InvalidArgumentException('This license key is bound to a different machine.');
    }

    $expiresRaw = $payload['expires_at'] ?? null;
    $expires = is_string($expiresRaw) && $expiresRaw !== '' ? (int)strtotime($expiresRaw) : 0;
    if ($expires > 0 && time() > $expires + (LICENSE_GRACE_DAYS * 86400)) {
        throw new InvalidArgumentException('That license key has already expired.');
    }

    settings_set('license_key', $key);
    settings_set('license_revoked', '0');
    settings_set('license_clock_tampered', '0');
    settings_set('license_last_seen_at', gmdate('c'));
    audit('system', 'LICENSE_ACTIVATE', (string)($payload['customer'] ?? '') . ' / ' . (string)($payload['plan'] ?? ''));
    return license_status();
}

function license_deactivate(): void
{
    settings_set('license_key', '');
    settings_set('license_revoked', '0');
    audit('system', 'LICENSE_DEACTIVATE');
}

/**
 * Optional hybrid phone-home. Best effort: any failure is ignored so the
 * offline product keeps working.
 */
function license_checkin(bool $force = false): ?array
{
    if (LICENSE_SERVER_URL === '') {
        return null;
    }
    $lastRaw = (string)(settings_get('license_last_checkin', '') ?? '');
    $last = $lastRaw !== '' ? (int)strtotime($lastRaw) : 0;
    if (!$force && $last > 0 && (time() - $last) < (LICENSE_CHECKIN_HOURS * 3600)) {
        return null;
    }

    $payload = json_encode([
        'install_id' => (string)(settings_get('install_id', '') ?? ''),
        'machine_id' => license_machine_id(),
        'key_fingerprint' => hash('sha256', (string)(settings_get('license_key', '') ?? '')),
        'app_version' => APP_VERSION,
        'checked_in_at' => gmdate('c'),
    ]);

    $response = null;
    try {
        if (function_exists('curl_init')) {
            $ch = curl_init(rtrim(LICENSE_SERVER_URL, '/') . '/license/checkin');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 5,
            ]);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (is_string($body) && $code >= 200 && $code < 300) {
                $response = json_decode($body, true);
            }
        }
    } catch (Throwable $e) {
        app_log('License check-in failed: ' . $e->getMessage());
        return null;
    }

    settings_set('license_last_checkin', gmdate('c'));
    if (!is_array($response)) {
        return null;
    }
    if (!empty($response['revoked'])) {
        settings_set('license_revoked', '1');
        audit('system', 'LICENSE_REVOKED', 'Remote check-in');
    }
    if (!empty($response['license_key']) && is_string($response['license_key'])) {
        $decoded = license_decode($response['license_key']);
        if ($decoded !== null) {
            settings_set('license_key', $response['license_key']);
            settings_set('license_revoked', '0');
        }
    }
    return $response;
}
