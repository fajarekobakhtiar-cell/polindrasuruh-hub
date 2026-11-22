<?php
/**
 * includes/admin_navbar.php
 * Navbar sidebar admin yang dipakai di semua halaman admin/.
 *
 * Variabel yang harus tersedia sebelum include:
 *   $activeAdminPage (string) - halaman aktif:
 *       'overview'|'driver'|'pesanan'|'user'
 *
 * Variabel opsional (ditampilkan di badge nav):
 *   $totalDrivers, $totalPesananAll, $totalUsers
 */

$_activeAdmin   = $activeAdminPage ?? '';
$_totalDrivers  = $totalDrivers ?? '';
$_totalPesanan  = $totalPesananAll ?? '';
$_totalUsers    = $totalUsers ?? '';
$_totalPenarikan = 0;
if (isset($pdo)) {
    try {
        $_totalPenarikan = (int) $pdo->query("SELECT COUNT(*) FROM penarikan_dana WHERE status = 'Menunggu'")->fetchColumn();
    } catch (Exception $e) {}
}
?>

<header class="admin-navbar">
    <div class="admin-brand">
        
        <div class="admin-brand-text">
            <h1>Polindra Suruh Admin</h1>
            <p>Halo, <strong><?= htmlspecialchars($_SESSION['nama'] ?? 'Admin') ?></strong> (Administrator)</p>
        </div>
    </div>

    <nav class="admin-nav-menu">
        <a href="index.php"
           class="admin-nav-link <?= $_activeAdmin === 'overview' ? 'active' : '' ?>">
            Overview
        </a>

        <a href="driver.php"
           class="admin-nav-link <?= $_activeAdmin === 'driver' ? 'active' : '' ?>">
            Kelola Driver
            <?php if ($_totalDrivers !== ''): ?>
                <span class="badge-count"><?= (int) $_totalDrivers ?></span>
            <?php endif; ?>
        </a>

        <a href="pesanan.php"
           class="admin-nav-link <?= $_activeAdmin === 'pesanan' ? 'active' : '' ?>">
            Kelola Pesanan
            <?php if ($_totalPesanan !== ''): ?>
                <span class="badge-count"><?= (int) $_totalPesanan ?></span>
            <?php endif; ?>
        </a>

        <a href="user.php"
           class="admin-nav-link <?= $_activeAdmin === 'user' ? 'active' : '' ?>">
            Kelola Pelanggan
            <?php if ($_totalUsers !== ''): ?>
                <span class="badge-count"><?= (int) $_totalUsers ?></span>
            <?php endif; ?>
        </a>

        <a href="penarikan.php"
           class="admin-nav-link <?= $_activeAdmin === 'penarikan' ? 'active' : '' ?>">
            Tarik Saldo
            <?php if ($_totalPenarikan > 0): ?>
                <span class="badge-count" style="background: #ef4444; color: #fff; font-weight: 800;"><?= (int) $_totalPenarikan ?></span>
            <?php endif; ?>
        </a>

        <a href="pengaturan.php"
           class="admin-nav-link <?= $_activeAdmin === 'pengaturan' ? 'active' : '' ?>">
            Gateway Pembayaran
        </a>

        <a href="../index.php" class="admin-nav-link" target="_blank">
            Lihat Web
        </a>

        <a href="../logout.php" class="admin-btn-logout">Logout</a>
    </nav>
</header>
