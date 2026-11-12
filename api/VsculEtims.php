<?php
declare(strict_types=1);

/**
 * VSCU/ETIMS Integration Library
 * Handles KRA compliance, QR code generation, and secure receipt management
 * 
 * VSCU (Value Serial Compliance Unit) - KRA's system for tracking sales
 * ETIMS (Excise Tax Information Management System) - KRA's real-time tax system
 */

class VsculEtims
{
    // KRA Configuration
    private const KRA_BASE_URL = 'https://etims.kra.go.ke/api'; // Production URL
    private const KRA_SANDBOX_URL = 'https://sandbox.etims.kra.go.ke/api'; // Testing
    
    private string $pin;
    private string $serialNumber;
    private string $verificationKey;
    private string $deviceId;
    private bool $sandbox = false;

    public function __construct(string $pin, string $serialNumber, string $verificationKey, string $deviceId, bool $sandbox = false)
    {
        $this->pin = $pin;
        $this->serialNumber = $serialNumber;
        $this->verificationKey = $verificationKey;
        $this->deviceId = $deviceId;
        $this->sandbox = $sandbox;
    }

    /**
     * Generate VSCU QR Code for receipt
     * QR Code format: https://www.kra.go.ke/VSCU format
     */
    public function generateQRCode(array $receiptData): string
    {
        // QR Code Data Format for KRA:
        // PIN|ReceiptNo|DateTime|GrossAmount|TaxAmount|SignatureData
        
        $pin = $this->pin;
        $receiptNo = $receiptData['receipt_no'] ?? '';
        $dateTime = $receiptData['created_at'] ?? date('Y-m-d H:i:s');
        $grossAmount = (float)($receiptData['total'] ?? 0);
        $taxAmount = (float)($receiptData['tax'] ?? 0);
        
        // Create signature hash
        $signatureData = $this->createSignature([
            'pin' => $pin,
            'receipt_no' => $receiptNo,
            'datetime' => $dateTime,
            'gross_amount' => $grossAmount,
            'tax_amount' => $taxAmount
        ]);
        
        // Format QR Code data
        $qrData = implode('|', [
            $pin,
            $receiptNo,
            $dateTime,
            number_format($grossAmount, 2, '.', ''),
            number_format($taxAmount, 2, '.', ''),
            $signatureData
        ]);
        
        return $qrData;
    }

    /**
     * Create HMAC signature for receipt
     * Used for VSCU compliance and tamper detection
     */
    private function createSignature(array $data): string
    {
        ksort($data);
        $signString = implode('|', array_values($data));
        
        // Use HMAC-SHA256 for signing
        return hash_hmac('sha256', $signString, $this->verificationKey);
    }

    /**
     * Verify QR Code signature
     * Ensures receipt hasn't been tampered with
     */
    public function verifyQRCode(string $qrData): bool
    {
        $parts = explode('|', $qrData);
        if (count($parts) < 6) {
            return false;
        }
        
        [, $receiptNo, $dateTime, $grossAmount, $taxAmount, $signature] = $parts;
        
        $expectedSignature = $this->createSignature([
            'pin' => $this->pin,
            'receipt_no' => $receiptNo,
            'datetime' => $dateTime,
            'gross_amount' => $grossAmount,
            'tax_amount' => $taxAmount
        ]);
        
        return hash_equals($signature, $expectedSignature);
    }

    /**
     * Format receipt for ETIMS submission
     * Prepare data according to KRA specifications
     */
    public function formatForEtims(array $saleData, array $items): array
    {
        return [
            'sales_details' => [
                'invoice_number' => $saleData['receipt_no'],
                'invoice_date' => $saleData['created_at'],
                'invoice_type' => $saleData['invoice_type'] ?? 'NORMAL',
                'customer_name' => $saleData['customer_name'] ?? 'Walk-in Customer',
                'customer_pin' => $saleData['customer_pin'] ?? null,
                'payment_method' => strtoupper($saleData['payment_method'] ?? 'CASH'),
            ],
            'line_items' => array_map(function ($item) {
                return [
                    'description' => $item['product_name'] ?? 'Product',
                    'quantity' => (float)$item['quantity'],
                    'unit_price' => function_exists('money_minor_to_major') ? money_minor_to_major($item['unit_price']) : (float)$item['unit_price'],
                    'total_excluding_tax' => function_exists('money_minor_to_major') ? money_minor_to_major($item['total']) : (float)$item['total'],
                ];
            }, $items),
            'totals' => [
                'total_excluding_tax' => function_exists('money_minor_to_major') ? money_minor_to_major($saleData['subtotal']) : (float)$saleData['subtotal'],
                'tax_amount' => function_exists('money_minor_to_major') ? money_minor_to_major($saleData['tax']) : (float)$saleData['tax'],
                'total_including_tax' => function_exists('money_minor_to_major') ? money_minor_to_major($saleData['total']) : (float)$saleData['total'],
            ],
            'seller_details' => [
                'pin' => $this->pin,
                'serial_number' => $this->serialNumber,
            ],
        ];
    }

