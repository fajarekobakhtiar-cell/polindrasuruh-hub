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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;700;800;900&family=Archivo+Black&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/home.css?v=<?= filemtime(__DIR__ . '/assets/css/home.css') ?>">
</head>
<body class="ps-body ps-auth-body">

    <div class="ps-auth">

        <!-- KIRI: PANEL MASKOT + SELAMAT DATANG -->
        <aside class="ps-auth-side" id="psAuthSide">
            <a href="index.php" class="ps-logo ps-auth-logo">
                <span class="ps-logo-mark">PS</span>
                <span>Polindra Suruh</span>
            </a>

            <div class="ps-auth-welcome">
                <span class="ps-pill"><i></i> SELAMAT DATANG</span>
                <h1 class="ps-auth-title" aria-label="Halo, Selamat Datang!">
                    <span class="ps-line"><span class="ps-word" style="--d:.05s">Halo,</span></span>
                    <span class="ps-line"><span class="ps-word ps-word-hl" style="--d:.2s">Selamat</span></span>
                    <span class="ps-line"><span class="ps-word" style="--d:.35s">Datang!</span></span>
                </h1>
                <p class="ps-auth-rotator">
                    Mau <b id="psRotator">titip makanan</b>?
                </p>
            </div>

            <div class="ps-auth-mascot" id="psAuthMascot">
                <div class="ps-auth-blob"></div>
                <div class="ps-auth-bubble">Halo! Yuk login</div>
                <div class="ps-auth-sticker">Siap<br>Ngebut!</div>
                <div class="ps-auth-tilt" id="psAuthTilt">
                    <picture>
                        <source srcset="assets/img/mascot-login.webp" type="image/webp">
                        <img src="assets/img/mascot-login.png" alt="Maskot Polindra Suruh menyapa" class="ps-auth-mascot-img" width="941" height="1672" fetchpriority="high">
                    </picture>
                </div>
            </div>
        </aside>

        <!-- KANAN: FORM LOGIN -->
        <main class="ps-auth-main">
            <div class="ps-auth-card">
                <div class="ps-auth-head">
                    <h2>Login</h2>
                    <p>Mau suruh apa hari ini?</p>
                </div>

                <?php if ($pesan !== ""): ?>
                    <div class="ps-alert ps-alert-<?= $pesanType ?>" role="alert">
                        <?= htmlspecialchars($pesan, ENT_QUOTES, "UTF-8") ?>
                    </div>
                <?php endif; ?>

                <form method="POST" class="ps-auth-form">
                    <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">

                    <label class="ps-field">
                        <span>Nomor WhatsApp / Username</span>
                        <input type="text"
                               name="login"
                               class="ps-input"
                               placeholder="08xx atau username"
                               value="<?= htmlspecialchars($login) ?>"
                               autocomplete="username"
                               required
                               autofocus>
                    </label>

                    <label class="ps-field">
                        <span>Password</span>
                        <div class="ps-input-wrap">
                            <input type="password"
                                   name="password"
                                   id="psPassword"
                                   class="ps-input"
                                   placeholder="Password"
                                   autocomplete="current-password"
                                   required>
                            <button type="button" class="ps-eye" id="psEye" aria-label="Tampilkan password">Lihat</button>
                        </div>
                    </label>

                    <div class="ps-forgot">
                        <a href="https://wa.me/6283194189185?text=Halo%20Admin%20Polindra%20Suruh,%20saya%20lupa%20password%20akun%20saya" target="_blank" rel="noopener">Lupa Password?</a>
                    </div>

                    <button type="submit" class="ps-btn ps-btn-dark ps-auth-submit">Login &rarr;</button>
                </form>

                <div class="ps-auth-foot">
                    Belum mempunyai akun? <a href="register.php">Daftar</a>
                </div>

                <div class="ps-auth-back">
                    <a href="index.php">&larr; Kembali ke Beranda</a>
                </div>
            </div>
        </main>
    </div>

    <script>
    (function () {
        // Tampilkan / sembunyikan password
        var pw = document.getElementById('psPassword'), eye = document.getElementById('psEye');
        eye.addEventListener('click', function () {
            var show = pw.type === 'password';
            pw.type = show ? 'text' : 'password';
            eye.textContent = show ? 'Sembunyi' : 'Lihat';
            eye.setAttribute('aria-label', show ? 'Sembunyikan password' : 'Tampilkan password');
        });

        // Teks berganti di area maskot
        var words = ['titip makanan', 'antar barang', 'belanja', 'fotokopi & print', 'antar jemput', 'suruh apa saja'];
        var el = document.getElementById('psRotator'), i = 0;
        setInterval(function () {
            el.classList.add('out');
            setTimeout(function () {
                i = (i + 1) % words.length;
                el.textContent = words[i];
                el.classList.remove('out');
            }, 260);
        }, 2400);

        // Maskot mengikuti arah kursor
        var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var side = document.getElementById('psAuthSide'), tilt = document.getElementById('psAuthTilt');
        if (!reduce && window.matchMedia('(hover: hover)').matches) {
            side.addEventListener('mousemove', function (e) {
                var r = side.getBoundingClientRect();
                var x = (e.clientX - r.left) / r.width - .5;
                var y = (e.clientY - r.top) / r.height - .5;
                tilt.style.transform = 'perspective(900px) rotateY(' + (x * 10) + 'deg) rotateX(' + (-y * 6) + 'deg) translate(' + (x * 12) + 'px,' + (y * 6) + 'px)';
            });
            side.addEventListener('mouseleave', function () { tilt.style.transform = ''; });
        }
    })();
    </script>

</body>
</html>