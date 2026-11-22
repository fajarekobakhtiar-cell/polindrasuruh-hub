<?php
/**
 * api/midtrans_callback.php
 * Webhook Listener Midtrans Notification
 */

header('Content-Type: application/json');
require_once dirname(__DIR__) . '/koneksi.php';
require_once dirname(__DIR__) . '/includes/midtrans_helper.php';

$rawBody = file_get_contents('php://input');
$notif = json_decode($rawBody, true);

if (!$notif || empty($notif['order_id'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid notification body']);
    exit;
}

$cfg = getMidtransConfig($pdo);

$orderId           = $notif['order_id'];
$statusCode        = $notif['status_code'] ?? '';
$grossAmount       = $notif['gross_amount'] ?? '';
$signatureKey      = $notif['signature_key'] ?? '';
$transactionStatus = $notif['transaction_status'] ?? '';
$fraudStatus       = $notif['fraud_status'] ?? 'accept';
$transactionId     = $notif['transaction_id'] ?? ('MID-' . time());

// Validasi Signature Key jika server key asli terpasang
$expectedSignature = hash('sha512', $orderId . $statusCode . $grossAmount . $cfg['server_key']);
$isLiveKey = (strpos($cfg['server_key'], 'SB-Mid-server-Demo') === false);

if ($isLiveKey && $signatureKey !== $expectedSignature) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Signature verification failed']);
    exit;
}

// Cari pesanan berdasarkan midtrans_order_id
$stmt = $pdo->prepare("SELECT id, status, total_tarif FROM pesanan WHERE midtrans_order_id = ? LIMIT 1");
$stmt->execute([$orderId]);
$pesanan = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$pesanan) {
    // Coba ekstrak ID jika format POLINDRA-{id}-{time}
    if (preg_match('/^POLINDRA-(\d+)-/', $orderId, $matches)) {
        $pesananId = (int)$matches[1];
        $stmt = $pdo->prepare("SELECT id, status, total_tarif FROM pesanan WHERE id = ? LIMIT 1");
        $stmt->execute([$pesananId]);
        $pesanan = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

if (!$pesanan) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Pesanan not found']);
    exit;
}

// Tangani status transaksi pembayaran
if ($transactionStatus === 'settlement' || $transactionStatus === 'capture') {
    if ($fraudStatus === 'accept') {
        verifikasiPembayaranSukses($pdo, (int)$pesanan['id'], $transactionId, 'Webhook Midtrans Settlement');
    }
} elseif ($transactionStatus === 'pending') {
    // Biarkan tetap Menunggu Pembayaran
} elseif (in_array($transactionStatus, ['deny', 'cancel', 'expire'])) {
    try {
        $pdo->prepare("UPDATE pesanan SET qris_diverifikasi = 2, alasan_tolak_bayar = 'Pembayaran dibatalkan atau kedaluwarsa' WHERE id = ?")
            ->execute([(int)$pesanan['id']]);
    } catch (Exception $e) {}
}

echo json_encode(['status' => 'success', 'order_id' => $orderId, 'transaction_status' => $transactionStatus]);
