/**
 * LinkVault — Admin Console Logic
 */

const API_BASE = '../api/';
const BASE_URL = window.location.origin + '/LinkVault';
let globalChart = null;
let userChart = null;
let currentPage = 1;
let currentUserSearch = '';
let currentLinkSearch = '';
let currentLinkStatus = 'all';
let currentUserModalId = null;
let allUsersData = [];
let allLinksData = [];

const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

function getCsrfHeaders() {
    return { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken };
}

document.addEventListener('DOMContentLoaded', () => {
    const tabs = document.querySelectorAll('.admin-tab');
    const sections = document.querySelectorAll('.admin-section');

    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            const target = tab.dataset.target;
            tabs.forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            sections.forEach(s => s.style.display = 'none');
            const sec = document.getElementById('section-' + target);
            if (sec) sec.style.display = 'block';
            if (target === 'overview') {
                loadStats();
            } else if (target === 'users') {
                loadUsersData();
            } else if (target === 'newest') {
                loadLinksData();
            }
        });
    });

    window.addEventListener('click', e => {
        if (e.target === document.getElementById('userModal')) closeUserModal();
        if (e.target === document.getElementById('custom-confirm-overlay')) {
            const overlay = document.getElementById('custom-confirm-overlay');
            if (overlay) overlay.remove();
        }
    });

    const previewClose = document.getElementById('preview-close-btn');
    if (previewClose) {
        previewClose.addEventListener('click', () => {
            document.getElementById('newest-link-preview').style.display = 'none';
            document.querySelectorAll('.newest-link-item').forEach(i => i.classList.remove('selected'));
        });
    }

    // ========== SEARCH FUNCTIONALITY ==========
    
    // Users search - live filtering
    const usersSearchInput = document.getElementById('users-search-input');
    if (usersSearchInput) {
        usersSearchInput.addEventListener('input', (e) => {
            currentUserSearch = e.target.value.trim().toLowerCase();
            filterAndRenderUsers();
        });
    }

    // Links search - live filtering
    const linksSearchInput = document.getElementById('links-search-input');
    if (linksSearchInput) {
        linksSearchInput.addEventListener('input', (e) => {
            currentLinkSearch = e.target.value.trim().toLowerCase();
            filterAndRenderLinks();
        });
    }

    // User modal links search - live filtering
    const searchUserLinksBtn = document.getElementById('search-user-links-btn');
    if (searchUserLinksBtn) {
        searchUserLinksBtn.addEventListener('click', () => {
            const modalLinksContainer = document.getElementById('user-links-list');
            if (!modalLinksContainer || !modalLinksContainer._originalLinks) return;
            const searchInput = document.createElement('input');
            searchInput.type = 'text';
            searchInput.placeholder = '🔍 Search links...';
            searchInput.style.cssText = 'width:100%; padding:8px 12px; margin-bottom:12px; background:var(--surface2); border:1px solid var(--border); border-radius:8px; color:var(--text);';
            const existingSearch = modalLinksContainer.querySelector('.user-links-search');
            if (existingSearch) existingSearch.remove();
            searchInput.className = 'user-links-search';
            modalLinksContainer.insertBefore(searchInput, modalLinksContainer.firstChild);
            searchInput.focus();
            searchInput.addEventListener('input', (e) => {
                const query = e.target.value.trim().toLowerCase();
                if (query === '') {
                    renderUserLinksList(modalLinksContainer._originalLinks);
                } else {
                    const filtered = modalLinksContainer._originalLinks.filter(link => 
                        link.short_code.toLowerCase().includes(query) ||
                        link.original_url.toLowerCase().includes(query)
                    );
                    renderUserLinksList(filtered);
                    // Highlight first result
                    if (filtered.length > 0) {
                        const firstLink = document.getElementById(`link-row-${filtered[0].id}`);
                        if (firstLink) {
                            firstLink.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            firstLink.style.backgroundColor = 'var(--accent-dim)';
                            setTimeout(() => firstLink.style.backgroundColor = '', 1500);
                        }
                    }
                }
            });
        });
    }

    // Filter buttons for newest links
    document.querySelectorAll('.filter-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            currentLinkStatus = btn.dataset.status;
            loadLinksData();
        });
    });

    // Initial loads
    loadStats();
    loadUsersData();
    loadLinksData();
});

