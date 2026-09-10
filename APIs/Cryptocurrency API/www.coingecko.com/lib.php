<?php
// Copyright 2026 J Code
// SPDX-License-Identifier: Apache-2.0
if (!defined('DNS_A'))    define('DNS_A', 1);
if (!defined('DNS_AAAA')) define('DNS_AAAA', 28);
if (!defined('DNS_ALL'))  define('DNS_ALL', 255);

function get_usd_to_toman_rate($CONFIG) {
    $cacheKey = 'japi_usd_toman_rate';
    if (function_exists('apcu_fetch')) {
        $ok = false;
        $cached = apcu_fetch($cacheKey, $ok);
        if ($ok) return $cached;
    } else {
        $cacheFile = $CONFIG['paths']['locks'] . '/usd_toman_rate.json';
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $CONFIG['toman_rate_cache_seconds']) {
            $data = json_decode(file_get_contents($cacheFile), true);
            if ($data && isset($data['rate'])) return (float) $data['rate'];
        }
    }

    $rate = null;
    if ($CONFIG['toman_rate_source'] === 'kifpool') {
        $html = @file_get_contents('https://kifpool.me/live/currency');
        if ($html && preg_match('/<span class="inline-block">([\d,]+)<\/span>/', $html, $m)) {
            $rate = (float) str_replace(',', '', $m[1]);
        }
    } elseif ($CONFIG['toman_rate_source'] === 'nobitex') {
        $url = 'https://api.nobitex.ir/market/stats?srcCurrency=usdt&dstCurrency=rls';
        $response = @file_get_contents($url);
        if ($response) {
            $data = json_decode($response, true);
            if (isset($data['stats']['latest'])) {
                $rate = (float) $data['stats']['latest'] / 10;
            }
        }
    } elseif ($CONFIG['toman_rate_source'] === 'custom' && !empty($CONFIG['custom_toman_rate'])) {
        $rate = (float) $CONFIG['custom_toman_rate'];
    }

    if ($rate !== null && $rate > 0) {
        if (function_exists('apcu_store')) {
            apcu_store($cacheKey, $rate, $CONFIG['toman_rate_cache_seconds']);
        } else {
            file_put_contents($cacheFile, json_encode(['rate' => $rate, 'updated' => time()]));
        }
        return $rate;
    }
    if (function_exists('apcu_fetch')) {
        $ok = false;
        $cached = apcu_fetch($cacheKey, $ok);
        if ($ok) return $cached;
    } else {
        $cacheFile = $CONFIG['paths']['locks'] . '/usd_toman_rate.json';
        if (file_exists($cacheFile)) {
            $data = json_decode(file_get_contents($cacheFile), true);
            if ($data && isset($data['rate'])) return (float) $data['rate'];
        }
    }
    return null;
}

function get_target_usd_price($target, $CONFIG, $allCurrencies) {
    $target = strtolower(trim($target));
    if ($target === 'usd') return 1.0;
    if ($target === 't') return null;
    
    if (isset($allCurrencies[$target])) {
        $conf = $allCurrencies[$target];
        $record = load_json_atomic($CONFIG['paths']['data'] . '/' . $target . '.json');
        if ($record && (time() - $record['updated']) < $CONFIG['refresh_after_seconds']) {
            return (float) $record['price'];
        }
        $fresh = get_fresh_currency_record($target, $conf, $CONFIG, $allCurrencies);
        if ($fresh) {
            return (float) $fresh['price'];
        }
        return null;
    }
    return null;
}

function convert_price_to_target($price_usd, $tocurrency, $CONFIG, $allCurrencies) {
    $tocurrency = strtolower(trim($tocurrency));
    if ($tocurrency === 'usd') {
        return ['price' => $price_usd, 'unit' => 'usd'];
    }
    if ($tocurrency === 't') {
        $tomanRate = get_usd_to_toman_rate($CONFIG);
        if ($tomanRate) {
            return ['price' => $price_usd * $tomanRate, 'unit' => 'toman'];
        }
        return ['price' => $price_usd, 'unit' => 'usd'];
    }
    $targetUsdPrice = get_target_usd_price($tocurrency, $CONFIG, $allCurrencies);
    if ($targetUsdPrice && $targetUsdPrice > 0) {
        $converted = $price_usd / $targetUsdPrice;
        return ['price' => $converted, 'unit' => $tocurrency];
    }
    return ['price' => $price_usd, 'unit' => 'usd'];
}

function request_param_ci($haystack, $name) {
    foreach ($haystack as $k => $v) {
        if (strtolower((string) $k) === $name) {
            return $v;
        }
    }
    return null;
}

function request_param($name) {
    $name = strtolower($name);

    $v = request_param_ci($_GET, $name);
    if ($v !== null) return $v;

    $v = request_param_ci($_POST, $name);
    if ($v !== null) return $v;

    static $jsonBody = null;
    if ($jsonBody === null) {
        $raw = file_get_contents('php://input');
        $decoded = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
        $jsonBody = is_array($decoded) ? $decoded : [];
    }
    return request_param_ci($jsonBody, $name);
}

function acquire_lock_nb($path) {
    $lockDir = $path . '.lockdir';
    if (@mkdir($lockDir, 0755)) {
        return $lockDir;
    }

    $mtime = @filemtime($lockDir);
    if ($mtime !== false && (time() - $mtime) > 120) {
        @rmdir($lockDir);
        if (@mkdir($lockDir, 0755)) {
            return $lockDir;
        }
    }
    return null;
}

function acquire_lock_wait($path, $maxWaitMs) {
    $deadline = microtime(true) + ($maxWaitMs / 1000);
    do {
        $fp = acquire_lock_nb($path);
        if ($fp) return $fp;
        usleep(50000);
    } while (microtime(true) < $deadline);
    return null;
}

function release_lock($lockDir) {
    if ($lockDir) {
        @rmdir($lockDir);
    }
}

function resolved_host($CONFIG) {
    if (!empty($CONFIG['fixed_host'])) {
        return $CONFIG['fixed_host'];
    }
    $host = $_SERVER['SERVER_NAME'] ?? '';
    if ($host === '') {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    }
    $host = preg_replace('/[^a-zA-Z0-9.\-:]/', '', $host);
    return $host !== '' ? $host : 'localhost';
}

function gc_expired_charts($chartsDir, $ttlSeconds) {
    $files = @glob(rtrim($chartsDir, '/') . '/chart_*.png');
    if (!$files) return;
    $now = time();
    foreach ($files as $file) {
        $mtime = @filemtime($file);
        if ($mtime !== false && ($now - $mtime) > $ttlSeconds) {
            @unlink($file);
        }
    }
}

