<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');

session_start();
require_once dirname(__DIR__) . '/koneksi.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Silakan login terlebih dahulu untuk membuat pesanan.']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$driver_id = !empty($_POST['driver_id']) ? (int)$_POST['driver_id'] : null;

$layanan_input = trim((string)($_POST['layanan'] ?? 'Food'));
$nama_toko = trim((string)($_POST['nama_toko'] ?? ''));
$alamat_toko = trim((string)($_POST['alamat_toko'] ?? ''));
$alamat_pelanggan = trim((string)($_POST['alamat_pelanggan'] ?? ''));
$catatan = trim((string)($_POST['catatan'] ?? ''));

$pickup_lat = trim((string)($_POST['pickup_lat'] ?? ''));
$pickup_lng = trim((string)($_POST['pickup_lng'] ?? ''));
$dest_lat = trim((string)($_POST['dest_lat'] ?? ''));
$dest_lng = trim((string)($_POST['dest_lng'] ?? ''));

$metode_bayar = in_array($_POST['metode_bayar'] ?? 'qris', ['qris', 'cash'], true)
    ? $_POST['metode_bayar']
    : 'qris';

if ($catatan === '') {
    echo json_encode(['status' => 'error', 'message' => 'Harap isi rincian pesanan Anda!']);
    exit;
}

// Pastikan tabel memiliki kolom foto_kost
try {
    $pdo->exec("ALTER TABLE pesanan ADD COLUMN foto_kost VARCHAR(255) NULL AFTER catatan");
} catch (Exception $e) {}

// Upload foto kondisi kost jika ada
$uploadedFotoKost = null;
if (isset($_FILES['foto_kost']) && $_FILES['foto_kost']['error'] === UPLOAD_ERR_OK) {
    $fileTmp = $_FILES['foto_kost']['tmp_name'];
    $fileName = $_FILES['foto_kost']['name'];
    $fileSize = $_FILES['foto_kost']['size'];
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];

    if (in_array($ext, $allowedExts) && $fileSize <= 8 * 1024 * 1024) {
        $dirKost = dirname(__DIR__) . '/uploads/foto_kost/';
        if (!is_dir($dirKost)) mkdir($dirKost, 0777, true);
        $newKostName = 'kost_' . $user_id . '_' . time() . '.' . $ext;
        if (move_uploaded_file($fileTmp, $dirKost . $newKostName)) {
            $uploadedFotoKost = $newKostName;
        }
    }
}

$isAntarJemput = (stripos($layanan_input, 'antar') !== false || stripos($layanan_input, 'jemput') !== false);
$isBeresinKost = (stripos($layanan_input, 'kost') !== false || stripos($layanan_input, 'beres') !== false);

// Default koordinat cerdas:
if ($pickup_lat === '' || $pickup_lng === '') {
    if (!empty($driver_id)) {
        $stmtDrvLoc = $pdo->prepare("SELECT lat, lng FROM drivers WHERE user_id = ? LIMIT 1");
        $stmtDrvLoc->execute([$driver_id]);
        $drvLoc = $stmtDrvLoc->fetch();
        if ($drvLoc && !empty($drvLoc['lat']) && !empty($drvLoc['lng'])) {
            $pickup_lat = (string)$drvLoc['lat'];
            $pickup_lng = (string)$drvLoc['lng'];
        }
    }
    if ($pickup_lat === '' || $pickup_lng === '') {
        $pickup_lat = '-6.4018000'; // Polindra Lohbener
        $pickup_lng = '108.2825000';
    }
}
if ($dest_lat === '' || $dest_lng === '') {
    $dest_lat = '-6.4018000';
    $dest_lng = '108.2825000';
}

// Hitung jarak Haversine
function getHaversineDistance($lat1, $lon1, $lat2, $lon2) {
    $r = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) * sin($dLat / 2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) * sin($dLon / 2);
    return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
}
$jarak_km = null;
$total_tarif = !empty($_POST['total_tarif']) ? (int)$_POST['total_tarif'] : null;

