<?php
/**
 * footer.php - Global Footer Component
 * 
 * This file contains the footer and all global JavaScript components that are
 * shared across the entire application. It is included at the end of every page.
 * 
 * Components included:
 *   1. Site Footer - Copyright and navigation links
 *   2. Toast Notification System - User feedback messages
 *   3. Login Modal - AJAX-based authentication popup
 *   4. Delete Link Confirmation Popup - Prevent accidental deletions
 *   5. User Dropdown Menu - Authenticated user menu
 *   6. Mobile Hamburger Menu - Responsive navigation
 *   7. Escape Key Handler - Close modals with keyboard
 * 
 * Security Features:
 *   - CSRF token integration for AJAX requests
 *   - XSS prevention via escapeHtml() function
 *   - Input sanitization for toast messages
 *   - Secure modal handling (no inline scripts)
 * 
 * @package LinkVault
 * @subpackage UI
 * @location /includes/footer.php
 * @requires main.js, style.css
 */
?>

<!-- ============================================================================
     SITE FOOTER
     ============================================================================ -->

</main> <!-- Close main content wrapper opened in header.php -->

<site-footer>
  <div class="footer-inner">
    <!-- Copyright information - dynamic year -->
    <span>LinkVault © <?= date('Y') ?></span>
    <!-- Quick navigation links in footer -->
    <span>
      <a href="/LinkVault/">Home</a> &nbsp;·&nbsp;
      <a href="/LinkVault/pages/dashboard.php">Dashboard</a>
    </span>
  </div>
</site-footer>

<!-- ============================================================================
     TOAST NOTIFICATION SYSTEM
     ============================================================================ -->

<!-- Container for dynamic toast messages (positioned fixed, top-right) -->
<div id="toast-container" aria-live="polite" aria-atomic="false"></div>

<!-- ============================================================================
     LOGIN MODAL - AJAX Authentication Popup
     ============================================================================ -->

<!--
  Login Modal - Appears when user clicks "Log in" button
  Features:
    - AJAX form submission (no page reload)
    - CSRF protection
    - Error handling with user-friendly messages
    - Redirects to dashboard on success
-->
<div id="login-modal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="modal-title">
  <div class="modal-container">
    <button class="modal-close" id="modal-close" aria-label="Close">&times;</button>
    <div class="modal-icon">[!]</div>
    <h3 class="modal-title" id="modal-title">Welcome back</h3>
    <p class="modal-desc">Log in to manage your short links</p>
    <form id="ajax-login-form" novalidate>
      <div class="form-group">
        <label for="login-email">Email or Username</label>
        <input type="text" id="login-email" name="username" required autocomplete="username">
      </div>
      <div class="form-group">
        <label for="login-password">Password</label>
        <input type="password" id="login-password" name="password" required autocomplete="current-password">
      </div>
      <div id="login-error" class="login-error" style="display:none" role="alert"></div>
      <button type="submit" class="btn btn-modal" id="login-submit-btn">Log in</button>
    </form>
    <div class="modal-footer">
      Don't have an account? <a href="/LinkVault/pages/register.php">Register</a>
    </div>
  </div>
</div>

<!-- ============================================================================
     DELETE LINK CONFIRMATION POPUP
     ============================================================================ -->

<!--
  Delete Link Popup - Confirmation dialog before permanent deletion
  Appears when user clicks delete button on any link
  Features:
    - Prevents accidental deletions (double confirmation)
    - Calls callback function on confirmation
    - Styled with danger colors (red accents)
-->
<div id="delete-link-popup" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="delete-link-title">
  <div class="modal-container modal-danger-container">
    <div class="modal-icon">[X]</div>
    <h3 class="modal-title" id="delete-link-title">Delete this link?</h3>
    <p class="modal-desc">This action cannot be undone. All click statistics for this link will be permanently lost.</p>
    <div class="modal-actions-row">
      <button class="btn-modal-cancel" id="delete-link-cancel">Cancel</button>
      <button class="btn-modal-danger" id="delete-link-confirm">Delete link</button>
    </div>
  </div>
</div>

<!-- ============================================================================
     INLINE STYLES - Global Component Styles
     ============================================================================ -->

<style>
/* Toast Notifications - Floating notification system */
#toast-container {
  position: fixed;
  top: 20px;
  right: 20px;
  z-index: 9999;
  display: flex;
  flex-direction: column;
  gap: 10px;
  pointer-events: none; /* Allows clicking through container, but not toasts */
}

