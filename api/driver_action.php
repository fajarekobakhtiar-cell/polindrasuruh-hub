<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

session_start();
require_once dirname(__DIR__) . '/koneksi.php';
require_once dirname(__DIR__) . '/includes/fitur_helper.php';
require_once dirname(__DIR__) . '/includes/wallet_helper.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'driver') {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'message' => 'Akses ditolak. Hanya untuk akun driver.'
    ]);
    exit;
}

$driver_id = (int)$_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];

// Selalu jalankan pengecekan 2 menit sebelum memproses data
prosesAutoTolakDuaMenit($pdo);

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'get_orders';
    if ($action === 'get_orders') {
        try {
            $stmt = $pdo->prepare("
                SELECT p.*, u.nama AS nama_pelanggan, u.email AS email_pelanggan,
                       GREATEST(0, 120 - TIMESTAMPDIFF(SECOND, p.created_at, NOW())) AS sisa_detik_ambil
                FROM pesanan p
                JOIN users u ON p.user_id = u.id
                WHERE (
                    p.driver_id = :driver_id
                    OR (
                        p.driver_id IS NULL
                        AND p.status IN ('Menunggu Driver', 'Menunggu', 'Menunggu Penyesuaian Harga')
                        AND NOT EXISTS (
                            SELECT 1 FROM pesanan_driver_ditolak pd
                            WHERE pd.pesanan_id = p.id AND pd.driver_user_id = :driver_id_tolak
                        )
                    )
                )
                ORDER BY p.id DESC
            ");
            $stmt->execute([
                'driver_id' => $driver_id,
                'driver_id_tolak' => $driver_id
            ]);
            $pesanan = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $pesananBaru = [];
            $pesananBerjalan = [];
            $pesananSelesai = [];

            foreach ($pesanan as $p) {
                $st = $p['status'];
                $item = [
                    'id' => (int)$p['id'],
                    'layanan' => $p['layanan'],
                    'status' => $p['status'],
                    'nama_pelanggan' => $p['nama_pelanggan'],
                    'catatan' => $p['catatan'],
                    'alamat' => $p['alamat'],
                    'total_tarif' => (int)$p['total_tarif'],
                    'total_tarif_format' => 'Rp ' . number_format((int)$p['total_tarif'], 0, ',', '.'),
                    'metode_bayar' => $p['metode_bayar'],
                    'qris_diverifikasi' => (int)($p['qris_diverifikasi'] ?? 0),
                    'bukti_pembayaran' => $p['bukti_pembayaran'] ?? null,
                    'bukti_antar' => !empty($p['bukti_antar']) ? 'uploads/bukti_antar/' . $p['bukti_antar'] : null,
                    'foto_kost' => !empty($p['foto_kost']) ? 'uploads/foto_kost/' . $p['foto_kost'] : null,
                    'sisa_detik_ambil' => (int)($p['sisa_detik_ambil'] ?? 0),
                    'created_at' => $p['created_at'],
                    'is_assigned_to_me' => ((int)$p['driver_id'] === $driver_id)
                ];

                if (in_array($st, ['Menunggu Driver', 'Menunggu Persetujuan Driver', 'Menunggu', 'Menunggu Penyesuaian Harga', 'Menunggu Penyesuaian Driver'], true)) {
                    $pesananBaru[] = $item;
                } elseif (in_array($st, ['Menunggu Pembayaran', 'Diproses', 'Dalam Perjalanan'], true)) {
                    $pesananBerjalan[] = $item;
                } else {
                    $pesananSelesai[] = $item;
                }
            }

            echo json_encode([
                'status' => 'success',
                'counts' => [
                    'baru' => count($pesananBaru),
                    'berjalan' => count($pesananBerjalan),
                    'selesai' => count($pesananSelesai)
                ],
                'data' => [
                    'baru' => $pesananBaru,
                    'berjalan' => $pesananBerjalan,
                    'selesai' => $pesananSelesai
                ]
            ]);
            exit;
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            exit;
        }
    }
}

$input = $_POST;
if (empty($input)) {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if (is_array($json)) {
        $input = $json;
    }
}

$action = $input['action'] ?? ($input['aksi_pesanan'] ?? '');
$order_id = (int)($input['pesanan_id'] ?? ($input['order_id'] ?? 0));

if ($order_id <= 0 && $action !== 'toggle_status') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Parameter order_id tidak valid.']);
    exit;
}

