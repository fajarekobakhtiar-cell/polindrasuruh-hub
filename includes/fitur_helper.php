<?php
/**
 * includes/fitur_helper.php
 *
 * Alur penolakan pesanan:
 *  - Driver menolak, atau tidak merespons dalam 2 menit (120 detik)
 *    => pesanan TIDAK dibatalkan. Statusnya menjadi "Driver Menolak",
 *       driver_id dikosongkan, dan pelanggan diminta memilih driver lain
 *       pada pesanan yang sama.
 *  - Driver yang sudah menolak/timeout dicatat di pesanan_driver_ditolak
 *    sehingga tidak bisa dipilih lagi untuk pesanan itu.
 *  - Saat pelanggan memilih driver baru (pilihUlangDriver), status kembali
 *    "Menunggu Driver" dan hitung mundur 2 menit dimulai lagi.
 */

if (!defined('BATAS_AMBIL_DETIK')) {
    define('BATAS_AMBIL_DETIK', 120); // 2 menit
}

if (!defined('STATUS_DRIVER_MENOLAK')) {
    define('STATUS_DRIVER_MENOLAK', 'Driver Menolak');
}

if (!function_exists('daftarStatusMenunggu')) {
    function daftarStatusMenunggu(): array
    {
        return [
            'Menunggu',
            'Menunggu Driver',
            'Menunggu Persetujuan Driver',
            'Menunggu Penyesuaian Harga',
            'Menunggu Penyesuaian Driver',
        ];
    }
}

/**
 * Memastikan tabel & kolom yang dibutuhkan ada (otomatis, hanya sekali per request).
 * - pesanan.status diubah dari ENUM ke VARCHAR bila perlu
 * - kolom: alasan_tolak_driver, driver_menolak_id, waktu_tolak, diajukan_at
 */
if (!function_exists('pastikanSkemaPenolakan')) {
    function pastikanSkemaPenolakan(PDO $pdo): void
    {
        static $sudah = false;
        if ($sudah) {
            return;
        }
        $sudah = true;

        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS pesanan_driver_ditolak (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                pesanan_id INT NOT NULL,
                driver_user_id INT NOT NULL,
                alasan VARCHAR(100) NOT NULL DEFAULT 'manual_reject',
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY pesanan_driver_unik (pesanan_id, driver_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $stmt = $pdo->query(
                "SELECT COLUMN_NAME, DATA_TYPE
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pesanan'"
            );
            $ada = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ada[$r['COLUMN_NAME']] = strtolower($r['DATA_TYPE']);
            }

            $kolomBaru = [
                'alasan_tolak_driver' => 'VARCHAR(30) NULL',
                'driver_menolak_id'   => 'INT NULL',
                'waktu_tolak'         => 'DATETIME NULL',
                'diajukan_at'         => 'DATETIME NULL',
            ];
            foreach ($kolomBaru as $nama => $definisi) {
                if (!isset($ada[$nama])) {
                    $pdo->exec("ALTER TABLE pesanan ADD COLUMN $nama $definisi");
                }
            }

            if (($ada['status'] ?? '') === 'enum') {
                $pdo->exec("ALTER TABLE pesanan MODIFY status VARCHAR(50) NOT NULL DEFAULT 'Menunggu Driver'");
            }
        } catch (Throwable $e) {
            error_log('pastikanSkemaPenolakan gagal: ' . $e->getMessage());
        }
    }
}

/**
 * Pesanan yang tidak direspons driver dalam 2 menit dikembalikan ke pelanggan
 * (status "Driver Menolak") agar memilih driver lain. Pesanan TIDAK dibatalkan.
 */
