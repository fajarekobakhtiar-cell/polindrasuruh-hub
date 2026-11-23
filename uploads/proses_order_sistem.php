<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once dirname(__DIR__) . '/koneksi.php';
require_once dirname(__DIR__) . '/includes/wallet_helper.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php?redirect=order');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../order.php');
    exit;
}

$user_id = (int) ($_SESSION['user_id'] ?? 0);
$driver_id = !empty($_POST['driver_id']) ? (int) $_POST['driver_id'] : null;

$nama_toko = trim((string) ($_POST['nama_toko'] ?? ''));
$alamat_toko = trim((string) ($_POST['alamat_toko'] ?? ''));
$alamat_pelanggan = trim((string) ($_POST['alamat_pelanggan'] ?? ''));

$pickup_lat = trim((string) ($_POST['pickup_lat'] ?? ''));
$pickup_lng = trim((string) ($_POST['pickup_lng'] ?? ''));
$dest_lat = trim((string) ($_POST['dest_lat'] ?? ''));
$dest_lng = trim((string) ($_POST['dest_lng'] ?? ''));

$catatan = trim((string) ($_POST['catatan'] ?? ''));
$metode_bayar = in_array($_POST['metode_bayar'] ?? 'qris', ['qris', 'cash'], true)
    ? $_POST['metode_bayar']
    : 'qris';

if ($user_id <= 0) {
    header('Location: ../login.php');
    exit;
}

if ($catatan === '') {
    echo "<script>alert('Harap isi pesanan atau barang yang ingin dibeli!'); window.history.back();</script>";
    exit;
}

$layanan_input = trim((string) ($_POST['layanan'] ?? ''));
if ($layanan_input === '') {
    $layanan_input = 'Suruh Beli Makanan/Minuman';
}

$isAntarJemput = (stripos($layanan_input, 'antar') !== false || stripos($layanan_input, 'jemput') !== false);
$isBeresinKost = (stripos($layanan_input, 'kost') !== false || stripos($layanan_input, 'beres') !== false);

// Ambil lokasi GPS realtime driver terpilih untuk perhitungan jarak ke pelanggan
// Ambil lokasi GPS realtime driver terpilih untuk perhitungan jarak ke pelanggan
if ($driver_id) {
    $stmtDrvLoc = $pdo->prepare("SELECT lat, lng FROM drivers WHERE user_id = ? LIMIT 1");
    $stmtDrvLoc->execute([$driver_id]);
    $drvLoc = $stmtDrvLoc->fetch(PDO::FETCH_ASSOC);
    if ($drvLoc && !empty($drvLoc['lat']) && !empty($drvLoc['lng'])) {
        $pickup_lat = (string) $drvLoc['lat'];
        $pickup_lng = (string) $drvLoc['lng'];
    }
}

if ($pickup_lat === '' || $pickup_lng === '') {
    $pickup_lat = '-6.4018000'; // Default Area Kampus Polindra Lohbener
    $pickup_lng = '108.2825000';
}

// Default Pelanggan jika GPS belum ditandai: Area Sekitar Lohbener (-6.408176, 108.280999)
if ($dest_lat === '' || $dest_lng === '') {
    $dest_lat = '-6.4081760';
    $dest_lng = '108.2809990';
}

$layanan_input = trim((string) ($_POST['layanan'] ?? ''));
if ($layanan_input === '') {
    $layanan_input = 'Suruh Beli Makanan/Minuman';
}

// Hitung tarif dan jarak otomatis untuk layanan yang ditentukan (Suruh Beli Makanan/Minuman, Galon, Print, Antar Jemput)
$infoTarif = hitungTarifOtomatis($pdo, $layanan_input, $pickup_lat, $pickup_lng, $dest_lat, $dest_lng);
$jarak_km = $infoTarif['jarak_km'];
$total_tarif = $infoTarif['total_tarif'];

// Pastikan kolom foto_kost tersedia di database
try {
    $pdo->exec("ALTER TABLE pesanan ADD COLUMN foto_kost VARCHAR(255) NULL AFTER catatan");
} catch (Exception $e) {
    // Kolom sudah ada
}

// Proses Upload Foto Kondisi Kost (Khusus Layanan Beresin Kost)
$uploadedFotoKost = null;
if (isset($_FILES['foto_kost']) && $_FILES['foto_kost']['error'] === UPLOAD_ERR_OK) {
    $fileTmp = $_FILES['foto_kost']['tmp_name'];
    $fileName = $_FILES['foto_kost']['name'];
    $fileSize = $_FILES['foto_kost']['size'];
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];

    if (in_array($ext, $allowedExts) && $fileSize <= 8 * 1024 * 1024) {
        $dirKost = dirname(__DIR__) . '/uploads/foto_kost/';
        if (!is_dir($dirKost)) {
            mkdir($dirKost, 0777, true);
        }
        $newKostName = 'kost_' . $user_id . '_' . time() . '.' . $ext;
        if (move_uploaded_file($fileTmp, $dirKost . $newKostName)) {
            $uploadedFotoKost = $newKostName;
        }
    }
}

$namaTokoFinal = $nama_toko !== '' ? $nama_toko : '';
$alamatPelangganFinal = $alamat_pelanggan !== '' ? $alamat_pelanggan : 'Lokasi Pelanggan (Indramayu)';

