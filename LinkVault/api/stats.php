<?php
/**
 * api/stats.php — Link Statistics JSON Endpoint
 * 
 * This API endpoint provides detailed analytics for a specific short link:
 * - Click count over time (for chart visualization)
 * - Geographic distribution (top countries and cities)
 * - Traffic sources (referrers like Google, Facebook, Twitter, etc.)
 * - Unique visitors count
 * 
 * Security Features:
 * - User authentication required (only link owners can view stats)
 * - Strict short_code validation (alphanumeric, 4-16 chars)
 * - Prepared statements prevent SQL injection
 * - Input validation and sanitization
 * - Returns 401/403 for unauthorized access
 * 
 * @package LinkVault
 * @subpackage API
 * @requires db.php, config.php, security.php
 */

declare(strict_types=1); // Enforce strict typing for better code reliability

session_start();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/security.php';

// Set JSON response header
header('Content-Type: application/json');

// ============================================================================
// AUTHENTICATION - Verify User is Logged In
// ============================================================================

/**
 * All statistics endpoints require authentication
 * Only the link owner can view analytics for their links
 * 
 * @security Returns HTTP 401 if user is not logged in
 */
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized. Please log in to view statistics.']);
    exit;
}

// ============================================================================
// INPUT VALIDATION
// ============================================================================

/**
 * Validate and sanitize input parameters
 * 
 * Query Parameters:
 *   @param string $code - The short code of the link (required)
 *   @param string $period - Time period for statistics (default: '7d')
 *                          Supported: '1d', '7d', '30d', '180d'
 * 
 * @security Regex validation prevents any injection attempts
 * @security Whitelist for period parameter prevents arbitrary values
 */
$code   = trim($_GET['code']   ?? '');
$period = trim($_GET['period'] ?? '7d');

// Validate short code format (alphanumeric, 4-16 characters)
if (!preg_match('/^[a-zA-Z0-9]{4,16}$/', $code)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid short code format.']);
    exit;
}

// Validate and sanitize period parameter using whitelist
$allowedPeriods = ['1d' => 1, '7d' => 7, '30d' => 30, '180d' => 180];
if (!array_key_exists($period, $allowedPeriods)) {
    $period = '7d'; // Default to 7 days if invalid
}

$days = $allowedPeriods[$period];

// ============================================================================
// AUTHORIZATION - Verify Link Ownership
// ============================================================================

/**
 * Ensure the link exists and belongs to the authenticated user
 * 
 * @security Checks both existence AND ownership
 * @security Returns 404 if link doesn't exist (prevents user enumeration)
 * @security Returns 404 even if link exists but belongs to another user
 * 
 * @note Using generic "Link not found" message prevents user enumeration
 */
$userId = (int) $_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT id, short_code, original_url, clicks, created_at, expires_at
    FROM links
    WHERE short_code = ? AND user_id = ?
    LIMIT 1
");
$stmt->execute([$code, $userId]);
$link = $stmt->fetch();

if (!$link) {
    http_response_code(404);
    echo json_encode(['error' => 'Link not found or access denied.']);
    exit;
}

$linkId = (int) $link['id'];

// ============================================================================
// STATISTICS COLLECTION
// ============================================================================

// --------------------------------------------------------------------------
// 1. CHART DATA - Clicks Over Time
// --------------------------------------------------------------------------
/**
 * Generate click count for each day in the selected period
 * 
 * Creates a complete time series with all dates (fill missing days with 0)
 * This ensures the chart renders correctly even with no data on some days
 * 
 * @returns array $chartData - Associative array with dates as keys, click counts as values
 */
