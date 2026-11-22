<?php
/**
 * includes/navbar.php
 * Komponen navigasi utama Polindra Suruh
 *
 * Variabel opsional sebelum include:
 *   $activePage (string) - 'home' | 'layanan' | 'cara_suruh' | 'tentang' | 'pesanan_saya' | 'order'
 *   $navPrefix  (string) - prefix path jika berada di subfolder (default: '')
 */

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

$login = isset($_SESSION["user_id"]);
$role  = $_SESSION["role"] ?? null;
$nama  = $_SESSION["nama"] ?? "Pengguna";

// Auto-detect active page jika tidak di-set manual
if (!isset($activePage)) {
    $currentScript = basename($_SERVER['PHP_SELF'] ?? '');
    if ($currentScript === 'index.php') {
        $activePage = 'home';
    } elseif (in_array($currentScript, ['layanan.php', 'food.php'], true)) {
        $activePage = 'layanan';
    } elseif ($currentScript === 'cara_suruh.php') {
        $activePage = 'cara_suruh';
    } elseif ($currentScript === 'tentang.php') {
        $activePage = 'tentang';
    } elseif ($currentScript === 'pesanan_saya.php') {
        $activePage = 'pesanan_saya';
    } elseif ($currentScript === 'order.php') {
        $activePage = 'order';
    } else {
        $activePage = '';
    }
}

$navPrefix = $navPrefix ?? '';
?>

<!-- STYLE KHUSUS NAVBAR & RESPONSIVE MOBILE (INLINE & ANTI-CACHE) -->
<!-- NAVBAR UTAMA -->
<nav class="main-navbar">
    <a href="<?= $navPrefix ?>index.php" class="logo">
        <img src="<?= $navPrefix ?>assets/img/logo-circle.jpg" alt="Polindra Suruh" class="logo-icon">
        <div class="logo-text">
            <span>POLINDRA</span>
            <span>SURUH</span>
        </div>
    </a>

    <!-- MENU DESKTOP -->
    <div class="menu desktop-menu">
        <a href="<?= $navPrefix ?>index.php" class="<?= $activePage === 'home' ? 'active' : '' ?>">HOME</a>
        <a href="<?= $navPrefix ?>layanan.php" class="<?= $activePage === 'layanan' ? 'active' : '' ?>">LAYANAN</a>
        <a href="<?= $navPrefix ?>cara_suruh.php" class="<?= $activePage === 'cara_suruh' ? 'active' : '' ?>">CARA SURUH</a>
        <a href="<?= $navPrefix ?>tentang.php" class="<?= $activePage === 'tentang' ? 'active' : '' ?>">TENTANG</a>
    </div>

    <!-- AKUN DESKTOP -->
    <div class="nav-account desktop-account">
        <?php if (!$login): ?>
            <a href="<?= $navPrefix ?>login.php" class="login-btn">MASUK</a>

        <?php elseif ($role === 'admin'): ?>
            <a href="<?= $navPrefix ?>admin/index.php" class="login-btn">DASHBOARD ADMIN</a>
            <a href="<?= $navPrefix ?>logout.php" class="login-btn btn-logout">LOGOUT</a>

        <?php elseif ($role === 'driver'): ?>
            <a href="<?= $navPrefix ?>driver.php" class="login-btn">DASHBOARD DRIVER</a>
            <a href="<?= $navPrefix ?>logout.php" class="login-btn btn-logout">LOGOUT</a>

        <?php else: ?>
            <a href="<?= $navPrefix ?>order.php" class="login-btn btn-order-nav">BUAT PESANAN</a>
            <a href="<?= $navPrefix ?>pesanan_saya.php" class="login-btn <?= $activePage === 'pesanan_saya' ? 'active' : '' ?>">PESANAN SAYA</a>
            <a href="<?= $navPrefix ?>logout.php" class="login-btn btn-logout">LOGOUT</a>
        <?php endif; ?>
    </div>

    <!-- TOMBOL HAMBURGER MOBILE -->
    <button type="button" class="mobile-nav-toggle" id="mobileNavToggle" aria-label="Buka Menu Navigasi" onclick="toggleMobileNav(event)" style="display:none;">
        <span class="nav-bar-icon"></span>
        <span class="nav-bar-icon"></span>
        <span class="nav-bar-icon"></span>
    </button>
</nav>

