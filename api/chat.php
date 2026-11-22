<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

session_start();
require_once dirname(__DIR__) . '/koneksi.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Silakan login terlebih dahulu.']);
    exit;
}

$currentUserId = (int)$_SESSION['user_id'];
$currentUserRole = $_SESSION['role'] ?? 'pelanggan';
$currentUserName = $_SESSION['nama'] ?? 'Pengguna';

// Pastikan tabel pesanan_chat tersedia
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pesanan_chat (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pesanan_id INT NOT NULL,
            sender_id INT NOT NULL,
            sender_role VARCHAR(20) NOT NULL,
            pesan TEXT NOT NULL,
            is_read TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX (pesanan_id),
            INDEX (sender_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {}

$method = $_SERVER['REQUEST_METHOD'];

// ==========================================
// 1. GET: AMBIL DAFTAR CHAT UNTUK PESANAN
// ==========================================
if ($method === 'GET') {
    $pesananId = (int)($_GET['pesanan_id'] ?? 0);
    $lastId    = (int)($_GET['last_id'] ?? 0);

    if ($pesananId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'ID pesanan tidak valid.']);
        exit;
    }

    // Ambil data pesanan dan verifikasi otorisasi
    $stmtPesanan = $pdo->prepare("
        SELECT 
            p.id, p.user_id, p.driver_id, p.layanan, p.status, p.alamat, p.catatan, p.total_tarif,
            u_cust.nama AS nama_pelanggan, u_cust.email AS email_pelanggan,
            u_drv.nama AS nama_driver, u_drv.email AS email_driver,
            d.no_hp AS no_hp_driver
        FROM pesanan p
        JOIN users u_cust ON p.user_id = u_cust.id
        LEFT JOIN users u_drv ON p.driver_id = u_drv.id
        LEFT JOIN drivers d ON d.user_id = u_drv.id
        WHERE p.id = :id
        LIMIT 1
    ");
    $stmtPesanan->execute(['id' => $pesananId]);
    $order = $stmtPesanan->fetch();

    if (!$order) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Pesanan tidak ditemukan.']);
        exit;
    }

    $isCustomer = ((int)$order['user_id'] === $currentUserId);
    $isDriver   = (!empty($order['driver_id']) && (int)$order['driver_id'] === $currentUserId);
    $isAdmin    = ($currentUserRole === 'admin');

    // Jika bukan customer pemilik, bukan driver yang ditugaskan, dan bukan admin
    if (!$isCustomer && !$isDriver && !$isAdmin) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Anda tidak memiliki akses ke percakapan pesanan ini.']);
        exit;
    }

    // Ambil pesan chat
    $sqlChat = "
        SELECT 
            c.id, c.pesanan_id, c.sender_id, c.sender_role, c.pesan, c.is_read, c.created_at,
            u.nama AS sender_nama
        FROM pesanan_chat c
        JOIN users u ON c.sender_id = u.id
        WHERE c.pesanan_id = :pesanan_id
    ";
    $params = ['pesanan_id' => $pesananId];

    if ($lastId > 0) {
        $sqlChat .= " AND c.id > :last_id ";
        $params['last_id'] = $lastId;
    }
    $sqlChat .= " ORDER BY c.id ASC";

    $stmtChat = $pdo->prepare($sqlChat);
    $stmtChat->execute($params);
    $rawMessages = $stmtChat->fetchAll();

    $messages = [];
    $unreadIdsToMark = [];

    foreach ($rawMessages as $m) {
        $isMe = ((int)$m['sender_id'] === $currentUserId);
        if (!$isMe && (int)$m['is_read'] === 0) {
            $unreadIdsToMark[] = (int)$m['id'];
        }

        $timeStr = date('H:i', strtotime($m['created_at']));
        $dateStr = date('d M Y', strtotime($m['created_at']));

        $messages[] = [
            'id'          => (int)$m['id'],
            'pesanan_id'  => (int)$m['pesanan_id'],
            'sender_id'   => (int)$m['sender_id'],
            'sender_nama' => $m['sender_nama'],
            'sender_role' => $m['sender_role'],
            'pesan'       => $m['pesan'],
            'is_read'     => (bool)$m['is_read'],
            'is_me'       => $isMe,
            'time'        => $timeStr,
            'date'        => $dateStr,
            'created_at'  => $m['created_at']
        ];
    }

    // Tandai pesan orang lain sebagai telah dibaca
    if (!empty($unreadIdsToMark)) {
        try {
            $inClause = implode(',', array_map('intval', $unreadIdsToMark));
            $pdo->exec("UPDATE pesanan_chat SET is_read = 1 WHERE id IN ($inClause)");
        } catch (Exception $e) {}
    }

    // Tentukan info lawan bicara
    if ($isCustomer) {
        $lawanNama = $order['nama_driver'] ?: 'Driver Polindra';
        $lawanRole = 'driver';
        $lawanHp   = $order['no_hp_driver'] ?: '';
    } else {
        $lawanNama = $order['nama_pelanggan'] ?: 'Pelanggan';
        $lawanRole = 'pelanggan';
        $lawanHp   = '';
    }

    echo json_encode([
        'status' => 'success',
        'data' => [
            'order' => [
                'id'             => (int)$order['id'],
                'layanan'        => $order['layanan'],
                'status'         => $order['status'],
                'catatan'        => $order['catatan'],
                'alamat'         => $order['alamat'],
                'total_tarif'    => (int)$order['total_tarif'],
                'nama_pelanggan' => $order['nama_pelanggan'],
                'nama_driver'    => $order['nama_driver']
            ],
            'opponent' => [
                'nama'  => $lawanNama,
                'role'  => $lawanRole,
                'no_hp' => $lawanHp
            ],
            'my_id'    => $currentUserId,
            'my_role'  => $isCustomer ? 'pelanggan' : ($isDriver ? 'driver' : $currentUserRole),
            'messages' => $messages
        ]
    ]);
    exit;
}

