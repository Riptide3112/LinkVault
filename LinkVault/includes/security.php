<?php
/**
 * security.php - Core Security Functions
 * 
 * This file provides essential security mechanisms for the application:
 *   1. CSRF (Cross-Site Request Forgery) Protection
 *   2. Rate Limiting (Database-based)
 *   3. Client IP Address Detection (with proxy support)
 * 
 * IMPORTANT: This file must be included AFTER session_start() to ensure
 * the session is available for CSRF token storage.
 * 
 * Security Features:
 *   - Cryptographically secure random CSRF tokens (32 bytes)
 *   - Timing-safe token comparison (hash_equals prevents timing attacks)
 *   - Database-backed rate limiting with automatic cleanup
 *   - IP detection that respects common proxy headers (CloudFlare, etc.)
 * 
 * @package LinkVault
 * @subpackage Security
 * @location /includes/security.php
 * @requires session_start() to be called before including this file
 */

// ============================================================================
// CSRF PROTECTION - Cross-Site Request Forgery Prevention
// ============================================================================

/**
 * Generate or retrieve CSRF token for the current session
 * 
 * CSRF tokens prevent attackers from making unauthorized requests on behalf
 * of authenticated users. Each form must include this token, and the server
 * verifies it before processing POST requests.
 * 
 * @return string 64-character hexadecimal CSRF token
 * 
 * @security Uses random_bytes(32) for cryptographically secure randomness
 * @security Token stored in session (server-side) for verification
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        // Generate 32 random bytes -> 64 hex characters
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Generate HTML hidden input field with CSRF token
 * 
 * Use this function inside forms to include CSRF protection:
 *   <?= csrf_field() ?>
 * 
 * @return string HTML input element with CSRF token
 * 
 * @security Uses htmlspecialchars to prevent XSS in token value
 */
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

/**
 * Verify CSRF token from POST request
 * 
 * Call this function at the beginning of all POST request handlers.
 * It validates that the token submitted with the form matches the one
 * stored in the session.
 * 
 * @return void Exits with HTTP 403 if token is invalid or missing
 * 
 * @security Uses hash_equals() for timing-safe comparison
 * @security Returns HTTP 403 Forbidden status code
 * @warning Exits script execution on failure
 */
function csrf_verify(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (empty($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        exit('Invalid or missing CSRF token.');
    }
}

// ============================================================================
// RATE LIMITING - Brute Force Protection
// ============================================================================

/**
 * Check if a key has exceeded its rate limit
 * 
 * Rate limiting prevents brute force attacks, API abuse, and DoS attempts.
 * Each action (login, registration, URL shortening) can have its own limits.
 * 
 * @param PDO $pdo Database connection
 * @param string $key Unique identifier (e.g., IP hash or user ID + action)
 * @param int $maxAttempts Maximum allowed attempts within the window (default: 5)
 * @param int $windowSeconds Time window in seconds (default: 300 = 5 minutes)
 * @return bool True if rate limit exceeded, false if under limit
 * 
 * @security Automatically cleans up expired rate limit records
 * @security Uses prepared statement to prevent SQL injection
 */
function rate_limit_check(PDO $pdo, string $key, int $maxAttempts = 5, int $windowSeconds = 300): bool {
    // Clean up expired records (maintenance)
    $pdo->prepare("DELETE FROM rate_limits WHERE expires_at < NOW()")->execute();

    // Check current attempts for this key
    $stmt = $pdo->prepare("SELECT attempts FROM rate_limits WHERE `key` = ? LIMIT 1");
    $stmt->execute([$key]);
    $row = $stmt->fetch();

    return $row && $row['attempts'] >= $maxAttempts;
}

/**
 * Record an attempt for rate limiting
 * 
 * Call this function after each failed action (login attempt, registration, etc.)
 * If the key doesn't exist, creates a new record. If it exists, increments counter.
 * 
 * @param PDO $pdo Database connection
 * @param string $key Unique identifier (same as in rate_limit_check)
 * @param int $windowSeconds Time window in seconds (default: 300 = 5 minutes)
 * @return void
 * 
 * @security Uses INSERT ... ON DUPLICATE KEY UPDATE for atomic operation
 * @security Expiration time is extended only if current record is not expired
 */
function rate_limit_hit(PDO $pdo, string $key, int $windowSeconds = 300): void {
    $expires = date('Y-m-d H:i:s', time() + $windowSeconds);
    $pdo->prepare("
        INSERT INTO rate_limits (`key`, attempts, expires_at)
        VALUES (?, 1, ?)
        ON DUPLICATE KEY UPDATE
            attempts   = attempts + 1,
            expires_at = IF(expires_at < NOW(), VALUES(expires_at), expires_at)
    ")->execute([$key, $expires]);
}

/**
 * Reset rate limit for a specific key
 * 
 * Call this function after a successful action (e.g., successful login)
 * to clear the rate limit counter and allow new attempts.
 * 
 * @param PDO $pdo Database connection
 * @param string $key Unique identifier to reset
 * @return void
 */
function rate_limit_reset(PDO $pdo, string $key): void {
    $pdo->prepare("DELETE FROM rate_limits WHERE `key` = ?")->execute([$key]);
}

// ============================================================================
// CLIENT IP DETECTION
// ============================================================================

/**
 * Get real client IP address, respecting proxy headers
 * 
 * This function checks common proxy headers in order of reliability:
 *   1. HTTP_CF_CONNECTING_IP - CloudFlare's connecting IP
 *   2. HTTP_X_FORWARDED_FOR - Standard proxy forwarded IP
 *   3. REMOTE_ADDR - Direct connection IP (fallback)
 * 
 * @return string Client IP address, or '0.0.0.0' if detection fails
 * 
 * @security Handles comma-separated lists (takes first IP)
 * @security Returns '0.0.0.0' as fallback (never returns null)
 * @note IP addresses are later hashed with salt before storage
 */
function get_client_ip(): string {
    // Check proxy headers in order of reliability
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            // If multiple IPs are present (X-Forwarded-For), take the first one
            return trim(explode(',', $_SERVER[$k])[0]);
        }
    }
    // Fallback (should never happen in normal conditions)
    return '0.0.0.0';
}