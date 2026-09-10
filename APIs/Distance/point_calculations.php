<?php
// Copyright 2026 J Code
// SPDX-License-Identifier: Apache-2.0
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$logDir = __DIR__ . '/private';
protectDir($logDir);
ini_set('error_log', $logDir . '/error.log');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: no-referrer');

set_time_limit(45);

if (($_SERVER['CONTENT_LENGTH'] ?? 0) > 4096) {
    http_response_code(413);
    echo json_encode(['error' => 'Request Entity Too Large']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST'])) {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

function checkRateLimit($ip) {
    $limit = 100;
    $window = 60;
    $file = sys_get_temp_dir() . '/rate_' . md5($ip) . '.json';

    $fp = @fopen($file, 'c+');
    if (!$fp) {
        error_log('checkRateLimit: unable to open ' . $file);
        return false;
    }
    flock($fp, LOCK_EX);

    $content = stream_get_contents($fp);
    $data = json_decode($content, true);
    if (!is_array($data) || !isset($data['timestamps'])) {
        $data = ['timestamps' => []];
    }

    $now = time();
    $data['timestamps'] = array_values(array_filter($data['timestamps'], function ($t) use ($now, $window) {
        return $t > ($now - $window);
    }));

    $allowed = count($data['timestamps']) < $limit;
    if ($allowed) {
        $data['timestamps'][] = $now;
    }

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return $allowed;
}

$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!checkRateLimit($clientIp)) {
    http_response_code(429);
    echo json_encode(['error' => 'Too Many Requests']);
    exit;
}

function protectDir($dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $htaccess = rtrim($dir, '/') . '/.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "Require all denied\nDeny from all\n");
    }
}

function cleanupOldImages($dir, $maxAgeSeconds) {
    if (!is_dir($dir)) return;
    $files = glob($dir . '/*.png');
    $now = time();
    foreach ($files as $file) {
        if ($now - filemtime($file) > $maxAgeSeconds) {
            @unlink($file);
        }
    }
}

function cleanupOldCacheFiles($globPattern, $maxAgeSeconds) {
    $files = glob(sys_get_temp_dir() . '/' . $globPattern);
    if (!$files) return;
    $now = time();
    foreach ($files as $file) {
        if ($now - filemtime($file) > $maxAgeSeconds) {
            @unlink($file);
        }
    }
}

$tmpImagesDir = __DIR__ . '/tmp_images';
protectDir($tmpImagesDir);
cleanupOldImages($tmpImagesDir, 80);
cleanupOldCacheFiles('geocache_*.json', 3600);
cleanupOldCacheFiles('rate_*.json', 3600);

$params = ($method === 'GET') ? $_GET : $_POST;

foreach (['origin', 'destination', 'unit', 'time', 'picture'] as $key) {
    if (isset($params[$key]) && !is_string($params[$key])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid parameter type']);
        exit;
    }
}

$maxParamLength = 100;
if (
    (isset($params['origin']) && strlen($params['origin']) > $maxParamLength) ||
    (isset($params['destination']) && strlen($params['destination']) > $maxParamLength) ||
    (isset($params['unit']) && strlen($params['unit']) > 20) ||
    (isset($params['time']) && strlen($params['time']) > 20) ||
    (isset($params['picture']) && strlen($params['picture']) > 5)
) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid parameter length']);
    exit;
}

$origin = $params['origin'] ?? null;
$destination = $params['destination'] ?? null;

$unit = strtolower($params['unit'] ?? 'automatic');
$timeUnitParam = strtolower($params['time'] ?? 'automatic');
$picture = isset($params['picture']) && strtolower($params['picture']) === 'true';

if ($origin === null || $destination === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing origin or destination']);
    exit;
}

$originCoords = parseCoordinates($origin);
$destCoords = parseCoordinates($destination);
if ($originCoords === false || $destCoords === false) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid coordinates format. Use "lat,lng"']);
    exit;
}

