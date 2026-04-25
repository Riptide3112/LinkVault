<?php
session_start();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/admin_auth.php';

$pageTitle = 'Admin Dashboard';
$adminTab = $_GET['admin_tab'] ?? 'overview';
require_once __DIR__ . '/../../includes/header.php';
?>

<style>
.admin-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 32px;
}

.admin-stat-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 20px;
    transition: all var(--transition);
}

.admin-stat-card:hover {
    border-color: var(--border2);
    transform: translateY(-2px);
}

.admin-stat-number {
    font-family: var(--sans);
    font-size: 2rem;
    font-weight: 800;
    color: var(--accent);
    line-height: 1.2;
}

.admin-stat-label {
    font-size: 0.68rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--muted);
    margin-top: 5px;
}

.admin-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 28px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 6px;
}

.admin-tab {
    padding: 10px 20px;
    border-radius: var(--radius);
    font-family: var(--mono);
    font-size: 0.78rem;
    color: var(--muted);
    transition: all var(--transition);
    cursor: pointer;
    background: none;
    border: none;
}

.admin-tab:hover {
    color: var(--text2);
    background: var(--surface2);
}

.admin-tab.active {
    background: var(--accent-dim);
    color: var(--accent);
    border: 1px solid rgba(0,212,180,0.3);
}

.search-bar {
    margin-bottom: 20px;
    display: flex;
    gap: 10px;
}

.search-bar input {
    flex: 1;
    padding: 10px 14px;
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    color: var(--text);
    font-family: var(--mono);
    font-size: 0.85rem;
}

.search-bar input:focus {
    border-color: var(--accent);
    outline: none;
}

.user-table, .links-table {
    width: 100%;
    border-collapse: collapse;
}

.user-table th, .links-table th,
.user-table td, .links-table td {
    padding: 12px 10px;
    text-align: left;
    border-bottom: 1px solid var(--border);
    font-size: 0.8rem;
}

.user-table th, .links-table th {
    color: var(--muted);
    font-weight: 500;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    font-size: 0.68rem;
}

.badge-admin {
    background: var(--accent-dim);
    color: var(--accent);
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 0.65rem;
}

.badge-user {
    background: var(--surface3);
    color: var(--muted);
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 0.65rem;
}

.link-expired-badge {
    background: var(--red-dim);
    color: var(--red);
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 0.65rem;
}

.link-active-badge {
    background: rgba(0,212,180,0.12);
    color: var(--accent);
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 0.65rem;
}

.action-buttons {
    display: flex;
    gap: 6px;
}

.action-btn {
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 5px 10px;
    color: var(--muted);
    cursor: pointer;
    font-size: 0.7rem;
    transition: all var(--transition);
}

.action-btn:hover {
    border-color: var(--accent);
    color: var(--accent);
}

.action-btn.danger:hover {
    border-color: var(--red);
    color: var(--red);
}

.pagination {
    display: flex;
    justify-content: center;
    gap: 8px;
    margin-top: 24px;
}

.page-btn {
    padding: 8px 14px;
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    color: var(--muted);
    cursor: pointer;
    font-size: 0.75rem;
    transition: all var(--transition);
}

.page-btn:hover {
    border-color: var(--accent);
    color: var(--accent);
}

.page-btn.active {
    background: var(--accent-dim);
    border-color: rgba(0,212,180,0.4);
    color: var(--accent);
}

.chart-container {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 20px;
    margin-top: 20px;
}

.chart-container canvas {
    max-height: 300px;
}

.loading-spinner {
    text-align: center;
    padding: 40px;
    color: var(--muted);
}

.status-filter {
    display: flex;
    gap: 8px;
    margin-bottom: 16px;
}

.status-btn {
    padding: 5px 12px;
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: 20px;
    color: var(--muted);
    cursor: pointer;
    font-size: 0.7rem;
    transition: all var(--transition);
}

.status-btn.active {
    background: var(--accent-dim);
    border-color: rgba(0,212,180,0.4);
    color: var(--accent);
}

.short-code {
    font-family: var(--mono);
    color: var(--accent);
    font-weight: 500;
}

