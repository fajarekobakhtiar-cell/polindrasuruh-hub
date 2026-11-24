<?php
/**
 * Otomatis menolak/membatalkan pesanan yang tidak diambil driver dalam 3 menit (180 detik).
 * Jika ada pesanan yang expired, driver_id dikosongkan agar user wajib memilih ulang driver.
 */
function prosesAutoTolakPesananKadaluarsa(PDO $pdo, int $batasDetik = 180): int
{
    $sql = "UPDATE pesanan
            SET status = 'Dibatalkan',
                driver_id = NULL
            WHERE status IN ('Menunggu', 'Menunggu Driver', 'Menunggu Persetujuan Driver')
              AND TIMESTAMPDIFF(SECOND, created_at, NOW()) >= :batas_detik";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['batas_detik' => $batasDetik]);
    return $stmt->rowCount();
}