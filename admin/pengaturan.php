<?php
session_start();
require_once '../koneksi.php';
require_once '../includes/auth.php';
require_once '../includes/midtrans_helper.php';

requireLogin();
requireRole('admin');

$activeAdminPage = 'pengaturan';
$pesan = '';
$jenisPesan = '';

// Proses Simpan Pengaturan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simpan_pengaturan'])) {
    $gatewayMode      = trim($_POST['payment_gateway_mode'] ?? 'midtrans');
    $midtransServer   = trim($_POST['midtrans_server_key'] ?? '');
    $midtransClient   = trim($_POST['midtrans_client_key'] ?? '');
    $midtransMerchant = trim($_POST['midtrans_merchant_id'] ?? '');
    $midtransIsProd   = isset($_POST['midtrans_is_production']) ? 1 : 0;

    $tarifDasar       = max(1000, (int)($_POST['tarif_dasar'] ?? 5000));
    $jarakDasar       = max(0.5, (float)($_POST['jarak_dasar_km'] ?? 1.0));
    $tarifPerKm       = max(500, (int)($_POST['tarif_per_km'] ?? 2500));
    $biayaGalon       = max(0, (int)($_POST['biaya_galon'] ?? 2000));

    try {
        $stmt = $pdo->prepare("
            UPDATE pengaturan_sistem
            SET payment_gateway_mode = ?,
                midtrans_server_key = ?,
                midtrans_client_key = ?,
                midtrans_merchant_id = ?,
                midtrans_is_production = ?,
                tarif_dasar = ?,
                jarak_dasar_km = ?,
                tarif_per_km = ?,
                biaya_galon = ?
            WHERE id = 1
        ");
        $stmt->execute([
            $gatewayMode,
            $midtransServer,
            $midtransClient,
            $midtransMerchant,
            $midtransIsProd,
            $tarifDasar,
            $jarakDasar,
            $tarifPerKm,
            $biayaGalon
        ]);

        $pesan = "Pengaturan sistem & Gateway Pembayaran berhasil disimpan!";
        $jenisPesan = "success";
    } catch (Exception $e) {
        $pesan = "Gagal menyimpan pengaturan: " . $e->getMessage();
        $jenisPesan = "error";
    }
}

// Ambil konfigurasi saat ini
$cfg = getMidtransConfig($pdo);

