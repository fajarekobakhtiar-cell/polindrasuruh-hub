<?php
session_start();
require_once '../koneksi.php';
require_once '../includes/auth.php';

requireLogin();
requireRole('admin');

$totalPesananAll      = (int) $pdo->query("SELECT COUNT(*) FROM pesanan")->fetchColumn();
$totalMenunggu        = (int) $pdo->query("SELECT COUNT(*) FROM pesanan WHERE status IN ('Menunggu', 'Menunggu Driver', 'Menunggu Persetujuan Driver')")->fetchColumn();
$totalMenungguBayar   = (int) $pdo->query("SELECT COUNT(*) FROM pesanan WHERE status = 'Menunggu Pembayaran'")->fetchColumn();
$totalVerifikasiQris  = (int) $pdo->query("SELECT COUNT(*) FROM pesanan WHERE status = 'Diproses' AND bukti_bayar IS NOT NULL AND (qris_diverifikasi = 0 OR qris_diverifikasi IS NULL)")->fetchColumn();
$totalSelesai         = (int) $pdo->query("SELECT COUNT(*) FROM pesanan WHERE status = 'Selesai'")->fetchColumn();

$totalDrivers  = (int) $pdo->query("SELECT COUNT(*) FROM drivers")->fetchColumn();
$driversOnline = (int) $pdo->query("SELECT COUNT(*) FROM drivers WHERE status_aktif = 1")->fetchColumn();
$totalUsers    = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn();
$totalPenarikanMenunggu = (int) $pdo->query("SELECT COUNT(*) FROM penarikan_dana WHERE status = 'Menunggu'")->fetchColumn();

