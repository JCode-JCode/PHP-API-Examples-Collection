<?php
// Copyright 2026 J Code
// SPDX-License-Identifier: Apache-2.0
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

set_time_limit(MAX_TOTAL_RUNTIME);

$requestDeadline = microtime(true) + MAX_TOTAL_RUNTIME - RUNTIME_SAFETY_MARGIN;

protectDir(LOG_DIR);
ini_set('error_log', LOG_DIR . 'error.log');

require_once __DIR__ . '/rate_limit.php';

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST'])) {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Method Not Allowed. Only GET and POST are supported.']);
    exit;
}

$clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (!checkRateLimit($clientIp)) {
    http_response_code(429);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Too Many Requests. Please try again later.']);
    exit;
}

cleanupTempFiles();

$site = $_GET['site'] ?? $_POST['site'] ?? '';
if (!is_string($site)) {
    $site = '';
}

if (empty($site)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Missing required parameter: site']);
    exit;
}

$siteUrl = validateAndNormalizeSite($site);
if (!$siteUrl) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid site URL or internal address blocked.']);
    exit;
}

$betterRaw = $_GET['better'] ?? $_POST['better'] ?? null;
$better = is_string($betterRaw) ? filter_var($betterRaw, FILTER_VALIDATE_BOOLEAN) : false;

$pictureRaw = $_GET['picture'] ?? $_POST['picture'] ?? null;
$picture = is_string($pictureRaw) ? filter_var($pictureRaw, FILTER_VALIDATE_BOOLEAN) : false;

$host = parse_url($siteUrl, PHP_URL_HOST);
$scheme = parse_url($siteUrl, PHP_URL_SCHEME) ?: 'http';

$mainFetch = safeFetch($siteUrl, 3, TIMEOUT_CONNECT_MAIN, TIMEOUT_RESPONSE_MAIN, $requestDeadline);
$htmlContent = $mainFetch !== false ? $mainFetch['body'] : false;
$mainHeaders = $mainFetch !== false ? $mainFetch['headers'] : '';

$metaInfo = getMetaInfo($htmlContent ?: '');
$cookies = getCookiesFromHeaders($mainHeaders);
$techInfo = getTechnologyFromHeaders($mainHeaders);

$resources = fetchSiteResources($siteUrl, $htmlContent, $requestDeadline);

$cpanelUrl = detectCpanel($host, $requestDeadline);

$result = [
    'site' => $siteUrl,
    'host' => $host,
    'ip_info' => getIpInfo($host, $requestDeadline),
    'protocols' => checkProtocols($host, $requestDeadline),
    'domain_info' => getDomainInfo($host, $requestDeadline),
    'open_ports' => scanPorts($host, $requestDeadline),
    'technology' => $techInfo,
    'ssl_certificate' => checkSSL($host, $requestDeadline),
    'cookies' => $cookies,
    'description' => $metaInfo['description'],
    'creator' => $metaInfo['creator'],
    'cpanel' => $cpanelUrl,
    'download' => [
        'zip_link' => $resources['zip_link'],
        'html' => $resources['html'] !== NOT_FOUND_MSG ? 'HTML content fetched (see zip)' : NOT_FOUND_MSG,
        'css_files' => $resources['css'],
        'js_files' => $resources['js'],
        'file_count' => $resources['file_count'] ?? 0,
        'file_paths' => $resources['file_paths'] ?? [],
    ],
];

if ($better) {
    $result = cleanNotFound($result);
}

$result['developer'] = 'J Code';

if ($picture) {
    $image = generateResponseImage($result);
    if ($image !== false) {
        protectDir(TEMP_DIR);
        $imageFileName = 'picture_' . bin2hex(random_bytes(8)) . '.png';
        $imagePath = TEMP_DIR . $imageFileName;
        imagepng($image, $imagePath);
        imagedestroy($image);

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        $host = preg_replace('/:\d+$/', '', $host);
        $pictureUrl = $protocol . '://' . $host . '/image.php?file=' . $imageFileName;
        $result['picture_link'] = $pictureUrl;
    }
}

header('Content-Type: application/json');
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);