// ============================================================================
// OVERVIEW STATISTICS
// ============================================================================

async function loadStats() {
    try {
        const res = await fetch(`${API_BASE}admin_stats.php`);
        const data = await res.json();
        if (!data.success) return;
        document.getElementById('total-users').innerText = data.stats.total_users || 0;
        document.getElementById('total-links').innerText = data.stats.total_links || 0;
        document.getElementById('total-clicks').innerText = data.stats.total_clicks || 0;
        document.getElementById('active-links').innerText = data.stats.active_links || 0;
        if (data.chart) renderGlobalChart(data.chart);
    } catch (err) { console.error(err); }
}

function renderGlobalChart(chartData) {
    const ctx = document.getElementById('adminActivityChart');
    if (!ctx) return;
    if (globalChart) globalChart.destroy();
    globalChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: chartData.labels,
            datasets: [
                { label: 'Clicks', data: chartData.clicks, borderColor: '#00D4B4', backgroundColor: 'rgba(0,212,180,0.05)', fill: true, tension: 0.4, borderWidth: 2, pointRadius: 3 },
                { label: 'Links Created', data: chartData.links_created, borderColor: '#71717A', backgroundColor: 'transparent', borderDash: [5, 5], tension: 0.4, borderWidth: 2, pointRadius: 2 }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { position: 'top', labels: { color: '#71717A' } } },
            scales: { y: { beginAtZero: true, ticks: { color: '#71717A' }, grid: { color: 'rgba(255,255,255,0.05)' } }, x: { ticks: { color: '#71717A' }, grid: { display: false } } }
        }
    });
}

// ============================================================================
// USERS MANAGEMENT WITH LIVE SEARCH
// ============================================================================

async function loadUsersData() {
    const tbody = document.getElementById('users-list');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:30px;">Loading users...<\/td><\/tr>';
    try {
        const res = await fetch(`${API_BASE}admin_user.php?action=list&page=1&limit=100`);
        const data = await res.json();
        if (!data.success) { 
            tbody.innerHTML = `<td><td colspan="4" style="color:#EF4444;">${escapeHtml(data.error)}<\/td><\/tr>`; 
            return; 
        }
        if (!data.users || data.users.length === 0) { 
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;">No users found.<\/td><\/tr>'; 
            return; 
        }
        allUsersData = data.users;
        filterAndRenderUsers();
        renderPagination(data.total_pages || 1, data.current_page || 1);
    } catch (err) { 
        tbody.innerHTML = '<tr><td colspan="4" style="color:#EF4444;">Server error.<\/td><\/tr>';
        console.error(err);
    }
}

function filterAndRenderUsers() {
    const tbody = document.getElementById('users-list');
    if (!tbody) return;
    
    let filteredUsers = [...allUsersData];
    if (currentUserSearch) {
        filteredUsers = filteredUsers.filter(user => 
            user.username.toLowerCase().includes(currentUserSearch) ||
            user.email.toLowerCase().includes(currentUserSearch)
        );
    }
    
    if (filteredUsers.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;">No users matching "<strong>' + escapeHtml(currentUserSearch) + '</strong>" found.<\/td><\/tr>';
        return;
    }
    
    tbody.innerHTML = filteredUsers.map(user => {
        const usernameHighlighted = highlightText(user.username, currentUserSearch);
        const emailHighlighted = highlightText(user.email, currentUserSearch);
        return `
            <tr>
                <td><div style="display:flex; flex-direction:column;"><span style="font-weight:600;">${usernameHighlighted}</span><small class="muted">${emailHighlighted}</small></div><\/td>
                <td>${new Date(user.created_at).toLocaleDateString()}<\/td>
                <td><span class="badge">${user.link_count || 0} links</span><span class="muted" style="margin-left:8px;">${user.total_clicks || 0} clicks</span><\/td>
                <td style="text-align:right;"><button class="action-btn" onclick="openUserModal(${user.id})">View<\/button><\/td>
            <\/tr>
        `;
    }).join('');
}

function renderPagination(totalPages, currentPageNum) {
    const container = document.getElementById('users-pagination');
    if (!container) return;
    if (totalPages <= 1) { container.innerHTML = ''; return; }
    let html = '';
    for (let i = 1; i <= Math.min(totalPages, 10); i++) {
        html += `<button class="page-btn ${i === currentPageNum ? 'active' : ''}" onclick="loadUsersPage(${i})">${i}</button>`;
    }
    container.innerHTML = html;
}

