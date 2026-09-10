<?php
// Copyright 2026 J Code
// SPDX-License-Identifier: Apache-2.0
require_once __DIR__ . '/config.php';

function isPublicIp($ip) {
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return false;
    }

    if (strpos($ip, ':') !== false && preg_match('/(\d+\.\d+\.\d+\.\d+)$/', $ip, $m)) {
        if (filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
    }
    return true;
}

function resolvePublicIp($host) {
    if (!is_string($host) || $host === '') return false;
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return isPublicIp($host) ? $host : false;
    }
    $ips = gethostbynamel($host);
    if (!$ips) return false;
    foreach ($ips as $ip) {
        if (!isPublicIp($ip)) return false;
    }
    return $ips[0];
}

function validateAndNormalizeSite($site) {
    $site = is_string($site) ? trim($site) : '';
    if ($site === '') return false;

    if (!preg_match('#^https?://#i', $site)) {
        $site = 'http://' . $site;
    }

    if (!filter_var($site, FILTER_VALIDATE_URL)) {
        return false;
    }

    $host = parse_url($site, PHP_URL_HOST);
    if (!$host) return false;

    if (resolvePublicIp($host) === false) return false;

    return $site;
}

function safeResolve($url) {
    if (!is_string($url) || !filter_var($url, FILTER_VALIDATE_URL)) return null;
    $host = parse_url($url, PHP_URL_HOST);
    $scheme = parse_url($url, PHP_URL_SCHEME);
    if (!$host || !in_array($scheme, ['http', 'https'], true)) return null;
    $ip = resolvePublicIp($host);
    if ($ip === false) return null;
    $port = parse_url($url, PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80);
    return ['host' => $host, 'port' => $port, 'ip' => $ip];
}

function clampTimeout($timeout, $remainingSeconds) {
    return max(1, (int) floor(min($timeout, $remainingSeconds)));
}

function deadlineExhausted($requestDeadline) {
    return $requestDeadline !== null && ($requestDeadline - microtime(true)) < MIN_NETWORK_BUDGET;
}

function safeFetch($url, $maxRedirects = 3, $connectTimeout = TIMEOUT_CONNECT_AUX, $responseTimeout = TIMEOUT_RESPONSE_AUX, $requestDeadline = null) {
    for ($i = 0; $i <= $maxRedirects; $i++) {
        if (deadlineExhausted($requestDeadline)) return false;

        $hopConnectTimeout = $connectTimeout;
        $hopResponseTimeout = $responseTimeout;
        if ($requestDeadline !== null) {
            $remaining = $requestDeadline - microtime(true);
            $hopConnectTimeout = clampTimeout($connectTimeout, $remaining);
            $hopResponseTimeout = clampTimeout($responseTimeout, $remaining);
        }

        $target = safeResolve($url);
        if ($target === null) return false;

        $headerBuf = '';
        $bodyBuf = '';
        $bodyBytes = 0;
        $aborted = false;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADERFUNCTION => function ($curlHandle, $headerLine) use (&$headerBuf) {
                $headerBuf .= $headerLine;
                return strlen($headerLine);
            },
            CURLOPT_WRITEFUNCTION => function ($curlHandle, $chunk) use (&$bodyBuf, &$bodyBytes, &$aborted) {
                $bodyBytes += strlen($chunk);
                if ($bodyBytes > MAX_RESPONSE_BYTES) {
                    $aborted = true;
                    return -1;
                }
                $bodyBuf .= $chunk;
                return strlen($chunk);
            },
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $hopConnectTimeout,
            CURLOPT_TIMEOUT => $hopResponseTimeout,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => USER_AGENT,
            CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:{$target['ip']}"],
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($aborted) return false;
        if ($bodyBuf === '' && $headerBuf === '') return false;

        if (in_array($httpCode, [301, 302, 303, 307, 308], true)) {
            if (preg_match('/^Location:\s*(.+)$/mi', $headerBuf, $m)) {
                $url = resolveUrl($url, trim($m[1]));
                continue;
            }
            return false;
        }

        return ['headers' => $headerBuf, 'body' => $bodyBuf, 'http_code' => $httpCode];
    }
    return false;
}

function makeHttpRequest($url, $connectTimeout = TIMEOUT_CONNECT_AUX, $responseTimeout = TIMEOUT_RESPONSE_AUX, $requestDeadline = null) {
    $result = safeFetch($url, 3, $connectTimeout, $responseTimeout, $requestDeadline);
    return $result === false ? false : $result['body'];
}