<!-- MOBILE DRAWER DROPDOWN -->
<div class="mobile-nav-drawer" id="mobileNavDrawer" style="display:none;">
    <div class="mobile-drawer-inner">
        <div class="mobile-menu-links">
            <a href="<?= $navPrefix ?>index.php" class="<?= $activePage === 'home' ? 'active' : '' ?>">HOME</a>
            <a href="<?= $navPrefix ?>layanan.php" class="<?= $activePage === 'layanan' ? 'active' : '' ?>">LAYANAN</a>
            <a href="<?= $navPrefix ?>cara_suruh.php" class="<?= $activePage === 'cara_suruh' ? 'active' : '' ?>">CARA SURUH</a>
            <a href="<?= $navPrefix ?>tentang.php" class="<?= $activePage === 'tentang' ? 'active' : '' ?>">TENTANG</a>
        </div>

        <div class="mobile-account-actions">
            <?php if (!$login): ?>
                <a href="<?= $navPrefix ?>login.php" class="mobile-btn-primary">Masuk / Daftar Akun</a>
            <?php elseif ($role === 'admin'): ?>
                <a href="<?= $navPrefix ?>admin/index.php" class="mobile-btn-primary">Dashboard Admin</a>
                <a href="<?= $navPrefix ?>logout.php" class="mobile-btn-danger">Keluar (Logout)</a>
            <?php elseif ($role === 'driver'): ?>
                <a href="<?= $navPrefix ?>driver.php" class="mobile-btn-primary">Dashboard Driver</a>
                <a href="<?= $navPrefix ?>logout.php" class="mobile-btn-danger">Keluar (Logout)</a>
            <?php else: ?>
                <a href="<?= $navPrefix ?>order.php" class="mobile-btn-primary">Buat Pesanan Baru</a>
                <a href="<?= $navPrefix ?>pesanan_saya.php" class="mobile-btn-secondary <?= $activePage === 'pesanan_saya' ? 'active' : '' ?>">Pesanan Saya</a>
                <a href="<?= $navPrefix ?>logout.php" class="mobile-btn-danger">Keluar (Logout)</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- BOTTOM BAR KHUSUS HP (SMARTPHONE APP-STYLE) -->
<nav class="mobile-bottom-bar" style="display:none;">
    <a href="<?= $navPrefix ?>index.php" class="bottom-bar-item <?= $activePage === 'home' ? 'active' : '' ?>">
        <span class="bar-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg></span>
        <span class="bar-label">Home</span>
    </a>
    <a href="<?= $navPrefix ?>layanan.php" class="bottom-bar-item <?= $activePage === 'layanan' ? 'active' : '' ?>">
        <span class="bar-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/></svg></span>
        <span class="bar-label">Layanan</span>
    </a>
    <?php if ($role === 'driver'): ?>
        <a href="<?= $navPrefix ?>driver.php" class="bottom-bar-item <?= ($currentScript ?? '') === 'driver.php' ? 'active' : '' ?>">
            <span class="bar-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/></svg></span>
            <span class="bar-label">Driver</span>
        </a>
    <?php else: ?>
        <a href="<?= $navPrefix ?>order.php" class="bottom-bar-item <?= $activePage === 'order' ? 'active' : '' ?>">
            <span class="bar-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v8"/><path d="M8 12h8"/></svg></span>
            <span class="bar-label">Pesan</span>
        </a>
    <?php endif; ?>
    <?php if ($login): ?>
        <?php if ($role === 'driver'): ?>
            <a href="<?= $navPrefix ?>driver.php?tab=wallet" class="bottom-bar-item">
                <span class="bar-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/></svg></span>
                <span class="bar-label">Dompet</span>
            </a>
        <?php elseif ($role === 'admin'): ?>
            <a href="<?= $navPrefix ?>admin/index.php" class="bottom-bar-item">
                <span class="bar-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20v-6M6 20V10M18 20V4"/></svg></span>
                <span class="bar-label">Admin</span>
            </a>
        <?php else: ?>
            <a href="<?= $navPrefix ?>pesanan_saya.php" class="bottom-bar-item <?= $activePage === 'pesanan_saya' ? 'active' : '' ?>">
                <span class="bar-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M9 14h6"/><path d="M9 18h6"/></svg></span>
                <span class="bar-label">Pesanan</span>
            </a>
        <?php endif; ?>
    <?php else: ?>
        <a href="<?= $navPrefix ?>login.php" class="bottom-bar-item">
            <span class="bar-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span>
            <span class="bar-label">Masuk</span>
        </a>
    <?php endif; ?>
</nav>

<script>
function toggleMobileNav(e) {
    if (e) e.stopPropagation();
    const drawer = document.getElementById('mobileNavDrawer');
    const toggle = document.getElementById('mobileNavToggle');
    if (drawer && toggle) {
        drawer.classList.toggle('open');
        toggle.classList.toggle('active');
    }
}

document.addEventListener('click', function(e) {
    const drawer = document.getElementById('mobileNavDrawer');
    const toggle = document.getElementById('mobileNavToggle');
    if (drawer && toggle && drawer.classList.contains('open')) {
        if (!drawer.contains(e.target) && !toggle.contains(e.target)) {
            drawer.classList.remove('open');
            toggle.classList.remove('active');
        }
    }
});
</script>