window.loadUsersPage = function(page) {
    loadUsersData();
};

// ============================================================================
// NEWEST LINKS WITH LIVE SEARCH & FILTER
// ============================================================================

async function loadLinksData() {
    const container = document.getElementById('newest-links-list');
    if (!container) return;
    container.innerHTML = '<div class="empty-state">Loading links...</div>';
    document.getElementById('newest-link-preview').style.display = 'none';
    try {
        let url = `${API_BASE}admin_links.php?action=list&page=1&limit=100`;
        if (currentLinkStatus && currentLinkStatus !== 'all') url += `&status=${currentLinkStatus}`;
        const res = await fetch(url);
        const data = await res.json();
        if (!data.success) { 
            container.innerHTML = `<div class="empty-state" style="color:#EF4444;">Error: ${escapeHtml(data.error)}</div>`; 
            return; 
        }
        if (!data.links || data.links.length === 0) { 
            container.innerHTML = '<div class="empty-state">No links found.</div>'; 
            return; 
        }
        allLinksData = data.links;
        filterAndRenderLinks();
    } catch (e) { 
        container.innerHTML = `<div class="empty-state" style="color:#EF4444;">Error: ${e.message}</div>`;
        console.error(e);
    }
}

function filterAndRenderLinks() {
    const container = document.getElementById('newest-links-list');
    if (!container) return;
    
    let filteredLinks = [...allLinksData];
    
    // Apply search filter
    if (currentLinkSearch) {
        filteredLinks = filteredLinks.filter(link => 
            link.short_code.toLowerCase().includes(currentLinkSearch) ||
            link.original_url.toLowerCase().includes(currentLinkSearch) ||
            (link.display_user && link.display_user.toLowerCase().includes(currentLinkSearch))
        );
    }
    
    // Apply status filter (already applied from API, but filter again for safety)
    if (currentLinkStatus && currentLinkStatus !== 'all') {
        if (currentLinkStatus === 'active') {
            filteredLinks = filteredLinks.filter(link => !link.is_expired);
        } else if (currentLinkStatus === 'expired') {
            filteredLinks = filteredLinks.filter(link => link.is_expired);
        }
    }
    
    if (filteredLinks.length === 0) {
        container.innerHTML = '<div class="empty-state">No links matching your search.</div>';
        return;
    }
    
    renderLinksList(container, filteredLinks);
}

