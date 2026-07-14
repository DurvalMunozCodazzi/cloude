<?php
require_once '../config.php';
$db     = rtDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ── POST login ───────────────────────────────────────────────
if ($method === 'POST' && $action === 'login') {
    $b    = json_decode(file_get_contents('php://input'), true);
    $user = trim($b['username'] ?? '');
    $pass = trim($b['password'] ?? '');
    if (!$user || !$pass) rtErr('Usuario y contraseña requeridos');

    $st = $db->prepare("SELECT * FROM rt_users WHERE (username=? OR email=?) AND active=1 LIMIT 1");
    $st->execute([$user, $user]);
    $row = $st->fetch();
    if (!$row || !password_verify($pass, $row['password'])) rtErr('Credenciales incorrectas', 401);

    $token   = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + RT_SESSION_HOURS * 3600);
    $db->prepare("INSERT INTO rt_sessions (user_id, token, expires_at) VALUES (?,?,?)")
       ->execute([$row['id'], $token, $expires]);
    $db->prepare("UPDATE rt_users SET last_login=NOW() WHERE id=?")->execute([$row['id']]);

    unset($row['password']);
    rtOut(['token' => $token, 'user' => $row, 'expires_at' => $expires]);
}

// ── POST logout ──────────────────────────────────────────────
if ($method === 'POST' && $action === 'logout') {
    $token = rtBearerToken();
    if ($token) $db->prepare("DELETE FROM rt_sessions WHERE token=?")->execute([$token]);
    rtOut(['ok' => true]);
}

// ── GET me ───────────────────────────────────────────────────
if ($method === 'GET' && $action === 'me') {
    $user = rtRequireAuth();
    unset($user['password']);
    rtOut(['user' => $user]);
}

rtErr('Acción no encontrada', 404);