function parallelFetch(array $urlsByKey, $connectTimeout, $responseTimeout, $nobody = false, $requestDeadline = null) {
    if (deadlineExhausted($requestDeadline)) {
        return array_fill_keys(array_keys($urlsByKey), false);
    }
    if ($requestDeadline !== null) {
        $remaining = $requestDeadline - microtime(true);
        $connectTimeout = clampTimeout($connectTimeout, $remaining);
        $responseTimeout = clampTimeout($responseTimeout, $remaining);
    }

    $results = [];
    $mh = curl_multi_init();
    $handles = [];
    $buffers = [];

    foreach ($urlsByKey as $key => $url) {
        $target = safeResolve($url);
        if ($target === null) {
            $results[$key] = false;
            continue;
        }
        $buffers[$key] = ['body' => '', 'headers' => '', 'aborted' => false];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_NOBODY => $nobody,
            CURLOPT_HEADERFUNCTION => function ($curlHandle, $headerLine) use (&$buffers, $key) {
                $buffers[$key]['headers'] .= $headerLine;
                return strlen($headerLine);
            },
            CURLOPT_WRITEFUNCTION => function ($curlHandle, $chunk) use (&$buffers, $key) {
                $buffers[$key]['body'] .= $chunk;
                if (strlen($buffers[$key]['body']) > MAX_RESPONSE_BYTES) {
                    $buffers[$key]['aborted'] = true;
                    return -1;
                }
                return strlen($chunk);
            },
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $responseTimeout,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => USER_AGENT,
            CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:{$target['ip']}"],
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$key] = $ch;
    }

    if ($handles) {
        $running = null;
        do {
            curl_multi_exec($mh, $running);
            if ($running > 0 && curl_multi_select($mh, 1.0) === -1) {
                usleep(10000);
            }
        } while ($running > 0);

        foreach ($handles as $key => $ch) {
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            if ($buffers[$key]['aborted']) {
                $results[$key] = false;
            } elseif ($buffers[$key]['body'] === '' && $buffers[$key]['headers'] === '') {
                $results[$key] = false;
            } else {
                $results[$key] = [
                    'body' => $buffers[$key]['body'],
                    'headers' => $buffers[$key]['headers'],
                    'http_code' => $httpCode,
                ];
            }
        }
    }

    curl_multi_close($mh);
    return $results;
}

function getIpInfo($host, $requestDeadline = null) {
    $ip = resolvePublicIp($host);
    if ($ip === false) return ['ip' => null, 'isp' => NOT_FOUND_MSG];
    if (deadlineExhausted($requestDeadline)) return ['ip' => $ip, 'isp' => NOT_FOUND_MSG];

    $apiUrl = "https://ipwho.is/{$ip}";
    $response = makeHttpRequest($apiUrl, TIMEOUT_CONNECT_AUX, TIMEOUT_RESPONSE_AUX, $requestDeadline);
    if (!$response) {
        return ['ip' => $ip, 'isp' => NOT_FOUND_MSG];
    }
    $data = json_decode($response, true);
    if (is_array($data) && ($data['success'] ?? false) === true) {
        $connection = $data['connection'] ?? [];
        return [
            'ip' => $ip,
            'isp' => $connection['isp'] ?? NOT_FOUND_MSG,
            'org' => $connection['org'] ?? NOT_FOUND_MSG,
            'as' => isset($connection['asn']) ? ('AS' . $connection['asn']) : NOT_FOUND_MSG,
            'country' => $data['country'] ?? NOT_FOUND_MSG,
            'city' => $data['city'] ?? NOT_FOUND_MSG,
        ];
    }
    return ['ip' => $ip, 'isp' => NOT_FOUND_MSG];
}

function checkProtocols($host, $requestDeadline = null) {
    $ip = resolvePublicIp($host);
    if ($ip === false) return [NOT_FOUND_MSG];

    $requests = ['http' => "http://$host", 'https' => "https://$host"];
    $results = parallelFetch($requests, TIMEOUT_CONNECT_AUX, TIMEOUT_RESPONSE_AUX, true, $requestDeadline);

    $protocols = [];
    foreach (['http', 'https'] as $proto) {
        if ($results[$proto] !== false && $results[$proto]['http_code'] > 0) {
            $protocols[] = $proto;
        }
    }

    return $protocols ?: [NOT_FOUND_MSG];
}

