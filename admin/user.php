<?php
session_start();
require_once "../koneksi.php";

// Pastikan hanya admin yang dapat mengakses halaman ini
if (!isset($_SESSION["user_id"]) || ($_SESSION["role"] ?? "") !== "admin") {
    header("Location: ../login.php");
    exit;
}

$pesan = "";
$jenisPesan = "";

// Helper normalisasi nomor WA
function normalisasiNomorWa($nomor) {
    $clean = preg_replace('/[^0-9]/', '', $nomor);
    if (str_starts_with($clean, '628')) {
        return '08' . substr($clean, 3);
    } elseif (str_starts_with($clean, '8')) {
        return '08' . substr($clean, 1);
    }
    return $clean;
}

// =========================================================================
// 1. CREATE: PROSES TAMBAH PELANGGAN BARU
// =========================================================================
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["tambah_user"])) {
    $nama = trim($_POST["nama"] ?? "");
    $username = trim($_POST["username"] ?? "");
    $no_wa = normalisasiNomorWa(trim($_POST["no_wa"] ?? ""));
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";

    $emailVal = ($email !== "" && filter_var($email, FILTER_VALIDATE_EMAIL)) ? $email : null;

    if ($nama === "" || $username === "" || $no_wa === "" || strlen($password) < 6) {
        $pesan = "Nama, username, nomor WhatsApp wajib diisi dan password minimal 6 karakter.";
        $jenisPesan = "error";
    } elseif (!preg_match('/^08[1-9][0-9]{7,11}$/', $no_wa)) {
        $pesan = "Nomor WhatsApp tidak valid. Format: 08xxxxxxxxxx (10-14 digit).";
        $jenisPesan = "error";
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO users (nama, username, no_wa, email, password, role)
                VALUES (?, ?, ?, ?, ?, 'user')
            ");
            $stmt->execute([
                $nama,
                $username,
                $no_wa,
                $emailVal,
                password_hash($password, PASSWORD_DEFAULT)
            ]);

            header("Location: user.php?notif=tambah_sukses");
            exit;
        } catch (PDOException $e) {
            $pesan = ($e->getCode() === "23000") ? "Username, nomor WhatsApp, atau email sudah digunakan akun lain." : "Gagal menambahkan pelanggan: " . $e->getMessage();
            $jenisPesan = "error";
        }
    }
}

// =========================================================================
// 2. UPDATE: PROSES EDIT PELANGGAN & RESET PASSWORD
// =========================================================================
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["edit_user"])) {
    $targetUserId = (int) ($_POST["user_id"] ?? 0);
    $nama = trim($_POST["nama"] ?? "");
    $username = trim($_POST["username"] ?? "");
    $no_wa = normalisasiNomorWa(trim($_POST["no_wa"] ?? ""));
    $email = trim($_POST["email"] ?? "");
    $passwordBaru = $_POST["password_baru"] ?? "";

    $emailVal = ($email !== "" && filter_var($email, FILTER_VALIDATE_EMAIL)) ? $email : null;

    if ($targetUserId <= 0 || $nama === "" || $username === "" || $no_wa === "") {
        $pesan = "Data nama, username, atau nomor WhatsApp tidak boleh kosong.";
        $jenisPesan = "error";
    } elseif (!preg_match('/^08[1-9][0-9]{7,11}$/', $no_wa)) {
        $pesan = "Nomor WhatsApp tidak valid. Format: 08xxxxxxxxxx (10-14 digit).";
        $jenisPesan = "error";
    } else {
        try {
            if (!empty($passwordBaru)) {
                if (strlen($passwordBaru) < 6) {
                    $pesan = "Password baru minimal 6 karakter.";
                    $jenisPesan = "error";
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE users
                        SET nama = ?, username = ?, no_wa = ?, email = ?, password = ?
                        WHERE id = ? AND role = 'user'
                    ");
                    $stmt->execute([
                        $nama,
                        $username,
                        $no_wa,
                        $emailVal,
                        password_hash($passwordBaru, PASSWORD_DEFAULT),
                        $targetUserId
                    ]);
                    header("Location: user.php?notif=edit_sukses");
                    exit;
                }
            } else {
                $stmt = $pdo->prepare("
                    UPDATE users
                    SET nama = ?, username = ?, no_wa = ?, email = ?
                    WHERE id = ? AND role = 'user'
                ");
                $stmt->execute([$nama, $username, $no_wa, $emailVal, $targetUserId]);
                header("Location: user.php?notif=edit_sukses");
                exit;
            }
        } catch (PDOException $e) {
            $pesan = ($e->getCode() === "23000") ? "Username, nomor WhatsApp, atau email sudah digunakan akun lain." : "Gagal mengupdate pelanggan: " . $e->getMessage();
            $jenisPesan = "error";
        }
    }
}

// =========================================================================
// 3. DELETE: PROSES HAPUS PELANGGAN (DENGAN PEMBERSIHAN DATA TERKAIT)
// =========================================================================
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["hapus_user"])) {
    $targetUserId = (int) ($_POST["user_id"] ?? 0);

    if ($targetUserId > 0) {
        try {
            $pdo->beginTransaction();

            // 1. Ambil semua bukti pembayaran terkait pesanan user ini untuk dihapus dari disk
            $stmtBukti = $pdo->prepare("SELECT bukti_bayar FROM pesanan WHERE user_id = ? AND bukti_bayar IS NOT NULL");
            $stmtBukti->execute([$targetUserId]);
            $buktiList = $stmtBukti->fetchAll(PDO::FETCH_COLUMN);

            $buktiDir = __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "bukti_bayar" . DIRECTORY_SEPARATOR;
            foreach ($buktiList as $buktiFile) {
                if ($buktiFile && file_exists($buktiDir . $buktiFile)) {
                    @unlink($buktiDir . $buktiFile);
                }
            }

            // 2. Hapus pesanan milik pelanggan
            $pdo->prepare("DELETE FROM pesanan WHERE user_id = ?")->execute([$targetUserId]);

            // 3. Hapus akun dari tabel users
            $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'user'")->execute([$targetUserId]);

            $pdo->commit();
            header("Location: user.php?notif=hapus_sukses");
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $pesan = "Gagal menghapus pelanggan: " . $e->getMessage();
            $jenisPesan = "error";
        }
    }
}

