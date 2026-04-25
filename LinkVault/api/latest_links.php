<?php
/**
 * api/latest_links.php - Latest Links API Endpoint
 * 
 * This API endpoint returns the most recently created links for the public homepage.
 * It is used for the "Latest created links" section that auto-refreshes every 30 seconds.
 * 
 * Features:
 * - Returns last 8 links ordered by creation date (newest first)
 * - Includes user information (username or 'Anonymous' for guests)
 * - Provides formatted data for frontend display
 * - Calculates expiration status for each link
 * 
 * Security:
 * - No authentication required (public endpoint for homepage)
 * - Prepared statements to prevent SQL injection
 * - JSON output with proper headers
 * - No sensitive data exposed (IP addresses, emails, etc.)
 * 
 * @package LinkVault
 * @subpackage PublicAPI
 * @requires db.php, config.php, security.php
 */

declare(strict_types=1); // Enforce strict typing for better code reliability

session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/security.php';

// Set JSON response headers
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate'); // Prevent caching for real-time updates

// ============================================================================
// DATA QUERY - Fetch Most Recent Links
// ============================================================================

/**
 * Query the 8 most recently created links
 * 
 * SQL Details:
 * - LEFT JOIN with users table to get usernames (null for guest links)
 * - ORDER BY created_at DESC ensures newest links appear first
 * - LIMIT 8 keeps the response size small and loading fast
 * 
 * @security Prepared statement prevents SQL injection
 * @performance LIMIT 8 ensures fast query even with millions of links
 */
$stmt = $pdo->prepare("
    SELECT 
        l.id,
        l.short_code,
        l.original_url,
        l.clicks,
        l.expires_at,
        l.created_at,
        u.username
    FROM links l
    LEFT JOIN users u ON l.user_id = u.id
    ORDER BY l.created_at DESC
    LIMIT 8
");
$stmt->execute();
$links = $stmt->fetchAll();

// ============================================================================
// DATA PROCESSING - Format Links for Frontend Display
// ============================================================================

/**
 * Process each link to add computed fields for the frontend
 * 
 * Each link object receives:
 * @property string $original_url_short - Truncated URL for display (max 65 chars)
 * @property string $display_user - Username or 'Anonymous' for guest links
 * @property bool $is_expired - Flag indicating if link has expired
 * @property string $short_url - Complete shortened URL
 * @property string $created_formatted - Human-readable creation date/time
 * @property string|null $expires_formatted - Human-readable expiration date (if set)
 * @property string $expires_status - Status: 'expires', 'expired', or 'permanent'
 */
foreach ($links as &$link) {
    // Truncate long URLs for cleaner display (preserve full URL in tooltip)
    if (strlen($link['original_url']) > 65) {
        $link['original_url_short'] = substr($link['original_url'], 0, 62) . '...';
    } else {
        $link['original_url_short'] = $link['original_url'];
    }
    
    // Display user info - 'Anonymous' for guest-created links (user_id = NULL)
    $link['display_user'] = $link['username'] ?? 'Anonymous';
    
    // Check if link has expired (expires_at is in the past)
    $link['is_expired'] = $link['expires_at'] && strtotime($link['expires_at']) < time();
    
    // Construct full shortened URL using configured base URL
    $link['short_url'] = BASE_URL . '/' . $link['short_code'];
    
    // Format creation date for display (e.g., "15 Apr 2024, 14:30")
    $link['created_formatted'] = date('d M Y, H:i', strtotime($link['created_at']));
    
    // Determine expiration status for frontend badge/styling
    if ($link['expires_at'] && !$link['is_expired']) {
        // Link has a future expiration date
        $link['expires_formatted'] = date('d M Y', strtotime($link['expires_at']));
        $link['expires_status'] = 'expires';
    } elseif ($link['is_expired']) {
        // Link has passed its expiration date
        $link['expires_status'] = 'expired';
    } else {
        // Link never expires (expires_at IS NULL)
        $link['expires_status'] = 'permanent';
    }
}

// ============================================================================
// JSON RESPONSE
// ============================================================================

/**
 * Return JSON response with:
 * @param bool $success - Always true for this endpoint (no failure states)
 * @param int $timestamp - Current server time for cache-busting on frontend
 * @param array $links - Processed links array with all display fields
 * 
 * @note JSON_UNESCAPED_SLASHES keeps URLs readable (no escaping of forward slashes)
 */
echo json_encode([
    'success' => true,
    'timestamp' => time(), // Helps frontend detect stale data
    'links' => $links
], JSON_UNESCAPED_SLASHES);