// ==========================================
// 2. POST: KIRIM PESAN BARU
// ==========================================
if ($method === 'POST') {
    $inputData = [];
    $rawInput = file_get_contents('php://input');
    if ($rawInput) {
        $json = json_decode($rawInput, true);
        if (is_array($json)) $inputData = $json;
    }
    if (empty($inputData)) {
        $inputData = $_POST;
    }

    $pesananId = (int)($inputData['pesanan_id'] ?? 0);
    $pesan     = trim((string)($inputData['pesan'] ?? ''));

    if ($pesananId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'ID pesanan tidak valid.']);
        exit;
    }

    if ($pesan === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Pesan tidak boleh kosong.']);
        exit;
    }

    // Verifikasi kepemilikan pesanan
    $stmtPesanan = $pdo->prepare("SELECT id, user_id, driver_id, status FROM pesanan WHERE id = :id LIMIT 1");
    $stmtPesanan->execute(['id' => $pesananId]);
    $order = $stmtPesanan->fetch();

    if (!$order) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Pesanan tidak ditemukan.']);
        exit;
    }

    $isCustomer = ((int)$order['user_id'] === $currentUserId);
    $isDriver   = (!empty($order['driver_id']) && (int)$order['driver_id'] === $currentUserId);
    $isAdmin    = ($currentUserRole === 'admin');

    if (!$isCustomer && !$isDriver && !$isAdmin) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Anda tidak memiliki hak mengirim pesan pada pesanan ini.']);
        exit;
    }

    $senderRole = $isCustomer ? 'pelanggan' : ($isDriver ? 'driver' : 'admin');

    $stmtInsert = $pdo->prepare("
        INSERT INTO pesanan_chat (pesanan_id, sender_id, sender_role, pesan, is_read, created_at)
        VALUES (:pesanan_id, :sender_id, :sender_role, :pesan, 0, NOW())
    ");
    $stmtInsert->execute([
        'pesanan_id'  => $pesananId,
        'sender_id'   => $currentUserId,
        'sender_role' => $senderRole,
        'pesan'       => $pesan
    ]);

    $newId = (int)$pdo->lastInsertId();

    echo json_encode([
        'status'  => 'success',
        'message' => 'Pesan terkirim',
        'data'    => [
            'id'          => $newId,
            'pesanan_id'  => $pesananId,
            'sender_id'   => $currentUserId,
            'sender_nama' => $currentUserName,
            'sender_role' => $senderRole,
            'pesan'       => $pesan,
            'is_me'       => true,
            'time'        => date('H:i'),
            'date'        => date('d M Y'),
            'created_at'  => date('Y-m-d H:i:s')
        ]
    ]);
    exit;
}