    /**
     * Generate unique ETIMS transaction ID
     * Format: PIN-YYYYMMDD-HHMM-RANDOM
     */
    public function generateEtimsTransactionId(): string
    {
        return sprintf(
            '%s-%s-%s',
            $this->pin,
            date('YmdHi'),
            bin2hex(random_bytes(4))
        );
    }

    /**
     * Generate unique ETIMS ID (VSCU format)
     * Format: SERIAL-TIMESTAMP-RANDOM
     */
    public function generateEtimsUniqueId(): string
    {
        return sprintf(
            '%s-%s-%s',
            $this->serialNumber,
            date('YmdHis'),
            bin2hex(random_bytes(3))
        );
    }

    /**
     * Encrypt receipt data for secure storage
     * Uses AES-256-GCM for FIPS compliance
     */
    public function encryptReceiptData(string $data): array
    {
        $cipher = 'aes-256-gcm';
        $iv = openssl_random_pseudo_bytes(12); // GCM requires 12 bytes IV
        $tag = '';
        
        $encrypted = openssl_encrypt(
            $data,
            $cipher,
            $this->verificationKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        
        return [
            'data' => base64_encode($encrypted),
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
        ];
    }

    /**
     * Decrypt receipt data
     */
    public function decryptReceiptData(array $encrypted): ?string
    {
        $cipher = 'aes-256-gcm';
        
        try {
            $decrypted = openssl_decrypt(
                base64_decode($encrypted['data']),
                $cipher,
                $this->verificationKey,
                OPENSSL_RAW_DATA,
                base64_decode($encrypted['iv']),
                base64_decode($encrypted['tag'])
            );
            
            return $decrypted;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Calculate VAT for a sale
     * Kenya VAT rate: 16%
     */
    public static function calculateVAT(float $amount, float $rate = 0.16): float
    {
        // VAT is calculated as: VAT = Amount / (1 + Rate) * Rate
        return round($amount / (1 + $rate) * $rate, 2);
    }

    /**
     * Generate KRA API authentication token
     * IMPORTANT: This is a placeholder - actual implementation requires KRA API credentials
     */
    public function getAuthToken(): string
    {
        // In production, you would:
        // 1. Call KRA API to get OAuth token
        // 2. Cache token with expiry
        // 3. Refresh when needed
        
        // For now, generate a secure request token
        return hash_hmac('sha256', $this->pin . date('YmdH'), $this->verificationKey);
    }

    /**
     * Format receipt for printing with VSCU QR compliance
     */
    public function formatReceiptForPrinting(array $saleData, array $items, string $qrCode): array
    {
        $subtotal = function_exists('money_minor_to_major') ? money_minor_to_major($saleData['subtotal']) : (float)$saleData['subtotal'];
        $tax = function_exists('money_minor_to_major') ? money_minor_to_major($saleData['tax']) : (float)$saleData['tax'];
        $total = function_exists('money_minor_to_major') ? money_minor_to_major($saleData['total']) : (float)$saleData['total'];
        $formattedItems = array_map(static function (array $item): array {
            if (function_exists('money_minor_to_major')) {
                foreach (['unit_price', 'total'] as $field) {
                    if (isset($item[$field])) {
                        $item[$field] = money_minor_to_major($item[$field]);
                    }
                }
            }
            return $item;
        }, $items);
        
        return [
            'receipt_number' => $saleData['receipt_no'],
            'date_time' => $saleData['created_at'],
            'cashier' => $saleData['cashier_name'],
            'customer' => $saleData['customer_name'],
            'items' => $formattedItems,
            'subtotal' => $subtotal,
            'vat_amount' => $tax,
            'vat_rate' => (float)($saleData['vat_rate'] ?? 0.16) * 100,
            'total_amount' => $total,
            'payment_method' => $saleData['payment_method'],
            'amount_paid' => function_exists('money_minor_to_major') ? money_minor_to_major($saleData['amount_paid']) : (float)$saleData['amount_paid'],
            'change_due' => function_exists('money_minor_to_major') ? money_minor_to_major($saleData['change_due']) : (float)$saleData['change_due'],
            'qr_code' => $qrCode,
            'etims_status' => $saleData['etims_status'] ?? 'PENDING',
            'etims_unique_id' => $saleData['etims_unique_id'] ?? 'Not yet assigned',
        ];
    }
}
