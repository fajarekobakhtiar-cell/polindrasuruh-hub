<?php
session_start();
require_once __DIR__ . '/koneksi.php';

if (!isset($_SESSION['user_id']) || in_array(($_SESSION['role'] ?? ''), ['admin', 'driver'], true)) {
    header('Location: login.php?redirect=pesanan_saya');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: pesanan_saya.php?tab=riwayat');
    exit;
}

$userId        = (int) $_SESSION['user_id'];
$pesananId     = (int) ($_POST['pesanan_id'] ?? 0);
$driverUserId  = (int) ($_POST['driver_user_id'] ?? 0);

if ($pesananId <= 0 || $driverUserId <= 0) {
    header('Location: pesanan_saya.php?tab=riwayat&pesan=pilih_driver_gagal&pesan_error=' . urlencode('Pesanan atau driver belum dipilih.'));
    exit;
}

try {
    $pdo->beginTransaction();

    // Pastikan pesanan milik user ini dan memang sedang ditolak driver
    $stmtOrder = $pdo->prepare("
        SELECT id, status
        FROM pesanan
        WHERE id = :pesanan_id AND user_id = :user_id
        LIMIT 1
    ");
    $stmtOrder->execute([
        'pesanan_id' => $pesananId,
        'user_id'    => $userId,
    ]);
    $order = $stmtOrder->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        $pdo->rollBack();
        header('Location: pesanan_saya.php?tab=riwayat&pesan=pilih_driver_gagal&pesan_error=' . urlencode('Pesanan tidak ditemukan.'));
        exit;
    }

    if (($order['status'] ?? '') !== 'Driver Menolak') {
        $pdo->rollBack();
        header('Location: pesanan_saya.php?tab=riwayat&pesan=pilih_driver_gagal&pesan_error=' . urlencode('Pesanan ini tidak dalam status Driver Menolak.'));
        exit;
    }

    // Pastikan driver tujuan ada dan sedang aktif/online
    $stmtDrv = $pdo->prepare("
        SELECT u.id
        FROM users u
        LEFT JOIN drivers d ON d.user_id = u.id
        WHERE u.id = :driver_user_id
          AND u.role = 'driver'
          AND COALESCE(d.status_aktif, u.status_aktif, 0) = 1
        LIMIT 1
    ");
    $stmtDrv->execute(['driver_user_id' => $driverUserId]);
    if (!$stmtDrv->fetchColumn()) {
        $pdo->rollBack();
        header('Location: pesanan_saya.php?tab=riwayat&pesan=pilih_driver_gagal&pesan_error=' . urlencode('Driver yang dipilih sedang offline atau tidak tersedia.'));
        exit;
    }

    // Izinkan pengiriman ulang ke driver yang dipilih jika sebelumnya tercatat di pesanan_driver_ditolak
    $stmtDelTolak = $pdo->prepare("
        DELETE FROM pesanan_driver_ditolak
        WHERE pesanan_id = :pesanan_id AND driver_user_id = :driver_user_id
    ");
    $stmtDelTolak->execute([
        'pesanan_id'     => $pesananId,
        'driver_user_id' => $driverUserId,
    ]);

    // Assign pesanan ke driver baru & kembalikan status menunggu driver
    $stmtUpdate = $pdo->prepare("
        UPDATE pesanan
        SET driver_id = :driver_user_id,
            status = 'Menunggu Driver',
            created_at = NOW()
        WHERE id = :pesanan_id AND user_id = :user_id
    ");
    $stmtUpdate->execute([
        'driver_user_id' => $driverUserId,
        'pesanan_id'     => $pesananId,
        'user_id'        => $userId,
    ]);

    $pdo->commit();

    header('Location: pesanan_saya.php?tab=aktif&pesan=driver_dipilih_ulang');
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    header('Location: pesanan_saya.php?tab=riwayat&pesan=pilih_driver_gagal&pesan_error=' . urlencode('Gagal memilih ulang driver: ' . $e->getMessage()));
    exit;
}