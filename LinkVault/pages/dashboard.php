<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: /LinkVault/pages/login.php');
    exit;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/security.php';

$userId = (int) $_SESSION['user_id'];
$tab    = $_GET['tab'] ?? 'links';

$stmt = $pdo->prepare("SELECT id, username, email, created_at FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header('Location: /LinkVault/pages/login.php');
    exit;
}

$settingsSuccess = null;
$settingsError   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_verify();

    if ($_POST['action'] === 'change_username') {
        $newUsername = trim($_POST['new_username'] ?? '');
        if (empty($newUsername) || strlen($newUsername) < 3 || strlen($newUsername) > 32) {
            $settingsError = 'Username must be between 3 and 32 characters.';
        } elseif (!preg_match('/^[a-zA-Z0-9_.-]+$/', $newUsername)) {
            $settingsError = 'Only letters, numbers, underscores, dots and hyphens allowed.';
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE users SET username = ? WHERE id = ?");
                $stmt->execute([$newUsername, $userId]);
                $_SESSION['username'] = $newUsername;
                $user['username']     = $newUsername;
                $settingsSuccess      = 'username_updated';
            } catch (PDOException $e) {
                $settingsError = $e->getCode() === '23000'
                    ? 'This username is already taken.'
                    : 'Could not update username.';
            }
        }
    }

    if ($_POST['action'] === 'change_password') {
        $currentPw = $_POST['current_password'] ?? '';
        $newPw     = $_POST['new_password'] ?? '';
        $newPw2    = $_POST['new_password2'] ?? '';
        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!password_verify($currentPw, $row['password_hash'])) {
            $settingsError = 'Current password is incorrect.';
        } elseif (strlen($newPw) < 8) {
            $settingsError = 'New password must be at least 8 characters.';
        } elseif ($newPw !== $newPw2) {
            $settingsError = 'New passwords do not match.';
        } else {
            $hash = password_hash($newPw, PASSWORD_BCRYPT, ['cost' => 12]);
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$hash, $userId]);
            $settingsSuccess = 'password_updated';
        }
    }

    if ($_POST['action'] === 'delete_account') {
        $confirmText = trim($_POST['confirm_text'] ?? '');
        $currentPw   = $_POST['current_password_delete'] ?? '';

        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if ($confirmText !== 'DELETE') {
            $settingsError = 'Please type DELETE to confirm.';
            $tab = 'settings';
        } elseif (!password_verify($currentPw, $row['password_hash'])) {
            $settingsError = 'Incorrect password.';
            $tab = 'settings';
        } else {
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
            session_destroy();
            header('Location: /LinkVault/?toast=account_deleted');
            exit;
        }
    }

    if ($_POST['action'] === 'delete_link') {
        $linkId = (int) ($_POST['link_id'] ?? 0);
        $pdo->prepare("DELETE FROM links WHERE id = ? AND user_id = ?")->execute([$linkId, $userId]);
        header('Location: /LinkVault/pages/dashboard.php?tab=links&toast=link_deleted');
        exit;
    }

    $tab = 'settings';
}

