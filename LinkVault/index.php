<?php
session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/security.php';

const URL_MAX_LENGTH = 2048;

function generateShortCode(PDO $pdo, int $length = SHORT_CODE_LENGTH, int $maxTries = 10): string {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    for ($try = 0; $try < $maxTries; $try++) {
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $stmt = $pdo->prepare("SELECT id FROM links WHERE short_code = ?");
        $stmt->execute([$code]);
        if (!$stmt->fetch()) return $code;
    }
    return generateShortCode($pdo, $length + 1, $maxTries);
}

function isValidUrl(string $url): bool {
    return strlen($url) <= URL_MAX_LENGTH
        && filter_var($url, FILTER_VALIDATE_URL) !== false
        && preg_match('/^https?:\/\//i', $url);
}

function getPublicStats(PDO $pdo): array {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
    $totalUsers = (int) $stmt->fetchColumn();
    
    $stmt = $pdo->query("
        SELECT COUNT(*) as count FROM links 
        WHERE expires_at IS NULL OR expires_at > NOW()
    ");
    $activeLinks = (int) $stmt->fetchColumn();
    
    $stmt = $pdo->query("
        SELECT COUNT(DISTINCT ip_hash) as count FROM link_clicks
    ");
    $totalVisitors = (int) $stmt->fetchColumn();
    
    return [
        'total_users' => $totalUsers,
        'active_links' => $activeLinks,
        'total_visitors' => $totalVisitors,
    ];
}

function getLatestLinks(PDO $pdo, int $limit = 10): array {
    $stmt = $pdo->prepare("
        SELECT 
            l.id,
            l.short_code,
            l.original_url,
            l.clicks,
            l.expires_at,
            l.created_at,
            u.username
        FROM links l
        LEFT JOIN users u ON l.user_id = u.id
        ORDER BY l.created_at DESC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    $links = $stmt->fetchAll();
    
    foreach ($links as &$link) {
        if (strlen($link['original_url']) > 65) {
            $link['original_url_short'] = substr($link['original_url'], 0, 62) . '...';
        } else {
            $link['original_url_short'] = $link['original_url'];
        }
        
        $link['display_user'] = $link['username'] ?? 'Anonymous';
        $link['is_expired'] = $link['expires_at'] && strtotime($link['expires_at']) < time();
        $link['created_formatted'] = date('d M Y, H:i', strtotime($link['created_at']));
        $link['short_url'] = BASE_URL . '/' . $link['short_code'];
        
        if ($link['expires_at'] && !$link['is_expired']) {
            $link['expires_formatted'] = date('d M Y', strtotime($link['expires_at']));
            $link['expires_status'] = 'expires';
        } elseif ($link['is_expired']) {
            $link['expires_status'] = 'expired';
        } else {
            $link['expires_status'] = 'permanent';
        }
    }
    
    return $links;
}

$result   = null;
$error    = null;
$loggedIn = isset($_SESSION['user_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $ip    = get_client_ip();
    $rlKey = 'shorten:' . ($loggedIn ? 'u' . $_SESSION['user_id'] : hash('sha256', $ip));
    $limit = $loggedIn ? 60 : 20;

    if (rate_limit_check($pdo, $rlKey, $limit, 60)) {
        $error = 'Too many requests. Please slow down.';
    } else {
        rate_limit_hit($pdo, $rlKey, 60);

        $originalUrl = trim($_POST['url'] ?? '');
        $expiresIn   = intval($_POST['expires_in'] ?? 1);

        if (!$loggedIn && $expiresIn !== 1) {
            $expiresIn = 1;
        }

        if (empty($originalUrl)) {
            $error = 'Please enter a URL.';
        } elseif (!isValidUrl($originalUrl)) {
            $error = 'Invalid URL. Make sure it starts with http:// or https://';
        } else {
            $userId = $_SESSION['user_id'] ?? null;

            if ($userId) {
                $stmt = $pdo->prepare("
                    SELECT * FROM links
                    WHERE original_url = ? AND user_id = ?
                      AND (expires_at IS NULL OR expires_at > NOW())
                    LIMIT 1
                ");
                $stmt->execute([$originalUrl, $userId]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT * FROM links
                    WHERE original_url = ? AND user_id IS NULL
                      AND (expires_at IS NULL OR expires_at > NOW())
                    LIMIT 1
                ");
                $stmt->execute([$originalUrl]);
            }

            $existing = $stmt->fetch();

            if ($existing) {
                $result = $existing;
            } else {
                $shortCode = generateShortCode($pdo);
                $expiresAt = $expiresIn > 0
                    ? date('Y-m-d H:i:s', strtotime("+{$expiresIn} days"))
                    : null;

                $stmt = $pdo->prepare("
                    INSERT INTO links (short_code, original_url, expires_at, user_id)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$shortCode, $originalUrl, $expiresAt, $userId]);

                $stmt = $pdo->prepare("SELECT * FROM links WHERE id = ?");
                $stmt->execute([$pdo->lastInsertId()]);
                $result = $stmt->fetch();
            }
        }
    }
}

$publicStats = getPublicStats($pdo);
$latestLinks = getLatestLinks($pdo, 8);

$shortUrl    = $result ? BASE_URL . '/' . $result['short_code'] : '';
$qrUrl       = $shortUrl
    ? 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' . urlencode($shortUrl)
    : '';
$forceShow   = ($_SERVER['REQUEST_METHOD'] === 'POST') ? '1' : '0';
$selectedExp = $loggedIn ? intval($_POST['expires_in'] ?? 1) : 1;

$pageTitle = 'Home';
require_once __DIR__ . '/includes/header.php';
?>

<div class="hero">
  <h1>Shorten your <span>links</span></h1>
  <p>Paste a long URL and get a short, trackable link in seconds.</p>
</div>

<div class="card">

  <?php if ($error): ?>
    <div class="alert-error">
      <span>⚠</span> <?= htmlspecialchars($error) ?>
    </div>
  <?php endif; ?>

  <form method="POST" action="">
    <?= csrf_field() ?>

    <div class="form-group">
      <label for="url">Long URL</label>
      <input
        type="url"
        id="url"
        name="url"
        placeholder="https://example.com/your-very-long-url..."
        value="<?= htmlspecialchars($_POST['url'] ?? '') ?>"
        required
        autocomplete="off"
        maxlength="<?= URL_MAX_LENGTH ?>"
      >
    </div>

    <div
      id="expiry-section"
      class="step-section"
      data-force-show="<?= $forceShow ?>"
      data-selected-exp="<?= $selectedExp ?>"
      style="display:none"
    >
      <label>
        Expires after
        <?php if (!$loggedIn): ?>
          <span class="label-note">— options over 1 day require an account</span>
        <?php endif; ?>
      </label>

      <div class="expiry-buttons">
        <?php
        $options = [
          ['value' => '0',  'label' => 'Never',   'auth' => !$loggedIn],
          ['value' => '1',  'label' => '1 day',   'auth' => false],
          ['value' => '7',  'label' => '7 days',  'auth' => !$loggedIn],
          ['value' => '30', 'label' => '30 days', 'auth' => !$loggedIn],
          ['value' => '90', 'label' => '90 days', 'auth' => !$loggedIn],
        ];
        foreach ($options as $opt):
          $isSelected = ($selectedExp == $opt['value'] && $forceShow === '1');
        ?>
          <button
            type="button"
            class="expiry-btn <?= $isSelected ? 'selected' : '' ?>"
            data-value="<?= $opt['value'] ?>"
            data-requires-auth="<?= $opt['auth'] ? '1' : '0' ?>"
          >
            <?= $opt['label'] ?>
            <?php if ($opt['auth']): ?><span class="btn-lock">⏐</span><?php endif; ?>
          </button>
        <?php endforeach; ?>
      </div>

      <input type="hidden" id="expires_in" name="expires_in" value="<?= $selectedExp ?>">
    </div>

    <div id="submit-section" class="step-section" style="display:none">
      <button type="submit" class="btn" id="submit-btn">⌁ Shorten URL</button>
    </div>

  </form>

  <?php if ($result): ?>
  <div class="result" id="result-box">
    <div class="result-label">⌗ Link generated successfully</div>
    <div class="result-body">
      <div class="result-info">
        <div class="short-url-wrap">
          <a class="short-url" href="<?= htmlspecialchars($shortUrl) ?>" target="_blank" rel="noopener noreferrer" id="shortUrlText">
            <?= htmlspecialchars($shortUrl) ?>
          </a>
          <button class="copy-btn" id="copyBtn">⎘ Copy</button>
        </div>
        <div class="meta">
          <span>⌛ Created: <?= date('d M Y, H:i', strtotime($result['created_at'])) ?></span>
          <?php if ($result['expires_at']): ?>
            <span>⌛ Expires: <?= date('d M Y, H:i', strtotime($result['expires_at'])) ?></span>
          <?php else: ?>
            <span>⌛ Expires: never</span>
          <?php endif; ?>
          <span>⌗ Code: <strong style="color:var(--text)"><?= htmlspecialchars($result['short_code']) ?></strong></span>
        </div>
        <a class="stats-link" href="/LinkVault/pages/dashboard.php?code=<?= urlencode($result['short_code']) ?>">
          ⎊ View statistics →
        </a>
      </div>
      <div class="qr-wrap">
        <img src="<?= htmlspecialchars($qrUrl) ?>" alt="QR Code" loading="lazy" width="84" height="84">
      </div>
    </div>
  </div>
  <?php endif; ?>

</div>

<!-- Public Statistics Section -->
<div class="public-stats-section">
  <div class="public-stats-header">
    <h2 class="public-stats-title">LinkVault in numbers</h2>
    <p class="public-stats-subtitle">Real-time statistics</p>
  </div>

  <div class="public-stats-grid">
    <div class="public-stat-card">
      <div class="public-stat-icon">👥</div>
      <div class="public-stat-number"><?= number_format($publicStats['total_users']) ?></div>
      <div class="public-stat-label">Registered members</div>
    </div>
    <div class="public-stat-card">
      <div class="public-stat-icon">🔗</div>
      <div class="public-stat-number"><?= number_format($publicStats['active_links']) ?></div>
      <div class="public-stat-label">Active links</div>
    </div>
    <div class="public-stat-card">
      <div class="public-stat-icon">👁</div>
      <div class="public-stat-number"><?= number_format($publicStats['total_visitors']) ?></div>
      <div class="public-stat-label">Unique visitors</div>
    </div>
  </div>

  <?php if (!empty($latestLinks)): ?>
  <div class="latest-links-section">
    <div class="latest-links-header">
      <h3 class="latest-links-title">Latest created links</h3>
      <span class="latest-links-badge">◉ Live</span>
    </div>
    
    <div class="latest-links-list">
      <?php foreach ($latestLinks as $link): ?>
        <div class="latest-link-item <?= $link['is_expired'] ? 'expired' : '' ?>">
          <div class="latest-link-main">
            <div class="latest-link-url">
              <span class="latest-link-short"><?= htmlspecialchars($link['short_url']) ?></span>
              <span class="latest-link-arrow">→</span>
              <span class="latest-link-original" title="<?= htmlspecialchars($link['original_url']) ?>">
                <?= htmlspecialchars($link['original_url_short']) ?>
              </span>
            </div>
            <div class="latest-link-meta">
              <span class="meta-clicks">⌗ <?= number_format($link['clicks']) ?> clicks</span>
              <span class="meta-user">⌾ <strong class="username-highlight"><?= htmlspecialchars($link['display_user']) ?></strong></span>
              <span class="meta-time">⌛ <?= $link['created_formatted'] ?></span>
              <?php if ($link['expires_status'] === 'expires'): ?>
                <span class="meta-expires">⌛ Expires: <?= $link['expires_formatted'] ?></span>
              <?php elseif ($link['expires_status'] === 'expired'): ?>
                <span class="meta-expired">⊗ Expired</span>
              <?php else: ?>
                <span class="meta-never">∞ Permanent</span>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<div id="auth-popup" class="popup-overlay" role="dialog" aria-modal="true" aria-labelledby="popup-title">
  <div class="popup">
    <button id="popup-close" class="popup-close" aria-label="Close">✕</button>
    <div class="popup-icon">⏐</div>
    <h3 class="popup-title" id="popup-title">Account required</h3>
    <p class="popup-desc">Links longer than 1 day require a free account. It only takes 30 seconds.</p>
    <div class="popup-actions">
      <a href="/LinkVault/pages/register.php" class="btn-popup-primary">⌂ Create free account</a>
      <a href="/LinkVault/pages/login.php" class="btn-popup-secondary">⏐ Log in</a>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script>
(function() {
    let autoRefreshInterval = null;
    let isRefreshing = false;

    function refreshLatestLinks() {
        if (isRefreshing) return;
        isRefreshing = true;

        fetch('/LinkVault/api/latest_links.php')
            .then(response => response.json())
            .then(data => {
                if (data.success && data.links) {
                    updateLinksContainer(data.links);
                }
            })
            .catch(error => console.error('Auto-refresh failed:', error))
            .finally(() => {
                isRefreshing = false;
            });
    }

    function updateLinksContainer(links) {
        const container = document.querySelector('.latest-links-list');
        if (!container) return;

        if (!links || links.length === 0) {
            container.innerHTML = '<div class="latest-links-empty">No links yet. Be the first to create one!</div>';
            return;
        }

        const baseUrl = '<?= BASE_URL ?>';
        
        container.innerHTML = links.map(link => {
            let expiresHtml = '';
            if (link.expires_status === 'expires') {
                expiresHtml = `<span class="meta-expires">⌛ Expires: ${link.expires_formatted}</span>`;
            } else if (link.expires_status === 'expired') {
                expiresHtml = `<span class="meta-expired">⊗ Expired</span>`;
            } else {
                expiresHtml = `<span class="meta-never">∞ Permanent</span>`;
            }

            return `
                <div class="latest-link-item ${link.is_expired ? 'expired' : ''}">
                    <div class="latest-link-main">
                        <div class="latest-link-url">
                            <span class="latest-link-short">${escapeHtml(link.short_url)}</span>
                            <span class="latest-link-arrow">→</span>
                            <span class="latest-link-original" title="${escapeHtml(link.original_url)}">
                                ${escapeHtml(link.original_url_short)}
                            </span>
                        </div>
                        <div class="latest-link-meta">
                            <span class="meta-clicks">⌗ ${link.clicks.toLocaleString()} clicks</span>
                            <span class="meta-user">⌾ <strong class="username-highlight">${escapeHtml(link.display_user)}</strong></span>
                            <span class="meta-time">⌛ ${escapeHtml(link.created_formatted)}</span>
                            ${expiresHtml}
                        </div>
                    </div>
                </div>
            `;
        }).join('');
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

    if (document.querySelector('.latest-links-list')) {
        autoRefreshInterval = setInterval(refreshLatestLinks, 30000);
    }

    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            if (autoRefreshInterval) clearInterval(autoRefreshInterval);
            autoRefreshInterval = null;
        } else {
            if (!autoRefreshInterval && document.querySelector('.latest-links-list')) {
                refreshLatestLinks();
                autoRefreshInterval = setInterval(refreshLatestLinks, 30000);
            }
        }
    });
})();
</script>