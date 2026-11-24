<?php
session_start();
require_once "koneksi.php";
require_once "includes/midtrans_helper.php";
require_once "includes/fitur_helper.php";

if (function_exists("prosesAutoTolakTigaMenit")) {
    prosesAutoTolakTigaMenit($pdo);
}

// Pastikan pengguna sudah login
if (!isset($_SESSION["user_id"])) {
    header("Location: login.php?redirect=pesanan_saya");
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
$namaPengguna = $_SESSION["nama"] ?? "Pengguna";

// ===== CSRF TOKEN =====
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION["csrf_token"];

function csrfValid(): bool {
    return isset($_POST["csrf_token"], $_SESSION["csrf_token"])
        && hash_equals($_SESSION["csrf_token"], (string) $_POST["csrf_token"]);
}

$isPost = (($_SERVER["REQUEST_METHOD"] ?? "") === "POST");

// Tolak semua POST tanpa CSRF token yang valid
if ($isPost && (isset($_POST["batal_pesanan"]) || isset($_POST["edit_pesanan"]) || isset($_POST["konfirmasi_bayar"])) && !csrfValid()) {
    header("Location: pesanan_saya.php?pesan=csrf_gagal");
    exit;
}

// Helper kecil
function normalisasiNomorWA(string $raw): string {
    $n = preg_replace('/\D/', '', $raw);
    if ($n !== '' && strpos($n, '0') === 0) {
        $n = '62' . substr($n, 1);
    }
    return $n;
}

// Proses pembatalan pesanan jika diminta oleh user
if ($isPost && isset($_POST["batal_pesanan"])) {
    $batalId = (int) ($_POST["pesanan_id"] ?? 0);

    if ($batalId > 0) {
        $stmtBatal = $pdo->prepare("
            UPDATE pesanan
            SET status = 'Dibatalkan'
            WHERE id = ? AND user_id = ? AND status IN ('Menunggu', 'Menunggu Driver', 'Menunggu Persetujuan Driver')
        ");
        $stmtBatal->execute([$batalId, $userId]);
        $ok = $stmtBatal->rowCount() > 0;

        header("Location: pesanan_saya.php?pesan=" . ($ok ? "dibatalkan" : "batal_gagal"));
        exit;
    }
}

// Proses update / edit pesanan jika masih menunggu konfirmasi driver
if ($isPost && isset($_POST["edit_pesanan"])) {
    $editId = (int) ($_POST["pesanan_id"] ?? 0);
    $catatanBaru = trim($_POST["catatan"] ?? "");

    if ($editId > 0 && $catatanBaru !== "") {
        $stmtEdit = $pdo->prepare("
            UPDATE pesanan
            SET catatan = ?
            WHERE id = ? AND user_id = ? AND status IN ('Menunggu', 'Menunggu Driver', 'Menunggu Persetujuan Driver')
        ");
        $stmtEdit->execute([$catatanBaru, $editId, $userId]);
        $ok = $stmtEdit->rowCount() > 0;

        header("Location: pesanan_saya.php?id=" . $editId . "&pesan=" . ($ok ? "diedit" : "edit_gagal"));
        exit;
    }
}

// Proses konfirmasi pembayaran QRIS (unggah bukti manual) oleh pelanggan
if ($isPost && isset($_POST["konfirmasi_bayar"])) {
    $bayarId = (int) ($_POST["pesanan_id"] ?? 0);
    $uploadedBukti = null;

    if ($bayarId > 0
        && isset($_FILES["bukti_bayar"])
        && $_FILES["bukti_bayar"]["error"] === UPLOAD_ERR_OK
        && $_FILES["bukti_bayar"]["size"] <= 5 * 1024 * 1024
    ) {
        $fileTmp = $_FILES["bukti_bayar"]["tmp_name"];

        // Deteksi MIME asli dari isi file (bukan dari input pengguna)
        $mime = "";
        if (function_exists("finfo_open")) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = (string) finfo_file($finfo, $fileTmp);
            finfo_close($finfo);
        }

        $mimeToExt = [
            "image/jpeg" => "jpg",
            "image/png"  => "png",
            "image/webp" => "webp",
        ];

        if (isset($mimeToExt[$mime])) {
            $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "bukti_bayar" . DIRECTORY_SEPARATOR;
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $newFileName = "bukti_" . $bayarId . "_" . bin2hex(random_bytes(6)) . "." . $mimeToExt[$mime];
            if (move_uploaded_file($fileTmp, $uploadDir . $newFileName)) {
                $uploadedBukti = $newFileName;
            }
        }
    }

    if ($bayarId > 0 && $uploadedBukti) {
        $stmtBayar = $pdo->prepare("
            UPDATE pesanan
            SET status = 'Diproses',
                bukti_bayar = ?,
                qris_diverifikasi = 0,
                alasan_tolak_bayar = NULL,
                waktu_bayar = NOW()
            WHERE id = ? AND user_id = ? AND status = 'Menunggu Pembayaran'
        ");
        $stmtBayar->execute([$uploadedBukti, $bayarId, $userId]);

        if ($stmtBayar->rowCount() > 0) {
            header("Location: pesanan_saya.php?id=" . $bayarId . "&tab=aktif&pesan=bayar_sukses");
        } else {
            // Pesanan bukan milik user / status sudah berubah -> hapus file yatim
            @unlink(__DIR__ . "/uploads/bukti_bayar/" . $uploadedBukti);
            header("Location: pesanan_saya.php?id=" . $bayarId . "&tab=aktif&pesan=bayar_gagal");
        }
    } else {
        header("Location: pesanan_saya.php?id=" . $bayarId . "&tab=aktif&pesan=bayar_gagal");
    }
    exit;
}

// Ambil semua daftar pesanan milik user ini beserta data nomor HP driver
$stmt = $pdo->prepare("
    SELECT
        p.id,
        p.user_id,
        p.driver_id,
        p.layanan,
        p.alamat,
        p.catatan,
        p.foto_kost,
        p.status,
        p.metode_bayar,
        p.jarak_km,
        p.total_tarif,
        p.pickup_lat,
        p.pickup_lng,
        p.dest_lat,
        p.dest_lng,
        p.bukti_bayar,
        p.bukti_antar,
        p.qris_diverifikasi,
        p.alasan_tolak_bayar,
        p.waktu_bayar,
        p.created_at,
        GREATEST(0, 180 - TIMESTAMPDIFF(SECOND, p.created_at, NOW())) AS sisa_detik_tunggu,
        dr.rating,
        dr.komentar AS ulasan,
        u.nama AS nama_driver,
        u.email AS email_driver,
        d.no_hp AS no_hp_driver,
        d.foto_qris AS foto_qris_driver
    FROM pesanan p
    LEFT JOIN users u ON p.driver_id = u.id
    LEFT JOIN drivers d ON d.user_id = u.id
    LEFT JOIN driver_ratings dr ON dr.pesanan_id = p.id
    WHERE p.user_id = :user_id
    ORDER BY p.id DESC
");
$stmt->execute(["user_id" => $userId]);
$pesananList = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Hitung ringkasan status & pisahkan list
$totalPesanan = count($pesananList);
$totalAktif = 0;
$totalSelesai = 0;
$pesananAktif = [];
$pesananRiwayat = [];

foreach ($pesananList as $item) {
    if (in_array($item["status"], ["Menunggu", "Menunggu Driver", "Menunggu Persetujuan Driver", "Menunggu Penyesuaian Harga", "Menunggu Pembayaran", "Diproses", "Dalam Perjalanan"])) {
        $totalAktif++;
        $pesananAktif[] = $item;
    } else {
        // Selesai, Dibatalkan, Driver Menolak, dll → masuk riwayat
        if ($item["status"] === "Selesai") $totalSelesai++;
        $pesananRiwayat[] = $item;
    }
}
unset($item);

// [TAMBAHAN BARU] Ambil daftar driver aktif untuk fitur pilih ulang driver pada pesanan ditolak
$driversOnline = [];
try {
    $stmtDriversOnline = $pdo->query("
        SELECT u.id AS user_id,
               COALESCE(NULLIF(d.nama_driver, ''), u.nama) AS nama_driver,
               COALESCE(d.rating_rata, 0) AS rating_rata,
               COALESCE(d.jumlah_rating, 0) AS jumlah_rating
        FROM users u
        LEFT JOIN drivers d ON d.user_id = u.id
        WHERE u.role = 'driver'
          AND COALESCE(d.status_aktif, u.status_aktif, 0) = 1
        ORDER BY rating_rata DESC, nama_driver ASC
    ");
    $driversOnline = $stmtDriversOnline->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $driversOnline = [];
}

$highlightId = isset($_GET["id"]) ? (int) $_GET["id"] : 0;
$pesanType = $_GET["pesan"] ?? "";

// Total tarif untuk banner pembayaran (diambil dari pesanan yang di-highlight)
$highlightTotal = 0;
foreach ($pesananList as $p) {
    if ((int) $p["id"] === $highlightId) {
        $highlightTotal = (float) ($p["total_tarif"] ?? 0);
        break;
    }
}

// Tab default: jika ada pesanan aktif → aktif, else riwayat
$activeTab = $_GET["tab"] ?? ($totalAktif > 0 ? "aktif" : "riwayat");
if (!in_array($activeTab, ["aktif", "riwayat"], true)) {
    $activeTab = $totalAktif > 0 ? "aktif" : "riwayat";
}

function formatTanggalIndo($datetimeStr) {
    if (!$datetimeStr) return "-";
    $timestamp = strtotime($datetimeStr);
    $bulanIndo = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
        5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agu',
        9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'
    ];
    $d = date('d', $timestamp);
    $m = (int) date('m', $timestamp);
    $y = date('Y', $timestamp);
    $time = date('H:i', $timestamp);
    return "$d {$bulanIndo[$m]} $y, $time WIB";
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pesanan Saya - Polindra Suruh</title>

    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>

<body class="page-pesanan">

<?php require_once __DIR__ . '/includes/navbar.php'; ?>

<main class="page-container">

    <!-- HEADER -->
    <div class="header-section">
        <div class="header-title-area">
            <span class="badge-sub">DAFTAR AKTIVITAS</span>
            <h1>Pesanan Saya</h1>
            <p>Halo <strong><?= htmlspecialchars($namaPengguna, ENT_QUOTES, "UTF-8") ?></strong>, pantau status pesanan dan komunikasi dengan driver di sini.</p>
        </div>

        <a href="order.php" class="btn-order-new">
            + Buat Pesanan Baru
        </a>
    </div>

    <!-- NOTIFIKASI ALERT -->
    <?php if ($pesanType === "sukses" && $highlightId > 0): ?>
        <div class="alert-box alert-success">
            <div>
                <strong>Pesanan Berhasil Dikirim!</strong>
            </div>
        </div>
    <?php elseif ($pesanType === "auto_ditolak_3_menit"): ?>
        <div class="alert-box alert-info">
            <div>
                <strong>Pesanan Otomatis Ditolak (Batas 3 Menit).</strong>
                Pesanan tidak diambil driver dalam 3 menit. Silakan pilih ulang driver pada menu Riwayat Pesanan.
            </div>
        </div>
    <?php elseif ($pesanType === "bayar_sukses"): ?>
        <div class="payment-success-banner">
            <div class="payment-success-content">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="48" height="48" fill="#166534" style="flex-shrink:0;margin-right:12px;">
                    <path d="M9 12l2 2 4-4" stroke="#166534" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                    <circle cx="12" cy="12" r="10" stroke="#166534" stroke-width="2" fill="none"/>
                </svg>
                <div>
                    <h3>Pembayaran Diterima</h3>
                    <p>Pembayaran untuk Pesanan #<?= (int) $highlightId ?> sudah diterima dan pesanan sedang diproses.</p>
                    <?php if ($highlightTotal > 0): ?>
                        <p>Total: Rp <?= number_format($highlightTotal, 0, ',', '.') ?></p>
                    <?php endif; ?>
                    <a href="order.php?pesanan_id=<?= (int) $highlightId ?>" class="btn-primary">Lihat Detail Pesanan</a>
                    <a href="index.php" class="btn-secondary">Kembali ke Beranda</a>
                </div>
            </div>
        </div>
    <?php elseif ($pesanType === "bayar_gagal"): ?>
        <div class="alert-box alert-info">
            <div>Bukti pembayaran gagal diunggah. Pastikan file berupa JPG/PNG/WEBP dengan ukuran maksimal 5 MB.</div>
        </div>
    <?php elseif ($pesanType === "driver_terpilih" && $highlightId > 0): ?>
        <div class="alert-box alert-success">
            <div>
                <strong>Driver Berhasil Dipilih!</strong>
                Driver telah ditugaskan untuk menangani Pesanan #<?= (int) $highlightId ?>.
            </div>
        </div>
    <?php elseif ($pesanType === "driver_dipilih_ulang"): ?>
        <div class="alert-box alert-success">
            <div>
                <strong>Driver Baru Berhasil Dipilih!</strong>
                Pesanan lama Anda telah diteruskan ke driver baru dan masuk kembali ke Pesanan Aktif.
            </div>
        </div>
    <?php elseif ($pesanType === "pilih_driver_gagal"): ?>
        <div class="alert-box alert-info">
            <div><?= htmlspecialchars((string)($_GET['pesan_error'] ?? 'Gagal memilih ulang driver.'), ENT_QUOTES, 'UTF-8') ?></div>
        </div>
    <?php elseif ($pesanType === "dibatalkan"): ?>
        <div class="alert-box alert-info">
            <div>Pesanan berhasil dibatalkan.</div>
        </div>
    <?php elseif ($pesanType === "batal_gagal"): ?>
        <div class="alert-box alert-info">
            <div>Pesanan tidak dapat dibatalkan karena statusnya sudah berubah (mungkin sudah diproses driver).</div>
        </div>
    <?php elseif ($pesanType === "diedit"): ?>
        <div class="alert-box alert-success">
            <div>Rincian catatan pesanan berhasil diperbarui!</div>
        </div>
    <?php elseif ($pesanType === "edit_gagal"): ?>
        <div class="alert-box alert-info">
            <div>Pesanan tidak dapat diedit karena statusnya sudah berubah.</div>
        </div>
    <?php elseif ($pesanType === "csrf_gagal"): ?>
        <div class="alert-box alert-info">
            <div>Sesi tidak valid. Silakan muat ulang halaman lalu coba lagi.</div>
        </div>
    <?php elseif ($pesanType === "rating_sukses"): ?>
        <div class="alert-box alert-success">
            <div>Rating dan ulasan driver berhasil dikirim.</div>
        </div>
    <?php elseif ($pesanType === "rating_gagal"): ?>
        <div class="alert-box alert-info">
            <div><?= htmlspecialchars((string)($_GET['pesan_error'] ?? 'Rating gagal disimpan.'), ENT_QUOTES, 'UTF-8') ?></div>
        </div>
    <?php endif; ?>

    <!-- RINGKASAN STATISTIK -->
    <?php if ($totalPesanan > 0): ?>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Pesanan</div>
                <div class="stat-val"><?= $totalPesanan ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Sedang Berjalan</div>
                <div class="stat-val" style="color: #2563eb;"><?= $totalAktif ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Pesanan Selesai</div>
                <div class="stat-val" style="color: #16a34a;"><?= $totalSelesai ?></div>
            </div>
        </div>
    <?php endif; ?>

    <!-- ============ TABS NAVIGASI ============ -->
    <?php if ($totalPesanan > 0): ?>
        <div class="order-tabs-wrap">
            <button type="button" class="order-tab-btn <?= $activeTab === 'aktif' ? 'active' : '' ?>"
                    data-tab="aktif" onclick="switchOrderTab('aktif')">
                Pesanan Aktif
                <?php if ($totalAktif > 0): ?>
                    <span class="tab-badge tab-badge-yellow"><?= $totalAktif ?></span>
                <?php endif; ?>
            </button>
            <button type="button" class="order-tab-btn <?= $activeTab === 'riwayat' ? 'active' : '' ?>"
                    data-tab="riwayat" onclick="switchOrderTab('riwayat')">
                Riwayat Pesanan
                <?php if (count($pesananRiwayat) > 0): ?>
                    <span class="tab-badge tab-badge-gray"><?= count($pesananRiwayat) ?></span>
                <?php endif; ?>
            </button>
        </div>

        <!-- ======== TAB: PESANAN AKTIF ======== -->
        <div id="tab-aktif" class="order-tab-panel <?= $activeTab === 'aktif' ? 'active' : '' ?>">
            <?php if (empty($pesananAktif)): ?>
                <div class="empty-state" style="padding: 40px 20px;">
                    <div class="empty-title" style="font-size: 20px;">Tidak Ada Pesanan Aktif</div>
                    <p class="empty-desc">Semua pesananmu sudah selesai. Mau pesan lagi?</p>
                    <a href="order.php" class="btn-order-new">+ Buat Pesanan Baru</a>
                </div>
            <?php else: ?>
                <div class="orders-list">
                    <?php foreach ($pesananAktif as $item): ?>
                        <?php
                            $status    = $item['status'] ?? '';
                            $canCancel = in_array($status, ['Menunggu', 'Menunggu Driver', 'Menunggu Persetujuan Driver']);
                            $sisaTunggu = max(0, (int) ($item['sisa_detik_tunggu'] ?? 180));
                            $waNumber  = normalisasiNomorWA((string)($item['no_hp_driver'] ?? ''));
                            $waMsg = urlencode("Halo kak, saya ingin menanyakan pesanan #PESANAN-" . (int)$item['id'] . " (" . ($item['layanan'] ?? '') . "). Apakah ada yang perlu dikonfirmasi?");
                            $waUrl = $waNumber ? "https://wa.me/{$waNumber}?text={$waMsg}" : '';

                            // Status badge
                            $statusClass = match(true) {
                                in_array($status, ['Menunggu','Menunggu Driver','Menunggu Persetujuan Driver','Menunggu Penyesuaian Harga']) => 'status-menunggu',
                                $status === 'Menunggu Pembayaran' => 'status-bayar',
                                $status === 'Diproses'            => 'status-proses',
                                $status === 'Dalam Perjalanan'    => 'status-perjalanan',
                                default => 'status-menunggu',
                            };

                            $fotoKostFile = !empty($item['foto_kost']) ? htmlspecialchars(basename($item['foto_kost']), ENT_QUOTES, 'UTF-8') : '';
                        ?>
                        <div class="order-card <?= $highlightId === (int)$item['id'] ? 'is-highlighted' : '' ?>">
                            <?php if ($highlightId === (int)$item['id']): ?>
                                <div class="highlight-tag">Pesanan Terbaru</div>
                            <?php endif; ?>

                            <div class="card-top">
                                <div class="order-meta">
                                    <span class="order-id-badge">#PESANAN-<?= (int)$item['id'] ?></span>
                                    <span class="order-time"><?= formatTanggalIndo($item['created_at']) ?></span>
                                </div>
                                <span class="status-pill <?= $statusClass ?>">
                                    <?= htmlspecialchars($status) ?>
                                </span>
                            </div>

                            <?php if ($canCancel): ?>
                                <div class="countdown-3m-user"
                                     data-pesanan-id="<?= (int)$item['id'] ?>"
                                     data-seconds="<?= $sisaTunggu ?>"
                                     style="margin-top: 10px; padding: 10px 12px; border-radius: 10px; background: #fffbeb; border: 1px solid #fde68a; color: #92400e; font-size: 12px; font-weight: 700;">
                                    Batas waktu driver mengambil pesanan:
                                    <span class="timer-3m-val" style="color: #dc2626; font-weight: 900;"><?= gmdate("i:s", $sisaTunggu) ?></span>
                                    <div style="font-size: 11px; font-weight: 500; color: #78350f; margin-top: 2px;">
                                        Jika tidak diambil dalam 3 menit, pesanan otomatis ditolak dan Anda perlu memilih ulang driver.
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- BADGE METODE BAYAR -->
                            <?php
                                $metodeBayarItem = $item['metode_bayar'] ?? 'qris';
                                $platItem = (!empty($item['pickup_lat']) && (float)$item['pickup_lat'] != 0) ? $item['pickup_lat'] : '-6.4018000';
                                $plngItem = (!empty($item['pickup_lng']) && (float)$item['pickup_lng'] != 0) ? $item['pickup_lng'] : '108.2825000';
                                $hasRouteCoords  = !empty($item['dest_lat']);
                            ?>
                            <div style="margin-top: 10px; display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                                <span style="display: inline-flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 8px; <?= $metodeBayarItem === 'cash' ? 'background:#fef9c3; color:#92400e; border: 1px solid #fde68a;' : 'background:#ecfdf5; color:#047857; border: 1px solid #a7f3d0;' ?>">
                                    <?= $metodeBayarItem === 'cash' ? 'Cash / Tunai' : 'QRIS' ?>
                                </span>
                                <?php if ($hasRouteCoords): ?>
                                    <a href="https://www.google.com/maps/dir/?api=1&amp;origin=<?= urlencode((string)$platItem) ?>,<?= urlencode((string)$plngItem) ?>&amp;destination=<?= urlencode((string)$item['dest_lat']) ?>,<?= urlencode((string)$item['dest_lng']) ?>" target="_blank" rel="noopener noreferrer" style="font-size: 11px; font-weight: 700; color: #2563eb; text-decoration: none; padding: 4px 8px; background: #fff; border: 1px solid #cbd5e1; border-radius: 8px;">
                                        Buka Rute Maps
                                    </a>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($item['alamat']) && $item['alamat'] !== '-'): ?>
                                <div style="margin-top: 10px; font-size: 12px; color: #334155; background: #f8fafc; padding: 8px 12px; border-radius: 10px; border-left: 3px solid #2563eb;">
                                    <strong>Rute / Lokasi:</strong> <?= htmlspecialchars($item['alamat']) ?>
                                </div>
                            <?php endif; ?>

                            <div class="card-body">
                                <div>
                                    <div class="info-label">Catatan Kebutuhan</div>
                                    <div class="catatan-box">
                                        <?= nl2br(htmlspecialchars($item['catatan'] ?? '-', ENT_QUOTES, 'UTF-8')) ?>
                                    </div>
                                    <?php if ($fotoKostFile !== ''): ?>
                                        <div style="margin-top: 10px; background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; padding: 10px 12px;">
                                            <div style="font-size: 11px; font-weight: 800; color: #92400e; margin-bottom: 6px; display: flex; justify-content: space-between; align-items: center;">
                                                <span>Foto Keadaan Kost Anda:</span>
                                                <a href="uploads/foto_kost/<?= $fotoKostFile ?>" target="_blank" rel="noopener noreferrer" style="color: #2563eb; text-decoration: underline;">Buka Foto &rarr;</a>
                                            </div>
                                            <a href="uploads/foto_kost/<?= $fotoKostFile ?>" target="_blank" rel="noopener noreferrer">
                                                <img src="uploads/foto_kost/<?= $fotoKostFile ?>" alt="Foto Keadaan Kost" style="max-height: 140px; border-radius: 8px; border: 1px solid #cbd5e1; object-fit: cover;">
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div>
                                    <div class="info-label">Driver Penanggung Jawab</div>
                                    <?php if (!empty($item['nama_driver'])): ?>
                                        <div class="driver-info-panel">
                                            <div class="driver-profile">
                                                <div class="driver-profile-left">
                                                    <div class="driver-avatar-mini">
                                                        <?= htmlspecialchars(strtoupper(mb_substr($item['nama_driver'], 0, 1)), ENT_QUOTES, 'UTF-8') ?>
                                                    </div>
                                                    <div>
                                                        <div class="driver-name"><?= htmlspecialchars($item['nama_driver'], ENT_QUOTES, 'UTF-8') ?></div>
                                                        <div class="driver-sub">● Siap Menjalankan Tugas</div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div style="margin-top: 12px; display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                                                <a href="chat.php?pesanan_id=<?= (int)$item['id'] ?>" class="btn-chat-inapp">
                                                    Open Chat Driver
                                                </a>
                                                <?php if (!empty($waNumber)): ?>
                                                    <a href="<?= htmlspecialchars($waUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="btn-whatsapp">
                                                        <svg viewBox="0 0 24 24"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91C2.13 13.66 2.59 15.36 3.45 16.86L2.05 22L7.3 20.62C8.75 21.41 10.38 21.83 12.04 21.83C17.5 21.83 21.95 17.38 21.95 11.92C21.95 9.27 20.92 6.78 19.05 4.91C17.18 3.03 14.69 2 12.04 2M12.05 3.67C14.25 3.67 16.31 4.53 17.87 6.09C19.42 7.65 20.28 9.72 20.28 11.92C20.28 16.46 16.58 20.15 12.04 20.15C10.56 20.15 9.11 19.76 7.85 19.02L7.55 18.84L4.44 19.66L5.27 16.63L5.07 16.31C4.24 15 3.8 13.48 3.8 11.91C3.8 7.37 7.5 3.67 12.05 3.67M9.53 7.5C9.36 7.5 9.09 7.57 8.87 7.8C8.65 8.04 8.04 8.61 8.04 9.77C8.04 10.93 8.88 12.05 9 12.21C9.12 12.38 10.66 14.75 13.03 15.77C13.6 16.02 14.04 16.16 14.38 16.27C14.95 16.45 15.47 16.43 15.88 16.36C16.34 16.3 17.3 15.79 17.5 15.22C17.7 14.66 17.7 14.18 17.64 14.07C17.58 13.97 17.43 13.91 17.2 13.8C16.97 13.68 15.86 13.14 15.65 13.06C15.45 12.97 15.3 12.93 15.15 13.16C15 13.38 14.56 13.91 14.43 14.07C14.29 14.22 14.16 14.24 13.93 14.13C13.71 14.01 12.98 13.78 12.12 13.01C11.45 12.41 11 11.67 10.87 11.45C10.74 11.22 10.86 11.1 10.97 10.98C11.08 10.88 11.21 10.71 11.33 10.57C11.45 10.43 11.49 10.32 11.56 10.17C11.64 10.03 11.6 9.9 11.54 9.79C11.49 9.68 11.04 8.57 10.85 8.12C10.67 7.68 10.49 7.74 10.35 7.73C10.22 7.73 10.07 7.72 9.92 7.72C9.77 7.72 9.53 7.5 9.53 7.5Z"/></svg>
                                                        WhatsApp
                                                    </a>
                                                <?php else: ?>
                                                    <span style="font-size: 12px; color: #64748b; display: block; margin-top: 6px;">
                                                        ℹ️ Kontak WhatsApp belum disetel oleh driver
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="driver-missing-box">
                                            <div class="driver-missing-text">
                                                Belum ada driver yang dipilih.
                                            </div>
                                            <?php if ($canCancel): ?>
                                                <a href="pilih_driver.php?pesanan_id=<?= (int)$item['id'] ?>" class="btn-pick-driver-now">
                                                    Pilih Driver Sekarang
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- PEMBAYARAN QRIS / CASH -->
                            <?php if ($status === 'Menunggu Pembayaran'): ?>
                                <?php
                                    $isCashPayment   = ($item['metode_bayar'] ?? 'qris') === 'cash';
                                    $driverDisplayName = !empty($item['nama_driver']) ? htmlspecialchars($item['nama_driver'], ENT_QUOTES, 'UTF-8') : 'Driver';
                                    $isRejected      = ((int)($item['qris_diverifikasi'] ?? 0) === 2);

                                    $qrisImageSrc    = 'assets/img/qris.jpeg';
                                    $isSandboxActive = false;

                                    // Generate / ambil QRIS Dinamis Otomatis Midtrans (hanya untuk non-cash)
                                    if (!$isCashPayment) {
                                        $midtransData    = generateQrisPesananMidtrans($pdo, (int)$item['id']);
                                        $qrisImageSrc    = !empty($midtransData['qr_url']) ? $midtransData['qr_url'] : 'assets/img/qris.jpeg';
                                        $isSandboxActive = !empty($midtransData['is_sandbox']);
                                    }
                                ?>
                                <?php if ($isCashPayment): ?>
                                    <div class="qris-payment-box" style="background: linear-gradient(135deg, #fffbeb 0%, #fefce8 100%); border: 1px solid #fde68a;">
                                        <div class="qris-box-header">
                                            <div>
                                                <h4>Bayar Cash ke Driver <?= $driverDisplayName ?>!</h4>
                                                <span style="font-size: 13px; color: #92400e; font-weight: 600;">Siapkan uang tunai sesuai tarif pesanan dan biaya belanja.</span>
                                            </div>
                                        </div>
                                        <?php if (!empty($item['total_tarif'])): ?>
                                            <div style="margin-top: 12px; padding: 12px 16px; background: #fef08a; border-radius: 12px; border: 1px solid #fde047; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                                                <span style="font-size: 13px; font-weight: 700; color: #854d0e;">Total Biaya Kesepakatan:</span>
                                                <span style="font-size: 18px; font-weight: 900; color: #713f12;">Rp <?= number_format((float)$item['total_tarif'], 0, ',', '.') ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <div style="margin-top: 14px; padding: 14px 16px; background: #fff; border-radius: 12px; border: 1px solid #fde68a; font-size: 13px; line-height: 1.8; color: #78350f;">
                                            <div style="margin-bottom: 8px; font-weight: 800; font-size: 14px; color: #92400e;">Tata Cara Bayar Cash:</div>
                                            <ol style="margin: 0; padding-left: 18px;">
                                                <li><strong>Chat driver</strong> via WhatsApp untuk negosiasi harga &amp; detail pesanan.</li>
                                                <li>Driver akan mengambil/mengerjakan pesananmu terlebih dahulu.</li>
                                                <li><strong>Siapkan uang tunai</strong> sesuai kesepakatan saat driver tiba.</li>
                                                <li>Bayar langsung kepada driver setelah pesanan diterima.</li>
                                            </ol>
                                        </div>
                                        <?php if (!empty($waNumber)): ?>
                                            <a href="<?= htmlspecialchars($waUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"
                                               style="display: flex; align-items: center; justify-content: center; gap: 8px; margin-top: 14px; padding: 12px; background: #25d366; color: #fff; border-radius: 12px; font-weight: bold; font-size: 13px; text-decoration: none;"
                                               onmouseover="this.style.opacity='0.85'" onmouseout="this.style.opacity='1'">
                                                Chat Driver untuk Negosiasi Harga
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <?php if ($isRejected): ?>
                                        <div class="qris-rejected-alert">
                                            <div class="rejected-alert-content">
                                                <h4>Pembayaran QRIS Ditolak oleh Driver <?= $driverDisplayName ?>!</h4>
                                                <div class="rejected-reason">
                                                    <strong>Alasan Penolakan:</strong> "<?= htmlspecialchars($item['alasan_tolak_bayar'] ?: 'Bukti transfer tidak sesuai atau saldo belum masuk.') ?>"
                                                </div>
                                                <p class="rejected-instruction">Silakan lakukan pembayaran ulang melalui kode QRIS di bawah ini dengan nominal yang benar, lalu unggah bukti transfer yang asli &amp; sah.</p>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    <div class="qris-payment-box">
                                        <div class="qris-box-header">
                                            <div>
                                                <span style="font-size: 13px; color: #047857; font-weight: 600;">
                                                    Scan QRIS di bawah ini dengan m-Banking atau e-Wallet apa saja.
                                                </span>
                                            </div>
                                        </div>
                                        <p class="qris-desc">
                                            Pesananmu ditangani oleh Driver <strong><?= $driverDisplayName ?></strong>. Pembayaran menggunakan QRIS Nasional (GPN). Sistem akan <strong>otomatis memverifikasi pembayaran</strong> begitu kamu selesai bayar tanpa perlu menunggu cek manual.
                                        </p>
                                        <div class="qris-content-grid">
                                            <div class="qris-image-wrapper">
                                                <img src="<?= htmlspecialchars($qrisImageSrc, ENT_QUOTES, 'UTF-8') ?>" alt="QRIS Resmi Polindra Suruh" onerror="this.onerror=null; this.src='assets/img/qris.jpeg';" style="border: 2px solid #86efac; border-radius: 14px; background: #fff; padding: 6px; width: 100%; max-width: 260px; height: auto; object-fit: contain;">
                                                <div style="margin-top: 10px; font-size: 11.5px; font-weight: bold; padding: 5px 12px; border-radius: 8px; display: inline-block; background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0;">
                                                    Polindra Suruh &bull;
                                                </div>
                                                <?php if (!empty($item['total_tarif'])): ?>
                                                    <div style="margin-top: 10px; padding: 10px 14px; background: #f0fdf4; border-radius: 12px; border: 1.5px solid #86efac; text-align: center;">
                                                        <div style="font-size: 11px; color: #166534; font-weight: 800; text-transform: uppercase;">Total yang Harus Dibayar:</div>
                                                        <div style="font-size: 22px; font-weight: 900; color: #14532d; margin-top: 2px;">Rp <?= number_format((float)$item['total_tarif'], 0, ',', '.') ?></div>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="qris-instructions">
                                                <!-- STATUS VERIFIKASI OTOMATIS MIDTRANS -->
                                                <div id="paymentLiveStatus_<?= (int)$item['id'] ?>" style="background: #ffffff; border: 1.5px solid #86efac; border-radius: 14px; padding: 16px; margin-bottom: 14px; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.08);">
                                                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px;">
                                                        <span style="display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: #10b981; box-shadow: 0 0 8px #10b981;"></span>
                                                        <p style="font-size: 12.5px; color: #475569; margin: 0; line-height: 1.6;">
                                                            Buka aplikasi m-Banking (BCA, Mandiri, BRI, BNI) atau e-Wallet (GoPay, DANA, OVO, ShopeePay), lalu <strong>scan kode QR</strong> di samping. Pembayaran kamu akan langsung terverifikasi dalam hitungan detik!
                                                        </p>
                                                    </div>

                                                    <!-- TOMBOL UJI COBA SIMULASI SANDBOX -->
                                                    <?php if ($isSandboxActive): ?>
                                                        <div style="margin-top: 14px; padding-top: 12px; border-top: 1px dashed #cbd5e1;">
                                                            <div style="font-size: 11px; font-weight: 800; color: #047857; text-transform: uppercase; margin-bottom: 6px;">
                                                                Fitur Uji Coba (Sandbox Simulator):
                                                            </div>
                                                            <button type="button" onclick="simulasikanBayarMidtrans(<?= (int)$item['id'] ?>)" id="btnSimulasi_<?= (int)$item['id'] ?>" style="width: 100%; display: flex; align-items: center; justify-content: center; gap: 8px; background: #0f172a; color: #ffd21f; border: none; padding: 12px 16px; border-radius: 10px; font-weight: 800; font-size: 13px; cursor: pointer; transition: transform 0.15s; box-shadow: 0 3px 10px rgba(0,0,0,0.15);" onmouseover="this.style.transform='scale(1.02)'" onmouseout="this.style.transform='scale(1)'">
                                                                Klik Di Sini: Tes Simulasi Bayar Berhasil (Otomatis Lunas)
                                                            </button>
                                                            <div style="font-size: 11px; color: #64748b; margin-top: 6px; text-align: center;">
                                                                <em>Klik tombol di atas untuk menguji alur deteksi pembayaran otomatis tanpa perlu scan HP beneran.</em>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>

                                                <!-- OPSI CADANGAN: UNGGAH BUKTI MANUAL -->
                                                <details style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px 14px; font-size: 12.5px;">
                                                    <summary style="font-weight: 700; color: #475569; cursor: pointer; user-select: none;">
                                                        Unggah bukti pembayaran di sini:
                                                    </summary>
                                                    <form method="POST" action="pesanan_saya.php" enctype="multipart/form-data" style="margin-top: 12px;">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                        <input type="hidden" name="konfirmasi_bayar" value="1">
                                                        <input type="hidden" name="pesanan_id" value="<?= (int)$item['id'] ?>">
                                                        <div class="upload-bukti-box" style="margin-bottom: 10px;">
                                                            <label class="upload-bukti-label" style="font-size: 12px;">
                                                                Pilih foto screenshot/struk (maks. 5 MB):
                                                            </label>
                                                            <input type="file" name="bukti_bayar" required accept="image/png, image/jpeg, image/webp" class="file-input-bukti" onchange="previewBukti(this, 'preview_user_<?= (int)$item['id'] ?>')">
                                                            <div id="preview_user_<?= (int)$item['id'] ?>" class="bukti-upload-preview" style="display: none;"></div>
                                                        </div>
                                                        <button type="submit" class="btn-konfirmasi-qris" style="padding: 10px 14px; font-size: 12px;">
                                                            Kirim Bukti Pembayaran
                                                        </button>
                                                    </form>
                                                </details>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php elseif ($status === 'Diproses'): ?>
                                <?php
                                    $buktiBayarFile    = !empty($item['bukti_bayar']) ? basename($item['bukti_bayar']) : '';
                                    $userHasBukti      = $buktiBayarFile !== '' && file_exists(__DIR__ . '/uploads/bukti_bayar/' . $buktiBayarFile);
                                    $userBuktiSrc      = $userHasBukti ? 'uploads/bukti_bayar/' . rawurlencode($buktiBayarFile) : '';
                                    $isDriverVerified  = ((int)($item['qris_diverifikasi'] ?? 0) === 1);
                                    $driverDisplayName = !empty($item['nama_driver']) ? htmlspecialchars($item['nama_driver'], ENT_QUOTES, 'UTF-8') : 'Driver';
                                ?>
                                <?php if ($isDriverVerified): ?>
                                    <div class="qris-status-banner qris-status-banner-verified">
                                        <div>
                                            <strong style="font-size: 14px;">Pembayaran QRIS Terbukti Sah &amp; Diterima Driver!</strong>
                                            <p style="margin-top: 4px; color: #166534;">Driver <strong><?= $driverDisplayName ?></strong> telah memverifikasi bahwa pembayaran QRIS Anda sudah masuk.</p>
                                            <?php if ($userHasBukti): ?>
                                                <button type="button" class="btn-lihat-bukti-user" onclick="openBuktiModal(<?= htmlspecialchars(json_encode($userBuktiSrc), ENT_QUOTES, 'UTF-8') ?>)">👁️ Lihat Bukti Transfer Anda</button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="qris-status-banner qris-status-banner-pending">
                                        <div>
                                            <strong style="font-size: 14px;">Pembayaran Terkonfirmasi - Menunggu Verifikasi Driver</strong>
                                            <p style="margin-top: 4px; color: #92400e;">Anda telah mengonfirmasi pembayaran QRIS. Driver <strong><?= $driverDisplayName ?></strong> sedang memeriksa mutasi saldo.</p>
                                            <?php if ($userHasBukti): ?>
                                                <button type="button" class="btn-lihat-bukti-user" onclick="openBuktiModal(<?= htmlspecialchars(json_encode($userBuktiSrc), ENT_QUOTES, 'UTF-8') ?>)">👁️ Lihat Bukti Transfer Anda</button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php elseif ($status === 'Dalam Perjalanan'): ?>
                                <?php
                                    $buktiBayarFileDP = !empty($item['bukti_bayar']) ? basename($item['bukti_bayar']) : '';
                                    $userHasBuktiDP   = $buktiBayarFileDP !== '' && file_exists(__DIR__ . '/uploads/bukti_bayar/' . $buktiBayarFileDP);
                                    $userBuktiSrcDP   = $userHasBuktiDP ? 'uploads/bukti_bayar/' . rawurlencode($buktiBayarFileDP) : '';
                                ?>
                                <div class="status-banner-info" style="background: #fefce8; border-color: #eab308; color: #854d0e;">
                                    <div style="flex: 1;">
                                        <strong>Sedang Diantar!</strong> Driver sedang dalam perjalanan menyelesaikan pesananmu.
                                        <?php if ((int)($item['qris_diverifikasi'] ?? 0) === 1): ?>
                                            <div style="margin-top: 4px; font-size: 12px; color: #15803d; font-weight: 600;">✓ Pembayaran QRIS Telah Terverifikasi Sah oleh Driver</div>
                                        <?php endif; ?>
                                        <?php if ($userHasBuktiDP): ?>
                                            <button type="button" class="btn-lihat-bukti-user" style="margin-top: 6px;" onclick="openBuktiModal(<?= htmlspecialchars(json_encode($userBuktiSrcDP), ENT_QUOTES, 'UTF-8') ?>)">👁️ Lihat Bukti Transfer</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="card-footer">
                                <div>
                                    <?php if ($canCancel): ?>
                                        <button type="button" class="btn-edit-pesanan"
                                            onclick='openEditModal(<?= (int)$item["id"] ?>, <?= json_encode((string)($item["catatan"] ?? ""), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'>
                                            Edit Pesanan
                                        </button>
                                        <form method="POST" action="pesanan_saya.php" onsubmit="return confirm('Apakah kamu yakin ingin membatalkan Pesanan #<?= (int)$item['id'] ?>?');" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="batal_pesanan" value="1">
                                            <input type="hidden" name="pesanan_id" value="<?= (int)$item['id'] ?>">
                                            <button type="submit" class="btn-batal">Batalkan Pesanan</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <a href="order.php" class="btn-reorder">↺ Pesan Lagi</a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; unset($item); ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ======== TAB: RIWAYAT PESANAN ======== -->
        <div id="tab-riwayat" class="order-tab-panel <?= $activeTab === 'riwayat' ? 'active' : '' ?>">
            <?php if (empty($pesananRiwayat)): ?>
                <div class="empty-state" style="padding: 40px 20px;">
                    <div class="empty-title" style="font-size: 20px;">Belum Ada Riwayat</div>
                    <p class="empty-desc">Pesanan yang sudah selesai atau dibatalkan akan muncul di sini.</p>
                </div>
            <?php else: ?>
                <!-- Ringkasan cepat riwayat -->
                <div style="display:flex; flex-direction:column; gap:12px; margin-bottom: 24px;">
                    <?php foreach ($pesananRiwayat as $row):
                        $stRiwayat = $row["status"] ?? "-";
                        $isDriverMenolak = in_array($stRiwayat, ['Driver Menolak', 'Ditolak Driver'], true);
                        if ($stRiwayat === "Selesai") { $rIcon = ""; $rColor = "#16a34a"; $rBg = "#f0fdf4"; $rBorder = "#bbf7d0"; }
                        elseif ($stRiwayat === "Dibatalkan" || $isDriverMenolak) { $rIcon = ""; $rColor = "#dc2626"; $rBg = "#fef2f2"; $rBorder = "#fecaca"; }
                        else { $rIcon = ""; $rColor = "#64748b"; $rBg = "#f8fafc"; $rBorder = "#e2e8f0"; }
                        $isSelesai = ($stRiwayat === 'Selesai');
                        $isDibatalkan = ($stRiwayat === 'Dibatalkan');
                        $buktiFile = trim((string)($row['bukti_antar'] ?? ''));
                        $sudahRating = $row['rating'] !== null;
                    ?>
                    <div style="border:1.5px solid <?= $rBorder ?>; border-radius:14px; background:<?= $rBg ?>; overflow:hidden;">
                        <div style="display:flex; justify-content:space-between; align-items:center; gap:14px; flex-wrap:wrap; padding:14px 18px;">
                            <div style="display:flex; align-items:center; gap:12px; flex:1; min-width:0;">
                                <?php if ($rIcon !== ''): ?>
                                    <span style="font-size:22px; flex-shrink:0;"><?= $rIcon ?></span>
                                <?php endif; ?>
                                <div style="min-width:0;">
                                    <div style="font-size:13px; font-weight:800; color:#111827;">#PESANAN-<?= (int)$row["id"] ?> &nbsp;·&nbsp; <?= htmlspecialchars($row["layanan"] ?? "-") ?></div>
                                    <div style="font-size:12px; color:#64748b; margin-top:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                        <?= htmlspecialchars(mb_strimwidth($row["catatan"] ?? "-", 0, 55, "...")) ?>
                                    </div>
                                    <div style="font-size:11px; color:#94a3b8; margin-top:3px;"><?= formatTanggalIndo($row["created_at"] ?? null) ?></div>
                                </div>
                            </div>
                            <div style="display:flex; align-items:center; gap:10px; flex-shrink:0;">
                                <?php if (!empty($row["total_tarif"])): ?>
                                    <span style="font-size:14px; font-weight:900; color:<?= $rColor ?>;">Rp <?= number_format((int)$row["total_tarif"], 0, ',', '.') ?></span>
                                <?php endif; ?>
                                <span style="font-size:11px; font-weight:700; color:<?= $rColor ?>; padding:4px 10px; border-radius:20px; border:1px solid <?= $rBorder ?>; background:#fff; white-space:nowrap;">
                                    <?= htmlspecialchars($stRiwayat) ?>
                                </span>
                                <?php if ($isDibatalkan): ?>
                                    <a href="pilih_driver.php?pesanan_id=<?= (int)$row["id"] ?>"
                                       style="font-size:11px; font-weight:800; color:#1d4ed8; background:#eff6ff; border:1px solid #bfdbfe; padding:5px 10px; border-radius:8px; text-decoration:none; white-space:nowrap;">
                                        Pilih Ulang Driver
                                    </a>
                                <?php endif; ?>
                                <?php if ($isDriverMenolak): ?>
                                    <button type="button"
                                            onclick="bukaModalPilihDriverUlang(<?= (int)$row["id"] ?>)"
                                            style="font-size:11px; font-weight:800; color:#000; background:#ffd21f; border:1.5px solid #000; padding:5px 10px; border-radius:8px; cursor:pointer; white-space:nowrap;">
                                        Pilih Driver Lain
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($isSelesai): ?>
                            <div style="margin:0 18px 18px; padding:14px; border-top:1px solid <?= $rBorder ?>; background:#fff; border-radius:10px;">
                                <div>
                                    <div style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px;">Bukti Pengantaran</div>
                                    <?php if ($buktiFile !== ''):
                                        $safeFile = htmlspecialchars(basename($buktiFile), ENT_QUOTES, 'UTF-8');
                                        $buktiSrc = 'uploads/bukti_antar/' . $safeFile;
                                        $buktiExists = file_exists(__DIR__ . '/uploads/bukti_antar/' . basename($buktiFile));
                                    ?>
                                        <?php if ($buktiExists): ?>
                                            <a href="<?= $buktiSrc ?>" target="_blank" rel="noopener noreferrer">
                                                <img src="<?= $buktiSrc ?>" alt="Bukti pengantaran pesanan #<?= (int)$row['id'] ?>" style="width:100%;max-width:260px;height:160px;object-fit:cover;border-radius:10px;border:1px solid #cbd5e1;">
                                            </a>
                                            <div style="font-size:11px;color:#64748b;margin-top:6px;">Klik gambar untuk memperbesar.</div>
                                        <?php else: ?>
                                            <div style="font-size:13px;color:#64748b;">Bukti pengantaran tidak ditemukan.</div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div style="font-size:13px;color:#64748b;">Bukti pengantaran belum diunggah oleh driver.</div>
                                    <?php endif; ?>
                                </div>

                                <div style="margin-top:14px;padding-top:12px;border-top:1px solid #e2e8f0;">
                                    <?php if ($sudahRating): ?>
                                        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap;">
                                            <div>
                                                <div style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.4px;">Rating Kamu</div>
                                                <div style="font-size:15px;font-weight:900;color:#b45309;margin-top:3px;">★ <?= (int)$row['rating'] ?> / 5</div>
                                                <?php if (!empty($row['ulasan'])): ?>
                                                    <div style="font-size:13px;color:#475569;margin-top:5px;">"<?= htmlspecialchars((string)$row['ulasan'], ENT_QUOTES, 'UTF-8') ?>"</div>
                                                <?php endif; ?>
                                            </div>
                                            <span style="font-size:11px;font-weight:800;color:#047857;background:#ecfdf5;border:1px solid #a7f3d0;padding:5px 9px;border-radius:20px;">Sudah Dinilai</span>
                                        </div>
                                    <?php else: ?>
                                        <form action="proses_rating.php" method="POST" style="display:grid;gap:10px;">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="pesanan_id" value="<?= (int)$row['id'] ?>">
                                            <label for="rating_<?= (int)$row['id'] ?>" style="font-size:12px;font-weight:800;color:#334155;">Beri Rating Driver</label>
                                            <select id="rating_<?= (int)$row['id'] ?>" name="rating" required style="width:min(260px,100%);padding:9px 10px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;font-size:13px;color:#1e293b;">
                                                <option value="">-- Pilih Rating --</option>
                                                <option value="5">5 - Sangat Memuaskan</option>
                                                <option value="4">4 - Memuaskan</option>
                                                <option value="3">3 - Cukup</option>
                                                <option value="2">2 - Kurang</option>
                                                <option value="1">1 - Buruk</option>
                                            </select>
                                            <label for="ulasan_<?= (int)$row['id'] ?>" style="font-size:12px;font-weight:800;color:#334155;">Ulasan (Opsional)</label>
                                            <textarea id="ulasan_<?= (int)$row['id'] ?>" name="ulasan" rows="2" placeholder="Tulis catatan singkat untuk driver..." style="width:100%;padding:9px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:13px;color:#1e293b;box-sizing:border-box;resize:vertical;"></textarea>
                                            <button type="submit" style="width:max-content;padding:9px 14px;border:0;border-radius:8px;background:#2563eb;color:#fff;font-size:13px;font-weight:800;cursor:pointer;">Kirim Rating</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; unset($row); ?>
                </div>
            <?php endif; ?>
        </div>

    <?php else: ?>
        <!-- EMPTY STATE - belum pernah order sama sekali -->
        <div class="empty-state">
            <div class="empty-title">Belum Ada Pesanan</div>
            <p class="empty-desc">
                Kamu belum pernah membuat pesanan di Polindra Suruh.<br>
                Lagi mager beli makan, butuh titip barang, atau bantuan tugas?<br>
                Serahkan pada driver kami!
            </p>
            <a href="order.php" class="btn-order-new">+ Buat Pesanan Pertama</a>
        </div>
    <?php endif; ?>

</main>

<!-- [TAMBAHAN BARU] Modal Pilih Ulang Driver pada Pesanan Ditolak -->
<div id="modalPilihDriverUlang" class="bukti-modal-backdrop" onclick="if(event.target===this) tutupModalPilihDriverUlang();">
    <div class="bukti-modal-content" onclick="event.stopPropagation()">
        <div class="bukti-modal-header">
            <h3 style="margin: 0; font-size: 16px;">Pilih Driver Baru (<span id="labelPesananDriverUlang">#-</span>)</h3>
            <button type="button" class="bukti-modal-close" onclick="tutupModalPilihDriverUlang()">&times;</button>
        </div>
        <?php if (empty($driversOnline)): ?>
            <div style="padding: 20px; text-align: left;">
                <p style="margin: 0; font-size: 13px; color: #64748b;">Saat ini belum ada driver yang sedang Online.</p>
            </div>
            <div class="bukti-modal-footer">
                <button type="button" class="btn-close-modal" onclick="tutupModalPilihDriverUlang()">Tutup</button>
            </div>
        <?php else: ?>
            <form method="POST" action="pilih_ulang_driver.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="pesanan_id" id="inputPesananIdDriverUlang" value="">
                <div style="padding: 20px; text-align: left;">
                    <label style="display: block; font-size: 13px; font-weight: 700; color: #1e293b; margin-bottom: 6px;">Driver Online Tersedia:</label>
                    <select name="driver_user_id" required style="width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; background: #fff; color: #1e293b;">
                        <?php foreach ($driversOnline as $drv): ?>
                            <option value="<?= (int) $drv['user_id'] ?>">
                                <?= htmlspecialchars($drv['nama_driver'], ENT_QUOTES, 'UTF-8') ?>
                                (★ <?= number_format((float) $drv['rating_rata'], 1) ?> / <?= (int) $drv['jumlah_rating'] ?> ulasan)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="bukti-modal-footer">
                    <button type="button" class="btn-close-modal" onclick="tutupModalPilihDriverUlang()">Batal</button>
                    <button type="submit" class="btn-konfirmasi-qris" style="padding: 9px 18px; font-size: 13px;">Kirim ke Driver Ini</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Lightbox Bukti Transfer User -->
<div id="modalBukti" class="bukti-modal-backdrop" onclick="closeBuktiModal(event)">
    <div class="bukti-modal-content" onclick="event.stopPropagation()">
        <div class="bukti-modal-header">
            <h3>Bukti Pembayaran QRIS Anda</h3>
            <button type="button" class="bukti-modal-close" onclick="closeBuktiModal()">&times;</button>
        </div>
        <div class="bukti-modal-body">
            <img id="imgBuktiModal" src="" alt="Bukti Pembayaran">
        </div>
        <div class="bukti-modal-footer">
            <a id="linkBuktiDownload" href="" target="_blank" rel="noopener noreferrer" class="btn-download-bukti">Buka Ukuran Asli</a>
            <button type="button" class="btn-close-modal" onclick="closeBuktiModal()">Tutup</button>
        </div>
    </div>
</div>

<!-- Modal Edit Pesanan User -->
<div id="modalEditPesanan" class="bukti-modal-backdrop" onclick="closeEditModal(event)">
    <div class="bukti-modal-content" onclick="event.stopPropagation()">
        <div class="bukti-modal-header">
            <h3 style="margin: 0; font-size: 16px;">Edit Rincian Pesanan #<span id="labelEditId"></span></h3>
            <button type="button" class="bukti-modal-close" onclick="closeEditModal()">&times;</button>
        </div>
        <form method="POST" action="pesanan_saya.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="edit_pesanan" value="1">
            <input type="hidden" name="pesanan_id" id="editPesananId">
            <div style="padding: 20px; text-align: left;">
                <div style="margin-bottom: 12px;">
                    <label style="display: block; font-size: 13px; font-weight: 700; color: #1e293b; margin-bottom: 6px;">Catatan / Barang yang Disuruh</label>
                    <textarea name="catatan" id="editCatatan" rows="4" style="width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; box-sizing: border-box; font-family: inherit; resize: vertical;" required></textarea>
                    <small style="color: #64748b; font-size: 12px; display: block; margin-top: 4px;">Anda dapat mengubah rincian pesanan selama driver belum menyetujui pesanan ini.</small>
                </div>
            </div>
            <div class="bukti-modal-footer">
                <button type="button" class="btn-close-modal" onclick="closeEditModal()">Batal</button>
                <button type="submit" class="btn-konfirmasi-qris" style="padding: 9px 18px; font-size: 13px;">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<script>
// [TAMBAHAN BARU] Handler Modal Pilih Ulang Driver
function bukaModalPilihDriverUlang(pesananId) {
    var modal = document.getElementById('modalPilihDriverUlang');
    if (!modal) return;
    document.getElementById('inputPesananIdDriverUlang').value = pesananId;
    document.getElementById('labelPesananDriverUlang').textContent = '#' + pesananId;
    modal.classList.add('active');
}

function tutupModalPilihDriverUlang() {
    var modal = document.getElementById('modalPilihDriverUlang');
    if (modal) modal.classList.remove('active');
}

function switchOrderTab(tabId) {
    document.querySelectorAll('.order-tab-btn').forEach(function (btn) {
        btn.classList.toggle('active', btn.getAttribute('data-tab') === tabId);
    });
    document.querySelectorAll('.order-tab-panel').forEach(function (panel) {
        panel.classList.remove('active');
    });
    var panel = document.getElementById('tab-' + tabId);
    if (panel) panel.classList.add('active');
    try {
        var url = new URL(window.location);
        url.searchParams.set('tab', tabId);
        window.history.replaceState({}, '', url);
    } catch (e) {}
}

function openEditModal(id, catatan) {
    var modal = document.getElementById('modalEditPesanan');
    if (!modal) return;
    document.getElementById('editPesananId').value = id;
    document.getElementById('labelEditId').innerText = id;
    document.getElementById('editCatatan').value = catatan;
    modal.classList.add('active');
}

function closeEditModal() {
    var modal = document.getElementById('modalEditPesanan');
    if (modal) modal.classList.remove('active');
}

function openBuktiModal(imageSrc) {
    var modal = document.getElementById('modalBukti');
    var img = document.getElementById('imgBuktiModal');
    var link = document.getElementById('linkBuktiDownload');
    if (modal && img) {
        img.src = imageSrc;
        if (link) link.href = imageSrc;
        modal.classList.add('active');
    }
}

function closeBuktiModal() {
    var modal = document.getElementById('modalBukti');
    if (modal) modal.classList.remove('active');
}

function previewBukti(input, previewId) {
    var preview = document.getElementById(previewId);
    if (!preview) return;

    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function (e) {
            var img = document.createElement('img');
            img.src = e.target.result;
            img.alt = 'Preview';
            preview.innerHTML = '';
            preview.appendChild(img);
            preview.style.display = 'block';
        };
        reader.readAsDataURL(input.files[0]);
    } else {
        preview.innerHTML = '';
        preview.style.display = 'none';
    }
}

function redirectBayarSukses(pesananId) {
    window.location.href = 'pesanan_saya.php?id=' + encodeURIComponent(pesananId) + '&tab=aktif&pesan=bayar_sukses';
}

// === COUNTDOWN 3 MENIT (180 DETIK) AUTO-TOLAK PESANAN ===
document.addEventListener('DOMContentLoaded', function () {
    var countdownEls = document.querySelectorAll('.countdown-3m-user');
    if (countdownEls.length > 0) {
        var countdownTimer = setInterval(function () {
            var expiredOrderId = null;

            countdownEls.forEach(function (el) {
                var sec = parseInt(el.getAttribute('data-seconds') || '0', 10);
                if (sec > 0) {
                    sec -= 1;
                    el.setAttribute('data-seconds', sec);
                    var mm = String(Math.floor(sec / 60)).padStart(2, '0');
                    var ss = String(sec % 60).padStart(2, '0');
                    var valEl = el.querySelector('.timer-3m-val');
                    if (valEl) valEl.textContent = mm + ':' + ss;
                }
                if (sec <= 0 && !expiredOrderId) {
                    expiredOrderId = el.getAttribute('data-pesanan-id') || '';
                }
            });

            if (expiredOrderId !== null) {
                clearInterval(countdownTimer);
                window.location.href = 'pesanan_saya.php?tab=riwayat&pesan=auto_ditolak_3_menit';
            }
        }, 1000);
    }
});

// === REAL-TIME PAYMENT DETECTOR & SIMULATOR ===
function simulasikanBayarMidtrans(pesananId) {
    var btn = document.getElementById('btnSimulasi_' + pesananId);
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = 'Memproses simulasi pembayaran...';
    }

    fetch('api/check_payment_status.php?pesanan_id=' + encodeURIComponent(pesananId) + '&simulasi=1')
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data.success && data.paid) {
                var liveBox = document.getElementById('paymentLiveStatus_' + pesananId);
                if (liveBox) {
                    liveBox.style.background = '#dcfce7';
                    liveBox.style.borderColor = '#22c55e';
                    liveBox.innerHTML = '<div style="color:#15803d; font-weight:900; font-size:14px; text-align:center;">Pembayaran Berhasil Terverifikasi Otomatis! Menyiapkan pesanan...</div>';
                }
                setTimeout(function () { redirectBayarSukses(pesananId); }, 1200);
            } else {
                alert(data.message || 'Gagal simulasi');
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = 'Klik Di Sini: Tes Simulasi Bayar Berhasil (Otomatis Lunas)';
                }
            }
        })
        .catch(function () {
            alert('Koneksi terputus');
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = 'Klik Di Sini: Tes Simulasi Bayar Berhasil (Otomatis Lunas)';
            }
        });
}

// Auto-polling status pembayaran QRIS tiap 3.5 detik untuk pesanan yang masih 'Menunggu Pembayaran'
document.addEventListener('DOMContentLoaded', function () {
    var cards = document.querySelectorAll('[id^="paymentLiveStatus_"]');
    if (!cards.length) return;

    var done = false;
    var timer = setInterval(function () {
        if (done) { clearInterval(timer); return; }

        cards.forEach(function (card) {
            var pesananId = card.id.replace('paymentLiveStatus_', '');
            if (!pesananId) return;

            fetch('api/check_payment_status.php?pesanan_id=' + encodeURIComponent(pesananId))
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.paid && !done) {
                        done = true;
                        clearInterval(timer);
                        card.style.background = '#dcfce7';
                        card.style.borderColor = '#22c55e';
                        card.innerHTML = '<div style="color:#15803d; font-weight:900; font-size:14px; text-align:center;">Pembayaran Berhasil Terdeteksi! Memperbarui...</div>';
                        setTimeout(function () { redirectBayarSukses(pesananId); }, 1000);
                    }
                })
                .catch(function () {});
        });
    }, 3500);
});

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        closeBuktiModal();
        closeEditModal();
        tutupModalPilihDriverUlang();
    }
});
</script>
</body>
</html>