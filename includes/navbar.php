<?php
/**
 * includes/navbar.php
 * Navbar baru Polindra Suruh, disamakan dengan navbar pada landing page.
 * Variabel opsional: $navPrefix untuk halaman di subfolder.
 */

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

$login = isset($_SESSION['user_id']);
$role  = $_SESSION['role'] ?? null;
$navPrefix = $navPrefix ?? '';
$currentScript = basename($_SERVER['PHP_SELF'] ?? '');
$isHome = ($currentScript === 'index.php' && $navPrefix === '');

// Link bagian landing page tetap mengarah ke section yang benar meskipun
// navbar dipanggil dari halaman lain.
$homeUrl = $navPrefix . 'index.php';
$caraKerjaUrl = $isHome ? '#cara-kerja' : $homeUrl . '#cara-kerja';
$driverUrl = $isHome ? '#driver' : $homeUrl . '#driver';
?>

<style>
/* Navbar baru Polindra Suruh */
.ps-nav {
    position: sticky;
    top: 0;
    z-index: 1000;
    width: 100%;
    background: rgba(247, 247, 243, .96);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    border-bottom: 2px solid #171717;
    font-family: 'Archivo', Arial, sans-serif;
}
.ps-nav * { box-sizing: border-box; }
.ps-nav-inner {
    width: min(1200px, calc(100% - 40px));
    min-height: 70px;
    margin: 0 auto;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
}
.ps-logo {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    color: #171717;
    text-decoration: none;
    font-family: 'Archivo Black', 'Archivo', Arial, sans-serif;
    font-size: 17px;
    white-space: nowrap;
}
.ps-logo-mark {
    width: 39px;
    height: 39px;
    display: grid;
    place-items: center;
    flex: 0 0 39px;
    border: 2.5px solid #171717;
    border-radius: 12px;
    background: #FDE100;
    color: #171717;
    font-size: 14px;
}
.ps-nav-links {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 30px;
}
.ps-nav-links a {
    position: relative;
    padding: 8px 0;
    color: #171717;
    text-decoration: none;
    font-size: 14px;
    font-weight: 700;
    transition: opacity .2s ease;
}
.ps-nav-links a::after {
    content: '';
    position: absolute;
    left: 0;
    bottom: 2px;
    width: 0;
    height: 3px;
    background: #FDE100;
    border: 1px solid #171717;
    transition: width .2s ease;
}
.ps-nav-links a:hover::after { width: 100%; }
.ps-nav-actions { display: flex; align-items: center; gap: 10px; }
.ps-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 42px;
    padding: 10px 18px;
    border: 2px solid #171717;
    border-radius: 999px;
    text-decoration: none;
    font-size: 13px;
    font-weight: 800;
    transition: transform .15s ease, box-shadow .15s ease;
    white-space: nowrap;
}
.ps-btn:hover { transform: translate(-2px, -2px); box-shadow: 4px 4px 0 #171717; }
.ps-btn-dark { background: #171717; color: #fff; }
.ps-btn-yellow { background: #FDE100; color: #171717; }
.ps-burger {
    display: none;
    width: 40px;
    height: 40px;
    padding: 8px;
    border: 2px solid #171717;
    border-radius: 10px;
    background: #FDE100;
    cursor: pointer;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 5px;
}
.ps-burger span { display: block; width: 19px; height: 2.5px; border-radius: 3px; background: #171717; }

@media (max-width: 900px) {
    .ps-nav-inner { width: min(100% - 28px, 1200px); min-height: 64px; gap: 12px; }
    .ps-burger { display: flex; }
    .ps-nav-links {
        display: none;
        position: absolute;
        top: calc(100% + 2px);
        left: 0;
        right: 0;
        padding: 18px 22px;
        background: #F7F7F3;
        border-bottom: 2px solid #171717;
        box-shadow: 0 8px 16px rgba(0,0,0,.08);
        flex-direction: column;
        align-items: stretch;
        gap: 8px;
    }
    .ps-nav-links.open { display: flex; }
    .ps-nav-links a { padding: 11px 4px; }
    .ps-nav-links a::after { bottom: 4px; }
    .ps-nav-actions { margin-left: auto; }
    .ps-btn { min-height: 39px; padding: 8px 13px; font-size: 12px; }
}
@media (max-width: 480px) {
    .ps-logo { font-size: 14px; gap: 8px; }
    .ps-logo-mark { width: 35px; height: 35px; flex-basis: 35px; }
    .ps-nav-inner { width: calc(100% - 22px); }
    .ps-nav-actions { gap: 7px; }
    .ps-btn { padding: 7px 10px; font-size: 10.5px; }
}
</style>

<header class="ps-nav">
    <div class="ps-nav-inner">
        <a href="<?= htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8') ?>" class="ps-logo">
            <span class="ps-logo-mark">PS</span>
            <span>Polindra Suruh</span>
        </a>

        <nav class="ps-nav-links" id="psNavLinks">
            <a href="<?= htmlspecialchars($navPrefix . 'index.php#layanan', ENT_QUOTES, 'UTF-8') ?>">Layanan</a>
            <a href="<?= htmlspecialchars($caraKerjaUrl, ENT_QUOTES, 'UTF-8') ?>">Cara Kerja</a>
            <a href="<?= htmlspecialchars($driverUrl, ENT_QUOTES, 'UTF-8') ?>">Driver</a>
            <a href="<?= htmlspecialchars($navPrefix . 'tentang.php', ENT_QUOTES, 'UTF-8') ?>">Tentang</a>
        </nav>

        <div class="ps-nav-actions">
            <?php if ($login && $role === 'admin'): ?>
                <a href="<?= $navPrefix ?>admin/index.php" class="ps-btn ps-btn-dark">Dashboard</a>
            <?php elseif ($login && $role === 'driver'): ?>
                <a href="<?= $navPrefix ?>driver.php" class="ps-btn ps-btn-dark">Dashboard</a>
            <?php elseif ($login): ?>
                <a href="<?= $navPrefix ?>pesanan_saya.php" class="ps-btn ps-btn-dark">Pesanan Saya</a>
            <?php else: ?>
                <a href="<?= $navPrefix ?>login.php" class="ps-btn ps-btn-yellow">Masuk / Daftar</a>
            <?php endif; ?>

            <button type="button" class="ps-burger" aria-label="Buka menu" aria-expanded="false"
                onclick="(function(btn){var menu=document.getElementById('psNavLinks');var opened=menu.classList.toggle('open');btn.setAttribute('aria-expanded',opened?'true':'false');})(this)">
                <span></span><span></span><span></span>
            </button>
        </div>
    </div>
</header>
