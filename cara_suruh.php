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

    <title>Cara Suruh - Polindra Suruh</title>

    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>

<body class="page-cara-suruh">

    <?php require_once __DIR__ . '/includes/navbar.php'; ?>

    <!-- HEADER -->
    <section class="header">
        <small>POLINDRA SURUH-HUB</small>

        <h1>
            CARA <span>SURUH</span>
        </h1>

        <p>
            Mager keluar atau butuh bantuan tugas dan belanja?
            Ikuti beberapa langkah mudah berikut untuk memesan jasa
            driver mahasiswa Polindra Suruh.
        </p>
    </section>

    <!-- LANGKAH PEMESANAN -->
    <section class="steps">

        <!-- LANGKAH 1 -->
        <div class="step">
            <div class="step-number">01</div>
            <div class="step-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            </div>

            <h2>Pilih Driver Aktif</h2>

            <p>
                Buka halaman Pilih Driver untuk melihat driver yang sedang online,
                atau kamu bisa langsung membuat pesanan untuk ditugaskan ke driver yang tersedia.
            </p>
        </div>

        <!-- LANGKAH 2 -->
        <div class="step">
            <div class="step-number">02</div>
            <div class="step-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            </div>

            <h2>Tulis Kebutuhan Pesanan</h2>

            <p>
                Tuliskan secara jelas barang yang ingin dibeli, bantuan yang dibutuhkan,
                atau instruksi khusus pada formulir Buat Pesanan.
            </p>
        </div>

        <!-- LANGKAH 3 -->
        <div class="step">
            <div class="step-number">03</div>
            <div class="step-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path d="M8 14h.01"/><path d="M12 14h.01"/><path d="M16 14h.01"/><path d="M8 18h.01"/><path d="M12 18h.01"/><path d="M16 18h.01"/></svg>
            </div>

            <h2>Konfirmasi & Hitung Tarif</h2>

            <p>
                Untuk layanan Food, Galon, Fotokopi & Antar Jemput, tarif otomatis dihitung berdasarkan jarak GPS. Driver akan meninjau dan menerima pesananmu.
            </p>
        </div>

        <!-- LANGKAH 4 -->
        <div class="step">
            <div class="step-number">04</div>
            <div class="step-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><path d="M7 17h.01"/><path d="M17 7h.01"/><path d="M7 7h.01"/><path d="M17 17h.01"/></svg>
            </div>

            <h2>Bayar via QRIS Resmi & Verifikasi</h2>

            <p>
                Bayar pesananmu dengan 1 QRIS resmi Polindra Suruh (DANA, Gopay, BCA, dll). Unggah bukti bayar agar diverifikasi dan pesanan langsung diproses.
            </p>
        </div>

        <!-- LANGKAH 5 -->
        <div class="step">
            <div class="step-number">05</div>
            <div class="step-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
            </div>

            <h2>Driver Menjalankan Pesanan</h2>

            <p>
                Driver segera membelikan dan mengantar pesananmu. Kamu juga bisa langsung
                berkomunikasi secara mudah lewat tombol chat WhatsApp ke driver.
            </p>
        </div>

        <!-- LANGKAH 6 -->
        <div class="step">
            <div class="step-number">06</div>
            <div class="step-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            </div>

            <h2>Pesanan Selesai</h2>

            <p>
                Pesananmu telah sampai dengan aman! Driver akan menandai pesanan selesai
                dan riwayat tersimpan rapi di halaman Pesanan Saya.
            </p>
        </div>

    </section>

    <!-- ALUR PEMESANAN -->
    <section class="flow">
        <div class="flow-title">
            <h2>Alur Pemesanan</h2>
            <p>Alur praktis dari awal pesan hingga barang sampai ke tanganmu.</p>
        </div>

        <div class="flow-list">

            <div class="flow-item">
                <div class="flow-icon">1</div>
                <h3>Pilih Driver</h3>
                <p>Pilih driver aktif</p>
            </div>

            <div class="arrow">→</div>

            <div class="flow-item">
                <div class="flow-icon">2</div>
                <h3>Pesan</h3>
                <p>Isi detail kebutuhan</p>
            </div>

            <div class="arrow">→</div>

            <div class="flow-item">
                <div class="flow-icon">3</div>
                <h3>Bayar QRIS</h3>
                <p>Scan & kirim bukti</p>
            </div>

            <div class="arrow">→</div>

            <div class="flow-item">
                <div class="flow-icon">4</div>
                <h3>Diproses</h3>
                <p>Driver memproses pesanan</p>
            </div>

            <div class="arrow">→</div>

            <div class="flow-item">
                <div class="flow-icon">5</div>
                <h3>Selesai</h3>
                <p>Pesanan tuntas</p>
            </div>

        </div>
    </section>

    <!-- TOMBOL MULAI SURUH -->
    <div class="bottom">
        <a
            href="<?= !$login
                ? 'login.php?redirect=pilih_driver'
                : ($role === 'user'
                    ? 'pilih_driver.php'
                    : ($role === 'admin'
                        ? 'admin/index.php'
                        : 'driver.php')) ?>"
            class="order-button">

            <?= !$login
                ? 'LOGIN UNTUK MULAI SURUH'
                : ($role === 'user'
                    ? 'MULAI SURUH SEKARANG'
                    : 'BUKA DASHBOARD') ?>

        </a>
    </div>

</body>
</html>
