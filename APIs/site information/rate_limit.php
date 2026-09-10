<?php
// Copyright 2026 J Code
// SPDX-License-Identifier: Apache-2.0
function checkRateLimit($ip) {
    if (!is_dir(RATE_LIMIT_DIR)) {
        @mkdir(RATE_LIMIT_DIR, 0755, true);
    }
    protectDir(RATE_LIMIT_DIR);

    $file = RATE_LIMIT_DIR . md5($ip) . '.json';

    $fp = @fopen($file, 'c+');
    if (!$fp) {
        error_log('checkRateLimit: unable to open ' . $file);
        return false;
    }

    flock($fp, LOCK_EX);
    $content = stream_get_contents($fp);
    $data = json_decode($content, true);
    if (!is_array($data)) $data = [];

    $now = time();
    $data = array_values(array_filter($data, fn($t) => ($now - $t) < 60));
    $data[] = $now;

    $allowed = count($data) <= MAX_REQUESTS_PER_MINUTE;

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return $allowed;
}