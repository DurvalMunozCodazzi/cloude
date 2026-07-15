<?php
require_once '../config.php';
$me     = rtRequireAuth();
$db     = rtDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$id     = intval($_GET['id'] ?? 0);

// ── GET comprobante HTML (para print/email preview) ───────────
if ($method === 'GET' && $action === 'preview') {
    if (!$id) rtErr('ID requerido');
    $row = getReservationFull($db, $id);
    if (!$row) rtErr('Reserva no encontrada', 404);
    header('Content-Type: text/html; charset=utf-8');
    echo buildReceipt($row, false);
    exit;
}

// ── POST enviar email al huésped ──────────────────────────────
if ($method === 'POST' && $action === 'send_summary') {
    if (!$id) rtErr('ID requerido');
    $row = getReservationFull($db, $id);
    if (!$row) rtErr('Reserva no encontrada', 404);
    if (!$row['guest_email']) rtErr('El huésped no tiene email cargado');

    $to      = $row['guest_email'];
    $name    = $row['guest_name'];
    $subject = 'Resumen de tu reserva #' . $id . ' — Reserva Total';
    $body    = buildReceipt($row, true);

    $cfg = getSmtpConfig($db);
    $err = sendSmtp($to, $name, $subject, $body, $cfg);
    if ($err) rtErr('Error al enviar email: ' . $err);
    rtOut(['ok' => true, 'email' => $to]);
}

