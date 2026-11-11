<?php
/**
 * kcb_client.php
 * Small client for the KCB Buni API: gets an OAuth token and sends STK push requests.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

class KcbApiException extends Exception {}

/**
 * Get an OAuth2 access token using client credentials (Consumer Key/Secret).
 */
function kcb_get_access_token(): string
{
    if (!function_exists('curl_init')) {
        throw new KcbApiException('PHP cURL extension is not enabled. Enable cURL in the PHP used by Apache.');
    }

    if (KCB_CONSUMER_KEY === '' || KCB_CONSUMER_SECRET === '') {
        throw new KcbApiException('KCB_CONSUMER_KEY / KCB_CONSUMER_SECRET are not set in .env');
    }

    $url = KCB_BASE_URL . '/token?grant_type=client_credentials';
    $basicAuth = base64_encode(KCB_CONSUMER_KEY . ':' . KCB_CONSUMER_SECRET);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => '',
        CURLOPT_HTTPHEADER => [
            "Authorization: Basic $basicAuth",
            "Content-Type: application/x-www-form-urlencoded",
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new KcbApiException("Could not reach KCB token endpoint: $error");
    }

    $data = json_decode($response, true);

    if ($httpCode !== 200 || !isset($data['access_token'])) {
        $desc = $data['fault']['message'] ?? $data['error_description'] ?? $response;
        throw new KcbApiException("Failed to get access token (HTTP $httpCode): $desc");
    }

    return $data['access_token'];
}

/**
 * Send an STK push request to a customer's phone.
 *
 * @param string $phone   Phone number in 2547XXXXXXXX format
 * @param string $amount  Amount as a string, e.g. "10"
 * @param string $invoice Your own reference/invoice number
 * @param string $description Short description shown internally
 * @return array Decoded JSON response from KCB
 */
function kcb_stk_push(string $phone, string $amount, string $invoice, string $description = 'Payment'): array
{
    $token = kcb_get_access_token();

    if (CALLBACK_URL === '' || !str_starts_with(CALLBACK_URL, 'https://')) {
        throw new KcbApiException('CALLBACK_URL must be set in .env and must be an HTTPS URL (use your ngrok https URL while testing).');
    }

    $url = KCB_BASE_URL . '/mm/api/request/1.0.0/stkpush';

    $payload = [
        'phoneNumber' => $phone,
        'amount' => $amount,
        'invoiceNumber' => $invoice,
        'callbackUrl' => CALLBACK_URL,
        'transactionDescription' => $description,
    ];

    if (KCB_SHARED_SHORTCODE) {
        $payload['sharedShortCode'] = KCB_ORG_SHORT_CODE;
    } else {
        $payload['orgShortCode'] = KCB_ORG_SHORT_CODE;
        if (KCB_ORG_PASSKEY !== '') {
            $payload['orgPassKey'] = KCB_ORG_PASSKEY;
        }
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $token",
            "Content-Type: application/json",
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new KcbApiException("Could not reach KCB STK push endpoint: $error");
    }

    $data = json_decode($response, true);

    if (!is_array($data)) {
        throw new KcbApiException("Unexpected response from KCB (HTTP $httpCode): $response");
    }

    return $data;
}
