<?php
session_start();
require_once "koneksi.php";
if (file_exists(__DIR__ . "/includes/fitur_helper.php")) {
    require_once __DIR__ . "/includes/fitur_helper.php";
}

if (function_exists("prosesAutoTolakTigaMenit")) {
    prosesAutoTolakTigaMenit($pdo);
}

// Hanya pelanggan yang boleh memilih driver
if (!isset($_SESSION["user_id"])) {
    header("Location: login.php?redirect=pilih_driver");
    exit;
}

$role = $_SESSION["role"] ?? "";

if ($role === "admin") {
    header("Location: admin/index.php");
    exit;
}

if ($role === "driver") {
    header("Location: driver.php");
    exit;
}

$userId = (int) $_SESSION["user_id"];
$pesananId = isset($_GET["pesanan_id"]) ? (int) $_GET["pesanan_id"] : (isset($_POST["pesanan_id"]) ? (int) $_POST["pesanan_id"] : 0);
$pesanKadaluarsa = "";

// Cek jika memilih ulang driver untuk pesanan yang otomatis ditolak (3 menit)
if ($pesananId > 0) {
    $stmtCekPesanan = $pdo->prepare("
        SELECT id, status, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS elapsed
        FROM pesanan
        WHERE id = :id AND user_id = :user_id
        LIMIT 1
    ");
    $stmtCekPesanan->execute(["id" => $pesananId, "user_id" => $userId]);
    $rowPesanan = $stmtCekPesanan->fetch(PDO::FETCH_ASSOC);

    if ($rowPesanan) {
        $statusLama = (string) ($rowPesanan["status"] ?? "");
        $elapsedLama = (int) ($rowPesanan["elapsed"] ?? 0);

        if ($statusLama === "Dibatalkan" && $elapsedLama >= 180) {
            // Reset waktu & status ke 'Menunggu Driver' saat user memilih ulang driver baru
            $stmtReset = $pdo->prepare("
                UPDATE pesanan
                SET status = 'Menunggu Driver',
                    driver_id = NULL,
                    created_at = NOW()
                WHERE id = :id AND user_id = :user_id
            ");
            $stmtReset->execute(["id" => $pesananId, "user_id" => $userId]);
            $pesanKadaluarsa = "Pesanan #" . $pesananId . " sebelumnya otomatis ditolak karena melewati batas 3 menit. Silakan pilih driver baru sekarang.";
        }
    }
}

// Jika menetapkan driver ke pesanan yang sudah ada
if (($_SERVER["REQUEST_METHOD"] ?? "") === "POST" && isset($_POST["pilih_driver_pesanan"])) {
    $targetPesananId = (int) ($_POST["pesanan_id"] ?? 0);
    $selectedDriverId = (int) ($_POST["driver_id"] ?? 0);

    if ($targetPesananId > 0 && $selectedDriverId > 0) {
        $stmtUpdate = $pdo->prepare("
            UPDATE pesanan
            SET driver_id = :driver_id, status = 'Menunggu Driver', created_at = NOW()
            WHERE id = :pesanan_id AND user_id = :user_id
        ");
        $stmtUpdate->execute([
            "driver_id" => $selectedDriverId,
            "pesanan_id" => $targetPesananId,
            "user_id" => $userId
        ]);

        header("Location: pesanan_saya.php?id=" . $targetPesananId . "&pesan=driver_terpilih");
        exit;
    }
}

// Ambil seluruh driver (aktif dan tidak aktif) beserta info QRIS dan Lokasi GPS
$sql = "SELECT u.id, u.nama, COALESCE(d.status_aktif, u.status_aktif, 0) AS status_aktif, d.foto_qris, d.lat, d.lng
        FROM users u
        LEFT JOIN drivers d ON d.user_id = u.id
        WHERE u.role = 'driver'
        ORDER BY status_aktif DESC, u.nama ASC";

$stmt = $pdo->query($sql);
$drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pilih Driver - Polindra Suruh</title>

    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>

<body class="page-pilih-driver">

    <?php require_once __DIR__ . '/includes/navbar.php'; ?>

    <section class="pilih-driver">

        <div class="driver-heading">
            <h1>Pilih Driver Kamu</h1>
            <p>
                <?= $pesananId > 0
                    ? 'Pilih driver yang tersedia untuk menangani Pesanan #' . (int) $pesananId . '.'
                    : 'Pilih driver yang sedang aktif sebelum membuat pesanan kamu.' ?>
            </p>
        </div>

        <?php if ($pesanKadaluarsa !== ""): ?>
            <div class="pesanan-alert-banner" style="background:#fef2f2; border-color:#fecaca; color:#991b1b;">
                <strong><?= htmlspecialchars($pesanKadaluarsa, ENT_QUOTES, 'UTF-8') ?></strong>
            </div>
        <?php endif; ?>

        <?php if ($pesananId > 0): ?>
            <div class="pesanan-alert-banner">
                <strong>Memilih Driver untuk Pesanan #<?= (int) $pesananId ?></strong>
            </div>
        <?php endif; ?>

        <?php if (count($drivers) > 0): ?>

            <div style="text-align: center;">
                <div class="pilih-driver-tabs">
                    <button type="button" class="tab-btn active" onclick="filterDriverGrid('all', this)">
                        Semua (<?= count($drivers) ?>)
                    </button>
                    <button type="button" class="tab-btn" onclick="filterDriverGrid('active', this)">
                        Aktif (<?= count(array_filter($drivers, fn($d) => (int)$d['status_aktif'] === 1)) ?>)
                    </button>
                    <button type="button" class="tab-btn" onclick="filterDriverGrid('inactive', this)">
                        Tidak Aktif (<?= count(array_filter($drivers, fn($d) => (int)$d['status_aktif'] !== 1)) ?>)
                    </button>
                </div>
            </div>

            <div class="driver-list">

                <?php foreach ($drivers as $driver): ?>
                    <?php $isAktif = ((int)$driver["status_aktif"] === 1); ?>

                    <div class="driver-card <?= $isAktif ? '' : 'is-offline' ?>" data-status="<?= $isAktif ? 'active' : 'inactive' ?>">

                        <div class="driver-avatar">
                            <?= strtoupper(
                                substr($driver["nama"], 0, 1)
                            ) ?>
                        </div>

                        <h3>
                            <?= htmlspecialchars(
                                $driver["nama"],
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>
                        </h3>

                        <?php if ($isAktif): ?>
                            <span class="driver-status">
                                ● Sedang Aktif
                            </span>
                        <?php else: ?>
                            <span class="driver-status offline">
                                ○ Sedang Tidak Aktif
                            </span>
                        <?php endif; ?>

                        <div style="font-size: 11.5px; color: #0369a1; background: #f0f9ff; border: 1px solid #bae6fd; padding: 4px 8px; border-radius: 8px; margin: 8px 0 12px; display: inline-flex; align-items: center; justify-content: center; gap: 4px;">
                            <span>
                                <?= (!empty($driver['lat']) && !empty($driver['lng'])) ? 'GPS: ' . round((float)$driver['lat'], 4) . ', ' . round((float)$driver['lng'], 4) : 'Area Kampus Polindra' ?>
                            </span>
                        </div>

                        <?php if (!empty($driver["foto_qris"]) && file_exists(__DIR__ . "/uploads/qris/" . $driver["foto_qris"])): ?>
                            <div style="margin-top: 6px; margin-bottom: 12px;">
                                <span style="display: inline-flex; align-items: center; gap: 4px; font-size: 11px; background: #ecfdf5; color: #047857; padding: 3px 8px; border-radius: 6px; font-weight: bold; border: 1px solid #a7f3d0;">
                                    QRIS Driver Siap
                                </span>
                            </div>
                        <?php endif; ?>

                        <?php if ($isAktif): ?>
                            <?php if ($pesananId > 0): ?>
                                <form action="pilih_driver.php" method="POST">
                                    <input type="hidden" name="pilih_driver_pesanan" value="1">
                                    <input type="hidden" name="pesanan_id" value="<?= (int) $pesananId ?>">
                                    <input type="hidden" name="driver_id" value="<?= (int) $driver["id"] ?>">

                                    <button type="submit" class="pilih-button">
                                        Pilih Driver Ini
                                    </button>
                                </form>
                            <?php else: ?>
                                <form action="order.php" method="GET">
                                    <input
                                        type="hidden"
                                        name="driver_id"
                                        value="<?= (int) $driver["id"] ?>"
                                    >

                                    <button type="submit" class="pilih-button">
                                        Pilih Driver
                                    </button>
                                </form>
                            <?php endif; ?>
                        <?php else: ?>
                            <button type="button" class="pilih-button disabled" disabled>
                                Driver Sedang Offline
                            </button>
                        <?php endif; ?>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php else: ?>

            <div class="driver-kosong">
                <h3>Belum Ada Driver Aktif</h3>
                <p>
                    Saat ini belum ada driver yang tersedia dan sedang online.
                    Silakan coba lagi beberapa saat.
                </p>
            </div>

        <?php endif; ?>

        <div class="kembali-wrapper">
            <a href="<?= $pesananId > 0 ? 'pesanan_saya.php?id=' . (int) $pesananId : 'index.php' ?>" class="kembali">
                ← <?= $pesananId > 0 ? 'Kembali ke Pesanan Saya' : 'Kembali ke Beranda' ?>
            </a>
        </div>

    </section>

    <script>
    function filterDriverGrid(status, btn) {
        document.querySelectorAll('.pilih-driver-tabs .tab-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');

        document.querySelectorAll('.driver-list .driver-card').forEach(card => {
            if (status === 'all') {
                card.style.display = 'block';
            } else if (card.getAttribute('data-status') === status) {
                card.style.display = 'block';
            } else {
                card.style.display = 'none';
            }
        });
    }
    </script>

</body>
</html>