// ── Helper: cargar reserva completa con extras ────────────────
function getReservationFull(PDO $db, $id) {
    $st = $db->prepare("SELECT r.*, res.name as resource_name, res.color as resource_color,
                               res.type as resource_type, res.price_per_day, res.price_per_hour
        FROM reservations r JOIN resources res ON res.id=r.resource_id WHERE r.id=?");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) return null;
    $st2 = $db->prepare("SELECT name,price,qty FROM reservation_extras WHERE reservation_id=? ORDER BY id");
    $st2->execute([$id]);
    $row['extras'] = $st2->fetchAll();
    $extTotal = array_sum(array_map(function($e){ return $e['price']*$e['qty']; }, $row['extras']));
    $row['extras_total'] = $extTotal;
    $row['grand_total']  = floatval($row['total_price']) + $extTotal;
    return $row;
}

// ── Helper: formato monetario ─────────────────────────────────
function fmtMoney($n) {
    $num = floatval($n);
    if ($num == floor($num)) return number_format($num, 0, ',', '.');
    return number_format($num, 2, ',', '.');
}

// ── Helper: construir HTML del comprobante ────────────────────
function buildReceipt(array $rv, bool $forEmail) {
    $statusLabels = ['confirmed'=>'Confirmada','pending'=>'Pendiente','paid'=>'Pagada',
                     'in_progress'=>'En curso','completed'=>'Finalizada','cancelled'=>'Cancelada'];
    $statusColors = ['confirmed'=>'#0d9488','pending'=>'#f59e0b','paid'=>'#3b82f6',
                     'in_progress'=>'#8b5cf6','completed'=>'#6b7280','cancelled'=>'#ef4444'];
    $payLabels    = ['cash'=>'Efectivo','transfer'=>'Transferencia','mp'=>'MercadoPago','card'=>'Tarjeta'];
    $typeLabels   = ['room'=>'Habitación','cabin'=>'Cabaña','vehicle'=>'Vehículo','tool'=>'Herramienta','other'=>'Otro'];

    $ci      = new DateTime($rv['check_in']);
    $co      = new DateTime($rv['check_out']);
    $diff    = $ci->diff($co);
    $nights  = $diff->days;
    $hours   = round(($co->getTimestamp() - $ci->getTimestamp()) / 3600);
    $durStr  = ($hours < 24) ? $hours . ' hora(s)' : $nights . ' noche(s)';
    $sc      = $statusColors[$rv['status']] ?? '#888';
    $sl      = $statusLabels[$rv['status']] ?? $rv['status'];
    $pay     = $payLabels[$rv['payment_method']] ?? $rv['payment_method'];
    $type    = $typeLabels[$rv['resource_type']] ?? $rv['resource_type'];
    $now     = date('d/m/Y H:i');
    $basePrice = floatval($rv['total_price']);
    $extras    = $rv['extras'];
    $grand     = $rv['grand_total'];

    $days_es = ['Sunday'=>'Domingo','Monday'=>'Lunes','Tuesday'=>'Martes','Wednesday'=>'Miércoles',
                'Thursday'=>'Jueves','Friday'=>'Viernes','Saturday'=>'Sábado'];
    $months_es = [1=>'enero',2=>'febrero',3=>'marzo',4=>'abril',5=>'mayo',6=>'junio',
                  7=>'julio',8=>'agosto',9=>'septiembre',10=>'octubre',11=>'noviembre',12=>'diciembre'];
    $fmtDt = function(DateTime $dt) use ($days_es, $months_es) {
        return $days_es[$dt->format('l')] . ' ' . $dt->format('j') . ' de ' .
               $months_es[(int)$dt->format('n')] . ' · ' . $dt->format('H:i');
    };

    // Expense rows
    $expRows = '';
    if ($basePrice > 0) {
        $label = $rv['price_per_hour'] > 0 && $hours < 24
            ? "{$hours} hora(s) × $" . fmtMoney($rv['price_per_hour'])
            : "{$nights} noche(s) × $" . fmtMoney($rv['price_per_day']);
        $expRows .= "<tr><td class='exp-name'>Alojamiento <span class='exp-detail'>{$label}</span></td>
                         <td class='exp-amt'>$" . fmtMoney($basePrice) . "</td></tr>";
    }
    foreach ($extras as $ex) {
        $sub = $ex['price'] * $ex['qty'];
        $qty = $ex['qty'] > 1 ? " <span class='exp-detail'>×{$ex['qty']}</span>" : '';
        $expRows .= "<tr><td class='exp-name'>" . htmlspecialchars($ex['name']) . $qty . "</td>
                         <td class='exp-amt'>$" . fmtMoney($sub) . "</td></tr>";
    }

    // Adults/children
    $pax = $rv['adults'] . ' adulto(s)';
    if ($rv['children'] > 0) $pax .= ' · ' . $rv['children'] . ' niño(s)';

    $bg = $forEmail ? '#f4f4f4' : 'white';

    $grandFmt = '$' . fmtMoney($grand);

    $notesBlock = '';
    if ($rv['notes']) {
        $notesBlock = <<<HTML
    <div class="section">
      <div class="sec-title">Notas</div>
      <div class="notes-box">{$rv['notes']}</div>
    </div>
HTML;
    }

    $printButtons = '';
    if (!$forEmail) {
        $printButtons = <<<HTML
<div class="no-print" style="text-align:center;margin-top:20px">
  <button onclick="window.print()" style="background:#0d9488;color:#fff;border:none;padding:10px 28px;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer;margin-right:8px">
    🖨 Imprimir / Guardar PDF
  </button>
  <button onclick="window.close()" style="background:#e2e8f0;color:#333;border:none;padding:10px 28px;border-radius:8px;font-size:14px;cursor:pointer">
    Cerrar
  </button>
</div>
HTML;
    }

    return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reserva #{$rv['id']} — {$rv['guest_name']}</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#1a1a2e;background:{$bg};padding:24px}
  .wrap{max-width:600px;margin:0 auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 20px rgba(0,0,0,.1)}
  .hdr{background:#0d9488;color:#fff;padding:20px 24px;display:flex;justify-content:space-between;align-items:flex-start}
  .hdr-logo{font-size:20px;font-weight:800;letter-spacing:-.3px}
  .hdr-logo span{font-size:12px;font-weight:400;display:block;opacity:.8;margin-top:2px}
  .hdr-meta{text-align:right;font-size:12px;opacity:.9}
  .hdr-meta strong{font-size:16px;font-weight:800;display:block}
  .body{padding:0 24px 24px}
  .section{padding:16px 0;border-bottom:1px solid #e8eaf0}
  .section:last-child{border-bottom:none}
  .sec-title{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#0d9488;margin-bottom:10px}
  .grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
  .field label{font-size:10px;color:#888;text-transform:uppercase;letter-spacing:.3px;display:block;margin-bottom:2px}
  .field .val{font-size:13px;font-weight:600;color:#1a1a2e}
  .res-name{font-size:16px;font-weight:800;color:#1a1a2e;margin-bottom:4px}
  .res-type{font-size:11px;color:#888;margin-bottom:10px}
  .dur-row{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}
  .dur-chip{background:#f0fdfc;border:1px solid #0d948840;border-radius:20px;padding:3px 10px;font-size:11px;font-weight:600;color:#0d9488}
  .status-badge{display:inline-block;padding:3px 12px;border-radius:20px;font-size:11px;font-weight:700;color:#fff}
  table.exp{width:100%;border-collapse:collapse;margin-top:6px}
  .exp-name{padding:7px 0;color:#444;border-bottom:1px dotted #e8eaf0}
  .exp-amt{padding:7px 0;text-align:right;font-weight:600;color:#1a1a2e;border-bottom:1px dotted #e8eaf0;white-space:nowrap}
  .exp-detail{font-size:11px;color:#888;font-weight:400;margin-left:4px}
  .total-row td{padding:12px 0 4px;font-size:16px;font-weight:800;border-top:2px solid #0d9488}
  .total-row .total-label{color:#1a1a2e}
  .total-row .total-amt{color:#0d9488;text-align:right}
  .pay-row{font-size:12px;color:#888;text-align:right;padding-top:4px}
  .notes-box{background:#f8fffe;border-left:3px solid #0d9488;padding:10px 12px;border-radius:0 6px 6px 0;font-size:12px;color:#444;line-height:1.6}
  .footer{background:#f8f9fa;padding:14px 24px;text-align:center;font-size:10px;color:#999;line-height:1.8}
  @media print{
    body{background:white;padding:0}
    .wrap{box-shadow:none;border-radius:0}
    .no-print{display:none}
  }
</style>
</head>
<body>
<div class="wrap">
  <div class="hdr">
    <div class="hdr-logo">🗓 Reserva Total<span>reservatotal.com.ar</span></div>
    <div class="hdr-meta"><strong>Reserva #{$rv['id']}</strong>Emitido: {$now}</div>
  </div>
  <div class="body">

    <div class="section">
      <div class="sec-title">Datos del huésped</div>
      <div class="grid">
        <div class="field"><label>Nombre</label><div class="val">{$rv['guest_name']}</div></div>
        <div class="field"><label>DNI / Pasaporte</label><div class="val">{$rv['guest_doc']}</div></div>
        <div class="field"><label>Email</label><div class="val">{$rv['guest_email']}</div></div>
        <div class="field"><label>Teléfono</label><div class="val">{$rv['guest_phone']}</div></div>
      </div>
    </div>

    <div class="section">
      <div class="sec-title">Alojamiento</div>
      <div class="res-name">{$rv['resource_name']}</div>
      <div class="res-type">{$type}</div>
      <div class="grid">
        <div class="field"><label>Entrada</label><div class="val">{$fmtDt($ci)}</div></div>
        <div class="field"><label>Salida</label><div class="val">{$fmtDt($co)}</div></div>
      </div>
      <div class="dur-row">
        <span class="dur-chip"><i>⏱</i> {$durStr}</span>
        <span class="dur-chip">👥 {$pax}</span>
        <span class="status-badge" style="background:{$sc}">{$sl}</span>
      </div>
    </div>

    <div class="section">
      <div class="sec-title">Detalle de gastos</div>
      <table class="exp">
        <tbody>{$expRows}</tbody>
        <tfoot>
          <tr class="total-row">
            <td class="total-label">TOTAL</td>
            <td class="total-amt">{$grandFmt}</td>
          </tr>
        </tfoot>
      </table>
      <div class="pay-row">Forma de pago: {$pay}</div>
    </div>

{$notesBlock}
  </div>
  <div class="footer">
    Diseñado y programado por Durval Muñoz Codazzi · Reserva Total v2.4<br>
    Reserva Total® es marca registrada<br>
    Este comprobante fue generado el {$now}
  </div>
</div>
{$printButtons}
</body>
</html>
HTML;
}

// ── SMTP helpers ─────────────────────────────────────────────
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
