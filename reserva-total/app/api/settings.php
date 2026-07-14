<?php
require_once '../config.php';
$me     = rtRequireAdmin();
$db     = rtDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ── GET settings ──────────────────────────────────────────────
if ($method === 'GET' && $action === 'get') {
    $keys = ['smtp_host','smtp_port','smtp_user','smtp_pass','smtp_from_name','smtp_enabled'];
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $st = $db->prepare("SELECT meta_key, meta_value FROM rt_settings WHERE meta_key IN ($placeholders)");
    $st->execute($keys);
    $rows = $st->fetchAll();
    $data = [];
    foreach ($rows as $r) $data[$r['meta_key']] = $r['meta_value'];
    // Mask password
    if (isset($data['smtp_pass'])) $data['smtp_pass_set'] = true;
    unset($data['smtp_pass']);
    rtOut(['settings' => $data]);
}

// ── POST save ────────────────────────────────────────────────
if ($method === 'POST' && $action === 'save') {
    $b = json_decode(file_get_contents('php://input'), true);
    $allowed = ['smtp_host','smtp_port','smtp_user','smtp_pass','smtp_from_name','smtp_enabled'];
    foreach ($allowed as $key) {
        if (!array_key_exists($key, $b)) continue;
        if ($key === 'smtp_pass' && $b[$key] === '') continue; // no sobreescribir si vacío
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

// ── Helpers ───────────────────────────────────────────────────
function getSmtpConfig(PDO $db) {
    $st = $db->query("SELECT meta_key,meta_value FROM rt_settings WHERE meta_key LIKE 'smtp_%'");
    $cfg = [];
    foreach ($st->fetchAll() as $r) $cfg[$r['meta_key']] = $r['meta_value'];
    return [
        'host'      => $cfg['smtp_host']      ?? 'smtp.gmail.com',
        'port'      => intval($cfg['smtp_port'] ?? 587),
        'user'      => $cfg['smtp_user']      ?? '',
        'pass'      => $cfg['smtp_pass']      ?? '',
        'from_name' => $cfg['smtp_from_name'] ?? 'Reserva Total',
        'enabled'   => ($cfg['smtp_enabled']  ?? '0') === '1',
    ];
}

function sendSmtp($to, $toName, $subject, $htmlBody, array $cfg) {
    if (!$cfg['user'] || !$cfg['pass']) return 'SMTP no configurado (usuario/contraseña vacíos)';
    $host = $cfg['host'];
    $port = $cfg['port'];
    $ssl  = ($port === 465) ? 'ssl://' : '';
    $ctx  = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $sock = @stream_socket_client("{$ssl}{$host}:{$port}", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$sock) return "No se pudo conectar a {$host}:{$port} — {$errstr}";

    $rd = function() use ($sock) { return fgets($sock, 512); };
    $wr = function($s) use ($sock) { fwrite($sock, $s . "\r\n"); };

    $rd();
    $wr('EHLO localhost');
    while (($l = $rd()) && substr($l, 3, 1) === '-');

    if ($port === 587) {
        $wr('STARTTLS'); $rd();
        stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $wr('EHLO localhost');
        while (($l = $rd()) && substr($l, 3, 1) === '-');
    }

    $wr('AUTH LOGIN'); $rd();
    $wr(base64_encode($cfg['user'])); $rd();
    $wr(base64_encode($cfg['pass']));
    $auth = $rd();
    if (strpos($auth, '235') === false) { fclose($sock); return 'Autenticación fallida — verificá usuario y clave de app'; }

    $wr("MAIL FROM:<{$cfg['user']}>"); $rd();
    $wr("RCPT TO:<{$to}>"); $rd();
    $wr('DATA'); $rd();

    $msg  = "From: =?UTF-8?B?" . base64_encode($cfg['from_name']) . "?= <{$cfg['user']}>\r\n";
    $msg .= "To: =?UTF-8?B?" . base64_encode($toName) . "?= <{$to}>\r\n";
    $msg .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
    $msg .= "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
    $msg .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $msg .= chunk_split(base64_encode($htmlBody)) . "\r\n.\r\n";

    $wr($msg);
    $resp = $rd();
    $wr('QUIT'); fclose($sock);
    if (strpos($resp, '250') === false) return 'Error al enviar: ' . trim($resp);
    return null;
}

rtErr('Acción no encontrada', 404);
