<?php
/**
 * includes/wallet_helper.php
 * Sistem Penghitungan Tarif Jarak & Dompet Saldo Driver Polindra Suruh
 */

/**
 * Hitung jarak antara 2 koordinat bumi menggunakan Haversine Formula (dalam KM)
 */
function hitungJarakHaversine($lat1, $lon1, $lat2, $lon2): float {
    $lat1 = (float)$lat1;
    $lon1 = (float)$lon1;
    $lat2 = (float)$lat2;
    $lon2 = (float)$lon2;

    if ($lat1 == 0 || $lon1 == 0 || $lat2 == 0 || $lon2 == 0) {
        return 1.0;
    }

    $earthRadius = 6371; // km
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);

    $a = sin($dLat / 2) * sin($dLat / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    $dist = $earthRadius * $c;

    return round(max(0.5, $dist), 2);
}

/**
 * Cek apakah layanan termasuk dalam kategori tarif otomatis by jarak
 */
function isLayananTarifOtomatis(string $layananNama): bool {
    $layanan = strtolower($layananNama);
    if (strpos($layanan, 'food') !== false || strpos($layanan, 'makan') !== false) {
        return true;
    }
    if (strpos($layanan, 'galon') !== false) {
        return true;
    }
    if (strpos($layanan, 'print') !== false || strpos($layanan, 'fotokopi') !== false || strpos($layanan, 'fc') !== false) {
        return true;
    }
    if (strpos($layanan, 'antar') !== false || strpos($layanan, 'jemput') !== false) {
        return true;
    }
    if (strpos($layanan, 'suruh') !== false || strpos($layanan, 'apa saja') !== false) {
        return true;
    }
    return false;
}

/**
 * Hitung jarak dan tarif otomatis untuk layanan yang berlaku
 */
function hitungTarifOtomatis(PDO $pdo, string $layananNama, $pickupLat, $pickupLng, $destLat, $destLng): array {
    if (!isLayananTarifOtomatis($layananNama)) {
        return [
            'is_otomatis' => false,
            'jarak_km'    => null,
            'total_tarif' => null,
            'keterangan'  => 'Tarif disepakati bersama driver via chat/negosiasi.'
        ];
    }

    // Default konfigurasi tarif (1 km pertama = Rp 5.000)
    $tarifDasar = 5000;
    $jarakDasar = 1.0;
    $tarifPerKm = 2500;
    $biayaGalon = 2000;

    try {
        $stmtCfg = $pdo->query("SELECT * FROM pengaturan_sistem WHERE id = 1 LIMIT 1");
        if ($cfg = $stmtCfg->fetch(PDO::FETCH_ASSOC)) {
            $tarifDasar = (int) $cfg['tarif_dasar'];
            $jarakDasar = (float) $cfg['jarak_dasar_km'];
            $tarifPerKm = (int) $cfg['tarif_per_km'];
            $biayaGalon = (int) $cfg['biaya_galon'];
        }
    } catch (Exception $e) {}

    $jarak = hitungJarakHaversine($pickupLat, $pickupLng, $destLat, $destLng);

    // Hitung tarif dasar
    $tarif = $tarifDasar;
    if ($jarak > $jarakDasar) {
        $extraKm = $jarak - $jarakDasar;
        $tarif += (int) ceil($extraKm * $tarifPerKm);
    }

    // Biaya tambahan khusus layanan tertentu
    $layanan = strtolower($layananNama);
    if (strpos($layanan, 'galon') !== false) {
        $tarif += $biayaGalon;
    }

    // Pembulatan ke ratusan terdekat (misal: 500)
    $tarif = (int) (ceil($tarif / 500) * 500);

    return [
        'is_otomatis' => true,
        'jarak_km'    => $jarak,
        'total_tarif' => $tarif,
        'keterangan'  => 'Tarif otomatis berdasarkan jarak GPS (' . $jarak . ' km).'
    ];
}

/**
 * Selesaikan pesanan dan kredit saldo ke dompet driver jika bayar via QRIS
 */