// =========================================================================
// 4. READ: AMBIL DAFTAR PELANGGAN DENGAN STATISTIK PESANAN
// =========================================================================
$search = trim($_GET["search"] ?? "");
$sql = "
    SELECT 
        u.id,
        u.nama,
        u.username,
        u.no_wa,
        u.email,
        u.created_at,
        COUNT(p.id) AS total_pesanan,
        SUM(CASE WHEN p.status = 'Selesai' THEN 1 ELSE 0 END) AS pesanan_selesai,
        SUM(CASE WHEN p.status IN ('Menunggu', 'Menunggu Driver', 'Menunggu Persetujuan Driver', 'Menunggu Pembayaran', 'Diproses', 'Dalam Perjalanan') THEN 1 ELSE 0 END) AS pesanan_aktif
    FROM users u
    LEFT JOIN pesanan p ON p.user_id = u.id
    WHERE u.role = 'user'
";

$params = [];
if ($search !== "") {
    $sql .= " AND (u.nama LIKE :q OR u.username LIKE :q OR u.email LIKE :q OR u.no_wa LIKE :q) ";
    $params["q"] = "%" . $search . "%";
}

$sql .= " GROUP BY u.id ORDER BY u.id DESC";

$stmtUsers = $pdo->prepare($sql);
$stmtUsers->execute($params);
$users = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

// Metrik ringkasan
$totalUsers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn();
$totalPesananUsers = (int) $pdo->query("SELECT COUNT(*) FROM pesanan")->fetchColumn();
$totalUserAktif = (int) $pdo->query("SELECT COUNT(DISTINCT user_id) FROM pesanan")->fetchColumn();

$notif = $_GET["notif"] ?? "";
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Pelanggan - Admin Polindra Suruh</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
</head>
<body class="admin-body">

<!-- HEADER DASHBOARD ADMIN -->
<header class="admin-navbar">
    <div class="admin-brand">
        <a href="index.php">Polindra <span>Admin</span></a>
    </div>
    <nav class="admin-nav-links">
        <a href="index.php">Dashboard</a>
        <a href="pesanan.php">Pesanan</a>
        <a href="driver.php">Driver</a>
        <a href="user.php" class="active">Pelanggan</a>
        <a href="penarikan.php">Penarikan Dana</a>
        <a href="../logout.php" class="btn-logout">Logout</a>
    </nav>
</header>

