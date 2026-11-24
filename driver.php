<?php
session_start();
require_once "koneksi.php";
require_once "includes/wallet_helper.php";
require_once __DIR__ . "/includes/fitur_helper.php";

// Pastikan yang login adalah driver
if (
    !isset($_SESSION["user_id"]) ||
    ($_SESSION["role"] ?? "") !== "driver"
) {
    header("Location: login.php");
    exit;
}

$driverId = (int) $_SESSION["user_id"];
$namaDriver = $_SESSION["nama"] ?? "Driver";
$notif = $_GET["notif"] ?? "";

// Daftar status pesanan yang masih "menunggu driver"
$STATUS_MENUNGGU = [
    "Menunggu",
    "Menunggu Driver",
    "Menunggu Persetujuan Driver",
    "Menunggu Penyesuaian Harga",
    "Menunggu Penyesuaian Driver",
];

// Pastikan tabel/kolom penolakan tersedia (otomatis, sekali per request)
pastikanSkemaPenolakan($pdo);

// Auto-tolak: pesanan yang tidak direspons driver dalam 2 menit (120 detik)
// dikembalikan ke pelanggan agar memilih driver lain (pesanan tetap sama).
prosesAutoTolakDuaMenit($pdo);

// Proses perubahan status Aktif / Off
if (
    ($_SERVER["REQUEST_METHOD"] ?? "") === "POST" &&
    isset($_POST["ubah_status"])
) {
    $statusBaru = (int) ($_POST["status_aktif"] ?? -1);

    if ($statusBaru === 0 || $statusBaru === 1) {
        $stmt = $pdo->prepare(
            "UPDATE users
             SET status_aktif = :status
             WHERE id = :id AND role = 'driver'"
        );
        $stmt->execute([
            "status" => $statusBaru,
            "id" => $driverId
        ]);

        $stmtDriver = $pdo->prepare(
            "UPDATE drivers
             SET status_aktif = :status
             WHERE user_id = :id"
        );
        $stmtDriver->execute([
            "status" => $statusBaru,
            "id" => $driverId
        ]);
    }

    header("Location: driver.php?notif=status_diubah");
    exit;
}

// Proses update No. WhatsApp Driver
if (
    ($_SERVER["REQUEST_METHOD"] ?? "") === "POST" &&
    isset($_POST["update_whatsapp"])
) {
    $noHpBaru = trim($_POST["no_hp"] ?? "");

    $stmtCheck = $pdo->prepare("SELECT id FROM drivers WHERE user_id = ?");
    $stmtCheck->execute([$driverId]);
    if ($stmtCheck->fetch()) {
        $stmtUpdate = $pdo->prepare("UPDATE drivers SET no_hp = ? WHERE user_id = ?");
        $stmtUpdate->execute([$noHpBaru, $driverId]);
    } else {
        $stmtInsert = $pdo->prepare("INSERT INTO drivers (user_id, nama_driver, no_hp, status_aktif) VALUES (?, ?, ?, 1)");
        $stmtInsert->execute([$driverId, $namaDriver, $noHpBaru]);
    }

    header("Location: driver.php?notif=wa_tersimpan&tab=settings");
    exit;
}

// Proses Upload / Perbarui Foto QRIS Driver
if (
    ($_SERVER["REQUEST_METHOD"] ?? "") === "POST" &&
    isset($_POST["upload_qris"])
) {
    if (isset($_FILES["foto_qris"]) && $_FILES["foto_qris"]["error"] === UPLOAD_ERR_OK) {
        $fileTmp = $_FILES["foto_qris"]["tmp_name"];
        $fileName = $_FILES["foto_qris"]["name"];
        $fileSize = $_FILES["foto_qris"]["size"];

        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $allowedExtensions = ["jpg", "jpeg", "png", "webp"];

        if (!in_array($ext, $allowedExtensions, true)) {
            header("Location: driver.php?notif=qris_format_salah&tab=settings");
            exit;
        }

        if ($fileSize > 3 * 1024 * 1024) { // Max 3MB
            header("Location: driver.php?notif=qris_terlalu_besar&tab=settings");
            exit;
        }

        // Cek MIME type
        $mime = "";
        if (function_exists("finfo_open")) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = finfo_file($finfo, $fileTmp);
                finfo_close($finfo);
            }
        } elseif (function_exists("mime_content_type")) {
            $mime = mime_content_type($fileTmp);
        }

        $allowedMimes = ["image/jpeg", "image/png", "image/webp", "image/pjpeg"];
        if ($mime && !in_array($mime, $allowedMimes, true)) {
            header("Location: driver.php?notif=qris_format_salah&tab=settings");
            exit;
        }

        $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "qris" . DIRECTORY_SEPARATOR;
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $newFileName = "qris_driver_" . $driverId . "_" . time() . "." . $ext;
        $destPath = $uploadDir . $newFileName;

        if (move_uploaded_file($fileTmp, $destPath)) {
            $stmtOld = $pdo->prepare("SELECT foto_qris FROM drivers WHERE user_id = ?");
            $stmtOld->execute([$driverId]);
            $oldQris = $stmtOld->fetchColumn();
            if ($oldQris && file_exists($uploadDir . $oldQris)) {
                @unlink($uploadDir . $oldQris);
            }

            $stmtCheck = $pdo->prepare("SELECT id FROM drivers WHERE user_id = ?");
            $stmtCheck->execute([$driverId]);
            if ($stmtCheck->fetch()) {
                $stmtUpdate = $pdo->prepare("UPDATE drivers SET foto_qris = ? WHERE user_id = ?");
                $stmtUpdate->execute([$newFileName, $driverId]);
            } else {
                $stmtInsert = $pdo->prepare("INSERT INTO drivers (user_id, nama_driver, foto_qris, status_aktif) VALUES (?, ?, ?, 1)");
                $stmtInsert->execute([$driverId, $namaDriver, $newFileName]);
            }

            header("Location: driver.php?notif=qris_tersimpan&tab=settings");
            exit;
        } else {
            header("Location: driver.php?notif=qris_gagal_upload&tab=settings");
            exit;
        }
    } else {
        header("Location: driver.php?notif=qris_pilih_file&tab=settings");
        exit;
    }
}

// Proses Hapus Foto QRIS Driver
if (
    ($_SERVER["REQUEST_METHOD"] ?? "") === "POST" &&
    isset($_POST["hapus_qris"])
) {
    $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "qris" . DIRECTORY_SEPARATOR;
    $stmtOld = $pdo->prepare("SELECT foto_qris FROM drivers WHERE user_id = ?");
    $stmtOld->execute([$driverId]);
    $oldQris = $stmtOld->fetchColumn();
    if ($oldQris && file_exists($uploadDir . $oldQris)) {
        @unlink($uploadDir . $oldQris);
    }

    $stmtUpdate = $pdo->prepare("UPDATE drivers SET foto_qris = NULL WHERE user_id = ?");
    $stmtUpdate->execute([$driverId]);

    header("Location: driver.php?notif=qris_dihapus&tab=settings");
    exit;
}

