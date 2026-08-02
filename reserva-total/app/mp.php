<?php
// ── Integración Mercado Pago (Checkout Pro) ─────────────────────────────────
// Sin SDK: cURL directo a la API, mismo estilo minimalista que smtp.php/wapp.php.

function getMpConfig(PDO $db) {
    $keys = ['mp_enabled','mp_access_token','mp_deposit_pct'];
    $ph = implode(',', array_fill(0, count($keys), '?'));
    $st = $db->prepare("SELECT meta_key, meta_value FROM rt_settings WHERE meta_key IN ($ph)");
    $st->execute($keys);
    $cfg = ['enabled' => false, 'token' => '', 'deposit_pct' => 30];
    foreach ($st->fetchAll() as $r) {
        if ($r['meta_key'] === 'mp_enabled')      $cfg['enabled'] = $r['meta_value'] === '1';
        if ($r['meta_key'] === 'mp_access_token') $cfg['token']   = trim($r['meta_value']);
        if ($r['meta_key'] === 'mp_deposit_pct')  $cfg['deposit_pct'] = max(1, min(100, floatval($r['meta_value']) ?: 30));
    }
    return $cfg;
}

function rtMpRequest($method, $url, array $cfg, $body = null) {
    $ch = curl_init($url);
    $headers = ['Authorization: Bearer ' . $cfg['token'], 'Content-Type: application/json'];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false) return [null, 'Error de conexión: ' . $err];
    $data = json_decode($resp, true);
    if ($code >= 300) return [null, 'HTTP ' . $code . ': ' . ($data['message'] ?? substr($resp, 0, 200))];
    return [$data, null];
}

// Crea la preferencia de Checkout Pro para la seña de una reserva.
// Devuelve [init_point, preference_id, error].
function mpCreatePreference(array $cfg, $reservationId, $title, $amount, $payerName, $payerEmail, $baseUrl) {
    $body = [
        'items' => [[
            'title'       => $title,
            'quantity'    => 1,
            'unit_price'  => round(floatval($amount), 2),
            'currency_id' => 'ARS',
        ]],
        'external_reference' => (string) $reservationId,
        'notification_url'   => $baseUrl . 'api/mp_webhook.php',
        'back_urls' => [
            'success' => $baseUrl . 'book.php?status=success',
            'pending' => $baseUrl . 'book.php?status=pending',
            'failure' => $baseUrl . 'book.php?status=failure',
        ],
        'auto_return' => 'approved',
    ];
    if ($payerEmail) $body['payer'] = ['name' => $payerName, 'email' => $payerEmail];

    [$data, $err] = rtMpRequest('POST', 'https://api.mercadopago.com/checkout/preferences', $cfg, $body);
    if ($err) return [null, null, $err];
    return [$data['init_point'] ?? null, $data['id'] ?? null, null];
}

// Consulta un pago por ID (para el webhook).
function mpGetPayment(array $cfg, $paymentId) {
    return rtMpRequest('GET', 'https://api.mercadopago.com/v1/payments/' . rawurlencode($paymentId), $cfg);
}