if (!function_exists('prosesAutoTolakDuaMenit')) {
    function prosesAutoTolakDuaMenit(PDO $pdo, ?int $pesananId = null): int
    {
        pastikanSkemaPenolakan($pdo);

        $status = daftarStatusMenunggu();
        $in = implode(',', array_fill(0, count($status), '?'));

        $sql = "SELECT id, driver_id
                FROM pesanan
                WHERE status IN ($in)
                  AND TIMESTAMPDIFF(SECOND, COALESCE(diajukan_at, created_at), NOW()) >= " . (int) BATAS_AMBIL_DETIK;
        $params = $status;

        if ($pesananId !== null && $pesananId > 0) {
            $sql .= " AND id = ?";
            $params[] = $pesananId;
        }

        $total = 0;
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $daftar = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($daftar as $row) {
                $idPesanan = (int) $row['id'];
                $driverLama = !empty($row['driver_id']) ? (int) $row['driver_id'] : null;

                if ($driverLama !== null) {
                    $catat = $pdo->prepare(
                        "INSERT IGNORE INTO pesanan_driver_ditolak (pesanan_id, driver_user_id, alasan)
                         VALUES (?, ?, 'timeout')"
                    );
                    $catat->execute([$idPesanan, $driverLama]);
                }

                $upd = $pdo->prepare(
                    "UPDATE pesanan
                     SET status = '" . STATUS_DRIVER_MENOLAK . "',
                         driver_menolak_id = ?,
                         alasan_tolak_driver = 'timeout',
                         waktu_tolak = NOW(),
                         driver_id = NULL
                     WHERE id = ? AND status IN ($in)"
                );
                $upd->execute(array_merge([$driverLama, $idPesanan], $status));
                $total += $upd->rowCount();
            }
        } catch (Throwable $e) {
            error_log('prosesAutoTolakDuaMenit gagal: ' . $e->getMessage());
        }

        return $total;
    }
}

// Alias lama agar kode lain yang masih memanggil nama ini tidak error.
if (!function_exists('prosesAutoTolakTigaMenit')) {
    function prosesAutoTolakTigaMenit(PDO $pdo, ?int $pesananId = null): int
    {
        return prosesAutoTolakDuaMenit($pdo, $pesananId);
    }
}

