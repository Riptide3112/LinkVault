<?php
/**
 * error.php — Pagină de eroare customizată pentru LinkVault
 * Folosire: include sau redirect din api/redirect.php
 *
 * Parametri GET:
 *   code    = HTTP status code (400, 404, 410, 429)
 *   message = mesaj opțional
 */

session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/security.php';

$allowedCodes = [400, 404, 410, 429];
$code = (int) ($_GET['code'] ?? 404);
if (!in_array($code, $allowedCodes)) $code = 404;

$message = htmlspecialchars(strip_tags($_GET['message'] ?? ''));

$configs = [
    400 => [
        'title'   => 'Bad Request',
        'heading' => 'Invalid link',
        'desc'    => 'The link you followed appears to be malformed or invalid.',
        'icon'    => '⚠',
        'color'   => '#F59E0B',
    ],
    404 => [
        'title'   => 'Not Found',
        'heading' => 'Link not found',
        'desc'    => 'This short link doesn\'t exist or may have been removed.',
        'icon'    => '🔗',
        'color'   => '#00C2A8',
    ],
    410 => [
        'title'   => 'Link Expired',
        'heading' => 'This link has expired',
        'desc'    => 'The link you\'re looking for has passed its expiry date and is no longer active.',
        'icon'    => '⏱',
        'color'   => '#71717A',
    ],
    429 => [
        'title'   => 'Too Many Requests',
        'heading' => 'Slow down!',
        'desc'    => 'You\'ve made too many requests in a short period. Please wait a moment and try again.',
        'icon'    => '🚫',
        'color'   => '#EF4444',
    ],
];

$cfg = $configs[$code];
http_response_code($code);

$pageTitle = $cfg['title'];
require_once __DIR__ . '/../includes/header.php';
?>

<style>
  .error-page {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-height: 60vh;
    text-align: center;
    padding: 40px 16px;
    gap: 0;
  }

  .error-code-wrap {
    position: relative;
    margin-bottom: 32px;
  }

  .error-code {
    font-family: var(--sans);
    font-size: clamp(6rem, 20vw, 10rem);
    font-weight: 800;
    letter-spacing: -0.05em;
    line-height: 1;
    color: transparent;
    -webkit-text-stroke: 2px <?= $cfg['color'] ?>;
    opacity: 0.18;
    user-select: none;
    animation: fadeIn 0.6s ease both;
  }

  .error-icon {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    font-size: clamp(2.5rem, 8vw, 4rem);
    animation: iconPop 0.5s cubic-bezier(0.34,1.56,0.64,1) 0.2s both;
  }

  .error-heading {
    font-family: var(--sans);
    font-size: clamp(1.3rem, 4vw, 1.8rem);
    font-weight: 800;
    letter-spacing: -0.03em;
    color: var(--text);
    margin-bottom: 12px;
    animation: fadeUp 0.5s ease 0.15s both;
  }

  .error-desc {
    font-size: 0.82rem;
    color: var(--muted);
    max-width: 380px;
    line-height: 1.7;
    margin-bottom: 32px;
    animation: fadeUp 0.5s ease 0.25s both;
  }

  .error-http {
    display: inline-block;
    font-size: 0.6rem;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    color: var(--muted2);
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: 4px;
    padding: 3px 10px;
    margin-bottom: 28px;
    animation: fadeUp 0.5s ease 0.3s both;
  }

  .error-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    justify-content: center;
    animation: fadeUp 0.5s ease 0.35s both;
  }

  .error-btn-primary {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 12px 24px;
    background: var(--accent);
    color: #000;
    border-radius: var(--radius);
    font-family: var(--sans);
    font-size: 0.85rem;
    font-weight: 700;
    text-decoration: none;
    transition: opacity 0.2s, box-shadow 0.2s;
  }

  .error-btn-primary:hover {
    opacity: 0.9;
    box-shadow: var(--accent-glow);
  }

  .error-btn-secondary {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 11px 20px;
    background: transparent;
    color: var(--muted);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    font-family: var(--mono);
    font-size: 0.78rem;
    text-decoration: none;
    transition: border-color 0.2s, color 0.2s;
  }

  .error-btn-secondary:hover {
    border-color: var(--border2);
    color: var(--text);
  }

  .error-divider {
    width: 1px;
    height: 40px;
    background: var(--border);
    margin: 32px auto;
    animation: fadeIn 0.5s ease 0.4s both;
  }

  .error-suggestion {
    animation: fadeUp 0.5s ease 0.45s both;
  }

  .error-suggestion-label {
    font-size: 0.62rem;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--muted2);
    margin-bottom: 14px;
  }

  .error-shorten-form {
    display: flex;
    gap: 8px;
    max-width: 420px;
    width: 100%;
  }

  .error-shorten-form input {
    flex: 1;
    font-size: 0.82rem;
    padding: 10px 14px;
  }

  .error-shorten-form .btn {
    width: auto;
    padding: 10px 18px;
    font-size: 0.82rem;
    white-space: nowrap;
  }

  @keyframes fadeIn {
    from { opacity: 0; }
    to   { opacity: 1; }
  }

  @keyframes fadeUp {
    from { opacity: 0; transform: translateY(12px); }
    to   { opacity: 1; transform: translateY(0); }
  }

  @keyframes iconPop {
    from { opacity: 0; transform: translate(-50%, -50%) scale(0.5); }
    to   { opacity: 1; transform: translate(-50%, -50%) scale(1); }
  }
</style>

<div class="error-page">

  <div class="error-code-wrap">
    <div class="error-code"><?= $code ?></div>
    <div class="error-icon"><?= $cfg['icon'] ?></div>
  </div>

  <h1 class="error-heading"><?= $cfg['heading'] ?></h1>

  <p class="error-desc">
    <?= $message ?: $cfg['desc'] ?>
  </p>

  <span class="error-http">HTTP <?= $code ?></span>

  <div class="error-actions">
    <a href="/LinkVault/" class="error-btn-primary">⚡ Shorten a link</a>
    <?php if (isset($_SESSION['user_id'])): ?>
      <a href="/LinkVault/pages/dashboard.php" class="error-btn-secondary">⚙ Dashboard</a>
    <?php else: ?>
      <a href="javascript:history.back()" class="error-btn-secondary">← Go back</a>
    <?php endif; ?>
  </div>

  <?php if ($code === 404 || $code === 410): ?>
  <div class="error-divider"></div>

  <div class="error-suggestion">
    <p class="error-suggestion-label">Want to shorten a URL?</p>
    <form class="error-shorten-form" action="/LinkVault/" method="GET">
      <input type="url" name="url" placeholder="https://example.com/..." autocomplete="off">
      <button type="submit" class="btn">Shorten</button>
    </form>
  </div>
  <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>