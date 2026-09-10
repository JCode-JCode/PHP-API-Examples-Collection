<?php
// Copyright 2026 J Code
// SPDX-License-Identifier: Apache-2.0
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require __DIR__ . '/lib.php';
$CONFIG = require __DIR__ . '/config.php';

foreach ([$CONFIG['paths']['charts'], $CONFIG['paths']['data'], $CONFIG['paths']['locks'], $CONFIG['paths']['icons']] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

gc_expired_charts($CONFIG['paths']['charts'], (int) ($CONFIG['chart_ttl_seconds'] ?? 100));

$currencies = $CONFIG['currencies'] + load_registry($CONFIG);

$locks = [];
$toRefresh = [];

foreach ($currencies as $key => $conf) {
    $lockFile = $CONFIG['paths']['locks'] . '/' . $key . '.lock';
    $lock = acquire_lock_nb($lockFile);
    if (!$lock) {
        continue;
    }

    $dataPath = $CONFIG['paths']['data'] . '/' . $key . '.json';
    $existing = load_json_atomic($dataPath);

    if ($existing !== null && (time() - $existing['updated']) < $CONFIG['refresh_after_seconds']) {
        release_lock($lock);
        continue;
    }

    $locks[$key] = $lock;
    $toRefresh[$key] = ['conf' => $conf, 'existing' => $existing];
}

if ($toRefresh) {
    $results = fetch_and_store_prices_batch($toRefresh, $CONFIG, $currencies, null);

    foreach ($toRefresh as $key => $item) {
        if (empty($results[$key])) {
            fwrite(STDERR, "warn: could not fetch price for {$key}\n");
        }
        release_lock($locks[$key]);
    }
}