.original-url {
    max-width: 250px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

@media (max-width: 768px) {
    .admin-stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .user-table, .links-table {
        display: block;
        overflow-x: auto;
    }
    
    .original-url {
        max-width: 150px;
    }
}
</style>

<div class="admin-tabs">
    <button class="admin-tab <?= $adminTab === 'overview' ? 'active' : '' ?>" data-tab="overview">[O] Overview</button>
    <button class="admin-tab <?= $adminTab === 'users' ? 'active' : '' ?>" data-tab="users">[U] Users</button>
    <button class="admin-tab <?= $adminTab === 'links' ? 'active' : '' ?>" data-tab="links">[L] Links</button>
</div>

<!-- Overview Tab -->
<div id="tab-overview" class="admin-tab-content" style="display: <?= $adminTab === 'overview' ? 'block' : 'none' ?>">
    <div class="admin-stats-grid" id="stats-grid">
        <div class="admin-stat-card">
            <div class="admin-stat-number" id="stat-total-users">-</div>
            <div class="admin-stat-label">Total Users</div>
        </div>
        <div class="admin-stat-card">
            <div class="admin-stat-number" id="stat-new-users">-</div>
            <div class="admin-stat-label">New Users (30d)</div>
        </div>
        <div class="admin-stat-card">
            <div class="admin-stat-number" id="stat-total-links">-</div>
            <div class="admin-stat-label">Total Links</div>
        </div>
        <div class="admin-stat-card">
            <div class="admin-stat-number" id="stat-active-links">-</div>
            <div class="admin-stat-label">Active Links</div>
        </div>
        <div class="admin-stat-card">
            <div class="admin-stat-number" id="stat-expired-links">-</div>
            <div class="admin-stat-label">Expired Links</div>
        </div>
        <div class="admin-stat-card">
            <div class="admin-stat-number" id="stat-total-clicks">-</div>
            <div class="admin-stat-label">Total Clicks</div>
        </div>
        <div class="admin-stat-card">
            <div class="admin-stat-number" id="stat-unique-visitors">-</div>
            <div class="admin-stat-label">Unique Visitors</div>
        </div>
    </div>
    
    <div class="chart-container">
        <h3 class="dash-section-title">Clicks over time (last 30 days)</h3>
        <canvas id="admin-chart"></canvas>
    </div>
</div>

<!-- Users Tab -->
<div id="tab-users" class="admin-tab-content" style="display: <?= $adminTab === 'users' ? 'block' : 'none' ?>">
    <div class="search-bar">
        <input type="text" id="user-search" placeholder="Search by username or email..." autocomplete="off">
        <button class="btn-sm" id="search-users-btn">Search</button>
    </div>
    
    <div id="users-loading" class="loading-spinner" style="display: none;">
        <div class="stats-spinner"></div>
        <p>Loading users...</p>
    </div>
    
    <div id="users-container">
        <table class="user-table">
            <thead>
                <tr><th>ID</th><th>Username</th><th>Email</th><th>Role</th><th>Joined</th><th>Actions</th></tr>
            </thead>
            <tbody id="users-tbody"></tbody>
        </table>
    </div>
    
    <div id="users-pagination" class="pagination"></div>
</div>

<!-- Links Tab -->
<div id="tab-links" class="admin-tab-content" style="display: <?= $adminTab === 'links' ? 'block' : 'none' ?>">
    <div class="search-bar">
        <input type="text" id="link-search" placeholder="Search by code, URL or user..." autocomplete="off">
        <button class="btn-sm" id="search-links-btn">Search</button>
    </div>
    
    <div class="status-filter">
        <button class="status-btn active" data-status="all">All</button>
        <button class="status-btn" data-status="active">Active</button>
        <button class="status-btn" data-status="expired">Expired</button>
    </div>
    
    <div id="links-loading" class="loading-spinner" style="display: none;">
        <div class="stats-spinner"></div>
        <p>Loading links...</p>
    </div>
    
    <div id="links-container">
        <table class="links-table">
            <thead>
                <tr><th>Short URL</th><th>Original URL</th><th>User</th><th>Clicks</th><th>Status</th><th>Created</th><th>Actions</th></tr>
            </thead>
            <tbody id="links-tbody"></tbody>
        </table>
    </div>
    
    <div id="links-pagination" class="pagination"></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
let adminChart = null;
let currentUserPage = 1;
let currentLinkPage = 1;
let currentLinkStatus = 'all';
let currentUserSearch = '';
let currentLinkSearch = '';

// Tab switching
document.querySelectorAll('.admin-tab').forEach(btn => {
    btn.addEventListener('click', () => {
        const tab = btn.dataset.tab;
        document.querySelectorAll('.admin-tab').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        document.querySelectorAll('.admin-tab-content').forEach(content => content.style.display = 'none');
        document.getElementById(`tab-${tab}`).style.display = 'block';
        
        if (tab === 'overview') {
            loadAdminStats();
        } else if (tab === 'users') {
            loadUsers();
        } else if (tab === 'links') {
            loadLinks();
        }
    });
});

// Load overview stats
async function loadAdminStats() {
    try {
        const res = await fetch('/LinkVault/api/admin_stats.php?period=30d');
        const data = await res.json();
        
        if (data.success) {
            document.getElementById('stat-total-users').textContent = data.stats.total_users.toLocaleString();
            document.getElementById('stat-new-users').textContent = data.stats.new_users.toLocaleString();
            document.getElementById('stat-total-links').textContent = data.stats.total_links.toLocaleString();
            document.getElementById('stat-active-links').textContent = data.stats.active_links.toLocaleString();
            document.getElementById('stat-expired-links').textContent = data.stats.expired_links.toLocaleString();
            document.getElementById('stat-total-clicks').textContent = data.stats.total_clicks.toLocaleString();
            document.getElementById('stat-unique-visitors').textContent = data.stats.unique_visitors.toLocaleString();
            
            renderAdminChart(data.chart);
        }
    } catch (e) {
        console.error('Failed to load stats:', e);
    }
}

function renderAdminChart(chart) {
    const ctx = document.getElementById('admin-chart').getContext('2d');
    if (adminChart) adminChart.destroy();
    
    adminChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: chart.labels.map(d => {
                const dt = new Date(d);
                return dt.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
            }),
            datasets: [{
                label: 'Clicks',
                data: chart.data,
                borderColor: '#00D4B4',
                backgroundColor: 'rgba(0,212,180,0.08)',
                borderWidth: 2,
                pointBackgroundColor: '#00D4B4',
                pointRadius: 2,
                pointHoverRadius: 5,
                fill: true,
                tension: 0.4,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { color: '#27272A' }, ticks: { color: '#71717A', font: { size: 10 } } },
                y: { grid: { color: '#27272A' }, ticks: { color: '#71717A', font: { size: 10 }, stepSize: 1 }, beginAtZero: true }
            }
        }
    });
}