$stmt = $pdo->prepare("
    SELECT id, short_code, original_url, clicks, expires_at, created_at
    FROM links
    WHERE user_id = ?
    ORDER BY created_at DESC
");
$stmt->execute([$userId]);
$links = $stmt->fetchAll();

$totalLinks  = count($links);
$totalClicks = array_sum(array_column($links, 'clicks'));
$activeLinks = count(array_filter($links, fn($l) =>
    is_null($l['expires_at']) || strtotime($l['expires_at']) > time()
));

$welcome = isset($_GET['welcome']);

$pageTitle = 'Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($tab === 'links'): ?>

<div class="dash-tabs">
  <a href="?tab=links"    class="dash-tab <?= $tab === 'links'    ? 'active' : '' ?>">⚡ My Links</a>
  <a href="?tab=account"  class="dash-tab <?= $tab === 'account'  ? 'active' : '' ?>">👤 Account</a>
  <a href="?tab=settings" class="dash-tab <?= $tab === 'settings' ? 'active' : '' ?>">⚙ Settings</a>
</div>

<div class="dash-stats-row">
  <div class="dash-stat-card">
    <span class="dash-stat-number"><?= $totalLinks ?></span>
    <span class="dash-stat-label">Total links</span>
  </div>
  <div class="dash-stat-card">
    <span class="dash-stat-number"><?= $activeLinks ?></span>
    <span class="dash-stat-label">Active links</span>
  </div>
  <div class="dash-stat-card">
    <span class="dash-stat-number"><?= number_format($totalClicks) ?></span>
    <span class="dash-stat-label">Total clicks</span>
  </div>
</div>

<div class="card dash-links-card">
  <div class="dash-section-header">
    <h2 class="dash-section-title">Your links</h2>
    <a href="/LinkVault/" class="btn-sm">+ New link</a>
  </div>

  <?php if (empty($links)): ?>
    <div class="dash-empty">
      <div class="dash-empty-icon">🔗</div>
      <p>No links yet. <a href="/LinkVault/">Create your first one →</a></p>
    </div>
  <?php else: ?>
    <div class="links-list">
      <?php foreach ($links as $link):
        $shortUrl  = BASE_URL . '/' . $link['short_code'];
        $isExpired = $link['expires_at'] && strtotime($link['expires_at']) < time();
      ?>
        <div class="link-row <?= $isExpired ? 'link-expired' : '' ?>">
          <div class="link-row-main">
            <div class="link-short-wrap">
              <a href="<?= htmlspecialchars($shortUrl) ?>" target="_blank" rel="noopener noreferrer" class="link-short">
                <?= htmlspecialchars($shortUrl) ?>
              </a>
              <?php if ($isExpired): ?>
                <span class="link-badge-expired">Expired</span>
              <?php endif; ?>
            </div>
            <div class="link-original">
              <?= htmlspecialchars(strlen($link['original_url']) > 70
                  ? substr($link['original_url'], 0, 70) . '…'
                  : $link['original_url']) ?>
            </div>
          </div>

          <div class="link-row-meta">
            <span title="Total clicks">🖱 <?= number_format($link['clicks']) ?></span>
            <span title="Created">📅 <?= date('d M Y', strtotime($link['created_at'])) ?></span>
            <span title="Expires">
              ⏱ <?= $link['expires_at']
                    ? ($isExpired ? 'Expired ' : '') . date('d M Y', strtotime($link['expires_at']))
                    : 'Never' ?>
            </span>
          </div>

          <div class="link-row-actions">
            <button class="btn-icon" title="Copy" onclick="copyLink('<?= htmlspecialchars($shortUrl) ?>', this)">⎘</button>
            <button class="btn-icon" title="Stats" onclick="openStats('<?= htmlspecialchars($link['short_code']) ?>')">📊</button>

            <!-- Delete link — fara confirm() nativ, foloseste popup-ul din footer -->
            <form method="POST" action="" style="display:inline" id="delete-form-<?= $link['id'] ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_link">
              <input type="hidden" name="link_id" value="<?= $link['id'] ?>">
              <button
                type="button"
                class="btn-icon btn-icon-danger"
                title="Delete"
                onclick="confirmDeleteLink(() => document.getElementById('delete-form-<?= $link['id'] ?>').submit())"
              >✕</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'account'): ?>

<div class="dash-tabs">
  <a href="?tab=links"    class="dash-tab">⚡ My Links</a>
  <a href="?tab=account"  class="dash-tab active">👤 Account</a>
  <a href="?tab=settings" class="dash-tab">⚙ Settings</a>
</div>

