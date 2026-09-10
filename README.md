# J Code – PHP API Examples Collection

[![PHP Version](https://img.shields.io/badge/php-7.4%2B-777bb4)](https://www.php.net/)
[![Status](https://img.shields.io/badge/status-example%20%2F%20learning-yellow)](#security--disclaimer)
[![Security](https://img.shields.io/badge/security-partial%2C%20not%20production--ready-orange)](#security--disclaimer)
[![Developer](https://img.shields.io/badge/developer-J%20Code-blueviolet)](#)

<br>

<img src="docs/images/logo-APIs.png" alt="J Code API Examples">

<br>

**J Code's PHP API Examples** is a collection of four small, framework-free PHP APIs built for learning purposes: an image CAPTCHA generator, a live cryptocurrency price API, a geo distance/time calculator, and a site-information (OSINT-style) lookup tool. Each one is self-contained, uses plain files (JSON/PNG) as its cache layer, and needs no database.

Every endpoint is a plain HTTP GET/POST call — no SDK, no build step, just `curl` or your browser.

> **Status: Archived.** This collection is published as-is and is **no longer actively maintained**. The repository is read-only — new issues and pull requests cannot be opened. It remains online as a reference for learning.

---

## Quick Start

```bash
# Generate a 6-character numeric CAPTCHA image
curl "https://your-domain.com/captcha/captcha_api.php?type=numbers&len=6"

# Get Bitcoin's current price (in Toman by default)
curl "https://your-domain.com/japi.php?currency=bitcoin"

# Distance and travel time between two coordinates
curl "https://your-domain.com/Distance/point_calculations.php?origin=35.6892,51.3890&destination=32.6539,51.6660"

# Technical information about a website
curl "https://your-domain.com/site_information/index.php?site=example.com"
```

Every call is a plain synchronous HTTP request — no authentication, no API keys required by default.

---

## Main Capabilities

· **Captcha API** – Generates a random-text image CAPTCHA with GD (`captcha_api.php`), with configurable character set, length, and a "moving/wavy" hard mode. Returns a temporary image link plus the plaintext value, and self-cleans expired images and rate-limit files.

· **Cryptocurrency API** – Live crypto prices (`japi.php`) with file/APCu caching, conversion to any configured currency (including a live USD→Toman rate), 24h/7d/30d change data, and an auto-generated candlestick price chart image. Ships as two deployments — `www.coingecko.com/` (sourced from the CoinGecko API) and `arzdigital.com/` (sourced directly from arzdigital.com's own coin pages) — plus a CLI script (`update_prices.php`) meant to run on a cron schedule.

· **Distance API** – Haversine distance and estimated travel time (`point_calculations.php`) between two `lat,lng` points, for walking/bicycle/car/airplane/jet modes, with automatic or manual distance/time units, reverse-geocoded location names, and an optional satellite-style route map image rendered from OpenTopoMap tiles.

· **Site Information API** – A website inspection endpoint (`index.php`) that returns IP/ISP info, active protocols, WHOIS/RDAP domain data, common open ports, server technology (from headers), SSL certificate details, cookies, meta description, cPanel detection, and can optionally zip up the site's HTML/CSS/JS or render a full visual report image.

> **On the count:** the collection ships as **five service folders** across **four distinct capabilities** — the Cryptocurrency API comes in two deployments (`www.coingecko.com` and `arzdigital.com`) that perform essentially the same job against different data sources. They are documented here as one API with two variants, which is why the rest of this README refers to "four APIs".

---

## A Note on Ports in Image URLs

Some of the image-producing APIs in this collection include the server **port** in the URLs they return (e.g. `https://your-domain.com:8080/charts/chart_bitcoin.png`), while others omit it and use only the hostname (e.g. `https://your-domain.com/charts/chart_bitcoin.png`).

**This is intentional, not an inconsistency.** Both styles are valid — and which one is "correct" depends entirely on whether the server is running on a non-standard port. By shipping one deployment of each style, the collection lets you see both URL-building approaches side by side and learn when each one applies, rather than only ever seeing a single convention.

---

## A Note on Availability

These APIs were built in Iran. A couple of the external services they call under the hood may not be reachable (or may behave differently) for users outside my country — but most of the services used are publicly accessible to everyone, regardless of location.

---

## Requirements

- **PHP 7.4+** on a web server (Apache/Nginx) with HTTPS
- **zip** extension enabled (required by the Site Information API to bundle downloaded HTML/CSS/JS into a `.zip`; enabled by default on many environments such as KSWEB, but not always on minimal/managed hosting)
- **GD** extension enabled (used for CAPTCHA images, price charts, route maps, and Site Information's report images)
- **cURL** extension enabled (used to call CoinGecko, Nominatim, OpenTopoMap, and the target site in Site Information)
- Write permission for PHP on each project's own folder (each API creates its own cache/temp-image subfolders automatically)
- Optional but recommended: **APCu** extension for the Cryptocurrency API (it falls back to file-based caching automatically if APCu isn't available)

---

## Installation

Each folder is a standalone PHP project — just copy it to your web server and point a domain/subdomain (or subfolder) at it. There's nothing to `npm install` or `composer install`; every dependency is either PHP's built-in extensions or a plain `.ttf` font file already included in each folder.

```bash
# Example: deploy the Cryptocurrency API
cp -r "Cryptocurrency_API/www.coingecko.com" /var/www/your-domain.com
```

---

## More Examples

### 1. Captcha API

`captcha/captcha_api.php`

```bash
curl "https://your-domain.com/captcha/captcha_api.php?type=numbers&len=6&moving=true"
```

```json
{
  "captcha link": "https://your-domain.com/captcha_images/9f1a2b3c....png",
  "Captcha value": "482913",
  "Developer": "J Code"
}
```

| Parameter | Type | Required | Default | Description |
| --- | --- | --- | --- | --- |
| `type` | string | No | `printable-characters` | Character set to use: `printable-characters`, `numbers`, `letters`, `lowercase-letters`, `capital-letters`, `no-numbers-and-letters`, `no-numbers-and-lowercase-letters`, `no-numbers-and-capital-letters`, `no-numbers`, `no-letters`, `no-lowercase-letters`, `no-capital-letters`. Any other value returns a `400` error. |
| `len` | integer | No | `6` | Length of the CAPTCHA text. Must be between `1` and `34` (inclusive); out-of-range or non-numeric values return a `400` error. |
| `moving` | boolean (`true`/`false`) | No | `false` | When `true`, characters are also shifted vertically and rotated more aggressively, producing a harder-to-read CAPTCHA. |

---

### 2. Cryptocurrency API

`Cryptocurrency_API/{www.coingecko.com or arzdigital.com}/japi.php`

The `www.coingecko.com` and `arzdigital.com` folders are two separate deployments for two domains. They are not byte-for-byte identical under the hood — `www.coingecko.com` sources prices from the CoinGecko API, while `arzdigital.com` reads prices directly off arzdigital.com's own coin pages — but both fully support price conversion via `tocurrency` (Toman, USD, or any other configured currency).

```bash
# Bitcoin price in Toman (default)
curl "https://your-domain.com/japi.php?currency=bitcoin"

# Ethereum price in USD, with 7-day change data and chart
curl "https://your-domain.com/japi.php?currency=ethereum&tocurrency=usd&changes=7"

# All configured currencies at once
curl "https://your-domain.com/japi.php?currency=all"

# List every built-in currency key
curl "https://your-domain.com/japi.php?currency=list"
```

```json
{
  "key": "bitcoin",
  "currency": "Bitcoin",
  "type": "crypto",
  "unit": "toman",
  "price": 4123456789.12,
  "price_2d_ago": 4067000000,
  "change_24h": 1.34,
  "change_2d": 1.98,
  "period_days": 1,
  "change_period": 1.34,
  "updated_at": "2026-09-10T12:00:00+03:30",
  "valid_for_sec": 82,
  "stale": false,
  "chart": "https://your-domain.com/charts/chart_bitcoin.toman.png?v=1234567890",
  "icon": "https://your-domain.com/icons/icon_bitcoin.png?v=1234567890",
  "requested_currency": "T",
  "conversion_note": null,
  "developer": "JCode"
}
```

> The chart filename encodes the target currency (e.g. `chart_bitcoin.toman.png` vs `chart_bitcoin.usd.png`), so each `tocurrency` gets its own cached chart instead of sharing one. `www.coingecko.com` returns `change_7d` / `change_30d` instead of `price_2d_ago` / `change_2d`, and does not include `requested_currency` / `conversion_note` (those two only appear on `arzdigital.com`, to flag when a requested conversion rate couldn't be fetched and the price fell back to Toman).

> **Note:** the two deployments also build their chart data differently. `www.coingecko.com` pulls historical prices straight from CoinGecko, so its chart is complete from the very first request. `arzdigital.com` instead builds its own history over time by recording prices each time the cron runs — on a fresh install its chart will appear as a flat line until enough days have accumulated (up to the 7-day cap).

> **Note:** the two deployments also differ in `max_period_days` — 30 days for `www.coingecko.com` vs 7 days for `arzdigital.com`, matching arzdigital's own `history_days` setting.

| Parameter | Type | Required | Default | Description |
| --- | --- | --- | --- | --- |
| `currency` | string | **Yes** | — (returns a `400` error if missing) | A currency key (e.g. `bitcoin`), `all` (every configured currency), or `list` (returns the key list). Currencies not in the built-in list are looked up dynamically on CoinGecko by name/symbol. |
| `tocurrency` | string | No | `T` (Toman) | Target unit to convert the price into: `T` (Toman), `USD`, or any other configured currency key (e.g. `ethereum`, to price it in ETH). |
| `changes` | integer | No | `1` (24h) | Number of days for the percentage-change figure and the chart period. Values above `max_period_days` are clamped to that maximum — `30` on `www.coingecko.com`, but only `7` on `arzdigital.com` (it doesn't retain more than 7 days of history). |

> **Note:** `update_prices.php` is a CLI-only script meant to run on a cron schedule (e.g. every 1–2 minutes) to keep cached prices fresh; without it, the first request for a stale currency has to wait on a live fetch.

---

### 3. Distance API

`Distance/point_calculations.php`

```bash
curl "https://your-domain.com/Distance/point_calculations.php?origin=35.6892,51.3890&destination=32.6539,51.6660&unit=km&picture=true"
```

```json
{
  "origin": { "coordinates": { "lat": 35.6892, "lng": 51.389 }, "location": { "country": "Iran", "city": "Tehran" } },
  "destination": { "coordinates": { "lat": 32.6539, "lng": 51.666 }, "location": { "country": "Iran", "city": "Isfahan" } },
  "distance": {
    "unit": "km",
    "value": 340.12,
    "modes": {
      "walking":  { "distance": { "value": 442.15, "unit": "km" }, "time": { "value": 3.68, "unit": "day" } },
      "car":      { "distance": { "value": 442.15, "unit": "km" }, "time": { "value": 7.36, "unit": "hour" } },
      "airplane": { "distance": { "value": 340.12, "unit": "km" }, "time": { "value": 22.67, "unit": "min" } }
    }
  },
  "picture": { "url": "https://your-domain.com/Distance/serve_image.php?file=map_....png", "expires_in_seconds": 80 },
  "developer": "J Code"
}
```

| Parameter | Type | Required | Default | Description |
| --- | --- | --- | --- | --- |
| `origin` | string (`lat,lng`) | **Yes** | — (returns a `400` error if missing or invalid) | Origin coordinates, e.g. `35.6892,51.3890`. Latitude must be between `-90` and `90`, longitude between `-180` and `180`. |
| `destination` | string (`lat,lng`) | **Yes** | — (returns a `400` error if missing or invalid) | Destination coordinates, same format and validation as `origin`. |
| `unit` | string | No | `automatic` | Distance unit: `km`, `m`, `cm`, `mm`, `megameter`, `mile`, `yard`, `foot`, `inch`, `nauticalmile` (and a few aliases). `automatic` picks the most readable unit for the value. |
| `time` | string | No | `automatic` | Time unit: `ms`, `s`, `min`, `hour`, `day`, `week`, `month`, `year` (and aliases). `automatic` picks the most readable unit. |
| `picture` | boolean (`true`/`false`) | No | `false` | When `true`, adds a `picture.url` field with a temporary map image showing the route between origin and destination. |

---

### 4. Site Information API

`site_information/index.php`

> This API actively probes the target host's infrastructure (including a light port scan). Only use it against domains you own or have explicit permission to inspect.

```bash
# Full JSON report
curl "https://your-domain.com/site_information/index.php?site=example.com"

# Strip empty / "not found" fields from the response
curl "https://your-domain.com/site_information/index.php?site=example.com&better=true"

# Also generate a visual report image
curl "https://your-domain.com/site_information/index.php?site=example.com&picture=true"
```

```json
{
  "site": "http://example.com",
  "host": "example.com",
  "ip_info": { "ip": "93.184.216.34", "isp": "...", "country": "...", "city": "..." },
  "protocols": ["http", "https"],
  "domain_info": { "domain_name": "example.com", "registrar": "...", "creation_date": "...", "expiration_date": "..." },
  "open_ports": [{ "port": 80, "service": "HTTP", "status": "open" }],
  "ssl_certificate": { "issuer": "...", "valid_from": "...", "valid_to": "..." },
  "download": { "zip_link": "https://your-domain.com/site_information/download.php?file=site_....zip" },
  "developer": "J Code"
}
```

| Parameter | Type | Required | Default | Description |
| --- | --- | --- | --- | --- |
| `site` | string (domain or URL) | **Yes** | — (returns a `400` error if missing) | Target domain or URL, e.g. `example.com` or `https://example.com`. Rejected with a `400` error if it resolves to a private/internal IP address. |
| `better` | boolean (`true`/`false`) | No | `false` | When `true`, removes fields whose value is "not found" from the response for a cleaner payload. |
| `picture` | boolean (`true`/`false`) | No | `false` | When `true`, adds a `picture_link` field pointing to a rendered PNG summary of the whole report. |

---

## ⚙ Values You Need to Fill In

Before deploying any of these, review and fill in the following:

| File | Value | Notes |
| --- | --- | --- |
| `captcha/captcha_api.php` | `FIXED_HOST` | Currently `null` — the host is read from the request's `Host` header. To avoid Host-header abuse, set this explicitly to your real domain (e.g. `'captcha.example.com'`). |
| `Cryptocurrency_API/.../config.php` | `'fixed_host'` | Same idea as above, currently `null`. Set it to your real domain — it's used when building chart/icon URLs. |
| `Cryptocurrency_API/.../config.php` | `'toman_rate_source'` / `'custom_toman_rate'` | The USD→Toman rate source, defaults to `'kifpool'`. Switch to `'nobitex'` or `'custom'` (with a fixed `custom_toman_rate`) if you prefer. |
| `Cryptocurrency_API/.../config.php` | `'demo_api_key'` | CoinGecko demo/free API key (only used by `www.coingecko.com`) — optional, but recommended to avoid getting rate-limited by CoinGecko itself. |
| `Cryptocurrency_API/.../config.php` | `'max_period_days'` / `'history_days'` | Differs by deployment: 30 on coingecko, 7 on arzdigital. Increase arzdigital's if you want longer history — requires the fetch/store logic to actually retain more days. |
| `Distance/point_calculations.php` | `CURLOPT_USERAGENT` in `reverseGeocode()` | Currently set to `contact: admin@example.com`. Per [Nominatim's usage policy](https://operations.osmfoundation.org/policies/nominatim/), replace it with a real contact email/domain, or your IP may get blocked. |
| `Distance/point_calculations.php` | `CURLOPT_USERAGENT` in `fetchTilesParallel()` | Also currently a placeholder (`your-email@example.com`) — replace it with real contact info, per OpenTopoMap's tile usage policy. |
| All projects | Cache/temp folders (`captcha_images`, `charts`/`data`/`locks`/`icons`, `tmp_images`, `temp_files`/`private`) | Created automatically, but PHP needs **write access** to each project's folder, or you'll get `500` errors. |
| Cryptocurrency API | Cron job for `update_prices.php` | Without it, prices only refresh on-demand (slower for the first request after expiry). See the note in that section above. |
| All projects | Rate-limit values (`MAX_REQUESTS_PER_MINUTE`, `api_rate_limit`, etc.) | Defaults are tuned for testing/learning — adjust them to match your real expected traffic. |
| Site Information API | The `open_ports` (port scan) feature | Only point this at domains you own or have permission to inspect. |

---

## Security & Disclaimer

These four APIs were built **strictly for learning purposes** — the goal was to demonstrate how to implement this kind of service in plain PHP, not to ship a production-ready public product.

Some security work has already gone into them — SSRF protection (resolving and validating that target IPs are public before fetching), rate limiting, input validation, basic security headers, and automatic cleanup of temporary files — and that work has been reviewed to some extent. **That said, these projects have not been fully hardened, and no extensive security testing has been performed on them.**

If you plan to actually use these in production and/or expose them to other people (end users, clients, etc.), you will **definitely** need, at minimum:

- Stricter rate limiting enforced at the web server / firewall level, not just in PHP
- Deeper input validation and sanitization (especially in Site Information, where user input drives outbound requests)
- Proper monitoring, logging, and alerting
- A security review / penetration test before any public release
- Tighter server resource limits (CPU/RAM/execution time) to prevent abuse or denial of service

In short: these APIs are **genuinely, genuinely, genuinely** great for learning — but they are **not** production-ready as-is.

---

## License

No formal license is attached to this collection — treat it as educational reference code rather than a licensed, redistributable package.

---

<br>

Designed and built with love by **J Code❤️**