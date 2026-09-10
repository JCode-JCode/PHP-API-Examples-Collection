<?php
// Copyright 2026 J Code
// SPDX-License-Identifier: Apache-2.0
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

protectDir(LOG_DIR);
ini_set('error_log', LOG_DIR . 'error.log');

$file = $_GET['file'] ?? '';
if (!is_string($file) || $file === '') {
    http_response_code(400);
    exit('Invalid request');
}

$file = basename($file);
$path = TEMP_DIR . $file;

if (!file_exists($path)) {
    http_response_code(404);
    exit('Image not found or expired');
}

if (time() - filemtime($path) > EXPIRATION_TIME) {
    unlink($path);
    http_response_code(410);
    exit('Image expired');
}

header('Content-Type: image/png');
header('Content-Length: ' . filesize($path));
readfile($path);

exit;