// Proses Aksi Pesanan: Terima / Tolak / Antar / Selesai
if (
    ($_SERVER["REQUEST_METHOD"] ?? "") === "POST" &&
    isset($_POST["aksi_pesanan"])
) {
    $pesananId = (int) ($_POST["pesanan_id"] ?? 0);
    $aksi = $_POST["aksi_pesanan"];

    if ($aksi === "terima") {
        $stmtCek = $pdo->prepare("
            SELECT metode_bayar, status,
                   TIMESTAMPDIFF(SECOND, COALESCE(diajukan_at, created_at), NOW()) AS elapsed
            FROM pesanan
            WHERE id = :id AND (driver_id IS NULL OR driver_id = :driver_id)
        ");
        $stmtCek->execute(["id" => $pesananId, "driver_id" => $driverId]);
        $rowCek = $stmtCek->fetch(PDO::FETCH_ASSOC);

        if (!$rowCek || !in_array($rowCek["status"], $STATUS_MENUNGGU, true)) {
            header("Location: driver.php?notif=sudah_diambil&tab=new");
            exit;
        }

        if ((int) ($rowCek["elapsed"] ?? 0) >= 120) {
            prosesAutoTolakDuaMenit($pdo, $pesananId);
            header("Location: driver.php?notif=timeout_2_menit&tab=new");
            exit;
        }

        $targetStatus = (($rowCek["metode_bayar"] ?? "qris") === "cash") ? "Diproses" : "Menunggu Pembayaran";

        $stmtDrv = $pdo->prepare("SELECT lat, lng FROM drivers WHERE user_id = ? LIMIT 1");
        $stmtDrv->execute([$driverId]);
        $drv = $stmtDrv->fetch(PDO::FETCH_ASSOC);
        $plat = ($drv && !empty($drv['lat'])) ? $drv['lat'] : '-6.4018000';
        $plng = ($drv && !empty($drv['lng'])) ? $drv['lng'] : '108.2825000';

        $placeholders = implode(",", array_fill(0, count($STATUS_MENUNGGU), "?"));
        $stmt = $pdo->prepare("
            UPDATE pesanan
            SET status = ?, driver_id = ?, pickup_lat = ?, pickup_lng = ?
            WHERE id = ?
              AND (driver_id IS NULL OR driver_id = ?)
              AND status IN ($placeholders)
        ");
        $stmt->execute(array_merge(
            [$targetStatus, $driverId, $plat, $plng, $pesananId, $driverId],
            $STATUS_MENUNGGU
        ));

        if ($stmt->rowCount() === 0) {
            header("Location: driver.php?notif=sudah_diambil&tab=new");
            exit;
        }

        header("Location: driver.php?notif=diterima&tab=running");
        exit;

    } elseif ($aksi === "konfirmasi_qris") {
        // Wajib ada bukti transfer dari pelanggan sebelum dikonfirmasi
        $stmtBukti = $pdo->prepare("SELECT bukti_bayar FROM pesanan WHERE id = :id AND driver_id = :driver_id");
        $stmtBukti->execute(["id" => $pesananId, "driver_id" => $driverId]);
        if (empty($stmtBukti->fetchColumn())) {
            header("Location: driver.php?notif=bukti_belum_ada&tab=running");
            exit;
        }

        $stmt = $pdo->prepare("
            UPDATE pesanan
            SET qris_diverifikasi = 1,
                alasan_tolak_bayar = NULL,
                status = CASE WHEN status = 'Menunggu Pembayaran' THEN 'Diproses' ELSE status END
            WHERE id = :id AND driver_id = :driver_id
        ");
        $stmt->execute(["id" => $pesananId, "driver_id" => $driverId]);
        header("Location: driver.php?notif=qris_diverifikasi&tab=running");
        exit;

    } elseif ($aksi === "tolak_qris") {
        $alasanPilihan = trim($_POST["alasan_pilihan"] ?? "");
        $alasanKustom = trim($_POST["alasan_kustom"] ?? "");

        $alasanTolak = $alasanPilihan;
        if ($alasanKustom !== "") {
            $alasanTolak = ($alasanPilihan !== "" && $alasanPilihan !== "Lainnya")
                ? $alasanPilihan . " (" . $alasanKustom . ")"
                : $alasanKustom;
        }
        if ($alasanTolak === "") {
            $alasanTolak = "Bukti pembayaran tidak sesuai atau dana belum masuk.";
        }

        $stmt = $pdo->prepare("
            UPDATE pesanan
            SET qris_diverifikasi = 2,
                alasan_tolak_bayar = :alasan,
                status = 'Menunggu Pembayaran'
            WHERE id = :id AND driver_id = :driver_id
        ");
        $stmt->execute([
            "alasan" => $alasanTolak,
            "id" => $pesananId,
            "driver_id" => $driverId
        ]);
        header("Location: driver.php?notif=qris_ditolak&tab=running");
        exit;

    } elseif ($aksi === "sesuaikan_harga_kost") {
        $hargaBaru = (int) ($_POST["harga_penyesuaian"] ?? 0);
        $catatanTambahan = trim($_POST["catatan_driver"] ?? "");
        if ($hargaBaru < 5000) $hargaBaru = 15000;

        $stmtCek = $pdo->prepare("
            SELECT metode_bayar, catatan, status,
                   TIMESTAMPDIFF(SECOND, COALESCE(diajukan_at, created_at), NOW()) AS elapsed
            FROM pesanan
            WHERE id = :id AND (driver_id IS NULL OR driver_id = :did)
        ");
        $stmtCek->execute(["id" => $pesananId, "did" => $driverId]);
        $rowCek = $stmtCek->fetch(PDO::FETCH_ASSOC);

        if (!$rowCek || !in_array($rowCek["status"], $STATUS_MENUNGGU, true)) {
            header("Location: driver.php?notif=sudah_diambil&tab=new");
            exit;
        }

        if ((int) ($rowCek["elapsed"] ?? 0) >= 120) {
            prosesAutoTolakDuaMenit($pdo, $pesananId);
            header("Location: driver.php?notif=timeout_2_menit&tab=new");
            exit;
        }

        $targetStatus = (($rowCek["metode_bayar"] ?? "qris") === "cash") ? "Diproses" : "Menunggu Pembayaran";

        $catatanUpdate = $rowCek["catatan"] ?? "";
        if ($catatanTambahan !== "") {
            $catatanUpdate .= "\n[Catatan Driver]: " . $catatanTambahan;
        }

        $placeholders = implode(",", array_fill(0, count($STATUS_MENUNGGU), "?"));
        $stmt = $pdo->prepare("
            UPDATE pesanan
            SET total_tarif = ?, driver_id = ?, status = ?, catatan = ?
            WHERE id = ?
              AND (driver_id IS NULL OR driver_id = ?)
              AND status IN ($placeholders)
        ");
        $stmt->execute(array_merge(
            [$hargaBaru, $driverId, $targetStatus, $catatanUpdate, $pesananId, $driverId],
            $STATUS_MENUNGGU
        ));

        if ($stmt->rowCount() === 0) {
            header("Location: driver.php?notif=sudah_diambil&tab=new");
            exit;
        }

        header("Location: driver.php?notif=harga_disesuaikan&tab=running");
        exit;

    } elseif ($aksi === "tolak") {
        // Pesanan dikembalikan ke pelanggan (status "Driver Menolak")
        // agar pelanggan memilih driver lain pada pesanan yang sama.
        tolakPesananOlehDriver($pdo, $pesananId, $driverId);
        header("Location: driver.php?notif=ditolak&tab=new");
        exit;

    } elseif ($aksi === "antar") {
        $stmt = $pdo->prepare("
            UPDATE pesanan
            SET status = 'Dalam Perjalanan'
            WHERE id = :id AND driver_id = :driver_id AND status = 'Diproses'
        ");
        $stmt->execute(["id" => $pesananId, "driver_id" => $driverId]);
        header("Location: driver.php?notif=antar&tab=running");
        exit;

    } elseif ($aksi === "selesai") {
        // Wajib mengunggah bukti pengantaran untuk semua layanan
        $uploadRes = uploadBuktiAntarWajib($_FILES["bukti_antar"] ?? [], $pesananId, $driverId);
        if (!$uploadRes["ok"]) {
            header("Location: driver.php?notif=bukti_antar_wajib&pesan_error=" . urlencode($uploadRes["message"]) . "&tab=running");
            exit;
        }

        $resSelesai = selesaikanPesananDenganBukti($pdo, $pesananId, $driverId, $uploadRes["filename"]);
        if ($resSelesai["ok"]) {
            header("Location: driver.php?notif=selesai&tab=completed");
        } else {
            header("Location: driver.php?notif=gagal_selesai&pesan_error=" . urlencode($resSelesai["message"]) . "&tab=running");
        }
        exit;
    }
}

// =========================================================================
// PROSES PENARIKAN DANA (WITHDRAW) DOMPET DRIVER
// =========================================================================
if (($_SERVER["REQUEST_METHOD"] ?? "") === "POST" && isset($_POST["tarik_saldo"])) {
    $nominal = (int) ($_POST["nominal"] ?? 0);
    $bankTujuan = trim($_POST["bank_tujuan"] ?? "");
    $nomorRekening = trim($_POST["nomor_rekening"] ?? "");
    $atasNama = trim($_POST["atas_nama"] ?? "");

    if ($nominal <= 0 || $bankTujuan === "" || $nomorRekening === "" || $atasNama === "") {
        header("Location: driver.php?notif=tarik_gagal&pesan_error=" . urlencode("Semua data rekening/e-wallet wajib diisi.") . "&tab=wallet");
        exit;
    }

    $hasilTarik = ajukanPenarikanDana($pdo, $driverId, $nominal, $bankTujuan, $nomorRekening, $atasNama);
    if ($hasilTarik['status']) {
        header("Location: driver.php?notif=tarik_sukses&tab=wallet");
    } else {
        header("Location: driver.php?notif=tarik_gagal&pesan_error=" . urlencode($hasilTarik['pesan']) . "&tab=wallet");
    }
    exit;
}

// Ambil status terbaru, data dompet, dan rating driver
$stmt = $pdo->prepare(
    "SELECT u.status_aktif, d.id AS driver_db_id, d.saldo, d.nama_bank, d.nomor_rekening, d.atas_nama,
            d.no_hp, d.foto_qris, d.lat, d.lng,
            COALESCE(d.rating_rata, 0) AS rating_rata,
            COALESCE(d.jumlah_rating, 0) AS jumlah_rating
     FROM users u
     LEFT JOIN drivers d ON d.user_id = u.id
     WHERE u.id = :id AND u.role = 'driver'"
);
$stmt->execute(["id" => $driverId]);
$dataDriver = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$dataDriver) {
    session_destroy();
    header("Location: login.php");
    exit;
}

$statusAktif = (int) $dataDriver["status_aktif"];
$driverDbId = (int) ($dataDriver["driver_db_id"] ?? 0);
$saldoDriver = (int) ($dataDriver["saldo"] ?? 0);
$bankDriver = $dataDriver["nama_bank"] ?? "";
$rekDriver = $dataDriver["nomor_rekening"] ?? "";
$anDriver = $dataDriver["atas_nama"] ?? "";
$noHpDriver = $dataDriver["no_hp"] ?? "";
$fotoQrisDriver = $dataDriver["foto_qris"] ?? null;
$latDriver = $dataDriver["lat"] ?? null;
$lngDriver = $dataDriver["lng"] ?? null;
$ratingRataDriver = (float) ($dataDriver["rating_rata"] ?? 0);
$jumlahRatingDriver = (int) ($dataDriver["jumlah_rating"] ?? 0);

// Ambil riwayat penarikan dana & mutasi dompet
$riwayatPenarikan = [];
$riwayatMutasi = [];
$totalDitarik = 0;
if ($driverDbId > 0) {
    $stmtTarik = $pdo->prepare("SELECT * FROM penarikan_dana WHERE driver_id = ? ORDER BY id DESC LIMIT 10");
    $stmtTarik->execute([$driverDbId]);
    $riwayatPenarikan = $stmtTarik->fetchAll(PDO::FETCH_ASSOC);

    $stmtMutasi = $pdo->prepare("SELECT * FROM dompet_transaksi WHERE driver_id = ? ORDER BY id DESC LIMIT 15");
    $stmtMutasi->execute([$driverDbId]);
    $riwayatMutasi = $stmtMutasi->fetchAll(PDO::FETCH_ASSOC);

    $stmtTotalTarik = $pdo->prepare("SELECT COALESCE(SUM(nominal), 0) FROM penarikan_dana WHERE driver_id = ? AND status = 'Selesai'");
    $stmtTotalTarik->execute([$driverDbId]);
    $totalDitarik = (int) $stmtTotalTarik->fetchColumn();
}

// Ambil pesanan driver:
// - pesanan milik driver ini, atau
// - pesanan bebas yang masih menunggu, belum ditolak driver ini, dan belum lewat 2 menit
$stmt = $pdo->prepare(
    "SELECT p.*, u.nama AS nama_pelanggan, u.no_wa AS wa_pelanggan, u.email AS email_pelanggan,
            GREATEST(0, 120 - TIMESTAMPDIFF(SECOND, COALESCE(p.diajukan_at, p.created_at), NOW())) AS sisa_detik_ambil
     FROM pesanan p
     JOIN users u ON p.user_id = u.id
     WHERE (
         p.driver_id = :driver_id
         OR (
             p.driver_id IS NULL
             AND p.status IN ('Menunggu Driver', 'Menunggu', 'Menunggu Persetujuan Driver', 'Menunggu Penyesuaian Harga', 'Menunggu Penyesuaian Driver')
             AND NOT EXISTS (
                 SELECT 1 FROM pesanan_driver_ditolak pd
                 WHERE pd.pesanan_id = p.id AND pd.driver_user_id = :driver_id_tolak
             )
         )
     )
     AND NOT (
         p.status IN ('Menunggu Driver', 'Menunggu', 'Menunggu Persetujuan Driver', 'Menunggu Penyesuaian Harga', 'Menunggu Penyesuaian Driver')
         AND TIMESTAMPDIFF(SECOND, COALESCE(p.diajukan_at, p.created_at), NOW()) >= 120
     )
     ORDER BY p.id DESC"
);
$stmt->execute([
    "driver_id" => $driverId,
    "driver_id_tolak" => $driverId
]);
$pesanan = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pesananBaru = [];
$pesananBerjalan = [];
$pesananSelesai = [];
$totalPendapatan = 0;

foreach ($pesanan as $p) {
    $st = $p["status"];
    if (in_array($st, $STATUS_MENUNGGU, true)) {
        $pesananBaru[] = $p;
    } elseif (in_array($st, ["Menunggu Pembayaran", "Diproses", "Dalam Perjalanan"], true)) {
        $pesananBerjalan[] = $p;
    } else {
        $pesananSelesai[] = $p;
        if ($st === "Selesai") {
            $totalPendapatan += (int) ($p["total_tarif"] ?? 0);
        }
    }
}

$activeTab = $_GET["tab"] ?? (!empty($pesananBaru) ? 'new' : (!empty($pesananBerjalan) ? 'running' : 'new'));
$cssPath = __DIR__ . '/assets/css/style.css';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Driver - Polindra Suruh</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= file_exists($cssPath) ? filemtime($cssPath) : time() ?>">
</head>

<body class="page-driver">

<?php require_once __DIR__ . '/includes/navbar.php'; ?>

<div class="driver-container">

    <!-- NOTIFIKASI FEEDBACK -->
    <?php if ($notif === "status_diubah"): ?>
        <div class="driver-alert alert-success">Status operasional Anda berhasil diperbarui!</div>
    <?php elseif ($notif === "diterima"): ?>
        <div class="driver-alert alert-success">Pesanan berhasil diterima! Silakan cek di tab "Sedang Berjalan".</div>
    <?php elseif ($notif === "sudah_diambil"): ?>
        <div class="driver-alert alert-warning">Pesanan sudah diambil driver lain atau tidak tersedia lagi.</div>
    <?php elseif ($notif === "harga_disesuaikan"): ?>
        <div class="driver-alert alert-success">Harga ditetapkan dan pesanan berhasil diterima.</div>
    <?php elseif ($notif === "ditolak"): ?>
        <div class="driver-alert alert-info">Pesanan ditolak. Pelanggan akan diminta memilih driver lain pada pesanan yang sama.</div>
    <?php elseif ($notif === "timeout_2_menit"): ?>
        <div class="driver-alert alert-warning">Waktu respons 2 menit telah habis. Pesanan dikembalikan ke pelanggan untuk memilih driver lain.</div>
    <?php elseif ($notif === "bukti_antar_wajib"): ?>
        <div class="driver-alert alert-warning"><?= htmlspecialchars($_GET["pesan_error"] ?? "Foto bukti pengantaran wajib diunggah untuk semua layanan sebelum menyelesaikan pesanan.") ?></div>
    <?php elseif ($notif === "gagal_selesai"): ?>
        <div class="driver-alert alert-warning"><?= htmlspecialchars($_GET["pesan_error"] ?? "Gagal menyelesaikan pesanan.") ?></div>
    <?php elseif ($notif === "bukti_belum_ada"): ?>
        <div class="driver-alert alert-warning">Pelanggan belum mengunggah bukti transfer.</div>
    <?php elseif ($notif === "qris_diverifikasi"): ?>
        <div class="driver-alert alert-success">Pembayaran QRIS telah diverifikasi sah! Pesanan siap diantar.</div>
    <?php elseif ($notif === "qris_ditolak"): ?>
        <div class="driver-alert alert-warning">Pembayaran QRIS ditolak dan pelanggan diminta bayar ulang.</div>
    <?php elseif ($notif === "antar"): ?>
        <div class="driver-alert alert-info">Status diubah: Pesanan sedang dalam perjalanan ke pelanggan.</div>
    <?php elseif ($notif === "selesai"): ?>
        <div class="driver-alert alert-success">Selamat! Pesanan telah selesai diantar beserta bukti pengantaran. Pendapatan ditambahkan.</div>
    <?php elseif ($notif === "wa_tersimpan"): ?>
        <div class="driver-alert alert-success">Nomor WhatsApp berhasil disimpan!</div>
    <?php elseif ($notif === "qris_tersimpan"): ?>
        <div class="driver-alert alert-success">Foto QRIS pribadi Anda berhasil diunggah!</div>
    <?php elseif ($notif === "qris_dihapus"): ?>
        <div class="driver-alert alert-info">Foto QRIS telah dihapus.</div>
    <?php elseif ($notif === "qris_format_salah"): ?>
        <div class="driver-alert alert-warning">Format foto QRIS harus JPG, PNG, atau WEBP.</div>
    <?php elseif ($notif === "qris_terlalu_besar"): ?>
        <div class="driver-alert alert-warning">Ukuran foto QRIS maksimal 3 MB.</div>
    <?php elseif ($notif === "qris_gagal_upload"): ?>
        <div class="driver-alert alert-warning">Gagal mengunggah foto QRIS. Coba lagi.</div>
    <?php elseif ($notif === "qris_pilih_file"): ?>
        <div class="driver-alert alert-warning">Pilih file foto QRIS terlebih dahulu.</div>
    <?php elseif ($notif === "tarik_sukses"): ?>
        <div class="driver-alert alert-success">Pengajuan penarikan dana berhasil dikirim! Admin akan segera memproses transfer ke rekening/e-wallet Anda.</div>
    <?php elseif ($notif === "tarik_gagal"): ?>
        <div class="driver-alert alert-warning">Gagal menarik saldo: <?= htmlspecialchars($_GET['pesan_error'] ?? 'Terjadi kesalahan') ?></div>
    <?php endif; ?>

    <!-- 1. DRIVER PROFILE HERO CARD -->
    <div class="driver-hero-card">
        <div class="driver-hero-left">
            <div class="driver-avatar-circle">
                <?= strtoupper(substr($namaDriver, 0, 1)) ?>
            </div>
            <div class="driver-hero-meta">
                <h1>
                    <span><?= htmlspecialchars($namaDriver) ?></span>
                    <?php if ($statusAktif === 1): ?>
                        <span class="driver-status-badge status-badge-online">● Online (Siap Terima Order)</span>
                    <?php else: ?>
                        <span class="driver-status-badge status-badge-offline">○ Offline (Istirahat)</span>
                    <?php endif; ?>
                </h1>
                <div style="font-size: 13px; color: #64748b;">
                    Driver Resmi Polindra Suruh
                    &bull; <strong style="color:#d97706;">★ <?= $jumlahRatingDriver > 0 ? number_format($ratingRataDriver, 1) . ' / 5.0 (' . $jumlahRatingDriver . ' ulasan)' : 'Belum ada rating' ?></strong>
                    &bull; WA: <?= $noHpDriver ? htmlspecialchars($noHpDriver) : '<em>Belum diisi</em>' ?>
                </div>
                <div style="margin-top: 6px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                    <div id="driverGpsBadge" style="display: inline-flex; align-items: center; gap: 6px; font-size: 11.5px; background: #ecfdf5; color: #047857; padding: 3px 10px; border-radius: 8px; border: 1px solid #a7f3d0; font-weight: 700;">
                        <span id="driverGpsTeks">
                            <?= (!empty($latDriver) && !empty($lngDriver)) ? 'GPS Aktif: ' . round((float)$latDriver, 5) . ', ' . round((float)$lngDriver, 5) : 'GPS: Menghubungkan...' ?>
                        </span>
                    </div>
                    <button type="button" onclick="syncGpsDriverManual()" style="background: #f1f5f9; border: 1px solid #cbd5e1; font-size: 11px; padding: 3px 8px; border-radius: 6px; font-weight: 700; cursor: pointer; color: #334155;">🔄 Update GPS</button>
                </div>
            </div>
        </div>

        <div class="driver-hero-actions">
            <form method="POST" action="driver.php" style="margin: 0;">
                <input type="hidden" name="ubah_status" value="1">
                <input type="hidden" name="status_aktif" value="<?= $statusAktif === 1 ? 0 : 1 ?>">
                <button type="submit" class="btn-toggle-status <?= $statusAktif === 1 ? 'btn-toggle-offline' : 'btn-toggle-online' ?>">
                    <?= $statusAktif === 1 ? 'Set Istirahat (Off)' : 'Aktifkan (Online)' ?>
                </button>
            </form>
            <a href="logout.php" class="btn-nav-logout">Keluar</a>
        </div>
    </div>

    <!-- 2. KPI / RINGKASAN ANGKA -->
    <div class="stats-grid">
        <div class="stat-card" style="border-left: 4px solid #f59e0b;">
            <div class="stat-meta">
                <div class="stat-label">Order Baru</div>
                <div class="stat-value"><?= count($pesananBaru) ?></div>
            </div>
        </div>

        <div class="stat-card" style="border-left: 4px solid #2563eb;">
            <div class="stat-meta">
                <div class="stat-label">Sedang Berjalan</div>
                <div class="stat-value"><?= count($pesananBerjalan) ?></div>
            </div>
        </div>

        <div class="stat-card" style="border-left: 4px solid #10b981;">
            <div class="stat-meta">
                <div class="stat-label">Order Selesai</div>
                <div class="stat-value"><?= count($pesananSelesai) ?></div>
            </div>
        </div>

        <div class="stat-card" style="border-left: 4px solid #059669; background: #ecfdf5;">
            <div class="stat-meta">
                <div class="stat-label" style="color: #047857;">Saldo Dompet</div>
                <div class="stat-value" style="font-size: 16px; color: #065f46;">Rp <?= number_format($saldoDriver, 0, ',', '.') ?></div>
            </div>
        </div>

        <div class="stat-card" style="border-left: 4px solid #64748b;">
            <div class="stat-meta">
                <div class="stat-label">Total Pendapatan</div>
                <div class="stat-value" style="font-size: 16px;">Rp <?= number_format($totalPendapatan, 0, ',', '.') ?></div>
            </div>
        </div>
    </div>

    <!-- 3. TABS NAVIGASI MENU -->
    <div class="driver-tabs">
        <button type="button" class="tab-btn <?= $activeTab === 'new' ? 'active' : '' ?>" onclick="switchTab('new')">
            Pesanan Baru
            <?php if (count($pesananBaru) > 0): ?>
                <span class="tab-counter counter-badge-orange"><?= count($pesananBaru) ?></span>
            <?php endif; ?>
        </button>

        <button type="button" class="tab-btn <?= $activeTab === 'running' ? 'active' : '' ?>" onclick="switchTab('running')">
            Sedang Berjalan
            <?php if (count($pesananBerjalan) > 0): ?>
                <span class="tab-counter counter-badge-blue"><?= count($pesananBerjalan) ?></span>
            <?php endif; ?>
        </button>

        <button type="button" class="tab-btn <?= $activeTab === 'completed' ? 'active' : '' ?>" onclick="switchTab('completed')">
            Riwayat Selesai
            <span class="tab-counter counter-badge-green"><?= count($pesananSelesai) ?></span>
        </button>

        <button type="button" class="tab-btn <?= $activeTab === 'wallet' ? 'active' : '' ?>" onclick="switchTab('wallet')">
            Dompet (Rp <?= number_format($saldoDriver, 0, ',', '.') ?>)
        </button>

        <button type="button" class="tab-btn <?= $activeTab === 'settings' ? 'active' : '' ?>" onclick="switchTab('settings')">
            QRIS & WhatsApp
        </button>
    </div>

    <!-- ========================================================
         TAB 1: PESANAN BARU MASUK
         ======================================================== -->
    <div id="panel-new" class="tab-panel <?= $activeTab === 'new' ? 'active' : '' ?>">
        <?php if (empty($pesananBaru)): ?>
            <div class="empty-placeholder">
                <strong>Belum ada pesanan baru yang masuk.</strong>
                <p style="margin-top: 4px;">Pastikan status driver tetap <b>Online</b> agar pelanggan dapat memilihmu.</p>
            </div>
        <?php else: ?>
            <?php foreach ($pesananBaru as $item): ?>
                <?php
                    $isCashItem = ($item["metode_bayar"] ?? "qris") === "cash";
                    $jarakItem = isset($item["jarak_km"]) && $item["jarak_km"] !== null ? (float) $item["jarak_km"] : null;
                    $tarifItem = isset($item["total_tarif"]) && $item["total_tarif"] !== null && (int)$item["total_tarif"] > 0 ? (int) $item["total_tarif"] : null;
                    $hasCoords = !empty($item["pickup_lat"]) && !empty($item["dest_lat"]);
                    $sisaDetik = max(0, (int) ($item["sisa_detik_ambil"] ?? 120));

                    if ($tarifItem === null && $hasCoords && function_exists("hitungTarifOtomatis")) {
                        $calcTarif = hitungTarifOtomatis($pdo, $item["layanan"] ?? "Food", $item["pickup_lat"], $item["pickup_lng"], $item["dest_lat"], $item["dest_lng"]);
                        if (!empty($calcTarif["total_tarif"])) {
                            $tarifItem = (int) $calcTarif["total_tarif"];
                            if ($jarakItem === null) $jarakItem = (float) $calcTarif["jarak_km"];
                        }
                    }
                    if ($tarifItem === null && function_exists("isLayananTarifOtomatis") && isLayananTarifOtomatis($item["layanan"] ?? "")) {
                        $tarifItem = 5000;
                    }
                ?>
                <div class="order-card-driver" style="border-left: 5px solid #f59e0b;">
                    <div class="order-card-top">
                        <span class="order-id-tag">#PESANAN-<?= (int) $item["id"] ?></span>
                        <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                            <span class="countdown-3m" data-seconds="<?= $sisaDetik ?>" style="font-size: 12px; font-weight: 800; background: #fef2f2; color: #b91c1c; padding: 4px 10px; border-radius: 8px; border: 1px solid #fecaca;">
                                Auto-tolak: <span class="timer-val"><?= gmdate("i:s", $sisaDetik) ?></span>
                            </span>
                            <span style="font-size: 12px; font-weight: 700; background: #fffbeb; color: #b45309; padding: 4px 10px; border-radius: 8px; border: 1px solid #fef08a;">
                                Menunggu Konfirmasi Driver
                            </span>
                        </div>
                    </div>

                    <div class="order-customer-box" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                        <div>
                            Pelanggan: <strong><?= htmlspecialchars($item["nama_pelanggan"]) ?></strong>
                            <?php if (!empty($item["wa_pelanggan"])): ?>
                                <?php
                                    $waClean = preg_replace('/[^0-9]/', '', $item['wa_pelanggan']);
                                    $waTarget = (substr($waClean, 0, 2) === '08') ? '62' . substr($waClean, 1) : $waClean;
                                ?>
                                &bull; <a href="https://wa.me/<?= htmlspecialchars($waTarget) ?>" target="_blank" style="color: #000000; font-weight: 700; text-decoration: underline;">WA: <?= htmlspecialchars($item["wa_pelanggan"]) ?></a>
                            <?php elseif (!empty($item["email_pelanggan"])): ?>
                                &bull; <?= htmlspecialchars($item["email_pelanggan"]) ?>
                            <?php endif; ?>
                        </div>
                        <a href="chat.php?pesanan_id=<?= (int)$item['id'] ?>" style="display: inline-flex; align-items: center; gap: 5px; background: #ffff00; color: #000000; font-weight: 800; font-size: 12px; padding: 6px 14px; border-radius: 20px; text-decoration: none; border: 1.5px solid #000000; box-shadow: 0 2px 6px rgba(0,0,0,0.08);">
                            Chat Pelanggan
                        </a>
                    </div>

                    <div class="route-info-box">
                        <div class="route-point">
                            <div><b>Rute Pengantaran:</b> <?= nl2br(htmlspecialchars(($item["alamat"] ?? '-') !== '-' ? ($item["alamat"] ?? '-') : $item["layanan"])) ?></div>
                            <?php if (!empty($item['dest_lat'])): ?>
                                <a href="https://www.google.com/maps/dir/?api=1&origin=<?= $item['pickup_lat'] ?>,<?= $item['pickup_lng'] ?>&destination=<?= $item['dest_lat'] ?>,<?= $item['dest_lng'] ?>" target="_blank" rel="noopener noreferrer" class="btn-route-maps" onclick="return bukaRuteDriver(event, '<?= $item['dest_lat'] ?>', '<?= $item['dest_lng'] ?>', '<?= $item['pickup_lat'] ?>', '<?= $item['pickup_lng'] ?>');">
                                    Buka Rute di Maps &rarr;
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="fare-badge-bar" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; background: #f0fdf4; border: 1.5px solid #86efac; border-radius: 12px; padding: 12px 16px; margin-bottom: 12px;">
                        <div>
                            <div style="font-size: 11px; color: #166534; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px;">
                                Layanan: <span style="color: #0f172a; font-weight: 900;"><?= htmlspecialchars($item["layanan"]) ?></span>
                            </div>
                            <div style="font-size: 12px; color: #047857; margin-top: 2px; font-weight: 600;">
                                <?php if ($jarakItem !== null && $jarakItem > 0): ?>
                                    Jarak Tempuh: <strong>~<?= number_format($jarakItem, 2, ',', '.') ?> km</strong>
                                <?php else: ?>
                                    Lokasi Sekitar Polindra
                                <?php endif; ?>
                            </div>
                        </div>

                        <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                            <span style="font-size: 11px; font-weight: 800; padding: 5px 10px; border-radius: 8px; <?= $isCashItem ? 'background:#fef9c3; color:#92400e; border:1px solid #fef08a;' : 'background:#dcfce7; color:#15803d; border:1px solid #bbf7d0;' ?>">
                                <?= $isCashItem ? 'Pembayaran Tunai (Cash)' : 'QRIS (Masuk Dompet)' ?>
                            </span>

                            <div style="text-align: right;">
                                <div style="font-size: 11px; color: #166534; font-weight: 800; text-transform: uppercase;">Total Ongkir:</div>
                                <div class="fare-amount" style="font-size: 20px; font-weight: 900; color: #15803d; line-height: 1.1;">
                                    <?= ($tarifItem !== null && $tarifItem > 0) ? 'Rp ' . number_format($tarifItem, 0, ',', '.') : 'Nego via Chat' ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($item['foto_kost'])): ?>
                        <div style="background: #fffbeb; border: 1.5px solid #fde68a; border-radius: 12px; padding: 12px 14px; margin-bottom: 12px;">
                            <div style="font-size: 12px; font-weight: 800; color: #92400e; margin-bottom: 6px; display: flex; align-items: center; justify-content: space-between;">
                                <span>Foto Keadaan Kamar Kost Pelanggan:</span>
                                <a href="uploads/foto_kost/<?= htmlspecialchars($item['foto_kost']) ?>" target="_blank" style="font-size: 11px; color: #2563eb; text-decoration: underline;">
                                    Lihat Ukuran Penuh &rarr;
                                </a>
                            </div>
                            <a href="uploads/foto_kost/<?= htmlspecialchars($item['foto_kost']) ?>" target="_blank">
                                <img src="uploads/foto_kost/<?= htmlspecialchars($item['foto_kost']) ?>" alt="Foto Keadaan Kost" style="max-height: 180px; width: 100%; object-fit: cover; border-radius: 8px; border: 1px solid #cbd5e1;">
                            </a>
                        </div>
                    <?php endif; ?>

                    <div class="catatan-driver-box">
                        <strong>Barang / Menu yang Diminta Pelanggan:</strong><br>
                        <?= nl2br(htmlspecialchars($item["catatan"] ?? "-")) ?>
                    </div>

                    <?php
                        $isBeresinKostItem = (stripos($item['layanan'] ?? '', 'kost') !== false || stripos($item['layanan'] ?? '', 'beres') !== false);
                    ?>

                    <?php if ($isBeresinKostItem): ?>
                        <div style="background: #f0fdf4; border: 1.5px solid #bbf7d0; border-radius: 14px; padding: 14px; margin-bottom: 12px;">
                            <form method="POST" action="driver.php" style="display: flex; flex-direction: column; gap: 8px;">
                                <input type="hidden" name="pesanan_id" value="<?= (int) $item["id"] ?>">
                                <input type="hidden" name="aksi_pesanan" value="sesuaikan_harga_kost">
                                <label style="font-size: 12.5px; font-weight: 800; color: #166534;">
                                    Tentukan Ongkos Kerja Sesuai Foto Kost (Rp):
                                </label>
                                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                    <input
                                        type="number"
                                        name="harga_penyesuaian"
                                        min="5000"
                                        step="1000"
                                        style="flex: 1; min-width: 140px; padding: 10px 12px; border: 1.5px solid #86efac; border-radius: 8px; font-size: 14px; font-weight: 700;"
                                        value="<?= (int)(((int)($item['total_tarif'] ?? 0)) > 5000 ? $item['total_tarif'] : 25000) ?>"
                                        required
                                    >
                                    <button type="submit" class="btn-action btn-accept" style="width: auto; padding: 10px 18px;" onclick="return confirm('Tetapkan harga ini dan terima pesanan?');">
                                        Tetapkan Harga & Terima
                                    </button>
                                </div>
                                <input
                                    type="text"
                                    name="catatan_driver"
                                    placeholder="Catatan ke pelanggan (opsional)"
                                    style="padding: 7px 10px; font-size: 12px; border: 1px solid #cbd5e1; border-radius: 6px;"
                                >
                            </form>
                        </div>

                        <div class="driver-action-buttons">
                            <form method="POST" action="driver.php" style="width: 100%; display: flex;">
                                <input type="hidden" name="pesanan_id" value="<?= (int) $item["id"] ?>">
                                <input type="hidden" name="aksi_pesanan" value="tolak">
                                <button type="submit" class="btn-action btn-reject" onclick="return confirm('Tolak pesanan ini? Pelanggan akan diminta memilih driver lain.');">
                                    Tolak Pesanan
                                </button>
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="driver-action-buttons">
                            <form method="POST" action="driver.php" style="flex: 1; display: flex;">
                                <input type="hidden" name="pesanan_id" value="<?= (int) $item["id"] ?>">
                                <input type="hidden" name="aksi_pesanan" value="terima">
                                <button type="submit" class="btn-action btn-accept" onclick="return confirm('Terima pesanan ini?');">
                                    Terima Pesanan Ini
                                </button>
                            </form>

                            <form method="POST" action="driver.php" style="flex: 0.6; display: flex;">
                                <input type="hidden" name="pesanan_id" value="<?= (int) $item["id"] ?>">
                                <input type="hidden" name="aksi_pesanan" value="tolak">
                                <button type="submit" class="btn-action btn-reject" onclick="return confirm('Tolak pesanan ini? Pelanggan akan diminta memilih driver lain.');">
                                    Tolak
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- ========================================================
         TAB 2: PESANAN SEDANG BERJALAN
         ======================================================== -->
    <div id="panel-running" class="tab-panel <?= $activeTab === 'running' ? 'active' : '' ?>">
        <?php if (empty($pesananBerjalan)): ?>
            <div class="empty-placeholder">
                <strong>Tidak ada pesanan yang sedang berjalan.</strong>
                <p style="margin-top: 4px;">Pesanan yang telah kamu terima akan muncul di sini untuk proses pengantaran.</p>
            </div>
        <?php else: ?>
            <?php foreach ($pesananBerjalan as $item): ?>
                <?php
                    $isCashItem = ($item["metode_bayar"] ?? "qris") === "cash";
                    $jarakItem = isset($item["jarak_km"]) && $item["jarak_km"] !== null ? (float) $item["jarak_km"] : null;
                    $tarifItem = isset($item["total_tarif"]) && $item["total_tarif"] !== null && (int)$item["total_tarif"] > 0 ? (int) $item["total_tarif"] : null;
                    $hasCoords = !empty($item["pickup_lat"]) && !empty($item["dest_lat"]);

                    if ($tarifItem === null && $hasCoords && function_exists("hitungTarifOtomatis")) {
                        $calcTarif = hitungTarifOtomatis($pdo, $item["layanan"] ?? "Food", $item["pickup_lat"], $item["pickup_lng"], $item["dest_lat"], $item["dest_lng"]);
                        if (!empty($calcTarif["total_tarif"])) {
                            $tarifItem = (int) $calcTarif["total_tarif"];
                            if ($jarakItem === null) $jarakItem = (float) $calcTarif["jarak_km"];
                        }
                    }
                    if ($tarifItem === null && function_exists("isLayananTarifOtomatis") && isLayananTarifOtomatis($item["layanan"] ?? "")) {
                        $tarifItem = 5000;
                    }

                    $isQrisVerified = ((int)($item["qris_diverifikasi"] ?? 0) === 1);
                    $isQrisRejected = ((int)($item["qris_diverifikasi"] ?? 0) === 2);
                    $hasBuktiBayar = !empty($item["bukti_bayar"]) && file_exists(__DIR__ . "/uploads/bukti_bayar/" . $item["bukti_bayar"]);
                    $buktiBayarSrc = $hasBuktiBayar ? "uploads/bukti_bayar/" . htmlspecialchars($item["bukti_bayar"]) : "";
                ?>
                <div class="order-card-driver" style="border-left: 5px solid #2563eb;">
                    <div class="order-card-top">
                        <span class="order-id-tag">#PESANAN-<?= (int) $item["id"] ?></span>
                        <div>
                            <?php if ($item["status"] === "Menunggu Pembayaran"): ?>
                                <span style="font-size: 12px; font-weight: 700; background: #eff6ff; color: #1d4ed8; padding: 4px 10px; border-radius: 8px; border: 1px solid #bfdbfe;">
                                    Menunggu Pembayaran QRIS Pelanggan
                                </span>
                            <?php elseif ($item["status"] === "Diproses"): ?>
                                <span style="font-size: 12px; font-weight: 700; background: #ecfdf5; color: #047857; padding: 4px 10px; border-radius: 8px; border: 1px solid #a7f3d0;">
                                    Siap / Sedang Dibelikan
                                </span>
                            <?php elseif ($item["status"] === "Dalam Perjalanan"): ?>
                                <span style="font-size: 12px; font-weight: 700; background: #fef08a; color: #854d0e; padding: 4px 10px; border-radius: 8px; border: 1px solid #fde047;">
                                    Sedang Dalam Perjalanan Antar
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="order-customer-box" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                        <div>
                            Pelanggan: <strong><?= htmlspecialchars($item["nama_pelanggan"]) ?></strong>
                            <?php if (!empty($item["wa_pelanggan"])): ?>
                                <?php
                                    $waClean = preg_replace('/[^0-9]/', '', $item['wa_pelanggan']);
                                    $waTarget = (substr($waClean, 0, 2) === '08') ? '62' . substr($waClean, 1) : $waClean;
                                ?>
                                &bull; <a href="https://wa.me/<?= htmlspecialchars($waTarget) ?>" target="_blank" style="color: #000000; font-weight: 700; text-decoration: underline;">WA: <?= htmlspecialchars($item["wa_pelanggan"]) ?></a>
                            <?php elseif (!empty($item["email_pelanggan"])): ?>
                                &bull; <?= htmlspecialchars($item["email_pelanggan"]) ?>
                            <?php endif; ?>
                        </div>
                        <a href="chat.php?pesanan_id=<?= (int)$item['id'] ?>" style="display: inline-flex; align-items: center; gap: 5px; background: #ffff00; color: #000000; font-weight: 800; font-size: 12px; padding: 6px 14px; border-radius: 20px; text-decoration: none; border: 1.5px solid #000000; box-shadow: 0 2px 6px rgba(0,0,0,0.08);">
                            Chat Pelanggan
                        </a>
                    </div>

                    <div class="route-info-box">
                        <div class="route-point">
                            <div><b>Rute Pengantaran:</b> <?= nl2br(htmlspecialchars(($item["alamat"] ?? '-') !== '-' ? ($item["alamat"] ?? '-') : $item["layanan"])) ?></div>
                        </div>
                    </div>

                    <div class="fare-badge-bar" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; background: #f0fdf4; border: 1.5px solid #86efac; border-radius: 12px; padding: 12px 16px; margin-bottom: 12px;">
                        <div>
                            <div style="font-size: 11px; color: #166534; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px;">
                                Layanan: <span style="color: #0f172a; font-weight: 900;"><?= htmlspecialchars($item["layanan"]) ?></span>
                            </div>
                            <div style="font-size: 12px; color: #047857; margin-top: 2px; font-weight: 600;">
                                <?php if ($jarakItem !== null && $jarakItem > 0): ?>
                                    Jarak Tempuh: <strong>~<?= number_format($jarakItem, 2, ',', '.') ?> km</strong>
                                <?php else: ?>
                                    Lokasi Sekitar Polindra
                                <?php endif; ?>
                            </div>
                        </div>

                        <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                            <span style="font-size: 11px; font-weight: 800; padding: 5px 10px; border-radius: 8px; <?= $isCashItem ? 'background:#fef9c3; color:#92400e; border:1px solid #fef08a;' : 'background:#dcfce7; color:#15803d; border:1px solid #bbf7d0;' ?>">
                                <?= $isCashItem ? 'Pembayaran Tunai (Cash)' : 'QRIS (Masuk Dompet)' ?>
                            </span>

                            <div style="text-align: right;">
                                <div style="font-size: 11px; color: #166534; font-weight: 800; text-transform: uppercase;">Total Ongkir:</div>
                                <div class="fare-amount" style="font-size: 20px; font-weight: 900; color: #15803d; line-height: 1.1;">
                                    <?= ($tarifItem !== null && $tarifItem > 0) ? 'Rp ' . number_format($tarifItem, 0, ',', '.') : 'Nego via Chat' ?>
                                </div>
                            </div>

                            <?php if (!empty($item['dest_lat'])): ?>
                                <a href="https://www.google.com/maps/dir/?api=1&origin=<?= $item['pickup_lat'] ?>,<?= $item['pickup_lng'] ?>&destination=<?= $item['dest_lat'] ?>,<?= $item['dest_lng'] ?>" target="_blank" rel="noopener noreferrer" class="btn-route-maps" onclick="return bukaRuteDriver(event, '<?= $item['dest_lat'] ?>', '<?= $item['dest_lng'] ?>', '<?= $item['pickup_lat'] ?>', '<?= $item['pickup_lng'] ?>');">
                                    Navigasi Google Maps &rarr;
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="catatan-driver-box">
                        <strong>Catatan Belanja:</strong><br>
                        <?= nl2br(htmlspecialchars($item["catatan"] ?? "-")) ?>
                    </div>

                    <!-- VERIFIKASI QRIS JIKA METODE BUKAN CASH -->
                    <?php if (!$isCashItem): ?>
                        <div class="qris-verify-box">
                            <div class="qris-verify-header">
                                <span>Verifikasi QRIS Pelanggan:</span>
                                <?php if ($isQrisVerified): ?>
                                    <span style="color: #15803d;">Pembayaran Sah & Diverifikasi</span>
                                <?php elseif ($isQrisRejected && !$hasBuktiBayar): ?>
                                    <span style="color: #b91c1c;">Pembayaran Ditolak (Menunggu Bukti Baru)</span>
                                <?php elseif ($isQrisRejected): ?>
                                    <span style="color: #b91c1c;">Pembayaran Ditolak</span>
                                <?php elseif ($hasBuktiBayar): ?>
                                    <span style="color: #2563eb;">Ada Bukti Transfer (Cek di Bawah)</span>
                                <?php else: ?>
                                    <span style="color: #92400e;">Menunggu Pelanggan Unggah Bukti</span>
                                <?php endif; ?>
                            </div>

                            <?php if ($hasBuktiBayar): ?>
                                <div class="qris-preview-row">
                                    <img src="<?= $buktiBayarSrc ?>" class="qris-thumb-img" alt="Bukti Transfer" onclick="openBuktiModal('<?= $buktiBayarSrc ?>')">
                                    <div>
                                        <div style="font-size: 12px; font-weight: 700; color: #0f172a;">Foto Struk Transfer Pelanggan</div>
                                        <button type="button" style="background: none; border: none; padding: 0; color: #2563eb; font-size: 12px; font-weight: bold; cursor: pointer; text-decoration: underline;" onclick="openBuktiModal('<?= $buktiBayarSrc ?>')">
                                            Klik untuk perbesar struk
                                        </button>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (!$isQrisVerified && $hasBuktiBayar): ?>
                                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                    <form method="POST" action="driver.php" style="margin:0; flex:1;">
                                        <input type="hidden" name="pesanan_id" value="<?= (int) $item["id"] ?>">
                                        <input type="hidden" name="aksi_pesanan" value="konfirmasi_qris">
                                        <button type="submit" class="btn-action btn-accept" style="width: 100%; padding: 8px 12px; font-size: 12px;" onclick="return confirm('Sudah memastikan uang masuk ke QRIS Anda?');">
                                            Konfirmasi Uang Masuk (Sah)
                                        </button>
                                    </form>

                                    <button type="button" class="btn-action btn-reject" style="flex: 0.6; padding: 8px 12px; font-size: 12px;" onclick="toggleTolakBox('tolak_box_<?= (int) $item['id'] ?>')">
                                        Tolak Bayar
                                    </button>
                                </div>

                                <div id="tolak_box_<?= (int) $item['id'] ?>" style="display: none; margin-top: 10px; padding: 12px; background: #fff; border: 1px solid #cbd5e1; border-radius: 10px;">
                                    <form method="POST" action="driver.php">
                                        <input type="hidden" name="pesanan_id" value="<?= (int) $item["id"] ?>">
                                        <input type="hidden" name="aksi_pesanan" value="tolak_qris">
                                        <label style="font-size: 12px; font-weight: bold; display: block; margin-bottom: 4px;">Pilih Alasan Penolakan:</label>
                                        <select name="alasan_pilihan" style="width: 100%; padding: 8px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 12px; margin-bottom: 6px;">
                                            <option value="Saldo QRIS belum masuk ke mutasi rekening/e-wallet">Saldo belum masuk ke mutasi</option>
                                            <option value="Nominal transfer kurang dari tarif pesanan">Nominal transfer kurang</option>
                                            <option value="Bukti struk tidak jelas / tidak valid">Bukti struk tidak jelas / tidak valid</option>
                                            <option value="Lainnya">Alasan lainnya</option>
                                        </select>
                                        <input type="text" name="alasan_kustom" placeholder="Catatan tambahan untuk pelanggan (opsional)..." style="width: 100%; padding: 8px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 12px; margin-bottom: 8px; box-sizing: border-box;">
                                        <div style="display: flex; gap: 6px; justify-content: flex-end;">
                                            <button type="button" style="padding: 6px 12px; font-size: 12px; background: #f1f5f9; border-radius: 6px; border: none; cursor: pointer;" onclick="toggleTolakBox('tolak_box_<?= (int) $item['id'] ?>')">Batal</button>
                                            <button type="submit" style="padding: 6px 12px; font-size: 12px; background: #ef4444; color: #fff; border-radius: 6px; border: none; font-weight: bold; cursor: pointer;">Kirim Penolakan</button>
                                        </div>
                                    </form>
                                </div>
                            <?php elseif (!$isQrisVerified): ?>
                                <div style="font-size: 12px; color: #92400e; background: #fffbeb; border: 1px solid #fde68a; padding: 8px 12px; border-radius: 8px;">
                                    Tombol verifikasi akan muncul setelah pelanggan mengunggah bukti transfer.
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <!-- STEP AKSI BERIKUTNYA -->
                    <div class="driver-action-buttons">
                        <?php if ($item["status"] === "Menunggu Pembayaran"): ?>
                            <span style="font-size: 13px; color: #2563eb; display: flex; align-items: center; gap: 6px;">
                                ℹ️ Menunggu pelanggan mentransfer ke QRIS sebelum proses antar.
                            </span>
                        <?php elseif ($item["status"] === "Diproses"): ?>
                            <form method="POST" action="driver.php" style="width: 100%; display: flex;">
                                <input type="hidden" name="pesanan_id" value="<?= (int) $item["id"] ?>">
                                <input type="hidden" name="aksi_pesanan" value="antar">
                                <button type="submit" class="btn-action btn-antar-proses" style="width: 100%;">
                                    Mulai Antar Sekarang (Dalam Perjalanan) &rarr;
                                </button>
                            </form>
                        <?php elseif ($item["status"] === "Dalam Perjalanan"): ?>
                            <form method="POST" action="driver.php" enctype="multipart/form-data" style="width: 100%; background:#f8fafc; border:1.5px solid #cbd5e1; border-radius:12px; padding:12px;">
                                <input type="hidden" name="pesanan_id" value="<?= (int) $item["id"] ?>">
                                <input type="hidden" name="aksi_pesanan" value="selesai">
                                <label style="display:block; font-size:12px; font-weight:800; color:#0f172a; margin-bottom:6px;">
                                    Foto Bukti Pengantaran (Wajib Semua Layanan) *
                                </label>
                                <input
                                    type="file"
                                    name="bukti_antar"
                                    accept="image/jpeg,image/png,image/webp"
                                    required
                                    style="display:block; width:100%; margin-bottom:10px; font-size:12px; background:#fff; padding:8px; border:1px solid #cbd5e1; border-radius:8px; box-sizing:border-box;"
                                >
                                <button type="submit" class="btn-action btn-selesai-order" style="width: 100%;" onclick="return confirm('Pastikan foto bukti pengantaran sudah sesuai dan pesanan diterima pelanggan!');">
                                    Selesaikan Pesanan & Unggah Bukti
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>

                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- ========================================================
         TAB 3: RIWAYAT PESANAN SELESAI
         ======================================================== -->
    <div id="panel-completed" class="tab-panel <?= $activeTab === 'completed' ? 'active' : '' ?>">
        <?php if (empty($pesananSelesai)): ?>
            <div class="empty-placeholder">
                <strong>Belum ada riwayat pesanan yang selesai.</strong>
            </div>
        <?php else: ?>
            <?php foreach ($pesananSelesai as $item): ?>
                <?php
                    $tarifItem = isset($item["total_tarif"]) && $item["total_tarif"] !== null ? (int) $item["total_tarif"] : 0;
                    $selesaiHasBuktiBayar = !empty($item["bukti_bayar"]) && file_exists(__DIR__ . "/uploads/bukti_bayar/" . $item["bukti_bayar"]);
                    $selesaiHasBuktiAntar = !empty($item["bukti_antar"]) && file_exists(__DIR__ . "/uploads/bukti_antar/" . $item["bukti_antar"]);
                ?>
                <div class="order-card-driver" style="opacity: 0.95;">
                    <div class="order-card-top">
                        <span class="order-id-tag">#PESANAN-<?= (int) $item["id"] ?> &bull; <?= htmlspecialchars($item["nama_pelanggan"]) ?></span>
                        <span style="font-size: 11px; font-weight: 800; background: #dcfce7; color: #15803d; padding: 4px 10px; border-radius: 8px;">
                            <?= htmlspecialchars($item["status"]) ?>
                        </span>
                    </div>

                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; font-size: 13px;">
                        <div>
                            <b>Layanan:</b> <?= htmlspecialchars($item["layanan"]) ?><br>
                            <small style="color: #64748b;"><?= htmlspecialchars($item["created_at"]) ?></small>
                        </div>
                        <div style="text-align: right;">
                            <div style="font-size: 11px; color: #64748b;">Pendapatan:</div>
                            <strong style="color: #15803d; font-size: 16px;"><?= $tarifItem ? 'Rp ' . number_format($tarifItem, 0, ',', '.') : 'Rp 0' ?></strong>
                        </div>
                    </div>

                    <?php if ($selesaiHasBuktiBayar || $selesaiHasBuktiAntar): ?>
                        <div style="margin-top: 10px; padding-top: 8px; border-top: 1px solid #f1f5f9; display:flex; gap:8px; flex-wrap:wrap;">
                            <?php if ($selesaiHasBuktiBayar): ?>
                                <button type="button" style="background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; padding: 5px 10px; border-radius: 6px; font-size: 11px; font-weight: bold; cursor: pointer;" onclick="openBuktiModal('uploads/bukti_bayar/<?= htmlspecialchars($item['bukti_bayar']) ?>')">
                                    Lihat Bukti Transfer
                                </button>
                            <?php endif; ?>
                            <?php if ($selesaiHasBuktiAntar): ?>
                                <button type="button" style="background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; padding: 5px 10px; border-radius: 6px; font-size: 11px; font-weight: bold; cursor: pointer;" onclick="openBuktiModal('uploads/bukti_antar/<?= htmlspecialchars($item['bukti_antar']) ?>')">
                                    Lihat Bukti Pengantaran
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- ========================================================
         TAB 4: DOMPET & PENARIKAN SALDO DRIVER
         ======================================================== -->
    <div id="panel-wallet" class="tab-panel <?= $activeTab === 'wallet' ? 'active' : '' ?>">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; margin-bottom: 20px;">
            <div style="background: #ffffff; border-radius: 16px; padding: 20px; border: 1.5px solid #bbf7d0; box-shadow: 0 4px 15px rgba(16, 185, 129, 0.08);">
                <div style="font-size: 11px; font-weight: 800; color: #047857; text-transform: uppercase; letter-spacing: 0.5px;">Saldo Dompet Saat Ini</div>
                <div style="font-size: 28px; font-weight: 900; color: #0f172a; margin-top: 6px;">Rp <?= number_format($saldoDriver, 0, ',', '.') ?></div>
                <div style="font-size: 12px; color: #10b981; font-weight: 700; margin-top: 4px;">Siap ditarik ke rekening / e-wallet</div>
            </div>

            <div style="background: #ffffff; border-radius: 16px; padding: 20px; border: 1.5px solid #e2e8f0; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03);">
                <div style="font-size: 11px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Total Dana Telah Ditarik</div>
                <div style="font-size: 24px; font-weight: 900; color: #0f172a; margin-top: 6px;">Rp <?= number_format($totalDitarik, 0, ',', '.') ?></div>
                <div style="font-size: 12px; color: #64748b; margin-top: 4px;">Berhasil ditransfer oleh Admin</div>
            </div>

            <div style="background: #ffffff; border-radius: 16px; padding: 20px; border: 1.5px solid #e2e8f0; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03);">
                <div style="font-size: 11px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Akumulasi Order Selesai</div>
                <div style="font-size: 24px; font-weight: 900; color: #0f172a; margin-top: 6px;">Rp <?= number_format($totalPendapatan, 0, ',', '.') ?></div>
                <div style="font-size: 12px; color: #64748b; margin-top: 4px;">Dari <?= count($pesananSelesai) ?> pesanan terselesaikan</div>
            </div>
        </div>

        <div style="background: #ffffff; border-radius: 18px; padding: 24px; margin-bottom: 24px; box-shadow: 0 6px 20px rgba(0,0,0,0.04); border: 1px solid #e2e8f0;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
                <h3 style="margin: 0; font-size: 17px; font-weight: 900; color: #0f172a;">Ajukan Penarikan Dana (Withdraw)</h3>
                <span style="font-size: 12px; background: #fef3c7; color: #92400e; padding: 4px 10px; border-radius: 8px; font-weight: 700;">Minimal Penarikan: Rp 10.000</span>
            </div>
            <p style="font-size: 13px; color: #64748b; margin: 0 0 18px; line-height: 1.5;">
                Hasil pesanan pelanggan via <strong>QRIS Admin</strong> otomatis terakumulasi ke saldo dompet ini. Admin akan mentransfer dana dalam 1x24 jam.
            </p>

            <form method="POST" action="driver.php" id="formTarikSaldo">
                <input type="hidden" name="tarik_saldo" value="1">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; margin-bottom: 16px;">
                    <div>
                        <label style="display: block; font-size: 12px; font-weight: 800; color: #334155; margin-bottom: 6px;">NOMINAL PENARIKAN (RP)</label>
                        <input
                            type="number"
                            name="nominal"
                            id="inputNominalTarik"
                            placeholder="Contoh: 50000"
                            min="10000"
                            max="<?= $saldoDriver ?>"
                            style="width: 100%; padding: 11px 14px; border: 1.5px solid #cbd5e1; border-radius: 10px; font-size: 15px; font-weight: 700; box-sizing: border-box;"
                            required
                        >
                        <div style="display: flex; gap: 6px; margin-top: 8px; flex-wrap: wrap;">
                            <button type="button" onclick="setNominalTarik(10000)" style="background: #f1f5f9; border: 1px solid #cbd5e1; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: 700; cursor: pointer;">10rb</button>
                            <button type="button" onclick="setNominalTarik(25000)" style="background: #f1f5f9; border: 1px solid #cbd5e1; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: 700; cursor: pointer;">25rb</button>
                            <button type="button" onclick="setNominalTarik(50000)" style="background: #f1f5f9; border: 1px solid #cbd5e1; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: 700; cursor: pointer;">50rb</button>
                            <button type="button" onclick="setNominalTarik(100000)" style="background: #f1f5f9; border: 1px solid #cbd5e1; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: 700; cursor: pointer;">100rb</button>
                            <?php if ($saldoDriver >= 10000): ?>
                                <button type="button" onclick="setNominalTarik(<?= $saldoDriver ?>)" style="background: #e0f2fe; border: 1px solid #bae6fd; color: #0284c7; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: 800; cursor: pointer;">Tarik Semua (Rp <?= number_format($saldoDriver, 0, ',', '.') ?>)</button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div>
                        <label style="display: block; font-size: 12px; font-weight: 800; color: #334155; margin-bottom: 6px;">BANK / DOMPET DIGITAL (E-WALLET)</label>
                        <select
                            name="bank_tujuan"
                            style="width: 100%; padding: 11px 14px; border: 1.5px solid #cbd5e1; border-radius: 10px; font-size: 14px; background: #fff; font-weight: 600; box-sizing: border-box;"
                            required
                        >
                            <?php
                            $listBank = ['DANA', 'GoPay', 'OVO', 'ShopeePay', 'BCA', 'BRI', 'Mandiri', 'BNI', 'Bank BJB', 'Bank Syariah Indonesia (BSI)'];
                            foreach ($listBank as $bk):
                            ?>
                                <option value="<?= htmlspecialchars($bk) ?>" <?= $bankDriver === $bk ? 'selected' : '' ?>><?= htmlspecialchars($bk) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label style="display: block; font-size: 12px; font-weight: 800; color: #334155; margin-bottom: 6px;">NOMOR REKENING / NO. E-WALLET</label>
                        <input
                            type="text"
                            name="nomor_rekening"
                            placeholder="Contoh: 08123456789 atau 1234567890"
                            value="<?= htmlspecialchars($rekDriver) ?>"
                            style="width: 100%; padding: 11px 14px; border: 1.5px solid #cbd5e1; border-radius: 10px; font-size: 14px; box-sizing: border-box;"
                            required
                        >
                    </div>

                    <div>
                        <label style="display: block; font-size: 12px; font-weight: 800; color: #334155; margin-bottom: 6px;">ATAS NAMA PEMILIK REKENING</label>
                        <input
                            type="text"
                            name="atas_nama"
                            placeholder="Contoh: Budi Santoso"
                            value="<?= htmlspecialchars($anDriver ?: $namaDriver) ?>"
                            style="width: 100%; padding: 11px 14px; border: 1.5px solid #cbd5e1; border-radius: 10px; font-size: 14px; box-sizing: border-box;"
                            required
                        >
                    </div>
                </div>

                <div style="display: flex; justify-content: flex-end; align-items: center; gap: 12px; border-top: 1px solid #f1f5f9; padding-top: 16px; flex-wrap: wrap;">
                    <span style="font-size: 12px; color: #64748b;">Pastikan nomor rekening & atas nama sudah sesuai.</span>
                    <button
                        type="submit"
                        style="background: #10b981; color: #ffffff; border: none; padding: 12px 24px; border-radius: 10px; font-weight: 800; font-size: 14px; cursor: pointer;"
                        <?= $saldoDriver < 10000 ? 'disabled' : '' ?>
                    >
                        Ajukan Penarikan Dana
                    </button>
                </div>
            </form>
        </div>

        <div style="background: #ffffff; border-radius: 18px; padding: 22px; margin-bottom: 24px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #e2e8f0;">
            <h4 style="margin: 0 0 14px; font-size: 15px; font-weight: 800; color: #0f172a;">Riwayat Pengajuan Penarikan Saldo</h4>
            <?php if (empty($riwayatPenarikan)): ?>
                <div style="text-align: center; padding: 25px; color: #94a3b8; font-size: 13px;">
                    Belum ada riwayat pengajuan penarikan dana.
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: left;">
                        <thead>
                            <tr style="border-bottom: 2px solid #e2e8f0; color: #64748b; font-size: 11px; text-transform: uppercase;">
                                <th style="padding: 10px;">Tanggal</th>
                                <th style="padding: 10px;">Nominal</th>
                                <th style="padding: 10px;">Tujuan Rekening</th>
                                <th style="padding: 10px;">Status</th>
                                <th style="padding: 10px;">Keterangan Admin</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($riwayatPenarikan as $pnr): ?>
                                <tr style="border-bottom: 1px solid #f1f5f9;">
                                    <td style="padding: 10px; color: #475569; white-space: nowrap;"><?= date('d M Y, H:i', strtotime($pnr['created_at'])) ?></td>
                                    <td style="padding: 10px; font-weight: 800; color: #0f172a; white-space: nowrap;">Rp <?= number_format($pnr['nominal'], 0, ',', '.') ?></td>
                                    <td style="padding: 10px;">
                                        <strong><?= htmlspecialchars($pnr['bank_tujuan'] ?? '') ?></strong> - <?= htmlspecialchars($pnr['nomor_rekening'] ?? '') ?><br>
                                        <small style="color: #64748b;">a.n <?= htmlspecialchars($pnr['atas_nama'] ?? '') ?></small>
                                    </td>
                                    <td style="padding: 10px; white-space: nowrap;">
                                        <?php if ($pnr['status'] === 'Menunggu'): ?>
                                            <span style="background: #fef3c7; color: #d97706; padding: 3px 8px; border-radius: 6px; font-weight: 700; font-size: 11px;">Menunggu Transfer</span>
                                        <?php elseif ($pnr['status'] === 'Diproses'): ?>
                                            <span style="background: #eff6ff; color: #2563eb; padding: 3px 8px; border-radius: 6px; font-weight: 700; font-size: 11px;">🔄 Sedang Diproses</span>
                                        <?php elseif ($pnr['status'] === 'Selesai'): ?>
                                            <span style="background: #dcfce7; color: #15803d; padding: 3px 8px; border-radius: 6px; font-weight: 700; font-size: 11px;">✅ Ditransfer</span>
                                        <?php else: ?>
                                            <span style="background: #fee2e2; color: #b91c1c; padding: 3px 8px; border-radius: 6px; font-weight: 700; font-size: 11px;">Ditolak</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 10px; color: #64748b; font-size: 12px;">
                                        <?= htmlspecialchars($pnr['catatan_admin'] ?? '-') ?>
                                        <?php if (!empty($pnr['bukti_transfer'])): ?>
                                            <div style="margin-top: 4px;">
                                                <button type="button" onclick="openBuktiModal('uploads/bukti_transfer/<?= htmlspecialchars($pnr['bukti_transfer']) ?>')" style="background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; cursor: pointer;">
                                                    Bukti Transfer
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div style="background: #ffffff; border-radius: 18px; padding: 22px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #e2e8f0;">
            <h4 style="margin: 0 0 14px; font-size: 15px; font-weight: 800; color: #0f172a;">Riwayat Mutasi Saldo Dompet</h4>
            <?php if (empty($riwayatMutasi)): ?>
                <div style="text-align: center; padding: 25px; color: #94a3b8; font-size: 13px;">
                    Belum ada catatan mutasi transaksi saldo.
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: left;">
                        <thead>
                            <tr style="border-bottom: 2px solid #e2e8f0; color: #64748b; font-size: 11px; text-transform: uppercase;">
                                <th style="padding: 10px;">Waktu</th>
                                <th style="padding: 10px;">Tipe</th>
                                <th style="padding: 10px;">Nominal</th>
                                <th style="padding: 10px;">Saldo Akhir</th>
                                <th style="padding: 10px;">Keterangan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($riwayatMutasi as $mut): ?>
                                <tr style="border-bottom: 1px solid #f1f5f9;">
                                    <td style="padding: 10px; color: #475569; font-size: 12px; white-space: nowrap;"><?= date('d M Y, H:i', strtotime($mut['created_at'])) ?></td>
                                    <td style="padding: 10px; white-space: nowrap;">
                                        <?php if ($mut['tipe'] === 'masuk'): ?>
                                            <span style="color: #15803d; font-weight: 800; background: #dcfce7; padding: 2px 6px; border-radius: 4px; font-size: 11px;">+ MASUK</span>
                                        <?php else: ?>
                                            <span style="color: #b91c1c; font-weight: 800; background: #fee2e2; padding: 2px 6px; border-radius: 4px; font-size: 11px;">- KELUAR</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 10px; font-weight: 800; white-space: nowrap; <?= $mut['tipe'] === 'masuk' ? 'color: #15803d;' : 'color: #b91c1c;' ?>">
                                        <?= $mut['tipe'] === 'masuk' ? '+' : '-' ?> Rp <?= number_format($mut['nominal'], 0, ',', '.') ?>
                                    </td>
                                    <td style="padding: 10px; color: #0f172a; font-weight: 600; white-space: nowrap;">Rp <?= number_format($mut['saldo_akhir'], 0, ',', '.') ?></td>
                                    <td style="padding: 10px; color: #475569; font-size: 12px;"><?= htmlspecialchars($mut['keterangan'] ?? '') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ========================================================
         TAB 5: PENGATURAN QRIS & WHATSAPP
         ======================================================== -->
    <div id="panel-settings" class="tab-panel <?= $activeTab === 'settings' ? 'active' : '' ?>">
        <div class="settings-card">
            <h3>Nomor WhatsApp Komunikasi Pelanggan</h3>
            <p>Nomor ini akan otomatis menjadi tombol chat WhatsApp bagi pelanggan yang memesan layanan kepadamu.</p>

            <form method="POST" action="driver.php" style="display: flex; gap: 10px; flex-wrap: wrap;">
                <input
                    type="text"
                    name="no_hp"
                    placeholder="Contoh: 081234567890"
                    value="<?= htmlspecialchars($noHpDriver) ?>"
                    style="flex: 1; min-width: 240px; padding: 11px 14px; border: 1.5px solid #cbd5e1; border-radius: 10px; font-size: 14px;"
                    required
                >
                <button type="submit" name="update_whatsapp" style="background: #25d366; color: #fff; border: none; padding: 11px 20px; border-radius: 10px; font-weight: 800; cursor: pointer; font-size: 13px;">
                    Simpan No. WhatsApp
                </button>
            </form>
        </div>

        <div class="settings-card">
            <h3>QRIS Pembayaran Pribadi Driver</h3>
            <p>Unggah foto QRIS kamu (DANA Bisnis, BCA, GoPay, ShopeePay, Mandiri, dll).</p>

            <div class="settings-qris-grid">
                <div class="qris-display-box">
                    <?php if (!empty($fotoQrisDriver) && file_exists(__DIR__ . '/uploads/qris/' . $fotoQrisDriver)): ?>
                        <img src="uploads/qris/<?= htmlspecialchars($fotoQrisDriver) ?>" alt="QRIS Pribadi">
                        <div style="font-size: 11px; font-weight: 800; color: #15803d; margin-top: 6px;">QRIS Terpasang</div>
                        <form method="POST" action="driver.php" style="margin-top: 6px;">
                            <input type="hidden" name="hapus_qris" value="1">
                            <button type="submit" style="background: #fee2e2; color: #ef4444; border: 1px solid #fecaca; font-size: 11px; padding: 4px 10px; border-radius: 6px; cursor: pointer; font-weight: bold;" onclick="return confirm('Hapus foto QRIS ini?');">
                                Hapus
                            </button>
                        </form>
                    <?php else: ?>
                        <div style="font-size: 12px; font-weight: 700; color: #64748b;">Belum Ada QRIS</div>
                        <small style="font-size: 11px; color: #94a3b8;">Unggah di sebelah kanan</small>
                    <?php endif; ?>
                </div>

                <div>
                    <form method="POST" action="driver.php" enctype="multipart/form-data">
                        <label style="font-size: 13px; font-weight: 700; display: block; margin-bottom: 6px;">
                            <?= (!empty($fotoQrisDriver) && file_exists(__DIR__ . '/uploads/qris/' . $fotoQrisDriver)) ? 'Ganti Foto QRIS Baru:' : 'Pilih Foto QRIS:' ?>
                        </label>
                        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                            <input
                                type="file"
                                name="foto_qris"
                                accept="image/png, image/jpeg, image/jpg, image/webp"
                                style="flex: 1; min-width: 220px; padding: 9px; border: 1.5px solid #cbd5e1; border-radius: 10px; font-size: 13px; background: #fff;"
                                required
                            >
                            <button type="submit" name="upload_qris" style="background: #10b981; color: #fff; border: none; padding: 10px 18px; border-radius: 10px; font-weight: 800; cursor: pointer; font-size: 13px;">
                                Simpan Foto QRIS
                            </button>
                        </div>
                        <small style="display: block; margin-top: 6px; color: #64748b; font-size: 12px;">Format JPG, PNG, WEBP. Maksimal 3 MB.</small>
                    </form>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- MODAL LIGHTBOX BUKTI -->
<div id="modalBukti" class="bukti-modal-backdrop" onclick="closeBuktiModal(event)">
    <div class="bukti-modal-content" onclick="event.stopPropagation()">
        <div class="bukti-modal-header">
            <h4 style="margin: 0; font-size: 15px; font-weight: 800;">Preview Bukti</h4>
            <button type="button" style="background: none; border: none; font-size: 20px; cursor: pointer;" onclick="closeBuktiModal()">&times;</button>
        </div>
        <div class="bukti-modal-body">
            <img id="imgBuktiModal" src="" alt="Preview Bukti">
        </div>
        <div class="bukti-modal-footer">
            <a id="linkBuktiDownload" href="" target="_blank" style="padding: 8px 14px; background: #2563eb; color: #fff; border-radius: 8px; font-size: 12px; font-weight: bold; text-decoration: none;">Buka Gambar Asli</a>
            <button type="button" style="padding: 8px 14px; background: #e2e8f0; border: none; border-radius: 8px; font-size: 12px; font-weight: bold; cursor: pointer;" onclick="closeBuktiModal()">Tutup</button>
        </div>
    </div>
</div>

<script>
function setNominalTarik(val) {
    const inp = document.getElementById('inputNominalTarik');
    if (inp) {
        inp.value = val;
        inp.focus();
    }
}

function switchTab(tabId) {
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.tab-panel').forEach(panel => panel.classList.remove('active'));

    const activeBtn = Array.from(document.querySelectorAll('.tab-btn'))
        .find(b => b.getAttribute('onclick') === `switchTab('${tabId}')`);
    if (activeBtn) activeBtn.classList.add('active');

    const activePanel = document.getElementById('panel-' + tabId);
    if (activePanel) activePanel.classList.add('active');

    // Pertahankan tab di URL tanpa membawa parameter notif lama
    const url = window.location.pathname + '?tab=' + encodeURIComponent(tabId);
    window.history.replaceState({}, '', url);
}

function toggleTolakBox(id) {
    const box = document.getElementById(id);
    if (box) {
        box.style.display = (box.style.display === 'none' || box.style.display === '') ? 'block' : 'none';
    }
}

function openBuktiModal(src) {
    const modal = document.getElementById('modalBukti');
    const img = document.getElementById('imgBuktiModal');
    const link = document.getElementById('linkBuktiDownload');
    if (modal && img) {
        img.src = src;
        if (link) link.href = src;
        modal.classList.add('active');
    }
}

function closeBuktiModal() {
    const modal = document.getElementById('modalBukti');
    if (modal) modal.classList.remove('active');
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeBuktiModal();
});

// Countdown 2 menit (120 detik) pada pesanan baru.
// Saat habis, muat ulang halaman: driver.php menjalankan auto-tolak di bagian atas.
let sudahRedirectTimeout = false;
setInterval(() => {
    let adaHabis = false;
    document.querySelectorAll('.countdown-3m').forEach(el => {
        let sec = parseInt(el.getAttribute('data-seconds') || '0', 10);
        if (sec > 0) {
            sec -= 1;
            el.setAttribute('data-seconds', sec);
            const mm = String(Math.floor(sec / 60)).padStart(2, '0');
            const ss = String(sec % 60).padStart(2, '0');
            const tv = el.querySelector('.timer-val');
            if (tv) tv.textContent = `${mm}:${ss}`;
            if (sec === 0) adaHabis = true;
        }
    });
    if (adaHabis && !sudahRedirectTimeout) {
        sudahRedirectTimeout = true;
        // Jeda 1,5 detik agar selisih jam browser dan server tidak mengakibatkan pesanan masih tampil
        setTimeout(() => {
            window.location.href = 'driver.php?notif=timeout_2_menit&tab=new';
        }, 1500);
    }
}, 1000);

// REALTIME POLLING ORDER BARU DRIVER
let currentNewOrdersCount = <?= count($pesananBaru) ?>;
const isDriverOnline = <?= $statusAktif === 1 ? 'true' : 'false' ?>;

if (isDriverOnline) {
    setInterval(() => {
        fetch('api/driver_action.php?action=get_orders')
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success' && data.counts) {
                    const newCount = data.counts.baru;
                    if (newCount > currentNewOrdersCount) {
                        showOrderToast('Pesanan Baru Masuk!', 'Ada ' + (newCount - currentNewOrdersCount) + ' pesanan baru menunggumu.');
                        setTimeout(() => {
                            window.location.reload();
                        }, 2500);
                    } else if (newCount !== currentNewOrdersCount) {
                        window.location.reload();
                    }
                    currentNewOrdersCount = newCount;
                }
            })
            .catch(() => {});
    }, 6000);
}

function showOrderToast(title, msg) {
    let toast = document.getElementById('driverLiveToast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'driverLiveToast';
        toast.style.cssText = `
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: #111827;
            color: #ffd21f;
            border: 2px solid #ffd21f;
            padding: 16px 20px;
            border-radius: 16px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.3);
            z-index: 999999;
            font-size: 14px;
            cursor: pointer;
        `;
        document.body.appendChild(toast);
    }
    toast.innerHTML = `<strong>${title}</strong><div style="color:#f1f5f9; font-size:12px; margin-top:4px;">${msg} (Klik untuk muat ulang)</div>`;
    toast.onclick = () => window.location.reload();
}

function bukaRuteDriver(e, destLat, destLng, fallbackLat, fallbackLng) {
    if (e) e.preventDefault();

    if (!destLat || !destLng) {
        alert('Koordinat tujuan pelanggan tidak ditemukan.');
        return false;
    }

    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            function(pos) {
                const myLat = pos.coords.latitude.toFixed(6);
                const myLng = pos.coords.longitude.toFixed(6);

                fetch('api/update_driver_location.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ lat: myLat, lng: myLng })
                }).catch(() => {});

                window.open(`https://www.google.com/maps/dir/?api=1&origin=${myLat},${myLng}&destination=${destLat},${destLng}`, '_blank');
            },
            function() {
                const originStr = (fallbackLat && fallbackLng) ? `origin=${fallbackLat},${fallbackLng}&` : '';
                window.open(`https://www.google.com/maps/dir/?api=1&${originStr}destination=${destLat},${destLng}`, '_blank');
            },
            { enableHighAccuracy: true, timeout: 4000 }
        );
    } else {
        const originStr = (fallbackLat && fallbackLng) ? `origin=${fallbackLat},${fallbackLng}&` : '';
        window.open(`https://www.google.com/maps/dir/?api=1&${originStr}destination=${destLat},${destLng}`, '_blank');
    }
    return false;
}