$unitFactors = [
    'km' => 1, 'm' => 1000, 'cm' => 100000, 'mm' => 1000000,
    'megameter' => 0.001, 'gigameter' => 0.000001, 'terameter' => 0.000000001,
    'meter' => 1000, 'centimeter' => 100000, 'millimeter' => 1000000,
    'megam' => 0.001, 'gigam' => 0.000001, 'teram' => 0.000000001,
    'mile' => 0.6213711922, 'mi' => 0.6213711922, 'miles' => 0.6213711922,
    'yard' => 1093.6132983, 'yd' => 1093.6132983, 'yards' => 1093.6132983,
    'foot' => 3280.8398950, 'feet' => 3280.8398950, 'ft' => 3280.8398950,
    'inch' => 39370.0787402, 'inches' => 39370.0787402, 'in' => 39370.0787402,
    'nauticalmile' => 0.5399568035, 'nauticalmiles' => 0.5399568035, 'nmi' => 0.5399568035,
];
if ($unit !== 'automatic' && !isset($unitFactors[$unit])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid distance unit']);
    exit;
}

$timeUnitFactors = [
    'ms' => 3600000, 'millisecond' => 3600000, 'milliseconds' => 3600000,
    's' => 3600, 'second' => 3600, 'seconds' => 3600,
    'min' => 60, 'minute' => 60, 'minutes' => 60,
    'h' => 1, 'hour' => 1, 'hours' => 1,
    'day' => 1/24, 'days' => 1/24,
    'week' => 1/(24*7), 'weeks' => 1/(24*7),
    'month' => 1/(24*30), 'months' => 1/(24*30),
    'year' => 1/(24*365), 'years' => 1/(24*365),
];
if ($timeUnitParam !== 'automatic' && !isset($timeUnitFactors[$timeUnitParam])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid time unit']);
    exit;
}

$distanceKm = haversineDistance(
    $originCoords['lat'], $originCoords['lng'],
    $destCoords['lat'], $destCoords['lng']
);

$modes = [
    'walking'  => ['factor' => 1.3, 'speed' => 5],
    'bicycle'  => ['factor' => 1.2, 'speed' => 15],
    'car'      => ['factor' => 1.3, 'speed' => 60],
    'airplane' => ['factor' => 1.0, 'speed' => 900],
    'jet'      => ['factor' => 1.0, 'speed' => 1200],
];

if ($unit === 'automatic') {
    $topAuto = autoSelectDistanceUnit($distanceKm);
    $topUnitName = $topAuto['unit'];
    $distanceInUnit = $topAuto['value'];
} else {
    $topUnitName = $unit;
    $distanceInUnit = $distanceKm * $unitFactors[$unit];
}

$response = [
    'origin' => [
        'coordinates' => $originCoords,
        'location' => reverseGeocode($originCoords['lat'], $originCoords['lng']),
    ],
    'destination' => [
        'coordinates' => $destCoords,
        'location' => reverseGeocode($destCoords['lat'], $destCoords['lng']),
    ],
    'distance' => [
        'unit' => $topUnitName,
        'value' => round($distanceInUnit, 6),
        'modes' => [],
    ],
];

foreach ($modes as $modeName => $mode) {
    $modeDistanceKm = $distanceKm * $mode['factor'];

    if ($unit === 'automatic') {
        $modeAuto = autoSelectDistanceUnit($modeDistanceKm);
        $modeUnitName = $modeAuto['unit'];
        $modeDistance = $modeAuto['value'];
    } else {
        $modeUnitName = $unit;
        $modeDistance = $modeDistanceKm * $unitFactors[$unit];
    }

    $timeHours = $modeDistanceKm / $mode['speed'];

    if ($timeUnitParam === 'automatic') {
        $timeAuto = autoSelectTimeUnit($timeHours);
        $modeTimeUnitName = $timeAuto['unit'];
        $timeConverted = $timeAuto['value'];
    } else {
        $modeTimeUnitName = $timeUnitParam;
        $timeConverted = $timeHours * $timeUnitFactors[$timeUnitParam];
    }

    $response['distance']['modes'][$modeName] = [
        'distance' => [
            'value' => round($modeDistance, 6),
            'unit' => $modeUnitName,
        ],
        'time' => [
            'value' => round($timeConverted, 6),
            'unit' => $modeTimeUnitName,
        ],
    ];
}

if ($picture) {
    $imageUrl = generateSatelliteImage($originCoords, $destCoords, $response);
    if ($imageUrl !== null) {
        $response['picture'] = [
            'url' => $imageUrl,
            'expires_in_seconds' => 80,
        ];
    } else {
        $response['picture'] = [
            'error' => 'Could not generate image',
        ];
    }
}

$response['developer'] = 'J Code';

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

