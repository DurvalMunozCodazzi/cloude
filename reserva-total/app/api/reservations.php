<?php
require_once '../config.php';
$me     = rtRequireAuth();
$db     = rtDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$id     = intval($_GET['id'] ?? 0);

// ── Helper: cargar extras de una reserva ─────────────────────
function loadExtras(PDO $db, $reservationId) {
    $st = $db->prepare("SELECT id,name,price,qty FROM reservation_extras WHERE reservation_id=? ORDER BY id");
    $st->execute([$reservationId]);
    return $st->fetchAll();
}

// ── Helper: guardar extras (borra y re-inserta) ───────────────
function saveExtras(PDO $db, $reservationId, $extras) {
    $db->prepare("DELETE FROM reservation_extras WHERE reservation_id=?")->execute([$reservationId]);
    if (!is_array($extras)) return;
    $st = $db->prepare("INSERT INTO reservation_extras (reservation_id,name,price,qty) VALUES (?,?,?,?)");
    foreach ($extras as $ex) {
        $name  = trim($ex['name'] ?? '');
        $price = floatval($ex['price'] ?? 0);
        $qty   = max(1, intval($ex['qty'] ?? 1));
        if ($name) $st->execute([$reservationId, $name, $price, $qty]);
    }
}

// ── Helper: pagos registrados de una reserva ─────────────────
function loadPayments(PDO $db, $reservationId) {
    $st = $db->prepare("SELECT id,amount,method,payment_date,notes,created_at
                         FROM reservation_payments WHERE reservation_id=? ORDER BY payment_date,id");
    $st->execute([$reservationId]);
    return $st->fetchAll();
}

// ── Helper: acompañantes que vinieron en esta reserva puntual ─
function loadReservationGuests(PDO $db, $reservationId) {
    $st = $db->prepare("SELECT c.id,c.first_name,c.last_name,c.dni,c.relationship
                         FROM reservation_guests rg
                         JOIN client_companions c ON c.id = rg.companion_id
                         WHERE rg.reservation_id=? ORDER BY c.id");
    $st->execute([$reservationId]);
    return $st->fetchAll();
}

// ── Helper: guardar acompañantes de esta reserva (borra y re-inserta) ─
function saveReservationGuests(PDO $db, $reservationId, $companionIds) {
    $db->prepare("DELETE FROM reservation_guests WHERE reservation_id=?")->execute([$reservationId]);
    if (!is_array($companionIds)) return;
    $st = $db->prepare("INSERT INTO reservation_guests (reservation_id,companion_id) VALUES (?,?)");
    foreach ($companionIds as $cid) {
        $cid = intval($cid);
        if ($cid) $st->execute([$reservationId, $cid]);
    }
}

// ── Helper: adjunta extras + pagos + totales + cliente/acompañantes ──
function attachTotals(PDO $db, array $row) {
    $extras   = loadExtras($db, $row['id']);
    $payments = loadPayments($db, $row['id']);
    $extTotal  = 0;
    foreach ($extras as $ex) $extTotal += floatval($ex['price']) * intval($ex['qty']);
    $paidTotal = 0;
    foreach ($payments as $p) $paidTotal += floatval($p['amount']);
    $grand = round(floatval($row['total_price']) + $extTotal, 2);

    $row['extras']       = $extras;
    $row['extras_total'] = round($extTotal, 2);
    $row['grand_total']  = $grand;
    $row['payments']     = $payments;
    $row['paid_total']   = round($paidTotal, 2);
    $row['balance']      = round($grand - $paidTotal, 2);
    $row['guests']       = loadReservationGuests($db, $row['id']);

    if (!empty($row['client_id'])) {
        $c = $db->prepare("SELECT id,first_name,last_name FROM clients WHERE id=?");
        $c->execute([$row['client_id']]);
        $client = $c->fetch();
        $row['client_name'] = $client ? trim($client['first_name'].' '.$client['last_name']) : null;
    } else {
        $row['client_name'] = null;
    }
    return $row;
}

// ── GET list — por mes o por recurso ─────────────────────────
if ($method === 'GET' && $action === 'list') {
    $year  = intval($_GET['year']  ?? date('Y'));
    $month = intval($_GET['month'] ?? date('n'));
    $resId = intval($_GET['resource_id'] ?? 0);

    $from = sprintf('%04d-%02d-01 00:00:00', $year, $month);
    $to   = date('Y-m-t 23:59:59', strtotime($from));

    $sql  = "SELECT r.*, res.name as resource_name, res.color as resource_color,
                    res.price_per_day, res.price_per_hour
             FROM reservations r
             JOIN resources res ON res.id = r.resource_id
             WHERE r.status != 'cancelled'
               AND r.check_in <= ? AND r.check_out >= ?";
    $params = [$to, $from];
    if ($resId) { $sql .= " AND r.resource_id=?"; $params[] = $resId; }
    $sql .= " ORDER BY r.check_in ASC";

    $st = $db->prepare($sql); $st->execute($params);
    $rows = $st->fetchAll();

    foreach ($rows as &$row) $row = attachTotals($db, $row);
    unset($row);

    rtOut(['reservations' => $rows]);
}

// ── GET single ───────────────────────────────────────────────
if ($method === 'GET' && $action === 'get') {
    if (!$id) rtErr('ID requerido');
    $st = $db->prepare("SELECT r.*, res.name as resource_name, res.color as resource_color,
                               res.price_per_day, res.price_per_hour
        FROM reservations r JOIN resources res ON res.id=r.resource_id WHERE r.id=?");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) rtErr('Reserva no encontrada', 404);
    $row = attachTotals($db, $row);
    rtOut(['reservation' => $row]);
}

// ── POST create ──────────────────────────────────────────────
if ($method === 'POST' && $action === 'create') {
    $b = json_decode(file_get_contents('php://input'), true);

    $resourceId = intval($b['resource_id'] ?? 0);
    $guestName  = trim($b['guest_name'] ?? '');
    $checkIn    = trim($b['check_in']   ?? '');
    $checkOut   = trim($b['check_out']  ?? '');

    if (!$resourceId) rtErr('Seleccioná un recurso');
    if (!$guestName)  rtErr('El nombre del huésped es requerido');
    if (!$checkIn || !$checkOut) rtErr('Fechas de entrada y salida requeridas');
    if ($checkOut <= $checkIn)   rtErr('La salida debe ser posterior a la entrada');

    // Verificar disponibilidad
    $conflict = $db->prepare("SELECT id FROM reservations
        WHERE resource_id=? AND status!='cancelled'
          AND check_in < ? AND check_out > ?");
    $conflict->execute([$resourceId, $checkOut, $checkIn]);
    if ($conflict->fetch()) rtErr('El recurso ya tiene una reserva en ese período');

    $blocked = $db->prepare("SELECT id FROM rt_blocked_dates
        WHERE resource_id=? AND date_start < ? AND date_end > ?");
    $blocked->execute([$resourceId, $checkOut, $checkIn]);
    if ($blocked->fetch()) rtErr('Ese período está bloqueado para este recurso');

    // Cliente: el elegido explícitamente, o buscar/crear a partir de los
    // datos del huésped (nombre/email/teléfono/DNI) si no se eligió ninguno.
    $clientId = intval($b['client_id'] ?? 0) ?: null;
    if (!$clientId) {
        $clientId = findOrCreateClient($db, $guestName, $b['guest_email'] ?? '', $b['guest_phone'] ?? '', $b['guest_doc'] ?? '');
    }

    $db->prepare("INSERT INTO reservations
        (resource_id,client_id,guest_name,guest_email,guest_phone,guest_doc,check_in,check_out,
         is_hourly,adults,children,total_price,status,payment_method,notes,internal_notes,created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
    ->execute([
        $resourceId, $clientId, $guestName,
        trim($b['guest_email']  ?? ''),
        trim($b['guest_phone']  ?? ''),
        trim($b['guest_doc']    ?? ''),
        $checkIn, $checkOut,
        isset($b['is_hourly']) ? intval($b['is_hourly']) : 0,
        intval($b['adults']   ?? 1),
        intval($b['children'] ?? 0),
        floatval($b['total_price'] ?? 0),
        in_array($b['status'] ?? '', ['pending','paid','confirmed','in_progress','completed','cancelled']) ? $b['status'] : 'confirmed',
        $b['payment_method'] ?? 'cash',
        trim($b['notes']          ?? ''),
        trim($b['internal_notes'] ?? ''),
        $me['id'],
    ]);
    $newId = $db->lastInsertId();

    // Guardar extras y acompañantes de esta estadía
    if (!empty($b['extras'])) saveExtras($db, $newId, $b['extras']);
    if (array_key_exists('guests', $b)) saveReservationGuests($db, $newId, $b['guests']);

    $st = $db->prepare("SELECT r.*, res.name as resource_name, res.color as resource_color,
                               res.price_per_day, res.price_per_hour
        FROM reservations r JOIN resources res ON res.id=r.resource_id WHERE r.id=?");
    $st->execute([$newId]);
    $row = $st->fetch();
    $row = attachTotals($db, $row);
    rtOut(['reservation' => $row], 201);
}

// ── PUT update ───────────────────────────────────────────────
if ($method === 'PUT' && $action === 'update') {
    if (!$id) rtErr('ID requerido');
    $b = json_decode(file_get_contents('php://input'), true);

    // Verificar conflicto si cambia recurso o fechas
    if (isset($b['resource_id']) || isset($b['check_in']) || isset($b['check_out'])) {
        $cur = $db->prepare("SELECT resource_id,check_in,check_out FROM reservations WHERE id=?");
        $cur->execute([$id]);
        $current = $cur->fetch();
        if ($current) {
            $newResId = intval($b['resource_id'] ?? $current['resource_id']);
            $newIn    = $b['check_in']  ?? $current['check_in'];
            $newOut   = $b['check_out'] ?? $current['check_out'];
            if ($newOut <= $newIn) rtErr('La salida debe ser posterior a la entrada');
            $conflict = $db->prepare("SELECT id FROM reservations
                WHERE resource_id=? AND id!=? AND status!='cancelled'
                  AND check_in < ? AND check_out > ?");
            $conflict->execute([$newResId, $id, $newOut, $newIn]);
            if ($conflict->fetch()) rtErr('El recurso ya tiene una reserva en ese período');

            $blocked = $db->prepare("SELECT id FROM rt_blocked_dates
                WHERE resource_id=? AND date_start < ? AND date_end > ?");
            $blocked->execute([$newResId, $newOut, $newIn]);
            if ($blocked->fetch()) rtErr('Ese período está bloqueado para este recurso');
        }
    }

    $sets = []; $vals = [];
    $allowed = ['resource_id','client_id','guest_name','guest_email','guest_phone','guest_doc','check_in','check_out',
                'is_hourly','adults','children','total_price','status','payment_method',
                'payment_id','notes','internal_notes'];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $b)) { $sets[] = "$f=?"; $vals[] = $b[$f]; }
    }
    // Si cambian las fechas, volver a habilitar los recordatorios automáticos
    // para que se re-evalúen contra la nueva fecha en el próximo cron.
    if (array_key_exists('check_in', $b))  { $sets[] = 'checkin_reminder_sent=0'; }
    if (array_key_exists('check_out', $b)) { $sets[] = 'overdue_reminder_sent=0'; $sets[] = 'housekeeping_flagged=0'; }
    if ($sets) {
        $vals[] = $id;
        $db->prepare("UPDATE reservations SET " . implode(',', $sets) . " WHERE id=?")->execute($vals);
    }
    if (array_key_exists('extras', $b)) saveExtras($db, $id, $b['extras']);
    if (array_key_exists('guests', $b)) saveReservationGuests($db, $id, $b['guests']);

    rtOut(['ok' => true]);
}

// ── DELETE (cancelar) ────────────────────────────────────────
if ($method === 'DELETE' && $action === 'cancel') {
    if (!$id) rtErr('ID requerido');
    $db->prepare("UPDATE reservations SET status='cancelled' WHERE id=?")->execute([$id]);
    rtOut(['ok' => true]);
}

// ── POST agregar pago / seña ──────────────────────────────────
if ($method === 'POST' && $action === 'add_payment') {
    if (!$id) rtErr('ID de reserva requerido');
    $chk = $db->prepare("SELECT id FROM reservations WHERE id=?"); $chk->execute([$id]);
    if (!$chk->fetch()) rtErr('Reserva no encontrada', 404);

    $b      = json_decode(file_get_contents('php://input'), true);
    $amount = floatval($b['amount'] ?? 0);
    if ($amount <= 0) rtErr('El monto debe ser mayor a cero');
    $date   = trim($b['payment_date'] ?? '') ?: date('Y-m-d');

    $db->prepare("INSERT INTO reservation_payments (reservation_id,amount,method,payment_date,notes,created_by)
                  VALUES (?,?,?,?,?,?)")
       ->execute([$id, $amount, trim($b['method'] ?? 'cash'), $date, trim($b['notes'] ?? ''), $me['id']]);

    rtOut(['ok' => true, 'payments' => loadPayments($db, $id)], 201);
}

// ── DELETE eliminar pago ──────────────────────────────────────
if ($method === 'DELETE' && $action === 'delete_payment') {
    $paymentId = intval($_GET['payment_id'] ?? 0);
    if (!$paymentId) rtErr('payment_id requerido');
    $db->prepare("DELETE FROM reservation_payments WHERE id=?")->execute([$paymentId]);
    rtOut(['ok' => true]);
}

// ── POST generar (u obtener) el link de check-in digital ──────
if ($method === 'POST' && $action === 'generate_checkin_link') {
    if (!$id) rtErr('ID requerido');
    $st = $db->prepare("SELECT checkin_token FROM reservations WHERE id=?"); $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) rtErr('Reserva no encontrada', 404);
    $token = $row['checkin_token'] ?: bin2hex(random_bytes(20));
    $db->prepare("UPDATE reservations SET checkin_token=? WHERE id=?")->execute([$token, $id]);
    rtOut(['token' => $token]);
}

// ── GET el checklist de check-in ya completado por el huésped ──
if ($method === 'GET' && $action === 'checkin_submission') {
    if (!$id) rtErr('ID requerido');
    $st = $db->prepare("SELECT * FROM rt_checkin_submissions WHERE reservation_id=?");
    $st->execute([$id]);
    rtOut(['submission' => $st->fetch() ?: null]);
}

// ── GET disponibilidad de un recurso en un mes ───────────────
if ($method === 'GET' && $action === 'availability') {
    $resId = intval($_GET['resource_id'] ?? 0);
    $year  = intval($_GET['year']  ?? date('Y'));
    $month = intval($_GET['month'] ?? date('n'));
    if (!$resId) rtErr('resource_id requerido');

    $from = sprintf('%04d-%02d-01 00:00:00', $year, $month);
    $to   = date('Y-m-t 23:59:59', strtotime($from));

    $st = $db->prepare("SELECT check_in, check_out, status FROM reservations
        WHERE resource_id=? AND status!='cancelled' AND check_in<=? AND check_out>=?
        ORDER BY check_in");
    $st->execute([$resId, $to, $from]);
    rtOut(['slots' => $st->fetchAll()]);
}

rtErr('Acción no encontrada', 404);
