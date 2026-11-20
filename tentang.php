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

    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>
<body>

    <?php require_once __DIR__ . '/includes/navbar.php'; ?>

    <!-- HEADER -->
    <section class="header">
        <small>POLINDRA SURUH-HUB</small>

        <h1>
            TENTANG <span>KAMI</span>
        </h1>

        <p>
            Mengenal lebih dekat platform jasa suruh civitas akademika Politeknik Negeri Indramayu.
            Solusi praktis, cepat, dan andalan mahasiswa untuk memenuhi segala kebutuhan harian.
        </p>
    </section>

    <!-- MAIN ABOUT HERO CARD -->
    <section class="about-main-card">
        <div>
            <div class="about-badge">
                <span class="about-badge-dot"></span>
                <span>PLATFORM SURUH MAHASISWA</span>
            </div>

            <h2 class="about-title">
                Solusi Praktis & Tanggap untuk <span>Segala Kebutuhanmu</span>
            </h2>

            <p class="about-desc">
                <strong>Polindra Suruh</strong> merupakan platform jasa suruh berbasis website yang dirancang
                khusus untuk mempermudah aktivitas mahasiswa, dosen, serta civitas akademika Politeknik Negeri Indramayu.
                Mulai dari membelikan makanan, kebutuhan fotokopi & print tugas, hingga antar jemput barang penting saat kamu sibuk praktikum atau kuliah padat.
            </p>

            <div class="about-features">
                <div class="about-feature-item">
                    
                    <div>
                        <strong>Cepat & Sigap</strong>
                        <p>Pesanan langsung direspons oleh driver aktif kampus.</p>
                    </div>
                </div>

                <div class="about-feature-item">
                    
                    <div>
                        <strong>Mitra Terpercaya</strong>
                        <p>Driver resmi dari mahasiswa Polindra yang amanah.</p>
                    </div>
                </div>

                <div class="about-feature-item">
                    
                    <div>
                        <strong>QRIS Digital</strong>
                        <p>Scan pembayaran praktis via e-wallet & m-banking.</p>
                    </div>
                </div>

                <div class="about-feature-item">
                    
                    <div>
                        <strong>Chat WhatsApp</strong>
                        <p>Koordinasi instruksi secara langsung dengan driver.</p>
                    </div>
                </div>
            </div>
        </div>

        <div>
            <div class="about-visual-box">
                <img src="assets/img/logo-circle.jpg" alt="Logo Polindra Suruh" style="width: 76px; height: 76px; border-radius: 50%; object-fit: cover; border: 3px solid #ffd21f; box-shadow: 0 6px 18px rgba(0, 0, 0, 0.35); margin-bottom: 14px;">
                <h3>Polindra Suruh-Hub</h3>
                <p class="sub">Jasa Suruh Apa Saja Kampus Polindra</p>

                <div class="about-visual-status-pill">
                    <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #4ade80; box-shadow: 0 0 8px #4ade80;"></span>
                    <span>Driver Aktif & Siap Melayani</span>
                </div>

                <div class="about-visual-stats">
                    <div class="about-visual-stat-item">
                        <strong>100%</strong>
                        <span>Mahasiswa</span>
                    </div>
                    <div class="about-visual-stat-item">
                        <strong>QRIS</strong>
                        <span>Scan Praktis</span>
                    </div>
                    <div class="about-visual-stat-item">
                        <strong>Gercep</strong>
                        <span>Siap Membantu</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- 3 PILLAR CARDS -->
    <section class="about-grid-three">
        <div class="about-card-pillar">
            <div class="pillar-icon-box">01</div>
            <h3>Visi & Misi</h3>
            <p>
                Menjadi platform andalan civitas kampus dalam mempermudah mobilitas dan efisiensi harian,
                sekaligus membuka peluang kerja sampingan yang fleksibel, aman, dan bermanfaat
                bagi mahasiswa Polindra sebagai mitra driver.
            </p>
        </div>

        <div class="about-card-pillar">
            <div class="pillar-icon-box">02</div>
            <h3>Solidaritas Kampus</h3>
            <p>
                Dibangun atas dasar semangat saling membantu antar sesama mahasiswa.
                Kami mengutamakan integritas, ketepatan waktu pengantaran, dan transparansi tarif
                yang bersahabat di kantong mahasiswa.
            </p>
        </div>

        <div class="about-card-pillar">
            <div class="pillar-icon-box">03</div>
            <h3>Kontak & Informasi</h3>
            <div style="margin-top: 14px;">
                <div class="contact-row-item">
                    
                    <div>
                        <strong>Kampus Polindra</strong>
                        <div style="font-size: 11px; color: #64748b;">Jl. Raya Lohbener Lama No. 08, Indramayu</div>
                    </div>
                </div>

                <div class="contact-row-item">
                    
                    <div>
                        <strong>WhatsApp CS</strong>
                        <div style="font-size: 11px; color: #047857; font-weight: 700;">0895-0729-8091</div>
                    </div>
                </div>

                <div class="contact-row-item">
                    
                    <div>
                        <strong>Instagram</strong>
                        <div style="font-size: 11px; color: #64748b;">@polindrasuruh</div>
                    </div>
                </div>

                <div class="contact-row-item">
                    
                    <div>
                        <strong>Jam Layanan</strong>
                        <div style="font-size: 11px; color: #64748b;">Fleksibel mengikuti jadwal aktifitas kampus</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- BOTTOM CTA BANNER -->
    <section class="info" style="margin-bottom: 60px;">
        <div class="info-text">
            <h2>Mager Keluar atau Butuh Bantuan Cepat?</h2>
            <p>
                Percayakan kebutuhan belanja kantin, antar jemput barang, dan suruhan harianmu
                kepada driver mahasiswa Polindra Suruh sekarang.
            </p>
        </div>

        <a href="<?= !$login
            ? 'login.php?redirect=pilih_driver'
            : ($role === 'user'
                ? 'pilih_driver.php'
                : ($role === 'admin'
                    ? 'admin/index.php'
                    : 'driver.php')) ?>"
           class="info-button">
            <?= !$login ? 'LOGIN UNTUK PESAN' : 'MULAI SURUH SEKARANG →' ?>
        </a>
    </section>

</body>
</html>