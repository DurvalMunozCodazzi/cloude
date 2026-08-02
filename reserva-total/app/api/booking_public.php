<?php
// ── API pública del motor de reserva online ─────────────────────────────────
// Sin sesión: la usa book.php (la página que ve el huésped). Solo expone
// datos no sensibles (recursos activos, precios, disponibilidad).
require_once '../config.php';
require_once '../pricing.php';
require_once '../mp.php';

$db     = rtDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ── GET recursos disponibles para reservar ───────────────────────────────
if ($method === 'GET' && $action === 'resources') {
    $rows = $db->query("SELECT id, name, description, type, price_per_day, capacity, color, photo
                         FROM resources WHERE active=1 ORDER BY position, name")->fetchAll();
    $mp = getMpConfig($db);
    rtOut([
        'resources'   => $rows,
        'mp_enabled'  => $mp['enabled'] && $mp['token'] !== '',
        'deposit_pct' => $mp['deposit_pct'],
    ]);
}

// ── GET cotización para recurso + fechas ─────────────────────────────────
if ($method === 'GET' && $action === 'quote') {
    $resId    = intval($_GET['resource_id'] ?? 0);
    $checkIn  = trim($_GET['check_in']  ?? '');
    $checkOut = trim($_GET['check_out'] ?? '');
    if (!$resId || !$checkIn || !$checkOut) rtErr('Parámetros incompletos');

    $st = $db->prepare("SELECT * FROM resources WHERE id=? AND active=1");
    $st->execute([$resId]);
    $resource = $st->fetch();
    if (!$resource) rtErr('Alojamiento no encontrado', 404);

    if (!rtIsAvailable($db, $resId, $checkIn, $checkOut)) {
        rtOut(['available' => false]);
    }

    $quote = rtQuoteStay($db, $resource, $checkIn, $checkOut);
    if (!$quote) rtErr('Fechas inválidas');

    $mp = getMpConfig($db);
    $quote['available']   = true;
    $quote['deposit_pct'] = $mp['deposit_pct'];
    $quote['deposit']     = round($quote['total'] * $mp['deposit_pct'] / 100, 2);
    $quote['mp_enabled']  = $mp['enabled'] && $mp['token'] !== '';
    rtOut($quote);
}

// ── POST crear reserva online (pendiente) + preferencia de pago ──────────
if ($method === 'POST' && $action === 'create') {
    $b = json_decode(file_get_contents('php://input'), true);

    $resId     = intval($b['resource_id'] ?? 0);
    $checkIn   = trim($b['check_in']  ?? '');
    $checkOut  = trim($b['check_out'] ?? '');
    $guestName = trim($b['guest_name'] ?? '');
    $email     = trim($b['guest_email'] ?? '');
    $phone     = trim($b['guest_phone'] ?? '');
    $doc       = trim($b['guest_doc']   ?? '');
    $adults    = max(1, intval($b['adults'] ?? 1));

    if (!$resId || !$checkIn || !$checkOut) rtErr('Elegí alojamiento y fechas');
    if (!$guestName) rtErr('Ingresá tu nombre completo');
    if (!$email && !$phone) rtErr('Dejanos un email o un teléfono para contactarte');
    if ($checkOut <= $checkIn) rtErr('La salida debe ser posterior a la entrada');

    $st = $db->prepare("SELECT * FROM resources WHERE id=? AND active=1");
    $st->execute([$resId]);
    $resource = $st->fetch();
    if (!$resource) rtErr('Alojamiento no encontrado', 404);

    if (!rtIsAvailable($db, $resId, $checkIn, $checkOut)) {
        rtErr('Esas fechas se acaban de ocupar — elegí otras, por favor');
    }

    $quote = rtQuoteStay($db, $resource, $checkIn, $checkOut);
    if (!$quote) rtErr('Fechas inválidas');

    // Vincular/crear ficha de cliente igual que una reserva manual
    $clientId = findOrCreateClient($db, $guestName, $email, $phone, $doc);

    $db->prepare("INSERT INTO reservations
        (resource_id, client_id, guest_name, guest_email, guest_phone, guest_doc,
         check_in, check_out, adults, total_price, status, payment_method, booking_source, internal_notes)
        VALUES (?,?,?,?,?,?,?,?,?,?,'pending','mp','online',?)")
       ->execute([
           $resId, $clientId, $guestName, $email, $phone, $doc,
           $checkIn, $checkOut, $adults, $quote['total'],
           'Reserva online — seña ' . intval(getMpConfig($db)['deposit_pct']) . '%',
       ]);
    $newId = $db->lastInsertId();

    $mp = getMpConfig($db);
    if (!$mp['enabled'] || !$mp['token']) {
        // Sin MP configurado: queda pendiente y el establecimiento coordina el pago
        rtOut(['ok' => true, 'reservation_id' => $newId, 'payment' => 'manual'], 201);
    }

    $deposit = round($quote['total'] * $mp['deposit_pct'] / 100, 2);
    $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $baseUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
             . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/') . '/';

    [$initPoint, $prefId, $err] = mpCreatePreference(
        $mp, $newId,
        'Seña reserva ' . $resource['name'] . ' (' . $quote['nights'] . ' noche/s)',
        $deposit, $guestName, $email, $baseUrl
    );

    if ($err || !$initPoint) {
        // La reserva queda pendiente igual; el pago se coordina a mano
        rtOut(['ok' => true, 'reservation_id' => $newId, 'payment' => 'manual', 'mp_error' => $err], 201);
    }

    $db->prepare("UPDATE reservations SET mp_preference_id=? WHERE id=?")->execute([$prefId, $newId]);
    rtOut(['ok' => true, 'reservation_id' => $newId, 'payment' => 'mp', 'init_point' => $initPoint], 201);
}

rtErr('Acción no encontrada', 404);