function maybe_gc_charts($CONFIG) {
    $ttl = (int) ($CONFIG['chart_ttl_seconds'] ?? 100);
    $interval = max(30, $ttl);

    if (function_exists('apcu_add')) {
        if (!apcu_add('japi_gc_marker', 1, $interval)) return;
        gc_expired_charts($CONFIG['paths']['charts'], $ttl);
        return;
    }

    $marker = rtrim($CONFIG['paths']['locks'], '/') . '/gc_marker.txt';
    $last = @filemtime($marker);
    if ($last !== false && (time() - $last) < $interval) return;

    $fp = acquire_lock_nb($marker);
    if (!$fp) return;
    touch($marker);
    gc_expired_charts($CONFIG['paths']['charts'], $ttl);
    release_lock($fp);
}

function maybe_gc_rate_limit_files($CONFIG) {

    if (function_exists('apcu_fetch')) {
        return;
    }

    $interval = 300;
    $marker = rtrim($CONFIG['paths']['locks'], '/') . '/rl_gc_marker.txt';
    $last = @filemtime($marker);
    if ($last !== false && (time() - $last) < $interval) return;

    $lock = acquire_lock_nb($marker);
    if (!$lock) return;
    touch($marker);

    $maxAge = max(
        120,
        2 * (int) ($CONFIG['api_rate_window_seconds'] ?? 60),
        2 * (int) ($CONFIG['api_all_rate_window_seconds'] ?? 60),
        2 * (int) ($CONFIG['dynamic_lookup_rate_window_seconds'] ?? 60)
    );
    $now = time();

    foreach (glob(rtrim($CONFIG['paths']['locks'], '/') . '/rl_*/*.json') ?: [] as $file) {
        $mtime = @filemtime($file);
        if ($mtime !== false && ($now - $mtime) > $maxAge) {
            @unlink($file);
        }
    }
    foreach (glob(rtrim($CONFIG['paths']['locks'], '/') . '/neg/*.json') ?: [] as $file) {
        $data = json_decode((string) @file_get_contents($file), true);
        $expired = !is_array($data) || !isset($data['expires']) || $data['expires'] < $now;
        if ($expired) {
            @unlink($file);
        }
    }

    release_lock($lock);
}

function rate_limit_dir($CONFIG, $sub) {
    $dir = rtrim($CONFIG['paths']['locks'], '/') . '/' . $sub;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

function file_bucket_hit($CONFIG, $sub, $bucketId, $limit, $windowSeconds) {
    $path = rate_limit_dir($CONFIG, $sub) . '/' . md5($bucketId) . '.json';
    $fp = @fopen($path, 'c+');
    if (!$fp) return false;
    @flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $data = $raw !== false && $raw !== '' ? json_decode($raw, true) : null;
    $now = time();
    if (!is_array($data) || !isset($data['start']) || ($now - $data['start']) >= $windowSeconds) {
        $data = ['start' => $now, 'count' => 1];
        $hit = false;
    } else {
        $data['count'] = (int) ($data['count'] ?? 0) + 1;
        $hit = $data['count'] > $limit;
    }
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    fflush($fp);
    @flock($fp, LOCK_UN);
    fclose($fp);
    return $hit;
}

function dynamic_lookup_negative_cache_get($slug, $CONFIG = null) {
    if (function_exists('apcu_fetch')) {
        $ok = false;
        apcu_fetch('japi_neg_' . $slug, $ok);
        return $ok;
    }
    if ($CONFIG === null) return false;
    $path = rate_limit_dir($CONFIG, 'neg') . '/' . $slug . '.json';
    if (!file_exists($path)) return false;
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data) || !isset($data['expires'])) return false;
    if ($data['expires'] < time()) {
        @unlink($path);
        return false;
    }
    return true;
}

function dynamic_lookup_negative_cache_set($slug, $ttlSeconds, $CONFIG = null) {
    if (function_exists('apcu_store')) {
        apcu_store('japi_neg_' . $slug, true, $ttlSeconds);
        return;
    }
    if ($CONFIG === null) return;
    $path = rate_limit_dir($CONFIG, 'neg') . '/' . $slug . '.json';
    file_put_contents($path, json_encode(['expires' => time() + $ttlSeconds]));
}

function dynamic_lookup_rate_limited($CONFIG) {
    return client_rate_limited($CONFIG, 'dyn', (int) ($CONFIG['dynamic_lookup_rate_limit'] ?? 20), (int) ($CONFIG['dynamic_lookup_rate_window_seconds'] ?? 60));
}

function client_rate_limited($CONFIG, $bucket, $limit, $windowSeconds) {
    if ($limit <= 0) return false;
    $ip = client_ip($CONFIG);

    if (function_exists('apcu_fetch') && function_exists('apcu_inc')) {
        $key = 'japi_rl_' . $bucket . '_' . md5($ip);
        $ok = false;
        $count = apcu_fetch($key, $ok);
        if (!$ok) {
            apcu_store($key, 1, $windowSeconds);
            return false;
        }
        if ($count >= $limit) return true;
        apcu_inc($key);
        return false;
    }

    return file_bucket_hit($CONFIG, 'rl_' . $bucket, $ip, $limit, $windowSeconds);
}

function client_ip($CONFIG) {
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function acquire_fetch_slot($CONFIG) {
    $slots = max(1, (int) ($CONFIG['max_concurrent_fetches'] ?? 6));
    $waitMs = (int) ($CONFIG['fetch_slot_wait_ms'] ?? 150);
    $dir = rate_limit_dir($CONFIG, 'slots');
    $deadline = microtime(true) + ($waitMs / 1000);
    do {
        for ($i = 0; $i < $slots; $i++) {
            $fp = acquire_lock_nb($dir . '/slot_' . $i . '.lock');
            if ($fp) return $fp;
        }
        usleep(20000);
    } while (microtime(true) < $deadline);
    return null;
}

function cg_headers($CONFIG) {
    $headers = ['Accept: application/json'];
    $key = $CONFIG['coingecko']['demo_api_key'] ?? null;
    if (!empty($key)) {
        $headers[] = 'x-cg-demo-api-key: ' . $key;
    }
    return $headers;
}

function cg_api_get($CONFIG, $path, $params = []) {
    $base = rtrim($CONFIG['coingecko']['base_url'], '/');
    $url = $base . $path . (($params) ? ('?' . http_build_query($params)) : '');

    $connectTimeout = $CONFIG['fetch_connect_timeout'];
    $timeout = $CONFIG['fetch_timeout'];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_FOLLOWLOCATION  => true,
        CURLOPT_MAXREDIRS       => 3,
        CURLOPT_CONNECTTIMEOUT  => $connectTimeout,
        CURLOPT_TIMEOUT         => $timeout,
        CURLOPT_SSL_VERIFYPEER  => true,
        CURLOPT_SSL_VERIFYHOST  => 2,
        CURLOPT_IPRESOLVE       => CURL_IPRESOLVE_V4,
        CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER      => cg_headers($CONFIG),
        CURLOPT_USERAGENT       => 'Mozilla/5.0 (compatible; JCode-API/1.0; +coingecko-client)',
    ]);
    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $err || $code >= 400) {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function is_ip_publicly_routable($ip) {

    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) !== false;
}

