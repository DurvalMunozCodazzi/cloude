<?php
require_once '../config.php';
$me     = rtRequireAuth();
$db     = rtDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$id     = intval($_GET['id'] ?? 0);

// ── Helper: acompañantes de un cliente ────────────────────────
function loadCompanions(PDO $db, $clientId) {
    $st = $db->prepare("SELECT id,first_name,last_name,dni,relationship FROM client_companions WHERE client_id=? ORDER BY id");
    $st->execute([$clientId]);
    return $st->fetchAll();
}

// ── GET list (buscador por nombre/apellido/dni/email/teléfono) ───────
if ($method === 'GET' && $action === 'list') {
    $q = trim($_GET['q'] ?? '');
    if ($q) {
        $like = "%$q%";
        $st = $db->prepare("SELECT * FROM clients WHERE active=1 AND
            (first_name LIKE ? OR last_name LIKE ? OR dni LIKE ? OR email LIKE ? OR phone LIKE ?)
            ORDER BY first_name,last_name LIMIT 50");
        $st->execute([$like,$like,$like,$like,$like]);
    } else {
        $st = $db->query("SELECT * FROM clients WHERE active=1 ORDER BY first_name,last_name");
    }
    rtOut(['clients' => $st->fetchAll()]);
}

// ── GET detalle: datos + acompañantes + historial de reservas ────────
if ($method === 'GET' && $action === 'get') {
    if (!$id) rtErr('ID requerido');
    $st = $db->prepare("SELECT * FROM clients WHERE id=?"); $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) rtErr('Cliente no encontrado', 404);
    $row['companions'] = loadCompanions($db, $id);

    $hist = $db->prepare("SELECT r.id, r.check_in, r.check_out, r.status, r.total_price,
            res.name AS resource_name,
            COALESCE(ext.extras_total,0) AS extras_total,
            COALESCE(pay.paid_total,0)   AS paid_total
        FROM reservations r
        JOIN resources res ON res.id = r.resource_id
        LEFT JOIN (SELECT reservation_id, SUM(price*qty) AS extras_total FROM reservation_extras GROUP BY reservation_id) ext ON ext.reservation_id = r.id
        LEFT JOIN (SELECT reservation_id, SUM(amount) AS paid_total FROM reservation_payments GROUP BY reservation_id) pay ON pay.reservation_id = r.id
        WHERE r.client_id = ?
        ORDER BY r.check_in DESC");
    $hist->execute([$id]);
    $reservations = $hist->fetchAll();
    foreach ($reservations as &$h) {
        $h['grand_total'] = round(floatval($h['total_price']) + floatval($h['extras_total']), 2);
        $h['balance']     = round($h['grand_total'] - floatval($h['paid_total']), 2);
    }
    unset($h);
    $row['reservations'] = $reservations;

    rtOut(['client' => $row]);
}

// ── POST create ────────────────────────────────────────────────
if ($method === 'POST' && $action === 'create') {
    $b = json_decode(file_get_contents('php://input'), true);
    $firstName = trim($b['first_name'] ?? '');
    if (!$firstName) rtErr('El nombre es requerido');
    $db->prepare("INSERT INTO clients (first_name,last_name,dni,address,email,phone,notes) VALUES (?,?,?,?,?,?,?)")
       ->execute([
           $firstName,
           trim($b['last_name'] ?? ''),
           trim($b['dni']       ?? ''),
           trim($b['address']   ?? ''),
           trim($b['email']     ?? ''),
           trim($b['phone']     ?? ''),
           trim($b['notes']     ?? ''),
       ]);
    rtOut(['ok' => true, 'id' => $db->lastInsertId()], 201);
}

// ── PUT update ─────────────────────────────────────────────────
if ($method === 'PUT' && $action === 'update') {
    if (!$id) rtErr('ID requerido');
    $b = json_decode(file_get_contents('php://input'), true);
    $sets = []; $vals = [];
    foreach (['first_name','last_name','dni','address','email','phone','notes'] as $f) {
        if (array_key_exists($f, $b)) { $sets[] = "$f=?"; $vals[] = trim($b[$f]); }
    }
    if ($sets) { $vals[] = $id; $db->prepare("UPDATE clients SET ".implode(',',$sets)." WHERE id=?")->execute($vals); }
    rtOut(['ok' => true]);
}

// ── DELETE (borrado lógico) ──────────────────────────────────────
if ($method === 'DELETE' && $action === 'delete') {
    if (!$id) rtErr('ID requerido');
    $db->prepare("UPDATE clients SET active=0 WHERE id=?")->execute([$id]);
    rtOut(['ok' => true]);
}

// ── Acompañantes ──────────────────────────────────────────────────
if ($method === 'POST' && $action === 'add_companion') {
    $clientId = intval($_GET['client_id'] ?? 0);
    if (!$clientId) rtErr('client_id requerido');
    $b = json_decode(file_get_contents('php://input'), true);
    $firstName = trim($b['first_name'] ?? '');
    if (!$firstName) rtErr('El nombre del acompañante es requerido');
    $db->prepare("INSERT INTO client_companions (client_id,first_name,last_name,dni,relationship) VALUES (?,?,?,?,?)")
       ->execute([$clientId, $firstName, trim($b['last_name']??''), trim($b['dni']??''), trim($b['relationship']??'')]);
    rtOut(['ok' => true, 'companions' => loadCompanions($db, $clientId)], 201);
}

if ($method === 'PUT' && $action === 'update_companion') {
    if (!$id) rtErr('ID requerido');
    $b = json_decode(file_get_contents('php://input'), true);
    $sets = []; $vals = [];
    foreach (['first_name','last_name','dni','relationship'] as $f) {
        if (array_key_exists($f, $b)) { $sets[] = "$f=?"; $vals[] = trim($b[$f]); }
    }
    if ($sets) { $vals[] = $id; $db->prepare("UPDATE client_companions SET ".implode(',',$sets)." WHERE id=?")->execute($vals); }
    rtOut(['ok' => true]);
}

if ($method === 'DELETE' && $action === 'delete_companion') {
    if (!$id) rtErr('ID requerido');
    $db->prepare("DELETE FROM client_companions WHERE id=?")->execute([$id]);
    rtOut(['ok' => true]);
}

rtErr('Acción no encontrada', 404);