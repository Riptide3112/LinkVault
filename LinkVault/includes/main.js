document.addEventListener('DOMContentLoaded', () => {

  const urlInput      = document.getElementById('url');
  const expirySection = document.getElementById('expiry-section');
  const expiryBtns    = document.querySelectorAll('.expiry-btn');
  const expiryInput   = document.getElementById('expires_in');
  const submitSection = document.getElementById('submit-section');
  const submitBtn     = document.getElementById('submit-btn');
  const popup         = document.getElementById('auth-popup');
  const popupClose    = document.getElementById('popup-close');
  const resultBox     = document.getElementById('result-box');

  // ── Slide helpers ──────────────────────────────────────────────────────────
  function slideDown(el) {
    el.style.display    = 'block';
    el.style.overflow   = 'hidden';
    el.style.maxHeight  = '0';
    el.style.opacity    = '0';
    el.style.transition = 'max-height 0.4s cubic-bezier(0.4,0,0.2,1), opacity 0.35s ease';
    el.getBoundingClientRect();
    el.style.maxHeight  = el.scrollHeight + 'px';
    el.style.opacity    = '1';
    el.addEventListener('transitionend', () => {
      el.style.maxHeight = 'none';
      el.style.overflow  = 'visible';
    }, { once: true });
  }

  function slideUp(el) {
    el.style.overflow   = 'hidden';
    el.style.maxHeight  = el.scrollHeight + 'px';
    el.getBoundingClientRect();
    el.style.transition = 'max-height 0.3s cubic-bezier(0.4,0,0.2,1), opacity 0.25s ease';
    el.style.maxHeight  = '0';
    el.style.opacity    = '0';
    el.addEventListener('transitionend', () => {
      el.style.display = 'none';
    }, { once: true });
  }

  function showInstant(el) {
    el.style.display    = 'block';
    el.style.opacity    = '1';
    el.style.maxHeight  = 'none';
    el.style.overflow   = 'visible';
    el.style.transition = 'none';
  }

  // ── URL validator ──────────────────────────────────────────────────────────
  function isValidUrl(str) {
    try {
      const u = new URL(str);
      return u.protocol === 'http:' || u.protocol === 'https:';
    } catch {
      return false;
    }
  }

  // ── Auth popup (expiry locked) ─────────────────────────────────────────────
  function showPopup() { if (popup) popup.classList.add('visible'); }
  function hidePopup() { if (popup) popup.classList.remove('visible'); }

  if (popupClose) popupClose.addEventListener('click', hidePopup);
  if (popup) popup.addEventListener('click', e => { if (e.target === popup) hidePopup(); });
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
      hidePopup();
      hideLoginModal();
    }
  });

  // ── After POST reload: restore full UI state instantly ────────────────────
  if (expirySection) {
    const forceShow   = expirySection.dataset.forceShow === '1';
    const selectedVal = expirySection.dataset.selectedExp;

    if (forceShow) {
      showInstant(expirySection);
      if (selectedVal !== undefined) {
        expiryBtns.forEach(b => {
          if (b.dataset.value === selectedVal) b.classList.add('selected');
        });
      }
      if (submitSection) showInstant(submitSection);
    }
  }

  // ── Step 1 → Step 2: URL valid → animă Expiry ─────────────────────────────
  let urlDebounce = null;

  if (urlInput && expirySection) {
    urlInput.addEventListener('input', () => {
      clearTimeout(urlDebounce);
      urlDebounce = setTimeout(() => {
        const valid = isValidUrl(urlInput.value.trim());
        if (valid && expirySection.style.display !== 'block') {
          slideDown(expirySection);
        } else if (!valid && expirySection.style.display === 'block') {
          slideUp(expirySection);
          if (submitSection && submitSection.style.display === 'block') slideUp(submitSection);
          expiryBtns.forEach(b => b.classList.remove('selected'));
          if (expiryInput) expiryInput.value = '1';
        }
      }, 300);
    });
  }

  // ── Step 2 → Step 3: alege expiry → animă Submit ──────────────────────────
  expiryBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      if (btn.dataset.requiresAuth === '1') {
        // Daca exista modal de login in pagina, il deschidem direct
        if (document.getElementById('login-modal')) {
          showLoginModal();
        } else {
          showPopup();
        }
        return;
      }
      expiryBtns.forEach(b => b.classList.remove('selected'));
      btn.classList.add('selected');
      if (expiryInput) expiryInput.value = btn.dataset.value;
      if (submitSection && submitSection.style.display !== 'block') {
        setTimeout(() => slideDown(submitSection), 80);
      }
    });
  });

  // ── Submit: loading state ──────────────────────────────────────────────────
  const form = document.querySelector('form');
  if (form && submitBtn) {
    form.addEventListener('submit', () => {
      const val = urlInput ? urlInput.value.trim() : '';
      if (!val || !isValidUrl(val)) return;
      submitBtn.classList.add('loading');
      submitBtn.disabled    = true;
      submitBtn.textContent = 'Generating…';
    });
  }

  // ── Scroll la rezultat ─────────────────────────────────────────────────────
  if (resultBox) {
    setTimeout(() => resultBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' }), 150);
  }

  // ── Copy button ────────────────────────────────────────────────────────────
  const copyBtn      = document.getElementById('copyBtn');
  const shortUrlText = document.getElementById('shortUrlText');

  if (copyBtn && shortUrlText) {
    copyBtn.addEventListener('click', () => {
      navigator.clipboard.writeText(shortUrlText.textContent.trim()).then(() => {
        copyBtn.textContent = 'Copied!';
        copyBtn.classList.add('copied');
        setTimeout(() => { copyBtn.textContent = 'Copy'; copyBtn.classList.remove('copied'); }, 2000);
      }).catch(() => {
        const range = document.createRange();
        range.selectNode(shortUrlText);
        window.getSelection().removeAllRanges();
        window.getSelection().addRange(range);
        document.execCommand('copy');
        window.getSelection().removeAllRanges();
        copyBtn.textContent = 'Copied!';
        copyBtn.classList.add('copied');
        setTimeout(() => { copyBtn.textContent = 'Copy'; copyBtn.classList.remove('copied'); }, 2000);
      });
    });
  }

});

// ── Login modal — expus global ca sa poata fi apelat din main.js ────────────
function showLoginModal() {
  const modal = document.getElementById('login-modal');
  if (!modal) return;
  const errorDiv = document.getElementById('login-error');
  if (errorDiv) errorDiv.style.display = 'none';
  modal.classList.add('visible');
  setTimeout(() => document.getElementById('login-email')?.focus(), 100);
}

function hideLoginModal() {
  const modal = document.getElementById('login-modal');
  if (!modal) return;
  modal.classList.remove('visible');
  const form = document.getElementById('ajax-login-form');
  if (form) form.reset();
  const errorDiv = document.getElementById('login-error');
  if (errorDiv) errorDiv.style.display = 'none';
}