function resolve_public_ip($host) {
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return is_ip_publicly_routable($host) ? $host : null;
    }

    if (function_exists('dns_get_record')) {
        $records = @dns_get_record($host, DNS_A);
        if ($records) {
            foreach ($records as $rec) {
                $ip = $rec['ip'] ?? null;
                if ($ip && is_ip_publicly_routable($ip)) {
                    return $ip;
                }
            }
        }
    }

    $ip = @gethostbyname($host);
    if ($ip && $ip !== $host && is_ip_publicly_routable($ip)) {
        return $ip;
    }

    return null;
}

function curl_fetch_ssrf_safe($url, $connectTimeout, $timeout, array $extraCurlOpts, $maxRedirects = 3) {
    for ($hop = 0; $hop <= $maxRedirects; $hop++) {
        if (!preg_match('#^https://#i', $url)) return null;
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) return null;
        $port = parse_url($url, PHP_URL_PORT) ?: 443;

        $curlOpts = [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_FOLLOWLOCATION  => false,
            CURLOPT_CONNECTTIMEOUT  => $connectTimeout,
            CURLOPT_TIMEOUT         => $timeout,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
            CURLOPT_IPRESOLVE       => CURL_IPRESOLVE_V4,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ];

        $ip = resolve_public_ip($host);
        if ($ip) {
            $curlOpts[CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . $ip];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, $extraCurlOpts + $curlOpts);

        $body     = curl_exec($ch);
        $err      = curl_error($ch);
        $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $location = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);

        if ($body === false || $err) return null;

        if ($code >= 300 && $code < 400 && $location) {
            $next = resolve_url($location, $url);
            if (!$next) return null;
            $url = $next;
            continue;
        }

        return ($code < 400) ? $body : null;
    }
    return null;
}

function fetch_binary($url, $connectTimeout, $timeout, $CONFIG = null) {
    return curl_fetch_ssrf_safe($url, $connectTimeout, $timeout, [
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
    ]);
}

function image_ext_from_mime($mime) {
    switch ($mime) {
        case 'image/png':  return 'png';
        case 'image/jpeg': return 'jpg';
        case 'image/webp': return 'webp';
        case 'image/gif':  return 'gif';
        default: return null;
    }
}

function find_existing_icon($key, $iconsDir) {
    foreach (['png', 'jpg', 'jpeg', 'webp', 'gif'] as $ext) {
        $p = rtrim($iconsDir, '/') . '/icon_' . $key . '.' . $ext;
        if (file_exists($p)) return $p;
    }
    return null;
}

function ensure_icon($key, $iconCandidates, $iconsDir, $CONFIG) {
    $existing = find_existing_icon($key, $iconsDir);
    if ($existing) return $existing;
    if (!is_dir($iconsDir)) @mkdir($iconsDir, 0755, true);
    foreach ((array) $iconCandidates as $iconUrl) {
        if (!$iconUrl) continue;
        $bytes = fetch_binary($iconUrl, $CONFIG['fetch_connect_timeout'], $CONFIG['fetch_timeout'], $CONFIG);
        if (!$bytes) continue;
        $info = @getimagesizefromstring($bytes);
        if (!$info) continue;
        $ext = image_ext_from_mime($info['mime'] ?? '');
        if (!$ext) continue;
        $path = rtrim($iconsDir, '/') . '/icon_' . $key . '.' . $ext;
        $tmp = $path . '.' . uniqid('', true) . '.tmp';
        file_put_contents($tmp, $bytes);
        rename($tmp, $path);
        return $path;
    }
    return null;
}

function sanitize_slug($key) {
    $key = strtolower(trim((string) $key));
    if (!preg_match('/^[a-z0-9]+([_-][a-z0-9]+)*$/', $key)) return null;
    if (strlen($key) > 80) return null;
    return $key;
}

function load_registry($CONFIG) {
    $path = $CONFIG['paths']['data'] . '/_registry.json';
    $data = load_json_atomic($path);
    return is_array($data) ? $data : [];
}

function save_registry($CONFIG, $registry) {
    $path = $CONFIG['paths']['data'] . '/_registry.json';
    save_json_atomic($path, $registry);
}

function resolve_currency_conf_static($key, $CONFIG) {
    if (isset($CONFIG['currencies'][$key])) {
        return $CONFIG['currencies'][$key];
    }
    $registry = load_registry($CONFIG);
    return $registry[$key] ?? null;
}

function resolve_currency_conf($key, $CONFIG) {
    $conf = resolve_currency_conf_static($key, $CONFIG);
    if ($conf !== null) {
        return $conf;
    }
    if (empty($CONFIG['allow_dynamic_currencies'])) {
        return null;
    }
    $slug = sanitize_slug($key);
    if ($slug === null) {
        return null;
    }
    if (dynamic_lookup_negative_cache_get($slug, $CONFIG)) {
        return null;
    }
    if (dynamic_lookup_rate_limited($CONFIG)) {
        return null;
    }
    $registry = load_registry($CONFIG);
    $maxDynamic = (int) ($CONFIG['max_dynamic_currencies'] ?? 500);
    if (count($registry) >= $maxDynamic) {
        return null;
    }
    $slot = acquire_fetch_slot($CONFIG);
    if (!$slot) {
        return null;
    }
    $query = str_replace(['-', '_'], ' ', $slug);
    $searchResult = cg_api_get($CONFIG, '/search', ['query' => $query]);
    $coins = $searchResult['coins'] ?? [];
    if (empty($coins)) {
        release_lock($slot);
        dynamic_lookup_negative_cache_set($slug, (int) ($CONFIG['negative_cache_seconds'] ?? 600), $CONFIG);
        return null;
    }
    $best = $coins[0];
    release_lock($slot);

    if (empty($best['id']) || empty($best['name'])) {
        dynamic_lookup_negative_cache_set($slug, (int) ($CONFIG['negative_cache_seconds'] ?? 600), $CONFIG);
        return null;
    }

    $conf = [
        'label' => $best['name'],
        'type'  => 'crypto',
        'cg_id' => $best['id'],
    ];
    $registry[$slug] = $conf;
    save_registry($CONFIG, $registry);
    return $conf;
}