$stmt = $pdo->prepare("
    SELECT DATE(clicked_at) as date, COUNT(*) as count
    FROM link_clicks
    WHERE link_id = ?
      AND clicked_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    GROUP BY DATE(clicked_at)
    ORDER BY date ASC
");
$stmt->execute([$linkId, $days]);
$clicksByDay = $stmt->fetchAll();

// Initialize array with all dates in period (values = 0)
$chartData = [];
for ($i = $days - 1; $i >= 0; $i--) {
    $chartData[date('Y-m-d', strtotime("-{$i} days"))] = 0;
}
// Fill in actual click counts
foreach ($clicksByDay as $row) {
    $chartData[$row['date']] = (int) $row['count'];
}

// --------------------------------------------------------------------------
// 2. TOP COUNTRIES
// --------------------------------------------------------------------------
/**
 * Get top 8 countries by click count
 * Filtered to the selected time period
 * Excludes records with NULL or empty country values
 */
$stmt = $pdo->prepare("
    SELECT country, COUNT(*) as count
    FROM link_clicks
    WHERE link_id = ? AND country IS NOT NULL AND country != ''
      AND clicked_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    GROUP BY country
    ORDER BY count DESC
    LIMIT 8
");
$stmt->execute([$linkId, $days]);
$countries = $stmt->fetchAll();

// --------------------------------------------------------------------------
// 3. TOP CITIES
// --------------------------------------------------------------------------
/**
 * Get top 5 cities by click count (with country info)
 * Useful for geographic heat mapping
 */
$stmt = $pdo->prepare("
    SELECT city, country, COUNT(*) as count
    FROM link_clicks
    WHERE link_id = ? AND city IS NOT NULL AND city != ''
      AND clicked_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    GROUP BY city, country
    ORDER BY count DESC
    LIMIT 5
");
$stmt->execute([$linkId, $days]);
$cities = $stmt->fetchAll();

// --------------------------------------------------------------------------
// 4. TOP REFERRERS (Traffic Sources)
// --------------------------------------------------------------------------
/**
 * Categorize referrer URLs into meaningful traffic sources
 * 
 * Categories:
 *   - Direct: No referrer or empty referrer
 *   - Google: google.com domains
 *   - Facebook: facebook.com or fb.com
 *   - Twitter/X: twitter.com or t.co
 *   - Instagram: instagram.com
 *   - LinkedIn: linkedin.com
 *   - Reddit: reddit.com
 *   - Other: All other known referrers
 */
$stmt = $pdo->prepare("
    SELECT
        CASE
            WHEN referer IS NULL OR referer = '' THEN 'Direct'
            WHEN referer LIKE '%google%'          THEN 'Google'
            WHEN referer LIKE '%facebook%'        THEN 'Facebook'
            WHEN referer LIKE '%twitter%'
              OR referer LIKE '%t.co%'            THEN 'Twitter/X'
            WHEN referer LIKE '%instagram%'       THEN 'Instagram'
            WHEN referer LIKE '%linkedin%'        THEN 'LinkedIn'
            WHEN referer LIKE '%reddit%'          THEN 'Reddit'
            ELSE 'Other'
        END as source,
        COUNT(*) as count
    FROM link_clicks
    WHERE link_id = ?
      AND clicked_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    GROUP BY source
    ORDER BY count DESC
");
$stmt->execute([$linkId, $days]);
$referrers = $stmt->fetchAll();

// --------------------------------------------------------------------------
// 5. PERIOD CLICKS
// --------------------------------------------------------------------------
/**
 * Total number of clicks in the selected time period
 * Used for the KPI card on the dashboard
 */
$stmt = $pdo->prepare("
    SELECT COUNT(*) as count
    FROM link_clicks
    WHERE link_id = ?
      AND clicked_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
");
$stmt->execute([$linkId, $days]);
$periodClicks = (int) $stmt->fetchColumn();

// --------------------------------------------------------------------------
// 6. UNIQUE VISITORS
// --------------------------------------------------------------------------
/**
 * Count unique visitors based on hashed IP addresses
 * 
 * @note Uses ip_hash (SHA256 with salt) - not reversible,
 *       but can be used to count unique visitors per link
 * @security Never stores or exposes raw IP addresses
 */
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT ip_hash) as count
    FROM link_clicks
    WHERE link_id = ?
      AND clicked_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
");
$stmt->execute([$linkId, $days]);
$uniqueVisitors = (int) $stmt->fetchColumn();

// ============================================================================
// JSON RESPONSE
// ============================================================================

/**
 * Build and return complete statistics JSON response
 * 
 * Response Structure:
 * @param object $link - Basic link information
 *   - short_code: The short identifier
 *   - original_url: Destination URL
 *   - total_clicks: Lifetime click count
 *   - created_at: Creation timestamp
 *   - expires_at: Expiration timestamp (or null)
 * 
 * @param object $period - Period-specific statistics
 *   - key: Period identifier (1d, 7d, 30d, 180d)
 *   - days: Number of days in period
 *   - clicks: Clicks within period
 *   - unique_visitors: Unique IPs within period
 * 
 * @param object $chart - Time series data for line chart
 *   - labels: Array of date strings (X-axis)
 *   - data: Array of click counts (Y-axis)
 * 
 * @param array $countries - Top 8 countries (name + count)
 * @param array $cities - Top 5 cities (name + country + count)
 * @param array $referrers - Traffic source breakdown (source + count)
 */
echo json_encode([
    'link' => [
        'short_code'   => $link['short_code'],
        'original_url' => $link['original_url'],
        'total_clicks' => (int) $link['clicks'],
        'created_at'   => $link['created_at'],
        'expires_at'   => $link['expires_at'],
    ],
    'period' => [
        'key'            => $period,
        'days'           => $days,
        'clicks'         => $periodClicks,
        'unique_visitors'=> $uniqueVisitors,
    ],
    'chart' => [
        'labels' => array_keys($chartData),
        'data'   => array_values($chartData),
    ],
    'countries' => $countries,
    'cities'    => $cities,
    'referrers' => $referrers,
]);