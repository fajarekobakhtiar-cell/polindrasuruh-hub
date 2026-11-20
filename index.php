<?php
session_start();
require_once "koneksi.php";

$nama = $_SESSION["nama"] ?? null;
$role = $_SESSION["role"] ?? null;

$login = isset($_SESSION["user_id"]);


$sqlDrivers = "SELECT u.id, u.nama, COALESCE(d.status_aktif, u.status_aktif, 0) AS status_aktif, d.no_hp
               FROM users u
               LEFT JOIN drivers d ON d.user_id = u.id
               WHERE u.role = 'driver'
               ORDER BY status_aktif DESC, u.nama ASC";
$stmtDrivers = $pdo->query($sqlDrivers);
$daftarDriver = $stmtDrivers->fetchAll(PDO::FETCH_ASSOC);

try {
    $stmtLayanan = $pdo->query("SELECT * FROM layanan WHERE status_aktif = 1 ORDER BY urutan ASC, id ASC");
    $daftarLayanan = $stmtLayanan->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $daftarLayanan = [];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Polindra Suruh - Jasa Suruh Apa Saja</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>
<body class="page-home">

    <!-- NAVBAR -->
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>

    <div class="home-main">
        <section class="hero-section">
            <div class="circle-one"></div>
            <div class="circle-two"></div>

            <div class="hero-glass-card">
                <div class="hero-badge">
                    <span class="hero-badge-dot"></span>
                    <span>POLINDRA SURUH</span>
                </div>

                <h1 class="hero-main-title">
                    Mager? <span>Suruh Kami.</span>
                </h1>

                <p class="hero-pitch">
                    Layanan jasa suruh apa saja untuk Mahasiswa Polindra dan Masyarakat sekitar.
                </p>

                <div class="hero-cta-group">
                    <?php if ($login && ($role === "user" || !$role)): ?>
                        <a href="layanan.php" class="btn-black-solid">Mulai Suruh Sekarang</a>
                        <a href="pesanan_saya.php" class="btn-white-outline">Pesanan Saya</a>
                    <?php elseif ($role === "admin"): ?>
                        <a href="admin/index.php" class="btn-black-solid">Buka Dashboard Admin</a>
                    <?php elseif ($role === "driver"): ?>
                        <a href="driver.php" class="btn-black-solid">Dashboard Driver</a>
                    <?php else: ?>
                        <a href="layanan.php" class="btn-black-solid">Mulai Suruh Sekarang</a>
                        <a href="login.php" class="btn-white-outline">Masuk / Daftar</a>
                    <?php endif; ?>
                </div>
            </div>
        </section>


        <section class="section-wrap">
            
            <!-- LAYANAN YANG TERSEDIA (MENYATU DALAM SATU CARD CONTAINER) -->
            <div class="section-intro">
                <h2>Layanan yang Tersedia</h2>
                <p>Pilih bantuan yang kamu butuhkan hari ini</p>
            </div>

            <div class="services-unified-card">
                <?php if (!empty($daftarLayanan)): ?>
                    <?php foreach ($daftarLayanan as $svc): ?>
                        <div class="service-row-item">
                            <div class="service-row-left">
                                <span class="service-num-badge"><?= htmlspecialchars($svc['nomor'] ?? sprintf('%02d', (int)($svc['urutan'] ?? 1))) ?></span>
                                <div class="service-row-content">
                                    <h3 class="service-row-title"><?= htmlspecialchars($svc['nama']) ?></h3>
                                    <p class="service-row-desc"><?= htmlspecialchars($svc['deskripsi']) ?></p>
                                </div>
                            </div>
                            <div class="service-row-right">
                                <a href="order.php?layanan=<?= urlencode($svc['nama']) ?>" class="service-row-btn">
                                    Pesan &rarr;
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p style="padding: 20px; color: #64748b; font-size: 13.5px;">Layanan sedang dimuat...</p>
                <?php endif; ?>
            </div>

            <!-- DAFTAR DRIVER SIAGA KAMPUS (MENYATU DALAM SATU CARD CONTAINER) -->
            <div class="section-intro" style="margin-top: 36px;">
                <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px;">
                    <div>
                        <h2>Driver</h2>
                        <p>Daftar driver yang siap di suruh hari ini</p>
                    </div>
                    <?php if (!empty($daftarDriver)): ?>
                        <div class="driver-home-tabs">
                            <button type="button" class="tab-btn-home active" onclick="filterHomeDrivers('all', this)">Semua (<?= count($daftarDriver) ?>)</button>
                            <button type="button" class="tab-btn-home" onclick="filterHomeDrivers('active', this)">Siaga (<?= count(array_filter($daftarDriver, fn($d) => (int)$d['status_aktif'] === 1)) ?>)</button>
                            <button type="button" class="tab-btn-home" onclick="filterHomeDrivers('offline', this)">Offline (<?= count(array_filter($daftarDriver, fn($d) => (int)$d['status_aktif'] !== 1)) ?>)</button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="drivers-unified-card">
                <?php if (!empty($daftarDriver)): ?>
                    <?php foreach ($daftarDriver as $d): ?>
                        <?php $isOnline = ((int)$d['status_aktif'] === 1); ?>
                        <div class="driver-row-item <?= $isOnline ? 'online' : 'offline' ?>" data-status="<?= $isOnline ? 'active' : 'offline' ?>">
                            <div class="driver-row-left">
                                <div class="driver-avatar-home <?= $isOnline ? 'avatar-online' : 'avatar-offline' ?>">
                                    <?= strtoupper(substr($d['nama'], 0, 1)) ?>
                                </div>
                                <div class="driver-row-details">
                                    <h4><?= htmlspecialchars($d['nama']) ?></h4>
                                    <span class="status-indicator <?= $isOnline ? 'st-online' : 'st-offline' ?>">
                                        <?= $isOnline ? '● Siaga / Aktif' : '○ Offline' ?>
                                    </span>
                                </div>
                            </div>

                            <div class="driver-row-right">
                                <?php if ($isOnline): ?>
                                    <a href="order.php?driver_id=<?= (int)$d['id'] ?>" class="btn-choose-driver-compact">
                                        Suruh Driver &rarr;
                                    </a>
                                <?php else: ?>
                                    <span class="btn-disabled-driver-compact">
                                        Offline
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p style="padding: 20px; color: #64748b; font-size: 13.5px; font-weight: 600;">Belum ada driver yang terdaftar di sistem.</p>
                <?php endif; ?>
            </div>

            <!-- KOTAK INFO SINGKAT -->
            <div class="how-box">
                <div class="how-text">
                    <h3>Mau Suruh Apa Hari Ini?</h3>
                    <p>Tinggal pilih layanan atau driver, tulis pesanan dan lokasimu. </p>
                </div>
                <a href="layanan.php" class="how-btn">Suruh Sekarang</a>
            </div>
        </section>

        <!-- FOOTER SEDERHANA -->
        <footer class="simple-footer">
            <div>
                &copy; <?= date('Y') ?> Polindra Suruh &bull; Kampus Polindra, Indramayu
            </div>
            <div>
                <a href="layanan.php">Layanan</a>
                <a href="cara_suruh.php">Cara Suruh</a>
                <a href="tentang.php">Tentang</a>
            </div>
        </footer>

    </div>

    <script>
    function filterHomeDrivers(status, btn) {
        document.querySelectorAll('.tab-btn-home').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');

        document.querySelectorAll('.driver-row-item').forEach(card => {
            if (status === 'all') {
                card.style.display = 'flex';
            } else if (card.getAttribute('data-status') === status) {
                card.style.display = 'flex';
            } else {
                card.style.display = 'none';
            }
        });
    }
    </script>

</body>
</html>
