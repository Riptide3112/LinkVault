<?php
/**
 * admin_user.php - Admin User Management API
 * 
 * This API endpoint handles all administrative actions related to users:
 * - Listing all registered users with pagination
 * - Retrieving detailed user information including their links
 * - Deleting individual links owned by users
 * - Deleting entire user accounts (with cascading cleanup)
 * 
 * Security:
 * - Requires active user session
 * - Requires admin privileges (is_admin = 1)
 * - Output buffering for clean JSON responses
 * - Prepared statements to prevent SQL injection
 * - Prevents admin from deleting their own account
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
    ob_clean();
    echo json_encode([
        'success' => false, 
        'error' => 'Unauthorized. Please log in.'
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
$adminCheck = $stmt->fetch();

if (!$adminCheck || (int)$adminCheck['is_admin'] !== 1) {
    ob_clean();
    echo json_encode([
        'success' => false, 
        'error' => 'Forbidden. Admin privileges required.'
    ]);
    exit;
}

// Determine which action to perform and the HTTP method used
$action = $_GET['action'] ?? 'list';
$method = $_SERVER['REQUEST_METHOD'];

// ============================================================================
// ACTION HANDLERS
// ============================================================================

try {
    // ------------------------------------------------------------------------
    // ACTION: LIST - Get all users with pagination
    // ------------------------------------------------------------------------
    /**
     * GET /api/admin_user.php?action=list&page=1
     * 
     * Returns a paginated list of all registered users
     * Excludes the 'Anonymous' placeholder user used for guest links
     * 
     * Query Parameters:
     *   @param int $page - Page number (default: 1)
     * 
     * Response:
     *   @param bool $success - Operation status
     *   @param array $users - Array of user objects
     *   @param int $total_pages - Total number of pages
     *   @param int $current_page - Current page number
     * 
     * Each user object contains:
     *   - id: User ID
     *   - username: Login name
     *   - email: User's email address
     *   - created_at: Registration timestamp
     *   - link_count: Total links owned by this user
     *   - total_clicks: Sum of all clicks on user's links
     * 
     * @security Uses subqueries for aggregated data (efficient and safe)
     * @security LIMIT and OFFSET are bound as integers to prevent injection
     */
    if ($action === 'list' && $method === 'GET') {
        $limit = 10; // Items per page
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $offset = ($page - 1) * $limit;

        // Get total count for pagination (excluding Anonymous)
        $totalUsers = $pdo->query("SELECT COUNT(*) FROM users WHERE username != 'Anonymous'")->fetchColumn();
        $totalPages = ceil($totalUsers / $limit);

        // Fetch users with their link statistics
        $stmt = $pdo->prepare("
            SELECT u.id, u.username, u.email, u.created_at, 
                   (SELECT COUNT(*) FROM links WHERE user_id = u.id) as link_count,
                   (SELECT COALESCE(SUM(clicks), 0) FROM links WHERE user_id = u.id) as total_clicks
            FROM users u
            WHERE u.username != 'Anonymous'
            ORDER BY u.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $users = $stmt->fetchAll();

        ob_clean();
        echo json_encode([
            'success' => true,
            'users' => $users,
            'total_pages' => (int)$totalPages,
            'current_page' => (int)$page
        ]);
        exit;
    }

    // ------------------------------------------------------------------------
    // ACTION: DETAILS - Get user details for admin modal
    // ------------------------------------------------------------------------
    /**
     * GET /api/admin_user.php?action=details&id={userId}
     * 
     * Returns comprehensive user information including:
     * - Basic profile data (username, email, registration date)
     * - Statistics (total links, total clicks)
     * - List of user's links (up to 50 most recent)
     * - Activity chart data for the last 14 days
     * 
     * URL Parameters:
     *   @param int $id - The ID of the user to fetch
     * 
     * Response:
     *   @param bool $success - Operation status
     *   @param object $user - User profile data
     *   @param array $links - Array of user's links with computed fields
     *   @param array $stats - Chart data for user activity visualization
     * 
     * @security User ID is cast to integer to prevent SQL injection
     */
    if ($action === 'details' && $method === 'GET') {
        $userId = (int)($_GET['id'] ?? 0);
        
        /**
         * Fetch user basic info with aggregated statistics
         * Uses subqueries for efficient single-query retrieval
         */
        $stmt = $pdo->prepare("
            SELECT id, username, email, created_at,
                   (SELECT COUNT(*) FROM links WHERE user_id = ?) as total_links,
                   (SELECT COALESCE(SUM(clicks), 0) FROM links WHERE user_id = ?) as total_clicks
            FROM users WHERE id = ?
        ");
        $stmt->execute([$userId, $userId, $userId]);
        $user = $stmt->fetch();

        if (!$user) {
            ob_clean();
            echo json_encode([
                'success' => false, 
                'error' => 'User not found'
            ]);
            exit;
        }

        /**
         * Fetch user's links (limit 50 most recent)
         * Ordered by creation date descending to show newest first
         */
        $stmtLinks = $pdo->prepare("
            SELECT id, short_code, original_url, clicks, created_at, expires_at 
            FROM links 
            WHERE user_id = ? 
            ORDER BY created_at DESC 
            LIMIT 50
        ");
        $stmtLinks->execute([$userId]);
        $links = $stmtLinks->fetchAll();

        /**
         * Process links for frontend display
         * Adds computed fields for better UX:
         * - is_expired: Check if link has expired
         * - original_url_short: Truncated URL for display
         * - created_formatted: Human-readable creation date
         * - short_url: Complete shortened URL
         */
        foreach ($links as &$link) {
            $link['is_expired'] = !empty($link['expires_at']) && strtotime($link['expires_at']) < time();
            $link['original_url_short'] = strlen($link['original_url']) > 50 
                ? substr($link['original_url'], 0, 47) . '...' 
                : $link['original_url'];
            $link['created_formatted'] = date('d M Y', strtotime($link['created_at']));
            $link['short_url'] = BASE_URL . '/' . $link['short_code'];
        }

        /**
         * Generate activity chart data for the last 14 days
         * Shows how many links the user created each day
         * Used for the bar chart in the admin modal
         */
        $chartData = [];
        $dates = [];
        
        // Generate date range (last 14 days)
        for ($i = 13; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $dates[$date] = date('d M', strtotime($date));
        }
        
        // Count links created on each date
        foreach ($dates as $fullDate => $displayDate) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM links WHERE user_id = ? AND DATE(created_at) = ?");
            $st->execute([$userId, $fullDate]);
            $chartData[] = [
                'date' => $displayDate,
                'links' => (int)$st->fetchColumn()
            ];
        }

        ob_clean();
        echo json_encode([
            'success' => true,
            'user' => $user,
            'links' => $links,
            'stats' => $chartData
        ]);
        exit;
    }

    // ------------------------------------------------------------------------
    // ACTION: DELETE_LINK - Delete a single link from a user
    // ------------------------------------------------------------------------
    /**
     * GET /api/admin_user.php?action=delete_link&id={linkId}
     * 
     * Permanently deletes a specific link and all its click statistics
     * Used when admin removes a link from the user modal
     * 
     * URL Parameters:
     *   @param int $id - The ID of the link to delete
     * 
     * Response:
     *   @param bool $success - Operation status
     * 
     * @security Cascading delete: removes clicks first then the link
     * @warning This operation is permanent and cannot be undone
     */
    if ($action === 'delete_link' && $method === 'GET') {
        $linkId = (int)($_GET['id'] ?? 0);
        
        // Delete associated click records first (foreign key constraint)
        $pdo->prepare("DELETE FROM link_clicks WHERE link_id = ?")->execute([$linkId]);
        // Delete the link itself
        $pdo->prepare("DELETE FROM links WHERE id = ?")->execute([$linkId]);
        
        ob_clean();
        echo json_encode(['success' => true]);
        exit;
    }

    // ------------------------------------------------------------------------
    // ACTION: DELETE - Delete a complete user account
    // ------------------------------------------------------------------------
    /**
     * GET /api/admin_user.php?action=delete&id={userId}
     * 
     * Permanently deletes a user account and all associated data:
     * - All link click statistics
     * - All links owned by the user
     * - The user account itself
     * 
     * URL Parameters:
     *   @param int $id - The ID of the user to delete
     * 
     * Response:
     *   @param bool $success - Operation status
     *   @param string $error - Error message (if any)
     * 
     * Security Features:
     *   - Prevents admin from deleting their own account
     *   - Cascading deletion of all user data
     *   - Uses proper foreign key relationships
     * 
     * @security Self-deletion prevention is critical for system integrity
     */
    if ($action === 'delete' && $method === 'GET') {
        $userId = (int)($_GET['id'] ?? 0);
        
        /**
         * Prevent admin from deleting their own account
         * This ensures at least one admin always remains in the system
         */
        if ($userId === (int)$_SESSION['user_id']) {
            ob_clean();
            echo json_encode([
                'success' => false, 
                'error' => 'You cannot delete your own account'
            ]);
            exit;
        }
        
        /**
         * Cascade deletion in correct order to maintain referential integrity:
         * 1. Delete all click records from user's links
         * 2. Delete all links owned by the user
         * 3. Delete the user account itself
         */
        $pdo->prepare("DELETE FROM link_clicks WHERE link_id IN (SELECT id FROM links WHERE user_id = ?)")->execute([$userId]);
        $pdo->prepare("DELETE FROM links WHERE user_id = ?")->execute([$userId]);
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
        
        ob_clean();
        echo json_encode(['success' => true]);
        exit;
    }

    // ------------------------------------------------------------------------
    // DEFAULT: Invalid action handler
    // ------------------------------------------------------------------------
    /**
     * Fallback response for unrecognized actions
     * Returns error if the requested action doesn't exist
     */
    ob_clean();
    echo json_encode([
        'success' => false, 
        'error' => 'Invalid action. Supported actions: list, details, delete_link, delete'
    ]);

} catch (Exception $e) {
    /**
     * Global Error Handling
     * Catches any database or processing errors and returns a clean error response
     * 
     * @security Does NOT expose exception details to client to prevent information leakage
     * @security Cleans output buffer before sending error JSON
     */
    ob_clean();
    echo json_encode([
        'success' => false, 
        'error' => 'Database error. Please try again later.'
    ]);
}