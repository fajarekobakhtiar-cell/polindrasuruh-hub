<?php
session_start();
require_once "koneksi.php";

$tujuan = [
    "admin" => "admin/index.php",
    "driver" => "driver.php",
    "user" => "index.php"
];

if (isset($_SESSION["user_id"], $_SESSION["role"])) {
    $role = $_SESSION["role"];

    if (isset($tujuan[$role])) {
        header("Location: " . $tujuan[$role]);
        exit;
    }
}

$pesan = isset($_GET["daftar"])
    ? "Registrasi berhasil! Silakan login dengan Nomor WhatsApp & Password Anda."
    : "";

$pesanType = isset($_GET["daftar"]) ? "success" : "error";
$login = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $login = trim($_POST["login"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($login === "" || $password === "") {
        $pesan = "Nomor WhatsApp / Username dan password wajib diisi.";
        $pesanType = "error";
    } else {
        // Normalisasi jika input berupa nomor HP/WhatsApp
        $digits = preg_replace('/[^0-9]/', '', $login);
        $wa08 = "";
        $wa62 = "";

        if (strlen($digits) >= 8) {
            if (str_starts_with($digits, '62')) {
                $wa08 = '0' . substr($digits, 2);
                $wa62 = $digits;
            } elseif (str_starts_with($digits, '0')) {
                $wa08 = $digits;
                $wa62 = '62' . substr($digits, 1);
            } elseif (str_starts_with($digits, '8')) {
                $wa08 = '0' . $digits;
                $wa62 = '62' . $digits;
            } else {
                $wa08 = $digits;
                $wa62 = $digits;
            }
        }

        // 1. Cek tabel admin terlebih dahulu (berdasarkan username atau email)
        $stmtAdmin = $pdo->prepare(
            "SELECT id, nama, username, email, password
             FROM admin
             WHERE username = ? OR email = ?
             LIMIT 1"
        );
        $stmtAdmin->execute([$login, $login]);
        $admin = $stmtAdmin->fetch();

        if ($admin && password_verify($password, $admin["password"])) {
            session_regenerate_id(true);

            $_SESSION["user_id"] = $admin["id"];
            $_SESSION["admin_id"] = $admin["id"];
            $_SESSION["nama"] = $admin["nama"];
            $_SESSION["username"] = $admin["username"];
            $_SESSION["role"] = "admin";

            header("Location: admin/index.php");
            exit;
        }

        // 2. Cek tabel users (Pelanggan & Driver)
        // Mendukung login via: Nomor WhatsApp (08xx / 628xx), Username, atau Email
        $stmt = $pdo->prepare(
            "SELECT id, nama, username, no_wa, email, password, role
             FROM users
             WHERE username = ? 
                OR email = ? 
                OR (? != '' AND (no_wa = ? OR no_wa = ?))
             LIMIT 1"
        );
        $stmt->execute([$login, $login, $wa08, $wa08, $wa62]);
        $user = $stmt->fetch();

        if (
            $user &&
            password_verify($password, $user["password"]) &&
            isset($tujuan[$user["role"]])
        ) {
            session_regenerate_id(true);

            $_SESSION["user_id"] = $user["id"];
            $_SESSION["nama"] = $user["nama"];
            $_SESSION["username"] = $user["username"];
            $_SESSION["no_wa"] = $user["no_wa"] ?? "";
            $_SESSION["role"] = $user["role"];

            if ($user["role"] === "user" &&
                ($_POST["redirect"] ?? "") === "order") {
                header("Location: order.php");
                exit;
            }

            if ($user["role"] === "user" &&
                ($_POST["redirect"] ?? "") === "pilih_driver") {
                header("Location: pilih_driver.php");
                exit;
            }

            header("Location: " . $tujuan[$user["role"]]);
            exit;
        }

        $pesan = "Nomor WhatsApp / Username atau password salah.";
        $pesanType = "error";
    }
}

$redirect = $_GET["redirect"] ?? $_POST["redirect"] ?? "";
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Polindra Suruh</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
</head>
<body class="page-auth page-santo-auth page-login">

    <div class="santo-auth-wrapper">
        <!-- TOP: HERO BANNER (Maskot Motor + Efek Bercahaya) -->
        <div class="santo-hero-banner">
            <div class="santo-hero-glow-aura"></div>
            <div class="santo-hero-overlay">
                <div class="santo-hero-mascot-wrap">
                    <img src="assets/img/mascot-motor.png?v=<?= filemtime(__DIR__ . '/assets/img/mascot-motor.png') ?>" alt="Polindra Suruh" class="santo-hero-mascot">
                </div>
                <h1 class="santo-hero-tagline">Mau suruh apa hari ini?</h1>
            </div>
        </div>

        <!-- BOTTOM: WHITE FORM CARD -->
        <div class="santo-form-card">
            <div class="santo-form-header">
                <h2>Login</h2>
                <p>Mau suruh apa hari ini?</p>
            </div>

            <?php if ($pesan !== ""): ?>
                <div class="alert alert-<?= $pesanType ?>">
                    <?= htmlspecialchars($pesan, ENT_QUOTES, "UTF-8") ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="santo-form">
                <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">

                <div class="santo-input-group">
                    <input type="text" 
                           name="login" 
                           class="santo-input" 
                           placeholder="Nomor WhatsApp / Username" 
                           value="<?= htmlspecialchars($login) ?>" 
                           autocomplete="username" 
                           required 
                           autofocus>
                </div>

                <div class="santo-input-group">
                    <input type="password" 
                           name="password" 
                           class="santo-input" 
                           placeholder="Password" 
                           autocomplete="current-password" 
                           required>
                </div>

                <div class="santo-forgot-wrap">
                    <a href="https://wa.me/6283194189185?text=Halo%20Admin%20Polindra%20Suruh,%20saya%20lupa%20password%20akun%20saya" target="_blank" class="santo-forgot-link">Lupa Password ?</a>
                </div>

                <button type="submit" class="santo-btn-submit">Login</button>
            </form>

            <div class="santo-auth-footer">
                Belum mempunyai akun? <a href="register.php">Daftar</a>
            </div>

            <div class="santo-back-home">
                <a href="index.php">← Kembali ke Beranda</a>
            </div>
        </div>
    </div>

</body>
</html>