function cg_markets_batch($cgIds, $CONFIG) {
    $vs = $CONFIG['coingecko']['vs_currency'] ?? 'usd';
    $cgIds = array_values(array_unique(array_filter($cgIds)));
    $byId = [];
    foreach (array_chunk($cgIds, 250) as $chunk) {
        $data = cg_api_get($CONFIG, '/coins/markets', [
            'vs_currency'              => $vs,
            'ids'                      => implode(',', $chunk),
            'price_change_percentage'  => '24h,7d,30d',
            'sparkline'                => 'false',
            'per_page'                 => 250,
        ]);
        if (!is_array($data)) continue;
        foreach ($data as $coin) {
            if (!empty($coin['id'])) {
                $byId[$coin['id']] = $coin;
            }
        }
    }
    return $byId;
}

function snapshot_from_markets_row($row, $vs) {
    if (!$row || !isset($row['current_price'])) return null;
    return [
        'price'           => (float) $row['current_price'],
        'change_24h'      => isset($row['price_change_percentage_24h_in_currency']) ? (float) $row['price_change_percentage_24h_in_currency'] : (isset($row['price_change_percentage_24h']) ? (float) $row['price_change_percentage_24h'] : null),
        'change_7d'       => isset($row['price_change_percentage_7d_in_currency']) ? (float) $row['price_change_percentage_7d_in_currency'] : null,
        'change_30d'      => isset($row['price_change_percentage_30d_in_currency']) ? (float) $row['price_change_percentage_30d_in_currency'] : null,
        'icon_candidates' => !empty($row['image']) ? [$row['image']] : [],
    ];
}

function fetch_snapshots_batch($keysConf, $CONFIG, $deadline = null) {
    $cgIds = [];
    foreach ($keysConf as $key => $conf) {
        if (!empty($conf['cg_id'])) $cgIds[$key] = $conf['cg_id'];
    }
    $rows = cg_markets_batch(array_values($cgIds), $CONFIG);
    $vs = $CONFIG['coingecko']['vs_currency'] ?? 'usd';

    $snapshots = [];
    foreach ($keysConf as $key => $conf) {
        $cgId = $cgIds[$key] ?? null;
        $row = $cgId ? ($rows[$cgId] ?? null) : null;
        $snapshots[$key] = $row ? snapshot_from_markets_row($row, $vs) : null;
    }
    return $snapshots;
}

function fetch_currency_snapshot($conf, $CONFIG) {
    $id = $conf['cg_id'] ?? null;
    if (!$id) return null;

    $attempts = 1 + max(0, (int) ($CONFIG['fetch_retries'] ?? 0));
    $delayUs  = (int) (($CONFIG['fetch_retry_delay_ms'] ?? 300) * 1000);
    $vs = $CONFIG['coingecko']['vs_currency'] ?? 'usd';

    for ($i = 1; $i <= $attempts; $i++) {
        $data = cg_api_get($CONFIG, '/coins/' . rawurlencode($id), [
            'localization'   => 'false',
            'tickers'        => 'false',
            'market_data'    => 'true',
            'community_data' => 'false',
            'developer_data' => 'false',
            'sparkline'      => 'false',
        ]);
        if ($data && isset($data['market_data']['current_price'][$vs])) {
            $md = $data['market_data'];
            return [
                'price'           => (float) $md['current_price'][$vs],
                'change_24h'      => isset($md['price_change_percentage_24h_in_currency'][$vs]) ? (float) $md['price_change_percentage_24h_in_currency'][$vs] : (isset($md['price_change_percentage_24h']) ? (float) $md['price_change_percentage_24h'] : null),
                'change_7d'       => isset($md['price_change_percentage_7d_in_currency'][$vs]) ? (float) $md['price_change_percentage_7d_in_currency'][$vs] : null,
                'change_30d'      => isset($md['price_change_percentage_30d_in_currency'][$vs]) ? (float) $md['price_change_percentage_30d_in_currency'][$vs] : null,
                'icon_candidates' => !empty($data['image']['large']) ? [$data['image']['large']] : (!empty($data['image']['small']) ? [$data['image']['small']] : []),
            ];
        }
        if ($i < $attempts) {
            usleep($delayUs);
        }
    }
    return null;
}

function fetch_market_chart($conf, $CONFIG, $days) {
    $id = $conf['cg_id'] ?? null;
    if (!$id) return null;
    $vs = $CONFIG['coingecko']['vs_currency'] ?? 'usd';
    $data = cg_api_get($CONFIG, '/coins/' . rawurlencode($id) . '/market_chart', [
        'vs_currency' => $vs,
        'days'        => $days,
    ]);
    if (!$data || empty($data['prices']) || !is_array($data['prices'])) {
        return null;
    }
    $history = [];
    foreach ($data['prices'] as $point) {
        if (!isset($point[0], $point[1])) continue;
        $history[] = ['t' => (int) round($point[0] / 1000), 'p' => (float) $point[1]];
    }
    return $history;
}

function load_json_atomic($path) {
    if (!file_exists($path)) return null;
    $content = file_get_contents($path);
    $decoded = json_decode($content, true);
    return is_array($decoded) ? $decoded : null;
}

function save_json_atomic($path, $data) {
    $tmp = $path . '.' . uniqid('', true) . '.tmp';
    file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    rename($tmp, $path);
}

function bucket_to_candles($history, $numCandles) {
    $count = count($history);
    if ($count === 0) return [];
    if ($count === 1) {
        $p = $history[0]['p'];
        return [['t' => $history[0]['t'], 'o' => $p, 'h' => $p, 'l' => $p, 'c' => $p]];
    }

    $numCandles = min($numCandles, $count);
    $bucketSize = $count / $numCandles;
    $candles = [];
    for ($i = 0; $i < $numCandles; $i++) {
        $start = (int) floor($i * $bucketSize);
        $end   = (int) floor(($i + 1) * $bucketSize);
        $end   = max($end, $start + 1);
        $slice = array_slice($history, $start, $end - $start);
        $prices = array_column($slice, 'p');
        $candles[] = [
            't' => $slice[count($slice) - 1]['t'],
            'o' => $prices[0],
            'h' => max($prices),
            'l' => min($prices),
            'c' => end($prices),
        ];
    }
    return $candles;
}

function price_at_or_before($history, $cutoff) {
    $best = null;
    foreach ($history as $pt) {
        if ($pt['t'] <= $cutoff) {
            if ($best === null || $pt['t'] > $best['t']) $best = $pt;
        }
    }
    return $best !== null ? $best['p'] : ($history[0]['p'] ?? null);
}

function rgb($img, $r, $g, $b) {
    return imagecolorallocate($img, $r, $g, $b);
}

function rgba($img, $r, $g, $b, $a) {
    return imagecolorallocatealpha($img, $r, $g, $b, $a);
}