<div class="admin-container">
    
    <div class="dash-title-wrap">
        <div>
            <h1 class="dash-page-title">Manajemen Akun Pelanggan</h1>
            <p class="dash-page-subtitle">Pantau akun terdaftar, nomor WhatsApp aktif, dan riwayat transaksi pemesanan mahasiswa.</p>
        </div>
    </div>

    <!-- NOTIFIKASI TOAST -->
    <?php if ($notif === "tambah_sukses"): ?>
        <div class="alert alert-success">Akun pelanggan baru berhasil didaftarkan ke sistem!</div>
    <?php elseif ($notif === "edit_sukses"): ?>
        <div class="alert alert-success">Data pelanggan berhasil diperbarui!</div>
    <?php elseif ($notif === "hapus_sukses"): ?>
        <div class="alert alert-success">Akun pelanggan dan riwayat pesanannya berhasil dihapus bersih dari sistem!</div>
    <?php endif; ?>

    <?php if ($pesan !== ""): ?>
        <div class="alert <?= $jenisPesan === "error" ? "alert-danger" : "alert-success" ?>">
            <?= htmlspecialchars($pesan) ?>
        </div>
    <?php endif; ?>

    <!-- STATISTIK KARTU PELANGGAN -->
    <section class="stats-grid">
        <div class="stat-card">
            <div class="stat-info">
                <div class="stat-val"><?= number_format($totalUsers) ?></div>
                <div class="stat-label">Total Akun Pelanggan</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <div class="stat-val"><?= number_format($totalUserAktif) ?></div>
                <div class="stat-label">Pelanggan Pernah Pesan</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <div class="stat-val"><?= number_format($totalPesananUsers) ?></div>
                <div class="stat-label">Total Semua Pesanan</div>
            </div>
        </div>
    </section>

    <!-- CARD TAMBAH PELANGGAN (CREATE) -->
    <div class="dash-card">
        <div class="dash-card-header">
            <h2 class="dash-card-title">Tambah Akun Pelanggan Baru</h2>
        </div>
        <form method="POST" autocomplete="off">
            <input type="hidden" name="tambah_user" value="1">
            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label">Nama Lengkap</label>
                    <input type="text" name="nama" class="form-control" placeholder="Contoh: Budi Santoso" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Username</label>
                    <input type="text" name="username" class="form-control" placeholder="Contoh: budi123" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Nomor WhatsApp</label>
                    <input type="tel" name="no_wa" class="form-control" placeholder="Contoh: 081234567890" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Email (Opsional)</label>
                    <input type="email" name="email" class="form-control" placeholder="Contoh: budi@gmail.com">
                </div>
                <div class="form-group">
                    <label class="form-label">Password Awal (Min. 6 Karakter)</label>
                    <input type="password" name="password" class="form-control" placeholder="Minimal 6 karakter" minlength="6" required>
                </div>
            </div>
            <div style="margin-top: 10px; display: flex; justify-content: flex-end;">
                <button type="submit" class="btn btn-primary">Simpan Akun Pelanggan</button>
            </div>
        </form>
    </div>

    <!-- CARD DAFTAR PELANGGAN (READ, UPDATE, DELETE) -->
    <div class="dash-card">
        <div class="dash-card-header">
            <h2 class="dash-card-title">Daftar Pelanggan Terdaftar (<?= count($users) ?>)</h2>
            <div class="dash-card-actions">
                <form method="GET" style="display: flex; gap: 8px;">
                    <input type="text" name="search" class="form-control" style="width: 260px; padding: 7px 12px; font-size: 13px;" placeholder="Cari nama, user, no WA..." value="<?= htmlspecialchars($search) ?>">
                    <button type="submit" class="btn btn-secondary btn-sm">Cari</button>
                    <?php if ($search !== ""): ?>
                        <a href="user.php" class="btn btn-secondary btn-sm">Reset</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <?php if (empty($users)): ?>
            <p style="text-align: center; color: var(--dark-muted); padding: 30px 0;">Tidak ada data pelanggan ditemukan.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="dash-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nama Pelanggan</th>
                            <th>Username</th>
                            <th>No. WhatsApp</th>
                            <th>Email</th>
                            <th>Total Pesanan</th>
                            <th>Pesanan Aktif</th>
                            <th>Tgl Terdaftar</th>
                            <th style="text-align: right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td><strong>#<?= $u["id"] ?></strong></td>
                                <td>
                                    <strong><?= htmlspecialchars($u["nama"]) ?></strong>
                                </td>
                                <td><code><?= htmlspecialchars($u["username"]) ?></code></td>
                                <td>
                                    <?php if (!empty($u["no_wa"])): ?>
                                        <?php 
                                            $waFormatted = $u["no_wa"];
                                            $waLink = str_starts_with($waFormatted, '08') ? '62' . substr($waFormatted, 1) : $waFormatted;
                                        ?>
                                        <a href="https://wa.me/<?= htmlspecialchars($waLink) ?>" target="_blank" style="color: #000000; font-weight: 700; text-decoration: underline;">
                                            <?= htmlspecialchars($waFormatted) ?>
                                        </a>
                                    <?php else: ?>
                                        <span style="color: #94a3b8; font-size: 12px;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= !empty($u["email"]) ? htmlspecialchars($u["email"]) : '<span style="color: #94a3b8; font-size: 12px;">-</span>' ?>
                                </td>
                                <td>
                                    <span class="status-pill bayar"><?= (int) $u["total_pesanan"] ?> pesanan</span>
                                    <?php if ((int) $u["pesanan_selesai"] > 0): ?>
                                        <span class="status-pill selesai" style="font-size: 10px; margin-left: 4px;"><?= (int) $u["pesanan_selesai"] ?> selesai</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ((int) $u["pesanan_aktif"] > 0): ?>
                                        <span class="status-pill proses"><?= (int) $u["pesanan_aktif"] ?> berjalan</span>
                                    <?php else: ?>
                                        <span style="color: #94a3b8; font-size: 12px;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size: 12px; color: var(--dark-muted);">
                                    <?= !empty($u["created_at"]) ? date("d M Y H:i", strtotime($u["created_at"])) : "-" ?>
                                </td>
                                <td style="text-align: right;">
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="bukaModalEdit(<?= htmlspecialchars(json_encode($u)) ?>)">
                                        Edit
                                    </button>
                                    <button type="button" class="btn btn-danger btn-sm" onclick="konfirmasiHapus(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['nama'])) ?>', <?= (int)$u['total_pesanan'] ?>)">
                                        Hapus
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

