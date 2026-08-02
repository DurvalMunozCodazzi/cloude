<?php
// Tarea programada: recordatorios por email + sync de calendarios iCal
// externos. No requiere sesión — protegida por RT_CRON_SECRET (query param).
// Se dispara sola cada hora vía WP-Cron (ver rt_run_reminders_cron en
// reserva-total.php), sin necesidad de configurar nada manualmente.
require_once '../config.php';
require_once '../smtp.php';
require_once '../ical_sync.php';

$secret = trim($_GET['secret'] ?? '');
if (!RT_CRON_SECRET || !hash_equals(RT_CRON_SECRET, $secret)) {
    rtErr('Secreto de cron inválido', 403);
}

$db  = rtDB();
$cfg = getSmtpConfig($db);

// ── Recordatorio de check-in al huésped (dentro de las próximas 24hs) ──────
$sentCheckin = 0;
$st = $db->prepare("SELECT r.*, res.name as resource_name
    FROM reservations r JOIN resources res ON res.id = r.resource_id
    WHERE r.status NOT IN ('cancelled','completed')
      AND r.guest_email IS NOT NULL AND r.guest_email != ''
      AND r.checkin_reminder_sent = 0
      AND r.check_in BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 24 HOUR)");
$st->execute();
foreach ($st->fetchAll() as $rv) {
    $ci   = new DateTime($rv['check_in']);
    $body = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#1a1a2e;line-height:1.6">'
          . '<p>Hola ' . htmlspecialchars($rv['guest_name']) . ',</p>'
          . '<p>Te recordamos que tu check-in en <strong>' . htmlspecialchars($rv['resource_name']) . '</strong> es el '
          . $ci->format('d/m/Y') . ' a las ' . $ci->format('H:i') . ' hs.</p>'
          . '<p>¡Te esperamos!</p></div>';
    $err = sendSmtp($rv['guest_email'], $rv['guest_name'], 'Recordatorio de check-in — Reserva Total', $body, $cfg);
    if (!$err) {
        $db->prepare("UPDATE reservations SET checkin_reminder_sent=1 WHERE id=?")->execute([$rv['id']]);
        $sentCheckin++;
    }
}

// ── Aviso interno: reservas finalizadas con saldo pendiente ────────────────
$sentOverdue  = 0;
$notifyEmail  = trim((string) $db->query("SELECT meta_value FROM rt_settings WHERE meta_key='notify_email'")->fetchColumn());
if ($notifyEmail) {
    $st2 = $db->prepare("SELECT r.*, res.name as resource_name
        FROM reservations r JOIN resources res ON res.id = r.resource_id
        WHERE r.status != 'cancelled'
          AND r.overdue_reminder_sent = 0
          AND r.check_out < NOW()");
    $st2->execute();
    foreach ($st2->fetchAll() as $rv) {
        $exSum = $db->prepare("SELECT COALESCE(SUM(price*qty),0) as t FROM reservation_extras WHERE reservation_id=?");
        $exSum->execute([$rv['id']]);
        $extTotal = floatval($exSum->fetch()['t']);

        $paySum = $db->prepare("SELECT COALESCE(SUM(amount),0) as t FROM reservation_payments WHERE reservation_id=?");
        $paySum->execute([$rv['id']]);
        $paidTotal = floatval($paySum->fetch()['t']);

        $grand   = floatval($rv['total_price']) + $extTotal;
        $balance = round($grand - $paidTotal, 2);
        if ($balance <= 0) {
            // Ya está saldada — marcar como procesada sin avisar
            $db->prepare("UPDATE reservations SET overdue_reminder_sent=1 WHERE id=?")->execute([$rv['id']]);
            continue;
        }

        $body = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#1a1a2e;line-height:1.6">'
              . '<p>La reserva <strong>#' . $rv['id'] . '</strong> de <strong>' . htmlspecialchars($rv['guest_name']) . '</strong> '
              . 'en <strong>' . htmlspecialchars($rv['resource_name']) . '</strong> ya finalizó (salida: '
              . (new DateTime($rv['check_out']))->format('d/m/Y') . ') y tiene un saldo pendiente de <strong>$'
              . number_format($balance, 2, ',', '.') . '</strong>.</p></div>';
        $err = sendSmtp($notifyEmail, 'Reserva Total', 'Saldo pendiente — Reserva #' . $rv['id'], $body, $cfg);
        if (!$err) {
            $db->prepare("UPDATE reservations SET overdue_reminder_sent=1 WHERE id=?")->execute([$rv['id']]);
            $sentOverdue++;
        }
    }
}

// ── Limpieza: marcar "sucio" el recurso apenas pasa el checkout de un huésped ──
$flaggedDirty = 0;
$st3 = $db->prepare("SELECT id, resource_id FROM reservations
    WHERE status != 'cancelled' AND housekeeping_flagged = 0 AND check_out < NOW()");
$st3->execute();
foreach ($st3->fetchAll() as $rv) {
    $db->prepare("UPDATE resources SET housekeeping_status='sucio' WHERE id=?")->execute([$rv['resource_id']]);
    $db->prepare("UPDATE reservations SET housekeeping_flagged=1 WHERE id=?")->execute([$rv['id']]);
    $flaggedDirty++;
}

// ── Sync de calendarios iCal externos importados (Booking/Airbnb → bloqueos) ──
$syncedImports = 0;
$imports = $db->query("SELECT * FROM rt_ical_imports")->fetchAll();
foreach ($imports as $imp) {
    if (rtSyncIcalImport($db, $imp) !== null) $syncedImports++;
}

rtOut([
    'ok' => true,
    'checkin_reminders_sent' => $sentCheckin,
    'overdue_reminders_sent' => $sentOverdue,
    'ical_imports_synced'    => $syncedImports,
    'resources_flagged_dirty' => $flaggedDirty,
]);