function getDomainInfo($host, $requestDeadline = null) {
    $parts = explode('.', $host);
    $count = count($parts);

    $twoPartTlds = [
        'co.uk', 'org.uk', 'gov.uk', 'ac.uk', 'me.uk', 'net.uk',
        'co.jp', 'co.kr', 'co.in', 'co.nz', 'co.za', 'co.il',
        'com.au', 'net.au', 'org.au', 'com.br', 'com.cn', 'net.cn',
        'org.cn', 'com.tr', 'com.mx', 'com.sg', 'com.hk', 'co.id',
    ];

    $domain = $parts[$count - 1];
    if ($count >= 2) {
        $domain = $parts[$count - 2] . '.' . $parts[$count - 1];
    }
    if ($count >= 3) {
        $lastTwo = $parts[$count - 2] . '.' . $parts[$count - 1];
        if (in_array($lastTwo, $twoPartTlds, true)) {
            $domain = $parts[$count - 3] . '.' . $lastTwo;
        }
    }

    if (deadlineExhausted($requestDeadline)) {
        return [
            'domain_name' => $domain,
            'domain_type' => NOT_FOUND_MSG,
            'registrar' => NOT_FOUND_MSG,
            'creation_date' => NOT_FOUND_MSG,
            'expiration_date' => NOT_FOUND_MSG,
        ];
    }

    $rdapUrl = "https://rdap.org/domain/$domain";
    $response = makeHttpRequest($rdapUrl, TIMEOUT_CONNECT_AUX, TIMEOUT_RESPONSE_AUX, $requestDeadline);
    if (!$response) {
        return [
            'domain_name' => $domain,
            'domain_type' => NOT_FOUND_MSG,
            'registrar' => NOT_FOUND_MSG,
            'creation_date' => NOT_FOUND_MSG,
            'expiration_date' => NOT_FOUND_MSG,
        ];
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        return [
            'domain_name' => $domain,
            'domain_type' => NOT_FOUND_MSG,
            'registrar' => NOT_FOUND_MSG,
            'creation_date' => NOT_FOUND_MSG,
            'expiration_date' => NOT_FOUND_MSG,
        ];
    }

    $registrar = NOT_FOUND_MSG;
    $creation = NOT_FOUND_MSG;
    $expiration = NOT_FOUND_MSG;

    if (isset($data['entities'])) {
        foreach ($data['entities'] as $entity) {
            if (in_array('registrar', $entity['roles'] ?? [])) {
                $registrar = $entity['vcardArray'][1][1][3] ?? NOT_FOUND_MSG;
                break;
            }
        }
    }

    if (isset($data['events'])) {
        foreach ($data['events'] as $event) {
            if (($event['eventAction'] ?? '') === 'registration') {
                $creation = $event['eventDate'] ?? NOT_FOUND_MSG;
            }
            if (($event['eventAction'] ?? '') === 'expiration') {
                $expiration = $event['eventDate'] ?? NOT_FOUND_MSG;
            }
        }
    }

    $tld = end($parts);
    $domain_type = (strlen($tld) === 2) ? 'ccTLD' : 'gTLD';

    return [
        'domain_name' => $domain,
        'domain_type' => $domain_type,
        'registrar' => $registrar,
        'creation_date' => $creation,
        'expiration_date' => $expiration,
    ];
}

function scanPorts($host, $requestDeadline = null) {
    $ip = resolvePublicIp($host);
    if ($ip === false) return [NOT_FOUND_MSG];
    if (deadlineExhausted($requestDeadline)) return [NOT_FOUND_MSG];

    $ports = [
        21   => 'FTP',
        22   => 'SSH',
        23   => 'Telnet',
        25   => 'SMTP',
        53   => 'DNS',
        80   => 'HTTP',
        110  => 'POP3',
        143  => 'IMAP',
        443  => 'HTTPS',
        3306 => 'MySQL',
        3389 => 'RDP',
        5432 => 'PostgreSQL',
        8080 => 'HTTP-Alt',
    ];

    if (extension_loaded('sockets')) {
        $openPorts = scanPortsParallel($ip, $ports, $requestDeadline);
    } else {
        $openPorts = scanPortsSequential($ip, $ports, $requestDeadline);
    }

    return $openPorts ?: [NOT_FOUND_MSG];
}

function scanPortsParallel($ip, $ports, $requestDeadline = null) {
    $pending = [];
    foreach ($ports as $port => $service) {
        $sock = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($sock === false) continue;
        socket_set_nonblock($sock);

        @socket_connect($sock, $ip, $port);
        $pending[$port] = ['sock' => $sock, 'service' => $service];
    }

    $openPorts = [];

    $scanDeadline = microtime(true) + 1.5;
    if ($requestDeadline !== null) {
        $scanDeadline = min($scanDeadline, $requestDeadline);
    }
    while ($pending && microtime(true) < $scanDeadline) {
        $write = [];
        $except = [];
        foreach ($pending as $s) {
            $write[] = $s['sock'];
            $except[] = $s['sock'];
        }
        $read = [];
        $changed = @socket_select($read, $write, $except, 1);
        if ($changed === false) break;
        if ($changed === 0) continue;

        foreach (array_merge($write, $except) as $sock) {
            $port = null;
            foreach ($pending as $p => $s) {
                if ($s['sock'] === $sock) { $port = $p; break; }
            }
            if ($port === null) continue;

            $err = @socket_get_option($pending[$port]['sock'], SOL_SOCKET, SO_ERROR);
            if ($err === 0) {
                $openPorts[] = ['port' => $port, 'service' => $pending[$port]['service'], 'status' => 'open'];
            }
            @socket_close($pending[$port]['sock']);
            unset($pending[$port]);
        }
    }

    foreach ($pending as $s) {
        @socket_close($s['sock']);
    }

    return $openPorts;
}

