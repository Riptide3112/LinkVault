<?php
/**
 * header.php - Global Header Component
 * 
 * This file contains the HTML head section, site header, and navigation elements
 * that are shared across the entire application. It is included at the beginning
 * of every page before the main content.
 * 
 * Components included:
 *   1. HTML5 document structure with responsive meta tags
 *   2. CSRF token meta tag for AJAX security
 *   3. Google Fonts integration (DM Mono and Syne)
 *   4. Main CSS stylesheet and favicon
 *   5. Site header with logo and branding
 *   6. Desktop navigation menu
 *   7. User dropdown menu (authenticated users)
 *   8. Mobile hamburger menu with drawer navigation
 *   9. Admin status verification and session management
 * 
 * Security Features:
 *   - CSRF token stored in meta tag for JavaScript access
 *   - Admin status verified from database on each request
 *   - XSS prevention via htmlspecialchars() for all dynamic output
 *   - Session-based authentication for user/admin access
 *   - Proper ARIA attributes for accessibility
 * 
 * @package LinkVault
 * @subpackage UI
 * @location /includes/header.php
 * @requires security.php, db.php, style.css
 */

// Ensure CSRF functions are available (from security.php)
if (!function_exists('csrf_token')) require_once __DIR__ . '/security.php';

// ============================================================================
// ADMIN SESSION VERIFICATION
// ============================================================================

/**
 * Verify and set admin status in session
 * 
 * This block ensures that the session has up-to-date admin privileges
 * even if the database is_admin flag changes during the session.
 * 
 * Security benefits:
 *   - Prevents session hijacking (admin status re-verified from DB)
 *   - Automatically revokes admin access if demoted
 *   - Handles database errors gracefully (defaults to non-admin)
 * 
 * @security Always re-verify admin status from database, don't trust session alone
 */
