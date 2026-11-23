<?php
session_start();
require_once "koneksi.php";
require_once "includes/data_tempat_indramayu.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php?redirect=order");
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
$nama = $_SESSION["nama"] ?? "Pengguna";

// Ambil seluruh driver (aktif dan tidak aktif) dengan koordinat GPS & rating
$sqlDrivers = "SELECT u.id, u.nama,
                      COALESCE(d.status_aktif, u.status_aktif, 0) AS status_aktif,
                      d.no_hp, d.foto_qris, d.lat, d.lng,
                      COALESCE(d.rating_rata, 0) AS rating_rata,
                      COALESCE(d.jumlah_rating, 0) AS jumlah_rating
               FROM users u
               LEFT JOIN drivers d ON d.user_id = u.id
               WHERE u.role = 'driver'
               ORDER BY status_aktif DESC, rating_rata DESC, u.nama ASC";
$stmtDrivers = $pdo->query($sqlDrivers);
$daftarDriver = $stmtDrivers->fetchAll(PDO::FETCH_ASSOC);

$driversOnline = array_values(array_filter($daftarDriver, fn($d) => (int)$d['status_aktif'] === 1));

// Driver terpilih jika ada (hanya jika aktif)
$driverId = isset($_GET["driver_id"]) ? (int) $_GET["driver_id"] : null;
$driverTerpilih = null;
if ($driverId) {
    foreach ($daftarDriver as $d) {
        if ((int)$d['id'] === $driverId && (int)$d['status_aktif'] === 1) {
            $driverTerpilih = $d;
            break;
        }
    }
}

// Jika belum ada driver terpilih dan ada driver online, pilih yang pertama secara default
if (!$driverTerpilih && count($driversOnline) > 0) {
    $driverTerpilih = $driversOnline[0];
    $driverId = (int)$driverTerpilih['id'];
}

$driverLatTerpilih   = ($driverTerpilih && !empty($driverTerpilih['lat'])) ? $driverTerpilih['lat'] : '-6.4018000';
$driverLngTerpilih   = ($driverTerpilih && !empty($driverTerpilih['lng'])) ? $driverTerpilih['lng'] : '108.2825000';
$driverNamaTerpilih  = $driverTerpilih ? $driverTerpilih['nama'] : 'Driver Polindra';
$driverRatingTerpilih = ($driverTerpilih && (int)$driverTerpilih['jumlah_rating'] > 0)
    ? '★ ' . number_format((float)$driverTerpilih['rating_rata'], 1) . ' (' . (int)$driverTerpilih['jumlah_rating'] . ' ulasan)'
    : '★ Baru (0 ulasan)';

// Parameter query string
$rawLayanan      = trim($_GET["layanan"] ?? "");
$paramTokoId     = $_GET["toko_id"] ?? "";
$paramNamaToko   = $_GET["nama_toko"] ?? "";
$paramLatToko    = $_GET["lat"] ?? "";
$paramLngToko    = $_GET["lng"] ?? "";
$paramAlamatToko = $_GET["alamat_toko"] ?? "";

// Cocokkan data toko jika toko_id dikirim dari katalog
if (!empty($paramTokoId)) {
    $daftarTempat = getDaftarTempatIndramayu();
    foreach ($daftarTempat as $dt) {
        if ($dt['id'] === $paramTokoId) {
            if (empty($paramNamaToko)) $paramNamaToko = $dt['nama'];
            if (empty($paramLatToko)) $paramLatToko = $dt['lat'];
            if (empty($paramLngToko)) $paramLngToko = $dt['lng'];
            if (empty($paramAlamatToko)) $paramAlamatToko = $dt['alamat'];
            break;
        }
    }
}

