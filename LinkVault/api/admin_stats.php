<?php
/**
 * admin_stats.php - Admin Statistics API
 * 
 * This API endpoint provides real-time system statistics for the admin dashboard:
 * - Total registered users (excluding 'Anonymous' placeholder)
 * - Total links in the system
 * - Active links (not expired)
 * - Total clicks across all links
 * - Daily activity chart data for the last 30 days
 * 
 * Security:
 * - Requires active user session
 * - Requires admin privileges (is_admin = 1)
 * - Output buffering for clean JSON responses
 * - Prepared statements to prevent SQL injection
 * 
 * @package LinkVault
 * @subpackage AdminAPI
 * @requires db.php, config.php, security.php
 */

// Start output buffering to prevent any accidental output before JSON
ob_start();
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/security.php';

// Set JSON response headers
header('Content-Type: application/json');
header('Cache-Control: no-cache, private'); // Prevent caching of sensitive data
header('X-Content-Type-Options: nosniff'); // Prevent MIME type sniffing

// ============================================================================
// AUTHENTICATION & AUTHORIZATION
// ============================================================================

/**
 * Verify user is logged in
 * @return void Exits with error JSON if not authenticated
 * @security Cleans output buffer before sending JSON to avoid corruption
 */
if (!isset($_SESSION['user_id'])) {
    ob_clean(); // Remove any accidental output before JSON
    echo json_encode([
        'success' => false, 
        'error' => 'Not logged in'
    ]);
    exit;
}

/**
 * Verify user has admin privileges
 * Check the is_admin flag in the users table
 * @return void Exits with error JSON if not admin
 * @security Cleans output buffer before sending JSON to avoid corruption
 */
$stmt = $pdo->prepare("SELECT is_admin FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user || (int)$user['is_admin'] !== 1) {
    ob_clean();
    echo json_encode([
        'success' => false, 
        'error' => 'Access denied. Admin privileges required.'
    ]);
    exit;
}

// ============================================================================
// DATA COLLECTION & PROCESSING
// ============================================================================

try {
    // Configuration - analyze last 30 days of activity
    $days = 30;

    /**
     * Basic System Statistics
     * All queries use COUNT(*) for performance and accuracy
     * 
     * @var int $totalUsers - Excludes the 'Anonymous' placeholder user used for guest links
     * @var int $totalLinks - All links regardless of expiration status
     * @var int $activeLinks - Links that are either permanent or not yet expired
     * @var int $totalClicks - Total click events across all links
     */
    $totalUsers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE username != 'Anonymous'")->fetchColumn();
    $totalLinks = (int) $pdo->query("SELECT COUNT(*) FROM links")->fetchColumn();
    $activeLinks = (int) $pdo->query("SELECT COUNT(*) FROM links WHERE expires_at IS NULL OR expires_at > NOW()")->fetchColumn();
    $totalClicks = (int) $pdo->query("SELECT COUNT(*) FROM link_clicks")->fetchColumn();

    /**
     * Chart Data Structure for Last 30 Days
     * Initializes arrays with all dates in the period, setting default values to 0
     * This ensures the chart shows complete data even for days with no activity
     * 
     * @var array $chartLabels - Human-readable date labels (e.g., "15 Apr")
     * @var array $clicksSeries - Click counts for each day (initialized to 0)
     * @var array $linksSeries - Link creation counts for each day (initialized to 0)
     * @var array $dateIndex - Maps actual dates to array indices for O(1) lookup
     */
    $chartLabels = [];
    $clicksSeries = [];
    $linksSeries = [];
    $dateIndex = []; // Used for efficient data insertion

    // Generate the last 30 days in reverse chronological order (oldest to newest)
    for ($i = $days - 1; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $displayDate = date('d M', strtotime($date));
        
        $chartLabels[] = $displayDate;
        $clicksSeries[] = 0;
        $linksSeries[] = 0;
        
        // Store mapping for O(1) lookup when populating data
        $dateIndex[$date] = $days - 1 - $i;
    }

    /**
     * Populate Click Data
     * Query returns actual click counts grouped by date
     * Uses prepared statement with parameter binding for security
     * 
     * @security Prepared statement prevents SQL injection
     */
    $stmtClicks = $pdo->prepare("
        SELECT DATE(clicked_at) as date, COUNT(*) as count
        FROM link_clicks
        WHERE clicked_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
        GROUP BY DATE(clicked_at)
    ");
    $stmtClicks->execute([$days]);
    
    foreach ($stmtClicks->fetchAll() as $row) {
        if (isset($dateIndex[$row['date']])) {
            $clicksSeries[$dateIndex[$row['date']]] = (int)$row['count'];
        }
    }

    /**
     * Populate Link Creation Data
     * Query returns link creation counts grouped by date
     * Uses prepared statement with parameter binding for security
     * 
     * @security Prepared statement prevents SQL injection
     */
    $stmtLinks = $pdo->prepare("
        SELECT DATE(created_at) as date, COUNT(*) as count
        FROM links
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
        GROUP BY DATE(created_at)
    ");
    $stmtLinks->execute([$days]);
    
    foreach ($stmtLinks->fetchAll() as $row) {
        if (isset($dateIndex[$row['date']])) {
            $linksSeries[$dateIndex[$row['date']]] = (int)$row['count'];
        }
    }

    /**
     * Build Final JSON Response
     * 
     * Response Structure:
     * @param bool $success - Always true when statistics load successfully
     * @param array $stats - Key system metrics
     *   - total_users: Number of registered users
     *   - total_links: Total links in database
     *   - total_clicks: Total click events
     *   - active_links: Currently active (non-expired) links
     * @param array $chart - Daily activity data for line chart visualization
     *   - labels: X-axis date labels (last 30 days)
     *   - clicks: Y-axis click counts per day
     *   - links_created: Y-axis link creation counts per day
     */
    $response = [
        'success' => true,
        'stats' => [
            'total_users' => $totalUsers,
            'total_links' => $totalLinks,
            'total_clicks' => $totalClicks,
            'active_links' => $activeLinks
        ],
        'chart' => [
            'labels' => $chartLabels,
            'clicks' => $clicksSeries,
            'links_created' => $linksSeries
        ]
    ];

    // Clean buffer and send response
    ob_clean();
    echo json_encode($response);

} catch (Exception $e) {
    /**
     * Error Handling
     * Catches any database or processing errors and returns a clean error response
     * 
     * @security Does not expose exception details to client (security by obscurity)
     * @security Cleans output buffer before sending error JSON
     */
    ob_clean();
    echo json_encode([
        'success' => false, 
        'error' => 'Database error. Please try again later.'
    ]);
}