<div class="card">
  <h2 class="dash-section-title" style="margin-bottom:24px">Account info</h2>
  <div class="account-info">
    <div class="account-info-row">
      <span class="account-info-label">👤 Username</span>
      <span class="account-info-value"><?= htmlspecialchars($user['username']) ?></span>
    </div>
    <div class="account-info-row">
      <span class="account-info-label">📧 Email</span>
      <span class="account-info-value"><?= htmlspecialchars($user['email']) ?></span>
    </div>
    <div class="account-info-row">
      <span class="account-info-label">🗓 Member since</span>
      <span class="account-info-value"><?= date('d M Y', strtotime($user['created_at'])) ?></span>
    </div>
    <div class="account-info-row">
      <span class="account-info-label">🔗 Total links</span>
      <span class="account-info-value"><?= $totalLinks ?></span>
    </div>
    <div class="account-info-row">
      <span class="account-info-label">🖱 Total clicks</span>
      <span class="account-info-value"><?= number_format($totalClicks) ?></span>
    </div>
  </div>
</div>

<?php elseif ($tab === 'settings'): ?>

<div class="dash-tabs">
  <a href="?tab=links"    class="dash-tab">⚡ My Links</a>
  <a href="?tab=account"  class="dash-tab">👤 Account</a>
  <a href="?tab=settings" class="dash-tab active">⚙ Settings</a>
</div>

<?php if ($settingsError): ?>
  <div class="alert-error"><span>⚠</span> <?= htmlspecialchars($settingsError) ?></div>
<?php endif; ?>

<div class="card settings-card">
  <h3 class="settings-title">Change username</h3>
  <form method="POST" action="?tab=settings" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="change_username">
    <div class="form-group">
      <label for="new_username">New username</label>
      <input type="text" id="new_username" name="new_username"
        value="<?= htmlspecialchars($user['username']) ?>"
        minlength="3" maxlength="32" required autocomplete="username">
    </div>
    <button type="submit" class="btn" style="width:auto;padding:10px 24px;">Save username</button>
  </form>
</div>

<div class="card settings-card">
  <h3 class="settings-title">Change password</h3>
  <form method="POST" action="?tab=settings" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="change_password">
    <div class="form-group">
      <label for="current_password">Current password</label>
      <div class="input-wrap">
        <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
        <button type="button" class="toggle-pw" data-target="current_password" aria-label="Toggle">👁</button>
      </div>
    </div>
    <div class="form-group">
      <label for="new_password">New password</label>
      <div class="input-wrap">
        <input type="password" id="new_password" name="new_password" required autocomplete="new-password" minlength="8">
        <button type="button" class="toggle-pw" data-target="new_password" aria-label="Toggle">👁</button>
      </div>
    </div>
    <div class="form-group">
      <label for="new_password2">Confirm new password</label>
      <div class="input-wrap">
        <input type="password" id="new_password2" name="new_password2" required autocomplete="new-password">
        <button type="button" class="toggle-pw" data-target="new_password2" aria-label="Toggle">👁</button>
      </div>
    </div>
    <button type="submit" class="btn" style="width:auto;padding:10px 24px;">Save password</button>
  </form>
</div>

<script>
document.querySelectorAll('.toggle-pw').forEach(btn => {
  btn.addEventListener('click', () => {
    const input = document.getElementById(btn.dataset.target);
    if (!input) return;
    input.type = input.type === 'password' ? 'text' : 'password';
    btn.textContent = input.type === 'password' ? '👁' : '🙈';
  });
});
</script>

<!-- ── DELETE ACCOUNT — doar popup, fara confirm() nativ ── -->
<div class="card settings-card settings-danger-card">
  <h3 class="settings-title settings-title-danger">⚠ Delete account</h3>
  <p class="settings-danger-desc">
    This will permanently delete your account, all your links, and all click statistics. This action <strong>cannot be undone</strong>.
  </p>
  <button type="button" class="btn-danger-outline" id="delete-account-toggle">Delete my account</button>
</div>

