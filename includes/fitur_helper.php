<?php
/**
 * includes/fitur_helper.php
 * Helper untuk:
 * - Auto-tolak pesanan per driver jika tidak diambil dalam 2 menit (120 detik)
 * - Upload wajib foto bukti pengantaran untuk semua layanan
 * - Rating driver (1-5) + komentar teks
 */

require_once __DIR__ . '/wallet_helper.php';

/**
 * Proses auto-tolak pesanan yang melewati 120 detik tanpa diambil driver.
 * Pesanan tidak dibatalkan total, melainkan dilepas dari driver tersebut
 * dan dicatat di pesanan_driver_ditolak agar user bisa memilih driver lain.
 */
function prosesAutoTolakDuaMenit(PDO $pdo, ?int $orderId = null): int
{
    $sql = "
        SELECT id, driver_id, created_at
        FROM pesanan
        WHERE status IN ('Menunggu Driver', 'Menunggu Persetujuan Driver')
          AND driver_id IS NOT NULL
          AND TIMESTAMPDIFF(SECOND, created_at, NOW()) >= 120
    ";
    $params = [];

    if ($orderId !== null && $orderId > 0) {
        $sql .= " AND id = :order_id";
        $params['order_id'] = $orderId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $diproses = 0;

    foreach ($rows as $row) {
        $pId = (int)$row['id'];
        $drvUserId = (int)$row['driver_id'];

        $pdo->beginTransaction();
        try {
            $stmtTolak = $pdo->prepare("
                INSERT IGNORE INTO pesanan_driver_ditolak (pesanan_id, driver_user_id, alasan, created_at)
                VALUES (:pesanan_id, :driver_user_id, 'timeout_2_menit', NOW())
            ");
            $stmtTolak->execute([
                'pesanan_id' => $pId,
                'driver_user_id' => $drvUserId
            ]);

            $stmtLepas = $pdo->prepare("
                UPDATE pesanan
                SET driver_id = NULL, status = 'Menunggu Driver'
                WHERE id = :id
                  AND driver_id = :driver_id
                  AND status IN ('Menunggu Driver', 'Menunggu Persetujuan Driver')
            ");
            $stmtLepas->execute([
                'id' => $pId,
                'driver_id' => $drvUserId
            ]);

            if ($stmtLepas->rowCount() > 0) {
                $diproses++;
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    return $diproses;
}

/**
 * Catat penolakan manual oleh driver untuk pesanan tertentu
 */
function catatPenolakanDriver(PDO $pdo, int $pesananId, int $driverUserId, string $alasan = 'manual_reject'): bool
{
    $stmtCatat = $pdo->prepare("
        INSERT IGNORE INTO pesanan_driver_ditolak (pesanan_id, driver_user_id, alasan, created_at)
        VALUES (:pesanan_id, :driver_user_id, :alasan, NOW())
    ");
    $stmtCatat->execute([
        'pesanan_id' => $pesananId,
        'driver_user_id' => $driverUserId,
        'alasan' => $alasan
    ]);

    $stmtLepas = $pdo->prepare("
        UPDATE pesanan
        SET driver_id = NULL, status = 'Menunggu Driver'
        WHERE id = :id AND driver_id = :driver_id
    ");
    $stmtLepas->execute([
        'id' => $pesananId,
        'driver_id' => $driverUserId
    ]);

    return true;
}

/**
 * Validasi & upload foto bukti pengantaran (WAJIB untuk semua layanan)
 */
function uploadBuktiAntarWajib(array $fileInput, int $pesananId, int $driverUserId): array
{
    if (empty($fileInput) || ($fileInput['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return [
            'ok' => false,
            'message' => 'Foto bukti pengantaran wajib diunggah untuk menyelesaikan pesanan.'
        ];
    }

    $fileTmp = (string)$fileInput['tmp_name'];
    $fileName = (string)$fileInput['name'];
    $fileSize = (int)$fileInput['size'];

    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($ext, $allowedExt, true)) {
        return [
            'ok' => false,
            'message' => 'Format foto bukti pengantaran harus JPG, JPEG, PNG, atau WEBP.'
        ];
    }

    if ($fileSize <= 0 || $fileSize > 5 * 1024 * 1024) {
        return [
            'ok' => false,
            'message' => 'Ukuran foto bukti pengantaran maksimal 5 MB.'
        ];
    }

    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = (string)finfo_file($finfo, $fileTmp);
            finfo_close($finfo);
        }
    } elseif (function_exists('mime_content_type')) {
        $mime = (string)mime_content_type($fileTmp);
    }

    $allowedMime = ['image/jpeg', 'image/png', 'image/webp', 'image/pjpeg'];
    if ($mime !== '' && !in_array($mime, $allowedMime, true)) {
        return [
            'ok' => false,
            'message' => 'Tipe file gambar tidak valid.'
        ];
    }

    $uploadDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'bukti_antar' . DIRECTORY_SEPARATOR;
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $newFileName = 'antar_' . $pesananId . '_d' . $driverUserId . '_' . time() . '.' . $ext;
    $destPath = $uploadDir . $newFileName;

    if (!move_uploaded_file($fileTmp, $destPath)) {
        return [
            'ok' => false,
            'message' => 'Gagal menyimpan file bukti pengantaran ke server.'
        ];
    }

    return [
        'ok' => true,
        'filename' => $newFileName
    ];
}

/**
 * Selesaikan pesanan dengan bukti pengantaran wajib & kredit saldo dompet driver
 */
function selesaikanPesananDenganBukti(PDO $pdo, int $pesananId, int $driverUserId, string $buktiAntarFile): array
{
    if (trim($buktiAntarFile) === '') {
        return ['ok' => false, 'message' => 'Bukti pengantaran wajib diisi.'];
    }

    $stmt = $pdo->prepare("SELECT * FROM pesanan WHERE id = ? AND driver_id = ?");
    $stmt->execute([$pesananId, $driverUserId]);
    $pesanan = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pesanan) {
        return ['ok' => false, 'message' => 'Pesanan tidak ditemukan atau bukan milik Anda.'];
    }

    $stmtDrv = $pdo->prepare("SELECT id, saldo FROM drivers WHERE user_id = ?");
    $stmtDrv->execute([$driverUserId]);
    $driverData = $stmtDrv->fetch(PDO::FETCH_ASSOC);

    if (!$driverData) {
        return ['ok' => false, 'message' => 'Profil driver tidak ditemukan.'];
    }

    $pdo->beginTransaction();
    try {
        $stmtUp = $pdo->prepare("
            UPDATE pesanan
            SET status = 'Selesai',
                bukti_antar = :bukti_antar,
                waktu_antar = NOW()
            WHERE id = :id AND driver_id = :driver_id
        ");
        $stmtUp->execute([
            'bukti_antar' => $buktiAntarFile,
            'id' => $pesananId,
            'driver_id' => $driverUserId
        ]);

        $driverDbId = (int)$driverData['id'];
        $saldoSaatIni = (int)$driverData['saldo'];
        $metodeBayar = strtolower((string)($pesanan['metode_bayar'] ?? 'qris'));
        $totalTarif = (int)($pesanan['total_tarif'] ?? 0);

        if ($metodeBayar === 'qris' && $totalTarif > 0) {
            $stmtCek = $pdo->prepare("SELECT COUNT(*) FROM dompet_transaksi WHERE pesanan_id = ? AND tipe = 'masuk'");
            $stmtCek->execute([$pesananId]);
            if ((int)$stmtCek->fetchColumn() === 0) {
                $saldoAkhir = $saldoSaatIni + $totalTarif;

                $stmtSaldo = $pdo->prepare("UPDATE drivers SET saldo = saldo + ? WHERE id = ?");
                $stmtSaldo->execute([$totalTarif, $driverDbId]);

                $keterangan = "Hasil orderan Pesanan #" . $pesananId . " (" . $pesanan['layanan'] . ")";
                $stmtMutasi = $pdo->prepare("
                    INSERT INTO dompet_transaksi (driver_id, pesanan_id, tipe, nominal, saldo_akhir, keterangan)
                    VALUES (?, ?, 'masuk', ?, ?, ?)
                ");
                $stmtMutasi->execute([$driverDbId, $pesananId, $totalTarif, $saldoAkhir, $keterangan]);
            }
        }

        $pdo->commit();
        return ['ok' => true, 'message' => 'Pesanan selesai dengan bukti pengantaran tersimpan.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'message' => 'Gagal menyelesaikan pesanan: ' . $e->getMessage()];
    }
}

/**
 * Simpan rating (1-5) dan komentar teks untuk driver pada pesanan yang sudah Selesai
 */
function simpanRatingDriver(PDO $pdo, int $pesananId, int $userId, int $rating, string $komentar): array
{
    if ($rating < 1 || $rating > 5) {
        return ['ok' => false, 'message' => 'Rating harus bernilai 1 sampai 5 bintang.'];
    }

    $stmtOrder = $pdo->prepare("
        SELECT id, user_id, driver_id, status
        FROM pesanan
        WHERE id = ? AND user_id = ?
        LIMIT 1
    ");
    $stmtOrder->execute([$pesananId, $userId]);
    $order = $stmtOrder->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        return ['ok' => false, 'message' => 'Pesanan tidak ditemukan.'];
    }

    if ($order['status'] !== 'Selesai') {
        return ['ok' => false, 'message' => 'Rating hanya dapat diberikan setelah pesanan berstatus Selesai.'];
    }

    $driverUserId = (int)($order['driver_id'] ?? 0);
    if ($driverUserId <= 0) {
        return ['ok' => false, 'message' => 'Data driver pada pesanan ini tidak tersedia.'];
    }

    $komentarClean = trim($komentar);

    $pdo->beginTransaction();
    try {
        $stmtInsert = $pdo->prepare("
            INSERT INTO driver_ratings (pesanan_id, user_id, driver_user_id, rating, komentar, created_at)
            VALUES (:pesanan_id, :user_id, :driver_user_id, :rating, :komentar, NOW())
            ON DUPLICATE KEY UPDATE
                rating = VALUES(rating),
                komentar = VALUES(komentar),
                created_at = NOW()
        ");
        $stmtInsert->execute([
            'pesanan_id' => $pesananId,
            'user_id' => $userId,
            'driver_user_id' => $driverUserId,
            'rating' => $rating,
            'komentar' => $komentarClean !== '' ? $komentarClean : null
        ]);

        // Hitung ulang rata-rata & jumlah rating driver
        $stmtAgg = $pdo->prepare("
            SELECT COALESCE(ROUND(AVG(rating), 2), 0) AS rata_rata, COUNT(*) AS total_ulasan
            FROM driver_ratings
            WHERE driver_user_id = :driver_user_id
        ");
        $stmtAgg->execute(['driver_user_id' => $driverUserId]);
        $agg = $stmtAgg->fetch(PDO::FETCH_ASSOC);

        $rataBaru = (float)($agg['rata_rata'] ?? 0);
        $totalBaru = (int)($agg['total_ulasan'] ?? 0);

        $stmtDrvUp = $pdo->prepare("
            UPDATE drivers
            SET rating_rata = :rata, jumlah_rating = :jumlah
            WHERE user_id = :driver_user_id
        ");
        $stmtDrvUp->execute([
            'rata' => $rataBaru,
            'jumlah' => $totalBaru,
            'driver_user_id' => $driverUserId
        ]);

        $pdo->commit();
        return ['ok' => true, 'message' => 'Terima kasih! Rating dan komentar Anda telah tersimpan.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'message' => 'Gagal menyimpan rating: ' . $e->getMessage()];
    }
}