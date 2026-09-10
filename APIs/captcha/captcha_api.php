<?php
// Copyright 2026 J Code
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

define('CAPTCHA_DIR', __DIR__ . '/captcha_images');
define('CAPTCHA_TTL', 80);
define('FONT_FILE', __DIR__ . '/font.ttf');
define('MAX_REQUESTS_PER_MINUTE', 10);
define('RATE_LIMIT_FILE_TTL', 300);
define('FIXED_HOST', null);

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Content-Type: application/json; charset=utf-8');

if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
    $redirect = 'https://' . resolvedHost() . ($_SERVER['REQUEST_URI'] ?? '/');
    header('Location: ' . $redirect, true, 301);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Only GET and POST are supported.']);
    exit;
}

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!rateLimit($ip)) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many requests. Please slow down.']);
    exit;
}

$params = array_merge($_GET, $_POST);

$type = $params['type'] ?? 'printable-characters';
$len  = $params['len']  ?? 6;
$moving = $params['moving'] ?? false;

$allowedTypes = [
    'printable-characters',
    'numbers',
    'letters',
    'lowercase-letters',
    'capital-letters',
    'no-numbers-and-letters',
    'no-numbers-and-lowercase-letters',
    'no-numbers-and-capital-letters',
    'no-numbers',
    'no-letters',
    'no-lowercase-letters',
    'no-capital-letters'
];

if (!in_array($type, $allowedTypes, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid type parameter.']);
    exit;
}

if (!is_numeric($len)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid len parameter.']);
    exit;
}
$len = (int)$len;
if ($len < 1 || $len > 34) {
    http_response_code(400);
    echo json_encode(['error' => 'Len must be between 1 and 34.']);
    exit;
}

$moving = filter_var($moving, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
$moving = $moving ?? false;

$charset = getCharset($type);
$captchaText = generateCaptchaText($len, $charset);

$imageData = generateCaptchaImage($captchaText, $moving);
if ($imageData === null) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to generate captcha image. Please check font file.']);
    exit;
}

if (!is_dir(CAPTCHA_DIR)) {
    if (!mkdir(CAPTCHA_DIR, 0750, true)) {
        http_response_code(500);
        echo json_encode(['error' => 'Cannot create captcha directory.']);
        exit;
    }
}

$filename = bin2hex(random_bytes(16)) . '.png';
$filePath = CAPTCHA_DIR . '/' . $filename;

if (!file_put_contents($filePath, $imageData)) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not save captcha image.']);
    exit;
}

cleanupOldCaptchas(CAPTCHA_DIR, CAPTCHA_TTL);
cleanupOldRateLimitFiles(RATE_LIMIT_FILE_TTL);

$protocol = 'https://';
$host = resolvedHost();
$link = $protocol . $host . '/captcha_images/' . $filename;

$response = [
    'captcha link'   => $link,
    'Captcha value'  => $captchaText,
    'Developer'      => 'J Code'
];

echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

function resolvedHost(): string
{
    if (!empty(FIXED_HOST)) {
        return FIXED_HOST;
    }
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $host = preg_replace('/[^a-zA-Z0-9.\-:]/', '', $host);
    return $host !== '' ? $host : 'localhost';
}

function cleanupOldRateLimitFiles(int $maxAgeSeconds): void
{
    $marker = sys_get_temp_dir() . '/captcha_rl_gc_marker';
    $last = @filemtime($marker);
    if ($last !== false && (time() - $last) < $maxAgeSeconds) {
        return;
    }
    @touch($marker);

    $files = glob(sys_get_temp_dir() . '/rate_*.json');
    if (!$files) {
        return;
    }
    $now = time();
    foreach ($files as $file) {
        $mtime = @filemtime($file);
        if ($mtime !== false && ($now - $mtime) > $maxAgeSeconds) {
            @unlink($file);
        }
    }
}

function rateLimit(string $ip): bool
{
    $file = sys_get_temp_dir() . '/rate_' . md5($ip) . '.json';
    $now = microtime(true);
    $window = 60;
    $max = MAX_REQUESTS_PER_MINUTE;

    $fp = fopen($file, 'c+');
    if ($fp === false) {
        return false;
    }

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return false;
    }

    $raw = stream_get_contents($fp);
    $data = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
    $timestamps = is_array($data['timestamps'] ?? null) ? $data['timestamps'] : [];

    $timestamps = array_values(array_filter($timestamps, fn($t) => $now - $t < $window));

    if (count($timestamps) >= $max) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return false;
    }

    $timestamps[] = $now;

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode(['timestamps' => $timestamps]));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return true;
}

