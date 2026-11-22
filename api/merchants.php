<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');

require_once dirname(__DIR__) . '/includes/data_tempat_indramayu.php';

$daftarTempat = getDaftarTempatIndramayu();

$category = trim($_GET['category'] ?? '');
$search = trim(strtolower($_GET['q'] ?? ''));

$results = [];

foreach ($daftarTempat as $m) {
    if ($category !== '' && $category !== 'all' && $m['kategori'] !== $category) {
        continue;
    }

    if ($search !== '') {
        $searchable = strtolower($m['nama'] . ' ' . $m['alamat'] . ' ' . $m['area'] . ' ' . $m['deskripsi']);
        if (strpos($searchable, $search) === false) {
            continue;
        }
    }

    $results[] = [
        'id' => $m['id'],
        'name' => $m['nama'],
        'category' => $m['kategori'],
        'category_label' => $m['label_kategori'],
        'area' => $m['area'],
        'address' => $m['alamat'],
        'lat' => (float)$m['lat'],
        'lng' => (float)$m['lng'],
        'icon' => $m['icon'],
        'description' => $m['deskripsi'],
        'maps_url' => $m['maps_url']
    ];
}

echo json_encode([
    'status' => 'success',
    'total' => count($results),
    'filters' => [
        'category' => $category ?: 'all',
        'query' => $search
    ],
    'data' => $results
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