$isBeresinKost = false;
if (stripos($layanan_input, 'antar') !== false || stripos($layanan_input, 'jemput') !== false) {
    $layanan = 'Antar Jemput' . ($namaTokoFinal !== '' ? ' (' . $namaTokoFinal . ')' : '');
    $alamat = ($namaTokoFinal !== '' ? 'Jemput: ' . $namaTokoFinal . ' ➔ Tujuan: ' : '') . $alamatPelangganFinal;
} elseif (stripos($layanan_input, 'kost') !== false || stripos($layanan_input, 'beres') !== false) {
    $layanan = 'Beresin Kost';
    $alamat = $alamatPelangganFinal;
    $isBeresinKost = true;
    if ($uploadedFotoKost) {
        $catatan .= "\n[Foto Keadaan Kost: Terlampir - Menunggu Penyesuaian Harga Driver]";
    }
} elseif (stripos($layanan_input, 'Suruh Beli Makanan/Minuman') !== false || stripos($layanan_input, 'makan') !== false) {
    $layanan = ($namaTokoFinal !== '' ? 'Suruh Beli Makanan/Minuman (' . $namaTokoFinal . ')' : 'Suruh Beli Makanan/Minuman');
    $alamat = ($namaTokoFinal !== '' ? 'Toko: ' . $namaTokoFinal . ' ➔ Antar ke: ' : '') . $alamatPelangganFinal;
} elseif (stripos($layanan_input, 'galon') !== false) {
    $layanan = 'Antar Galon';
    $alamat = $alamatPelangganFinal;
} elseif (stripos($layanan_input, 'print') !== false || stripos($layanan_input, 'fotokopi') !== false) {
    $layanan = 'Print & Fotokopi';
    $alamat = $alamatPelangganFinal;
} elseif (stripos($layanan_input, 'motor') !== false || stripos($layanan_input, 'service') !== false) {
    $layanan = 'Service Motor';
    $alamat = $alamatPelangganFinal;
} elseif (stripos($layanan_input, 'nongkrong') !== false || stripos($layanan_input, 'teman') !== false) {
    $layanan = 'Temanin Nongkrong';
    $alamat = $alamatPelangganFinal;
} else {
    $layanan = $layanan_input ?: 'Suruh Apa Saja';
    $alamat = $alamatPelangganFinal;
}

try {
    // Status pesanan cerdas:
    if ($isBeresinKost) {
        $statusPesanan = $driver_id ? 'Menunggu Penyesuaian Driver' : 'Menunggu Penyesuaian Harga';
    } elseif ($infoTarif['is_otomatis']) {
        // Layanan tarif otomatis (Suruh Beli Makanan/Minuman, Galon, Print, Antar Jemput):
        if ($metode_bayar === 'qris') {
            // Sudah ada harga pasti, jika ada driver langsung bayar QRIS Admin!
            $statusPesanan = $driver_id ? 'Menunggu Pembayaran' : 'Menunggu Driver';
        } else {
            // Cash: langsung siap diproses
            $statusPesanan = $driver_id ? 'Diproses' : 'Menunggu Driver';
        }
    } else {
        // Layanan negosiasi manual:
        $statusPesanan = $driver_id ? 'Menunggu Persetujuan Driver' : 'Menunggu Driver';
    }

    // Periksa kolom tabel pesanan
    $columnExists = [];
    $schemaStmt = $pdo->query('SHOW COLUMNS FROM pesanan');
    while ($row = $schemaStmt->fetch(PDO::FETCH_ASSOC)) {
        $columnExists[$row['Field']] = true;
    }

    $fields = ['user_id', 'layanan', 'alamat', 'catatan', 'metode_bayar', 'status'];
    $values = [$user_id, $layanan, $catatan, $metode_bayar, $statusPesanan];
    // Perbaikan susunan values sesuai fields
    $values = [$user_id, $layanan, $alamat, $catatan, $metode_bayar, $statusPesanan];

    if (isset($columnExists['foto_kost']) && $uploadedFotoKost) {
        $fields[] = 'foto_kost';
        $values[] = $uploadedFotoKost;
    }

    if (isset($columnExists['driver_id']) && $driver_id) {
        $fields[] = 'driver_id';
        $values[] = $driver_id;
    }

    foreach (['pickup_lat', 'pickup_lng', 'dest_lat', 'dest_lng', 'jarak_km', 'total_tarif'] as $col) {
        if (!isset($columnExists[$col])) {
            continue;
        }

        $fields[] = $col;

        if ($col === 'pickup_lat') {
            $values[] = $pickup_lat;
        } elseif ($col === 'pickup_lng') {
            $values[] = $pickup_lng;
        } elseif ($col === 'dest_lat') {
            $values[] = $dest_lat;
        } elseif ($col === 'dest_lng') {
            $values[] = $dest_lng;
        } elseif ($col === 'jarak_km') {
            $values[] = $jarak_km;
        } elseif ($col === 'total_tarif') {
            $values[] = $total_tarif;
        }
    }

    if (isset($columnExists['created_at'])) {
        $fields[] = 'created_at';
        $values[] = 'NOW()';
    }

    $sqlValuePlaceholders = [];
    foreach ($values as $value) {
        $sqlValuePlaceholders[] = ($value === 'NOW()') ? 'NOW()' : '?';
    }

    $sql = 'INSERT INTO pesanan (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $sqlValuePlaceholders) . ')';
    $bindValues = [];
    foreach ($values as $value) {
        if ($value !== 'NOW()') {
            $bindValues[] = $value;
        }
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($bindValues);
    $pesanan_id = (int) $pdo->lastInsertId();

    // Langsung arahkan ke Pesanan Saya
    header('Location: ../pesanan_saya.php?id=' . $pesanan_id . '&pesan=sukses');
    exit;

} catch (Throwable $e) {
    echo '<h2>Error saat memproses pesanan</h2>';
    echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
    echo '<pre>File: ' . htmlspecialchars($e->getFile(), ENT_QUOTES, 'UTF-8') . ' (Line ' . (int) $e->getLine() . ')</pre>';
    exit;
}