function scanPortsSequential($ip, $ports, $requestDeadline = null) {
    $openPorts = [];
    foreach ($ports as $port => $service) {
        if (deadlineExhausted($requestDeadline)) break;
        $portTimeout = 1;
        if ($requestDeadline !== null) {
            $portTimeout = min($portTimeout, $requestDeadline - microtime(true));
        }
        $connection = @fsockopen($ip, $port, $errno, $errstr, $portTimeout);
        if (is_resource($connection)) {
            $openPorts[] = [
                'port' => $port,
                'service' => $service,
                'status' => 'open'
            ];
            fclose($connection);
        }
    }
    return $openPorts;
}

function getTechnologyFromHeaders($headers) {
    $tech = [];

    if (!$headers) {
        return ['server' => NOT_FOUND_MSG, 'x_powered_by' => NOT_FOUND_MSG, 'framework' => NOT_FOUND_MSG];
    }

    if (preg_match('/^Server:\s*(.+)$/mi', $headers, $m)) {
        $tech['server'] = trim($m[1]);
    } else {
        $tech['server'] = NOT_FOUND_MSG;
    }

    if (preg_match('/^X-Powered-By:\s*(.+)$/mi', $headers, $m)) {
        $tech['x_powered_by'] = trim($m[1]);
    } else {
        $tech['x_powered_by'] = NOT_FOUND_MSG;
    }

    if (preg_match('/^Set-Cookie:\s*(.+)$/mi', $headers, $m)) {
        $cookie = $m[1];
        if (stripos($cookie, 'PHPSESSID') !== false) $tech['framework'] = 'PHP';
        elseif (stripos($cookie, 'JSESSIONID') !== false) $tech['framework'] = 'Java';
        elseif (stripos($cookie, 'ASP.NET_SessionId') !== false) $tech['framework'] = 'ASP.NET';
        else $tech['framework'] = 'Unknown (cookie detected)';
    } else {
        $tech['framework'] = NOT_FOUND_MSG;
    }

    return $tech;
}

function getCookiesFromHeaders($headers) {
    if (!$headers) return [NOT_FOUND_MSG];

    preg_match_all('/^Set-Cookie:\s*(.+)$/mi', $headers, $matches);
    $cookies = [];
    foreach ($matches[1] as $cookieLine) {
        $parts = explode(';', $cookieLine);
        $cookie = trim($parts[0]);
        if ($cookie) $cookies[] = $cookie;
    }
    return $cookies ?: [NOT_FOUND_MSG];
}

function checkSSL($host, $requestDeadline = null) {
    $ip = resolvePublicIp($host);
    if ($ip === false) return [NOT_FOUND_MSG];
    if (deadlineExhausted($requestDeadline)) return [NOT_FOUND_MSG];

    $connectTimeout = TIMEOUT_CONNECT_AUX;
    if ($requestDeadline !== null) {
        $connectTimeout = clampTimeout($connectTimeout, $requestDeadline - microtime(true));
    }

    $context = stream_context_create([
        'ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'peer_name' => $host,
            'SNI_enabled' => true,
        ]
    ]);

    $client = @stream_socket_client(
        "ssl://$ip:443",
        $errno,
        $errstr,
        $connectTimeout,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!$client) return [NOT_FOUND_MSG];

    $params = stream_context_get_params($client);
    $cert = $params['options']['ssl']['peer_certificate'] ?? null;
    fclose($client);
    if (!$cert) return [NOT_FOUND_MSG];

    $certInfo = openssl_x509_parse($cert);
    return [
        'issuer' => $certInfo['issuer']['CN'] ?? NOT_FOUND_MSG,
        'valid_from' => date('Y-m-d H:i:s', $certInfo['validFrom_time_t'] ?? 0),
        'valid_to' => date('Y-m-d H:i:s', $certInfo['validTo_time_t'] ?? 0),
        'subject' => $certInfo['subject']['CN'] ?? NOT_FOUND_MSG,
    ];
}