if (!function_exists('catatPenolakanDriver')) {
    function catatPenolakanDriver(PDO $pdo, int $pesananId, int $driverUserId, string $alasan = 'manual_reject'): array
    {
        if ($pesananId <= 0 || $driverUserId <= 0) {
            return ['ok' => false, 'message' => 'Pesanan atau driver tidak valid.'];
        }

        try {
            pastikanSkemaPenolakan($pdo);

            $stmt = $pdo->prepare("INSERT IGNORE INTO pesanan_driver_ditolak
                (pesanan_id, driver_user_id, alasan)
                VALUES (?, ?, ?)");
            $stmt->execute([$pesananId, $driverUserId, $alasan]);

            return ['ok' => true, 'message' => 'Penolakan pesanan dicatat.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Gagal mencatat penolakan: ' . $e->getMessage()];
        }
    }
}

/**
 * Driver menolak pesanan.
 * - Dicatat di pesanan_driver_ditolak
 * - Jika pesanan ditugaskan ke driver ini, status menjadi "Driver Menolak"
 *   dan pelanggan diminta memilih driver lain (pesanan tetap sama).
 */
if (!function_exists('tolakPesananOlehDriver')) {
    function tolakPesananOlehDriver(PDO $pdo, int $pesananId, int $driverUserId): array
    {
        if ($pesananId <= 0 || $driverUserId <= 0) {
            return ['ok' => false, 'dikembalikan' => false, 'message' => 'Pesanan atau driver tidak valid.'];
        }

        pastikanSkemaPenolakan($pdo);
        catatPenolakanDriver($pdo, $pesananId, $driverUserId, 'manual_reject');

        $status = daftarStatusMenunggu();
        $in = implode(',', array_fill(0, count($status), '?'));

        try {
            $upd = $pdo->prepare(
                "UPDATE pesanan
                 SET status = '" . STATUS_DRIVER_MENOLAK . "',
                     driver_menolak_id = ?,
                     alasan_tolak_driver = 'manual_reject',
                     waktu_tolak = NOW(),
                     driver_id = NULL
                 WHERE id = ? AND driver_id = ? AND status IN ($in)"
            );
            $upd->execute(array_merge([$driverUserId, $pesananId, $driverUserId], $status));

            return [
                'ok' => true,
                'dikembalikan' => $upd->rowCount() > 0,
                'message' => 'Pesanan ditolak.',
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'dikembalikan' => false, 'message' => 'Gagal menolak pesanan: ' . $e->getMessage()];
        }
    }
}

/**
 * Daftar user_id driver yang sudah menolak / timeout untuk pesanan ini.
 * Gunakan untuk menyembunyikan mereka dari daftar pilihan driver pelanggan.
 */
if (!function_exists('ambilDriverDitolak')) {
    function ambilDriverDitolak(PDO $pdo, int $pesananId): array
    {
        try {
            $stmt = $pdo->prepare("SELECT driver_user_id FROM pesanan_driver_ditolak WHERE pesanan_id = ?");
            $stmt->execute([$pesananId]);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            return [];
        }
    }
}

/**
 * Pelanggan memilih driver lain pada pesanan yang sama.
 * Status kembali "Menunggu Driver" dan timer 2 menit dimulai ulang.
 */
if (!function_exists('pilihUlangDriver')) {
    function pilihUlangDriver(PDO $pdo, int $pesananId, int $userId, int $driverUserId): array
    {
        if ($pesananId <= 0 || $userId <= 0 || $driverUserId <= 0) {
            return ['ok' => false, 'message' => 'Data pesanan atau driver tidak valid.'];
        }

        pastikanSkemaPenolakan($pdo);

        try {
            $cek = $pdo->prepare("SELECT status FROM pesanan WHERE id = ? AND user_id = ? LIMIT 1");
            $cek->execute([$pesananId, $userId]);
            $row = $cek->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                return ['ok' => false, 'message' => 'Pesanan tidak ditemukan.'];
            }
            if ($row['status'] !== STATUS_DRIVER_MENOLAK) {
                return ['ok' => false, 'message' => 'Pesanan ini tidak sedang menunggu pemilihan driver baru.'];
            }
            if (in_array($driverUserId, ambilDriverDitolak($pdo, $pesananId), true)) {
                return ['ok' => false, 'message' => 'Driver tersebut sudah menolak pesanan ini. Pilih driver lain.'];
            }

            $drv = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'driver' AND status_aktif = 1 LIMIT 1");
            $drv->execute([$driverUserId]);
            if (!$drv->fetch()) {
                return ['ok' => false, 'message' => 'Driver tidak tersedia atau sedang offline.'];
            }

            $upd = $pdo->prepare(
                "UPDATE pesanan
                 SET driver_id = ?,
                     status = 'Menunggu Driver',
                     diajukan_at = NOW(),
                     driver_menolak_id = NULL,
                     alasan_tolak_driver = NULL,
                     waktu_tolak = NULL
                 WHERE id = ? AND user_id = ? AND status = '" . STATUS_DRIVER_MENOLAK . "'"
            );
            $upd->execute([$driverUserId, $pesananId, $userId]);

            if ($upd->rowCount() === 0) {
                return ['ok' => false, 'message' => 'Pesanan gagal diperbarui. Coba lagi.'];
            }

            return ['ok' => true, 'message' => 'Pesanan dikirim ke driver baru. Tunggu konfirmasi driver.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Gagal memilih driver: ' . $e->getMessage()];
        }
    }
}

/**
 * Info penolakan untuk ditampilkan ke pelanggan (null jika pesanan tidak
 * sedang berstatus "Driver Menolak").
 */
if (!function_exists('ambilInfoPenolakanPesanan')) {
    function ambilInfoPenolakanPesanan(PDO $pdo, int $pesananId, ?int $userId = null): ?array
    {
        try {
            $sql = "SELECT p.status, p.alasan_tolak_driver, p.driver_menolak_id, p.waktu_tolak,
                           u.nama AS nama_driver
                    FROM pesanan p
                    LEFT JOIN users u ON u.id = p.driver_menolak_id
                    WHERE p.id = ?";
            $params = [$pesananId];
            if ($userId !== null) {
                $sql .= " AND p.user_id = ?";
                $params[] = $userId;
            }
            $sql .= " LIMIT 1";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row || $row['status'] !== STATUS_DRIVER_MENOLAK) {
                return null;
            }

            $nama = trim((string) ($row['nama_driver'] ?? ''));
            $timeout = ($row['alasan_tolak_driver'] ?? '') === 'timeout';

            if ($nama === '') {
                $pesan = 'Tidak ada driver yang merespons dalam 2 menit. Silakan pilih driver lain.';
            } elseif ($timeout) {
                $pesan = $nama . ' tidak merespons dalam 2 menit. Silakan pilih driver lain.';
            } else {
                $pesan = $nama . ' menolak pesanan Anda. Silakan pilih driver lain.';
            }

            return [
                'nama_driver' => $nama,
                'timeout' => $timeout,
                'waktu_tolak' => $row['waktu_tolak'],
                'pesan' => $pesan,
            ];
        } catch (Throwable $e) {
            return null;
        }
    }
}

