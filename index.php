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

$totalDriver   = count($daftarDriver);
$totalSiaga    = count(array_filter($daftarDriver, fn($d) => (int)$d['status_aktif'] === 1));
$totalOffline  = $totalDriver - $totalSiaga;

// Teks berjalan: ambil dari nama layanan, fallback ke default
$marqueeItems = array_map(fn($s) => $s['nama'], $daftarLayanan);
if (empty($marqueeItems)) {
    $marqueeItems = ['Titip Makanan', 'Antar Barang', 'Belanja', 'Fotokopi & Print', 'Antar Jemput', 'Apa Saja'];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Polindra Suruh - Jasa Suruh Apa Saja</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;700;800;900&family=Archivo+Black&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/home.css?v=<?= filemtime(__DIR__ . '/assets/css/home.css') ?>">
</head>
<body class="ps-body">

    <!-- 1. NAVBAR MINIMALIS -->
    <header class="ps-nav">
        <div class="ps-container ps-nav-inner">
            <a href="index.php" class="ps-logo">
                <span class="ps-logo-mark">PS</span>
                <span>Polindra Suruh</span>
            </a>

<nav class="ps-nav-links" id="psNavLinks">
    <a href="#layanan">Layanan</a>
    <a href="#cara-kerja">Cara Kerja</a>
    <a href="#driver">Driver</a>
    <a href="tentang.php">Tentang</a>
</nav>

<div class="ps-nav-actions">

    <?php if ($login && $role === "admin"): ?>
        <a href="admin/index.php" class="ps-btn ps-btn-dark ps-btn-sm">
            Dashboard
        </a>

    <?php elseif ($login && $role === "driver"): ?>
        <a href="driver.php" class="ps-btn ps-btn-dark ps-btn-sm">
            Dashboard
        </a>

    <?php elseif ($login): ?>
        <a href="pesanan_saya.php" class="ps-btn ps-btn-dark ps-btn-sm">
            Pesanan Saya
        </a>

    <?php else: ?>
        <a href="login.php" class="ps-btn ps-btn-yellow ps-btn-sm">
            Masuk / Daftar
        </a>
    <?php endif; ?>

    <?php if ($login): ?>
        <a href="logout.php" class="ps-btn ps-btn-ghost ps-btn-sm">
            Keluar
        </a>
    <?php endif; ?>

    <button type="button" class="ps-burger"
        aria-label="Menu"
        onclick="document.getElementById('psNavLinks').classList.toggle('open')">
        <span></span>
        <span></span>
        <span></span>
    </button>

</div>
        </div>
    </header>

    <main>

        <!-- 2. HERO -->
        <section class="ps-hero">
            <div class="ps-container ps-hero-inner">
                <div class="ps-hero-text">
                    <span class="ps-pill"><i></i> POLINDRA SURUH</span>

                    <h1 class="ps-hero-title">
                        Mager?<br>
                        <span class="ps-hl">Suruh</span> Kami.
                    </h1>

                    <p class="ps-hero-sub">
                        Layanan jasa suruh apa saja untuk Mahasiswa Polindra dan Masyarakat sekitar.
                    </p>

                    <div class="ps-hero-cta">
                        <?php if ($login && ($role === "user" || !$role)): ?>
                            <a href="#layanan" class="ps-btn ps-btn-yellow">Mulai Suruh Sekarang &rarr;</a>
                            <a href="pesanan_saya.php" class="ps-btn ps-btn-ghost">Pesanan Saya</a>
                        <?php elseif ($role === "admin"): ?>
                            <a href="admin/index.php" class="ps-btn ps-btn-yellow">Buka Dashboard Admin &rarr;</a>
                        <?php elseif ($role === "driver"): ?>
                            <a href="driver.php" class="ps-btn ps-btn-yellow">Dashboard Driver &rarr;</a>
                        <?php else: ?>
                            <a href="layanan.php" class="ps-btn ps-btn-yellow">Mulai Suruh Sekarang &rarr;</a>
                            <a href="login.php" class="ps-btn ps-btn-ghost">Masuk / Daftar</a>
                        <?php endif; ?>
                    </div>

                    <div class="ps-hero-stats">
                        <div><strong><?= $totalSiaga ?></strong><span>Driver siaga</span></div>
                        <div><strong><?= count($daftarLayanan) ?></strong><span>Layanan</span></div>
                        <div><strong>24/7</strong><span>Pesan kapan saja</span></div>
                    </div>
                </div>

                <!-- Maskot -->
                <div class="ps-hero-mascot">
                    <div class="ps-mascot-blob"></div>
                    <div class="ps-sticker">Siap<br>Ngebut!</div>
                    <picture>
                        <source srcset="assets/img/mascot.webp" type="image/webp">
                        <img src="assets/img/mascot.png" alt="Maskot driver Polindra Suruh" class="ps-mascot-img" width="941" height="1672" fetchpriority="high">
                    </picture>
                </div>
            </div>
        </section>

        <!-- 3. RUNNING TEXT -->
        <section class="ps-marquee" aria-label="Kategori layanan">
            <div class="ps-marquee-track">
                <?php for ($loop = 0; $loop < 4; $loop++): ?>
                    <?php foreach ($marqueeItems as $item): ?>
                        <span><?= htmlspecialchars($item) ?></span><b>&#10038;</b>
                    <?php endforeach; ?>
                <?php endfor; ?>
            </div>
        </section>

        <!-- 4. KARTU LAYANAN -->
        <section class="ps-section" id="layanan">
            <div class="ps-container">
                <div class="ps-head">
                    <div>
                        <h2 class="ps-h2">Layanan <span class="ps-pill-title">Tersedia</span><br>Buat Kamu</h2>
                        <p class="ps-lead">Pilih bantuan yang kamu butuhkan hari ini.</p>
                    </div>
<a href="#layanan" class="ps-btn ps-btn-yellow ps-btn-sm" style="margin-top: 12px;">Lihat Semua</a>
</div>

                <div class="ps-cards">
                    <?php if (!empty($daftarLayanan)): ?>
                        <?php foreach ($daftarLayanan as $i => $svc): ?>
                            <article class="ps-card <?= $i === 0 ? 'ps-card-featured' : '' ?>">
                                <span class="ps-card-num"><?= htmlspecialchars($svc['nomor'] ?? sprintf('%02d', (int)($svc['urutan'] ?? ($i + 1)))) ?></span>
                                <h3><?= htmlspecialchars($svc['nama']) ?></h3>
                                <p><?= htmlspecialchars($svc['deskripsi']) ?></p>
                                <a href="order.php?layanan=<?= urlencode($svc['nama']) ?>" class="ps-card-link">Pesan &rarr;</a>
                            </article>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="ps-empty">Layanan sedang dimuat...</p>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- 5. CARA KERJA (BACKGROUND HITAM) -->
        <section class="ps-how" id="cara-kerja">
            <div class="ps-container">
                <h2 class="ps-h2 ps-h2-light">Cara Kerja<br><span class="ps-yellow">Gampang Banget.</span></h2>

                <div class="ps-steps">
                    <div class="ps-step">
                        <span>01</span>
                        <h3>Pilih Layanan</h3>
                        <p>Pilih jenis bantuan atau langsung pilih driver yang sedang siaga.</p>
                    </div>
                    <div class="ps-step">
                        <span>02</span>
                        <h3>Tulis Pesanan</h3>
                        <p>Jelaskan apa yang kamu butuhkan dan tentukan lokasinya.</p>
                    </div>
                    <div class="ps-step">
                        <span>03</span>
                        <h3>Driver Berangkat</h3>
                        <p>Driver menerima pesananmu dan langsung bergerak mengerjakannya.</p>
                    </div>
                    <div class="ps-step">
                        <span>04</span>
                        <h3>Beres, Terima</h3>
                        <p>Pesanan sampai di tangan kamu. Tinggal santai, tidak perlu mager lagi.</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- 6. DAFTAR DRIVER AKTIF -->
        <section class="ps-section" id="driver">
            <div class="ps-container">
                <div class="ps-head">
                    <div>
                        <h2 class="ps-h2">Driver <span class="ps-pill-title">Siaga</span><br>Hari Ini</h2>
                        <p class="ps-lead">Daftar driver yang siap disuruh hari ini.</p>
                    </div>
                    <?php if (!empty($daftarDriver)): ?>
                        <div class="ps-tabs">
                            <button type="button" class="ps-tab active" onclick="filterHomeDrivers('all', this)">Semua (<?= $totalDriver ?>)</button>
                            <button type="button" class="ps-tab" onclick="filterHomeDrivers('active', this)">Siaga (<?= $totalSiaga ?>)</button>
                            <button type="button" class="ps-tab" onclick="filterHomeDrivers('offline', this)">Offline (<?= $totalOffline ?>)</button>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="ps-drivers">
                    <?php if (!empty($daftarDriver)): ?>
                        <?php foreach ($daftarDriver as $d): ?>
                            <?php $isOnline = ((int)$d['status_aktif'] === 1); ?>
                            <div class="ps-driver <?= $isOnline ? 'online' : 'offline' ?>" data-status="<?= $isOnline ? 'active' : 'offline' ?>">
                                <div class="ps-driver-avatar"><?= strtoupper(substr($d['nama'], 0, 1)) ?></div>
                                <div class="ps-driver-info">
                                    <h4><?= htmlspecialchars($d['nama']) ?></h4>
                                    <span class="ps-status <?= $isOnline ? 'on' : 'off' ?>">
                                        <?= $isOnline ? 'Siaga / Aktif' : 'Offline' ?>
                                    </span>
                                </div>
                                <?php if ($isOnline): ?>
                                    <a href="order.php?driver_id=<?= (int)$d['id'] ?>" class="ps-btn ps-btn-dark ps-btn-sm">Suruh &rarr;</a>
                                <?php else: ?>
                                    <span class="ps-btn-disabled">Offline</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="ps-empty">Belum ada driver yang terdaftar di sistem.</p>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- 7. CTA PEMESANAN -->
        <section class="ps-cta">
            <div class="ps-container">
                <div class="ps-cta-box">
                    <div>
                        <h2>Mau Suruh Apa Hari Ini?</h2>
                        <p>Tinggal pilih layanan atau driver, tulis pesanan dan lokasimu.</p>
                    </div>
                    <a href="index.php#layanan" class="ps-btn ps-btn-dark">Suruh Sekarang &rarr;</a>
                </div>
            </div>
        </section>
    </main>

    <!-- 8. FOOTER -->
    <footer class="ps-footer">
        <div class="ps-container ps-footer-inner">
            <div>&copy; <?= date('Y') ?> Polindra Suruh &bull; Kampus Polindra, Indramayu</div>
            <div class="ps-footer-links">
                <a href="layanan.php">Layanan</a>
                <a href="cara_suruh.php">Cara Suruh</a>
                <a href="tentang.php">Tentang</a>
            </div>
        </div>
    </footer>

    <script>
    function filterHomeDrivers(status, btn) {
        document.querySelectorAll('.ps-tab').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');

        document.querySelectorAll('.ps-driver').forEach(card => {
            card.style.display = (status === 'all' || card.getAttribute('data-status') === status) ? 'flex' : 'none';
        });
    }
    </script>

</body>
</html>