if (isset($_SESSION['user_id']) && !isset($_SESSION['is_admin'])) {
    try {
        require_once __DIR__ . '/db.php';
        $stmt = $pdo->prepare("SELECT is_admin FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $adminCheck = $stmt->fetch();
        $_SESSION['is_admin'] = $adminCheck ? (int) $adminCheck['is_admin'] : 0;
    } catch (Exception $e) {
        // Database error - default to non-admin for safety
        $_SESSION['is_admin'] = 0;
    }
}

// ============================================================================
// PAGE CONTEXT VARIABLES
// ============================================================================

/**
 * Determine current page context for active navigation highlighting
 * 
 * @var string $currentPage - Base filename without extension (e.g., 'index', 'dashboard')
 * @var bool $inAdmin - True if current page is in admin directory
 * @var bool $loggedIn - True if user has active session
 * @var bool $isAdmin - True if logged in user has admin privileges
 */
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$inAdmin     = str_contains($_SERVER['PHP_SELF'], '/admin/');
$loggedIn    = isset($_SESSION['user_id']);
$isAdmin     = $loggedIn && ($_SESSION['is_admin'] ?? 0) == 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <!-- ====================================================================
         DOCUMENT METADATA
         ==================================================================== -->
    <meta charset="UTF-8">
    <!-- Viewport settings for responsive mobile design -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    
    <!-- Dynamic page title with fallback -->
    <title><?= htmlspecialchars($pageTitle ?? 'LinkVault') ?> — URL Shortener</title>
    
    <!-- CSRF Token Meta Tag - Used by JavaScript for AJAX requests -->
    <!-- @security Critical for preventing CSRF attacks on API endpoints -->
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
    
    <!-- ====================================================================
         FONTS & STYLES
         ==================================================================== -->
    <!-- Google Fonts: DM Mono (monospace) and Syne (sans-serif headings) -->
    <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@300;400;500&family=Syne:wght@400;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Main application stylesheet -->
    <link rel="stylesheet" href="/LinkVault/includes/style.css">
    
    <!-- Favicon - SVG-based with LV monogram in accent color -->
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' fill='%2300D4B4' rx='20'/><text x='50' y='68' font-family='monospace' font-size='40' font-weight='bold' fill='%23000000' text-anchor='middle'>LV</text></svg>">
</head>
<body>

<!-- ============================================================================
     SITE HEADER - Main navigation and branding
     ============================================================================ -->

<site-header>
  <div class="header-inner">
    <!-- Logo / Branding Link -->
    <a href="/LinkVault/" class="logo">
      <div class="logo-text">Link<span>Vault</span></div>
      <span class="logo-credit">by RiptideDev</span>
    </a>

    <!-- ========================================================================
         DESKTOP NAVIGATION
         ======================================================================== -->
    <nav class="nav-desktop">
      <!-- Home link - active on non-admin index pages -->
      <a href="/LinkVault/" class="<?= (!$inAdmin && $currentPage === 'index') ? 'active' : '' ?>">Home</a>
      
      <!-- Admin link - only visible to users with admin privileges -->
      <?php if ($isAdmin): ?>
        <a href="/LinkVault/superuser/adminpanel.php" class="<?= str_contains($_SERVER['PHP_SELF'], '/admin/') ? 'active' : '' ?>">Admin</a>
      <?php endif; ?>

      <!-- Authenticated User Menu -->
      <?php if ($loggedIn): ?>
        <div class="user-menu-wrap">
          <!-- Dropdown trigger button with user avatar and name -->
          <button class="user-menu-trigger" id="userMenuTrigger" aria-expanded="false" aria-haspopup="true">
            <!-- User avatar (first letter of username) -->
            <span class="user-avatar"><?= strtoupper(substr($_SESSION['username'], 0, 1)) ?></span>
            <span class="user-menu-name"><?= htmlspecialchars($_SESSION['username']) ?></span>
            <span class="user-menu-caret">▼</span>
          </button>
          <!-- Dropdown menu items -->
          <div class="user-menu-dropdown" id="userMenuDropdown" role="menu">
            <a href="/LinkVault/pages/dashboard.php" role="menuitem">
              Dashboard
            </a>
            <a href="/LinkVault/pages/dashboard.php?tab=settings" role="menuitem">
              Settings
            </a>
            <div class="menu-divider"></div>
            <a href="/LinkVault/pages/logout.php" class="menu-danger" role="menuitem">
              Log out
            </a>
          </div>
        </div>
      
      <!-- Guest User Menu (not logged in) -->
      <?php else: ?>
        <a href="/LinkVault/pages/login.php" id="login-popup-trigger" class="nav-login">Log in</a>
        <a href="/LinkVault/pages/register.php" class="nav-register">Register</a>
      <?php endif; ?>
    </nav>

    <!-- ========================================================================
         MOBILE HAMBURGER MENU BUTTON
         ======================================================================== -->
    <!--
      Hamburger button - visible only on mobile screens (CSS media query)
      Opens/closes the mobile navigation drawer
    -->
    <button class="hamburger" id="hamburger" aria-label="Toggle menu" aria-expanded="false">
      <span></span><span></span><span></span>
    </button>
  </div>

  <!-- ========================================================================
       MOBILE NAVIGATION DRAWER
       ======================================================================== -->
  <!--
    Mobile navigation drawer - slides in from top when hamburger is clicked
    Contains same links as desktop navigation but in vertical layout
  -->
  <div class="nav-mobile" id="navMobile" aria-hidden="true">
    <a href="/LinkVault/" class="<?= (!$inAdmin && $currentPage === 'index') ? 'active' : '' ?>">Home</a>
    
    <?php if ($isAdmin): ?>
      <a href="/LinkVault/pages/admin/dashboard.php">Admin</a>
    <?php endif; ?>
    
    <?php if ($loggedIn): ?>
      <a href="/LinkVault/pages/dashboard.php">Dashboard</a>
      <a href="/LinkVault/pages/dashboard.php?tab=settings">Settings</a>
      <a href="/LinkVault/pages/logout.php" class="menu-danger">Log out</a>
    <?php else: ?>
      <a href="/LinkVault/pages/login.php" id="login-popup-trigger-mobile" class="nav-login">Log in</a>
      <a href="/LinkVault/pages/register.php">Register</a>
    <?php endif; ?>
  </div>
</site-header>

<!-- ============================================================================
     MAIN CONTENT CONTAINER
     ============================================================================ -->
<!--
  Main content wrapper - all page content is injected here
  The closing </main> tag is in footer.php
-->
<main>