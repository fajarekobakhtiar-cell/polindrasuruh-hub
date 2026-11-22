<?php
/**
 * includes/midtrans_helper.php
 * Modul Integrasi Otomatisasi Pembayaran QRIS Midtrans & Gateway
 * Polindra Suruh
 */

if (!function_exists('getMidtransConfig')) {
    function getMidtransConfig(PDO $pdo): array {
        $cfg = [
            'mode'           => 'midtrans', // 'midtrans' atau 'manual'
            'server_key'     => 'SB-Mid-server-DemoTestPolindra123',
            'client_key'     => 'SB-Mid-client-DemoTestPolindra123',
            'is_production'  => false,
            'merchant_id'    => 'G123456789',
            'qris_admin'     => 'assets/img/qris.jpeg',
            'tarif_dasar'    => 5000,
            'jarak_dasar_km' => 1.0,
            'tarif_per_km'   => 2500,
        ];

        try {
            $stmt = $pdo->query("SELECT * FROM pengaturan_sistem WHERE id = 1 LIMIT 1");
            if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $cfg['mode']           = $row['payment_gateway_mode'] ?? 'midtrans';
                $cfg['server_key']     = !empty($row['midtrans_server_key']) ? $row['midtrans_server_key'] : $cfg['server_key'];
                $cfg['client_key']     = !empty($row['midtrans_client_key']) ? $row['midtrans_client_key'] : $cfg['client_key'];
                $cfg['is_production']  = ((int)($row['midtrans_is_production'] ?? 0) === 1);
                $cfg['merchant_id']    = $row['midtrans_merchant_id'] ?? $cfg['merchant_id'];
                $cfg['qris_admin']     = $row['qris_admin'] ?? $cfg['qris_admin'];
                $cfg['tarif_dasar']    = (int)($row['tarif_dasar'] ?? 5000);
                $cfg['jarak_dasar_km'] = (float)($row['jarak_dasar_km'] ?? 1.0);
                $cfg['tarif_per_km']   = (int)($row['tarif_per_km'] ?? 2500);
            }
        } catch (Exception $e) {}

        return $cfg;
    }
}

/**
 * Buat atau ambil QRIS dinamis untuk suatu pesanan
 */
if (!function_exists('generateQrisPesananMidtrans')) {
    function generateQrisPesananMidtrans(PDO $pdo, int $pesananId): array {
        $cfg = getMidtransConfig($pdo);

        // Ambil data pesanan & pelanggan
        $stmt = $pdo->prepare("
            SELECT p.*, u.nama AS nama_pelanggan, u.email AS email_pelanggan
            FROM pesanan p
            JOIN users u ON p.user_id = u.id
            WHERE p.id = ?
            LIMIT 1
        ");
        $stmt->execute([$pesananId]);
        $pesanan = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pesanan) {
            return [
                'success' => false,
                'message' => 'Pesanan tidak ditemukan'
            ];
        }

        $grossAmount = !empty($pesanan['total_tarif']) ? (int)$pesanan['total_tarif'] : 5000;
        if ($grossAmount < 1000) $grossAmount = 5000;

        // Jika pesanan sudah memiliki QRIS tersimpan, gunakan yang sudah ada
        if (!empty($pesanan['qris_url']) && !empty($pesanan['midtrans_order_id'])) {
            return [
                'success'      => true,
                'order_id'     => $pesanan['midtrans_order_id'],
                'gross_amount' => $grossAmount,
                'qr_url'       => $pesanan['qris_url'],
                'is_sandbox'   => !$cfg['is_production'],
                'mode'         => $cfg['mode']
            ];
        }

        $midtransOrderId = 'POLINDRA-' . $pesananId . '-' . time();
        $isLiveMidtransKey = (strpos($cfg['server_key'], 'SB-Mid-server-Demo') === false && strlen($cfg['server_key']) > 20);

        $qrImageUrl = '';

        // Coba request ke Midtrans Core API jika Server Key asli tersedia
        if ($isLiveMidtransKey) {
            $baseUrl = $cfg['is_production'] ? 'https://api.midtrans.com/v2' : 'https://api.sandbox.midtrans.com/v2';
            $payload = [
                'payment_type' => 'qris',
                'transaction_details' => [
                    'order_id'     => $midtransOrderId,
                    'gross_amount' => $grossAmount
                ],
                'customer_details' => [
                    'first_name' => $pesanan['nama_pelanggan'] ?: 'Pelanggan Polindra',
                    'email'      => $pesanan['email_pelanggan'] ?: 'user@polindra.ac.id'
                ],
                'qris' => [
                    'acquirer' => 'gopay'
                ]
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $baseUrl . '/charge');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Basic ' . base64_encode($cfg['server_key'] . ':')
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $res = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 || $httpCode === 201) {
                $json = json_decode($res, true);
                if (!empty($json['actions'])) {
                    foreach ($json['actions'] as $act) {
                        if ($act['name'] === 'generate-qr-code') {
                            $qrImageUrl = $act['url'];
                            break;
                        }
                    }
                }
                if (empty($qrImageUrl) && !empty($json['qr_string'])) {
                    $qrImageUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&margin=12&data=' . urlencode($json['qr_string']);
                }
            }
        }

        // Fallback ke QRIS resmi lokal Polindra Suruh jika API belum connect atau mode Sandbox
        if (empty($qrImageUrl)) {
            $qrImageUrl = 'assets/img/qris.jpeg';
        }

        // Simpan ke pesanan
        try {
            $upStmt = $pdo->prepare("UPDATE pesanan SET midtrans_order_id = ?, qris_url = ? WHERE id = ?");
            $upStmt->execute([$midtransOrderId, $qrImageUrl, $pesananId]);
        } catch (Exception $e) {}

        return [
            'success'      => true,
            'order_id'     => $midtransOrderId,
            'gross_amount' => $grossAmount,
            'qr_url'       => $qrImageUrl,
            'is_sandbox'   => !$cfg['is_production'],
            'mode'         => $cfg['mode']
        ];
    }
}

/**
 * Tandai pembayaran pesanan berhasil & diverifikasi otomatis
 */
if (!function_exists('verifikasiPembayaranSukses')) {
    function verifikasiPembayaranSukses(PDO $pdo, int $pesananId, string $transactionId = '', string $catatan = 'Verifikasi Otomatis Midtrans'): bool {
        try {
            $stmt = $pdo->prepare("
                UPDATE pesanan
                SET status = 'Diproses',
                    qris_diverifikasi = 1,
                    alasan_tolak_bayar = NULL,
                    waktu_bayar = NOW(),
                    midtrans_transaction_id = :trx_id
                WHERE id = :id AND status IN ('Menunggu Pembayaran', 'Menunggu')
            ");
            return $stmt->execute([
                'trx_id' => $transactionId ?: ('AUTO-' . time()),
                'id'     => $pesananId
            ]);
        } catch (Exception $e) {
            return false;
        }
    }
}