<!-- ── DELETE ACCOUNT POPUP ── -->
<div id="delete-account-popup" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="da-title">
  <div class="modal-container modal-danger-container">
    <button class="modal-close" id="da-close" aria-label="Close">&times;</button>
    <div class="modal-icon">⚠️</div>
    <h3 class="modal-title" id="da-title">Delete your account?</h3>
    <p class="modal-desc">
      This will permanently delete your account, all your links, and all click statistics.
      This action <strong>cannot be undone</strong>.
    </p>

    <form method="POST" action="?tab=settings" novalidate id="delete-account-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete_account">

      <div class="form-group" style="text-align:left">
        <label for="current_password_delete">Your password</label>
        <div class="input-wrap">
          <input type="password" id="current_password_delete" name="current_password_delete"
            placeholder="Enter your current password" required autocomplete="current-password">
          <button type="button" class="toggle-pw" data-target="current_password_delete" aria-label="Toggle">👁</button>
        </div>
      </div>

      <div class="form-group" style="text-align:left">
        <label for="confirm_text">
          Type <strong style="color:#EF4444;letter-spacing:0.05em">DELETE</strong> to confirm
        </label>
        <input type="text" id="confirm_text" name="confirm_text"
          placeholder="DELETE" autocomplete="off" spellcheck="false">
      </div>

      <div class="modal-actions-row">
        <button type="button" class="btn-modal-cancel" id="da-cancel">Cancel</button>
        <button type="submit" class="btn-modal-danger" id="da-submit" disabled>Delete account</button>
      </div>
    </form>
  </div>
</div>