function renderLinksList(container, links) {
    container.innerHTML = links.map(link => {
        const isExpired = link.is_expired;
        const fullUrl = `${BASE_URL}/${link.short_code}`;
        const originalUrlShort = link.original_url_short || (link.original_url.substring(0, 60) + (link.original_url.length > 60 ? '...' : ''));
        const shortCodeHighlighted = highlightText(link.short_code, currentLinkSearch);
        const originalUrlHighlighted = highlightText(originalUrlShort, currentLinkSearch);
        const userHighlighted = highlightText(link.display_user || 'Anonymous', currentLinkSearch);
        
        return `<div class="newest-link-item" data-link-id="${link.id}" data-link-url="${escapeHtml(link.original_url)}" data-short-code="${escapeHtml(link.short_code)}">
            <div class="newest-link-header">
                <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                    <code class="newest-link-code">/${shortCodeHighlighted}</code>
                    ${isExpired ? '<span class="badge-expired">Expired</span>' : '<span class="badge-active">Active</span>'}
                </div>
                <div class="newest-link-actions">
                    <button class="action-btn-small" onclick="event.stopPropagation(); previewLink('${escapeHtml(link.short_code)}', '${escapeHtml(link.original_url)}')">Preview</button>
                    <button class="action-btn-small warning" onclick="event.stopPropagation(); suspendLink(${link.id})">Suspend</button>
                    <button class="action-btn-small danger" onclick="event.stopPropagation(); deleteLinkFromList(${link.id})">Delete</button>
                </div>
            </div>
            <div class="newest-link-url"><span>🔗</span><a href="${escapeHtml(fullUrl)}" target="_blank" onclick="event.stopPropagation();">${escapeHtml(fullUrl)}</a><span>→</span><span title="${escapeHtml(link.original_url)}">${originalUrlHighlighted}</span></div>
            <div class="newest-link-meta"><span>📊 ${link.clicks || 0} clicks</span><span>📅 ${link.created_formatted || new Date(link.created_at).toLocaleDateString()}</span>${link.expires_at ? `<span>⏱ Expires: ${new Date(link.expires_at).toLocaleDateString()}</span>` : '<span>∞ Never expires</span>'}<span>👤 by ${userHighlighted}</span></div>
        </div>`;
    }).join('');
    
    document.querySelectorAll('.newest-link-item').forEach(item => {
        item.addEventListener('click', (e) => {
            if (e.target.tagName === 'BUTTON' || e.target.tagName === 'A') return;
            previewLink(item.dataset.shortCode, item.dataset.linkUrl);
            document.querySelectorAll('.newest-link-item').forEach(i => i.classList.remove('selected'));
            item.classList.add('selected');
        });
    });
    
    // Auto-select and preview first result on search
    if (currentLinkSearch && links.length > 0) {
        const firstItem = container.querySelector('.newest-link-item');
        if (firstItem && !document.querySelector('.newest-link-item.selected')) {
            firstItem.classList.add('selected');
            previewLink(firstItem.dataset.shortCode, firstItem.dataset.linkUrl);
            firstItem.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }
}

window.previewLink = function(shortCode, originalUrl) {
    const panel = document.getElementById('newest-link-preview');
    const urlSpan = document.getElementById('preview-url');
    const frame = document.getElementById('preview-frame');
    if (!panel) return;
    panel.style.display = 'flex';
    if (urlSpan) urlSpan.innerHTML = `<a href="${escapeHtml(originalUrl)}" target="_blank">${escapeHtml(originalUrl.substring(0, 50))}...</a>`;
    if (frame) {
        frame.innerHTML = '<div class="preview-loading"><div class="loading-spinner"></div><p>Loading preview...</p></div>';
        const iframe = document.createElement('iframe');
        iframe.src = originalUrl;
        iframe.sandbox = 'allow-same-origin allow-scripts allow-popups allow-forms allow-modals';
        iframe.style.width = '100%';
        iframe.style.height = '100%';
        iframe.style.border = 'none';
        iframe.onload = () => { frame.innerHTML = ''; frame.appendChild(iframe); };
        iframe.onerror = () => { frame.innerHTML = '<div class="preview-error">Unable to load preview.</div>'; };
        setTimeout(() => { if (frame.innerHTML.includes('Loading preview')) frame.innerHTML = '<div class="preview-error">Preview timed out.</div>'; }, 10000);
    }
};

// ============================================================================
// USER MODAL
// ============================================================================

window.openUserModal = async function(userId) {
    const modal = document.getElementById('userModal');
    if (!modal) return;
    currentUserModalId = userId;
    modal.classList.add('visible');
    document.getElementById('modal-username').innerText = 'Loading...';
    document.getElementById('modal-email').innerText = '-';
    document.getElementById('modal-user-id').innerText = `ID #${userId}`;
    document.getElementById('modal-joined').innerText = '-';
    document.getElementById('modal-link-count').innerText = '-';
    document.getElementById('modal-total-clicks').innerText = '-';
    document.getElementById('user-links-list').innerHTML = '<div class="empty-state">Loading links...</div>';
    if (userChart) { userChart.destroy(); userChart = null; }
    
    const deleteBtn = document.getElementById('delete-user-btn');
    if (deleteBtn) {
        const newDeleteBtn = deleteBtn.cloneNode(true);
        deleteBtn.parentNode.replaceChild(newDeleteBtn, deleteBtn);
        newDeleteBtn.onclick = () => { 
            const username = document.getElementById('modal-username').innerText; 
            showPriorityConfirm(`Delete account "${username}"? All their links will also be permanently deleted.`, () => deleteUserAccount(userId)); 
        };
    }
    
    try {
        const res = await fetch(`${API_BASE}admin_user.php?action=details&id=${userId}`);
        const data = await res.json();
        if (!data.success) { 
            document.getElementById('modal-username').innerText = 'Error'; 
            return; 
        }
        const user = data.user, links = data.links || [], chartStats = data.stats || [];
        document.getElementById('modal-username').innerText = user.username;
        document.getElementById('modal-email').innerText = user.email;
        document.getElementById('modal-user-id').innerText = `ID #${user.id}`;
        document.getElementById('modal-joined').innerText = new Date(user.created_at).toLocaleDateString();
        document.getElementById('modal-link-count').innerText = user.total_links || 0;
        document.getElementById('modal-total-clicks').innerText = user.total_clicks || 0;
        
        const listEl = document.getElementById('user-links-list');
        if (links.length === 0) { 
            listEl.innerHTML = '<div class="empty-state">This user has no links.</div>'; 
        } else {
            // Remove any existing search input
            const existingSearch = listEl.querySelector('.user-links-search');
            if (existingSearch) existingSearch.remove();
            listEl._originalLinks = links;
            renderUserLinksList(links);
        }
        renderUserChart(chartStats);
    } catch (err) { 
        document.getElementById('modal-username').innerText = 'Failed to load.';
        console.error(err);
    }
};

function renderUserLinksList(links) {
    const container = document.getElementById('user-links-list');
    if (!container) return;
    if (links.length === 0) {
        container.innerHTML = '<div class="empty-state">No matching links found.</div>';
        return;
    }
    container.innerHTML = links.map(link => {
        const isExpired = link.expires_at && new Date(link.expires_at) < new Date();
        const shortUrl = `/LinkVault/${link.short_code}`;
        return `<div class="mini-link-item" id="link-row-${link.id}">
            <div class="mini-link-info">
                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;"><code>/${escapeHtml(link.short_code)}</code>${isExpired ? '<span class="badge-expired">Expired</span>' : ''}</div>
                <small class="muted" style="display:block;margin-top:2px;font-size:0.65rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${escapeHtml(link.original_url)}">${escapeHtml(link.original_url_short || link.original_url.substring(0, 50))}${link.original_url.length > 50 ? '...' : ''}</small>
                <small class="muted" style="font-size:0.6rem;">📊 ${link.clicks || 0} clicks · 📅 ${link.created_formatted || new Date(link.created_at).toLocaleDateString()}</small>
            </div>
            <div class="mini-link-actions">
                <a href="${shortUrl}" target="_blank" class="action-btn" style="width:28px;height:28px;font-size:11px;">🔗</a>
                <button class="action-btn danger-hover" style="width:28px;height:28px;font-size:11px;" onclick="deleteLinkFromModal(${link.id}, ${currentUserModalId})">✕</button>
            </div>
        </div>`;
    }).join('');
}

window.closeUserModal = function() {
    document.getElementById('userModal').classList.remove('visible');
    if (userChart) { userChart.destroy(); userChart = null; }
};

function renderUserChart(statsData) {
    const ctx = document.getElementById('userActivityChart');
    if (!ctx) return;
    if (userChart) userChart.destroy();
    if (!statsData || statsData.length === 0) {
        userChart = new Chart(ctx, { 
            type: 'bar', 
            data: { labels: ['No data'], datasets: [{ label: 'Links Created', data: [0], backgroundColor: '#71717A', borderRadius: 4 }] }, 
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } } 
        });
        return;
    }
    userChart = new Chart(ctx, {
        type: 'bar', 
        data: { labels: statsData.map(s => s.date), datasets: [{ label: 'Links Created', data: statsData.map(s => s.links), backgroundColor: '#00D4B4', borderRadius: 6 }] },
        options: { 
            responsive: true, maintainAspectRatio: false, 
            plugins: { legend: { labels: { color: '#71717A' } } }, 
            scales: { y: { beginAtZero: true, ticks: { color: '#71717A', stepSize: 1 }, grid: { color: 'rgba(255,255,255,0.05)' } }, x: { ticks: { color: '#71717A', font: { size: 10 }, maxRotation: 45 }, grid: { display: false } } } 
        }
    });
}