// Load users
async function loadUsers() {
    const loading = document.getElementById('users-loading');
    const container = document.getElementById('users-container');
    loading.style.display = 'flex';
    container.style.opacity = '0.5';
    
    try {
        const url = `/LinkVault/api/admin_users.php?action=list&page=${currentUserPage}&search=${encodeURIComponent(currentUserSearch)}`;
        const res = await fetch(url);
        const data = await res.json();
        
        if (data.success) {
            renderUsers(data.users);
            renderPagination('users-pagination', data.page, Math.ceil(data.total / data.limit), (page) => {
                currentUserPage = page;
                loadUsers();
            });
        }
    } catch (e) {
        console.error('Failed to load users:', e);
    } finally {
        loading.style.display = 'none';
        container.style.opacity = '1';
    }
}

function renderUsers(users) {
    const tbody = document.getElementById('users-tbody');
    if (!users.length) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:40px">No users found.</td></tr>';
        return;
    }
    
    tbody.innerHTML = users.map(user => `
        <tr>
            <td>${user.id}</td>
            <td>${escapeHtml(user.username)}</td>
            <td>${escapeHtml(user.email)}</td>
            <td><span class="${user.is_admin ? 'badge-admin' : 'badge-user'}">${user.is_admin ? 'Admin' : 'User'}</span></td>
            <td>${new Date(user.created_at).toLocaleDateString()}</td>
            <td class="action-buttons">
                <button class="action-btn" onclick="toggleAdmin(${user.id}, ${user.is_admin})">${user.is_admin ? '[R] Remove Admin' : '[A] Make Admin'}</button>
                <button class="action-btn danger" onclick="deleteUser(${user.id})">[X] Delete</button>
            </td>
        </tr>
    `).join('');
}

async function toggleAdmin(userId, isAdmin) {
    if (!confirm(isAdmin ? 'Remove admin privileges from this user?' : 'Make this user an admin?')) return;
    
    try {
        const res = await fetch(`/LinkVault/api/admin_users.php?action=toggle_admin&id=${userId}`);
        const data = await res.json();
        if (data.success) {
            showToast(isAdmin ? 'Admin rights removed.' : 'User is now an admin.', 'success');
            loadUsers();
        } else {
            showToast(data.error || 'Failed to update user.', 'error');
        }
    } catch (e) {
        showToast('Network error.', 'error');
    }
}

