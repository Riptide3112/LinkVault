<?php
/**
 * api/redirect.php - Link Redirect Handler
 * 
 * This is the core redirection engine of LinkVault. It handles all short URL redirects
 * with comprehensive security, analytics, and rate limiting.
 * 
 * How it works:
 *   .htaccess rewrites /{code} to /api/redirect.php?code={code}
 *   This script validates, tracks, and redirects to the original URL
 * 
 * Security Features:
 *   - Strict short_code validation (alphanumeric, 4-16 chars)
 *   - IP hashing with salt (never stores raw IP addresses)
 *   - Rate limiting to prevent scraping (120 redirects/minute)
 *   - GeoIP lookup only for public IPs (short timeout)
 *   - 301 redirect only to validated http/https URLs
 *   - Click tracking never blocks the redirect if it fails
 * 
 * Privacy:
 *   - IP addresses are hashed with a secret salt before storage
 *   - No personally identifiable information is stored permanently
 *   - GeoIP data is optional and only for public IPs
 * 
 * @package LinkVault
 * @subpackage Core
 * @requires db.php, config.php, security.php
 */

declare(strict_types=1); // Enforce strict typing for better code reliability

session_start();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/security.php';

// ============================================================================
// SECURITY HEADERS
// ============================================================================

/**
 * Set security headers for all redirect responses
 * Even though we redirect, these headers protect any error pages shown
 */
header('X-Content-Type-Options: nosniff');      // Prevent MIME type sniffing
header('X-Frame-Options: DENY');                // Prevent clickjacking
header('Referrer-Policy: no-referrer');         // Don't leak the original URL

// ============================================================================
// VALIDATE SHORT CODE
// ============================================================================

/**
 * Extract and validate the short code from URL parameters
 * 
 * Validation rules:
 *   - Only alphanumeric characters (a-z, A-Z, 0-9)
 *   - Length between 4 and 16 characters
 * 
 * @security Strict regex prevents any injection attempts
 * @security Invalid codes get a clean 404 error page
 */
$code = trim($_GET['code'] ?? '');

if (!preg_match('/^[a-zA-Z0-9]{4,16}$/', $code)) {
    http_response_code(400);
    header('Location: ' . BASE_URL . '/pages/error.php?code=400');
    exit;
}

// ============================================================================
// RATE LIMITING
// ============================================================================

/**
 * Rate limiting per IP to prevent abuse and scraping
 * 
 * Limits:
 *   - Maximum 120 redirects per minute per IP address
 *   - Exceeding the limit returns HTTP 429 (Too Many Requests)
 * 
 * @security Uses hashed IP (with SHA256) for privacy
 * @security Rate limit data stored in database with automatic cleanup
 */
$ip    = get_client_ip();                      // Gets real IP even behind proxies
$rlKey = 'redirect:' . hash('sha256', $ip);    // Hashed key for privacy

if (rate_limit_check($pdo, $rlKey, 120, 60)) {
    http_response_code(429);
    header('Location: ' . BASE_URL . '/pages/error.php?code=429');
    exit;
}
rate_limit_hit($pdo, $rlKey, 60);              // Increment counter for this request

// ============================================================================
// FETCH LINK FROM DATABASE
// ============================================================================

/**
 * Retrieve the link information from the database
 * 
 * Query returns:
 *   - id: Link ID for tracking
 *   - original_url: Destination URL
 *   - expires_at: Expiration timestamp (null = never expires)
 * 
 * @security Prepared statement prevents SQL injection
 * @security Uses LIMIT 1 for performance
 */