// ============================================================================
// DELETE ACTIONS
// ============================================================================

window.suspendLink = async function(linkId) {
    showPriorityConfirm(`Suspend this link? It will expire immediately and become inaccessible.`, async () => {
        try {
            const res = await fetch(`${API_BASE}admin_links.php?action=suspend&id=${linkId}`, { method: 'POST', headers: getCsrfHeaders() });
            const data = await res.json();
            if (data.success) { 
                showToast('Link suspended successfully.', 'success'); 
                loadLinksData(); 
            } else { 
                showToast(data.error || 'Could not suspend link.', 'error'); 
            }
        } catch (e) { showToast('Network error', 'error'); }
    });
};

window.deleteLinkFromList = async function(linkId) {
    showPriorityConfirm(`Delete this link? This action cannot be undone.`, async () => {
        try {
            const res = await fetch(`${API_BASE}admin_links.php?action=delete&id=${linkId}`, { method: 'POST', headers: getCsrfHeaders() });
            const data = await res.json();
            if (data.success) { 
                showToast('Link deleted successfully', 'success'); 
                loadLinksData(); 
                document.getElementById('newest-link-preview').style.display = 'none'; 
            } else { 
                showToast(data.error || 'Could not delete link.', 'error'); 
            }
        } catch (e) { showToast('Network error', 'error'); }
    });
};

