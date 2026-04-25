<?php
/**
 * admin_auth.php - Admin Authentication Middleware
 * 
 * This file acts as authentication middleware for all admin panel pages.
 * It verifies that:
 *   1. The user is logged in (active session)
 *   2. The user has admin privileges (is_admin = 1)
 * 
 * How to use:
 *   Include this file at the beginning of any admin page:
 *   ```php
 *   require_once __DIR__ . '/../includes/admin_auth.php';
 *   ```
 * 
 * Security Features:
 *   - Session validation (prevents direct access without login)
 *   - Database verification of admin status (prevents session tampering)
 *   - HTTP 403 response for unauthorized access
 *   - AJAX detection for appropriate response format
 *   - Generic error messages to prevent information disclosure
 * 
 * @package LinkVault
 * @subpackage Security
 * @location /includes/admin_auth.php
 * @requires db.php, config.php
 * @note This file should be included after session_start()
 */

// Prevent direct access to this file
if (basename($_SERVER['PHP_SELF']) === 'admin_auth.php') {
    http_response_code(403);
    exit('Direct access not allowed.');
}

// ============================================================================
// SESSION CHECK - Verify User is Logged In
// ============================================================================

/**
 * Check if user has an active session
 * 
 * If not logged in, redirect to login page.
 * This prevents unauthorized access to admin-only pages.
 * 
 * @security Critical - First line of defense for admin panel
 */
if (!isset($_SESSION['user_id'])) {
    header('Location: /LinkVault/pages/login.php');
    exit;
}

// ============================================================================
// DATABASE CONNECTION
// ============================================================================

/**
 * Include database and configuration files
 * These are needed to verify admin status from the database
 * 
 * @note Using __DIR__ ensures correct path resolution regardless of where
 *       this file is included from
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

// ============================================================================
// ADMIN STATUS VERIFICATION
// ============================================================================

/**
 * Verify admin privileges from database
 * 
 * Database check is essential because:
 *   1. Prevents session hijacking (admin status is server-side)
 *   2. Ensures user hasn't been demoted since login
 *   3. Provides defense against session tampering
 * 
 * @security Even if session claims admin, we verify with database
 * @security Prepared statement prevents SQL injection
 */
$stmt = $pdo->prepare("SELECT is_admin FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

/**
 * Check if user exists and has admin privileges
 * 
 * If not admin, return HTTP 403 Forbidden with error details.
 * Using generic error message to avoid information disclosure.
 * 
 * @security Generic error message prevents user enumeration
 * @security HTTP 403 is the correct status for forbidden access
 */
if (!$user || $user['is_admin'] != 1) {
    http_response_code(403);
    
    /**
     * Detect request type and respond appropriately
     * 
     * - AJAX requests (X-Requested-With header) get JSON response
     * - Regular requests get HTML error page
     * 
     * @security Consistent error messages prevent information leakage
     */
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        // API/AJAX request - return JSON error
        echo json_encode([
            'error' => 'Access denied. Admin privileges required.',
            'code' => 403
        ]);
    } else {
        // Regular request - show HTML error page
        echo '<!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>403 - Access Denied</title>
            <style>
                body {
                    background: #09090B;
                    color: #FAFAFA;
                    font-family: monospace;
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    min-height: 100vh;
                    margin: 0;
                    padding: 20px;
                }
                .error-box {
                    background: #111113;
                    border: 1px solid #27272A;
                    border-radius: 16px;
                    padding: 40px;
                    text-align: center;
                    max-width: 400px;
                }
                .error-code { 
                    font-size: 4rem; 
                    color: #EF4444; 
                    margin-bottom: 16px; 
                    font-weight: bold;
                }
                h1 { 
                    font-size: 1.5rem; 
                    margin-bottom: 8px; 
                    color: #FAFAFA;
                }
                p { 
                    color: #71717A; 
                    margin-bottom: 24px; 
                    line-height: 1.6;
                }
                a { 
                    color: #00D4B4; 
                    text-decoration: none; 
                    display: inline-block;
                    padding: 8px 16px;
                    background: rgba(0,212,180,0.1);
                    border-radius: 8px;
                    transition: all 0.2s;
                }
                a:hover { 
                    background: rgba(0,212,180,0.2);
                    text-decoration: none;
                }
            </style>
        </head>
        <body>
            <div class="error-box">
                <div class="error-code">403</div>
                <h1>Access Denied</h1>
                <p>You do not have permission to access this area.<br>Admin privileges required.</p>
                <a href="/LinkVault/">← Return to Home</a>
            </div>
        </body>
        </html>';
    }
    exit;
}

// ============================================================================
// ADMIN ACCESS GRANTED
// ============================================================================

/**
 * If execution reaches this point, access is granted.
 * 
 * The admin page that included this file will continue executing
 * with the knowledge that the user is:
 *   1. Authenticated (logged in)
 *   2. Authorized (has admin privileges)
 * 
 * @note No further checks needed - page can safely execute admin operations
 */