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

$tocurrencyRaw = request_param('tocurrency');
$tocurrency = strtoupper(trim((string)($tocurrencyRaw ?? $CONFIG['default_tocurrency'] ?? 'T')));
if ($tocurrency === '') $tocurrency = 'T';

$changesRaw = request_param('changes');
$changes = 1;
if ($changesRaw !== null && preg_match('/^\d+$/', (string) $changesRaw)) {
    $changes = (int) $changesRaw;
}
if ($changes < 1) $changes = 1;
if ($changes > (int) $CONFIG['max_period_days']) $changes = (int) $CONFIG['max_period_days'];

$unit = $CONFIG['coingecko']['vs_currency'] ?? 'usd';

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
        'note'       => 'To fetch any cryptocurrency available on CoinGecko, simply send its name or symbol as the currency value, even if it is not in this list.',
        'developer'  => 'JCode',
    ], 200, 3600);
}

function build_currency_payload($key, $conf, $record, $CONFIG, $changes = 1, $unit = 'usd', $deadline = null, $tocurrency = 'T', $allCurrencies = []) {
    $isStale = (time() - $record['updated']) > $CONFIG['stale_after_seconds'];
    $iconUrl = !empty($record['icon_file'])
        ? base_url() . '/icons/' . $record['icon_file'] . '?v=' . $record['updated']
        : null;
    $iconPath = !empty($record['icon_file'])
        ? rtrim($CONFIG['paths']['icons'], '/') . '/' . $record['icon_file']
        : null;

    $converted = convert_price_to_target($record['price'], $tocurrency, $CONFIG, $allCurrencies);
    $displayPrice = $converted['price'];
    $displayUnit  = $converted['unit'];
    $unitSlug = preg_replace('/[^a-z0-9]+/', '', strtolower($displayUnit)) ?: 'usd';

    if ($changes === 1) {
        $chartKeyForFile = $key;
        $periodLabel = '24h';
        $changePeriod = $record['change_24h'] ?? 0.0;
    } else {
        $chartKeyForFile = $key . '.' . $changes . 'd';
        $periodLabel = $changes . 'd';
        $changePeriod = null;
    }
    $chartFile = 'chart_' . $chartKeyForFile . '.' . $unitSlug . '.png';
    $chartPath = $CONFIG['paths']['charts'] . '/' . $chartFile;

    $overBudget = $deadline !== null && microtime(true) >= $deadline;

    if (!file_exists($chartPath) && !$overBudget) {

        $chartFetchSlot = acquire_fetch_slot($CONFIG);
        $marketHistory = $chartFetchSlot ? (fetch_market_chart($conf, $CONFIG, $changes) ?? []) : [];
        if ($chartFetchSlot) {
            release_lock($chartFetchSlot);
        }
        if ($marketHistory && $record['price'] > 0 && $displayPrice !== null) {
            $factor = $displayPrice / $record['price'];
            foreach ($marketHistory as &$pt) {
                $pt['p'] *= $factor;
            }
            unset($pt);
        }
        $candles = bucket_to_candles($marketHistory, $CONFIG['candle_count']);
        if ($changePeriod === null) {
            $refPrice = $marketHistory[0]['p'] ?? null;
            $changePeriod = ($refPrice && $refPrice > 0)
                ? (($displayPrice - $refPrice) / $refPrice) * 100
                : ($record['change_24h'] ?? 0.0);
        }
        generate_chart(
            $chartKeyForFile,
            $conf['label'],
            $displayPrice,
            $candles,
            $changePeriod,
            $CONFIG['chart_width'],
            $CONFIG['chart_height'],
            $CONFIG['paths']['charts'],
            $CONFIG['paths']['fonts'],
            $iconPath,
            $periodLabel,
            $displayUnit
        );
    } elseif ($changePeriod === null) {

        if ($changes >= 30) {
            $changePeriod = $record['change_30d'] ?? $record['change_24h'] ?? 0.0;
        } elseif ($changes >= 7) {
            $changePeriod = $record['change_7d'] ?? $record['change_24h'] ?? 0.0;
        } else {
            $changePeriod = $record['change_24h'] ?? 0.0;
        }
    }

    $chartExists = file_exists($chartPath);
    $chartVersion = $chartExists ? filemtime($chartPath) : time();
    $chartUrl = $chartExists ? (base_url() . '/charts/' . $chartFile . '?v=' . $chartVersion) : null;

    return [
        'key'             => $key,
        'currency'        => $conf['label'],
        'type'            => $conf['type'],
        'unit'            => $displayUnit,
        'price'           => $displayPrice,
        'change_24h'      => $record['change_24h'] ?? null,
        'change_7d'       => $record['change_7d'] ?? null,
        'change_30d'      => $record['change_30d'] ?? null,
        'period_days'     => $changes,
        'change_period'   => $changePeriod,
        'updated_at'      => date('c', $record['updated']),
        'valid_for_sec'   => max(0, $CONFIG['refresh_after_seconds'] - (time() - $record['updated'])),
        'stale'           => $isStale,
        'chart'           => $chartUrl,
        'icon'            => $iconUrl,
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
        $out[$key] = build_currency_payload($key, $conf, $record, $CONFIG, 1, $unit, $budgetDeadline, $tocurrency, $allCurrencies);
    }

    send_json([
        'currencies' => $out,
        'count'      => count($out),
        'developer'  => 'JCode',
    ], 200, $CONFIG['api_cache_seconds']);
}

if ($currency === '') {
    send_json([
        'error'     => 'The currency parameter was not provided. Example: japi.php?currency=bitcoin or japi.php?currency=all or japi.php?currency=list',
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

send_json(build_currency_payload($currency, $conf, $record, $CONFIG, $changes, $unit, null, $tocurrency, $allCurrencies), 200, $CONFIG['api_cache_seconds']);