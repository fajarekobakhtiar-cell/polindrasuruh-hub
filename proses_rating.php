<?php
session_start();
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/includes/fitur_helper.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') === 'admin' || ($_SESSION['role'] ?? '') === 'driver') {
    header('Location: login.php?redirect=pesanan_saya');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: pesanan_saya.php?tab=riwayat');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$pesananId = (int) ($_POST['pesanan_id'] ?? 0);
$rating = (int) ($_POST['rating'] ?? 0);
$ulasan = trim((string) ($_POST['ulasan'] ?? ''));

$result = simpanRatingDriver($pdo, $pesananId, $userId, $rating, $ulasan);

if ($result['ok']) {
    header('Location: pesanan_saya.php?tab=riwayat&pesan=rating_sukses');
} else {
    header('Location: pesanan_saya.php?tab=riwayat&pesan=rating_gagal&pesan_error=' . urlencode($result['message']));
}
exit;
