<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');

session_start();
require_once dirname(__DIR__) . '/koneksi.php';
require_once dirname(__DIR__) . '/includes/fitur_helper.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'Silakan login terlebih dahulu.'
    ]);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'pelanggan';
$order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;

try {
    // Proses auto-tolak 2 menit sebelum mengambil status pesanan
    $autoDitolakCount = prosesAutoTolakDuaMenit($pdo, $order_id > 0 ? $order_id : null);

    if ($order_id > 0) {
        if ($user_role === 'driver') {
            $stmt = $pdo->prepare("
                SELECT p.*,
                       u.nama AS customer_nama, u.email AS customer_email,
                       d.id AS driver_table_id, d.nama_driver, d.no_hp AS driver_hp, d.foto_qris,
                       COALESCE(d.rating_rata, 0) AS driver_rating_rata,
                       COALESCE(d.jumlah_rating, 0) AS driver_jumlah_rating,
                       ud.nama AS driver_user_nama, ud.status_aktif AS driver_online,
                       GREATEST(0, 120 - TIMESTAMPDIFF(SECOND, p.created_at, NOW())) AS sisa_detik_ambil,
                       dr.rating AS order_rating, dr.komentar AS order_komentar
                FROM pesanan p
                LEFT JOIN users u ON p.user_id = u.id
                LEFT JOIN users ud ON p.driver_id = ud.id
                LEFT JOIN drivers d ON d.user_id = ud.id
                LEFT JOIN driver_ratings dr ON dr.pesanan_id = p.id
                WHERE p.id = :id
                  AND (
                      p.driver_id = :driver_id
                      OR (
                          p.driver_id IS NULL
                          AND NOT EXISTS (
                              SELECT 1 FROM pesanan_driver_ditolak pd
                              WHERE pd.pesanan_id = p.id AND pd.driver_user_id = :driver_id_tolak
                          )
                      )
                  )
                LIMIT 1
            ");
            $stmt->execute([
                'id' => $order_id,
                'driver_id' => $user_id,
                'driver_id_tolak' => $user_id
            ]);
        } else {
            $stmt = $pdo->prepare("
                SELECT p.*,
                       u.nama AS customer_nama, u.email AS customer_email,
                       d.id AS driver_table_id, d.nama_driver, d.no_hp AS driver_hp, d.foto_qris,
                       COALESCE(d.rating_rata, 0) AS driver_rating_rata,
                       COALESCE(d.jumlah_rating, 0) AS driver_jumlah_rating,
                       ud.nama AS driver_user_nama, ud.status_aktif AS driver_online,
                       GREATEST(0, 120 - TIMESTAMPDIFF(SECOND, p.created_at, NOW())) AS sisa_detik_ambil,
                       dr.rating AS order_rating, dr.komentar AS order_komentar
                FROM pesanan p
                LEFT JOIN users u ON p.user_id = u.id
                LEFT JOIN users ud ON p.driver_id = ud.id
                LEFT JOIN drivers d ON d.user_id = ud.id
                LEFT JOIN driver_ratings dr ON dr.pesanan_id = p.id
                WHERE p.id = :id AND (p.user_id = :user_id OR :is_admin = 'admin')
                LIMIT 1
            ");
            $stmt->execute([
                'id' => $order_id,
                'user_id' => $user_id,
                'is_admin' => $user_role
            ]);
        }
    } else {
        // Ambil order aktif terakhir user
        $stmt = $pdo->prepare("
            SELECT p.*,
                   u.nama AS customer_nama, u.email AS customer_email,
                   d.id AS driver_table_id, d.nama_driver, d.no_hp AS driver_hp, d.foto_qris,
                   COALESCE(d.rating_rata, 0) AS driver_rating_rata,
                   COALESCE(d.jumlah_rating, 0) AS driver_jumlah_rating,
                   ud.nama AS driver_user_nama, ud.status_aktif AS driver_online,
                   GREATEST(0, 120 - TIMESTAMPDIFF(SECOND, p.created_at, NOW())) AS sisa_detik_ambil,
                   dr.rating AS order_rating, dr.komentar AS order_komentar
            FROM pesanan p
            LEFT JOIN users u ON p.user_id = u.id
            LEFT JOIN users ud ON p.driver_id = ud.id
            LEFT JOIN drivers d ON d.user_id = ud.id
            LEFT JOIN driver_ratings dr ON dr.pesanan_id = p.id
            WHERE p.user_id = :user_id
            ORDER BY p.id DESC
            LIMIT 1
        ");
        $stmt->execute(['user_id' => $user_id]);
    }

    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'Pesanan tidak ditemukan atau sudah dilepas karena melewati batas waktu 2 menit.'
        ]);
        exit;
    }

    // Ambil daftar driver yang sudah menolak / auto-tolak pesanan ini
    $stmtTolak = $pdo->prepare("
        SELECT pd.driver_user_id, pd.alasan, pd.created_at, u.nama AS nama_driver
        FROM pesanan_driver_ditolak pd
        LEFT JOIN users u ON u.id = pd.driver_user_id
        WHERE pd.pesanan_id = :pesanan_id
        ORDER BY pd.id ASC
    ");
    $stmtTolak->execute(['pesanan_id' => (int)$order['id']]);
    $riwayatTolak = $stmtTolak->fetchAll(PDO::FETCH_ASSOC);

    $driverData = null;
    if (!empty($order['driver_id'])) {
        $driverData = [
            'id' => (int)$order['driver_id'],
            'nama' => $order['nama_driver'] ?: ($order['driver_user_nama'] ?: 'Driver Polindra Suruh'),
            'no_hp' => $order['driver_hp'] ?? '',
            'foto_qris' => $order['foto_qris'] ?? null,
            'is_online' => (bool)($order['driver_online'] ?? 0),
            'rating_rata' => (float)($order['driver_rating_rata'] ?? 0),
            'jumlah_rating' => (int)($order['driver_jumlah_rating'] ?? 0)
        ];
    }

    // Format badge status ala Gojek
    $statusText = $order['status'];
    $statusBadge = 'mencari';
    $stepProgress = 1;

    switch ($statusText) {
        case 'Menunggu Driver':
        case 'Menunggu Persetujuan Driver':
        case 'Menunggu':
            $statusBadge = 'mencari';
            $stepProgress = 1;
            break;
        case 'Menunggu Penyesuaian Harga':
            $statusBadge = 'cek_kondisi';
            $stepProgress = 1;
            break;
        case 'Menunggu Pembayaran':
            $statusBadge = 'bayar';
            $stepProgress = 2;
            break;
        case 'Diproses':
            $statusBadge = 'diproses';
            $stepProgress = 3;
            break;
        case 'Dalam Perjalanan':
            $statusBadge = 'otw';
            $stepProgress = 4;
            break;
        case 'Selesai':
            $statusBadge = 'selesai';
            $stepProgress = 5;
            break;
        case 'Dibatalkan':
            $statusBadge = 'batal';
            $stepProgress = 0;
            break;
    }

    $isMenungguDriver = in_array($statusText, ['Menunggu Driver', 'Menunggu Persetujuan Driver', 'Menunggu'], true);
    $sisaDetik = (!empty($order['driver_id']) && $isMenungguDriver)
        ? (int)($order['sisa_detik_ambil'] ?? 0)
        : 0;

    $ratingData = null;
    if ($order['order_rating'] !== null) {
        $ratingData = [
            'rating' => (int)$order['order_rating'],
            'komentar' => $order['order_komentar'] ?? ''
        ];
    }

    echo json_encode([
        'status' => 'success',
        'auto_ditolak_count' => $autoDitolakCount,
        'data' => [
            'id' => (int)$order['id'],
            'layanan' => $order['layanan'],
            'status' => $order['status'],
            'status_badge' => $statusBadge,
            'step_progress' => $stepProgress,
            'sisa_detik_ambil' => $sisaDetik,
            'perlu_pilih_driver_lain' => ($isMenungguDriver && empty($order['driver_id']) && count($riwayatTolak) > 0),
            'driver_ditolak' => $riwayatTolak,
            'catatan' => $order['catatan'],
            'alamat' => $order['alamat'],
            'total_tarif' => (int)$order['total_tarif'],
            'total_tarif_format' => 'Rp ' . number_format((int)$order['total_tarif'], 0, ',', '.'),
            'metode_bayar' => $order['metode_bayar'],
            'qris_diverifikasi' => (int)($order['qris_diverifikasi'] ?? 0),
            'bukti_pembayaran' => $order['bukti_pembayaran'] ?? null,
            'bukti_antar' => !empty($order['bukti_antar']) ? 'uploads/bukti_antar/' . $order['bukti_antar'] : null,
            'waktu_antar' => $order['waktu_antar'] ?? null,
            'foto_kost' => !empty($order['foto_kost']) ? 'uploads/foto_kost/' . $order['foto_kost'] : null,
            'rating' => $ratingData,
            'created_at' => $order['created_at'],
            'driver' => $driverData
        ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Terjadi kesalahan server: ' . $e->getMessage()
    ]);
}