<?php
// ===========================================================================
// API: VERIFIKASI KODE OTP & BUAT AKUN
// POST /api/verify_otp.php
// Body: { otp_token, otp_code }
// Response: { success, message, redirect? }
// ===========================================================================
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../koneksi.php';

// Hanya terima POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$otpToken = trim($_POST['otp_token'] ?? '');
$otpCode  = trim($_POST['otp_code'] ?? '');

// Jika token kosong di POST, cek fallback dari SESSION
if ($otpToken === '' && !empty($_SESSION['otp_token'])) {
    $otpToken = $_SESSION['otp_token'];
}

// 1. Validasi input
if ($otpToken === '' || $otpCode === '') {
    echo json_encode(['success' => false, 'message' => 'Token dan kode OTP wajib diisi. Silakan daftar ulang.']);
    exit;
}

if (!preg_match('/^[0-9]{6}$/', $otpCode)) {
    echo json_encode(['success' => false, 'message' => 'Kode OTP harus 6 digit angka.']);
    exit;
}

// 2. Cari OTP di database (dengan pengecekan expired langsung dari database)
$stmtOtp = $pdo->prepare("
    SELECT id, no_wa, username, password_hash, otp_code, attempts, expired_at, verified,
           (expired_at < NOW()) AS is_expired
    FROM otp_verification
    WHERE otp_token = ?
    LIMIT 1
");
$stmtOtp->execute([$otpToken]);
$otpRecord = $stmtOtp->fetch();

if (!$otpRecord) {
    echo json_encode(['success' => false, 'message' => 'Sesi OTP tidak ditemukan atau sudah kadaluarsa. Silakan klik "Ubah nomor / data" untuk kirim ulang.']);
    exit;
}

// 3. Cek apakah sudah diverifikasi
if ((int) $otpRecord['verified'] === 1) {
    echo json_encode(['success' => false, 'message' => 'OTP sudah diverifikasi sebelumnya. Silakan login.']);
    exit;
}

// 4. Cek apakah sudah expired
if ((int) $otpRecord['is_expired'] === 1) {
    echo json_encode(['success' => false, 'message' => 'Kode OTP sudah kadaluarsa (lebih dari 5 menit). Silakan kirim ulang OTP baru.', 'expired' => true]);
    exit;
}

// 5. Cek jumlah percobaan
$maxAttempts = defined('OTP_MAX_ATTEMPTS') ? OTP_MAX_ATTEMPTS : 5;
if ((int) $otpRecord['attempts'] >= $maxAttempts) {
    echo json_encode(['success' => false, 'message' => 'Terlalu banyak percobaan salah. Silakan kirim ulang OTP baru.', 'expired' => true]);
    exit;
}

// 6. Increment percobaan
$pdo->prepare("UPDATE otp_verification SET attempts = attempts + 1 WHERE id = ?")->execute([$otpRecord['id']]);

// 7. Verifikasi kode OTP
if ($otpCode !== $otpRecord['otp_code']) {
    $attemptsLeft = $maxAttempts - ((int) $otpRecord['attempts'] + 1);
    echo json_encode([
        'success' => false,
        'message' => "Kode OTP salah. Sisa percobaan: {$attemptsLeft}."
    ]);
    exit;
}

// === OTP BENAR! Buat akun pengguna ===
try {
    $pdo->beginTransaction();

    // 8. Cek ulang apakah nomor/username masih tersedia (race condition protection)
    $stmtCek = $pdo->prepare("SELECT id FROM users WHERE no_wa = ? OR username = ? LIMIT 1");
    $stmtCek->execute([$otpRecord['no_wa'], $otpRecord['username']]);
    if ($stmtCek->fetch()) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Nomor WhatsApp atau username sudah terdaftar. Silakan login.']);
        exit;
    }

    // 9. Insert user baru
    $stmtUser = $pdo->prepare("
        INSERT INTO users (nama, username, no_wa, email, password, role)
        VALUES (?, ?, ?, NULL, ?, 'user')
    ");
    $stmtUser->execute([
        $otpRecord['username'],
        $otpRecord['username'],
        $otpRecord['no_wa'],
        $otpRecord['password_hash']
    ]);

    // 10. Tandai OTP sebagai verified
    $pdo->prepare("UPDATE otp_verification SET verified = 1 WHERE id = ?")->execute([$otpRecord['id']]);

    // 11. Bersihkan OTP lama untuk nomor ini
    $pdo->prepare("DELETE FROM otp_verification WHERE no_wa = ? AND verified = 0")->execute([$otpRecord['no_wa']]);

    $pdo->commit();

    echo json_encode([
        'success'  => true,
        'message'  => 'Registrasi berhasil! Nomor WhatsApp Anda telah terverifikasi.',
        'redirect' => 'login.php?daftar=berhasil'
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();

    if ($e->getCode() === '23000') {
        echo json_encode(['success' => false, 'message' => 'Nomor WhatsApp atau username sudah terdaftar.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Registrasi gagal. Silakan coba lagi.']);
    }
}
