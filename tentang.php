
<?php
session_start();

$nama = $_SESSION["nama"] ?? null;
$role = $_SESSION["role"] ?? null;
$login = isset($_SESSION["user_id"]);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tentang Kami - Polindra Suruh</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;600;700;800;900&family=Archivo+Black&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="assets/css/home.css?v=<?= filemtime(__DIR__ . '/assets/css/home.css') ?>">
</head>

<body class="ps-body">

    <!-- NAVBAR -->
    <header class="ps-nav">
        <div class="ps-container ps-nav-inner">

            <a href="index.php" class="ps-logo">
                <span class="ps-logo-mark">PS</span>
                <span>Polindra Suruh</span>
            </a>

            <nav class="ps-nav-links" id="psNavLinks">
                <a href="index.php#layanan">Layanan</a>
                <a href="index.php#cara-kerja">Cara Kerja</a>
                <a href="index.php#driver">Driver</a>
                <a href="tentang.php" class="ps-nav-active">Tentang</a>
            </nav>

            <div class="ps-nav-actions">

                <?php if ($login && $role === "admin"): ?>
                    <a href="admin/index.php" class="ps-btn ps-btn-dark ps-btn-sm">Dashboard</a>

                <?php elseif ($login && $role === "driver"): ?>
                    <a href="driver.php" class="ps-btn ps-btn-dark ps-btn-sm">Dashboard</a>

                <?php elseif ($login): ?>
                    <a href="pesanan_saya.php" class="ps-btn ps-btn-dark ps-btn-sm">Pesanan Saya</a>

                <?php else: ?>
                    <a href="login.php" class="ps-btn ps-btn-yellow ps-btn-sm">Masuk / Daftar</a>
                <?php endif; ?>

                <?php if ($login): ?>
                    <a href="logout.php" class="ps-btn ps-btn-ghost ps-btn-sm">Keluar</a>
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

        <!-- ABOUT HERO -->
        <section class="about-hero">
            <div class="ps-container about-hero-inner">

                <div class="about-hero-content">
                    <span class="ps-pill">
                        <i></i> POLINDRA SURUH - HUB
                    </span>

                    <h1 class="ps-hero-title">
                        TENTANG<br>
                        <span class="ps-hl">KAMI.</span>
                    </h1>

                    <p class="about-hero-desc">
                        Mengenal lebih dekat platform jasa suruh civitas akademika
                        Politeknik Negeri Indramayu. Solusi praktis, cepat, dan
                        andalan mahasiswa untuk memenuhi segala kebutuhan harian.
                    </p>

                    <a href="index.php#layanan" class="ps-btn ps-btn-dark">
                        Jelajahi Layanan &rarr;
                    </a>
                </div>

                <div class="about-hero-visual">
                    <div class="about-visual-circle"></div>

                    <div class="about-logo-card">
                        <img src="assets/img/logo-circle.jpg" alt="Logo Polindra Suruh">

                        <h3>POLINDRA<br>SURUH-HUB</h3>
                        <p>Jasa Suruh Apa Saja</p>

                        <div class="about-visual-status">
                            <span></span> SIAP MEMBANTU
                        </div>
                    </div>

                    <div class="about-floating-label">
                        DARI MAHASISWA,<br>UNTUK MAHASISWA.
                    </div>
                </div>

            </div>
        </section>

        <!-- DESKRIPSI -->
        <section class="ps-section about-intro">
            <div class="ps-container">

                <div class="ps-head">
                    <div>
                        <h2 class="ps-h2">
                            KENAL LEBIH DEKAT<br>
                            <span class="ps-pill-title">POLINDRA SURUH</span>
                        </h2>
                        <p class="ps-lead">
                            Satu platform untuk membantu aktivitas sehari-hari.
                        </p>
                    </div>
                </div>

                <div class="about-intro-card">
                    <div class="about-intro-number">01</div>

                    <div>
                        <h3>Solusi Praktis & Tanggap untuk Segala Kebutuhanmu.</h3>

                        <p>
                            <strong>Polindra Suruh</strong> merupakan platform jasa
                            suruh berbasis website yang dirancang khusus untuk
                            mempermudah aktivitas mahasiswa, dosen, serta civitas
                            akademika Politeknik Negeri Indramayu.

                            Mulai dari membelikan makanan, kebutuhan fotokopi dan
                            print tugas, hingga antar jemput barang penting saat
                            kamu sibuk praktikum atau kuliah padat.
                        </p>
                    </div>
                </div>

            </div>
        </section>

        <!-- KEUNGGULAN -->
        <section class="about-features-section">
            <div class="ps-container">

                <div class="ps-head">
                    <div>
                        <h2 class="ps-h2">
                            KENAPA<br>
                            <span class="ps-yellow">POLINDRA SURUH?</span>
                        </h2>
                        <p class="about-section-desc">
                            Dirancang untuk membuat setiap urusan menjadi lebih mudah.
                        </p>
                    </div>
                </div>

                <div class="about-features-grid">

                    <article class="about-feature-card">
                        <span>01</span>
                        <h3>Cepat & Sigap</h3>
                        <p>Pesanan langsung direspons oleh driver aktif kampus.</p>
                    </article>

                    <article class="about-feature-card">
                        <span>02</span>
                        <h3>Mitra Terpercaya</h3>
                        <p>Driver dari mahasiswa Polindra yang siap membantu.</p>
                    </article>

                    <article class="about-feature-card">
                        <span>03</span>
                        <h3>QRIS Digital</h3>
                        <p>Scan pembayaran praktis melalui e-wallet dan m-banking.</p>
                    </article>

                    <article class="about-feature-card">
                        <span>04</span>
                        <h3>Chat WhatsApp</h3>
                        <p>Koordinasi instruksi secara langsung dengan driver.</p>
                    </article>

                </div>
            </div>
        </section>

        <!-- VISI MISI -->
        <section class="ps-section about-values">
            <div class="ps-container">

                <div class="ps-head">
                    <div>
                        <h2 class="ps-h2">
                            LEBIH DARI<br>
                            <span class="ps-pill-title">SEKADAR JASA.</span>
                        </h2>
                        <p class="ps-lead">
                            Dibangun dari semangat kebersamaan mahasiswa.
                        </p>
                    </div>
                </div>

                <div class="ps-cards">

                    <article class="ps-card">
                        <span class="ps-card-num">01</span>
                        <h3>Visi & Misi</h3>
                        <p>
                            Menjadi platform andalan civitas kampus dalam
                            mempermudah mobilitas dan efisiensi harian,
                            sekaligus membuka peluang kerja sampingan yang
                            fleksibel, aman, dan bermanfaat bagi mahasiswa
                            Polindra sebagai mitra driver.
                        </p>
                    </article>

                    <article class="ps-card">
                        <span class="ps-card-num">02</span>
                        <h3>Solidaritas Kampus</h3>
                        <p>
                            Dibangun atas dasar semangat saling membantu
                            antar sesama mahasiswa. Kami mengutamakan
                            integritas, ketepatan waktu pengantaran, dan
                            transparansi tarif yang bersahabat di kantong mahasiswa.
                        </p>
                    </article>

                    <article class="ps-card about-contact-card">
                        <span class="ps-card-num">03</span>
                        <h3>Kontak & Informasi</h3>

                        <div class="about-contact-list">
                            <div>
                                <strong>Kampus Polindra</strong>
                                <p>Jl. Raya Lohbener Lama No. 08, Indramayu</p>
                            </div>

                            <div>
                                <strong>WhatsApp CS</strong>
                                <p>0895-0729-8091</p>
                            </div>

                            <div>
                                <strong>Instagram</strong>
                                <p>@polindrasuruh</p>
                            </div>

                            <div>
                                <strong>Jam Layanan</strong>
                                <p>Fleksibel mengikuti aktivitas kampus</p>
                            </div>
                        </div>
                    </article>

                </div>
            </div>
        </section>

        <!-- CTA -->
        <section class="ps-cta">
            <div class="ps-container">

                <div class="ps-cta-box">
                    <div>
                        <h2>Mager Keluar<br>atau Butuh Bantuan?</h2>
                        <p>
                            Percayakan kebutuhan harianmu kepada driver
                            Polindra Suruh sekarang.
                        </p>
                    </div>

                    <a href="index.php#driver" class="ps-btn ps-btn-dark">
    <?= !$login ? 'Masuk Sekarang' : 'Mulai Suruh Sekarang' ?> &rarr;
</a>
                </div>

            </div>
        </section>

    </main>

    <!-- FOOTER -->
    <footer class="ps-footer">
        <div class="ps-container ps-footer-inner">
            <div>
                &copy; <?= date('Y') ?> Polindra Suruh &bull;
                Kampus Polindra, Indramayu
            </div>

            <div class="ps-footer-links">
                <a href="index.php#layanan">Layanan</a>
                <a href="index.php#cara-kerja">Cara Kerja</a>
                <a href="index.php#driver">Driver</a>
                <a href="tentang.php">Tentang</a>
            </div>
        </div>
    </footer>

</body>
</html>