function selesaikanPesananDanKreditSaldo(PDO $pdo, int $pesananId, int $driverUserId): bool {
    // 1. Ambil data pesanan
    $stmt = $pdo->prepare("SELECT * FROM pesanan WHERE id = ? AND driver_id = ?");
    $stmt->execute([$pesananId, $driverUserId]);
    $pesanan = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pesanan) {
        return false;
    }

    // 2. Ambil data driver
    $stmtDrv = $pdo->prepare("SELECT id, saldo FROM drivers WHERE user_id = ?");
    $stmtDrv->execute([$driverUserId]);
    $driverData = $stmtDrv->fetch(PDO::FETCH_ASSOC);

    if (!$driverData) {
        return false;
    }

    $driverDbId   = (int) $driverData['id'];
    $saldoSaatIni = (int) $driverData['saldo'];
    $metodeBayar  = strtolower($pesanan['metode_bayar'] ?? 'qris');
    $totalTarif   = (int) ($pesanan['total_tarif'] ?? 0);

    // 3. Update status pesanan jadi Selesai
    $stmtUp = $pdo->prepare("UPDATE pesanan SET status = 'Selesai' WHERE id = ?");
    $stmtUp->execute([$pesananId]);

    // 4. Jika metode bayar QRIS dan ada tarif, kreditkan ke dompet driver
    if ($metodeBayar === 'qris' && $totalTarif > 0) {
        // Cek agar tidak kredit ganda
        $stmtCek = $pdo->prepare("SELECT COUNT(*) FROM dompet_transaksi WHERE pesanan_id = ? AND tipe = 'masuk'");
        $stmtCek->execute([$pesananId]);
        if ($stmtCek->fetchColumn() == 0) {
            $saldoAkhir = $saldoSaatIni + $totalTarif;

            // Tambah saldo driver
            $stmtSaldo = $pdo->prepare("UPDATE drivers SET saldo = saldo + ? WHERE id = ?");
            $stmtSaldo->execute([$totalTarif, $driverDbId]);

            // Catat mutasi masuk
            $keterangan = "Hasil orderan Pesanan #" . $pesananId . " (" . $pesanan['layanan'] . ")";
            $stmtMutasi = $pdo->prepare("
                INSERT INTO dompet_transaksi (driver_id, pesanan_id, tipe, nominal, saldo_akhir, keterangan)
                VALUES (?, ?, 'masuk', ?, ?, ?)
            ");
            $stmtMutasi->execute([$driverDbId, $pesananId, $totalTarif, $saldoAkhir, $keterangan]);
        }
    }

    return true;
}

/**
 * Pengajuan Penarikan Dana (Withdraw) oleh Driver
 */
function ajukanPenarikanDana(PDO $pdo, int $driverUserId, int $nominal, string $bankTujuan, string $noRekening, string $atasNama): array {
    if ($nominal < 10000) {
        return ['status' => false, 'pesan' => 'Nominal penarikan minimal Rp 10.000.'];
    }

    $stmtDrv = $pdo->prepare("SELECT id, saldo FROM drivers WHERE user_id = ?");
    $stmtDrv->execute([$driverUserId]);
    $driverData = $stmtDrv->fetch(PDO::FETCH_ASSOC);

    if (!$driverData) {
        return ['status' => false, 'pesan' => 'Data driver tidak ditemukan.'];
    }

    $driverDbId = (int) $driverData['id'];
    $saldoSaatIni = (int) $driverData['saldo'];

    if ($saldoSaatIni < $nominal) {
        return ['status' => false, 'pesan' => 'Saldo dompet tidak mencukupi. Saldo Anda: Rp ' . number_format($saldoSaatIni, 0, ',', '.')];
    }

    // Potong saldo driver
    $saldoAkhir = $saldoSaatIni - $nominal;
    $stmtPotong = $pdo->prepare("UPDATE drivers SET saldo = saldo - ?, nama_bank = ?, nomor_rekening = ?, atas_nama = ? WHERE id = ?");
    $stmtPotong->execute([$nominal, $bankTujuan, $noRekening, $atasNama, $driverDbId]);

    // Buat pengajuan penarikan
    $stmtTarik = $pdo->prepare("
        INSERT INTO penarikan_dana (driver_id, nominal, bank_tujuan, nomor_rekening, atas_nama, status)
        VALUES (?, ?, ?, ?, ?, 'Menunggu')
    ");
    $stmtTarik->execute([$driverDbId, $nominal, $bankTujuan, $noRekening, $atasNama]);

    // Catat mutasi keluar
    $keterangan = "Pengajuan penarikan dana ke " . $bankTujuan . " (" . $noRekening . " a.n " . $atasNama . ")";
    $stmtMutasi = $pdo->prepare("
        INSERT INTO dompet_transaksi (driver_id, pesanan_id, tipe, nominal, saldo_akhir, keterangan)
        VALUES (?, NULL, 'keluar', ?, ?, ?)
    ");
    $stmtMutasi->execute([$driverDbId, $nominal, $saldoAkhir, $keterangan]);

    return ['status' => true, 'pesan' => 'Pengajuan penarikan dana sebesar Rp ' . number_format($nominal, 0, ',', '.') . ' berhasil dikirim ke Admin.'];
}
