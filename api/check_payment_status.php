<?php
/**
 * api/check_payment_status.php
 * Polling status pembayaran otomatis untuk halaman Pelanggan (pesanan_saya.php)
 */

session_start();
header('Content-Type: application/json');

require_once dirname(__DIR__) . '/koneksi.php';
require_once dirname(__DIR__) . '/includes/midtrans_helper.php';

$userId = $_SESSION['user_id'] ?? null;
$pesananId = (int)($_GET['pesanan_id'] ?? 0);
$doSimulasi = isset($_GET['simulasi']) && $_GET['simulasi'] === '1';

if (!$pesananId) {
    echo json_encode(['success' => false, 'message' => 'pesanan_id required']);
    exit;
}

$stmt = $pdo->prepare("SELECT id, user_id, status, total_tarif, qris_diverifikasi, alasan_tolak_bayar FROM pesanan WHERE id = ? LIMIT 1");
$stmt->execute([$pesananId]);
$pesanan = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$pesanan) {
    echo json_encode(['success' => false, 'message' => 'Pesanan tidak ditemukan']);
    exit;
}

// Fitur Simulasi Pembayaran Sandbox (Bisa langsung dipicu untuk testing)
if ($doSimulasi) {
    $cfg = getMidtransConfig($pdo);
    if (!$cfg['is_production']) {
        verifikasiPembayaranSukses($pdo, $pesananId, 'SIMULASI-PAY-' . time(), 'Simulasi Pembayaran Sandbox');
        echo json_encode([
            'success'     => true,
            'paid'        => true,
            'simulated'   => true,
            'status'      => 'Diproses',
            'message'     => 'Simulasi pembayaran sukses terverifikasi otomatis!'
        ]);
        exit;
    }
}

$isPaid = ((int)($pesanan['qris_diverifikasi'] ?? 0) === 1) || in_array($pesanan['status'], ['Diproses', 'Dalam Perjalanan', 'Selesai'], true);
$isRejected = ((int)($pesanan['qris_diverifikasi'] ?? 0) === 2);

echo json_encode([
    'success'      => true,
    'pesanan_id'   => $pesananId,
    'status'       => $pesanan['status'],
    'paid'         => $isPaid,
    'rejected'     => $isRejected,
    'alasan_tolak' => $pesanan['alasan_tolak_bayar'] ?? null
]);
