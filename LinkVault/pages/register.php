<?php
session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: /LinkVault/pages/dashboard.php');
    exit;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/security.php';

$errors = [];
$values = ['username' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    // Rate limiting: max 5 inregistrari / ora per IP
    $ip    = get_client_ip();
    $rlKey = 'register:' . hash('sha256', $ip);

    if (rate_limit_check($pdo, $rlKey, 5, 3600)) {
        $errors['general'] = 'Too many registration attempts. Please try again later.';
    } else {
        $username  = trim($_POST['username'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $password  = $_POST['password'] ?? '';
        $password2 = $_POST['password2'] ?? '';

        $values = ['username' => $username, 'email' => $email];

        if (empty($username)) {
            $errors['username'] = 'Username is required.';
        } elseif (strlen($username) < 3 || strlen($username) > 32) {
            $errors['username'] = 'Username must be between 3 and 32 characters.';
        } elseif (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
            $errors['username'] = 'Only letters, numbers, underscores, dots and hyphens allowed.';
        }

        if (empty($email)) {
            $errors['email'] = 'Email is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Please enter a valid email address.';
        } elseif (strlen($email) > 255) {
            $errors['email'] = 'Email address is too long.';
        }

        if (empty($password)) {
            $errors['password'] = 'Password is required.';
        } elseif (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters.';
        }

        if (empty($errors['password']) && $password !== $password2) {
            $errors['password2'] = 'Passwords do not match.';
        }

        // Verificare unicitate — mesaj generic pentru a nu permite user enumeration
        if (empty($errors)) {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
            $stmt->execute([$username, $email]);
            if ($stmt->fetch()) {
                // Mesaj deliberat vag — nu revelam care camp e duplicat
                $errors['general'] = 'An account with this username or email already exists.';
            }
        }

        if (empty($errors)) {
            rate_limit_hit($pdo, $rlKey, 3600);

            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            try {
                $stmt = $pdo->prepare(
                    "INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)"
                );
                $stmt->execute([$username, $email, $hash]);
            } catch (PDOException $e) {
                // Cod 23000 = Integrity constraint violation (UNIQUE duplicate)
                if ($e->getCode() === '23000') {
                    $errors['general'] = 'An account with this username or email already exists.';
                } else {
                    $errors['general'] = 'Registration failed. Please try again.';
                }
            }

            if (empty($errors)) {
                $newId = $pdo->lastInsertId();
                session_regenerate_id(true);
                $_SESSION['user_id']  = $newId;
                $_SESSION['username'] = $username;

                header('Location: /LinkVault/pages/dashboard.php?welcome=1');
                exit;
            }
        }
    }
}

$pageTitle = 'Register';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="hero">
  <h1>Create your <span>account</span></h1>
  <p>Free forever. No credit card required.</p>
</div>

<div class="card auth-card">

  <?php if (isset($errors['general'])): ?>
    <div class="alert-error"><span>⚠</span> <?= htmlspecialchars($errors['general']) ?></div>
  <?php endif; ?>

  <form method="POST" action="" novalidate id="register-form">
    <?= csrf_field() ?>

    <div class="form-group <?= isset($errors['username']) ? 'has-error' : '' ?>">
      <label for="username">Username</label>
      <input type="text" id="username" name="username"
        placeholder="your_username"
        value="<?= htmlspecialchars($values['username']) ?>"
        autocomplete="username" maxlength="32" required>
      <?php if (isset($errors['username'])): ?>
        <span class="field-error"><?= htmlspecialchars($errors['username']) ?></span>
      <?php endif; ?>
    </div>

    <div class="form-group <?= isset($errors['email']) ? 'has-error' : '' ?>">
      <label for="email">Email</label>
      <input type="email" id="email" name="email"
        placeholder="you@example.com"
        value="<?= htmlspecialchars($values['email']) ?>"
        autocomplete="email" required>
      <?php if (isset($errors['email'])): ?>
        <span class="field-error"><?= htmlspecialchars($errors['email']) ?></span>
      <?php endif; ?>
    </div>

    <div class="form-group <?= isset($errors['password']) ? 'has-error' : '' ?>">
      <label for="password">Password</label>
      <div class="input-wrap">
        <input type="password" id="password" name="password"
          placeholder="Minimum 8 characters"
          autocomplete="new-password" required>
        <button type="button" class="toggle-pw" data-target="password" aria-label="Toggle password visibility">👁</button>
      </div>
      <?php if (isset($errors['password'])): ?>
        <span class="field-error"><?= htmlspecialchars($errors['password']) ?></span>
      <?php endif; ?>
      <div class="pw-strength"><div class="pw-strength-bar" id="pw-strength-bar"></div></div>
      <span class="pw-strength-label" id="pw-strength-label"></span>
    </div>

    <div class="form-group <?= isset($errors['password2']) ? 'has-error' : '' ?>">
      <label for="password2">Confirm password</label>
      <div class="input-wrap">
        <input type="password" id="password2" name="password2"
          placeholder="Repeat your password"
          autocomplete="new-password" required>
        <button type="button" class="toggle-pw" data-target="password2" aria-label="Toggle password visibility">👁</button>
      </div>
      <?php if (isset($errors['password2'])): ?>
        <span class="field-error"><?= htmlspecialchars($errors['password2']) ?></span>
      <?php endif; ?>
    </div>

    <button type="submit" class="btn" id="register-btn">Create account →</button>
  </form>

  <div class="auth-footer">
    Already have an account? <a href="/LinkVault/pages/login.php">Log in</a>
  </div>

</div>

<script>
(function () {
  document.querySelectorAll('.toggle-pw').forEach(btn => {
    btn.addEventListener('click', () => {
      const input = document.getElementById(btn.dataset.target);
      if (!input) return;
      input.type = input.type === 'password' ? 'text' : 'password';
      btn.textContent = input.type === 'password' ? '👁' : '🙈';
    });
  });

  const pwInput = document.getElementById('password');
  const bar     = document.getElementById('pw-strength-bar');
  const lbl     = document.getElementById('pw-strength-label');

  function scorePassword(pw) {
    let s = 0;
    if (!pw) return 0;
    if (pw.length >= 8)  s++;
    if (pw.length >= 12) s++;
    if (pw.length >= 16) s++;
    if (/[a-z]/.test(pw)) s++;
    if (/[A-Z]/.test(pw)) s++;
    if (/[0-9]/.test(pw)) s++;
    if (/[^a-zA-Z0-9]/.test(pw)) s++;
    return s;
  }

  const levels = [
    { min: 0, color: 'transparent', text: '' },
    { min: 1, color: '#EF4444',     text: 'Weak' },
    { min: 3, color: '#F59E0B',     text: 'Fair' },
    { min: 5, color: '#00C2A8',     text: 'Strong' },
    { min: 7, color: '#00C2A8',     text: 'Very strong' },
  ];

  if (pwInput && bar && lbl) {
    pwInput.addEventListener('input', () => {
      const score = scorePassword(pwInput.value);
      const level = [...levels].reverse().find(l => score >= l.min) || levels[0];
      const pct   = pwInput.value.length === 0 ? 0 : Math.min(100, (score / 7) * 100);
      bar.style.width      = pct + '%';
      bar.style.background = level.color;
      lbl.textContent      = level.text;
      lbl.style.color      = level.color;
    });
  }

  const pw2 = document.getElementById('password2');
  if (pwInput && pw2) {
    const check = () => {
      if (!pw2.value) { pw2.style.borderColor = ''; return; }
      pw2.style.borderColor = pwInput.value === pw2.value ? 'var(--accent)' : 'var(--red)';
    };
    pw2.addEventListener('input', check);
    pwInput.addEventListener('input', check);
  }

  const form = document.getElementById('register-form');
  const btn  = document.getElementById('register-btn');
  if (form && btn) {
    form.addEventListener('submit', () => {
      btn.disabled    = true;
      btn.textContent = 'Creating account…';
    });
  }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>