/**
 * HTML banner notifikasi untuk form pesanan pelanggan.
 * Kosong ("") jika pesanan tidak sedang menunggu pemilihan driver baru.
 */
if (!function_exists('bannerDriverMenolakHtml')) {
    function bannerDriverMenolakHtml(PDO $pdo, int $pesananId, ?int $userId = null): string
    {
        $info = ambilInfoPenolakanPesanan($pdo, $pesananId, $userId);
        if ($info === null) {
            return '';
        }

        return '<div role="alert" style="background:#fef2f2; border:1.5px solid #fecaca; border-left:5px solid #ef4444; '
            . 'color:#991b1b; padding:14px 16px; border-radius:12px; margin-bottom:16px; font-size:14px; line-height:1.5;">'
            . '<strong style="display:block; margin-bottom:2px;">Driver menolak pesananmu</strong>'
            . htmlspecialchars($info['pesan'])
            . '</div>';
    }
}

if (!function_exists('uploadBuktiAntarWajib')) {
    function uploadBuktiAntarWajib(array $file, int $pesananId, int $driverUserId): array
    {
        if ($pesananId <= 0 || $driverUserId <= 0) {
            return ['ok' => false, 'message' => 'Pesanan atau driver tidak valid.'];
        }

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => 'Bukti antar wajib diunggah.'];
        }

        $tmpName = $file['tmp_name'] ?? '';
        $originalName = $file['name'] ?? '';
        $size = (int) ($file['size'] ?? 0);

        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            return ['ok' => false, 'message' => 'File bukti antar tidak valid.'];
        }
        if ($size <= 0 || $size > 5 * 1024 * 1024) {
            return ['ok' => false, 'message' => 'Ukuran bukti antar maksimal 5 MB.'];
        }

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
        if (!in_array($ext, $allowedExt, true)) {
            return ['ok' => false, 'message' => 'Format bukti harus JPG, PNG, atau WEBP.'];
        }

        $mime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = finfo_file($finfo, $tmpName);
                finfo_close($finfo);
            }
        } elseif (function_exists('mime_content_type')) {
            $mime = mime_content_type($tmpName);
        }

        $allowedMime = ['image/jpeg', 'image/png', 'image/webp', 'image/pjpeg'];
        if ($mime !== '' && !in_array($mime, $allowedMime, true)) {
            return ['ok' => false, 'message' => 'Jenis file bukti antar tidak didukung.'];
        }

        $uploadDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'bukti_antar' . DIRECTORY_SEPARATOR;
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
            return ['ok' => false, 'message' => 'Folder bukti antar tidak dapat dibuat.'];
        }

        $filename = 'bukti_' . $pesananId . '_' . $driverUserId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!move_uploaded_file($tmpName, $uploadDir . $filename)) {
            return ['ok' => false, 'message' => 'Gagal mengunggah bukti antar.'];
        }

        return ['ok' => true, 'filename' => $filename, 'message' => 'Bukti antar berhasil diunggah.'];
    }
}

if (!function_exists('selesaikanPesananDenganBukti')) {
    function selesaikanPesananDenganBukti(PDO $pdo, int $pesananId, int $driverUserId, string $filename): array
    {
        if ($pesananId <= 0 || $driverUserId <= 0 || trim($filename) === '') {
            return ['ok' => false, 'message' => 'Data penyelesaian pesanan tidak lengkap.'];
        }

        try {
            $pdo->beginTransaction();

            $cek = $pdo->prepare("SELECT id, status FROM pesanan WHERE id = :id AND driver_id = :driver_id LIMIT 1");
            $cek->execute(['id' => $pesananId, 'driver_id' => $driverUserId]);
            $pesanan = $cek->fetch(PDO::FETCH_ASSOC);

            if (!$pesanan) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => 'Pesanan tidak ditemukan atau tidak ditugaskan kepada Anda.'];
            }

            $update = $pdo->prepare("UPDATE pesanan
                SET status = 'Selesai', bukti_antar = :bukti
                WHERE id = :id AND driver_id = :driver_id");
            $update->execute([
                'bukti' => $filename,
                'id' => $pesananId,
                'driver_id' => $driverUserId
            ]);

            $pdo->commit();

            require_once __DIR__ . DIRECTORY_SEPARATOR . 'wallet_helper.php';
            if (function_exists('selesaikanPesananDanKreditSaldo')) {
                selesaikanPesananDanKreditSaldo($pdo, $pesananId, $driverUserId);
            }

            return ['ok' => true, 'message' => 'Pesanan berhasil diselesaikan.'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'message' => 'Gagal menyelesaikan pesanan: ' . $e->getMessage()];
        }
    }
}

