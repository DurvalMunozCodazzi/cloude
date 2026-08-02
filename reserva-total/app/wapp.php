<?php
// ── Envío de WhatsApp vía Cloud API oficial de Meta ─────────────────────────
// Mismo estilo minimalista que smtp.php: sin librerías, cURL directo.
// Solo se usa si el admin cargó token + phone_number_id en la configuración;
// si no, los recordatorios de WhatsApp quedan en modo manual (botones wa.me).

function getWappConfig(PDO $db) {
    $keys = ['wapp_enabled','wapp_token','wapp_phone_id'];
    $ph = implode(',', array_fill(0, count($keys), '?'));
    $st = $db->prepare("SELECT meta_key, meta_value FROM rt_settings WHERE meta_key IN ($ph)");
    $st->execute($keys);
    $cfg = ['enabled' => false, 'token' => '', 'phone_id' => ''];
    foreach ($st->fetchAll() as $r) {
        if ($r['meta_key'] === 'wapp_enabled')  $cfg['enabled']  = $r['meta_value'] === '1';
        if ($r['meta_key'] === 'wapp_token')    $cfg['token']    = trim($r['meta_value']);
        if ($r['meta_key'] === 'wapp_phone_id') $cfg['phone_id'] = trim($r['meta_value']);
    }
    return $cfg;
}

// Normaliza un teléfono argentino a formato internacional para WhatsApp:
// dígitos solos, sin 0 inicial ni "15", con 549 adelante si no trae código.
function rtWappPhone($raw) {
    $d = preg_replace('/\D+/', '', (string) $raw);
    if (!$d) return '';
    if (strpos($d, '549') === 0) return $d;
    if (strpos($d, '54')  === 0) return '549' . substr($d, 2);
    if (strpos($d, '0')   === 0) $d = substr($d, 1);
    // El "15" viene después del código de área (2 a 4 dígitos): quitarlo
    // hasta quedar en los 10 dígitos de área+número.
    if (strlen($d) === 12) {
        for ($i = 2; $i <= 4; $i++) {
            if (substr($d, $i, 2) === '15') { $d = substr($d, 0, $i) . substr($d, $i + 2); break; }
        }
    }
    return '549' . $d;
}

// Devuelve null si se envió OK, o un string con el error.
function sendWhatsApp($toPhone, $message, array $cfg) {
    if (!$cfg['enabled'] || !$cfg['token'] || !$cfg['phone_id']) {
        return 'WhatsApp automático no configurado';
    }
    $to = rtWappPhone($toPhone);
    if (!$to) return 'Teléfono inválido';

    $url  = 'https://graph.facebook.com/v19.0/' . rawurlencode($cfg['phone_id']) . '/messages';
    $body = json_encode([
        'messaging_product' => 'whatsapp',
        'to'                => $to,
        'type'              => 'text',
        'text'              => ['body' => $message],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $cfg['token'],
            'Content-Type: application/json',
        ],
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) return 'Error de conexión: ' . $err;
    if ($code >= 300) {
        $data = json_decode($resp, true);
        return 'HTTP ' . $code . ': ' . ($data['error']['message'] ?? substr($resp, 0, 200));
    }
    return null;
}