<style>
  .settings-danger-card { border-color: rgba(239,68,68,0.3); }
  .settings-title-danger { color: #EF4444; }
  .settings-danger-desc {
    font-size: 0.78rem;
    color: var(--muted);
    line-height: 1.7;
    margin-bottom: 18px;
  }
  .settings-danger-desc strong { color: var(--text); }
  .btn-danger-outline {
    display: inline-flex;
    align-items: center;
    padding: 9px 20px;
    background: transparent;
    border: 1px solid #EF4444;
    border-radius: var(--radius);
    color: #EF4444;
    font-family: var(--mono);
    font-size: 0.78rem;
    cursor: pointer;
    transition: background 0.2s;
    letter-spacing: 0.04em;
  }
  .btn-danger-outline:hover { background: rgba(239,68,68,0.08); }
</style>

<script>
(function () {
  const toggleBtn = document.getElementById('delete-account-toggle');
  const popup     = document.getElementById('delete-account-popup');
  const closeBtn  = document.getElementById('da-close');
  const cancelBtn = document.getElementById('da-cancel');
  const input     = document.getElementById('confirm_text');
  const pwInput   = document.getElementById('current_password_delete');
  const submitBtn = document.getElementById('da-submit');

  function openPopup() {
    popup.classList.add('visible');
    setTimeout(() => pwInput?.focus(), 100);
  }

  function closePopup() {
    popup.classList.remove('visible');
    if (input)   input.value   = '';
    if (pwInput) pwInput.value = '';
    if (submitBtn) submitBtn.disabled = true;
  }

  toggleBtn?.addEventListener('click', openPopup);
  closeBtn?.addEventListener('click',  closePopup);
  cancelBtn?.addEventListener('click', closePopup);
  popup?.addEventListener('click', e => { if (e.target === popup) closePopup(); });

  document.querySelectorAll('.toggle-pw').forEach(btn => {
    btn.addEventListener('click', () => {
      const inp = document.getElementById(btn.dataset.target);
      if (!inp) return;
      inp.type = inp.type === 'password' ? 'text' : 'password';
      btn.textContent = inp.type === 'password' ? '👁' : '🙈';
    });
  });

  function checkReady() {
    if (submitBtn) {
      submitBtn.disabled = !(input?.value === 'DELETE' && pwInput?.value?.length > 0);
    }
  }

  input?.addEventListener('input',   checkReady);
  pwInput?.addEventListener('input', checkReady);
})();
</script>

<?php endif; ?>

<!-- ══════════════════════════════════════════════════════
     STATS MODAL
══════════════════════════════════════════════════════ -->
<div id="stats-modal" class="stats-overlay" role="dialog" aria-modal="true" aria-labelledby="stats-title">
  <div class="stats-container">

    <div class="stats-header">
      <div class="stats-header-left">
        <span class="stats-icon">📊</span>
        <div>
          <h2 class="stats-title" id="stats-title">Link Statistics</h2>
          <p class="stats-subtitle" id="stats-subtitle">Loading…</p>
        </div>
      </div>
      <button class="stats-close" id="stats-close" aria-label="Close">✕</button>
    </div>

    <div class="stats-periods">
      <button class="period-btn" data-period="1d">24h</button>
      <button class="period-btn active" data-period="7d">7 days</button>
      <button class="period-btn" data-period="30d">30 days</button>
      <button class="period-btn" data-period="180d">6 months</button>
    </div>

    <div class="stats-loading" id="stats-loading">
      <div class="stats-spinner"></div>
      <p>Loading statistics…</p>
    </div>

    <div class="stats-error" id="stats-error" style="display:none">
      <p>⚠ Could not load statistics. Please try again.</p>
    </div>

    <div class="stats-content" id="stats-content" style="display:none">
      <div class="stats-left">
        <div class="stats-kpi-row">
          <div class="stats-kpi">
            <span class="stats-kpi-number" id="stat-period-clicks">—</span>
            <span class="stats-kpi-label">Clicks (period)</span>
          </div>
          <div class="stats-kpi">
            <span class="stats-kpi-number" id="stat-unique">—</span>
            <span class="stats-kpi-label">Unique visitors</span>
          </div>
          <div class="stats-kpi">
            <span class="stats-kpi-number" id="stat-total">—</span>
            <span class="stats-kpi-label">Total clicks</span>
          </div>
        </div>
        <div class="stats-section">
          <h3 class="stats-section-title">Top countries</h3>
          <div id="stats-countries" class="stats-bars"></div>
        </div>
        <div class="stats-section">
          <h3 class="stats-section-title">Traffic sources</h3>
          <div id="stats-referrers" class="stats-bars"></div>
        </div>
        <div class="stats-section">
          <h3 class="stats-section-title">Top cities</h3>
          <div id="stats-cities" class="stats-bars"></div>
        </div>
      </div>
      <div class="stats-right">
        <h3 class="stats-section-title">Clicks over time</h3>
        <div class="stats-chart-wrap">
          <canvas id="stats-chart"></canvas>
        </div>
      </div>
    </div>

  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// ── Copy link ──────────────────────────────────────────────────────────────
function copyLink(url, btn) {
  navigator.clipboard.writeText(url).then(() => {
    const orig = btn.textContent;
    btn.textContent = '✓';
    btn.classList.add('copied');
    setTimeout(() => { btn.textContent = orig; btn.classList.remove('copied'); }, 2000);
  });
}

// ── Stats modal ────────────────────────────────────────────────────────────
(function () {
  const modal      = document.getElementById('stats-modal');
  const closeBtn   = document.getElementById('stats-close');
  const loading    = document.getElementById('stats-loading');
  const errorDiv   = document.getElementById('stats-error');
  const content    = document.getElementById('stats-content');
  const subtitle   = document.getElementById('stats-subtitle');
  const periodBtns = document.querySelectorAll('.period-btn');

  let currentCode   = null;
  let currentPeriod = '7d';
  let chartInstance = null;

  window.openStats = function (code) {
    currentCode = code;
    modal.classList.add('visible');
    document.body.style.overflow = 'hidden';
    loadStats(code, currentPeriod);
  };

  function closeModal() {
    modal.classList.remove('visible');
    document.body.style.overflow = '';
  }

  closeBtn.addEventListener('click', closeModal);
  modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

  periodBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      periodBtns.forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      currentPeriod = btn.dataset.period;
      if (currentCode) loadStats(currentCode, currentPeriod);
    });
  });

  async function loadStats(code, period) {
    loading.style.display  = 'flex';
    content.style.display  = 'none';
    errorDiv.style.display = 'none';
    try {
      const res  = await fetch(`/LinkVault/api/stats.php?code=${encodeURIComponent(code)}&period=${period}`);
      const data = await res.json();
      if (!res.ok || data.error) throw new Error(data.error || 'Failed');
      renderStats(data);
      loading.style.display = 'none';
      content.style.display = 'flex';
    } catch (e) {
      loading.style.display  = 'none';
      errorDiv.style.display = 'block';
    }
  }

  function renderStats(data) {
    const url = data.link.original_url;
    subtitle.textContent = url.length > 60 ? url.substring(0, 60) + '…' : url;
    document.getElementById('stat-period-clicks').textContent = data.period.clicks.toLocaleString();
    document.getElementById('stat-unique').textContent        = data.period.unique_visitors.toLocaleString();
    document.getElementById('stat-total').textContent         = data.link.total_clicks.toLocaleString();
    renderBars('stats-countries', data.countries, 'country',  data.period.clicks);
    renderBars('stats-referrers', data.referrers, 'source',   data.period.clicks);
    renderBars('stats-cities',    data.cities,    'city',     data.period.clicks);
    renderChart(data.chart);
  }

  function renderBars(containerId, items, labelKey, total) {
    const container = document.getElementById(containerId);
    if (!items || items.length === 0) {
      container.innerHTML = '<p class="stats-empty">No data for this period</p>';
      return;
    }
    const max = Math.max(...items.map(i => parseInt(i.count)));
    container.innerHTML = items.map(item => {
      const pct  = max > 0 ? Math.round((item.count / max) * 100) : 0;
      const vpct = total > 0 ? ((item.count / total) * 100).toFixed(1) : 0;
      return `
        <div class="stats-bar-row">
          <span class="stats-bar-label">${escHtml(item[labelKey] || 'Unknown')}</span>
          <div class="stats-bar-track">
            <div class="stats-bar-fill" style="width:${pct}%"></div>
          </div>
          <span class="stats-bar-count">${item.count} <span class="stats-bar-pct">${vpct}%</span></span>
        </div>`;
    }).join('');
  }

  function renderChart(chart) {
    const ctx = document.getElementById('stats-chart').getContext('2d');
    if (chartInstance) chartInstance.destroy();
    chartInstance = new Chart(ctx, {
      type: 'line',
      data: {
        labels: chart.labels.map(d => {
          const dt = new Date(d);
          return dt.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
        }),
        datasets: [{
          label: 'Clicks',
          data: chart.data,
          borderColor: '#00C2A8',
          backgroundColor: 'rgba(0,194,168,0.08)',
          borderWidth: 2,
          pointBackgroundColor: '#00C2A8',
          pointRadius: 3,
          pointHoverRadius: 5,
          fill: true,
          tension: 0.4,
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { intersect: false, mode: 'index' },
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: '#18181B',
            borderColor: '#27272A',
            borderWidth: 1,
            titleColor: '#FAFAFA',
            bodyColor: '#71717A',
            padding: 10,
          }
        },
        scales: {
          x: {
            grid: { color: '#27272A' },
            ticks: { color: '#71717A', font: { size: 11 }, maxTicksLimit: 8 }
          },
          y: {
            grid: { color: '#27272A' },
            ticks: { color: '#71717A', font: { size: 11 }, stepSize: 1 },
            beginAtZero: true,
          }
        }
      }
    });
  }

  function escHtml(str) {
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
})();

// ── Toast-uri din redirect params ─────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  const params    = new URLSearchParams(window.location.search);
  const toastType = params.get('toast');

  if (toastType === 'link_deleted') {
    showToast('Link deleted successfully.', 'success');
  }

  // Settings success (din POST redirect)
  <?php if ($settingsSuccess === 'username_updated'): ?>
    showToast('Username updated successfully.', 'success');
  <?php elseif ($settingsSuccess === 'password_updated'): ?>
    showToast('Password updated successfully.', 'success');
  <?php endif; ?>

  // Welcome dupa register
  <?php if ($welcome): ?>
    showToast('Welcome to LinkVault, <?= addslashes(htmlspecialchars($user['username'])) ?>! 🎉', 'success', 5000);
  <?php endif; ?>

  // Curata parametrii toast din URL fara reload
  if (toastType) {
    const url = new URL(window.location);
    url.searchParams.delete('toast');
    history.replaceState({}, '', url);
  }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>