</div>

<!-- MODAL EDIT PELANGGAN -->
<div class="modal-backdrop" id="modalEdit">
    <div class="modal-box">
        <div class="modal-header">
            <h3 class="modal-title">Edit Akun Pelanggan</h3>
            <button class="modal-close" onclick="tutupModal('modalEdit')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="edit_user" value="1">
            <input type="hidden" name="user_id" id="editUserId">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Nama Lengkap</label>
                    <input type="text" name="nama" id="editNama" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Username</label>
                    <input type="text" name="username" id="editUsername" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Nomor WhatsApp</label>
                    <input type="tel" name="no_wa" id="editNoWa" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Email (Opsional)</label>
                    <input type="email" name="email" id="editEmail" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">Reset Password (Opsional)</label>
                    <input type="password" name="password_baru" class="form-control" placeholder="Kosongkan jika password tidak ingin diubah" minlength="6">
                    <small class="form-hint">Isi hanya bila ingin mengganti password pelanggan ini.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="tutupModal('modalEdit')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL KONFIRMASI HAPUS PELANGGAN -->
<div class="modal-backdrop" id="modalHapus">
    <div class="modal-box">
        <div class="modal-header">
            <h3 class="modal-title" style="color: var(--danger-dark);">Konfirmasi Hapus Pelanggan</h3>
            <button class="modal-close" onclick="tutupModal('modalHapus')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="hapus_user" value="1">
            <input type="hidden" name="user_id" id="hapusUserId">
            <div class="modal-body">
                <p>Apakah Anda yakin ingin menghapus akun pelanggan <strong id="hapusUserNama"></strong>?</p>
                <div id="hapusUserPeringatan" class="alert alert-warning" style="margin-top: 14px; display: none;">
                    Pelanggan ini memiliki <strong id="hapusUserPesananCount">0</strong> riwayat pesanan. Semua pesanan dan bukti bayar terkait juga akan ikut terhapus secara otomatis.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="tutupModal('modalHapus')">Batal</button>
                <button type="submit" class="btn btn-danger">Ya, Hapus Akun</button>
            </div>
        </form>
    </div>
</div>

<script>
function bukaModalEdit(user) {
    document.getElementById('editUserId').value = user.id;
    document.getElementById('editNama').value = user.nama;
    document.getElementById('editUsername').value = user.username;
    document.getElementById('editNoWa').value = user.no_wa || '';
    document.getElementById('editEmail').value = user.email || '';
    document.getElementById('modalEdit').classList.add('active');
}

function konfirmasiHapus(id, nama, pesananCount) {
    document.getElementById('hapusUserId').value = id;
    document.getElementById('hapusUserNama').innerText = nama;
    const warnBox = document.getElementById('hapusUserPeringatan');
    if (pesananCount > 0) {
        document.getElementById('hapusUserPesananCount').innerText = pesananCount;
        warnBox.style.display = 'flex';
    } else {
        warnBox.style.display = 'none';
    }
    document.getElementById('modalHapus').classList.add('active');
}

function tutupModal(id) {
    document.getElementById(id).classList.remove('active');
}

// Tutup modal jika user klik di backdrop
window.onclick = function(e) {
    if (e.target.classList.contains('modal-backdrop')) {
        e.target.classList.remove('active');
    }
};
</script>

</body>
</html>