function parseCoordinates($input) {
    if (!is_string($input)) return false;
    $input = trim($input);
    if (empty($input)) return false;
    $parts = explode(',', $input);
    if (count($parts) !== 2) return false;
    $lat = filter_var($parts[0], FILTER_VALIDATE_FLOAT);
    $lng = filter_var($parts[1], FILTER_VALIDATE_FLOAT);
    if ($lat === false || $lng === false) return false;
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) return false;
    return ['lat' => $lat, 'lng' => $lng];
}

function haversineDistance($lat1, $lng1, $lat2, $lng2) {
    $earthRadius = 6371;
    $latDelta = deg2rad($lat2 - $lat1);
    $lngDelta = deg2rad($lng2 - $lng1);
    $a = sin($latDelta/2) * sin($latDelta/2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($lngDelta/2) * sin($lngDelta/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $earthRadius * $c;
}

function autoSelectDistanceUnit($km) {
    $order = [
        'terameter' => 0.000000001,
        'gigameter' => 0.000001,
        'megameter' => 0.001,
        'km'        => 1,
        'm'         => 1000,
        'cm'        => 100000,
        'mm'        => 1000000,
    ];
    $fallbackUnit = 'mm';
    $chosenUnit = $fallbackUnit;
    $chosenValue = $km * $order[$fallbackUnit];
    foreach ($order as $unitName => $factor) {
        $value = $km * $factor;
        if ($value >= 1) {
            $chosenUnit = $unitName;
            $chosenValue = $value;
            break;
        }
    }
    return ['unit' => $chosenUnit, 'value' => $chosenValue];
}

function autoSelectTimeUnit($hours) {
    $order = [
        'year'  => 1 / (24 * 365),
        'month' => 1 / (24 * 30),
        'week'  => 1 / (24 * 7),
        'day'   => 1 / 24,
        'hour'  => 1,
        'min'   => 60,
        's'     => 3600,
        'ms'    => 3600000,
    ];
    $fallbackUnit = 'ms';
    $chosenUnit = $fallbackUnit;
    $chosenValue = $hours * $order[$fallbackUnit];
    foreach ($order as $unitName => $factor) {
        $value = $hours * $factor;
        if ($value >= 1) {
            $chosenUnit = $unitName;
            $chosenValue = $value;
            break;
        }
    }
    return ['unit' => $chosenUnit, 'value' => $chosenValue];
}

function reverseGeocode($lat, $lng) {
    $cacheKey = round($lat, 3) . ',' . round($lng, 3);
    $cacheFile = sys_get_temp_dir() . '/geocache_' . md5($cacheKey) . '.json';
    $cacheTtl = 3600;

    if (is_file($cacheFile) && (time() - filemtime($cacheFile) < $cacheTtl)) {
        $cachedRaw = @file_get_contents($cacheFile);
        $cached = $cachedRaw !== false ? json_decode($cachedRaw, true) : null;
        if (is_array($cached) && array_key_exists('country', $cached)) {
            return $cached;
        }
    }

    $url = "https://nominatim.openstreetmap.org/reverse?format=json&lat={$lat}&lon={$lng}&addressdetails=1&zoom=10";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => 'DistanceTimeImageAPI/1.0 (contact: admin@example.com)',
        CURLOPT_TIMEOUT => 6,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $location = ['country' => '', 'city' => '', 'state' => ''];
    if ($response !== false) {
        $data = json_decode($response, true);
        if (isset($data['address'])) {
            $address = $data['address'];
            $location['country'] = $address['country'] ?? '';
            $location['city'] = $address['city'] ?? $address['town'] ?? $address['village'] ?? '';
            $location['state'] = $address['state'] ?? $address['province'] ?? $address['region'] ?? '';
        }
    }

    @file_put_contents($cacheFile, json_encode($location), LOCK_EX);
    return $location;
}

function generateSatelliteImage($originCoords, $destCoords, $responseData) {
    if (!extension_loaded('gd')) {
        return null;
    }

    $lat1 = $originCoords['lat']; $lng1 = $originCoords['lng'];
    $lat2 = $destCoords['lat'];   $lng2 = $destCoords['lng'];

    if (abs($lng1 - $lng2) > 180) {
        if ($lng1 < 0) $lng1 += 360; else $lng2 += 360;
    }

    $minLat = min($lat1, $lat2); $maxLat = max($lat1, $lat2);
    $minLng = min($lng1, $lng2); $maxLng = max($lng1, $lng2);
    $latPad = ($maxLat - $minLat) * 0.3 + 0.005;
    $lngPad = ($maxLng - $minLng) * 0.3 + 0.005;
    $minLat -= $latPad; $maxLat += $latPad;
    $minLng -= $lngPad; $maxLng += $lngPad;

    $mapWidth = 1600;
    $mapHeight = 1200;

    $zoom = findZoomLevel($minLat, $maxLat, $minLng, $maxLng, $mapWidth, $mapHeight);

    $tileSize = 256;
    $centerLat = ($minLat + $maxLat) / 2;
    $centerLng = ($minLng + $maxLng) / 2;
    $centerPixelX = lngToPixelX($centerLng, $zoom);
    $centerPixelY = latToPixelY($centerLat, $zoom);

    $minPixelX = $centerPixelX - $mapWidth / 2;
    $maxPixelX = $centerPixelX + $mapWidth / 2;
    $minPixelY = $centerPixelY - $mapHeight / 2;
    $maxPixelY = $centerPixelY + $mapHeight / 2;

    $minTileX = floor($minPixelX / $tileSize);
    $maxTileX = floor($maxPixelX / $tileSize);
    $minTileY = floor($minPixelY / $tileSize);
    $maxTileY = floor($maxPixelY / $tileSize);

    $mapImage = imagecreatetruecolor($mapWidth, $mapHeight);
    $bg = imagecolorallocate($mapImage, 30, 30, 30);
    imagefill($mapImage, 0, 0, $bg);

    $tileCount = pow(2, $zoom);
    $tileUrls = [];
    for ($x = $minTileX; $x <= $maxTileX; $x++) {
        for ($y = $minTileY; $y <= $maxTileY; $y++) {
            if ($y < 0 || $y >= $tileCount) continue;
            $tileX = (($x % $tileCount) + $tileCount) % $tileCount;
            $url = "https://a.tile.opentopomap.org/{$zoom}/{$tileX}/{$y}.png";
            $tileUrls[] = ['url' => $url, 'x' => $x, 'y' => $y];
        }
    }

    $tileImages = fetchTilesParallel($tileUrls);

    foreach ($tileImages as $tile) {
        if ($tile['image'] !== false) {
            $dx = $tile['x'] * $tileSize - $minPixelX;
            $dy = $tile['y'] * $tileSize - $minPixelY;
            imagecopy($mapImage, $tile['image'], (int)$dx, (int)$dy, 0, 0, $tileSize, $tileSize);
            imagedestroy($tile['image']);
        }
    }

    $originPixelX = lngToPixelX($lng1, $zoom) - $minPixelX;
    $originPixelY = latToPixelY($lat1, $zoom) - $minPixelY;
    $destPixelX = lngToPixelX($lng2, $zoom) - $minPixelX;
    $destPixelY = latToPixelY($lat2, $zoom) - $minPixelY;

    $red = imagecolorallocate($mapImage, 255, 0, 0);
    $darkRed = imagecolorallocate($mapImage, 180, 0, 0);
    $white = imagecolorallocate($mapImage, 255, 255, 255);

    imagefilledellipse($mapImage, (int)$originPixelX, (int)$originPixelY, 16, 16, $red);
    imageellipse($mapImage, (int)$originPixelX, (int)$originPixelY, 16, 16, $darkRed);
    drawTextWithOutline($mapImage, "Origin", (int)$originPixelX + 12, (int)$originPixelY - 12, $white, $darkRed);

    imagefilledellipse($mapImage, (int)$destPixelX, (int)$destPixelY, 16, 16, $red);
    imageellipse($mapImage, (int)$destPixelX, (int)$destPixelY, 16, 16, $darkRed);
    drawTextWithOutline($mapImage, "Destination", (int)$destPixelX + 12, (int)$destPixelY - 12, $white, $darkRed);

    $attribution = '© OpenStreetMap contributors, © OpenTopoMap (CC-BY-SA)';
    $attributionColor = imagecolorallocate($mapImage, 255, 255, 255);
    imagestring($mapImage, 3, 10, $mapHeight - 20, $attribution, $attributionColor);

    $jsonText = json_encode($responseData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $lines = explode("\n", $jsonText);

    $fontFile = __DIR__ . '/DejaVuSans.ttf';
    $useTtf = file_exists($fontFile);
    $fontSize = 12;
    $lineHeight = $useTtf ? 20 : (imagefontheight(5) + 5);
    $topPadding = $useTtf ? 30 : 20;
    $panelHeight = $topPadding + (count($lines) * $lineHeight) + 20;

    $totalHeight = $mapHeight + $panelHeight;
    $finalImage = imagecreatetruecolor($mapWidth, $totalHeight);
    imagecopy($finalImage, $mapImage, 0, 0, 0, 0, $mapWidth, $mapHeight);
    imagedestroy($mapImage);

    $panelBg = imagecolorallocate($finalImage, 40, 40, 60);
    imagefilledrectangle($finalImage, 0, $mapHeight, $mapWidth - 1, $totalHeight - 1, $panelBg);

    $borderColor = imagecolorallocate($finalImage, 200, 200, 200);
    imageline($finalImage, 0, $mapHeight, $mapWidth, $mapHeight, $borderColor);

    $panelText = imagecolorallocate($finalImage, 255, 255, 255);
    $startY = $mapHeight + $topPadding;
    if ($useTtf) {
        foreach ($lines as $line) {
            imagettftext($finalImage, $fontSize, 0, 20, $startY, $panelText, $fontFile, $line);
            $startY += $lineHeight;
        }
    } else {
        foreach ($lines as $line) {
            imagestring($finalImage, 5, 20, $startY, $line, $panelText);
            $startY += $lineHeight;
        }
    }

    $tempDir = __DIR__ . '/tmp_images';
    protectDir($tempDir);
    cleanupOldImages($tempDir, 80);

    $filename = 'map_' . bin2hex(random_bytes(16)) . '.png';
    $filePath = $tempDir . '/' . $filename;
    imagepng($finalImage, $filePath, 9);
    imagedestroy($finalImage);

    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
    $publicUrl = $baseUrl . rtrim($scriptDir, '/') . '/serve_image.php?file=' . $filename;
    return $publicUrl;
}

function fetchTilesParallel($tilesInfo) {
    $mh = curl_multi_init();
    $handles = [];
    $results = [];

    foreach ($tilesInfo as $index => $tile) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $tile['url'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => 'DistanceTimeImageAPI/1.0 (contact: your-email@example.com)',
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$index] = $ch;
    }

    $running = null;
    do {
        curl_multi_exec($mh, $running);
        if ($running > 0) {
            if (curl_multi_select($mh) === -1) {
                usleep(50000);
            }
        }
    } while ($running > 0);

    foreach ($handles as $index => $ch) {
        $data = curl_multi_getcontent($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);

        $image = false;
        if ($data !== false && $httpCode === 200) {
            $image = @imagecreatefromstring($data);
        }
        $results[] = [
            'image' => $image,
            'x' => $tilesInfo[$index]['x'],
            'y' => $tilesInfo[$index]['y'],
        ];
    }

    curl_multi_close($mh);
    return $results;
}

function findZoomLevel($minLat, $maxLat, $minLng, $maxLng, $mapWidth, $mapHeight) {
    for ($zoom = 18; $zoom >= 1; $zoom--) {
        $pixelMinX = lngToPixelX($minLng, $zoom);
        $pixelMaxX = lngToPixelX($maxLng, $zoom);
        $pixelMinY = latToPixelY($maxLat, $zoom);
        $pixelMaxY = latToPixelY($minLat, $zoom);
        $requiredWidth = $pixelMaxX - $pixelMinX;
        $requiredHeight = $pixelMaxY - $pixelMinY;
        if ($requiredWidth <= $mapWidth && $requiredHeight <= $mapHeight) {
            return $zoom;
        }
    }
    return 1;
}

function lngToPixelX($lng, $zoom) {
    return ($lng + 180) / 360 * pow(2, $zoom + 8);
}

function latToPixelY($lat, $zoom) {
    $latRad = deg2rad($lat);
    $mercN = log(tan(M_PI/4 + $latRad/2));
    return (1 - $mercN / M_PI) * pow(2, $zoom + 7);
}

function drawTextWithOutline($image, $text, $x, $y, $textColor, $outlineColor) {
    $font = 5;
    for ($dx = -1; $dx <= 1; $dx++) {
        for ($dy = -1; $dy <= 1; $dy++) {
            if ($dx == 0 && $dy == 0) continue;
            imagestring($image, $font, $x + $dx, $y + $dy, $text, $outlineColor);
        }
    }
    imagestring($image, $font, $x, $y, $text, $textColor);
}
?>