.toast {
  pointer-events: all; /* Toasts themselves are clickable */
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 13px 18px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 10px;
  font-family: var(--mono);
  font-size: 0.78rem;
  color: var(--text);
  box-shadow: 0 8px 32px rgba(0,0,0,0.45);
  min-width: 260px;
  max-width: 360px;
  transform: translateX(calc(100% + 24px)); /* Hidden off-screen to the right */
  opacity: 0;
  transition: transform 0.35s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.3s ease;
  position: relative;
  overflow: hidden;
}

/* Visible state - slide in from right */
.toast.toast-visible {
  transform: translateX(0);
  opacity: 1;
}

/* Hiding state - slide out to right */
.toast.toast-hiding {
  transform: translateX(calc(100% + 24px));
  opacity: 0;
  transition: transform 0.3s ease, opacity 0.25s ease;
}

/* Progress bar animation at bottom of toast */
.toast-progress {
  position: absolute;
  bottom: 0;
  left: 0;
  height: 2px;
  background: var(--accent);
  width: 100%;
  transform-origin: left;
  animation: toastProgress var(--toast-duration, 4s) linear forwards;
}

/* Color variations for different toast types */
.toast-success .toast-progress { background: #00D4B4; }
.toast-error   .toast-progress { background: #FF4D6A; }
.toast-info    .toast-progress { background: #60A5FA; }
.toast-warning .toast-progress { background: #F59E0B; }

/* Left border accents for visual categorization */
.toast-success { border-left: 3px solid #00D4B4; }
.toast-error   { border-left: 3px solid #FF4D6A; }
.toast-info    { border-left: 3px solid #60A5FA; }
.toast-warning { border-left: 3px solid #F59E0B; }

.toast-icon { font-size: 1rem; flex-shrink: 0; }
.toast-message { flex: 1; line-height: 1.4; }

.toast-close {
  background: none;
  border: none;
  color: var(--muted);
  cursor: pointer;
  font-size: 0.85rem;
  padding: 2px 4px;
  line-height: 1;
  flex-shrink: 0;
  transition: color 0.2s;
}
.toast-close:hover { color: var(--text); }

/* Progress bar animation */
@keyframes toastProgress {
  from { transform: scaleX(1); }
  to   { transform: scaleX(0); }
}

/* Delete Link Popup - Danger theme */
.modal-danger-container {
  border-top: 3px solid #FF4D6A;
}

.modal-actions-row {
  display: flex;
  gap: 10px;
  margin-top: 24px;
  justify-content: center;
}

.btn-modal-cancel {
  flex: 1;
  padding: 11px 20px;
  background: transparent;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  color: var(--muted);
  font-family: var(--mono);
  font-size: 0.82rem;
  cursor: pointer;
  transition: border-color 0.2s, color 0.2s;
}
.btn-modal-cancel:hover {
  border-color: var(--border2);
  color: var(--text);
}

.btn-modal-danger {
  flex: 1;
  padding: 11px 20px;
  background: #FF4D6A;
  border: none;
  border-radius: var(--radius);
  color: #fff;
  font-family: var(--sans);
  font-size: 0.82rem;
  font-weight: 700;
  cursor: pointer;
  transition: opacity 0.2s;
}
.btn-modal-danger:hover { opacity: 0.85; }
</style>

<!-- ============================================================================
     MAIN JAVASCRIPT
     ============================================================================ -->

<script src="/LinkVault/includes/main.js"></script>

<!-- ============================================================================
     CORE JAVASCRIPT FUNCTIONALITY
     ============================================================================ -->

<script>
(function () {
  // Get CSRF token from meta tag (set in header.php)
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

  // ==========================================================================
  // TOAST SYSTEM - User feedback notifications
  // ==========================================================================
  
  /**
   * Icon mapping for different toast types
   * Uses simple ASCII symbols for reliability
   */
  const TOAST_ICONS = {
    success: '✓',
    error:   '✕',
    info:    'ℹ',
    warning: '⚠',
  };

  /**
   * Display a toast notification
   * @param {string} message - The message to display
   * @param {string} type - One of: 'success', 'error', 'info', 'warning'
   * @param {number} duration - How long to show (ms), default 4000
   */
  window.showToast = function(message, type = 'info', duration = 4000) {
    const container = document.getElementById('toast-container');
    if (!container) return;

    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.style.setProperty('--toast-duration', duration + 'ms');
    toast.innerHTML = `
      <span class="toast-icon">${TOAST_ICONS[type] ?? 'ℹ'}</span>
      <span class="toast-message">${escapeHtml(message)}</span>
      <button class="toast-close" aria-label="Dismiss">✕</button>
      <div class="toast-progress"></div>
    `;

    container.appendChild(toast);

    // Trigger enter animation (double rAF ensures smooth transition)
    requestAnimationFrame(() => {
      requestAnimationFrame(() => toast.classList.add('toast-visible'));
    });

    const dismiss = () => {
      toast.classList.add('toast-hiding');
      toast.addEventListener('transitionend', () => toast.remove(), { once: true });
    };

    toast.querySelector('.toast-close').addEventListener('click', dismiss);
    setTimeout(dismiss, duration);
  };

  /**
   * XSS Prevention: Escape HTML special characters
   * @param {string} str - Raw string
   * @returns {string} Escaped string safe for HTML insertion
   */
  function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>]/g, function(m) {
      if (m === '&') return '&amp;';
      if (m === '<') return '&lt;';
      if (m === '>') return '&gt;';
      return m;
    });
  }

  // ==========================================================================
  // TOAST FROM URL PARAMETERS (Post-redirect notifications)
  // ==========================================================================
  
  /**
   * Check URL for toast parameters after redirects
   * This allows showing success/error messages after page reloads
   */
  const urlParams = new URLSearchParams(window.location.search);
  const toastType = urlParams.get('toast');
  
  if (toastType === 'logged_out') {
    showToast('You have been logged out successfully.', 'success', 4000);
    const url = new URL(window.location);
    url.searchParams.delete('toast');
    history.replaceState({}, '', url);
  }
  if (toastType === 'logged_in') {
    showToast('Welcome back! You are now logged in.', 'success', 4000);
    const url = new URL(window.location);
    url.searchParams.delete('toast');
    history.replaceState({}, '', url);
  }
  if (toastType === 'registered') {
    showToast('Account created successfully. Welcome to LinkVault!', 'success', 5000);
    const url = new URL(window.location);
    url.searchParams.delete('toast');
    history.replaceState({}, '', url);
  }
  if (toastType === 'link_deleted') {
    showToast('Link deleted successfully.', 'success', 4000);
    const url = new URL(window.location);
    url.searchParams.delete('toast');
    history.replaceState({}, '', url);
  }
  if (toastType === 'account_deleted') {
    showToast('Your account has been permanently deleted.', 'info', 5000);
    const url = new URL(window.location);
    url.searchParams.delete('toast');
    history.replaceState({}, '', url);
  }

  // ==========================================================================
  // DELETE LINK POPUP - Confirmation modal
  // ==========================================================================
  
  const deleteLinkPopup   = document.getElementById('delete-link-popup');
  const deleteLinkCancel  = document.getElementById('delete-link-cancel');
  const deleteLinkConfirm = document.getElementById('delete-link-confirm');

  let pendingDeleteCallback = null;

  /**
   * Show delete confirmation popup
   * @param {Function} onConfirm - Callback to execute on confirmation
   */
  window.confirmDeleteLink = function(onConfirm) {
    pendingDeleteCallback = onConfirm;
    if (deleteLinkPopup) deleteLinkPopup.classList.add('visible');
  };

  if (deleteLinkCancel) {
    deleteLinkCancel.addEventListener('click', () => {
      deleteLinkPopup.classList.remove('visible');
      pendingDeleteCallback = null;
    });
  }

  if (deleteLinkConfirm) {
    deleteLinkConfirm.addEventListener('click', () => {
      deleteLinkPopup.classList.remove('visible');
      if (typeof pendingDeleteCallback === 'function') {
        pendingDeleteCallback();
        pendingDeleteCallback = null;
      }
    });
  }

  // Close popup when clicking on overlay (not on modal content)
  if (deleteLinkPopup) {
    deleteLinkPopup.addEventListener('click', e => {
      if (e.target === deleteLinkPopup) {
        deleteLinkPopup.classList.remove('visible');
        pendingDeleteCallback = null;
      }
    });
  }

  // ==========================================================================
  // LOGIN MODAL - AJAX Authentication
  // ==========================================================================
  
  const modal     = document.getElementById('login-modal');
  const closeBtn  = document.getElementById('modal-close');
  const loginForm = document.getElementById('ajax-login-form');
  const errorDiv  = document.getElementById('login-error');
  const submitBtn = document.getElementById('login-submit-btn');

  if (modal) {
    // Attach click handlers to all login trigger buttons
    document.querySelectorAll('[id^="login-popup-trigger"]').forEach(trigger => {
      trigger.addEventListener('click', e => {
        e.preventDefault();
        showLoginModal();
      });
    });

    closeBtn && closeBtn.addEventListener('click', hideLoginModal);
    modal.addEventListener('click', e => {
      if (e.target === modal) hideLoginModal();
    });
  }

  /**
   * Show login modal with focus on first input
   */
  window.showLoginModal = function() {
    if (!modal) return;
    if (errorDiv) errorDiv.style.display = 'none';
    modal.classList.add('visible');
    setTimeout(() => document.getElementById('login-email')?.focus(), 100);
  };

  /**
   * Hide login modal and reset form
   */
  window.hideLoginModal = function() {
    if (!modal) return;
    modal.classList.remove('visible');
    if (loginForm) loginForm.reset();
    if (errorDiv) errorDiv.style.display = 'none';
  };

  /**
   * AJAX login form submission
   * Non-blocking, no page reload on failure
   */
  if (loginForm && submitBtn) {
    loginForm.addEventListener('submit', async (e) => {
      e.preventDefault();

      const username = document.getElementById('login-email').value.trim();
      const password = document.getElementById('login-password').value;

      if (!username || !password) {
        if (errorDiv) {
          errorDiv.textContent = 'Please fill in all fields.';
          errorDiv.style.display = 'block';
        }
        return;
      }

      submitBtn.disabled = true;
      submitBtn.textContent = 'Logging in...';

      try {
        const res = await fetch('/LinkVault/ajax_login.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
          },
          body: JSON.stringify({ username, password })
        });
        const data = await res.json();

        if (data.success) {
          window.location.href = '/LinkVault/pages/dashboard.php?toast=logged_in';
        } else {
          if (errorDiv) {
            errorDiv.textContent = data.error || 'Invalid credentials.';
            errorDiv.style.display = 'block';
          }
          submitBtn.disabled = false;
          submitBtn.textContent = 'Log in';
        }
      } catch {
        if (errorDiv) {
          errorDiv.textContent = 'Connection error. Please try again.';
          errorDiv.style.display = 'block';
        }
        submitBtn.disabled = false;
        submitBtn.textContent = 'Log in';
      }
    });
  }

  // ==========================================================================
  // USER DROPDOWN MENU - Desktop authenticated user menu
  // ==========================================================================
  
  const userTrigger  = document.getElementById('userMenuTrigger');
  const userDropdown = document.getElementById('userMenuDropdown');

  if (userTrigger && userDropdown) {
    userTrigger.addEventListener('click', e => {
      e.stopPropagation();
      const open = userDropdown.classList.toggle('visible');
      userTrigger.setAttribute('aria-expanded', open);
    });
    
    // Close dropdown when clicking anywhere else
    document.addEventListener('click', () => {
      userDropdown.classList.remove('visible');
      userTrigger && userTrigger.setAttribute('aria-expanded', false);
    });
    
    // Close dropdown on Escape key
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape') {
        userDropdown.classList.remove('visible');
        userTrigger && userTrigger.setAttribute('aria-expanded', false);
      }
    });
  }

  // ==========================================================================
  // HAMBURGER MENU - Mobile responsive navigation
  // ==========================================================================
  
  const hamburger = document.getElementById('hamburger');
  const navMobile = document.getElementById('navMobile');

  if (hamburger && navMobile) {
    hamburger.addEventListener('click', () => {
      const open = hamburger.classList.toggle('open');
      navMobile.classList.toggle('open', open);
      hamburger.setAttribute('aria-expanded', open);
      navMobile.setAttribute('aria-hidden', !open);
    });
    
    // Close mobile menu when a link is clicked
    navMobile.querySelectorAll('a').forEach(a => {
      a.addEventListener('click', () => {
        hamburger.classList.remove('open');
        navMobile.classList.remove('open');
        hamburger.setAttribute('aria-expanded', false);
        navMobile.setAttribute('aria-hidden', true);
      });
    });
  }

  // ==========================================================================
  // GLOBAL ESCAPE KEY HANDLER - Close all modals
  // ==========================================================================
  
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
      if (modal && modal.classList.contains('visible')) hideLoginModal();
      if (deleteLinkPopup && deleteLinkPopup.classList.contains('visible')) {
        deleteLinkPopup.classList.remove('visible');
        pendingDeleteCallback = null;
      }
    }
  });
})();
</script>
</body>
</html>