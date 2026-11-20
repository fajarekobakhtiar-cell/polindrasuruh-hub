<?php
header('Content-Type: application/json; charset=utf-8');

session_start();
require_once dirname(__DIR__) . '/koneksi.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'driver') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Akses ditolak.']);
    exit;
}

$driverUserId = (int)$_SESSION['user_id'];
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

$lat = isset($data['lat']) ? (float)$data['lat'] : (isset($_POST['lat']) ? (float)$_POST['lat'] : null);
$lng = isset($data['lng']) ? (float)$data['lng'] : (isset($_POST['lng']) ? (float)$_POST['lng'] : null);

if ($lat === null || $lng === null || abs($lat) > 90 || abs($lng) > 180) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Koordinat tidak valid.']);
    exit;
}

try {
    // Update lokasi di tabel drivers
    $stmt = $pdo->prepare("UPDATE drivers SET lat = :lat, lng = :lng WHERE user_id = :user_id");
    $stmt->execute([
        'lat' => $lat,
        'lng' => $lng,
        'user_id' => $driverUserId
    ]);

    // Update juga pickup_lat di pesanan aktif yang sedang dijalankan driver ini
    $stmtOrder = $pdo->prepare("
        UPDATE pesanan 
        SET pickup_lat = :lat, pickup_lng = :lng 
        WHERE driver_id = :driver_id 
          AND status IN ('Menunggu Driver', 'Menunggu Persetujuan Driver', 'Menunggu Pembayaran', 'Diproses', 'Dalam Perjalanan')
    ");
    $stmtOrder->execute([
        'lat' => $lat,
        'lng' => $lng,
        'driver_id' => $driverUserId
    ]);

    echo json_encode([
        'status' => 'success',
        'message' => 'Lokasi driver berhasil diperbarui.',
        'data' => ['lat' => $lat, 'lng' => $lng]
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Gagal memperbarui lokasi: ' . $e->getMessage()]);
}