async function deleteUser(userId) {
    if (!confirm('Permanently delete this user and all their links? This cannot be undone.')) return;
    
    try {
        const res = await fetch(`/LinkVault/api/admin_users.php?action=delete&id=${userId}`);
        const data = await res.json();
        if (data.success) {
            showToast('User deleted successfully.', 'success');
            loadUsers();
        } else {
            showToast(data.error || 'Failed to delete user.', 'error');
        }
    } catch (e) {
        showToast('Network error.', 'error');
    }
}

// Load links
async function loadLinks() {
    const loading = document.getElementById('links-loading');
    const container = document.getElementById('links-container');
    loading.style.display = 'flex';
    container.style.opacity = '0.5';
    
    try {
        const url = `/LinkVault/api/admin_links.php?action=list&page=${currentLinkPage}&status=${currentLinkStatus}&search=${encodeURIComponent(currentLinkSearch)}`;
        const res = await fetch(url);
        const data = await res.json();
        
        if (data.success) {
            renderLinks(data.links);
            renderPagination('links-pagination', data.page, Math.ceil(data.total / data.limit), (page) => {
                currentLinkPage = page;
                loadLinks();
            });
        }
    } catch (e) {
        console.error('Failed to load links:', e);
    } finally {
        loading.style.display = 'none';
        container.style.opacity = '1';
    }
}

function renderLinks(links) {
    const tbody = document.getElementById('links-tbody');
    if (!links.length) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:40px">No links found.</td></tr>';
        return;
    }
    
    tbody.innerHTML = links.map(link => `
        <tr>
            <td><a href="${escapeHtml(link.short_url)}" target="_blank" class="short-code">${escapeHtml(link.short_code)}</a></td>
            <td class="original-url" title="${escapeHtml(link.original_url)}">${escapeHtml(link.original_url.substring(0, 60))}${link.original_url.length > 60 ? '...' : ''}</td>
            <td>${escapeHtml(link.display_user)}</td>
            <td>${link.clicks.toLocaleString()}</td>
            <td><span class="${link.is_expired ? 'link-expired-badge' : 'link-active-badge'}">${link.is_expired ? 'Expired' : 'Active'}</span></td>
            <td>${new Date(link.created_at).toLocaleDateString()}</td>
            <td><button class="action-btn danger" onclick="deleteLink(${link.id})">[X] Delete</button></td>
        </tr>
    `).join('');
}

async function deleteLink(linkId) {
    if (!confirm('Permanently delete this link and all its statistics? This cannot be undone.')) return;
    
    try {
        const res = await fetch(`/LinkVault/api/admin_links.php?action=delete&id=${linkId}`);
        const data = await res.json();
        if (data.success) {
            showToast('Link deleted successfully.', 'success');
            loadLinks();
        } else {
            showToast('Failed to delete link.', 'error');
        }
    } catch (e) {
        showToast('Network error.', 'error');
    }
}

function renderPagination(containerId, currentPage, totalPages, onPageChange) {
    const container = document.getElementById(containerId);
    if (!container) return;
    
    if (totalPages <= 1) {
        container.innerHTML = '';
        return;
    }
    
    let html = '';
    for (let i = 1; i <= Math.min(totalPages, 10); i++) {
        html += `<button class="page-btn ${i === currentPage ? 'active' : ''}" data-page="${i}">${i}</button>`;
    }
    container.innerHTML = html;
    
    container.querySelectorAll('.page-btn').forEach(btn => {
        btn.addEventListener('click', () => onPageChange(parseInt(btn.dataset.page)));
    });
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}

// Search handlers
document.getElementById('search-users-btn')?.addEventListener('click', () => {
    currentUserSearch = document.getElementById('user-search').value;
    currentUserPage = 1;
    loadUsers();
});

document.getElementById('user-search')?.addEventListener('keypress', (e) => {
    if (e.key === 'Enter') {
        currentUserSearch = e.target.value;
        currentUserPage = 1;
        loadUsers();
    }
});

document.getElementById('search-links-btn')?.addEventListener('click', () => {
    currentLinkSearch = document.getElementById('link-search').value;
    currentLinkPage = 1;
    loadLinks();
});

document.getElementById('link-search')?.addEventListener('keypress', (e) => {
    if (e.key === 'Enter') {
        currentLinkSearch = e.target.value;
        currentLinkPage = 1;
        loadLinks();
    }
});

document.querySelectorAll('.status-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.status-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        currentLinkStatus = btn.dataset.status;
        currentLinkPage = 1;
        loadLinks();
    });
});

// Initial load
if (document.getElementById('tab-overview').style.display !== 'none') loadAdminStats();
if (document.getElementById('tab-users').style.display !== 'none') loadUsers();
if (document.getElementById('tab-links').style.display !== 'none') loadLinks();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>