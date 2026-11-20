<?php
// ===========================================================================
// API: KIRIM KODE OTP KE WHATSAPP
// POST /api/send_otp.php
// Body: { no_wa, username, password }
// Response: { success, message, token?, masked_wa?, dev_otp? }
// ===========================================================================
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../includes/wa_sender.php';

// Hanya terima POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

// Ambil input
$rawWa   = trim($_POST['no_wa'] ?? '');
$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

// 1. Validasi input
if ($rawWa === '' || $username === '' || $password === '') {
    echo json_encode(['success' => false, 'message' => 'Semua kolom wajib diisi.']);
    exit;
}

// 2. Normalisasi nomor WA
$cleanWa = normalizeWaNumber($rawWa);

if (!preg_match('/^08[1-9][0-9]{7,11}$/', $cleanWa)) {
    echo json_encode(['success' => false, 'message' => 'Nomor WhatsApp tidak valid. Masukkan nomor HP aktif (contoh: 081234567890).']);
    exit;
}

// 3. Validasi username
if (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
    echo json_encode(['success' => false, 'message' => 'Username hanya boleh huruf, angka, dan underscore (3-30 karakter).']);
    exit;
}

// 4. Validasi password
if (strlen($password) < 6) {
    echo json_encode(['success' => false, 'message' => 'Password minimal 6 karakter.']);
    exit;
}

// 5. Cek apakah nomor WA sudah terdaftar di users
$wa62 = toInternationalFormat($cleanWa);
$stmtCekWa = $pdo->prepare("SELECT id FROM users WHERE no_wa = ? OR no_wa = ? LIMIT 1");
$stmtCekWa->execute([$cleanWa, $wa62]);
if ($stmtCekWa->fetch()) {
    echo json_encode(['success' => false, 'message' => 'Nomor WhatsApp ini sudah terdaftar. Silakan login.']);
    exit;
}

// 6. Cek apakah username sudah dipakai
$stmtCekUser = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
$stmtCekUser->execute([$username]);
$stmtCekAdmin = $pdo->prepare("SELECT id FROM admin WHERE username = ? LIMIT 1");
$stmtCekAdmin->execute([$username]);
if ($stmtCekUser->fetch() || $stmtCekAdmin->fetch()) {
    echo json_encode(['success' => false, 'message' => "Username '$username' sudah digunakan. Silakan pilih username lain."]);
    exit;
}

// 7. Rate limiting: Cek jumlah OTP yang dikirim ke nomor ini dalam 1 jam terakhir
$stmtRate = $pdo->prepare(
    "SELECT COUNT(*) FROM otp_verification 
     WHERE no_wa = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
);
$stmtRate->execute([$cleanWa]);
$sentCount = (int) $stmtRate->fetchColumn();

if ($sentCount >= OTP_MAX_SEND_PER_HOUR) {
    echo json_encode(['success' => false, 'message' => 'Terlalu banyak percobaan. Coba lagi dalam 1 jam.']);
    exit;
}

// 8. Cooldown: Cek apakah OTP terakhir dikirim kurang dari 60 detik lalu
$stmtCooldown = $pdo->prepare(
    "SELECT created_at FROM otp_verification 
     WHERE no_wa = ? ORDER BY id DESC LIMIT 1"
);
$stmtCooldown->execute([$cleanWa]);
$lastSent = $stmtCooldown->fetchColumn();

if ($lastSent) {
    $elapsed = time() - strtotime($lastSent);
    if ($elapsed < OTP_COOLDOWN_SECONDS) {
        $remaining = OTP_COOLDOWN_SECONDS - $elapsed;
        echo json_encode(['success' => false, 'message' => "Tunggu {$remaining} detik sebelum mengirim ulang OTP.", 'cooldown' => $remaining]);
        exit;
    }
}

// 9. Generate OTP & token
$otpCode = generateOTP();
$otpToken = bin2hex(random_bytes(16));
$passwordHash = password_hash($password, PASSWORD_DEFAULT);

// 10. Simpan ke database dengan expired_at menggunakan waktu database (+5 menit)
$stmtInsert = $pdo->prepare("
    INSERT INTO otp_verification (otp_token, no_wa, username, password_hash, otp_code, expired_at)
    VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))
");
$stmtInsert->execute([$otpToken, $cleanWa, $username, $passwordHash, $otpCode, OTP_EXPIRY_MINUTES]);

// Simpan token ke sesi PHP juga sebagai cadangan
$_SESSION['otp_token'] = $otpToken;

// 11. Kirim OTP via WhatsApp
$sendResult = sendOTP($cleanWa, $otpCode);

// 12. Bersihkan OTP kadaluarsa (yang sudah lebih dari 2 jam)
$pdo->exec("DELETE FROM otp_verification WHERE expired_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)");

// 13. Siapkan response
$response = [
    'success'   => true,
    'message'   => 'Kode OTP telah dikirim ke WhatsApp Anda.',
    'token'     => $otpToken,
    'masked_wa' => maskPhoneNumber($cleanWa),
    'expiry'    => OTP_EXPIRY_MINUTES,
    'cooldown'  => OTP_COOLDOWN_SECONDS
];

// Dev mode: tampilkan OTP di response (JANGAN gunakan di production!)
if (defined('WA_DEV_MODE') && WA_DEV_MODE === true) {
    $response['dev_otp'] = $otpCode;
    $response['dev_note'] = 'MODE DEVELOPMENT: OTP ditampilkan untuk testing. Set WA_DEV_MODE=false di production.';
}

// Jika pengiriman WA gagal (dan bukan dev mode), beri tahu user
if (!$sendResult['success'] && !(defined('WA_DEV_MODE') && WA_DEV_MODE === true)) {
    $response['success'] = false;
    $response['message'] = 'Gagal mengirim OTP ke WhatsApp. ' . ($sendResult['message'] ?? 'Silakan coba lagi.');
    unset($response['token']);
}

echo json_encode($response);
