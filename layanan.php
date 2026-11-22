<?php
session_start();
require_once "koneksi.php";

$nama = $_SESSION["nama"] ?? null;
$role = $_SESSION["role"] ?? null;
$login = isset($_SESSION["user_id"]);

// Ambil data layanan aktif dari database agar tersinkronisasi penuh dengan panel Admin
$daftarLayanan = [];
try {
    $stmtLayanan = $pdo->query("SELECT * FROM layanan WHERE status_aktif = 1 ORDER BY urutan ASC, id ASC");
    $dbRows = $stmtLayanan->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($dbRows)) {
        foreach ($dbRows as $row) {
            $daftarLayanan[] = [
                'id'        => (int) $row['id'],
                'nomor'     => !empty($row['nomor']) ? $row['nomor'] : sprintf('%02d', (int)$row['urutan']),
                'icon'      => '',
                'nama'      => $row['nama'],
                'deskripsi' => $row['deskripsi'],
                'link'      => 'order.php?layanan=' . urlencode($row['nama'])
            ];
        }
    }
} catch (Exception $e) {
    // Fallback jika koneksi gagal
    $daftarLayanan = [];
}

// Default cadangan jika belum ada data di database
if (empty($daftarLayanan)) {
    $daftarLayanan = [
        [
            'nomor' => '01',
            'icon' => '',
            'nama' => 'Suruh Beli Makanan/Minuman',
            'deskripsi' => 'Mau makan tapi malas keluar? Kami siap membelikan makanan yang kalian mau.',
            'link' => 'order.php?layanan=Food'
        ],
        [
            'nomor' => '02',
            'icon' => '',
            'nama' => 'Antar-Jemput',
            'deskripsi' => 'Membutuhkan bantuan untuk mengantar atau mengambil barang, paket maupun antar jemput?',
            'link' => 'order.php?layanan=Antar-Jemput'
        ],
        [
            'nomor' => '03',
            'icon' => '',
            'nama' => 'Beresin Kost',
            'deskripsi' => 'Kamar kosan berantakan atau males nyuci? Kami bisa membantu membersihkan kamar kosan, sapu, pel, nyuci apapun.',
            'link' => 'order.php?layanan=Beresin+Kost'
        ],
        [
            'nomor' => '04',
            'icon' => '',
            'nama' => 'Print & Fotokopi',
            'deskripsi' => 'Mau print tugas, laporan praktikum, jilid skripsi, atau fotokopi tapi buru-buru? Tinggal kirim file pesanan ke driver.',
            'link' => 'order.php?layanan=Print+%26+Fotokopi'
        ],
        [
            'nomor' => '05',
            'icon' => '',
            'nama' => 'Service Motor',
            'deskripsi' => 'Motor mogok, ban bocor, atau butuh ganti oli tapi mager ke bengkel? Kami bisa atasin kendaraan kamu.',
            'link' => 'order.php?layanan=Service+Motor'
        ],
        [
            'nomor' => '06',
            'icon' => '',
            'nama' => 'Temanin Nongkrong',
            'deskripsi' => 'Lagi gabut, pengen ngopi tapi ga ada teman? Kami siap nemenin nongkrong, ngobrol santai, atau ngerjain tugas bareng.',
            'link' => 'order.php?layanan=Temanin+Nongkrong'
        ],
        [
            'nomor' => '07',
            'icon' => '',
            'nama' => 'Antar Galon',
            'deskripsi' => 'Galon kosan habis dan malas angkut sendiri? Kami siap belikan dan antar galon air atau gas sampai depan pintu kamarmu.',
            'link' => 'order.php?layanan=Antar+Galon'
        ],
        [
            'nomor' => '08',
            'icon' => '',
            'nama' => 'Suruh Apa Saja',
            'deskripsi' => 'Ada kebutuhan lain di luar daftar di atas? Tuliskan apa saja yang kamu butuhkan secara bebas, Kami siap membantu!',
            'link' => 'order.php?layanan=Suruh+Apa+Saja'
        ]
    ];
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pilih Layanan - Polindra Suruh</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>

<body class="page-layanan">

    <?php require_once __DIR__ . '/includes/navbar.php'; ?>

    <!-- HEADER PERSIS SEPERTI DI GAMBAR UI -->
    <section class="header">
        <small>POLINDRA SURUH-HUB</small>
        <h1>
            PILIH <span>LAYANAN</span>
        </h1>
        <p>
            Butuh bantuan? Pilih layanan yang kamu butuhkan dan biarkan Polindra Suruh membantu menyelesaikan kebutuhanmu.
        </p>
    </section>

    <!-- DAFTAR LAYANAN MENYATU (TIDAK TERPISAH SATU-SATU) -->
    <section class="services-wrapper">
        <div class="services-unified-card">
            <?php foreach ($daftarLayanan as $item): ?>
                <div class="service-row-item">
                    <div class="service-row-left">
                        <span class="service-num-badge"><?= htmlspecialchars($item['nomor']) ?></span>
                        <div class="service-row-content">
                            <h2 class="service-row-title"><?= htmlspecialchars($item['nama']) ?></h2>
                            <p class="service-row-desc"><?= htmlspecialchars($item['deskripsi']) ?></p>
                        </div>
                    </div>
                    <div class="service-row-right">
                        <a href="<?= htmlspecialchars($item['link']) ?>" class="service-row-btn">
                            PESAN
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

</body>
</html>