function getMetaInfo($html) {
    $description = NOT_FOUND_MSG;
    $creator = NOT_FOUND_MSG;

    if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\'](.*?)["\']/i', $html, $m)) {
        $description = $m[1];
    } elseif (preg_match('/<meta[^>]+property=["\']og:description["\'][^>]+content=["\'](.*?)["\']/i', $html, $m)) {
        $description = $m[1];
    }

    if (preg_match('/<meta[^>]+name=["\']author["\'][^>]+content=["\'](.*?)["\']/i', $html, $m)) {
        $creator = $m[1];
    } elseif (preg_match('/<meta[^>]+name=["\']generator["\'][^>]+content=["\'](.*?)["\']/i', $html, $m)) {
        $creator = $m[1];
    } elseif (preg_match('/<meta[^>]+name=["\']copyright["\'][^>]+content=["\'](.*?)["\']/i', $html, $m)) {
        $creator = $m[1];
    }

    return [
        'description' => $description,
        'creator' => $creator,
    ];
}

function detectCpanel($host, $requestDeadline = null) {

    $requests = [
        'port_2082'         => "http://$host:2082/",
        'port_2083'         => "https://$host:2083/",
        'path_cpanel'       => "http://$host/cpanel",
        'path_cpanel_slash' => "http://$host/cpanel/",
        'path_whm'          => "http://$host/whm",
        'path_webmail'      => "http://$host/webmail",
    ];

    $results = parallelFetch($requests, TIMEOUT_CONNECT_AUX, TIMEOUT_RESPONSE_AUX, false, $requestDeadline);

    foreach (['port_2082', 'port_2083'] as $key) {
        if ($results[$key] !== false) {
            $body = $results[$key]['body'];
            if (stripos($body, 'cPanel') !== false || stripos($body, 'WHM') !== false) {
                return $requests[$key];
            }
        }
    }

    foreach (['path_cpanel', 'path_cpanel_slash', 'path_whm', 'path_webmail'] as $key) {
        if ($results[$key] !== false) {
            $body = $results[$key]['body'];
            if (stripos($body, 'cPanel') !== false || stripos($body, 'login') !== false) {
                return $requests[$key];
            }
        }
    }

    return NOT_FOUND_MSG;
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

function cleanupTempFiles() {
    $now = time();

    if (is_dir(TEMP_DIR)) {
        $files = glob(TEMP_DIR . '*');
        foreach ($files as $file) {
            if (basename($file) === '.htaccess') continue;
            if (is_file($file) || is_dir($file)) {
                if ($now - filemtime($file) > EXPIRATION_TIME) {
                    if (is_dir($file)) {
                        deleteDirectory($file);
                    } else {
                        unlink($file);
                    }
                }
            }
        }
    }

    if (is_dir(RATE_LIMIT_DIR)) {
        foreach ((glob(RATE_LIMIT_DIR . '*.json') ?: []) as $file) {
            if ($now - filemtime($file) > 3600) {
                @unlink($file);
            }
        }
    }
}

function deleteDirectory($dir) {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            deleteDirectory($path);
        } else {
            unlink($path);
        }
    }
    rmdir($dir);
}

function fetchSiteResources($url, $html, $requestDeadline = null) {
    protectDir(TEMP_DIR);

    $uniqueId = 'site_' . bin2hex(random_bytes(16));
    $folder = TEMP_DIR . $uniqueId;
    mkdir($folder, 0755, true);

    if ($html === false || $html === null || $html === '') {
        deleteDirectory($folder);
        return ['html' => NOT_FOUND_MSG, 'css' => [], 'js' => [], 'zip_link' => NOT_FOUND_MSG, 'file_count' => 0, 'file_paths' => []];
    }

    file_put_contents($folder . '/index.html', $html);

    preg_match_all('/<link[^>]+href=["\']([^"\']+\.css)["\']/i', $html, $cssMatches);
    preg_match_all('/<script[^>]+src=["\']([^"\']+\.js)["\']/i', $html, $jsMatches);

    $cssFiles = array_slice(array_unique($cssMatches[1] ?? []), 0, MAX_RESOURCE_FILES);
    $jsFiles = array_slice(array_unique($jsMatches[1] ?? []), 0, MAX_RESOURCE_FILES);

    $requests = [];
    foreach ($cssFiles as $i => $cssUrl) {
        $requests['css_' . $i] = resolveUrl($url, $cssUrl);
    }
    foreach ($jsFiles as $i => $jsUrl) {
        $requests['js_' . $i] = resolveUrl($url, $jsUrl);
    }

    $fetched = $requests ? parallelFetch($requests, TIMEOUT_CONNECT_AUX, TIMEOUT_RESPONSE_AUX, false, $requestDeadline) : [];

    $downloadLinks = ['css' => [], 'js' => []];
    $filePaths = ['index.html'];

    foreach ($cssFiles as $i => $cssUrl) {
        $key = 'css_' . $i;
        $result = $fetched[$key] ?? false;
        if ($result !== false) {
            $resolvedUrl = $requests[$key];

            $baseName = basename(parse_url($resolvedUrl, PHP_URL_PATH)) ?: ('style_' . md5($resolvedUrl) . '.css');
            $fileName = $i . '_' . $baseName;
            file_put_contents($folder . '/' . $fileName, $result['body']);
            $downloadLinks['css'][] = $fileName;
            $filePaths[] = $fileName;
        }
    }

    foreach ($jsFiles as $i => $jsUrl) {
        $key = 'js_' . $i;
        $result = $fetched[$key] ?? false;
        if ($result !== false) {
            $resolvedUrl = $requests[$key];
            $baseName = basename(parse_url($resolvedUrl, PHP_URL_PATH)) ?: ('script_' . md5($resolvedUrl) . '.js');
            $fileName = $i . '_' . $baseName;
            file_put_contents($folder . '/' . $fileName, $result['body']);
            $downloadLinks['js'][] = $fileName;
            $filePaths[] = $fileName;
        }
    }

    $zipPath = $folder . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE) === TRUE) {
        $files = scandir($folder);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $zip->addFile($folder . '/' . $file, $file);
        }
        $zip->close();
        deleteDirectory($folder);

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        $host = preg_replace('/:\d+$/', '', $host);
        $zip_link = $protocol . '://' . $host . '/download.php?file=' . basename($zipPath);

        return [
            'html' => $html,
            'css' => $downloadLinks['css'],
            'js' => $downloadLinks['js'],
            'zip_link' => $zip_link,
            'file_count' => count($filePaths),
            'file_paths' => $filePaths,
        ];
    }

    deleteDirectory($folder);
    return ['html' => $html, 'css' => [], 'js' => [], 'zip_link' => NOT_FOUND_MSG, 'file_count' => 0, 'file_paths' => []];
}

