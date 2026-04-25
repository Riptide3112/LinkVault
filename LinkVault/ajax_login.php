<?php
session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/security.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$username = trim($input['username'] ?? '');
$password = $input['password'] ?? '';

// CSRF vine din header X-CSRF-Token (trimis de JS)
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($csrfToken) || !hash_equals(csrf_token(), $csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid request.']);
    exit;
}

if (empty($username) || empty($password)) {
    echo json_encode(['success' => false, 'error' => 'Missing credentials.']);
    exit;
}

$ip    = get_client_ip();
$rlKey = 'login:' . hash('sha256', $ip);

if (rate_limit_check($pdo, $rlKey, 10, 300)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many attempts. Please wait 5 minutes.']);
    exit;
}

$stmt = $pdo->prepare(
    "SELECT id, username, email, password_hash FROM users WHERE email = ? OR username = ? LIMIT 1"
);
$stmt->execute([$username, $username]);
$user = $stmt->fetch();

if ($user && password_verify($password, $user['password_hash'])) {
    rate_limit_reset($pdo, $rlKey);
    session_regenerate_id(true);
    $_SESSION['user_id']  = $user['id'];
    $_SESSION['username'] = $user['username'];
    echo json_encode(['success' => true]);
} else {
    rate_limit_hit($pdo, $rlKey, 300);
    usleep(300_000);
    echo json_encode(['success' => false, 'error' => 'Invalid email/username or password.']);
}