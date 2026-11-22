
<?php
date_default_timezone_set('Asia/Jakarta');

$host = "localhost";
$dbname = "polindra_suruh";
$dbUser = "root";
$dbPass = "";

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
    $pdo->exec("SET time_zone = '+07:00'");
} catch (PDOException $e) {
    exit("Koneksi database gagal.");
}