try {
    if ($action === 'accept') {
        $stmtCek = $pdo->prepare("
            SELECT metode_bayar, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS elapsed
            FROM pesanan
            WHERE id = :id AND (driver_id IS NULL OR driver_id = :driver_id)
        ");
        $stmtCek->execute(['id' => $order_id, 'driver_id' => $driver_id]);
        $row = $stmtCek->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            echo json_encode(['status' => 'error', 'message' => 'Pesanan sudah diambil driver lain atau tidak tersedia.']);
            exit;
        }

        if ((int)$row['elapsed'] >= 120) {
            prosesAutoTolakDuaMenit($pdo, $order_id);
            echo json_encode(['status' => 'error', 'message' => 'Waktu pengambilan 2 menit telah habis. Pesanan otomatis dilepas untuk driver lain.']);
            exit;
        }

        $targetStatus = ($row['metode_bayar'] === 'cash') ? 'Diproses' : 'Menunggu Pembayaran';

        $stmt = $pdo->prepare("
            UPDATE pesanan
            SET status = :status, driver_id = :new_driver_id
            WHERE id = :id AND (driver_id IS NULL OR driver_id = :curr_driver_id)
        ");
        $stmt->execute([
            'status' => $targetStatus,
            'new_driver_id' => $driver_id,
            'id' => $order_id,
            'curr_driver_id' => $driver_id
        ]);

        echo json_encode(['status' => 'success', 'message' => 'Pesanan berhasil diterima!', 'new_status' => $targetStatus]);
        exit;
    }

    if ($action === 'reject') {
        catatPenolakanDriver($pdo, $order_id, $driver_id, 'manual_reject');
        echo json_encode(['status' => 'success', 'message' => 'Pesanan ditolak untuk akun Anda dan tetap tersedia untuk driver lain.']);
        exit;
    }

    if ($action === 'start_trip') {
        $stmt = $pdo->prepare("
            UPDATE pesanan
            SET status = 'Dalam Perjalanan'
            WHERE id = :id AND driver_id = :driver_id
        ");
        $stmt->execute(['id' => $order_id, 'driver_id' => $driver_id]);
        echo json_encode(['status' => 'success', 'message' => 'Status diubah ke Dalam Perjalanan.']);
        exit;
    }

    if ($action === 'complete') {
        $uploadRes = uploadBuktiAntarWajib($_FILES['bukti_antar'] ?? [], $order_id, $driver_id);
        if (!$uploadRes['ok']) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => $uploadRes['message']]);
            exit;
        }

        $res = selesaikanPesananDenganBukti($pdo, $order_id, $driver_id, $uploadRes['filename']);
        if (!$res['ok']) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => $res['message']]);
            exit;
        }

        echo json_encode([
            'status' => 'success',
            'message' => $res['message'],
            'bukti_antar' => 'uploads/bukti_antar/' . $uploadRes['filename']
        ]);
        exit;
    }

    if ($action === 'verify_qris') {
        $stmt = $pdo->prepare("
            UPDATE pesanan
            SET qris_diverifikasi = 1,
                alasan_tolak_bayar = NULL,
                status = CASE WHEN status = 'Menunggu Pembayaran' THEN 'Diproses' ELSE status END
            WHERE id = :id AND driver_id = :driver_id
        ");
        $stmt->execute(['id' => $order_id, 'driver_id' => $driver_id]);
        echo json_encode(['status' => 'success', 'message' => 'Pembayaran QRIS berhasil dikonfirmasi.']);
        exit;
    }

    if ($action === 'reject_qris') {
        $alasan = trim((string)($input['alasan'] ?? 'Bukti pembayaran tidak sesuai atau dana belum masuk.'));
        $stmt = $pdo->prepare("
            UPDATE pesanan
            SET qris_diverifikasi = 2,
                alasan_tolak_bayar = :alasan,
                status = 'Menunggu Pembayaran'
            WHERE id = :id AND driver_id = :driver_id
        ");
        $stmt->execute([
            'alasan' => $alasan,
            'id' => $order_id,
            'driver_id' => $driver_id
        ]);
        echo json_encode(['status' => 'success', 'message' => 'Bukti QRIS ditolak. Pelanggan diminta mengunggah ulang.']);
        exit;
    }

    if ($action === 'adjust_price') {
        $hargaBaru = (int)($input['harga_penyesuaian'] ?? ($input['total_tarif'] ?? 0));
        $catatanTambahan = trim((string)($input['catatan_driver'] ?? ''));
        if ($hargaBaru < 5000) $hargaBaru = 15000;

        $stmtCek = $pdo->prepare("SELECT metode_bayar, catatan FROM pesanan WHERE id = :id AND (driver_id IS NULL OR driver_id = :driver_id)");
        $stmtCek->execute(['id' => $order_id, 'driver_id' => $driver_id]);
        $row = $stmtCek->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            echo json_encode(['status' => 'error', 'message' => 'Pesanan sudah diambil atau tidak ditemukan.']);
            exit;
        }

        $targetStatus = ($row['metode_bayar'] === 'cash') ? 'Diproses' : 'Menunggu Pembayaran';
        $catatanUpdate = $row['catatan'] ?? '';
        if ($catatanTambahan !== '') {
            $catatanUpdate .= "\n[Catatan Driver]: " . $catatanTambahan;
        }

        $stmt = $pdo->prepare("
            UPDATE pesanan
            SET total_tarif = :total_tarif,
                driver_id = :new_driver_id,
                status = :status,
                catatan = :catatan
            WHERE id = :id AND (driver_id IS NULL OR driver_id = :curr_driver_id)
        ");
        $stmt->execute([
            'total_tarif' => $hargaBaru,
            'new_driver_id' => $driver_id,
            'status' => $targetStatus,
            'catatan' => $catatanUpdate,
            'id' => $order_id,
            'curr_driver_id' => $driver_id
        ]);

        echo json_encode(['status' => 'success', 'message' => 'Harga berhasil disesuaikan dan pesanan diterima!', 'new_price' => $hargaBaru, 'new_status' => $targetStatus]);
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Aksi tidak dikenali.']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}