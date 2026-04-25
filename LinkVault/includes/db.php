<?php
/**
 * db.php - Database Connection Handler
 * 
 * This file establishes the PDO (PHP Data Objects) connection to the MySQL/MariaDB
 * database. It includes comprehensive error handling that detects request types
 * and returns appropriate responses (JSON for AJAX, HTML for regular requests).
 * 
 * PDO Configuration Security:
 *   - Uses exception mode for consistent error handling
 *   - Disables emulated prepared statements (uses native prepared statements)
 *   - Sets default fetch mode to associative array
 *   - Uses UTF-8 character set for proper unicode support
 * 
 * Error Handling:
 *   - AJAX requests receive JSON error responses
 *   - Regular requests receive user-friendly HTML error pages
 *   - HTTP 503 (Service Unavailable) status for database failures
 * 
 * @package LinkVault
 * @subpackage Database
 * @location /includes/db.php
 * @requires config.php
 * @global PDO $pdo The database connection object (used throughout the application)
 */

// Include configuration constants (DB_HOST, DB_NAME, DB_USER, DB_PASS)
require_once __DIR__ . '/config.php';

// ============================================================================
// PDO CONNECTION
// ============================================================================

/**
 * Establish PDO database connection with secure configuration
 * 
 * DSN (Data Source Name) format:
 *   mysql:host=localhost;dbname=linkvault;charset=utf8mb4
 * 
 * @security utf8mb4 charset is required for full Unicode support (including emojis)
 * @security Native prepared statements (ATTR_EMULATE_PREPARES = false) prevents SQL injection
 */
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            /**
             * PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
             * 
             * Causes PDO to throw exceptions (PDOException) on errors
             * Benefits:
             *   - Consistent error handling with try-catch blocks
             *   - Detailed error information for debugging
             *   - Prevents silent failures
             */
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            
            /**
             * PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
             * 
             * Sets the default fetch mode to associative array
             * Results are returned as: $row['column_name']
             * Alternative would be FETCH_OBJ ($row->column_name)
             * 
             * @benefit More readable and explicit than numeric indices
             */
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            
            /**
             * PDO::ATTR_EMULATE_PREPARES => false
             * 
             * DISABLES emulated prepared statements (SECURITY CRITICAL)
             * 
             * When true (default in some PHP versions), PDO emulates prepared statements
             * which can lead to SQL injection vulnerabilities in edge cases.
             * 
             * When false, PDO uses NATIVE prepared statements provided by MySQL/MariaDB
             * which are completely safe from SQL injection.
             * 
             * @security CRITICAL - This is one of the most important security settings
             */
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    // ========================================================================
    // ERROR HANDLING - Detect Request Type
    // ========================================================================
    
    /**
     * Detect if the request expects JSON response (AJAX/API call)
     * 
     * Two detection methods:
     *   1. X-Requested-With header (set by most JS libraries: jQuery, Axios, Fetch)
     *   2. HTTP_ACCEPT header containing 'application/json'
     * 
     * @security Never expose database error details to clients
     * @security Generic error messages prevent information leakage
     */
    $isAjax = (
        !empty($_SERVER['HTTP_X_REQUESTED_WITH']) ||
        (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
    );

    if ($isAjax) {
        /**
         * AJAX/API Response
         * 
         * Returns clean JSON error object
         * HTTP status will remain 200 (for simplicity) but error flag is set
         * 
         * @response JSON
         */
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'Database connection failed. Please try again later.',
            'success' => false
        ]);
    } else {
        /**
         * Regular Web Request Response
         * 
         * Returns user-friendly HTML error page
         * HTTP status 503 indicates service is temporarily unavailable
         * 
         * @response HTML
         * @http 503 Service Unavailable
         */
        http_response_code(503);
        echo '<!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>503 - Service Unavailable</title>
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
                <div class="error-code">503</div>
                <h1>Service Unavailable</h1>
                <p>We are experiencing technical difficulties.<br>Please try again later.</p>
                <a href="/LinkVault/">⟳ Retry</a>
            </div>
        </body>
        </html>';
    }
    // Stop script execution after error output
    exit;
}

// ============================================================================
// CONNECTION SUCCESSFUL
// ============================================================================

/**
 * If execution reaches this point, the PDO connection is successful.
 * The $pdo object is now available globally for all database queries.
 * 
 * @global PDO $pdo Use this object throughout the application for database operations
 * @example $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
 * @example $stmt->execute([$userId]);
 * @example $user = $stmt->fetch();
 */