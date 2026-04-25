<?php
/**
 * config.php - LinkVault Configuration File
 * 
 * This file contains all global configuration constants for the application.
 * It defines database connection settings, application URLs, security salts,
 * and other system-wide parameters.
 * 
 * Security Best Practices:
 *   - Never commit this file with real credentials to version control
 *   - Use environment variables for production (getenv() or $_ENV)
 *   - Generate strong random salts for IP hashing
 *   - Keep BASE_URL consistent with your actual domain
 * 
 * @package LinkVault
 * @subpackage Configuration
 * @location /includes/config.php
 * @warning Modify this file before deploying to production
 */

// ============================================================================
// APPLICATION SETTINGS
// ============================================================================

/**
 * Application Name
 * Used in page titles, email subjects, and UI headers
 * 
 * @var string
 */
define('APP_NAME', 'LinkVault');

// ============================================================================
// DATABASE CONNECTION SETTINGS
// ============================================================================

/**
 * Database Host
 * Usually 'localhost' for local development
 * For production, this may be an IP address or domain name
 * 
 * @var string
 * @security Use localhost or internal IP - never expose database to public internet
 */
define('DB_HOST', 'localhost');

/**
 * Database Name
 * The name of the MySQL/MariaDB database used by LinkVault
 * 
 * @var string
 */
define('DB_NAME', 'linkvault');

/**
 * Database Username
 * User account with appropriate privileges (SELECT, INSERT, UPDATE, DELETE)
 * 
 * @var string
 * @security Use a dedicated database user with minimal required privileges
 * @security Never use root account for application database connections
 */
define('DB_USER', 'linkvault');

/**
 * Database Password
 * Strong password for the database user
 * 
 * @var string
 * @security For production, use environment variables instead of hardcoding
 * @example define('DB_PASS', getenv('DB_PASSWORD') ?: 'default_password');
 */
define('DB_PASS', 'linkvault123');

// ============================================================================
// URL CONFIGURATION
// ============================================================================

/**
 * Base URL of the application
 * Used for generating absolute URLs (short links, API endpoints, redirects)
 * 
 * Important: No trailing slash at the end
 * 
 * @var string
 * @example 'https://yourdomain.com/LinkVault' for production
 * @warning Changing this after links are created will break existing short URLs
 */
define('BASE_URL', 'http://localhost/LinkVault');

// ============================================================================
// LINK GENERATION SETTINGS
// ============================================================================

/**
 * Short Code Length
 * Determines how many characters the generated short code will have
 * 
 * Formula for possible combinations: 62^SHORT_CODE_LENGTH
 *   - Length 6: ~56 billion combinations (more than enough for most use cases)
 *   - Length 8: ~218 trillion combinations
 * 
 * @var int
 * @note Longer codes = more unique combinations but longer URLs
 */
define('SHORT_CODE_LENGTH', 6);

// ============================================================================
// SECURITY - IP HASHING SALT
// ============================================================================

/**
 * IP Salt for Hash-based IP Anonymization
 * 
 * This salt is used when hashing IP addresses for the link_clicks table.
 * The actual formula is: hash('sha256', $ip . IP_SALT)
 * 
 * Why this is important:
 *   - Raw IP addresses are NEVER stored in the database
 *   - Hashed IPs can still be used to count unique visitors per link
 *   - Without the salt, the IP hash cannot be reversed
 *   - With the salt, the hash is unique to your installation
 * 
 * Security Recommendations:
 *   1. Generate a strong random value (at least 32 bytes)
 *   2. Keep this value secret (never commit to public repositories)
 *   3. Use different salts for different environments
 *   4. Changing this salt invalidates all existing IP hashes
 * 
 * How to generate a strong salt (run in terminal):
 *   php -r "echo bin2hex(random_bytes(32));"
 * 
 * Example output: 
 *   "d4f8e2c1a3b5e7f9c2a4d6b8e1f3a5c7d8e2f4a6b8c1d3e5f7a9c2b4d6e8f1a3"
 * 
 * @var string
 * @security CRITICAL - Change this from the default value before production!
 * @warning The default value 'LINKVAULTZZZZ' is NOT secure for production
 * 
 * @todo Generate a unique random salt for production deployment
 */
define('IP_SALT', 'LINKVAULTZZZZ');

// ============================================================================
// PRODUCTION RECOMMENDATIONS - Environment Variables
// ============================================================================

/**
 * For production environments, it's strongly recommended to use environment
 * variables instead of hardcoded credentials. Example implementation:
 * 
 * ```php
 * define('DB_HOST', getenv('LINKVAULT_DB_HOST') ?: 'localhost');
 * define('DB_NAME', getenv('LINKVAULT_DB_NAME') ?: 'linkvault');
 * define('DB_USER', getenv('LINKVAULT_DB_USER') ?: 'linkvault');
 * define('DB_PASS', getenv('LINKVAULT_DB_PASS') ?: '');
 * define('BASE_URL', getenv('LINKVAULT_BASE_URL') ?: 'http://localhost/LinkVault');
 * define('IP_SALT', getenv('LINKVAULT_IP_SALT') ?: '');
 * ```
 * 
 * Then set environment variables in your Apache/Nginx configuration or .env file:
 *   SetEnv LINKVAULT_DB_PASS "your-secure-password"
 *   SetEnv LINKVAULT_IP_SALT "your-random-salt-here"
 */

// ============================================================================
// VALIDATION - Ensure Critical Constants Are Set
// ============================================================================

/**
 * Validate that required configuration is present
 * This helps catch missing configuration early
 */
if (!defined('IP_SALT') || IP_SALT === '' || IP_SALT === 'LINKVAULTZZZZ') {
    // Log warning but don't crash - better to have a weak salt than no salt
    error_log('WARNING: LinkVault is using default IP_SALT. Generate a strong random salt for production!');
}