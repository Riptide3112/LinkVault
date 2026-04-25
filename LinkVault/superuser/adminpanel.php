<?php
/**
 * LinkVault - Admin Console (adminpanel.php)
 */
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';

if (!isset($_SESSION['user_id'])) { 
    header('Location: ../pages/login.php'); 
    exit; 
}

$stmt = $pdo->prepare("SELECT is_admin FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user || (int)$user['is_admin'] !== 1) { 
    header('Location: ../index.php'); 
    exit; 
}

$pageTitle = 'Admin Console';
require_once __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="admin.css?v=<?= time(); ?>">

<div class="admin-container">
    <div class="admin-header">
        <h1>Admin Console</h1>
        <p class="muted">Global system monitoring and user management.</p>
    </div>

    <div class="admin-tabs">
        <button class="admin-tab active" data-target="overview">Overview</button>
        <button class="admin-tab" data-target="users">User Management</button>
        <button class="admin-tab" data-target="newest">Newest Links</button>
    </div>

    <!-- Overview Section -->
    <div id="section-overview" class="admin-section">
        <div class="stats-grid">
            <div class="stat-card"><span class="stat-number" id="total-users">0</span><span class="stat-label">Total Users</span></div>
            <div class="stat-card"><span class="stat-number" id="total-links">0</span><span class="stat-label">Total Links</span></div>
            <div class="stat-card"><span class="stat-number" id="total-clicks">0</span><span class="stat-label">Total Clicks</span></div>
            <div class="stat-card"><span class="stat-number" id="active-links">0</span><span class="stat-label">Active Links</span></div>
        </div>
        <div class="chart-container-main">
            <h3 class="chart-title">Platform Activity (Last 30 Days)</h3>
            <div class="chart-wrapper"><canvas id="adminActivityChart"></canvas></div>
        </div>
    </div>

    <!-- Users Section -->
    <div id="section-users" class="admin-section" style="display: none;">
        <div class="search-bar">
            <input type="text" id="users-search-input" placeholder="Search by username or email..." autocomplete="off">
            <button class="search-icon-btn" id="users-search-btn">🔍</button>
        </div>
        <div class="data-table-container">
            <table class="data-table">
                <thead><tr><th>User</th><th>Joined</th><th>Links</th><th style="text-align: right;">Actions</th></tr></thead>
                <tbody id="users-list"><tr><td colspan="4" class="empty-state">Loading users...<\/td><\/tr></tbody>
            </table>
        </div>
        <div id="users-pagination" class="pagination-container"></div>
    </div>

    <!-- Newest Links Section -->
    <div id="section-newest" class="admin-section" style="display: none;">
        <div class="search-bar">
            <input type="text" id="links-search-input" placeholder="Search by short code, URL or username..." autocomplete="off">
            <button class="search-icon-btn" id="links-search-btn">🔍</button>
        </div>
        <div class="filter-group">
            <button class="filter-btn active" data-status="all">All</button>
            <button class="filter-btn" data-status="active">Active</button>
            <button class="filter-btn" data-status="expired">Expired</button>
        </div>
        <div class="newest-links-container">
            <div class="newest-links-list" id="newest-links-list"><div class="empty-state">Loading links...</div></div>
            <div class="newest-link-preview" id="newest-link-preview" style="display: none;">
                <div class="preview-header">
                    <h4>Link Preview</h4>
                    <div><span class="preview-url" id="preview-url">-</span><button class="preview-close" id="preview-close-btn">Close</button></div>
                </div>
                <div class="preview-frame" id="preview-frame"><div class="preview-loading">Select a link to preview</div></div>
            </div>
        </div>
    </div>
</div>

<!-- User Modal -->
<div id="userModal" class="modal-overlay">
    <div class="modal-card user-details-wide">
        <button class="modal-close" onclick="closeUserModal()">&times;</button>
        <div class="user-modal-header">
            <div class="user-title-section"><h2 id="modal-username">User Profile</h2><button class="action-btn danger-hover delete-account-btn" id="delete-user-btn">Delete Account</button></div>
            <span class="badge" id="modal-user-id"></span>
        </div>
        <div class="user-modal-content">
            <div class="user-modal-left">
                <div class="info-strip">
                    <div class="info-box"><label>Email</label><span id="modal-email">-</span></div>
                    <div class="info-box"><label>Member Since</label><span id="modal-joined">-</span></div>
                    <div class="info-box"><label>Created Links</label><span id="modal-link-count">0</span></div>
                    <div class="info-box"><label>Total Clicks</label><span id="modal-total-clicks">0</span></div>
                </div>
                <div class="user-chart-container"><canvas id="userActivityChart"></canvas></div>
            </div>
            <div class="user-modal-right">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
                    <h3>Managed Links</h3>
                    <button class="mini-search-icon" id="search-user-links-btn" title="Search links">🔍</button>
                </div>
                <div id="user-links-list" class="mini-links-list"><div class="empty-state">Select a user to view their links</div></div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="admin.js?v=<?= time(); ?>"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>