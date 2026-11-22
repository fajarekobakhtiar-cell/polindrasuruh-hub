<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

// Fungsi Haversine
function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371; // KM
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) * sin($dLat / 2)
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
        * sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadius * $c;
}

$input = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;

// JSON body support
$rawInput = file_get_contents('php://input');
if (!empty($rawInput)) {
    $json = json_decode($rawInput, true);
    if (is_array($json)) {
        $input = array_merge($input, $json);
    }
}

$service = trim($input['service'] ?? 'Food');
$zona = trim($input['zona'] ?? '');
$pickupLat = isset($input['pickup_lat']) ? (float)$input['pickup_lat'] : null;
$pickupLng = isset($input['pickup_lng']) ? (float)$input['pickup_lng'] : null;
$destLat = isset($input['dest_lat']) ? (float)$input['dest_lat'] : null;
$destLng = isset($input['dest_lng']) ? (float)$input['dest_lng'] : null;

// Cek jika layanan adalah Beresin Kost
if (stripos($service, 'kost') !== false || stripos($service, 'beres') !== false) {
    echo json_encode([
        'status' => 'success',
        'service' => 'Beresin Kost',
        'pricing_type' => 'driver_adjusted',
        'message' => 'Tarif disesuaikan oleh driver berdasarkan foto kondisi kamar kosan.',
        'data' => [
            'distance_km' => 0.0,
            'estimated_minutes' => 30,
            'base_fare' => 15000,
            'total_fare' => 15000,
            'formatted_total' => 'Disesuaikan Driver',
            'is_fixed' => false
        ]
    ]);
    exit;
}

// Cek zona cepat untuk Antar Jemput
$zoneRates = [
    'kampus' => ['fare' => 5000, 'km' => 1.5, 'name' => 'Sekitar Kampus & Kosan Lohbener'],
    'laundry' => ['fare' => 7000, 'km' => 2.5, 'name' => 'Laundry & Paket Barang'],
    'kota' => ['fare' => 15000, 'km' => 10.0, 'name' => 'Area Indramayu Kota'],
    'stasiun' => ['fare' => 20000, 'km' => 13.0, 'name' => 'Stasiun Jatibarang']
];

if (stripos($service, 'antar') !== false || stripos($service, 'jemput') !== false) {
    if (!empty($zona) && isset($zoneRates[$zona])) {
        $z = $zoneRates[$zona];
        echo json_encode([
            'status' => 'success',
            'service' => 'Antar Jemput',
            'pricing_type' => 'fixed_zone',
            'zone' => $z['name'],
            'data' => [
                'distance_km' => (float)$z['km'],
                'estimated_minutes' => (int)max(5, round($z['km'] * 2.5)),
                'base_fare' => $z['fare'],
                'total_fare' => $z['fare'],
                'formatted_total' => 'Rp ' . number_format($z['fare'], 0, ',', '.'),
                'is_fixed' => true
            ]
        ]);
        exit;
    }
}

// Hitung berbasis koordinat nyata
if ($pickupLat !== null && $pickupLng !== null && $destLat !== null && $destLng !== null) {
    $distanceKm = round(calculateDistance($pickupLat, $pickupLng, $destLat, $destLng), 2);
    
    // Kecepatan rata-rata motor 30 km/jam = 0.5 km per menit + waktu jemput 3 menit
    $estimatedMinutes = (int)max(3, round(($distanceKm / 30) * 60 + 3));

    $perKmRate = (stripos($service, 'antar') !== false || stripos($service, 'jemput') !== false) ? 2500 : 3000;

    if ($distanceKm <= 2.0) {
        $totalFare = 5000;
        $distanceFare = 0;
    } else {
        $distanceFare = (int)round(($distanceKm - 2.0) * $perKmRate);
        $totalFare = 5000 + $distanceFare;
    }
    $totalFare = max(5000, $totalFare);

    echo json_encode([
        'status' => 'success',
        'service' => $service,
        'pricing_type' => 'realtime_distance',
        'data' => [
            'distance_km' => $distanceKm,
            'estimated_minutes' => $estimatedMinutes,
            'base_fare' => 5000,
            'per_km_rate' => $perKmRate,
            'distance_fare' => $distanceFare,
            'total_fare' => $totalFare,
            'formatted_total' => 'Rp ' . number_format($totalFare, 0, ',', '.'),
            'is_fixed' => false
        ]
    ]);
    exit;
}

// Fallback default
echo json_encode([
    'status' => 'success',
    'service' => $service,
    'pricing_type' => 'default',
    'data' => [
        'distance_km' => 1.5,
        'estimated_minutes' => 5,
        'base_fare' => 5000,
        'total_fare' => 5000,
        'formatted_total' => 'Rp 5.000',
        'is_fixed' => false
    ]
]);