// =========================================================================
// AMBIL 10 PESANAN TERBARU
// =========================================================================
$stmtPesananTerbaru = $pdo->query("
    SELECT
        p.id,
        p.catatan,
        p.status,
        p.bukti_bayar,
        p.qris_diverifikasi,
        p.created_at,
        u.nama  AS nama_pelanggan,
        ud.nama AS nama_driver
    FROM pesanan p
    INNER JOIN users u  ON p.user_id  = u.id
    LEFT  JOIN users ud ON p.driver_id = ud.id
    ORDER BY p.id DESC
    LIMIT 10
");
$pesananTerbaru = $stmtPesananTerbaru->fetchAll(PDO::FETCH_ASSOC);

$stmtDrivers = $pdo->query("
    SELECT
        d.id AS driver_id,
        d.nama_driver,
        d.no_hp,
        d.status_aktif,
        u.email,
        (SELECT COUNT(*) FROM pesanan WHERE driver_id = d.user_id AND status = 'Selesai') AS total_selesai
    FROM drivers d
    INNER JOIN users u ON d.user_id = u.id
    ORDER BY d.status_aktif DESC, d.id DESC
    LIMIT 6
");
$daftarDrivers = $stmtDrivers->fetchAll(PDO::FETCH_ASSOC);

$activeAdminPage = 'overview';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Polindra Suruh</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700;800&family=Archivo+Black&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
    <?php $adminCssFile = __DIR__ . '/../assets/css/admin.css'; ?>
    <link rel="stylesheet" href="../assets/css/admin.css?v=<?= file_exists($adminCssFile) ? filemtime($adminCssFile) : time() ?>">
</head>
<body class="page-admin page-admin-index">

<div class="dashboard-container">

    <?php include '../includes/admin_navbar.php'; ?>

    <!-- HIGHLIGHT PERHATIAN ADMIN -->
    <?php if ($totalPenarikanMenunggu > 0): ?>
        <div class="adm-alert">
            <div class="adm-alert-text">
                <strong>Ada <?= $totalPenarikanMenunggu ?> pengajuan penarikan dana driver menunggu transfer!</strong>
            </div>
            <a href="penarikan.php?status=Menunggu" class="adm-alert-btn">Proses Penarikan &rarr;</a>
        </div>
    <?php endif; ?>

    <?php if ($totalVerifikasiQris > 0): ?>
        <div class="adm-alert">
            <div class="adm-alert-text">
                <strong>Ada <?= $totalVerifikasiQris ?> bukti pembayaran QRIS yang memerlukan konfirmasi/verifikasi!</strong>
            </div>
            <a href="pesanan.php?status=Diproses" class="adm-alert-btn">Periksa Pesanan &rarr;</a>
        </div>
    <?php endif; ?>

    <!-- STATS METRICS -->
    <section class="stats-grid">
        <div class="stat-card">
            
            <div class="stat-info">
                <div class="stat-val"><?= number_format($totalPesananAll) ?></div>
                <div class="stat-label">Total Pesanan Masuk</div>
            </div>
        </div>
        <div class="stat-card">
            
            <div class="stat-info">
                <div class="stat-val"><?= number_format($totalMenunggu + $totalMenungguBayar) ?></div>
                <div class="stat-label">Pesanan Menunggu</div>
            </div>
        </div>
        <div class="stat-card">
            
            <div class="stat-info">
                <div class="stat-val"><?= $driversOnline ?> / <?= $totalDrivers ?></div>
                <div class="stat-label">Driver Online / Total</div>
            </div>
        </div>
        <div class="stat-card">
            
            <div class="stat-info">
                <div class="stat-val"><?= number_format($totalUsers) ?></div>
                <div class="stat-label">Pelanggan Terdaftar</div>
            </div>
        </div>
    </section>

    <!-- QUICK ACTIONS -->
    <div class="quick-actions">
        <a href="penarikan.php" class="quick-action-card">
            <div><strong>Kelola Penarikan Dana</strong><span>Transfer saldo hasil orderan driver</span></div>
            
        </a>
        <a href="driver.php"  class="quick-action-card">
            <div><strong>Kelola & Tambah Driver</strong><span>Atur akun driver & status kerja</span></div>
            
        </a>
        <a href="pesanan.php" class="quick-action-card">
            <div><strong>Kelola & Buat Pesanan</strong><span>Verifikasi QRIS & status driver</span></div>
            
        </a>
        <a href="user.php"    class="quick-action-card">
            <div><strong>Kelola & Tambah Pelanggan</strong><span>Daftar akun pengguna aplikasi</span></div>
            
        </a>
    </div>

    <!-- PESANAN TERBARU -->
    <div class="dash-card">
        <div class="dash-card-header">
            <h2 class="dash-card-title">Pesanan Terbaru (10 Terakhir)</h2>
            <div class="dash-card-actions">
                <a href="pesanan.php" class="btn btn-secondary btn-sm">Lihat Semua Pesanan &rarr;</a>
            </div>
        </div>

        <?php if (empty($pesananTerbaru)): ?>
            <p class="adm-empty">Belum ada riwayat pesanan.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="dash-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Pelanggan</th>
                            <th>Catatan Pesanan</th>
                            <th>Driver Ditugaskan</th>
                            <th>Status</th>
                            <th>QRIS / Bukti</th>
                            <th>Waktu</th>
                            <th class="adm-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pesananTerbaru as $p):
                            $statusClass = 'menunggu';
                            if ($p['status'] === 'Diproses')           $statusClass = 'proses';
                            elseif ($p['status'] === 'Dalam Perjalanan') $statusClass = 'antar';
                            elseif ($p['status'] === 'Selesai')          $statusClass = 'selesai';
                            elseif ($p['status'] === 'Dibatalkan')       $statusClass = 'batal';
                            elseif ($p['status'] === 'Menunggu Pembayaran') $statusClass = 'bayar';
                        ?>
                            <tr>
                                <td><strong>#<?= $p['id'] ?></strong></td>
                                <td><strong><?= htmlspecialchars($p['nama_pelanggan']) ?></strong></td>
                                <td class="adm-note"
                                    title="<?= htmlspecialchars($p['catatan'] ?? '') ?>">
                                    <?= htmlspecialchars(mb_strimwidth($p['catatan'] ?? '-', 0, 35, '...')) ?>
                                </td>
                                <td>
                                    <?php if (!empty($p['nama_driver'])): ?>
                                        <span class="status-pill proses"><?= htmlspecialchars($p['nama_driver']) ?></span>
                                    <?php else: ?>
                                        <span class="adm-muted">Belum ditugaskan</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status-pill <?= $statusClass ?>"><?= htmlspecialchars($p['status']) ?></span>
                                </td>
                                <td>
                                    <?php if (!empty($p['bukti_bayar'])): ?>
                                        <?php if ((int)$p['qris_diverifikasi'] === 1): ?>
                                            <span class="status-pill selesai">Terverifikasi</span>
                                        <?php elseif ((int)$p['qris_diverifikasi'] === 2): ?>
                                            <span class="status-pill batal">Ditolak</span>
                                        <?php else: ?>
                                            <span class="status-pill menunggu">Cek Bukti</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="adm-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="adm-time">
                                    <?= !empty($p['created_at']) ? date('d/m/Y H:i', strtotime($p['created_at'])) : '-' ?>
                                </td>
                                <td class="adm-right">
                                    <a href="pesanan.php" class="btn btn-secondary btn-sm">Kelola</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- DRIVER AKTIF & STATUS -->
    <div class="dash-card">
        <div class="dash-card-header">
            <h2 class="dash-card-title">Status Driver Terbaru</h2>
            <div class="dash-card-actions">
                <a href="driver.php" class="btn btn-secondary btn-sm">Kelola Driver Selengkapnya &rarr;</a>
            </div>
        </div>

        <div class="table-responsive">
            <table class="dash-table">
                <thead>
                    <tr>
                        <th>Nama Driver</th>
                        <th>No. WhatsApp</th>
                        <th>Email Akun</th>
                        <th>Order Selesai</th>
                        <th>Status Operasional</th>
                        <th class="adm-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($daftarDrivers as $d): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($d['nama_driver']) ?></strong></td>
                            <td><code><?= htmlspecialchars($d['no_hp']) ?></code></td>
                            <td><?= htmlspecialchars($d['email']) ?></td>
                            <td><span class="status-pill selesai"><?= (int)$d['total_selesai'] ?> selesai</span></td>
                            <td>
                                <?php if ((int)$d['status_aktif'] === 1): ?>
                                    <span class="status-pill aktif">Online / Aktif</span>
                                <?php else: ?>
                                    <span class="status-pill nonaktif">Nonaktif / Offline</span>
                                <?php endif; ?>
                            </td>
                            <td class="adm-right">
                                <a href="driver.php" class="btn btn-secondary btn-sm">Edit / Status</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

</body>
</html>