function getCharset(string $type): string
{
    $printable = '';
    for ($i = 33; $i <= 126; $i++) {
        $printable .= chr($i);
    }

    $digits = '0123456789';
    $lower  = 'abcdefghijklmnopqrstuvwxyz';
    $upper  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    switch ($type) {
        case 'numbers':
            return $digits;
        case 'letters':
            return $lower . $upper;
        case 'lowercase-letters':
            return $lower;
        case 'capital-letters':
            return $upper;
        case 'no-numbers-and-letters':
            $exclude = $digits . $lower . $upper;
            return str_replace(str_split($exclude), '', $printable);
        case 'no-numbers-and-lowercase-letters':
            $exclude = $digits . $lower;
            return str_replace(str_split($exclude), '', $printable);
        case 'no-numbers-and-capital-letters':
            $exclude = $digits . $upper;
            return str_replace(str_split($exclude), '', $printable);
        case 'no-numbers':
            return str_replace(str_split($digits), '', $printable);
        case 'no-letters':
            $exclude = $lower . $upper;
            return str_replace(str_split($exclude), '', $printable);
        case 'no-lowercase-letters':
            return str_replace(str_split($lower), '', $printable);
        case 'no-capital-letters':
            return str_replace(str_split($upper), '', $printable);
        case 'printable-characters':
        default:
            return $printable;
    }
}

function generateCaptchaText(int $length, string $charset): string
{
    $maxIndex = strlen($charset) - 1;
    if ($maxIndex < 0) {
        return '';
    }
    $text = '';
    for ($i = 0; $i < $length; $i++) {
        $text .= $charset[random_int(0, $maxIndex)];
    }
    return $text;
}

function generateCaptchaImage(string $text, bool $moving): ?string
{
    if (!function_exists('imagecreatetruecolor')) {
        return null;
    }

    $font = FONT_FILE;
    if (!file_exists($font)) {
        return null;
    }

    $charWidth = 45;
    $height = 80;
    $padding = 15;
    $totalChars = strlen($text);
    if ($totalChars === 0) {
        return null;
    }

    $width = $totalChars * $charWidth + $padding * 2;

    $img = imagecreatetruecolor($width, $height);
    if (!$img) {
        return null;
    }

    $bg = imagecolorallocate($img, 245, 245, 245);
    imagefilledrectangle($img, 0, 0, $width, $height, $bg);

    $fontSize = 28;
    $segmentWidth = ($width - $padding * 2) / $totalChars;
    $baselineY = intval($height * 0.6);

    for ($i = 0; $i < $totalChars; $i++) {
        $char = $text[$i];

        $x = (int)($padding + $i * $segmentWidth + ($segmentWidth / 2) - ($fontSize / 2) + rand(-3, 3));

        if ($moving) {
            $minY = $fontSize + 10;
            $maxY = $height - 15;
            $y = rand($minY, $maxY);
            $angle = rand(-15, 15);
        } else {
            $y = $baselineY;
            $angle = rand(-8, 8);
        }

        $textColor = imagecolorallocate($img, rand(80, 160), rand(80, 160), rand(80, 160));
        imagettftext($img, $fontSize, $angle, $x, $y, $textColor, $font, $char);
    }

    for ($i = 0; $i < 6; $i++) {
        $color = imagecolorallocate($img, rand(100, 200), rand(100, 200), rand(100, 200));
        imageline($img, rand(0, $width), rand(0, $height), rand(0, $width), rand(0, $height), $color);
    }

    for ($i = 0; $i < 120; $i++) {
        $color = imagecolorallocate($img, rand(150, 200), rand(150, 200), rand(150, 200));
        imagesetpixel($img, rand(0, $width - 1), rand(0, $height - 1), $color);
    }

    ob_start();
    imagepng($img);
    $data = ob_get_clean();
    imagedestroy($img);
    return $data;
}

function cleanupOldCaptchas(string $dir, int $ttl): void
{
    $files = glob($dir . '/*.png');
    if (!$files) {
        return;
    }
    $now = time();
    foreach ($files as $file) {
        if ($now - filemtime($file) > $ttl) {
            @unlink($file);
        }
    }
}