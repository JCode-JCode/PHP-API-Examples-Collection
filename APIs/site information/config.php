<?php
// Copyright 2026 J Code
// SPDX-License-Identifier: Apache-2.0
define('MAX_REQUESTS_PER_MINUTE', 10);

define('LOG_DIR', __DIR__ . '/private/');
define('RATE_LIMIT_DIR', LOG_DIR . 'rate_limit/');

define('USER_AGENT', 'SiteInfoAPI/1.0');

define('TIMEOUT_CONNECT_MAIN', 6);
define('TIMEOUT_RESPONSE_MAIN', 20);
define('TIMEOUT_CONNECT_AUX', 4);
define('TIMEOUT_RESPONSE_AUX', 6);

define('NOT_FOUND_MSG', 'No information found for this item');
define('TEMP_DIR', __DIR__ . '/temp_files/');
define('EXPIRATION_TIME', 80);

define('MAX_RESOURCE_FILES', 15);

define('MAX_RESPONSE_BYTES', 5 * 1024 * 1024);

define('MAX_TOTAL_RUNTIME', 45);

define('RUNTIME_SAFETY_MARGIN', 3);

define('MIN_NETWORK_BUDGET', 1.0);

define('FONT_PATH', __DIR__ . '/fonts/DejaVuSans.ttf');