function fill_vgradient($img, $x, $y, $w, $h, $top, $bottom) {
    for ($i = 0; $i < $h; $i++) {
        $ratio = $h > 1 ? $i / ($h - 1) : 0;
        $r = (int) ($top[0] + ($bottom[0] - $top[0]) * $ratio);
        $g = (int) ($top[1] + ($bottom[1] - $top[1]) * $ratio);
        $b = (int) ($top[2] + ($bottom[2] - $top[2]) * $ratio);
        $c = imagecolorallocate($img, $r, $g, $b);
        imageline($img, $x, $y + $i, $x + $w, $y + $i, $c);
    }
}

function filled_rounded_rect($img, $x1, $y1, $x2, $y2, $radius, $color) {
    $radius = (int) min($radius, ($x2 - $x1) / 2, ($y2 - $y1) / 2);
    imagefilledrectangle($img, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
    imagefilledrectangle($img, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);
    imagefilledellipse($img, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($img, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($img, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($img, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
}

function draw_star($img, $cx, $cy, $r, $color) {
    $points = [
        $cx, $cy - $r,
        $cx + $r * 0.35, $cy - $r * 0.35,
        $cx + $r, $cy,
        $cx + $r * 0.35, $cy + $r * 0.35,
        $cx, $cy + $r,
        $cx - $r * 0.35, $cy + $r * 0.35,
        $cx - $r, $cy,
        $cx - $r * 0.35, $cy - $r * 0.35,
    ];
    imagefilledpolygon($img, $points, $color);
}

function load_image_resource($path) {
    $info = @getimagesize($path);
    if (!$info) return null;
    switch ($info['mime']) {
        case 'image/png':  return @imagecreatefrompng($path);
        case 'image/jpeg': return @imagecreatefromjpeg($path);
        case 'image/webp': return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null;
        case 'image/gif':  return @imagecreatefromgif($path);
        default: return null;
    }
}

function draw_coin_icon($img, $iconPath, $cx, $cy, $r) {
    if (!$iconPath || !file_exists($iconPath)) return false;
    $src = load_image_resource($iconPath);
    if (!$src) return false;
    $srcW = imagesx($src);
    $srcH = imagesy($src);
    if ($srcW <= 0 || $srcH <= 0) { imagedestroy($src); return false; }

    $size = (int) round($r * 2);
    $side = min($srcW, $srcH);
    $srcX = (int) (($srcW - $side) / 2);
    $srcY = (int) (($srcH - $side) / 2);

    $square = imagecreatetruecolor($size, $size);
    imagesavealpha($square, true);
    $transparent = imagecolorallocatealpha($square, 0, 0, 0, 127);
    imagefill($square, 0, 0, $transparent);
    imagealphablending($square, true);
    imagecopyresampled($square, $src, 0, 0, $srcX, $srcY, $size, $size, $side, $side);
    imagedestroy($src);

    $circle = imagecreatetruecolor($size, $size);
    imagesavealpha($circle, true);
    imagefill($circle, 0, 0, $transparent);
    imagealphablending($circle, false);
    $rr = $r * $r;
    for ($y = 0; $y < $size; $y++) {
        $dy = $y - $r + 0.5;
        for ($x = 0; $x < $size; $x++) {
            $dx = $x - $r + 0.5;
            if ($dx * $dx + $dy * $dy <= $rr) {
                imagesetpixel($circle, $x, $y, imagecolorat($square, $x, $y));
            }
        }
    }
    imagedestroy($square);

    imagealphablending($img, true);
    imagecopy($img, $circle, (int) round($cx - $r), (int) round($cy - $r), 0, 0, $size, $size);
    imagedestroy($circle);
    return true;
}

function text_width($fontPath, $size, $text) {
    if (function_exists('imagettfbbox') && $fontPath) {
        $box = @imagettfbbox($size, 0, $fontPath, $text);
        if ($box) return abs($box[2] - $box[0]);
    }
    return strlen($text) * ($size * 0.6);
}

function draw_text($img, $fontPath, $size, $x, $y, $color, $text, $align = 'left') {
    $usesTtf = function_exists('imagettftext') && $fontPath && file_exists($fontPath);
    $w = text_width($fontPath, $size, $text);
    if ($align === 'right') $x -= $w;
    if ($align === 'center') $x -= $w / 2;

    if ($usesTtf) {
        @imagettftext($img, $size, 0, (int) $x, (int) ($y + $size), $color, $fontPath, $text);
        return;
    }
    $gdFont = $size >= 20 ? 5 : ($size >= 14 ? 4 : 3);
    imagestring($img, $gdFont, (int) $x, (int) $y, $text, $color);
}

function glow_blob($img, $cx, $cy, $maxR, $r, $g, $b, $steps = 16) {
    for ($i = $steps; $i >= 1; $i--) {
        $radius = $maxR * $i / $steps;
        $alpha = max(90, min(122, 127 - (int) (($steps - $i + 1) * (37 / $steps))));
        $c = imagecolorallocatealpha($img, $r, $g, $b, $alpha);
        imagefilledellipse($img, $cx, $cy, $radius * 2, $radius * 2, $c);
    }
}

function shadow_under_rect($img, $x1, $y1, $x2, $y2, $radius, $offsetY = 14, $layers = 10) {
    for ($i = $layers; $i >= 1; $i--) {
        $grow = $i * 2.2;
        $alpha = 122 - (int) (($layers - $i) * (24 / $layers));
        $c = imagecolorallocatealpha($img, 20, 30, 60, max(105, min(126, $alpha)));
        filled_rounded_rect(
            $img,
            $x1 - $grow, $y1 - $grow + $offsetY,
            $x2 + $grow, $y2 + $grow + $offsetY,
            $radius + $grow, $c
        );
    }
}

function dashed_hline($img, $x1, $x2, $y, $color, $dash = 5, $gap = 6) {
    $x = $x1;
    while ($x < $x2) {
        $xEnd = min($x + $dash, $x2);
        imageline($img, (int) $x, (int) $y, (int) $xEnd, (int) $y, $color);
        $x += $dash + $gap;
    }
}

function gradient_hline($img, $x1, $x2, $y, $thickness, $c1, $c2) {
    $w = $x2 - $x1;
    for ($x = $x1; $x < $x2; $x++) {
        $ratio = $w > 0 ? ($x - $x1) / $w : 0;
        $r = (int) ($c1[0] + ($c2[0] - $c1[0]) * $ratio);
        $g = (int) ($c1[1] + ($c2[1] - $c1[1]) * $ratio);
        $b = (int) ($c1[2] + ($c2[2] - $c1[2]) * $ratio);
        $col = imagecolorallocate($img, $r, $g, $b);
        for ($t = 0; $t < $thickness; $t++) {
            imagesetpixel($img, (int) $x, (int) ($y + $t), $col);
        }
    }
}

function catmull_rom($p0, $p1, $p2, $p3, $t) {
    $t2 = $t * $t;
    $t3 = $t2 * $t;
    return 0.5 * (
        (2 * $p1) +
        (-$p0 + $p2) * $t +
        (2 * $p0 - 5 * $p1 + 4 * $p2 - $p3) * $t2 +
        (-$p0 + 3 * $p1 - 3 * $p2 + $p3) * $t3
    );
}

function smooth_series($values, $samplesPerSegment = 10) {
    $n = count($values);
    if ($n === 0) return [];
    if ($n === 1) return [$values[0], $values[0]];

    $out = [];
    for ($i = 0; $i < $n - 1; $i++) {
        $p0 = $values[max(0, $i - 1)];
        $p1 = $values[$i];
        $p2 = $values[$i + 1];
        $p3 = $values[min($n - 1, $i + 2)];
        for ($s = 0; $s < $samplesPerSegment; $s++) {
            $t = $s / $samplesPerSegment;
            $out[] = catmull_rom($p0, $p1, $p2, $p3, $t);
        }
    }
    $out[] = $values[$n - 1];
    return $out;
}

function generate_chart($key, $label, $price, $candles, $changePct, $width, $height, $chartsDir, $fontsDir, $iconPath = null, $periodLabel = '24h', $unitLabel = 'USD') {
    $unitSlug = preg_replace('/[^a-z0-9]+/', '', strtolower($unitLabel)) ?: 'usd';
    $scale = $width / 1000;
    $s = fn($v) => (int) round($v * $scale);

    $fontRegular = $fontsDir . '/Poppins-Regular.ttf';
    $fontMedium  = $fontsDir . '/Poppins-Medium.ttf';
    $fontBold    = $fontsDir . '/Poppins-Bold.ttf';
    if (!file_exists($fontRegular)) $fontRegular = $fontsDir . '/DejaVuSans.ttf';
    if (!file_exists($fontBold))    $fontBold    = $fontsDir . '/DejaVuSans-Bold.ttf';
    if (!file_exists($fontMedium))  $fontMedium  = $fontRegular;
    if (!file_exists($fontRegular)) $fontRegular = null;
    if (!file_exists($fontMedium))  $fontMedium  = null;
    if (!file_exists($fontBold))    $fontBold    = null;

    $img = imagecreatetruecolor($width, $height);
    imageantialias($img, true);
    imagealphablending($img, true);
    imagesavealpha($img, false);

    fill_vgradient($img, 0, 0, $width, $height, [24, 16, 48], [7, 8, 18]);

    glow_blob($img, $width * 0.08, $height * 0.06, $s(230), 139, 92, 246, 14);
    glow_blob($img, $width * 0.97, $height * 0.1, $s(200), 56, 189, 248, 12);
    glow_blob($img, $width * 0.95, $height * 0.97, $s(230), 45, 212, 160, 10);
    glow_blob($img, $width * 0.03, $height * 0.95, $s(190), 250, 204, 21, 8);
    glow_blob($img, $width * 0.5, $height * 0.45, $s(280), 88, 60, 186, 6);

    gradient_hline($img, 0, $width, 0, max(1, $s(5)), [124, 92, 246], [56, 189, 248]);

    $cardMargin = $s(30);
    $cx1 = $cardMargin; $cy1 = $cardMargin + $s(12);
    $cx2 = $width - $cardMargin; $cy2 = $height - $cardMargin;
    $radius = $s(28);

    shadow_under_rect($img, $cx1, $cy1, $cx2, $cy2, $radius, $s(16), 8);

    $cardBgArr = [21, 23, 42];
    $cardBg = rgb($img, $cardBgArr[0], $cardBgArr[1], $cardBgArr[2]);
    $borderColor = rgba($img, 255, 255, 255, 100);
    filled_rounded_rect($img, $cx1, $cy1, $cx2, $cy2, $radius, $borderColor);
    filled_rounded_rect($img, $cx1 + 1, $cy1 + 1, $cx2 - 1, $cy2 - 1, $radius - 1, $cardBg);
    imagesetthickness($img, max(1, $s(1)));

    $white      = rgb($img, 244, 245, 250);
    $gray       = rgb($img, 148, 152, 178);
    $lightGray  = rgba($img, 255, 255, 255, 110);
    $pillBg     = rgba($img, 255, 255, 255, 105);
    $pillText   = rgb($img, 200, 202, 220);

    $trendUp = $changePct >= 0;
    if ($trendUp) {
        $accentArr = [45, 212, 160];
        $accent    = rgb($img, $accentArr[0], $accentArr[1], $accentArr[2]);
        $badgeBg   = rgba($img, 45, 212, 160, 90);
        $glowR = 45; $glowG = 212; $glowB = 160;
    } else {
        $accentArr = [248, 90, 90];
        $accent    = rgb($img, $accentArr[0], $accentArr[1], $accentArr[2]);
        $badgeBg   = rgba($img, 248, 90, 90, 90);
        $glowR = 248; $glowG = 90; $glowB = 90;
    }

    $pad = $s(44);

    $iconCx = $cx1 + $pad + $s(26);
    $iconCy = $cy1 + $pad + $s(18);
    glow_blob($img, $iconCx, $iconCy, $s(50), 124, 92, 246, 12);
    imagefilledellipse($img, $iconCx, $iconCy, $s(64), $s(64), rgba($img, 255, 255, 255, 40));
    imagefilledellipse($img, $iconCx, $iconCy, $s(58), $s(58), rgb($img, 246, 247, 252));
    imagefilledellipse($img, $iconCx, $iconCy, $s(55), $s(55), rgb($img, 230, 232, 245));
    $iconDrawn = draw_coin_icon($img, $iconPath, $iconCx, $iconCy, $s(26));
    if (!$iconDrawn) {
        imagefilledellipse($img, $iconCx, $iconCy, $s(56), $s(56), rgb($img, 99, 78, 216));
        imagefilledellipse($img, $iconCx - $s(7), $iconCy - $s(8), $s(28), $s(28), rgb($img, 150, 132, 245));
        draw_star($img, $iconCx, $iconCy, $s(12), rgb($img, 255, 255, 255));
    }

    $titleSize = 24 * $scale;
    draw_text($img, $fontBold, $titleSize, $iconCx + $s(40), $iconCy - $s(16), $white, strtoupper($label));

    $liveDotX = $iconCx + $s(40) + text_width($fontBold, $titleSize, strtoupper($label)) + $s(18);
    $liveDotY = $iconCy - $s(1);
    glow_blob($img, $liveDotX, $liveDotY, $s(12), 45, 212, 160, 8);
    imagefilledellipse($img, $liveDotX, $liveDotY, $s(9), $s(9), rgb($img, 45, 212, 160));
    draw_text($img, $fontBold, 12 * $scale, $liveDotX + $s(10), $liveDotY - $s(8), rgb($img, 45, 212, 160), 'LIVE');

    $pairText = strtoupper($label) . ' / ' . strtoupper($unitLabel);
    $pairFontSize = 14 * $scale;
    $pairW = text_width($fontBold, $pairFontSize, $pairText) + $s(34);
    $pairX2 = $cx2 - $pad;
    $pairX1 = $pairX2 - $pairW;
    filled_rounded_rect($img, $pairX1, $cy1 + $pad - $s(6), $pairX2, $cy1 + $pad + $s(28), $s(15), $pillBg);
    draw_text($img, $fontMedium, $pairFontSize, $pairX1 + $s(17), $cy1 + $pad + $s(2), $pillText, $pairText);

    $priceY = $iconCy + $s(42);
    $priceStr = number_format($price, $price < 10 ? 2 : 0);
    $priceFontSize = 48 * $scale;
    $maxPriceWidth = ($cx2 - $pad) - ($cx1 + $pad);
    while ($priceFontSize > 18 * $scale && text_width($fontBold, $priceFontSize, $priceStr) > $maxPriceWidth * 0.62) {
        $priceFontSize -= 1 * $scale;
    }
    draw_text($img, $fontBold, $priceFontSize, $cx1 + $pad, $priceY, $white, $priceStr);
    $unitX = $cx1 + $pad + text_width($fontBold, $priceFontSize, $priceStr) + $s(12);
    $unitY = $priceY + ($priceFontSize - 18 * $scale);
    draw_text($img, $fontMedium, 18 * $scale, $unitX, $unitY, $gray, strtoupper($unitLabel));

    $badgeY1 = $priceY + $s(64);
    $badgeText = ($trendUp ? '▲ +' : '▼ ') . number_format($changePct, 2) . '%';
    $badgeFontSize = 15 * $scale;
    $badgeW = text_width($fontBold, $badgeFontSize, $badgeText) + $s(32);
    glow_blob($img, $cx1 + $pad + $badgeW / 2, $badgeY1 + $s(15), $badgeW * 0.55, $glowR, $glowG, $glowB, 8);
    filled_rounded_rect($img, $cx1 + $pad, $badgeY1, $cx1 + $pad + $badgeW, $badgeY1 + $s(32), $s(16), $badgeBg);
    draw_text($img, $fontBold, $badgeFontSize, $cx1 + $pad + $s(16), $badgeY1 + $s(7), $accent, $badgeText);

    draw_text($img, $fontRegular, 13 * $scale, $cx1 + $pad + $badgeW + $s(16), $badgeY1 + $s(9), $gray, 'last ' . $periodLabel);

    $dividerY = $badgeY1 + $s(52);
    gradient_hline($img, $cx1 + $pad, $cx2 - $pad, $dividerY, max(1, $s(1)), [70, 72, 100], [21, 23, 42]);

    $footerFontSize = 12 * $scale;
    $footerLine2Y = $cy2 - $s(30);
    $footerLine1Y = $footerLine2Y - $s(22);
    $chartTop0 = $dividerY + $s(24);
    $chartBottom0 = $footerLine1Y - $s(16);
    $chartLeft0 = $cx1 + $pad;
    $chartRight0 = $cx2 - $pad - $s(54);

    $insetX = ($chartRight0 - $chartLeft0) * 0.01;
    $insetY = ($chartBottom0 - $chartTop0) * 0.18;
    $chartTop = $chartTop0 + $insetY;
    $chartBottom = $chartBottom0 - $insetY;
    $chartLeft = $chartLeft0 + $insetX;
    $chartRight = $chartRight0 - $insetX;
    $chartW = $chartRight - $chartLeft;
    $chartH = $chartBottom - $chartTop;

    $closes = array_column($candles, 'c');
    $highs  = array_column($candles, 'h');
    $lows   = array_column($candles, 'l');
    $max = max($highs);
    $min = min($lows);
    if ($min == $max) { $min *= 0.98; $max *= 1.02; }
    $range = $max - $min;
    $padRange = $range * 0.12;
    $max += $padRange;
    $min -= $padRange;
    $range = $max - $min;

    for ($i = 0; $i <= 4; $i++) {
        $y = (int) ($chartTop + ($chartH / 4) * $i);
        dashed_hline($img, $chartLeft, $chartRight, $y, $lightGray, max(2, $s(5)), max(2, $s(6)));
        $val = $max - ($range / 4) * $i;
        draw_text($img, $fontRegular, 11 * $scale, $chartRight + $s(10), $y - $s(6), $gray, number_format($val, $val < 10 ? 3 : 2));
    }

    $smoothed = smooth_series($closes, 10);
    $sN = count($smoothed);

    $points = [];
    for ($i = 0; $i < $sN; $i++) {
        $x = $chartLeft + ($sN > 1 ? ($chartW * $i / ($sN - 1)) : 0);
        $y = $chartTop + $chartH - (($smoothed[$i] - $min) / $range) * $chartH;
        $points[] = [$x, $y];
    }

    $colWidth = $sN > 1 ? max($s(2), (int) ceil($chartW / ($sN - 1)) + 1) : $chartW;
    foreach ($points as $pt) {
        [$px, $py] = $pt;
        $h = $chartBottom - $py;
        if ($h <= 0) continue;
        fill_vgradient($img, (int) ($px - $colWidth / 2), (int) $py, $colWidth, (int) $h, $accentArr, $cardBgArr);
    }

    for ($i = 0; $i < $sN - 1; $i++) {
        [$x1, $y1] = $points[$i];
        [$x2, $y2] = $points[$i + 1];
        $glowColor = imagecolorallocatealpha($img, $accentArr[0], $accentArr[1], $accentArr[2], 105);
        imagesetthickness($img, max(1, $s(5)));
        imageline($img, (int) $x1, (int) $y1, (int) $x2, (int) $y2, $glowColor);
        imagesetthickness($img, max(1, $s(2)));
        imageline($img, (int) $x1, (int) $y1, (int) $x2, (int) $y2, $accent);
    }
    imagesetthickness($img, max(1, $s(1)));

    if (!empty($points)) {
        [$lastX, $lastY] = end($points);
        glow_blob($img, $lastX, $lastY, $s(18), $accentArr[0], $accentArr[1], $accentArr[2], 10);
        imagefilledellipse($img, (int) $lastX, (int) $lastY, $s(11), $s(11), rgb($img, 255, 255, 255));
        imagefilledellipse($img, (int) $lastX, (int) $lastY, $s(7), $s(7), $accent);
    }

    $footerColor = rgb($img, 120, 122, 148);
    draw_text($img, $fontRegular, $footerFontSize, $width / 2, $footerLine1Y, $footerColor, 'J Code API  •  Live Market Data', 'center');
    draw_text($img, $fontRegular, $footerFontSize, $width / 2, $footerLine2Y, $footerColor, 'Designer: Ali Mousavi  ---  Programmer: J Code', 'center');

    $fileName = 'chart_' . $key . '.' . $unitSlug . '.png';
    $filePath = $chartsDir . '/' . $fileName;
    $tmp = $filePath . '.tmp';
    imagepng($img, $tmp, 6);
    imagedestroy($img);
    rename($tmp, $filePath);

    return $fileName;
}

function find_alias_snapshot($key, $conf, $currencies, $CONFIG) {
    if (!$currencies) return null;
    foreach ($currencies as $otherKey => $otherConf) {
        if ($otherKey === $key) continue;
        if (($otherConf['cg_id'] ?? null) !== ($conf['cg_id'] ?? null) || empty($conf['cg_id'])) continue;
        $record = load_json_atomic($CONFIG['paths']['data'] . '/' . $otherKey . '.json');
        if ($record === null) continue;
        if ((time() - $record['updated']) >= $CONFIG['refresh_after_seconds']) continue;
        $iconPath = null;
        if (!empty($record['icon_file'])) {
            $candidate = rtrim($CONFIG['paths']['icons'], '/') . '/' . $record['icon_file'];
            if (file_exists($candidate)) $iconPath = $candidate;
        }
        return [
            'price'      => $record['price'],
            'change_24h' => $record['change_24h'] ?? null,
            'change_7d'  => $record['change_7d'] ?? null,
            'change_30d' => $record['change_30d'] ?? null,
            'icon_path'  => $iconPath,
        ];
    }
    return null;
}

function finalize_price_record($key, $conf, $CONFIG, $existingRecord, $snapshot, $iconPath) {
    if (!$iconPath && !empty($existingRecord['icon_file'])) {
        $candidate = rtrim($CONFIG['paths']['icons'], '/') . '/' . $existingRecord['icon_file'];
        if (file_exists($candidate)) $iconPath = $candidate;
    }

    foreach ((@glob($CONFIG['paths']['charts'] . '/chart_' . $key . '.*.png') ?: []) as $staleChart) {
        @unlink($staleChart);
    }

    $now = time();
    $record = [
        'price'      => $snapshot['price'],
        'change_24h' => $snapshot['change_24h'] ?? 0.0,
        'change_7d'  => $snapshot['change_7d'] ?? null,
        'change_30d' => $snapshot['change_30d'] ?? null,
        'updated'    => $now,
        'icon_file'  => $iconPath ? basename($iconPath) : ($existingRecord['icon_file'] ?? null),
    ];

    $dataPath = $CONFIG['paths']['data'] . '/' . $key . '.json';
    save_json_atomic($dataPath, $record);

    return $record;
}

function fetch_and_store_price($key, $conf, $CONFIG, $existingRecord, $currencies = null) {
    $alias = find_alias_snapshot($key, $conf, $currencies, $CONFIG);
    if ($alias !== null) {
        return finalize_price_record($key, $conf, $CONFIG, $existingRecord, $alias, $alias['icon_path']);
    }

    $slot = acquire_fetch_slot($CONFIG);
    if (!$slot) {
        return null;
    }
    $snapshot = fetch_currency_snapshot($conf, $CONFIG);
    if ($snapshot === null) {
        release_lock($slot);
        return null;
    }
    $iconPath = ensure_icon($key, $snapshot['icon_candidates'], $CONFIG['paths']['icons'], $CONFIG);
    release_lock($slot);

    return finalize_price_record($key, $conf, $CONFIG, $existingRecord, $snapshot, $iconPath);
}

function fetch_and_store_prices_batch($items, $CONFIG, $currencies = null, $deadline = null) {
    $results = [];
    $needFetch = [];

    foreach ($items as $key => $item) {
        $conf = $item['conf'];
        $alias = find_alias_snapshot($key, $conf, $currencies, $CONFIG);
        if ($alias !== null) {
            $results[$key] = finalize_price_record($key, $conf, $CONFIG, $item['existing'], $alias, $alias['icon_path']);
        } else {
            $needFetch[$key] = $conf;
        }
    }

    if ($needFetch) {
        $snapshots = fetch_snapshots_batch($needFetch, $CONFIG, $deadline);
        foreach ($needFetch as $key => $conf) {
            $snapshot = $snapshots[$key] ?? null;
            if ($snapshot === null) {
                $results[$key] = null;
                continue;
            }
            $existing = $items[$key]['existing'];
            $iconPath = ensure_icon($key, $snapshot['icon_candidates'], $CONFIG['paths']['icons'], $CONFIG);
            $results[$key] = finalize_price_record($key, $conf, $CONFIG, $existing, $snapshot, $iconPath);
        }
    }

    return $results;
}

function get_fresh_currency_record($key, $conf, $CONFIG, $currencies = null) {
    $dataPath = $CONFIG['paths']['data'] . '/' . $key . '.json';
    $apcuKey  = 'japi_rec_' . $key;
    $refreshAfter = $CONFIG['refresh_after_seconds'];

    $record = null;
    $fromApcu = false;
    if (function_exists('apcu_fetch')) {
        $cached = apcu_fetch($apcuKey, $ok);
        if ($ok) { $record = $cached; $fromApcu = true; }
    }
    if ($record === null) {
        $record = load_json_atomic($dataPath);
    }

    $age = $record ? (time() - $record['updated']) : PHP_INT_MAX;

    if ($record !== null && $age < $refreshAfter) {
        if (!$fromApcu && function_exists('apcu_store')) {
            apcu_store($apcuKey, $record, $refreshAfter);
        }
        return $record;
    }

    if (!is_dir($CONFIG['paths']['locks'])) {
        @mkdir($CONFIG['paths']['locks'], 0755, true);
    }
    $lockFile = $CONFIG['paths']['locks'] . '/' . $key . '.lock';

    $hasNoDataAtAll = ($record === null);
    $waitMs = $hasNoDataAtAll
        ? max($CONFIG['lock_wait_ms'], $CONFIG['first_fetch_wait_ms'] ?? 8000)
        : $CONFIG['lock_wait_ms'];

    $lock = acquire_lock_wait($lockFile, $waitMs);
    if ($lock) {
        $fresh = fetch_and_store_price($key, $conf, $CONFIG, $record, $currencies);
        release_lock($lock);
        if ($fresh !== null) {
            if (function_exists('apcu_store')) {
                apcu_store($apcuKey, $fresh, $refreshAfter);
            }
            return $fresh;
        }
        return $record;
    }

    if ($record !== null) {
        return $record;
    }
    return load_json_atomic($dataPath);
}

function price_n_days_ago($history, $days) {
    $cutoff = time() - ((int) $days) * 86400;
    return price_at_or_before($history, $cutoff);
}