// Susun penamaan layanan & alamat
if ($isAntarJemput) {
    $layanan = 'Antar Jemput' . ($nama_toko !== '' ? ' (' . $nama_toko . ')' : '');
    $alamat = 'Jemput di: ' . ($nama_toko !== '' ? $nama_toko : 'Titik Penjemputan') . ' ➔ Tujuan: ' . ($alamat_pelanggan ?: 'Alamat Pengantaran');
    $statusPesanan = $driver_id ? 'Menunggu Persetujuan Driver' : 'Menunggu Driver';
} elseif ($isBeresinKost) {
    $layanan = 'Beresin Kost';
    $alamat = 'Lokasi Kost: ' . ($alamat_pelanggan ?: 'Alamat Kost');
    if ($uploadedFotoKost) {
        $catatan .= "\n[Foto Kondisi Kost: Terlampir - Menunggu Penyesuaian Harga Driver]";
    }
    $statusPesanan = $driver_id ? 'Menunggu Penyesuaian Driver' : 'Menunggu Penyesuaian Harga';
} else {
    $layanan = 'Food' . ($nama_toko !== '' ? ' (' . $nama_toko . ')' : '');
    $alamat = 'Toko: ' . ($nama_toko ?: 'Toko Terpilih') . ' ➔ Antar ke: ' . ($alamat_pelanggan ?: 'Alamat Pelanggan');
    $statusPesanan = $driver_id ? 'Menunggu Persetujuan Driver' : 'Menunggu Driver';
}

try {
    $columnExists = [];
    $schemaStmt = $pdo->query('SHOW COLUMNS FROM pesanan');
    while ($row = $schemaStmt->fetch(PDO::FETCH_ASSOC)) {
        $columnExists[$row['Field']] = true;
    }

    $fields = ['user_id', 'layanan', 'alamat', 'catatan', 'metode_bayar', 'status'];
    $values = [$user_id, $layanan, $alamat, $catatan, $metode_bayar, $statusPesanan];

    if (isset($columnExists['driver_id']) && $driver_id) {
        $fields[] = 'driver_id';
        $values[] = $driver_id;
    }

    if (isset($columnExists['foto_kost']) && $uploadedFotoKost) {
        $fields[] = 'foto_kost';
        $values[] = $uploadedFotoKost;
    }

    foreach (['pickup_lat', 'pickup_lng', 'dest_lat', 'dest_lng', 'jarak_km', 'total_tarif'] as $col) {
        if (!isset($columnExists[$col])) continue;
        $fields[] = $col;
        if ($col === 'pickup_lat') $values[] = $pickup_lat;
        elseif ($col === 'pickup_lng') $values[] = $pickup_lng;
        elseif ($col === 'dest_lat') $values[] = $dest_lat;
        elseif ($col === 'dest_lng') $values[] = $dest_lng;
        elseif ($col === 'jarak_km') $values[] = $jarak_km;
        elseif ($col === 'total_tarif') $values[] = $total_tarif;
    }

    if (isset($columnExists['created_at'])) {
        $fields[] = 'created_at';
        $values[] = 'NOW()';
    }

    $sqlPlaceholders = [];
    $bindValues = [];
    foreach ($values as $v) {
        if ($v === 'NOW()') {
            $sqlPlaceholders[] = 'NOW()';
        } else {
            $sqlPlaceholders[] = '?';
            $bindValues[] = $v;
        }
    }

    $sql = "INSERT INTO pesanan (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $sqlPlaceholders) . ")";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($bindValues);
    $orderId = (int)$pdo->lastInsertId();

    echo json_encode([
        'status' => 'success',
        'message' => 'Pesanan berhasil dibuat ala Gojek! Sedang menghubungkan dengan driver terdekat.',
        'data' => [
            'order_id' => $orderId,
            'service' => $layanan,
            'status' => $statusPesanan,
            'total_fare' => $total_tarif,
            'formatted_fare' => 'Rp ' . number_format($total_tarif, 0, ',', '.'),
            'distance_km' => $jarak_km,
            'payment_method' => $metode_bayar,
            'redirect_url' => 'pesanan_saya.php?id=' . $orderId . '&pesan=sukses'
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Gagal membuat pesanan: ' . $e->getMessage()
    ]);
}

