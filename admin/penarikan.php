<?php
session_start();
require_once '../koneksi.php';
require_once '../includes/auth.php';
require_once '../includes/wallet_helper.php';

requireLogin();
requireRole('admin');

$activeAdminPage = 'penarikan';
$pesan = '';
$jenisPesan = '';

// =========================================================================
// 1. ACTION: KONFIRMASI TRANSFER DANA SELESAI
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'konfirmasi_transfer') {
    $id = (int) ($_POST['id'] ?? 0);
    $catatanAdmin = trim($_POST['catatan_admin'] ?? 'Transfer penarikan berhasil diproses.');

    if ($id <= 0) {
        $pesan = "ID penarikan tidak valid.";
        $jenisPesan = "error";
    } else {
        // Upload bukti transfer jika ada
        $buktiTransferName = null;
        if (isset($_FILES['bukti_transfer']) && $_FILES['bukti_transfer']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['bukti_transfer']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp']) && $_FILES['bukti_transfer']['size'] <= 4 * 1024 * 1024) {
                $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'bukti_transfer' . DIRECTORY_SEPARATOR;
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                $buktiTransferName = 'tf_' . $id . '_' . time() . '.' . $ext;
                move_uploaded_file($_FILES['bukti_transfer']['tmp_name'], $uploadDir . $buktiTransferName);
            }
        }

        try {
            if ($buktiTransferName) {
                $stmt = $pdo->prepare("
                    UPDATE penarikan_dana
                    SET status = 'Selesai', bukti_transfer = ?, catatan_admin = ?, updated_at = NOW()
                    WHERE id = ? AND status IN ('Menunggu', 'Diproses')
                ");
                $stmt->execute([$buktiTransferName, $catatanAdmin, $id]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE penarikan_dana
                    SET status = 'Selesai', catatan_admin = ?, updated_at = NOW()
                    WHERE id = ? AND status IN ('Menunggu', 'Diproses')
                ");
                $stmt->execute([$catatanAdmin, $id]);
            }

            header("Location: penarikan.php?notif=transfer_selesai");
            exit;
        } catch (PDOException $e) {
            $pesan = "Gagal memproses konfirmasi transfer: " . $e->getMessage();
            $jenisPesan = "error";
        }
    }
}

// =========================================================================
// 2. ACTION: TOLAK PENARIKAN (REFUND SALDO KE DRIVER)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'tolak_penarikan') {
    $id = (int) ($_POST['id'] ?? 0);
    $alasanTolak = trim($_POST['alasan_tolak'] ?? 'Data rekening tujuan tidak sesuai / tidak valid.');

    if ($id <= 0) {
        $pesan = "ID penarikan tidak valid.";
        $jenisPesan = "error";
    } else {
        try {
            $pdo->beginTransaction();

            // Ambil data penarikan
            $stmtCek = $pdo->prepare("SELECT * FROM penarikan_dana WHERE id = ? AND status IN ('Menunggu', 'Diproses')");
            $stmtCek->execute([$id]);
            $penarikan = $stmtCek->fetch(PDO::FETCH_ASSOC);

            if (!$penarikan) {
                throw new Exception("Pengajuan penarikan tidak ditemukan atau sudah diproses sebelumnya.");
            }

            $driverDbId = (int) $penarikan['driver_id'];
            $nominal    = (int) $penarikan['nominal'];

            // Kembalikan saldo driver
            $stmtDriver = $pdo->prepare("SELECT saldo FROM drivers WHERE id = ? FOR UPDATE");
            $stmtDriver->execute([$driverDbId]);
            $saldoSaatIni = (int) $stmtDriver->fetchColumn();
            $saldoAkhir   = $saldoSaatIni + $nominal;

            $stmtRefund = $pdo->prepare("UPDATE drivers SET saldo = saldo + ? WHERE id = ?");
            $stmtRefund->execute([$nominal, $driverDbId]);

            // Catat mutasi pengembalian saldo (refund)
            $keterangan = "Pengembalian dana penarikan #" . $id . " ditolak: " . $alasanTolak;
            $stmtMutasi = $pdo->prepare("
                INSERT INTO dompet_transaksi (driver_id, pesanan_id, tipe, nominal, saldo_akhir, keterangan)
                VALUES (?, NULL, 'masuk', ?, ?, ?)
            ");
            $stmtMutasi->execute([$driverDbId, $nominal, $saldoAkhir, $keterangan]);

            // Ubah status penarikan jadi Ditolak
            $stmtUp = $pdo->prepare("
                UPDATE penarikan_dana
                SET status = 'Ditolak', catatan_admin = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmtUp->execute([$alasanTolak, $id]);

            $pdo->commit();
            header("Location: penarikan.php?notif=penarikan_ditolak");
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $pesan = "Gagal menolak penarikan: " . $e->getMessage();
            $jenisPesan = "error";
        }
    }
}

// =========================================================================
// 3. STATISTIK KEUANGAN & PENARIKAN DANA
// =========================================================================
$totalMenungguTransfer = (int) $pdo->query("SELECT COUNT(*) FROM penarikan_dana WHERE status = 'Menunggu'")->fetchColumn();
$nominalMenungguTransfer = (int) $pdo->query("SELECT COALESCE(SUM(nominal), 0) FROM penarikan_dana WHERE status = 'Menunggu'")->fetchColumn();
$nominalBerhasilDitransfer = (int) $pdo->query("SELECT COALESCE(SUM(nominal), 0) FROM penarikan_dana WHERE status = 'Selesai'")->fetchColumn();
$totalSaldoDriverMengendap = (int) $pdo->query("SELECT COALESCE(SUM(saldo), 0) FROM drivers")->fetchColumn();

// Navbar counters
$totalDrivers    = (int) $pdo->query("SELECT COUNT(*) FROM drivers")->fetchColumn();
$totalPesananAll = (int) $pdo->query("SELECT COUNT(*) FROM pesanan")->fetchColumn();
$totalUsers      = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn();

// =========================================================================
// 4. FILTER & QUERY DATA PENARIKAN
// =========================================================================
$filterStatus = $_GET['status'] ?? 'all';
$searchQuery  = trim($_GET['q'] ?? '');

$sql = "
    SELECT
        p.*,
        d.nama_driver,
        d.saldo AS saldo_terkini_driver,
        d.no_hp AS no_hp_driver,
        u.email AS email_driver
    FROM penarikan_dana p
    JOIN drivers d ON p.driver_id = d.id
    JOIN users u ON d.user_id = u.id
    WHERE 1=1
";
$params = [];

if (in_array($filterStatus, ['Menunggu', 'Diproses', 'Selesai', 'Ditolak'])) {
    $sql .= " AND p.status = ?";
    $params[] = $filterStatus;
}

if ($searchQuery !== '') {
    $sql .= " AND (d.nama_driver LIKE ? OR p.bank_tujuan LIKE ? OR p.nomor_rekening LIKE ? OR p.atas_nama LIKE ?)";
    $like = '%' . $searchQuery . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= " ORDER BY (p.status = 'Menunggu') DESC, p.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$daftarPenarikan = $stmt->fetchAll(PDO::FETCH_ASSOC);

$notif = $_GET['notif'] ?? '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Penarikan Dana Driver - Polindra Suruh</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
</head>
<body class="page-admin">

<div class="dashboard-container">

    <!-- NAVBAR ADMIN -->
    <?php include '../includes/admin_navbar.php'; ?>

    <!-- ALERT NOTIFIKASI -->
    <?php if ($notif === 'transfer_selesai'): ?>
        <div class="alert alert-success"><strong>Transfer Berhasil Dikonfirmasi!</strong> Status penarikan telah diselesaikan dan bukti transfer tersimpan.</div>
    <?php elseif ($notif === 'penarikan_ditolak'): ?>
        <div class="alert alert-warning"><strong>Penarikan Ditolak & Saldo Direfund!</strong> Saldo telah otomatis dikembalikan ke dompet driver beserta alasannya.</div>
    <?php elseif ($pesan !== ''): ?>
        <div class="alert alert-<?= $jenisPesan === 'error' ? 'danger' : 'success' ?>">
            <?= htmlspecialchars($pesan) ?>
        </div>
    <?php endif; ?>

    <!-- STATISTIK KEUANGAN & PENARIKAN -->
    <div class="stats-grid">
        <div class="stat-card" style="border-left: 4px solid #f59e0b;">
            
            <div class="stat-content">
                <div class="stat-label">Menunggu Transfer</div>
                <div class="stat-value"><?= $totalMenungguTransfer ?> Pengajuan</div>
                <small style="color: #d97706; font-weight: 700;">Rp <?= number_format($nominalMenungguTransfer, 0, ',', '.') ?></small>
            </div>
        </div>

        <div class="stat-card" style="border-left: 4px solid #10b981;">
            
            <div class="stat-content">
                <div class="stat-label">Total Telah Ditransfer</div>
                <div class="stat-value" style="font-size: 18px;">Rp <?= number_format($nominalBerhasilDitransfer, 0, ',', '.') ?></div>
                <small style="color: #64748b;">Dana order QRIS tersalurkan</small>
            </div>
        </div>

        <div class="stat-card" style="border-left: 4px solid #2563eb;">
            
            <div class="stat-content">
                <div class="stat-label">Saldo Mengendap Driver</div>
                <div class="stat-value" style="font-size: 18px;">Rp <?= number_format($totalSaldoDriverMengendap, 0, ',', '.') ?></div>
                <small style="color: #64748b;">Total di seluruh dompet driver</small>
            </div>
        </div>

        <div class="stat-card" style="border-left: 4px solid #64748b;">
            
            <div class="stat-content">
                <div class="stat-label">Total Driver Mitra</div>
                <div class="stat-value"><?= $totalDrivers ?> Driver</div>
                <small style="color: #64748b;">Terdaftar di Polindra Suruh</small>
            </div>
        </div>
    </div>

    <!-- FILTER BAR -->
    <div class="filter-panel">
        <form method="GET" action="penarikan.php" class="filter-actions" style="margin: 0; width: 100%; justify-content: space-between;">
            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <input
                    type="text"
                    name="q"
                    placeholder="Cari driver, rekening, bank..."
                    value="<?= htmlspecialchars($searchQuery) ?>"
                    style="padding: 9px 14px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 13px; width: 240px;"
                >

                <select name="status" onchange="this.form.submit()" style="padding: 9px 14px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 13px; background: white; font-weight: 600;">
                    <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>Semua Status</option>
                    <option value="Menunggu" <?= $filterStatus === 'Menunggu' ? 'selected' : '' ?>>Menunggu Transfer</option>
                    <option value="Selesai" <?= $filterStatus === 'Selesai' ? 'selected' : '' ?>>Selesai (Ditransfer)</option>
                    <option value="Ditolak" <?= $filterStatus === 'Ditolak' ? 'selected' : '' ?>>Ditolak (Direfund)</option>
                </select>

                <button type="submit" class="btn btn-secondary" style="padding: 9px 16px; font-size: 13px;">Cari</button>

                <?php if ($searchQuery !== '' || $filterStatus !== 'all'): ?>
                    <a href="penarikan.php" style="font-size: 12px; color: #64748b; text-decoration: underline;">Reset Filter</a>
                <?php endif; ?>
            </div>

            <div style="font-size: 13px; color: #64748b;">
                Menampilkan <strong><?= count($daftarPenarikan) ?></strong> data pengajuan
            </div>
        </form>
    </div>

    <!-- TABEL DATA PENARIKAN DANA -->
    <div class="card">
        <div style="padding: 16px 20px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-size: 16px; font-weight: 800; color: #0f172a;">Daftar Pengajuan Penarikan Saldo Driver</h3>
            <span style="font-size: 12px; color: #64748b;">Hasil orderan via QRIS Pusat Polindra Suruh</span>
        </div>

        <?php if (empty($daftarPenarikan)): ?>
            <div style="text-align: center; padding: 48px 20px; color: #94a3b8;">
                <div style="font-size: 36px; margin-bottom: 8px;"></div>
                <div style="font-weight: 700; color: #475569; font-size: 15px;">Belum Ada Pengajuan Penarikan</div>
                <p style="font-size: 13px; margin-top: 4px;">Tidak ada riwayat penarikan dana sesuai filter yang dipilih.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID & TANGGAL</th>
                            <th>NAMA DRIVER</th>
                            <th>NOMINAL PENARIKAN</th>
                            <th>REKENING / E-WALLET TUJUAN</th>
                            <th>STATUS</th>
                            <th>BUKTI & CATATAN</th>
                            <th style="text-align: right;">AKSI ADMIN</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($daftarPenarikan as $row): ?>
                            <tr>
                                <td>
                                    <strong style="color: #0f172a;">#WD-<?= $row['id'] ?></strong><br>
                                    <small style="color: #64748b;"><?= date('d M Y, H:i', strtotime($row['created_at'])) ?></small>
                                </td>

                                <td>
                                    <strong><?= htmlspecialchars($row['nama_driver']) ?></strong><br>
                                    <small style="color: #64748b;">WA: <?= htmlspecialchars($row['no_hp_driver'] ?? '-') ?></small><br>
                                    <small style="color: #10b981; font-weight: 700;">Saldo Saat Ini: Rp <?= number_format($row['saldo_terkini_driver'], 0, ',', '.') ?></small>
                                </td>

                                <td>
                                    <span style="font-size: 16px; font-weight: 900; color: #0f172a;">
                                        Rp <?= number_format($row['nominal'], 0, ',', '.') ?>
                                    </span>
                                </td>

                                <td>
                                    <span style="display: inline-block; background: #f1f5f9; padding: 2px 8px; border-radius: 6px; font-weight: 800; font-size: 12px; color: #0f172a; margin-bottom: 4px;">
                                        <?= htmlspecialchars($row['bank_tujuan']) ?>
                                    </span><br>
                                    <strong style="font-size: 13px; letter-spacing: 0.5px;"><?= htmlspecialchars($row['nomor_rekening']) ?></strong><br>
                                    <small style="color: #475569;">a.n <b><?= htmlspecialchars($row['atas_nama']) ?></b></small>
                                </td>

                                <td>
                                    <?php if ($row['status'] === 'Menunggu'): ?>
                                        <span class="status-pill status-pill-menunggu">Menunggu Transfer</span>
                                    <?php elseif ($row['status'] === 'Selesai'): ?>
                                        <span class="status-pill status-pill-selesai">Berhasil Ditransfer</span>
                                    <?php else: ?>
                                        <span class="status-pill status-pill-ditolak">Ditolak (Direfund)</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if (!empty($row['catatan_admin'])): ?>
                                        <div style="font-size: 12px; color: #475569; max-width: 220px; margin-bottom: 4px;">
                                            <em>"<?= htmlspecialchars($row['catatan_admin']) ?>"</em>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: #94a3b8; font-size: 12px;">-</span>
                                    <?php endif; ?>

                                    <?php if (!empty($row['bukti_transfer']) && file_exists(__DIR__ . '/../uploads/bukti_transfer/' . $row['bukti_transfer'])): ?>
                                        <button
                                            type="button"
                                            onclick="openBuktiTransfer('../uploads/bukti_transfer/<?= htmlspecialchars($row['bukti_transfer']) ?>')"
                                            style="background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: 800; cursor: pointer;"
                                        >
                                            Bukti Transfer
                                        </button>
                                    <?php endif; ?>
                                </td>

                                <td style="text-align: right; white-space: nowrap;">
                                    <?php if ($row['status'] === 'Menunggu'): ?>
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-success"
                                            style="padding: 6px 12px; font-size: 12px; font-weight: 800;"
                                            onclick="bukaModalKonfirmasi(<?= $row['id'] ?>, '<?= htmlspecialchars(addslashes($row['nama_driver'])) ?>', <?= $row['nominal'] ?>, '<?= htmlspecialchars(addslashes($row['bank_tujuan'])) ?>', '<?= htmlspecialchars(addslashes($row['nomor_rekening'])) ?>', '<?= htmlspecialchars(addslashes($row['atas_nama'])) ?>')"
                                        >
                                            Konfirmasi Transfer
                                        </button>

                                        <button
                                            type="button"
                                            class="btn btn-sm btn-danger"
                                            style="padding: 6px 12px; font-size: 12px; font-weight: 800; margin-left: 4px;"
                                            onclick="bukaModalTolak(<?= $row['id'] ?>, '<?= htmlspecialchars(addslashes($row['nama_driver'])) ?>', <?= $row['nominal'] ?>)"
                                        >
                                            Tolak & Refund
                                        </button>
                                    <?php else: ?>
                                        <span style="color: #94a3b8; font-size: 12px;">Selesai diproses</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

</div>

<!-- =========================================================
     MODAL 1: KONFIRMASI TRANSFER DANA SELESAI
     ========================================================= -->
<div id="modalKonfirmasi" class="admin-modal-backdrop" onclick="tutupModal('modalKonfirmasi')">
    <div class="admin-modal-box" onclick="event.stopPropagation()">
        <div class="admin-modal-header">
            <h3>Konfirmasi Transfer Dana Driver</h3>
            <button type="button" class="admin-modal-close" onclick="tutupModal('modalKonfirmasi')">&times;</button>
        </div>

        <form method="POST" action="penarikan.php" enctype="multipart/form-data">
            <input type="hidden" name="action" value="konfirmasi_transfer">
            <input type="hidden" name="id" id="konfirmasiId" value="">

            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px; margin-bottom: 16px; font-size: 13px;">
                <div style="margin-bottom: 4px;">Driver: <strong id="konfirmasiNamaDriver">-</strong></div>
                <div style="margin-bottom: 4px;">Nominal Transfer: <strong style="color: #15803d; font-size: 16px;" id="konfirmasiNominal">-</strong></div>
                <div style="margin-bottom: 4px;">Bank / E-Wallet: <strong id="konfirmasiBank">-</strong></div>
                <div style="margin-bottom: 4px;">Nomor Rekening: <strong id="konfirmasiRekening">-</strong></div>
                <div>Atas Nama: <strong id="konfirmasiAtasNama">-</strong></div>
            </div>

            <div style="margin-bottom: 14px;">
                <label style="display: block; font-size: 12px; font-weight: 800; color: #334155; margin-bottom: 6px;">UNGGAH BUKTI TRANSFER (OPSIONAL)</label>
                <input
                    type="file"
                    name="bukti_transfer"
                    accept="image/png, image/jpeg, image/jpg, image/webp"
                    style="width: 100%; padding: 8px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 13px; box-sizing: border-box;"
                >
                <small style="color: #64748b; font-size: 11px; margin-top: 4px; display: block;">Format JPG, PNG, WEBP. Maks 4 MB.</small>
            </div>

            <div style="margin-bottom: 18px;">
                <label style="display: block; font-size: 12px; font-weight: 800; color: #334155; margin-bottom: 6px;">CATATAN ADMIN (OPSIONAL)</label>
                <input
                    type="text"
                    name="catatan_admin"
                    value="Transfer telah berhasil dikirim ke rekening/e-wallet Anda."
                    style="width: 100%; padding: 10px 12px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 13px; box-sizing: border-box;"
                >
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="tutupModal('modalKonfirmasi')">Batal</button>
                <button type="submit" class="btn btn-success" style="font-weight: 800;">Simpan & Tandai Selesai</button>
            </div>
        </form>
    </div>
</div>

<!-- =========================================================
     MODAL 2: TOLAK PENARIKAN (REFUND OTOMATIS)
     ========================================================= -->
<div id="modalTolak" class="admin-modal-backdrop" onclick="tutupModal('modalTolak')">
    <div class="admin-modal-box" onclick="event.stopPropagation()">
        <div class="admin-modal-header">
            <h3 style="color: #b91c1c;">Tolak Pengajuan Penarikan</h3>
            <button type="button" class="admin-modal-close" onclick="tutupModal('modalTolak')">&times;</button>
        </div>

        <form method="POST" action="penarikan.php">
            <input type="hidden" name="action" value="tolak_penarikan">
            <input type="hidden" name="id" id="tolakId" value="">

            <div style="background: #fff1f2; border: 1px solid #fecdd3; border-radius: 12px; padding: 14px; margin-bottom: 16px; font-size: 13px; color: #9f1239;">
                <div style="font-weight: 800; margin-bottom: 4px;">Saldo akan otomatis direfund ke dompet driver!</div>
                <div>Driver: <strong id="tolakNamaDriver">-</strong></div>
                <div>Nominal yang dikembalikan: <strong id="tolakNominal">-</strong></div>
            </div>

            <div style="margin-bottom: 18px;">
                <label style="display: block; font-size: 12px; font-weight: 800; color: #334155; margin-bottom: 6px;">ALASAN PENOLAKAN</label>
                <textarea
                    name="alasan_tolak"
                    rows="3"
                    style="width: 100%; padding: 10px 12px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 13px; box-sizing: border-box;"
                    placeholder="Contoh: Nomor rekening salah / tidak terdaftar atas nama tersebut. Silakan ajukan ulang dengan nomor rekening yang valid."
                    required
                ></textarea>
                <small style="color: #64748b; font-size: 11px; margin-top: 4px; display: block;">Alasan ini akan tampil di riwayat dompet driver.</small>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="tutupModal('modalTolak')">Batal</button>
                <button type="submit" class="btn btn-danger" style="font-weight: 800;" onclick="return confirm('Yakin ingin menolak pengajuan ini? Saldo akan direfund kembali ke driver.')">Tolak & Refund Saldo</button>
            </div>
        </form>
    </div>
</div>

<!-- =========================================================
     MODAL 3: LIGHTBOX PREVIEW BUKTI TRANSFER
     ========================================================= -->
<div id="modalBuktiTf" class="admin-modal-backdrop" onclick="tutupModal('modalBuktiTf')">
    <div class="admin-modal-box" style="max-width: 600px; text-align: center;" onclick="event.stopPropagation()">
        <div class="admin-modal-header">
            <h3>Bukti Transfer Admin ke Driver</h3>
            <button type="button" class="admin-modal-close" onclick="tutupModal('modalBuktiTf')">&times;</button>
        </div>
        <div style="margin-bottom: 16px;">
            <img id="imgBuktiTf" src="" alt="Bukti Transfer" style="max-width: 100%; max-height: 70vh; border-radius: 10px; border: 1px solid #e2e8f0;">
        </div>
        <div>
            <a id="linkBuktiTfDownload" href="" target="_blank" class="btn btn-primary btn-sm" style="margin-right: 6px;">Buka Gambar Penuh</a>
            <button type="button" class="btn btn-secondary btn-sm" onclick="tutupModal('modalBuktiTf')">Tutup</button>
        </div>
    </div>
</div>

<script>
function bukaModalKonfirmasi(id, nama, nominal, bank, rekening, atasNama) {
    document.getElementById('konfirmasiId').value = id;
    document.getElementById('konfirmasiNamaDriver').innerText = nama;
    document.getElementById('konfirmasiNominal').innerText = 'Rp ' + Number(nominal).toLocaleString('id-ID');
    document.getElementById('konfirmasiBank').innerText = bank;
    document.getElementById('konfirmasiRekening').innerText = rekening;
    document.getElementById('konfirmasiAtasNama').innerText = atasNama;
    document.getElementById('modalKonfirmasi').classList.add('active');
}

function bukaModalTolak(id, nama, nominal) {
    document.getElementById('tolakId').value = id;
    document.getElementById('tolakNamaDriver').innerText = nama;
    document.getElementById('tolakNominal').innerText = 'Rp ' + Number(nominal).toLocaleString('id-ID');
    document.getElementById('modalTolak').classList.add('active');
}

function openBuktiTransfer(src) {
    document.getElementById('imgBuktiTf').src = src;
    document.getElementById('linkBuktiTfDownload').href = src;
    document.getElementById('modalBuktiTf').classList.add('active');
}

function tutupModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove('active');
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        tutupModal('modalKonfirmasi');
        tutupModal('modalTolak');
        tutupModal('modalBuktiTf');
    }
});
</script>

</body>
</html>