function resolveUrl($baseUrl, $relativeUrl) {
    if (preg_match('#^https?://#i', $relativeUrl)) return $relativeUrl;

    $baseParts = parse_url($baseUrl);
    $scheme = $baseParts['scheme'] ?? 'http';
    $host = $baseParts['host'] ?? '';
    $path = $baseParts['path'] ?? '/';

    if (substr($relativeUrl, 0, 2) === '//') {
        return "$scheme:$relativeUrl";
    }

    $dir = pathinfo($path, PATHINFO_DIRNAME);
    if (($relativeUrl[0] ?? '') === '/') {
        return "$scheme://$host$relativeUrl";
    } else {
        return "$scheme://$host$dir/$relativeUrl";
    }
}

function cleanNotFound($data) {
    if (is_array($data)) {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = cleanNotFound($value);
                if (empty($data[$key])) {
                    unset($data[$key]);
                }
            } elseif ($value === NOT_FOUND_MSG) {
                unset($data[$key]);
            }
        }
    }
    return $data;
}

function generateResponseImage($data) {
    if (!extension_loaded('gd')) {
        return false;
    }
    if (!file_exists(FONT_PATH)) {
        return false;
    }

    $width = 1400;
    $fontTitle = 24;
    $fontHeader = 13;
    $fontSize = 11;
    $lineHeight = 18;
    $spacing = 20;
    $headerHeight = 30;
    $paddingTop = 21;
    $paddingBottom = 21;
    $titleAreaHeight = 80;
    $footerAreaHeight = 60;
    $colWidth = ($width - 80) / 2;
    $leftX = 20;
    $rightX = $leftX + $colWidth + 40;
    $yStart = $titleAreaHeight + 20;

    $sections = [
        'Site & Host' => [
            'site' => $data['site'] ?? 'N/A',
            'host' => $data['host'] ?? 'N/A',
        ],
        'IP Information' => $data['ip_info'] ?? [],
        'Protocols' => $data['protocols'] ?? [],
        'Domain Information' => $data['domain_info'] ?? [],
        'Open Ports' => $data['open_ports'] ?? [],
        'Technology' => $data['technology'] ?? [],
        'SSL Certificate' => $data['ssl_certificate'] ?? [],
        'Cookies' => $data['cookies'] ?? [],
        'Description' => ['description' => $data['description'] ?? ''],
        'Creator' => ['creator' => $data['creator'] ?? ''],
        'cPanel Detection' => ['cpanel' => $data['cpanel'] ?? ''],
        'Download' => $data['download'] ?? [],
    ];

    $leftSections = array_slice($sections, 0, 5, true);
    $rightSections = array_slice($sections, 5, null, true);

    function calcSectionHeight($sectionData, $colWidth, $fontSize, $lineHeight, $paddingTop, $paddingBottom, $headerHeight) {
        $lines = formatSectionData($sectionData);
        $totalWrappedLines = 0;
        foreach ($lines as $line) {
            $wrapped = wrapTextToWidth($line, $fontSize, $colWidth - 20);
            $totalWrappedLines += count($wrapped);
        }
        return $headerHeight + $paddingTop + $totalWrappedLines * $lineHeight + $paddingBottom;
    }

    $leftHeight = 0;
    foreach ($leftSections as $sectionData) {
        $leftHeight += calcSectionHeight($sectionData, $colWidth, $fontSize, $lineHeight, $paddingTop, $paddingBottom, $headerHeight) + $spacing;
    }
    $leftHeight -= $spacing;

    $rightHeight = 0;
    foreach ($rightSections as $sectionData) {
        $rightHeight += calcSectionHeight($sectionData, $colWidth, $fontSize, $lineHeight, $paddingTop, $paddingBottom, $headerHeight) + $spacing;
    }
    $rightHeight -= $spacing;

    $contentHeight = max($leftHeight, $rightHeight);
    $totalHeight = $titleAreaHeight + $contentHeight + $footerAreaHeight + 40;
    $totalHeight = max($totalHeight, 800);

    $image = imagecreatetruecolor($width, $totalHeight);

    $bgTopR = 5; $bgTopG = 15; $bgTopB = 20;
    $bgBottomR = 15; $bgBottomG = 30; $bgBottomB = 40;

    for ($y = 0; $y < $totalHeight; $y++) {
        $t = $y / $totalHeight;
        $r = (int) round($bgTopR + ($bgBottomR - $bgTopR) * $t);
        $g = (int) round($bgTopG + ($bgBottomG - $bgTopG) * $t);
        $b = (int) round($bgTopB + ($bgBottomB - $bgTopB) * $t);
        $color = imagecolorallocate($image, $r, $g, $b);
        imageline($image, 0, $y, $width, $y, $color);
    }

    $sectionBg = imagecolorallocate($image, 25, 25, 35);
    $sectionHeader = imagecolorallocate($image, 0, 150, 100);
    $sectionText = imagecolorallocate($image, 220, 220, 220);
    $border = imagecolorallocate($image, 0, 255, 170);
    $titleColor = imagecolorallocate($image, 0, 255, 170);
    $titleBg = imagecolorallocate($image, 0, 0, 0);
    $headerText = imagecolorallocate($image, 255, 255, 255);

    $siteName = $data['host'] ?? 'Unknown';
    $title = 'Site Information Report - ' . $siteName;
    $bbox = imagettfbbox($fontTitle, 0, FONT_PATH, $title);
    $titleWidth = $bbox[2] - $bbox[0];
    $titleX = ($width - $titleWidth) / 2;
    imagettftext($image, $fontTitle, 0, $titleX, 50, $titleColor, FONT_PATH, $title);

    imageline($image, 0, $titleAreaHeight, $width, $titleAreaHeight, $border);

    function drawRoundedRectFilled($image, $x1, $y1, $x2, $y2, $radius, $color) {
        imagefilledellipse($image, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
        imagefilledrectangle($image, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
        imagefilledrectangle($image, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);
    }

    function drawRoundedRectBorder($image, $x1, $y1, $x2, $y2, $radius, $color, $thickness = 1) {
        imagesetthickness($image, $thickness);
        imagearc($image, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, 180, 270, $color);
        imagearc($image, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, 270, 360, $color);
        imagearc($image, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, 90, 180, $color);
        imagearc($image, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, 0, 90, $color);
        imageline($image, $x1 + $radius, $y1, $x2 - $radius, $y1, $color);
        imageline($image, $x1 + $radius, $y2, $x2 - $radius, $y2, $color);
        imageline($image, $x1, $y1 + $radius, $x1, $y2 - $radius, $color);
        imageline($image, $x2, $y1 + $radius, $x2, $y2 - $radius, $color);
        imagesetthickness($image, 1);
    }

    function drawSection($image, $x, $y, $width, $title, $lines, $bg, $header, $textColor, $border, $fontHeader, $fontSize, $lineHeight, $headerHeight, $paddingTop, $paddingBottom) {
        $totalLines = 0;
        foreach ($lines as $line) {
            $wrapped = wrapTextToWidth($line, $fontSize, $width - 20);
            $totalLines += count($wrapped);
        }
        $boxHeight = $headerHeight + $paddingTop + $totalLines * $lineHeight + $paddingBottom;
        $radius = 10;
        drawRoundedRectFilled($image, $x, $y, $x + $width, $y + $boxHeight, $radius, $bg);
        drawRoundedRectBorder($image, $x, $y, $x + $width, $y + $boxHeight, $radius, $border);

        drawRoundedRectFilled($image, $x, $y, $x + $width, $y + $headerHeight, $radius, $header);
        drawRoundedRectBorder($image, $x, $y, $x + $width, $y + $headerHeight, $radius, $border);

        $bbox = imagettfbbox($fontHeader, 0, FONT_PATH, $title);
        $titleWidth = $bbox[2] - $bbox[0];
        $titleX = $x + ($width - $titleWidth) / 2;
        imagettftext($image, $fontHeader, 0, $titleX, $y + $headerHeight - 8, $textColor, FONT_PATH, $title);

        $firstLine = $lines[0] ?? '';
$lastLine = $lines[count($lines) - 1] ?? '';

        $bboxFirst = imagettfbbox($fontSize, 0, FONT_PATH, $firstLine);
        $ascent = abs($bboxFirst[7]);

        $bboxLast = imagettfbbox($fontSize, 0, FONT_PATH, $lastLine);
        $descent = abs($bboxLast[1]);

        $actualBlockHeight = $ascent + $descent + ($totalLines - 1) * $lineHeight;

        $availableHeight = $boxHeight - $headerHeight;
        $topMargin = ($availableHeight - $actualBlockHeight) / 2;

        $textY = $y + $headerHeight + $topMargin + $ascent;
        foreach ($lines as $line) {
            $wrapped = wrapTextToWidth($line, $fontSize, $width - 20);
            foreach ($wrapped as $wrappedLine) {
                $bbox = imagettfbbox($fontSize, 0, FONT_PATH, $wrappedLine);
                $lineWidth = $bbox[2] - $bbox[0];
                $lineX = $x + ($width - $lineWidth) / 2;
                imagettftext($image, $fontSize, 0, $lineX, $textY, $textColor, FONT_PATH, $wrappedLine);
                $textY += $lineHeight;
            }
        }

        return $y + $boxHeight;
    }

    $y = $yStart;
    foreach ($leftSections as $sectionTitle => $sectionData) {
        $lines = formatSectionData($sectionData);
        $y = drawSection($image, $leftX, $y, $colWidth, $sectionTitle, $lines, $sectionBg, $sectionHeader, $sectionText, $border, $fontHeader, $fontSize, $lineHeight, $headerHeight, $paddingTop, $paddingBottom);
        $y += $spacing;
    }

    $y = $yStart;
    foreach ($rightSections as $sectionTitle => $sectionData) {
        $lines = formatSectionData($sectionData);
        $y = drawSection($image, $rightX, $y, $colWidth, $sectionTitle, $lines, $sectionBg, $sectionHeader, $sectionText, $border, $fontHeader, $fontSize, $lineHeight, $headerHeight, $paddingTop, $paddingBottom);
        $y += $spacing;
    }

    for ($sy = 0; $sy < $totalHeight; $sy += 4) {
        imageline($image, 0, $sy, $width, $sy, imagecolorallocatealpha($image, 0, 255, 170, 110));
    }

    $footer = 'Developer: J Code';
    $fontFooter = 10;
    $bbox = imagettfbbox($fontFooter, 0, FONT_PATH, $footer);
    $footerWidth = $bbox[2] - $bbox[0];
    imagettftext($image, $fontFooter, 0, ($width - $footerWidth) / 2, $totalHeight - 20, $titleColor, FONT_PATH, $footer);

    return $image;
}

function formatSectionData($data, $prefix = '') {
    $lines = [];
    if (is_array($data)) {
        if (empty($data)) {
            return ['No data available'];
        }
        $isAssoc = array_keys($data) !== range(0, count($data) - 1);
        if ($isAssoc) {
            foreach ($data as $key => $value) {
                $displayKey = $prefix . $key;
                if (is_array($value)) {
                    $subLines = formatSectionData($value, $displayKey . ': ');
                    $lines = array_merge($lines, $subLines);
                } else {
                    $lines[] = $displayKey . ': ' . $value;
                }
            }
        } else {
            foreach ($data as $item) {
                if (is_array($item)) {
                    $parts = [];
                    foreach ($item as $k => $v) {
                        $parts[] = "$k: $v";
                    }
                    $lines[] = $prefix . implode(' | ', $parts);
                } else {
                    $lines[] = $prefix . $item;
                }
            }
        }
    } else {
        $lines[] = $prefix . $data;
    }
    return $lines;
}

function wrapTextToWidth($text, $fontSize, $maxWidth) {
    $words = explode(' ', $text);
    $lines = [];
    $currentLine = '';
    foreach ($words as $word) {
        $testLine = $currentLine === '' ? $word : $currentLine . ' ' . $word;
        $bbox = imagettfbbox($fontSize, 0, FONT_PATH, $testLine);
        $lineWidth = $bbox[2] - $bbox[0];
        if ($lineWidth <= $maxWidth) {
            $currentLine = $testLine;
        } else {
            if ($currentLine !== '') {
                $lines[] = $currentLine;
            }
            $currentLine = $word;
        }
    }
    if ($currentLine !== '') {
        $lines[] = $currentLine;
    }
    return $lines;
}