async function deleteUserAccount(userId) {
    try {
        const res = await fetch(`${API_BASE}admin_user.php?action=delete&id=${userId}`);
        const data = await res.json();
        if (data.success) { 
            closeUserModal(); 
            loadUsersData(); 
            showToast('User deleted successfully', 'success'); 
        } else { 
            showToast(data.error || 'Could not delete user.', 'error'); 
        }
    } catch (e) { showToast('Network error', 'error'); }
}

window.deleteLinkFromModal = async function(linkId, userId) {
    showPriorityConfirm(`Delete this link? This action cannot be undone.`, async () => {
        try {
            const res = await fetch(`${API_BASE}admin_user.php?action=delete_link&id=${linkId}`);
            const data = await res.json();
            if (data.success) { 
                document.getElementById(`link-row-${linkId}`)?.remove(); 
                openUserModal(userId); 
                showToast('Link deleted successfully', 'success'); 
            } else { 
                showToast(data.error || 'Could not delete link.', 'error'); 
            }
        } catch (e) { showToast('Network error', 'error'); }
    });
};

// ============================================================================
// CONFIRM MODAL
// ============================================================================

function showPriorityConfirm(message, onConfirm, onCancel) {
    const existing = document.getElementById('custom-confirm-overlay');
    if (existing) existing.remove();
    const overlay = document.createElement('div');
    overlay.id = 'custom-confirm-overlay';
    overlay.innerHTML = `<div class="confirm-box"><div class="confirm-icon">⚠️</div><h3>Confirm Action</h3><p>${escapeHtml(message)}</p><div class="confirm-actions"><button class="confirm-btn-secondary" id="confirm-cancel-btn">Cancel</button><button class="confirm-btn-danger" id="confirm-ok-btn">Delete</button></div></div>`;
    document.body.appendChild(overlay);
    document.getElementById('confirm-cancel-btn').onclick = () => { overlay.remove(); if (onCancel) onCancel(); };
    document.getElementById('confirm-ok-btn').onclick = () => { overlay.remove(); if (onConfirm) onConfirm(); };
    overlay.onclick = (e) => { if (e.target === overlay) overlay.remove(); };
}

// ============================================================================
// TOAST & UTILS
// ============================================================================

function highlightText(text, searchTerm) {
    if (!searchTerm || !text) return escapeHtml(text);
    const escapedText = escapeHtml(text);
    const escapedSearch = escapeHtml(searchTerm);
    const regex = new RegExp(`(${escapedSearch.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi');
    return escapedText.replace(regex, '<mark style="background:var(--accent-dim); color:var(--accent); padding:0 2px; border-radius:3px;">$1</mark>');
}

function showToast(message, type = 'info') {
    let container = document.getElementById('toast-container');
    if (!container) { 
        container = document.createElement('div'); 
        container.id = 'toast-container'; 
        document.body.appendChild(container); 
    }
    const toast = document.createElement('div');
    toast.className = 'success-toast';
    toast.innerHTML = `<div class="checkmark-circle"><div class="background"></div><div class="check"></div></div><span class="toast-text">${escapeHtml(message)}</span>`;
    container.appendChild(toast);
    setTimeout(() => { 
        toast.style.opacity = '0'; 
        setTimeout(() => toast.remove(), 300); 
    }, 3000);
}

function escapeHtml(str) {
    if (str == null) return '';
    return String(str).replace(/[&<>]/g, m => m === '&' ? '&amp;' : m === '<' ? '&lt;' : '&gt;');
}