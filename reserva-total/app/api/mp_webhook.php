<?php
// ── Webhook de Mercado Pago ──────────────────────────────────────────────────
// MP avisa acá cuando hay un pago. Verificamos contra la API real de MP
// (nunca confiamos en el cuerpo del webhook) y si está aprobado registramos
// el pago en la reserva y la confirmamos. Idempotente: el mismo pago no se
// registra dos veces.
require_once '../config.php';
require_once '../mp.php';
require_once '../smtp.php';

$db = rtDB();
$mp = getMpConfig($db);
if (!$mp['token']) { http_response_code(200); exit; } // nada que hacer sin credenciales

// MP manda el ID de pago de varias formas según la versión de la notificación
$paymentId = $_GET['data_id'] ?? $_GET['id'] ?? '';
$type      = $_GET['type'] ?? $_GET['topic'] ?? '';
$raw       = json_decode(file_get_contents('php://input'), true);
if (!$paymentId && isset($raw['data']['id'])) $paymentId = $raw['data']['id'];
if (!$type && isset($raw['type']))            $type      = $raw['type'];

if (!$paymentId || ($type && !in_array($type, ['payment', 'payment.created', 'payment.updated']))) {
    http_response_code(200); exit; // notificación que no nos interesa
}

[$payment, $err] = mpGetPayment($mp, $paymentId);
if ($err || !$payment) { http_response_code(200); exit; }

$status        = $payment['status'] ?? '';
$reservationId = intval($payment['external_reference'] ?? 0);
$amount        = floatval($payment['transaction_amount'] ?? 0);
if ($status !== 'approved' || !$reservationId || $amount <= 0) { http_response_code(200); exit; }

$st = $db->prepare("SELECT r.*, res.name as resource_name FROM reservations r
                     JOIN resources res ON res.id = r.resource_id WHERE r.id=?");
$st->execute([$reservationId]);
$rv = $st->fetch();
if (!$rv) { http_response_code(200); exit; }

// Idempotencia: si este pago de MP ya fue registrado, no duplicar
$tag = 'MP #' . $paymentId;
$dup = $db->prepare("SELECT id FROM reservation_payments WHERE reservation_id=? AND notes=?");
$dup->execute([$reservationId, $tag]);
if ($dup->fetch()) { http_response_code(200); exit; }

$db->prepare("INSERT INTO reservation_payments (reservation_id, amount, method, payment_date, notes)
              VALUES (?,?,'mp',CURDATE(),?)")
   ->execute([$reservationId, $amount, $tag]);

// Total pagado vs total de la reserva (alojamiento + extras)
$exSum = $db->prepare("SELECT COALESCE(SUM(price*qty),0) as t FROM reservation_extras WHERE reservation_id=?");
$exSum->execute([$reservationId]);
$grand = floatval($rv['total_price']) + floatval($exSum->fetch()['t']);

$paySum = $db->prepare("SELECT COALESCE(SUM(amount),0) as t FROM reservation_payments WHERE reservation_id=?");
$paySum->execute([$reservationId]);
$paid = floatval($paySum->fetch()['t']);

$newStatus = ($paid >= $grand && $grand > 0) ? 'paid' : 'confirmed';
$db->prepare("UPDATE reservations SET status=?, payment_id=? WHERE id=?")
   ->execute([$newStatus, (string) $paymentId, $reservationId]);

// Avisos por email (si SMTP está configurado); los errores no afectan al webhook
$cfg = getSmtpConfig($db);
$fmtM = fn($n) => number_format(floatval($n), 2, ',', '.');

$notifyEmail = trim((string) $db->query("SELECT meta_value FROM rt_settings WHERE meta_key='notify_email'")->fetchColumn());
if ($notifyEmail) {
    $body = '<div style="font-family:Arial,sans-serif;font-size:14px;line-height:1.6">'
          . '<p>💰 Pago acreditado por Mercado Pago.</p>'
          . '<p>Reserva <strong>#' . $reservationId . '</strong> — ' . htmlspecialchars($rv['guest_name'])
          . ' en <strong>' . htmlspecialchars($rv['resource_name']) . '</strong><br>'
          . 'Monto: <strong>$' . $fmtM($amount) . '</strong> · Pagado acumulado: $' . $fmtM($paid)
          . ' de $' . $fmtM($grand) . '</p></div>';
    sendSmtp($notifyEmail, 'Reserva Total', 'Pago acreditado — Reserva #' . $reservationId, $body, $cfg);
}
if ($rv['guest_email']) {
    $ci = new DateTime($rv['check_in']);
    $body = '<div style="font-family:Arial,sans-serif;font-size:14px;line-height:1.6">'
          . '<p>Hola ' . htmlspecialchars($rv['guest_name']) . ',</p>'
          . '<p>Recibimos tu pago de <strong>$' . $fmtM($amount) . '</strong> y tu reserva en <strong>'
          . htmlspecialchars($rv['resource_name']) . '</strong> quedó confirmada para el '
          . $ci->format('d/m/Y') . ' a las ' . $ci->format('H:i') . ' hs.</p>'
          . '<p>¡Te esperamos!</p></div>';
    sendSmtp($rv['guest_email'], $rv['guest_name'], 'Reserva confirmada — pago recibido', $body, $cfg);
}

http_response_code(200);
echo 'OK';