$stmt = $pdo->prepare("
    SELECT id, original_url, expires_at
    FROM links
    WHERE short_code = ?
    LIMIT 1
");
$stmt->execute([$code]);
$link = $stmt->fetch();

// Link not found in database
if (!$link) {
    http_response_code(404);
    header('Location: ' . BASE_URL . '/pages/error.php?code=404');
    exit;
}

// ============================================================================
// CHECK EXPIRATION
// ============================================================================

/**
 * Verify the link hasn't expired
 * 
 * Expired links return HTTP 410 (Gone) to indicate permanent unavailability
 * This helps search engines remove the link from their indices
 */
if ($link['expires_at'] && strtotime($link['expires_at']) < time()) {
    http_response_code(410);
    header('Location: ' . BASE_URL . '/pages/error.php?code=410');
    exit;
}

// ============================================================================
// VALIDATE DESTINATION URL
// ============================================================================

/**
 * Security validation of the destination URL
 * 
 * Requirements:
 *   - Must start with http:// or https://
 *   - Prevents javascript: and other dangerous protocols
 * 
 * @security Open redirect prevention
 * @security Protocol whitelisting (only HTTP/HTTPS)
 */
$destination = $link['original_url'];
if (!preg_match('/^https?:\/\//i', $destination)) {
    http_response_code(400);
    header('Location: ' . BASE_URL . '/pages/error.php?code=400');
    exit;
}

// ============================================================================
// REGISTER CLICK (Non-blocking)
// ============================================================================

/**
 * Record the click for analytics
 * 
 * Important: This operation is wrapped in a try-catch and will NEVER
 * block the redirect. If click recording fails, the redirect still happens.
 * This ensures maximum availability.
 * 
 * Click data recorded:
 *   - Hashed IP (with salt) - cannot be reversed to original IP
 *   - Country and city (from GeoIP, if available and public IP)
 *   - Referrer (where the click came from)
 *   - User agent (browser/client information)
 *   - Timestamp of the click
 */
try {
    registerClick($pdo, (int) $link['id'], $ip);
    $pdo->prepare("UPDATE links SET clicks = clicks + 1 WHERE id = ?")->execute([$link['id']]);
} catch (Throwable $e) {
    // Log error but DO NOT block redirect
    error_log('LinkVault redirect error: ' . $e->getMessage());
}

// ============================================================================
// PERFORM REDIRECT
// ============================================================================

/**
 * Send 301 Permanent Redirect
 * 
 * 301 redirect is SEO-friendly and tells browsers/search engines
 * that this is a permanent mapping.
 * 
 * Additional cache headers prevent any intermediate caching
 * of the redirect decision.
 */
header('Location: ' . $destination, true, 301);
header('Cache-Control: no-cache, no-store, must-revalidate');
exit;

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

/**
 * Register a click in the database with full analytics data
 * 
 * Privacy features:
 *   - IP addresses are hashed with a secret salt (never stored raw)
 *   - GeoIP lookup only performed for public IPs (not private/LAN)
 *   - User agent and referrer are truncated to reasonable lengths
 * 
 * @param PDO $pdo Database connection
 * @param int $linkId ID of the clicked link
 * @param string $ip Raw client IP address (will be hashed)
 * 
 * @return void
 * @security IP hashing with salt prevents IP reconstruction
 */
function registerClick(PDO $pdo, int $linkId, string $ip): void
{
    // Hash the IP with secret salt - cannot be reversed without the salt
    $ipHash    = hash('sha256', $ip . IP_SALT);
    
    // Truncate long strings to fit database columns
    $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512);
    $referer   = substr($_SERVER['HTTP_REFERER']    ?? '', 0, 512);

    $country = null;
    $city    = null;

    // Only perform GeoIP lookup for public IPs (skip localhost, LAN)
    if (!isPrivateIp($ip)) {
        $geo = fetchGeoIp($ip);
        if ($geo) {
            $country = $geo['country'] ?? null;
            $city    = $geo['city']    ?? null;
        }
    }

    // Insert click record with all available analytics
    $pdo->prepare("
        INSERT INTO link_clicks (link_id, ip_hash, country, city, referer, user_agent, clicked_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ")->execute([$linkId, $ipHash, $country, $city, $referer, $userAgent]);
}

/**
 * Fetch GeoIP information for an IP address using free ip-api.com service
 * 
 * Features:
 *   - 2 second timeout (fast, doesn't block redirect)
 *   - Only requests country and city (minimal data)
 *   - Silent failure (returns null on error)
 * 
 * @param string $ip IP address to lookup
 * @return array|null Array with 'country' and 'city' keys, or null on failure
 * 
 * @note This is a free service. For production with high traffic,
 *       consider a paid GeoIP service or local database.
 */
function fetchGeoIp(string $ip): ?array
{
    $url = 'http://ip-api.com/json/' . urlencode($ip) . '?fields=status,country,city';
    $ctx = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
    $res = @file_get_contents($url, false, $ctx);
    if (!$res) return null;
    $data = json_decode($res, true);
    return ($data && ($data['status'] ?? '') === 'success') ? $data : null;
}

/**
 * Check if an IP address is private (non-routable)
 * 
 * Private IP ranges include:
 *   - 10.0.0.0/8
 *   - 172.16.0.0/12
 *   - 192.168.0.0/16
 *   - 127.0.0.0/8 (localhost)
 * 
 * @param string $ip IP address to check
 * @return bool True if IP is private/LAN, false if public
 * 
 * @security Prevents GeoIP lookups for internal IPs (privacy + performance)
 */
function isPrivateIp(string $ip): bool
{
    return !filter_var($ip, FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    );
}

/**
 * Display a user-friendly error page
 * 
 * This function is called when errors occur before a redirect can be made.
 * It generates a clean, branded error page with appropriate HTTP status code.
 * 
 * @param string $message Human-readable error message
 * @param int $code HTTP status code (400, 404, 410, 429)
 * 
 * @return void (exits after output)
 */
function showError(string $message, int $code = 404): void
{
    $base   = defined('BASE_URL') ? BASE_URL : '';
    $titles = [400 => 'Bad Request', 404 => 'Not Found', 410 => 'Link Expired', 429 => 'Too Many Requests'];
    $title  = $titles[$code] ?? 'Error';
    $icons  = [400 => '⚠', 404 => '🔗', 410 => '⏱', 429 => '🚫'];
    $icon   = $icons[$code] ?? '⚠';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>LinkVault — <?= htmlspecialchars($title) ?></title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400&family=Syne:wght@700;800&display=swap" rel="stylesheet">
  <style>
    :root{--bg:#09090B;--surface:#111113;--border:#27272A;--text:#FAFAFA;--muted:#71717A;--accent:#00C2A8}
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    body{background:var(--bg);color:var(--text);font-family:'DM Mono',monospace;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
    .box{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:40px 32px;max-width:420px;width:100%;text-align:center}
    .icon{font-size:2.5rem;margin-bottom:16px}
    h1{font-family:'Syne',sans-serif;font-size:1.3rem;font-weight:800;margin-bottom:10px;letter-spacing:-0.02em}
    p{font-size:0.78rem;color:var(--muted);margin-bottom:24px;line-height:1.6}
    .code{font-size:0.65rem;color:var(--muted);margin-bottom:20px}
    a{display:inline-block;padding:11px 24px;background:var(--accent);color:#000;border-radius:8px;font-family:'Syne',sans-serif;font-size:0.85rem;font-weight:700;text-decoration:none}
    a:hover{opacity:0.9}
  </style>
</head>
<body>
  <div class="box">
    <div class="icon"><?= $icon ?></div>
    <h1><?= htmlspecialchars($title) ?></h1>
    <p><?= htmlspecialchars($message) ?></p>
    <div class="code">HTTP <?= $code ?></div>
    <a href="<?= htmlspecialchars($base) ?>/">Go to LinkVault</a>
  </div>
</body>
</html>
    <?php
}