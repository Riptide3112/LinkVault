<?php
/**
 * admin_links.php - Admin Links Management API
 * 
 * This API endpoint handles all administrative actions related to links:
 * - Listing all links with pagination
 * - Deleting links permanently
 * - Suspending links (expiring them immediately)
 * 
 * Security:
 * - Requires active user session
 * - Requires admin privileges (is_admin = 1)
 * - CSRF protection for POST requests
 * - Prepared statements to prevent SQL injection
 * 
 * @package LinkVault
 * @subpackage AdminAPI
 * @requires db.php, config.php, security.php
 */

// Start session and include required files
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/security.php';

// Set JSON response headers for API consistency
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff'); // Prevent MIME type sniffing

// ============================================================================
// AUTHENTICATION & AUTHORIZATION
// ============================================================================

/**
 * Verify user is logged in
 * @return void Exits with error JSON if not authenticated
 */
if (!isset($_SESSION['user_id'])) {
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
 */
$stmt = $pdo->prepare("SELECT is_admin FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user || (int)$user['is_admin'] !== 1) {
    echo json_encode([
        'success' => false, 
        'error' => 'Access denied. Admin privileges required.'
    ]);
    exit;
}

// Determine which action to perform and the HTTP method used
$action = $_GET['action'] ?? 'list';
$method = $_SERVER['REQUEST_METHOD'];

// ============================================================================
// ACTION: LIST - Retrieve all links with pagination
// ============================================================================
/**
 * GET /api/admin_links.php?action=list&page=1
 * 
 * Returns a paginated list of all links in the system
 * 
 * Query Parameters:
 *   @param int $page - Page number (default: 1)
 *   @param int $limit - Results per page (hardcoded: 30)
 * 
 * Response:
 *   @param bool $success - Operation status
 *   @param array $links - Array of link objects
 *   @param int $total - Total number of links
 *   @param int $page - Current page number
 *   @param int $limit - Items per page
 *   @param int $total_pages - Total number of pages
 * 
 * Each link object contains:
 *   - id: Link ID
 *   - short_code: Unique short identifier
 *   - original_url: Destination URL
 *   - user_id: Owner ID (null for anonymous)
 *   - clicks: Total click count
 *   - expires_at: Expiration date (null = never)
 *   - created_at: Creation timestamp
 *   - username: Owner's username (null for anonymous)
 *   - short_url: Full shortened URL
 *   - display_user: Owner name or 'Anonymous'
 *   - is_expired: Boolean expiration status
 *   - original_url_short: Truncated URL for display
 *   - created_formatted: Human-readable creation date
 */
if ($action === 'list' && $method === 'GET') {
    // Pagination parameters
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = 30;
    $offset = ($page - 1) * $limit;

    // Get total count for pagination
    $totalStmt = $pdo->query("SELECT COUNT(*) FROM links");
    $total = (int) $totalStmt->fetchColumn();

    // Fetch links with user information via LEFT JOIN
    $stmt = $pdo->prepare("
        SELECT l.*, u.username 
        FROM links l 
        LEFT JOIN users u ON l.user_id = u.id 
        ORDER BY l.created_at DESC 
        LIMIT ? OFFSET ?
    ");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $links = $stmt->fetchAll();

    // Process each link to add computed fields
    foreach ($links as &$link) {
        $link['short_url'] = BASE_URL . '/' . $link['short_code'];
        $link['display_user'] = $link['username'] ?? 'Anonymous';
        $link['is_expired'] = $link['expires_at'] && strtotime($link['expires_at']) < time();
        $link['original_url_short'] = strlen($link['original_url']) > 60 
            ? substr($link['original_url'], 0, 57) . '...' 
            : $link['original_url'];
        $link['created_formatted'] = date('d M Y', strtotime($link['created_at']));
    }

    echo json_encode([
        'success' => true,
        'links' => $links,
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'total_pages' => ceil($total / $limit)
    ]);
    exit;
}

// ============================================================================
// ACTION: DELETE - Permanently remove a link
// ============================================================================
/**
 * POST /api/admin_links.php?action=delete&id={linkId}
 * 
 * Permanently deletes a link and all associated click statistics
 * 
 * URL Parameters:
 *   @param int $id - The ID of the link to delete
 * 
 * Headers Required:
 *   @param string X-CSRF-Token - CSRF protection token
 * 
 * Response:
 *   @param bool $success - Operation status
 *   @param string $error - Error message (on failure)
 * 
 * Security:
 *   - CSRF token validation
 *   - Cascading delete on link_clicks table
 */
if ($action === 'delete' && $method === 'POST') {
    $linkId = (int)($_GET['id'] ?? 0);
    
    // Validate CSRF token for security
    $headers = getallheaders();
    $csrfToken = $headers['X-CSRF-Token'] ?? '';
    
    if (!hash_equals(csrf_token(), $csrfToken)) {
        echo json_encode([
            'success' => false, 
            'error' => 'Invalid CSRF token'
        ]);
        exit;
    }
    
    // Delete associated click records first (foreign key constraint)
    $pdo->prepare("DELETE FROM link_clicks WHERE link_id = ?")->execute([$linkId]);
    // Delete the link itself
    $pdo->prepare("DELETE FROM links WHERE id = ?")->execute([$linkId]);
    
    echo json_encode(['success' => true]);
    exit;
}

// ============================================================================
// ACTION: SUSPEND - Immediately expire a link
// ============================================================================
/**
 * POST /api/admin_links.php?action=suspend&id={linkId}
 * 
 * Immediately expires a link by setting its expiration date to yesterday
 * The link becomes inaccessible to users
 * 
 * URL Parameters:
 *   @param int $id - The ID of the link to suspend
 * 
 * Headers Required:
 *   @param string X-CSRF-Token - CSRF protection token
 * 
 * Response:
 *   @param bool $success - Operation status
 *   @param string $error - Error message (on failure)
 * 
 * Use Case:
 *   - Suspicious content detection
 *   - Abuse prevention
 *   - Temporary takedown without permanent deletion
 */
if ($action === 'suspend' && $method === 'POST') {
    $linkId = (int)($_GET['id'] ?? 0);
    
    // Validate CSRF token for security
    $headers = getallheaders();
    $csrfToken = $headers['X-CSRF-Token'] ?? '';
    
    if (!hash_equals(csrf_token(), $csrfToken)) {
        echo json_encode([
            'success' => false, 
            'error' => 'Invalid CSRF token'
        ]);
        exit;
    }
    
    // Set expiration to yesterday (ensures link is expired)
    $expireNow = date('Y-m-d H:i:s', strtotime('-1 second'));
    $stmt = $pdo->prepare("UPDATE links SET expires_at = ? WHERE id = ?");
    $stmt->execute([$expireNow, $linkId]);
    
    echo json_encode(['success' => true]);
    exit;
}

// ============================================================================
// DEFAULT RESPONSE
// ============================================================================
/**
 * Fallback response for unrecognized actions
 * Returns error if the requested action doesn't exist
 */
echo json_encode([
    'success' => false, 
    'error' => 'Invalid action. Supported actions: list, delete, suspend'
]);
exit;