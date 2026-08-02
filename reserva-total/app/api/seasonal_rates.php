<?php
require_once '../config.php';
$me     = rtRequireAuth();
$db     = rtDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$id     = intval($_GET['id'] ?? 0);

// ── GET list — todas las tarifas (se cachean en el front como los recursos) ──
if ($method === 'GET' && $action === 'list') {
    $rows = $db->query("SELECT sr.*, res.name as resource_name
                         FROM rt_seasonal_rates sr
                         LEFT JOIN resources res ON res.id = sr.resource_id
                         ORDER BY sr.date_start")->fetchAll();
    rtOut(['rates' => $rows]);
}

// ── POST create ────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'create') {
    rtRequireAdmin();
    $b = json_decode(file_get_contents('php://input'), true);

    $name      = trim($b['name'] ?? '');
    $dateStart = trim($b['date_start'] ?? '');
    $dateEnd   = trim($b['date_end']   ?? '');
    if (!$name) rtErr('El nombre de la temporada es requerido');
    if (!$dateStart || !$dateEnd) rtErr('Fechas de inicio y fin requeridas');
    if ($dateEnd < $dateStart) rtErr('La fecha de fin debe ser posterior a la de inicio');

    $resourceId = intval($b['resource_id'] ?? 0) ?: null;

    $db->prepare("INSERT INTO rt_seasonal_rates (resource_id,name,date_start,date_end,price_per_day,price_per_hour)
                  VALUES (?,?,?,?,?,?)")
       ->execute([
           $resourceId, $name, $dateStart, $dateEnd,
           floatval($b['price_per_day'] ?? 0),
           isset($b['price_per_hour']) && $b['price_per_hour'] !== '' ? floatval($b['price_per_hour']) : null,
       ]);

    rtOut(['ok' => true, 'id' => $db->lastInsertId()], 201);
}

// ── PUT update ─────────────────────────────────────────────────────────
if ($method === 'PUT' && $action === 'update') {
    rtRequireAdmin();
    if (!$id) rtErr('ID requerido');
    $b = json_decode(file_get_contents('php://input'), true);
    $sets = []; $vals = [];
    $fields = ['resource_id','name','date_start','date_end','price_per_day','price_per_hour'];
    foreach ($fields as $f) {
        if (array_key_exists($f, $b)) {
            $sets[] = "$f=?";
            $vals[] = ($f === 'resource_id') ? (intval($b[$f]) ?: null) : $b[$f];
        }
    }
    if ($sets) {
        $vals[] = $id;
        $db->prepare("UPDATE rt_seasonal_rates SET " . implode(',', $sets) . " WHERE id=?")->execute($vals);
    }
    rtOut(['ok' => true]);
}

// ── DELETE ─────────────────────────────────────────────────────────────
if ($method === 'DELETE' && $action === 'delete') {
    rtRequireAdmin();
    if (!$id) rtErr('ID requerido');
    $db->prepare("DELETE FROM rt_seasonal_rates WHERE id=?")->execute([$id]);
    rtOut(['ok' => true]);
}

rtErr('Acción no encontrada', 404);
