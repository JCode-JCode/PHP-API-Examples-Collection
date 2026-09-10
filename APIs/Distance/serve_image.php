<?php
// Copyright 2026 J Code
// SPDX-License-Identifier: Apache-2.0
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

function protectDir($dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $htaccess = rtrim($dir, '/') . '/.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "Require all denied\nDeny from all\n");
    }
}

$logDir = __DIR__ . '/private';
protectDir($logDir);
ini_set('error_log', $logDir . '/error.log');

$tempDir = __DIR__ . '/tmp_images';

protectDir($tempDir);
$expiration = 80;

$file = $_GET['file'] ?? '';
if (!is_string($file) || $file === '') {
    http_response_code(400);
    exit('Invalid request');
}

$file = basename($file);
$path = $tempDir . '/' . $file;

if (!file_exists($path)) {
    http_response_code(404);
    exit('File not found or expired');
}

if (time() - filemtime($path) > $expiration) {
    unlink($path);
    http_response_code(410);
    exit('File expired');
}

header('Content-Type: image/png');
header('Content-Disposition: inline; filename="' . $file . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
?>