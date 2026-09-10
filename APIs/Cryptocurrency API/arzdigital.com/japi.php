<?php
// Copyright 2026 J Code
// SPDX-License-Identifier: Apache-2.0
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

require __DIR__ . '/lib.php';
$CONFIG = require __DIR__ . '/config.php';

if (!$isHttps) {
    $host = resolved_host($CONFIG);
    $uri  = $_SERVER['REQUEST_URI'] ?? '/';
    header('Location: https://' . $host . $uri, true, 301);
    exit;
}

header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header('X-Content-Type-Options: nosniff');
header('Vary: Origin');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Max-Age: 86400');
header('Content-Type: application/json; charset=utf-8');

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (extension_loaded('zlib') && !ini_get('zlib.output_compression')) {
    ob_start('ob_gzhandler');
}

@set_time_limit((int) ($CONFIG['max_execution_seconds'] ?? 55));

foreach ([$CONFIG['paths']['charts'], $CONFIG['paths']['data'], $CONFIG['paths']['locks'], $CONFIG['paths']['icons']] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

maybe_gc_charts($CONFIG);
maybe_gc_rate_limit_files($CONFIG);

function base_url() {
    global $CONFIG;
    $host = resolved_host($CONFIG);
    $dir  = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    return 'https://' . $host . $dir;
}

function send_json($data, $httpCode = 200, $cacheSeconds = 0) {
    http_response_code($httpCode);
    if ($cacheSeconds > 0) {
        header('Cache-Control: public, max-age=' . $cacheSeconds);
        $etag = '"' . md5(json_encode($data)) . '"';
        header('ETag: ' . $etag);
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            http_response_code(304);
            exit;
        }
    } else {
        header('Cache-Control: no-store');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function reject_rate_limited($windowSeconds) {
    header('Retry-After: ' . $windowSeconds);
    send_json([
        'error'     => 'Too many requests. Please wait a moment and try again.',
        'developer' => 'JCode',
    ], 429);
}

if (client_rate_limited($CONFIG, 'api', (int) $CONFIG['api_rate_limit'], (int) $CONFIG['api_rate_window_seconds'])) {
    reject_rate_limited((int) $CONFIG['api_rate_window_seconds']);
}

$currency = strtolower(trim((string) (request_param('currency') ?? '')));

$changesRaw = request_param('changes');
$changes = 1;
if ($changesRaw !== null && preg_match('/^\d+$/', (string) $changesRaw)) {
    $changes = (int) $changesRaw;
}
if ($changes < 1) $changes = 1;
if ($changes > (int) $CONFIG['max_period_days']) $changes = (int) $CONFIG['max_period_days'];

$toCurrencyRaw = request_param('tocurrency');
$toCurrency = trim((string) ($toCurrencyRaw ?? $CONFIG['default_tocurrency'] ?? 'T'));
if ($toCurrency === '') $toCurrency = 'T';

$allCurrencies = $CONFIG['currencies'] + load_registry($CONFIG);

if ($currency === 'list') {
    $list = [];
    foreach ($allCurrencies as $key => $conf) {
        $list[] = [
            'key'      => $key,
            'currency' => $conf['label'],
            'type'     => $conf['type'],
        ];
    }
    send_json([
        'currencies' => $list,
        'count'      => count($list),
        'note'       => 'To fetch any cryptocurrency available at arzdigital.com/coins/, simply send its page slug as the currency value, even if it is not in this list.',
        'developer'  => 'JCode',
    ], 200, 3600);
}

function build_currency_payload($key, $conf, $record, $CONFIG, $changes = 1, $tocurrency = 'T', $allCurrencies = [], $deadline = null) {
    $isStale = (time() - $record['updated']) > $CONFIG['stale_after_seconds'];
    $iconUrl = !empty($record['icon_file'])
        ? base_url() . '/icons/' . $record['icon_file'] . '?v=' . $record['updated']
        : null;

    $history = $record['history'] ?? [];
    $price2dAgo = price_n_days_ago($history, 2);
    $change2d = $record['change_2d'] ?? (
        ($price2dAgo && $price2dAgo > 0)
            ? (($record['price'] - $price2dAgo) / $price2dAgo) * 100
            : null
    );

    $converted = convert_price_to_target($record['price'], $tocurrency, $CONFIG, $allCurrencies);
    $convertedPrice2dAgo = ($price2dAgo !== null)
        ? convert_price_to_target($price2dAgo, $tocurrency, $CONFIG, $allCurrencies)['price']
        : null;
    $unit = $converted['unit'];
    $unitSlug = preg_replace('/[^a-z0-9]+/', '', strtolower($unit)) ?: 'toman';

    $chartHistory = $history;
    if ($unit !== 'toman' && $record['price'] > 0 && $converted['price'] !== null) {
        $factor = $converted['price'] / $record['price'];
        foreach ($chartHistory as &$pt) {
            $pt['p'] *= $factor;
        }
        unset($pt);
    }

    $overBudget = $deadline !== null && microtime(true) >= $deadline;

    if ($changes === 1) {
        $chartFile = 'chart_' . $key . '.' . $unitSlug . '.png';
        $chartPath = $CONFIG['paths']['charts'] . '/' . $chartFile;
        $changePeriod = $record['change_24h'] ?? null;

        if (!file_exists($chartPath) && !$overBudget) {
            $candles24h = bucket_to_candles($chartHistory, $CONFIG['candle_count']);
            $iconPath = !empty($record['icon_file'])
                ? rtrim($CONFIG['paths']['icons'], '/') . '/' . $record['icon_file']
                : null;
            generate_chart(
                $key,
                $conf['label'],
                $converted['price'],
                $candles24h,
                $changePeriod ?? 0.0,
                $CONFIG['chart_width'],
                $CONFIG['chart_height'],
                $CONFIG['paths']['charts'],
                $CONFIG['paths']['fonts'],
                $iconPath,
                '24h',
                $unit
            );
        }
        $chartVersion = file_exists($chartPath) ? filemtime($chartPath) : time();
    } else {
        $chartKey = $key . '.' . $changes . 'd';
        $chartPath = $CONFIG['paths']['charts'] . '/chart_' . $chartKey . '.' . $unitSlug . '.png';
        $cutoff = time() - $changes * 86400;
        $refPeriod = price_at_or_before($history, $cutoff);
        $changePeriod = ($refPeriod && $refPeriod > 0) ? (($record['price'] - $refPeriod) / $refPeriod) * 100 : ($record['change_24h'] ?? 0.0);

        if (!file_exists($chartPath) && !$overBudget) {
            $window = array_values(array_filter($chartHistory, fn($pt) => $pt['t'] >= $cutoff));
            if (count($window) < 2) $window = $chartHistory;
            $candlesPeriod = bucket_to_candles($window, $CONFIG['candle_count']);
            $iconPath = !empty($record['icon_file'])
                ? rtrim($CONFIG['paths']['icons'], '/') . '/' . $record['icon_file']
                : null;
            generate_chart(
                $chartKey,
                $conf['label'],
                $converted['price'],
                $candlesPeriod,
                $changePeriod,
                $CONFIG['chart_width'],
                $CONFIG['chart_height'],
                $CONFIG['paths']['charts'],
                $CONFIG['paths']['fonts'],
                $iconPath,
                $changes . 'd',
                $unit
            );
        }
        $chartFile = 'chart_' . $chartKey . '.' . $unitSlug . '.png';
        $chartVersion = file_exists($chartPath) ? filemtime($chartPath) : time();
    }

    $chartUrl = file_exists($chartPath) ? (base_url() . '/charts/' . $chartFile . '?v=' . $chartVersion) : null;

    return [
        'key'             => $key,
        'currency'        => $conf['label'],
        'type'            => $conf['type'],
        'unit'            => $converted['unit'],
        'price'           => $converted['price'],
        'price_2d_ago'    => $convertedPrice2dAgo,
        'change_24h'      => $record['change_24h'] ?? null,
        'change_2d'       => $change2d,
        'period_days'     => $changes,
        'change_period'   => $changePeriod,
        'updated_at'      => date('c', $record['updated']),
        'valid_for_sec'   => max(0, $CONFIG['refresh_after_seconds'] - (time() - $record['updated'])),
        'stale'           => $isStale,
        'chart'           => $chartUrl,
        'icon'            => $iconUrl,
        'requested_currency' => $tocurrency,
        'conversion_note' => (strtolower(trim($tocurrency)) !== '' && strtolower(trim($tocurrency)) !== 't' && strtolower(trim($tocurrency)) !== 'toman' && $unit === 'toman')
            ? 'Could not fetch the conversion rate for "' . $tocurrency . '"; falling back to toman.'
            : null,
        'developer'       => 'JCode',
    ];
}

if ($currency === 'all') {
    if (client_rate_limited($CONFIG, 'all', (int) $CONFIG['api_all_rate_limit'], (int) $CONFIG['api_all_rate_window_seconds'])) {
        reject_rate_limited((int) $CONFIG['api_all_rate_window_seconds']);
    }

    $out = [];
    $budgetDeadline = microtime(true) + (float) ($CONFIG['all_endpoint_time_budget_seconds'] ?? 20);

    $records = [];
    $locks = [];
    $toRefresh = [];

    foreach ($allCurrencies as $key => $conf) {
        $apcuKey = 'japi_rec_' . $key;
        $record = null;
        if (function_exists('apcu_fetch')) {
            $cached = apcu_fetch($apcuKey, $ok);
            if ($ok) $record = $cached;
        }
        if ($record === null) {
            $record = load_json_atomic($CONFIG['paths']['data'] . '/' . $key . '.json');
        }
        $records[$key] = $record;

        $age = $record ? (time() - $record['updated']) : PHP_INT_MAX;
        $needsRefresh = ($record === null) || ($age >= $CONFIG['refresh_after_seconds']);

        if ($needsRefresh && microtime(true) < $budgetDeadline) {
            $lockFile = $CONFIG['paths']['locks'] . '/' . $key . '.lock';
            $lock = acquire_lock_nb($lockFile);
            if ($lock) {
                $locks[$key] = $lock;
                $toRefresh[$key] = ['conf' => $conf, 'existing' => $record];
            }
        }
    }

    if ($toRefresh) {
        $freshResults = fetch_and_store_prices_batch($toRefresh, $CONFIG, $allCurrencies, $budgetDeadline);
        foreach ($toRefresh as $key => $item) {
            if (!empty($freshResults[$key])) {
                $records[$key] = $freshResults[$key];
                if (function_exists('apcu_store')) {
                    apcu_store('japi_rec_' . $key, $freshResults[$key], $CONFIG['refresh_after_seconds']);
                }
            }
            release_lock($locks[$key]);
        }
    }

    foreach ($allCurrencies as $key => $conf) {
        $record = $records[$key] ?? null;
        if ($record === null) {
            $out[$key] = [
                'currency' => $conf['label'],
                'error'    => 'No data has been recorded for this currency yet.',
            ];
            continue;
        }
        $out[$key] = build_currency_payload($key, $conf, $record, $CONFIG, 1, $toCurrency, $allCurrencies, $budgetDeadline);
    }

    send_json([
        'currencies' => $out,
        'count'      => count($out),
        'developer'  => 'JCode',
    ], 200, $CONFIG['api_cache_seconds']);
}

if ($currency === '') {
    send_json([
        'error'     => 'The currency parameter was not provided. Example: japi.php?currency=dollar or japi.php?currency=all or japi.php?currency=list',
        'available' => array_keys($CONFIG['currencies']),
        'developer' => 'JCode',
    ], 400);
}

$conf = resolve_currency_conf($currency, $CONFIG);

if ($conf === null) {
    send_json([
        'error'     => 'The requested currency was not found or is not supported.',
        'available' => array_keys($CONFIG['currencies']),
        'developer' => 'JCode',
    ], 404);
}

$record = get_fresh_currency_record($currency, $conf, $CONFIG, $allCurrencies);

if ($record === null) {
    send_json([
        'error'     => 'The price for this currency is currently unavailable. Please try again in a moment.',
        'currency'  => $conf['label'],
        'developer' => 'JCode',
    ], 503);
}

send_json(build_currency_payload($currency, $conf, $record, $CONFIG, $changes, $toCurrency, $allCurrencies), 200, $CONFIG['api_cache_seconds']);