function kirimLokasiDriver(lat, lng) {
    fetch('api/update_driver_location.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ lat: lat, lng: lng })
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            const gpsEl = document.getElementById('driverGpsTeks');
            if (gpsEl) gpsEl.innerHTML = `GPS Terhubung: <strong>${lat}, ${lng}</strong>`;
        }
    })
    .catch(() => {});
}

function syncGpsDriverManual() {
    if (navigator.geolocation) {
        const gpsEl = document.getElementById('driverGpsTeks');
        if (gpsEl) gpsEl.innerText = 'Mencari sinyal GPS...';
        navigator.geolocation.getCurrentPosition(function(pos) {
            const lat = pos.coords.latitude.toFixed(6);
            const lng = pos.coords.longitude.toFixed(6);
            kirimLokasiDriver(lat, lng);
        }, function(err) {
            alert('Gagal mendeteksi GPS: ' + err.message + '. Pastikan izin akses lokasi aktif pada browser Anda.');
        }, { enableHighAccuracy: true, timeout: 8000 });
    }
}

const driverIsActive = <?= $statusAktif === 1 ? 'true' : 'false' ?>;
if (driverIsActive && navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(function(pos) {
        kirimLokasiDriver(pos.coords.latitude.toFixed(6), pos.coords.longitude.toFixed(6));
    }, function() {}, { enableHighAccuracy: true, timeout: 7000 });

    navigator.geolocation.watchPosition(function(pos) {
        kirimLokasiDriver(pos.coords.latitude.toFixed(6), pos.coords.longitude.toFixed(6));
    }, function() {}, { enableHighAccuracy: true, maximumAge: 10000, timeout: 15000 });

    setInterval(function() {
        navigator.geolocation.getCurrentPosition(function(pos) {
            kirimLokasiDriver(pos.coords.latitude.toFixed(6), pos.coords.longitude.toFixed(6));
        }, function() {}, { enableHighAccuracy: true });
    }, 15000);
}
</script>

</body>
</html>