// Tentukan Mode Layanan sesuai pilihan user
if (stripos($rawLayanan, 'print') !== false || stripos($rawLayanan, 'fotokopi') !== false) {
    $mode = 'Print & Fotokopi';
    $modeIcon = '';
    $modeJudul = 'Jasa Print & Fotokopi';
    $modeSub = 'Titip print tugas, makalah, jilid skripsi, atau fotokopi berkas kampus';
    $paramLatToko = '-6.4018000';
    $paramLngToko = '108.2825000';
} elseif (stripos($rawLayanan, 'service') !== false || stripos($rawLayanan, 'motor') !== false || stripos($rawLayanan, 'bengkel') !== false) {
    $mode = 'Service Motor';
    $modeIcon = '';
    $modeJudul = 'Jasa Service Motor';
    $modeSub = 'Bantuan antar motor ke bengkel, tambal ban bocor, ganti oli, atau service rutin';
    $paramLatToko = '-6.4018000';
    $paramLngToko = '108.2825000';
} elseif (stripos($rawLayanan, 'nongkrong') !== false || stripos($rawLayanan, 'teman') !== false) {
    $mode = 'Temanin Nongkrong';
    $modeIcon = '';
    $modeJudul = 'Jasa Temanin Nongkrong';
    $modeSub = 'Teman ngopi santai di kafe, ngobrol seru, atau ngerjain tugas kuliah bareng';
    $paramLatToko = '-6.4018000';
    $paramLngToko = '108.2825000';
} elseif (stripos($rawLayanan, 'galon') !== false || stripos($rawLayanan, 'gas') !== false) {
    $mode = 'Antar Galon';
    $modeIcon = '';
    $modeJudul = 'Jasa Antar Galon / Gas';
    $modeSub = 'Bantuan beli dan antar galon air minum atau tabung gas ke kosan';
    $paramLatToko = '-6.4018000';
    $paramLngToko = '108.2825000';
} elseif (stripos($rawLayanan, 'antar') !== false || stripos($rawLayanan, 'jemput') !== false) {
    $mode = 'Antar Jemput';
    $modeIcon = '';
    $modeJudul = 'Pesan Antar Jemput';
    $modeSub = 'Antar jemput orang, barang, paket dokumen kampus, atau laundry';
    if (empty($paramLatToko)) {
        $paramLatToko = '-6.4018000';
        $paramLngToko = '108.2825000';
    }
} elseif (stripos($rawLayanan, 'kost') !== false || stripos($rawLayanan, 'beres') !== false) {
    $mode = 'Beresin Kost';
    $modeIcon = '';
    $modeJudul = 'Pesan Jasa Beresin Kost';
    $modeSub = 'Bantuan bersih-bersih kamar kos, sapu, pel & buang sampah';
    $paramLatToko = '-6.4018000';
    $paramLngToko = '108.2825000';
} elseif (stripos($rawLayanan, 'food') !== false || stripos($rawLayanan, 'makan') !== false) {
    $mode = 'Food';
    $modeIcon = '';
    $modeJudul = 'Pesan Makanan & Belanja';
    $modeSub = 'Pesan makanan atau belanjaan dari toko di Indramayu';
    if (empty($paramLatToko)) {
        $paramLatToko = '-6.4018000';
        $paramLngToko = '108.2825000';
    }
} else {
    $mode = 'Suruh Apa Saja';
    $modeIcon = '';
    $modeJudul = 'Layanan Terserah (Suruh Apa Saja)';
    $modeSub = 'Tuliskan apa saja bantuan yang ingin kamu suruh ke driver mahasiswa';
    $paramLatToko = '-6.4018000';
    $paramLngToko = '108.2825000';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($modeJudul) ?> - Polindra Suruh</title>
    <link rel="stylesheet" href="assets/css/home.css?v=<?= filemtime(__DIR__ . '/assets/css/home.css') ?>">
</head>

<body >

<?php require_once __DIR__ . '/includes/navbar.php'; ?>

<main class="simple-order-wrap">
    <div class="order-box">

        <!-- HEADER SESUAI LAYANAN YANG DIORDER -->
        <div class="order-header-clean">
            <div class="header-left">
                <div class="header-icon"><?= $modeIcon ?></div>
                <div class="header-text">
                    <h1><?= htmlspecialchars($modeJudul) ?></h1>
                    <p><?= htmlspecialchars($modeSub) ?></p>
                </div>
            </div>
            <a href="layanan.php" class="btn-change-service">Ganti Layanan &rarr;</a>
        </div>

        <form method="POST" action="uploads/proses_order_sistem.php" id="orderForm">

            <!-- DATA TERSEMBUNYI -->
            <input type="hidden" name="layanan" value="<?= htmlspecialchars($mode) ?>">
            <input type="hidden" id="driver_id" name="driver_id" value="<?= $driverId ? (int)$driverId : '' ?>">
            <input type="hidden" id="nama_toko" name="nama_toko" value="<?= htmlspecialchars($paramNamaToko) ?>">
            <input type="hidden" id="alamat_toko" name="alamat_toko" value="<?= htmlspecialchars($paramAlamatToko) ?>">
            <input type="hidden" id="pickup_lat" name="pickup_lat" value="<?= htmlspecialchars($driverLatTerpilih) ?>">
            <input type="hidden" id="pickup_lng" name="pickup_lng" value="<?= htmlspecialchars($driverLngTerpilih) ?>">
            <input type="hidden" id="dest_lat" name="dest_lat" value="">
            <input type="hidden" id="dest_lng" name="dest_lng" value="">

            <?php if (!empty($paramNamaToko)): ?>
                <div class="shop-preview-card" style="margin-bottom: 18px;">
                    <div class="shop-preview-info">
                        <strong><?= htmlspecialchars($paramNamaToko) ?></strong>
                        <span><?= htmlspecialchars($paramAlamatToko ?: 'Wilayah Indramayu') ?></span>
                    </div>
                    <a href="order.php?layanan=<?= urlencode($mode) ?>" class="shop-change-link">Ubah Toko</a>
                </div>
            <?php endif; ?>

            <!-- LANGKAH 1: PILIH DRIVER (MENAMPILKAN RATING & LOKASI DRIVER) -->
            <div class="form-step" id="stepPilihDriver">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <label class="step-label" style="margin-bottom: 0;">
                        <span class="step-num">1</span>
                        <span>Pilih Driver Tersedia</span>
                    </label>
                    <span style="font-size: 11px; background: #dcfce7; color: #166534; font-weight: 700; padding: 2px 8px; border-radius: 10px;">
                        <?= count($driversOnline) ?> Driver Online
                    </span>
                </div>

                <?php if (empty($driversOnline)): ?>
                    <div style="background: #fffbeb; border: 1.5px solid #fef08a; border-radius: 12px; padding: 14px; font-size: 13px; color: #92400e;">
                        <strong>Saat ini belum ada driver yang online.</strong> Pesanan Anda akan berstatus <em>Menunggu Driver</em> hingga ada driver yang siap mengambil.
                    </div>
                <?php else: ?>
                    <p style="font-size: 12px; color: #64748b; margin: 0 0 10px;">Pilih driver untuk pesanan ini. Tarif akan dihitung otomatis dari lokasi driver ke lokasi antarmu:</p>

                    <div class="driver-options-grid" id="driverGrid">
                        <?php foreach ($driversOnline as $drv): ?>
                            <?php 
                                $isSelected = ($driverTerpilih && (int)$driverTerpilih['id'] === (int)$drv['id']);
                                $dLat = !empty($drv['lat']) ? (float)$drv['lat'] : -6.4018000;
                                $dLng = !empty($drv['lng']) ? (float)$drv['lng'] : 108.2825000;
                                $rataRating = isset($drv['rating_rata']) ? (float)$drv['rating_rata'] : 0.0;
                                $jmlRating  = isset($drv['jumlah_rating']) ? (int)$drv['jumlah_rating'] : 0;
                                $teksRating = $jmlRating > 0
                                    ? '★ ' . number_format($rataRating, 1) . ' (' . $jmlRating . ' ulasan)'
                                    : '★ Baru (0 ulasan)';
                            ?>
                            <label class="driver-pill-card <?= $isSelected ? 'active' : '' ?>" 
                                   id="card-driver-<?= $drv['id'] ?>"
                                   onclick="pilihDriverItem(<?= (int)$drv['id'] ?>, '<?= htmlspecialchars(addslashes($drv['nama'])) ?>', <?= $dLat ?>, <?= $dLng ?>, '<?= htmlspecialchars(addslashes($teksRating)) ?>')">
                                <input type="radio" 
                                       name="radio_driver" 
                                       value="<?= (int)$drv['id'] ?>" 
                                       <?= $isSelected ? 'checked' : '' ?> 
                                       required>

                                <div class="driver-pill-avatar">
                                    <?= strtoupper(substr($drv['nama'], 0, 1)) ?>
                                </div>

                                <div class="driver-pill-info">
                                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 6px;">
                                        <strong><?= htmlspecialchars($drv['nama']) ?></strong>
                                        <span style="font-size: 10px; background: #dcfce7; color: #15803d; font-weight: 800; padding: 1px 6px; border-radius: 6px;">● Online</span>
                                    </div>

                                    <!-- BADGE RATING DRIVER -->
                                    <div style="margin-top: 3px; font-size: 11px; font-weight: 700; color: #b45309; display: inline-flex; align-items: center; gap: 4px; background: #fffbeb; border: 1px solid #fde68a; padding: 1px 6px; border-radius: 6px;">
                                        <span>★ <?= $jmlRating > 0 ? number_format($rataRating, 1) . ' / 5.0' : 'Baru' ?></span>
                                        <span style="color: #78716c; font-weight: 600;">
                                            (<?= $jmlRating > 0 ? $jmlRating . ' ulasan' : '0 ulasan' ?>)
                                        </span>
                                    </div>

                                    <span class="driver-location-badge" style="font-size: 11px; color: #0284c7; display: flex; align-items: center; gap: 4px; margin-top: 3px;">
                                        Lokasi: <?= round($dLat, 4) ?>, <?= round($dLng, 4) ?>
                                    </span>
                                    <span class="driver-distance-badge" id="driver-dist-<?= $drv['id'] ?>" style="font-size: 11px; color: #16a34a; font-weight: 700; margin-top: 2px;">
                                        <!-- Diisi otomatis via JS saat pelanggan menandai lokasi -->
                                    </span>
                                </div>

                                <div class="driver-check-badge"></div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- LANGKAH 2: MAU NYURUH APA -->
            <div class="form-step">
                <label class="step-label" for="catatan">
                    <span class="step-num">2</span>
                    <span>Mau Nyuruh Apa?</span>
                </label>
                <textarea
                    id="catatan"
                    name="catatan"
                    class="clean-textarea"
                    placeholder="<?= ($mode === 'Food') 
                        ? "Tuliskan pesananmu di sini...\nContoh: 1 Ayam Penyet Sambal Sedang + 1 Es Teh Manis" 
                        : (($mode === 'Antar Jemput') 
                            ? "Tuliskan keperluan antar-jemput...\nContoh: Jemput saya di gerbang Polindra jam 15.00 / titip ambil paket laundry 3 kg" 
                            : (($mode === 'Beresin Kost') 
                                ? "Tuliskan apa saja yang perlu dibersihkan...\nContoh: Tolong sapu & pel lantai kamar kos dan buang sampah di depan" 
                                : "Tuliskan apa saja yang ingin kamu suruh ke driver...\nContoh: Beli makanan, antar barang, atau bantuan lainnya")) ?>"
                    rows="4"
                    required
                ></textarea>
            </div>

            <!-- LANGKAH 3: LOKASI PELANGGAN & PERHITUNGAN JARAK -->
            <div class="form-step">
                <label class="step-label" for="alamat_pelanggan">
                    <span class="step-num">3</span>
                    <span>Lokasi Anda (Titik Antar)</span>
                </label>
                <div class="input-with-btn">
                    <input
                        type="text"
                        id="alamat_pelanggan"
                        name="alamat_pelanggan"
                        class="clean-input"
                        placeholder="Masukkan lokasi Anda (Contoh: Kost Wisma Melati No. 4 / Gedung TI Polindra)"
                        required
                    >
                </div>
                <!-- TITIK KOORDINAT GPS -->
                <div class="koordinat-box">
                    <button type="button" class="btn-koordinat" onclick="ambilKoordinatTujuan()" id="btnKoordTujuan">
                        Tandai Lokasi Saya
                    </button>
                    <span class="koordinat-loading" id="koordTujuanLoading">Mendeteksi lokasi...</span>
                    <span class="koordinat-info" id="koordTujuanInfo">Lokasi: <span id="koordTujuanTeks">-</span></span>
                    <span class="koordinat-error" id="koordTujuanError">Gagal deteksi GPS</span>
                </div>

                <?php 
                    $isTarifOtomatis = in_array($mode, ['Food', 'Antar Jemput', 'Print & Fotokopi', 'Antar Galon', 'Suruh Apa Saja'], true);
                ?>

                <?php if ($isTarifOtomatis): ?>
                    <!-- KOTAK ESTIMASI TARIF OTOMATIS BERDASARKAN JARAK DRIVER KE PELANGGAN -->
                    <div id="boxEstimasiTarif" style="display:none; background:#ecfdf5; border:1.5px solid #a7f3d0; border-radius:14px; padding:15px 18px; margin-top:14px; box-shadow: 0 2px 8px rgba(16, 185, 129, 0.08);">
                        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                            <div>
                                <span style="font-size:11.5px; font-weight:800; color:#047857; text-transform:uppercase; letter-spacing:0.5px;"></span>
                                <div style="font-size:13px; color:#065f46; font-weight:700; margin-top:3px;">
                                    Driver: <strong id="namaDriverAktif"><?= htmlspecialchars($driverNamaTerpilih) ?></strong>
                                    <span id="ratingDriverAktif" style="margin-left:6px; font-size:11.5px; background:#fffbeb; color:#b45309; border:1px solid #fde68a; padding:1px 7px; border-radius:6px;">
                                        <?= htmlspecialchars($driverRatingTerpilih) ?>
                                    </span>
                                </div>
                                <div style="font-size:12.5px; color:#047857; margin-top:2px;" id="teksJarakKm">Perkiraan jarak tempuh: -</div>
                            </div>
                            <div style="text-align:right;">
                                <div style="font-size:11px; color:#059669; font-weight:700;">Total Ongkir:</div>
                                <div style="font-size:22px; font-weight:900; color:#065f46;" id="teksTotalTarif">Rp 5.000</div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div style="background:#fffbeb; border:1.5px solid #fde68a; border-radius:12px; padding:12px 16px; margin-top:14px; font-size:12.5px; color:#92400e; display:flex; align-items:center; gap:8px;">
                        <span><strong>Pembayaran Cash</strong> Biaya jasa dan belanja untuk layanan ini disepakati langsung bersama driver melalui chat.</span>
                    </div>
                <?php endif; ?>
            </div>

            <!-- LANGKAH 4: METODE PEMBAYARAN -->
            <div class="form-step">
                <label class="step-label">
                    <span class="step-num">4</span>
                    <span>Pilih Pembayaran</span>
                </label>
                <div class="pay-options">
                    <label class="pay-pill active" id="pill-qris">
                        <input type="radio" name="metode_bayar" value="qris" checked onchange="pilihMetode(this)">
                        QRIS 
                    </label>
                    <label class="pay-pill" id="pill-cash">
                        <input type="radio" name="metode_bayar" value="cash" onchange="pilihMetode(this)">
                        Tunai / Cash
                    </label>
                </div>
            </div>

            <!-- TOMBOL PESAN SEKARANG -->
            <button type="submit" class="btn-order-now" id="btnSubmit">
                <span>Pesan Sekarang</span>
            </button>

        </form>

    </div>
</main>

<script>
// Data driver online untuk perhitungan live jarak dan estimasi tarif
const listDrivers = <?= json_encode(array_map(function($d) {
    return [
        'id' => (int)$d['id'],
        'nama' => $d['nama'],
        'lat' => !empty($d['lat']) ? (float)$d['lat'] : -6.4018000,
        'lng' => !empty($d['lng']) ? (float)$d['lng'] : 108.2825000,
        'rating_rata' => isset($d['rating_rata']) ? (float)$d['rating_rata'] : 0.0,
        'jumlah_rating' => isset($d['jumlah_rating']) ? (int)$d['jumlah_rating'] : 0,
    ];
}, $driversOnline)) ?>;

function hitungJarakKm(lat1, lon1, lat2, lon2) {
    const R = 6371; // radius bumi km
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLon = (lon2 - lon1) * Math.PI / 180;
    const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
              Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
              Math.sin(dLon/2) * Math.sin(dLon/2);
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
    return Math.max(0.5, Math.round((R * c) * 100) / 100);
}

function pilihDriverItem(id, nama, lat, lng, teksRating) {
    document.querySelectorAll('.driver-pill-card').forEach(c => c.classList.remove('active'));
    const card = document.getElementById('card-driver-' + id);
    if (card) {
        card.classList.add('active');
        const radio = card.querySelector('input[type="radio"]');
        if (radio) radio.checked = true;
    }

    document.getElementById('driver_id').value = id;
    document.getElementById('pickup_lat').value = lat;
    document.getElementById('pickup_lng').value = lng;

    const elNama = document.getElementById('namaDriverAktif');
    if (elNama) elNama.textContent = nama;

    const elRating = document.getElementById('ratingDriverAktif');
    if (elRating && teksRating) elRating.textContent = teksRating;

    // Hitung ulang tarif jika koordinat pelanggan sudah ditandai
    const dLat = document.getElementById('dest_lat').value;
    const dLng = document.getElementById('dest_lng').value;
    if (dLat && dLng) {
        hitungEstimasiTarifLive(dLat, dLng);
    }
}

// ---- TITIK KOORDINAT GPS PELANGGAN ----
function ambilKoordinatTujuan() {
    const btn      = document.getElementById('btnKoordTujuan');
    const loading  = document.getElementById('koordTujuanLoading');
    const info     = document.getElementById('koordTujuanInfo');
    const teks     = document.getElementById('koordTujuanTeks');
    const err      = document.getElementById('koordTujuanError');

    // reset state
    info.classList.remove('show');
    err.classList.remove('show');
    loading.classList.add('show');
    btn.disabled = true;

    if (!navigator.geolocation) {
        loading.classList.remove('show');
        err.classList.add('show');
        err.textContent = 'Browser tidak mendukung GPS';
        btn.disabled = false;
        return;
    }

    navigator.geolocation.getCurrentPosition(
        function(pos) {
            const lat = pos.coords.latitude.toFixed(6);
            const lng = pos.coords.longitude.toFixed(6);
            document.getElementById('dest_lat').value = lat;
            document.getElementById('dest_lng').value = lng;

            // Isi field alamat jika masih kosong
            const alamatEl = document.getElementById('alamat_pelanggan');
            if (alamatEl && !alamatEl.value.trim()) {
                alamatEl.value = 'Lokasi GPS Pelanggan (Lat: ' + lat + ')';
            }

            teks.textContent = lat + ', ' + lng;
            loading.classList.remove('show');
            info.classList.add('show');
            btn.disabled = false;
            btn.textContent = 'Perbarui Lokasi';

            // Update badge jarak pada masing-masing driver yang sedang online
            listDrivers.forEach(drv => {
                const dist = hitungJarakKm(drv.lat, drv.lng, parseFloat(lat), parseFloat(lng));
                const distEl = document.getElementById('driver-dist-' + drv.id);
                if (distEl) {
                    distEl.textContent = '~' + dist + ' km dari lokasimu';
                }
            });

            // Hitung estimasi tarif otomatis dari lokasi driver terpilih ke pelanggan
            hitungEstimasiTarifLive(lat, lng);
        },
        function(e) {
            loading.classList.remove('show');
            err.classList.add('show');
            err.textContent = 'Gagal deteksi GPS — izinkan akses lokasi di browser';
            btn.disabled = false;
        },
        { timeout: 8000, enableHighAccuracy: true }
    );
}

function hitungEstimasiTarifLive(destLat, destLng) {
    const isOtomatis = <?= $isTarifOtomatis ? 'true' : 'false' ?>;
    if (!isOtomatis) return;

    let pLat = parseFloat(document.getElementById('pickup_lat').value) || -6.4018000;
    let pLng = parseFloat(document.getElementById('pickup_lng').value) || 108.2825000;
    let dLat = parseFloat(destLat);
    let dLng = parseFloat(destLng);

    let jarak = hitungJarakKm(pLat, pLng, dLat, dLng);

    let tarifDasar = 5000;
    let jarakDasar = 1.0;
    let tarifPerKm = 2500;
    let isGalon = <?= (stripos($mode, 'galon') !== false) ? 'true' : 'false' ?>;

    let total = tarifDasar;
    if (jarak > jarakDasar) {
        total += Math.ceil((jarak - jarakDasar) * tarifPerKm);
    }
    if (isGalon) {
        total += 2000;
    }
    total = Math.ceil(total / 500) * 500;

    const box = document.getElementById('boxEstimasiTarif');
    if (box) {
        box.style.display = 'block';
        document.getElementById('teksJarakKm').textContent = 'Perkiraan jarak driver ke antaran: ~' + jarak + ' km';
        document.getElementById('teksTotalTarif').textContent = 'Rp ' + total.toLocaleString('id-ID');
    }
}

function pilihMetode(radioEl) {
    document.querySelectorAll('.pay-pill').forEach(pill => pill.classList.remove('active'));
    radioEl.closest('.pay-pill').classList.add('active');
}

document.addEventListener('DOMContentLoaded', function() {
    // SUBMIT PESANAN LANGSUNG KE SISTEM
    document.getElementById('orderForm').addEventListener('submit', function(e) {
        const driverId = document.getElementById('driver_id').value;
        if (!driverId && listDrivers.length > 0) {
            e.preventDefault();
            alert('Harap pilih driver yang tersedia terlebih dahulu!');
            const stepDrv = document.getElementById('stepPilihDriver');
            if (stepDrv) stepDrv.scrollIntoView({ behavior: 'smooth' });
            return;
        }

        const catatan = document.getElementById('catatan').value.trim();
        if (!catatan) {
            e.preventDefault();
            alert('Harap isi apa yang ingin Anda suruh!');
            document.getElementById('catatan').focus();
            return;
        }
        const alamat = document.getElementById('alamat_pelanggan').value.trim();
        if (!alamat) {
            e.preventDefault();
            alert('Harap masukkan lokasi penjemputan / pengantaran!');
            document.getElementById('alamat_pelanggan').focus();
            return;
        }

        const btnSubmit = document.querySelector('.btn-order-now');
        if (btnSubmit) {
            btnSubmit.disabled = true;
            btnSubmit.innerHTML = '<span>Memproses Pesanan...</span>';
        }
    });
});
</script>

</body>
</html>