// Hitung webhook URL otomatis
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$webhookUrl = $protocol . $host . '/polindrasuruh/api/midtrans_callback.php';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengaturan Gateway Pembayaran - Polindra Suruh Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
</head>
<body class="page-admin">

    <?php require_once '../includes/admin_navbar.php'; ?>

    <main class="admin-main-wrap">

        <div style="margin-bottom: 24px;">
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                <div>
                    <h1 style="font-size: 26px; font-weight: 900; color: #0f172a; margin: 0;">Pengaturan Gateway & Sistem</h1>
                    <p style="color: #64748b; font-size: 14px; margin-top: 4px;">Kelola otomatisasi verifikasi pembayaran QRIS (Midtrans) dan tarif per kilometer.</p>
                </div>
                <div>
                    <span class="badge-env <?= $cfg['is_production'] ? 'badge-prod' : 'badge-sandbox' ?>">
                        Status Gateway: <?= $cfg['is_production'] ? 'Production (Live)' : 'Sandbox (Uji Coba)' ?>
                    </span>
                </div>
            </div>
        </div>

        <?php if (!empty($pesan)): ?>
            <div style="padding: 14px 18px; border-radius: 12px; margin-bottom: 20px; font-size: 13.5px; font-weight: 700; <?= $jenisPesan === 'success' ? 'background: #dcfce7; color: #166534; border: 1.5px solid #86efac;' : 'background: #fee2e2; color: #991b1b; border: 1.5px solid #fca5a5;' ?>">
                <?= $jenisPesan === 'success' ? '' : '' ?> <?= htmlspecialchars($pesan) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="pengaturan.php">
            <input type="hidden" name="simpan_pengaturan" value="1">

            <!-- KARTU 1: GATEWAY MIDTRANS -->
            <div class="settings-card">
                <div class="card-head-title">
                    
                    <div>
                        <h2>Otomatisasi Pembayaran QRIS (Midtrans)</h2>
                        <p>Pelanggan scan QRIS & sistem otomatis mendeteksi pembayaran sah tanpa perlu cek foto struk.</p>
                    </div>
                </div>

                <div class="form-group-item">
                    <label class="form-label-bold">Mode Pembayaran:</label>
                    <div style="display: flex; gap: 16px; flex-wrap: wrap;">
                        <label style="display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 700; cursor: pointer;">
                            <input type="radio" name="payment_gateway_mode" value="midtrans" <?= $cfg['mode'] === 'midtrans' ? 'checked' : '' ?>>
                            <span>Otomatis Midtrans QRIS (Rekomendasi)</span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 700; cursor: pointer;">
                            <input type="radio" name="payment_gateway_mode" value="manual" <?= $cfg['mode'] === 'manual' ? 'checked' : '' ?>>
                            <span>Manual (Upload Bukti Struk Transfer)</span>
                        </label>
                    </div>
                </div>

                <div class="form-group-item" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px;">
                    <label style="display: flex; align-items: center; gap: 10px; font-size: 14px; font-weight: 800; cursor: pointer;">
                        <input type="checkbox" name="midtrans_is_production" value="1" <?= $cfg['is_production'] ? 'checked' : '' ?> style="width: 18px; height: 18px;">
                        <span>Aktifkan Mode Production (Live Asli)</span>
                    </label>
                    <p style="font-size: 12px; color: #64748b; margin: 4px 0 0 28px;">
                        Biarkan tidak dicentang jika masih dalam tahap pengujian / demonstrasi (Sandbox Mode).
                    </p>
                </div>

                <div class="form-grid-2">
                    <div class="form-group-item">
                        <label class="form-label-bold">Midtrans Server Key:</label>
                        <input type="text" name="midtrans_server_key" class="form-input-text" value="<?= htmlspecialchars($cfg['server_key']) ?>" required placeholder="Contoh: SB-Mid-server-xxxx">
                        <small style="font-size: 11.5px; color: #64748b;">Dapatkan dari Dashboard Midtrans &rarr; Access Keys</small>
                    </div>

                    <div class="form-group-item">
                        <label class="form-label-bold">Midtrans Client Key:</label>
                        <input type="text" name="midtrans_client_key" class="form-input-text" value="<?= htmlspecialchars($cfg['client_key']) ?>" required placeholder="Contoh: SB-Mid-client-xxxx">
                        <small style="font-size: 11.5px; color: #64748b;">Dapatkan dari Dashboard Midtrans &rarr; Access Keys</small>
                    </div>
                </div>

                <div class="form-group-item">
                    <label class="form-label-bold">URL Notifikasi Webhook (Payment Notification URL):</label>
                    <div class="copy-url-box">
                        <span id="webhookUrlText"><?= htmlspecialchars($webhookUrl) ?></span>
                        <button type="button" class="btn-copy" onclick="copyWebhook()">Salin URL</button>
                    </div>
                    <small style="font-size: 11.5px; color: #64748b; display: block; margin-top: 4px;">
                        Salin URL ini dan tempelkan ke <strong>Dashboard Midtrans &rarr; Settings &rarr; Configuration &rarr; Payment Notification URL</strong> agar Midtrans dapat memberitahu server secara otomatis saat uang masuk.
                    </small>
                </div>
            </div>

            <!-- KARTU 2: PENGATURAN TARIF PER KILOMETER -->
            <div class="settings-card">
                <div class="card-head-title">
                    <div style="font-size: 28px;"></div>
                    <div>
                        <h2>Pengaturan Tarif Jarak GPS</h2>
                        <p>Sesuaikan tarif ongkir per kilometer dari lokasi driver ke pelanggan.</p>
                    </div>
                </div>

                <div class="form-grid-2">
                    <div class="form-group-item">
                        <label class="form-label-bold">Tarif Dasar (Rp):</label>
                        <input type="number" name="tarif_dasar" class="form-input-text" value="<?= (int)$cfg['tarif_dasar'] ?>" required step="500">
                        <small style="font-size: 11.5px; color: #64748b;">Tarif minimum untuk jarak awal (Saat ini: Rp 5.000)</small>
                    </div>

                    <div class="form-group-item">
                        <label class="form-label-bold">Batas Jarak Dasar (KM):</label>
                        <input type="number" name="jarak_dasar_km" class="form-input-text" value="<?= (float)$cfg['jarak_dasar_km'] ?>" required step="0.1">
                        <small style="font-size: 11.5px; color: #64748b;">Jarak awal yang dicakup tarif dasar (Saat ini: 1.0 km)</small>
                    </div>

                    <div class="form-group-item">
                        <label class="form-label-bold">Tarif Tambahan per KM (Rp):</label>
                        <input type="number" name="tarif_per_km" class="form-input-text" value="<?= (int)$cfg['tarif_per_km'] ?>" required step="500">
                        <small style="font-size: 11.5px; color: #64748b;">Tambahan tarif untuk setiap kilometer berikutnya (Saat ini: Rp 2.500/km)</small>
                    </div>

                    <div class="form-group-item">
                        <label class="form-label-bold">Biaya Khusus Antar Galon (Rp):</label>
                        <input type="number" name="biaya_galon" class="form-input-text" value="<?= (int)($cfg['biaya_galon'] ?? 2000) ?>" required step="500">
                        <small style="font-size: 11.5px; color: #64748b;">Beban fisik tambahan layanan galon (Saat ini: Rp 2.000)</small>
                    </div>
                </div>
            </div>

            <div style="text-align: right;">
                <button type="submit" class="btn-save-primary">
                    Simpan Semua Pengaturan
                </button>
            </div>
        </form>

    </main>

    <script>
    function copyWebhook() {
        const text = document.getElementById('webhookUrlText').innerText;
        navigator.clipboard.writeText(text).then(() => {
            alert('URL Webhook berhasil disalin:\n' + text);
        }).catch(() => {
            alert('Silakan salin teks URL secara manual.');
        });
    }
    </script>
</body>
</html>
