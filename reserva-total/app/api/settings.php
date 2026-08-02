<?php
require_once '../config.php';
require_once '../smtp.php';
$me     = rtRequireAdmin();
$db     = rtDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ── GET settings ──────────────────────────────────────────────
if ($method === 'GET' && $action === 'get') {
    $keys = ['smtp_host','smtp_port','smtp_user','smtp_pass','smtp_from_name','smtp_enabled','notify_email',
             'wapp_enabled','wapp_token','wapp_phone_id',
             'mp_enabled','mp_access_token','mp_deposit_pct'];
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $st = $db->prepare("SELECT meta_key, meta_value FROM rt_settings WHERE meta_key IN ($placeholders)");
    $st->execute($keys);
    $rows = $st->fetchAll();
    $data = [];
    foreach ($rows as $r) $data[$r['meta_key']] = $r['meta_value'];
    // Enmascarar credenciales sensibles
    if (isset($data['smtp_pass'])) $data['smtp_pass_set'] = true;
    unset($data['smtp_pass']);
    if (isset($data['wapp_token'])) $data['wapp_token_set'] = true;
    unset($data['wapp_token']);
    if (isset($data['mp_access_token'])) $data['mp_token_set'] = true;
    unset($data['mp_access_token']);
    rtOut(['settings' => $data]);
}

// ── POST save ────────────────────────────────────────────────
if ($method === 'POST' && $action === 'save') {
    $b = json_decode(file_get_contents('php://input'), true);
    $allowed = ['smtp_host','smtp_port','smtp_user','smtp_pass','smtp_from_name','smtp_enabled','notify_email',
                'wapp_enabled','wapp_token','wapp_phone_id',
                'mp_enabled','mp_access_token','mp_deposit_pct'];
    foreach ($allowed as $key) {
        if (!array_key_exists($key, $b)) continue;
        if (in_array($key, ['smtp_pass','wapp_token','mp_access_token']) && $b[$key] === '') continue; // no sobreescribir si vacío
        $db->prepare("INSERT INTO rt_settings (meta_key,meta_value) VALUES (?,?)
                      ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)")
           ->execute([$key, $b[$key]]);
    }
    rtOut(['ok' => true]);
}

// ── POST test SMTP ────────────────────────────────────────────
if ($method === 'POST' && $action === 'test_smtp') {
    $b    = json_decode(file_get_contents('php://input'), true);
    $to   = trim($b['to'] ?? $me['email'] ?? '');
    if (!$to) rtErr('Ingresá un email de destino para la prueba');

    $cfg  = getSmtpConfig($db);
    $body = '<p>Si recibís este email, la configuración SMTP de <strong>Reserva Total</strong> funciona correctamente.</p>';
    $err  = sendSmtp($to, $to, 'Prueba SMTP — Reserva Total', $body, $cfg);
    if ($err) rtErr('Error SMTP: ' . $err);
    rtOut(['ok' => true, 'email' => $to]);
}

rtErr('Acción no encontrada', 404);
