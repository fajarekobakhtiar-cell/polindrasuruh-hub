<?php
// ===========================================================================
// WHATSAPP MESSAGE SENDER - POLINDRA SURUH
// ===========================================================================
require_once __DIR__ . '/wa_config.php';

/**
 * Generate kode OTP acak
 */
function generateOTP(): string {
    $min = (int) str_pad('1', OTP_LENGTH, '0');
    $max = (int) str_pad('', OTP_LENGTH, '9');
    return (string) random_int($min, $max);
}

/**
 * Mask nomor telepon untuk tampilan: 0812****7890
 */
function maskPhoneNumber(string $no): string {
    $clean = preg_replace('/[^0-9]/', '', $no);
    if (strlen($clean) >= 8) {
        return substr($clean, 0, 4) . str_repeat('*', max(0, strlen($clean) - 8)) . substr($clean, -4);
    }
    return $clean;
}

/**
 * Normalisasi nomor WA ke format 08xx
 */
function normalizeWaNumber(string $raw): string {
    $clean = preg_replace('/[^0-9]/', '', $raw);
    if (str_starts_with($clean, '62')) {
        return '0' . substr($clean, 2);
    } elseif (str_starts_with($clean, '8')) {
        return '0' . $clean;
    }
    return $clean;
}

/**
 * Convert nomor ke format internasional 628xxx (untuk API)
 */
function toInternationalFormat(string $no): string {
    $clean = preg_replace('/[^0-9]/', '', $no);
    if (str_starts_with($clean, '0')) {
        return '62' . substr($clean, 1);
    } elseif (str_starts_with($clean, '62')) {
        return $clean;
    }
    return '62' . $clean;
}

/**
 * Kirim pesan WhatsApp via provider API
 */
function sendWhatsAppMessage(string $target, string $message): array {
    $international = toInternationalFormat($target);

    // === DEV MODE: Jangan kirim pesan, langsung return sukses ===
    if (defined('WA_DEV_MODE') && WA_DEV_MODE === true) {
        return [
            'success'  => true,
            'dev_mode' => true,
            'message'  => 'DEV MODE: Pesan tidak terkirim ke WhatsApp.',
            'target'   => $international
        ];
    }

    $provider = defined('WA_PROVIDER') ? WA_PROVIDER : 'fonnte';

    switch ($provider) {
        case 'fonnte':
            return _sendViaFonnte($international, $message);

        case 'wablas':
            return _sendViaWablas($international, $message);

        default:
            return [
                'success'  => false,
                'message'  => 'Provider WhatsApp tidak dikenali: ' . $provider
            ];
    }
}

/**
 * Kirim OTP ke nomor WhatsApp
 */
function sendOTP(string $noWa, string $otpCode): array {
    $msg  = "🔐 *KODE OTP POLINDRA SURUH*\n\n";
    $msg .= "Kode verifikasi Anda:\n";
    $msg .= "*{$otpCode}*\n\n";
    $msg .= "⏰ Berlaku selama " . OTP_EXPIRY_MINUTES . " menit.\n";
    $msg .= "⚠️ Jangan bagikan kode ini kepada siapapun.\n\n";
    $msg .= "Jika Anda tidak melakukan pendaftaran, abaikan pesan ini.\n\n";
    $msg .= "— Polindra Suruh 🏍️";

    return sendWhatsAppMessage($noWa, $msg);
}

// ===========================================================================
// PROVIDER IMPLEMENTATIONS (INTERNAL)
// ===========================================================================

/**
 * Kirim via Fonnte API
 * Docs: https://fonnte.com/api
 */
function _sendViaFonnte(string $target, string $message): array {
    $postFields = [
        'target'      => $target,
        'message'     => $message,
        'countryCode' => '62'
    ];

    $response = null;
    $httpCode = 200;

    // 1. Coba menggunakan cURL jika tersedia
    if (function_exists('curl_init')) {
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL            => 'https://api.fonnte.com/send',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: ' . WA_API_TOKEN
            ],
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
    }

    // 2. Fallback otomatis ke file_get_contents / stream context jika cURL tidak ada atau gagal
    if (!$response) {
        $opts = [
            'http' => [
                'method'        => 'POST',
                'header'        => "Authorization: " . WA_API_TOKEN . "\r\n" .
                                   "Content-Type: application/x-www-form-urlencoded\r\n",
                'content'       => http_build_query($postFields),
                'timeout'       => 30,
                'ignore_errors' => true
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false
            ]
        ];

        $context = stream_context_create($opts);
        $response = @file_get_contents('https://api.fonnte.com/send', false, $context);
    }

    if (!$response) {
        return ['success' => false, 'message' => 'Gagal menghubungi server WhatsApp. Pastikan koneksi internet aktif.'];
    }

    $result = json_decode($response, true);

    if (is_array($result) && isset($result['status']) && $result['status'] === true) {
        return ['success' => true, 'message' => 'OTP berhasil dikirim via WhatsApp.', 'response' => $result];
    }

    return [
        'success'  => false,
        'message'  => 'Gagal mengirim OTP: ' . ($result['reason'] ?? $result['detail'] ?? 'Terjadi kesalahan pada layanan WhatsApp.'),
        'response' => $result
    ];
}

/**
 * Kirim via WaBlas API
 * Docs: https://wablas.com/api
 */
function _sendViaWablas(string $target, string $message): array {
    if (!function_exists('curl_init')) {
        return ['success' => false, 'message' => 'Ekstensi PHP cURL tidak tersedia.'];
    }

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL            => 'https://pati.wablas.com/api/send-message',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: ' . WA_API_TOKEN,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS     => json_encode([
            'phone'   => $target,
            'message' => $message
        ])
    ]);

    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($curl);
    curl_close($curl);

    if ($curlErr) {
        return ['success' => false, 'message' => 'Gagal menghubungi server WhatsApp: ' . $curlErr];
    }

    $result = json_decode($response, true);

    if ($httpCode === 200 && isset($result['status']) && $result['status'] === true) {
        return ['success' => true, 'message' => 'OTP berhasil dikirim via WhatsApp.', 'response' => $result];
    }

    return [
        'success'  => false,
        'message'  => 'Gagal mengirim OTP: ' . ($result['message'] ?? 'Unknown error'),
        'response' => $result
    ];
}
