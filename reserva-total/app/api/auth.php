<?php
require_once '../config.php';
require_once '../smtp.php';
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

// ── POST olvidé mi contraseña — envía link de recuperación por email ──
if ($method === 'POST' && $action === 'forgot_password') {
    $b          = json_decode(file_get_contents('php://input'), true);
    $identifier = trim($b['identifier'] ?? '');
    // Mensaje genérico siempre — no revela si el usuario existe o no
    $generic = ['ok' => true, 'message' => 'Si el usuario existe, te enviamos un email con instrucciones.'];
    if (!$identifier) rtOut($generic);

    $st = $db->prepare("SELECT * FROM rt_users WHERE (username=? OR email=?) AND active=1 LIMIT 1");
    $st->execute([$identifier, $identifier]);
    $row = $st->fetch();
    if (!$row || !$row['email']) rtOut($generic);

    $token   = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + 30 * 60);
    $db->prepare("UPDATE rt_users SET reset_token=?, reset_expires=? WHERE id=?")
       ->execute([$token, $expires, $row['id']]);

    $link = rtrim(RT_SITE_URL, '/') . '/index.html?reset_token=' . $token;
    $body = "<p>Hola " . htmlspecialchars($row['name']) . ",</p>"
          . "<p>Pediste restablecer tu contraseña de Reserva Total. Este enlace vale por 30 minutos:</p>"
          . "<p><a href='" . htmlspecialchars($link) . "'>" . htmlspecialchars($link) . "</a></p>"
          . "<p>Si no fuiste vos, ignorá este mensaje.</p>";

    $cfg = getSmtpConfig($db);
    sendSmtp($row['email'], $row['name'], 'Recuperar contraseña — Reserva Total', $body, $cfg);
    // No exponemos el resultado del envío al cliente (mismo motivo: no filtrar info)
    rtOut($generic);
}

// ── POST restablecer contraseña con el token del email ─────────────────
if ($method === 'POST' && $action === 'reset_password') {
    $b     = json_decode(file_get_contents('php://input'), true);
    $token = trim($b['token'] ?? '');
    $pass  = trim($b['password'] ?? '');
    if (!$token) rtErr('Token requerido');
    if (strlen($pass) < 4) rtErr('La contraseña debe tener al menos 4 caracteres');

    $st = $db->prepare("SELECT id FROM rt_users WHERE reset_token=? AND reset_expires>NOW() AND active=1 LIMIT 1");
    $st->execute([$token]);
    $row = $st->fetch();
    if (!$row) rtErr('El enlace es inválido o ya venció. Pedí uno nuevo.', 400);

    $db->prepare("UPDATE rt_users SET password=?, reset_token=NULL, reset_expires=NULL WHERE id=?")
       ->execute([password_hash($pass, PASSWORD_DEFAULT), $row['id']]);
    rtOut(['ok' => true]);
}

rtErr('Acción no encontrada', 404);