if (!function_exists('simpanRatingDriver')) {
    function simpanRatingDriver(PDO $pdo, int $pesananId, int $userId, int $rating, string $ulasan = ''): array
    {
        if ($pesananId <= 0) {
            return ['ok' => false, 'message' => 'Pesanan tidak valid.'];
        }
        if ($userId <= 0) {
            return ['ok' => false, 'message' => 'Pengguna tidak valid.'];
        }
        if ($rating < 1 || $rating > 5) {
            return ['ok' => false, 'message' => 'Rating harus dipilih dari 1 sampai 5.'];
        }

        try {
            $kolomRating = [];
            $schemaRating = $pdo->query("SHOW COLUMNS FROM driver_ratings");
            foreach ($schemaRating->fetchAll(PDO::FETCH_ASSOC) as $kolom) {
                $kolomRating[strtolower((string) $kolom['Field'])] = true;
            }

            if (!isset($kolomRating['driver_id'])) {
                if (isset($kolomRating['driver_user_id'])) {
                    $kolomDriverRating = 'driver_user_id';
                } else {
                    $pdo->exec("ALTER TABLE driver_ratings ADD COLUMN driver_id INT NOT NULL DEFAULT 0 AFTER user_id");
                    $kolomDriverRating = 'driver_id';
                }
            } else {
                $kolomDriverRating = 'driver_id';
            }

            $cekPesanan = $pdo->prepare("SELECT id, user_id, driver_id, status
                FROM pesanan
                WHERE id = ? AND user_id = ? LIMIT 1");
            $cekPesanan->execute([$pesananId, $userId]);
            $pesanan = $cekPesanan->fetch(PDO::FETCH_ASSOC);

            if (!$pesanan) {
                return ['ok' => false, 'message' => 'Pesanan tidak ditemukan atau bukan milik Anda.'];
            }
            if (($pesanan['status'] ?? '') !== 'Selesai') {
                return ['ok' => false, 'message' => 'Rating hanya dapat diberikan setelah pesanan selesai.'];
            }

            $driverId = (int) ($pesanan['driver_id'] ?? 0);
            if ($driverId <= 0) {
                return ['ok' => false, 'message' => 'Data driver pada pesanan tidak ditemukan.'];
            }

            $cekRating = $pdo->prepare("SELECT id FROM driver_ratings WHERE pesanan_id = ? LIMIT 1");
            $cekRating->execute([$pesananId]);
            if ($cekRating->fetch()) {
                return ['ok' => false, 'message' => 'Pesanan ini sudah diberi rating.'];
            }

            $ulasan = trim($ulasan);
            $ulasan = function_exists('mb_substr') ? mb_substr($ulasan, 0, 1000) : substr($ulasan, 0, 1000);
            $simpan = $pdo->prepare("INSERT INTO driver_ratings
                (pesanan_id, user_id, $kolomDriverRating, rating, komentar)
                VALUES (?, ?, ?, ?, ?)");
            $simpan->execute([$pesananId, $userId, $driverId, $rating, $ulasan !== '' ? $ulasan : null]);

            return ['ok' => true, 'message' => 'Rating berhasil disimpan.'];
        } catch (Throwable $e) {
            error_log('simpanRatingDriver gagal: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Gagal menyimpan rating: ' . $e->getMessage()];
        }
    }
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS driver_ratings (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        pesanan_id INT NOT NULL,
        user_id INT NOT NULL,
        driver_id INT NOT NULL,
        rating TINYINT UNSIGNED NOT NULL,
        komentar TEXT NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY pesanan_rating_unik (pesanan_id),
        KEY driver_rating_idx (driver_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {
    error_log('create driver_ratings table failed: ' . $e->getMessage());
}