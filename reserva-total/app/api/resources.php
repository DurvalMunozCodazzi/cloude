<?php
require_once '../config.php';
$me     = rtRequireAuth();
$db     = rtDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$id     = intval($_GET['id'] ?? 0);

// ── GET list ─────────────────────────────────────────────────
if ($method === 'GET' && $action === 'list') {
    $rows = $db->query("SELECT * FROM resources WHERE active=1 ORDER BY position,name")->fetchAll();
    rtOut(['resources' => $rows]);
}

// ── GET single ───────────────────────────────────────────────
if ($method === 'GET' && $action === 'get') {
    if (!$id) rtErr('ID requerido');
    $st = $db->prepare("SELECT * FROM resources WHERE id=?");
    $st->execute([$id]); $row = $st->fetch();
    if (!$row) rtErr('Recurso no encontrado', 404);
    rtOut(['resource' => $row]);
}

// ── POST create ──────────────────────────────────────────────
if ($method === 'POST' && $action === 'create') {
    rtRequireAdmin();
    $b = json_decode(file_get_contents('php://input'), true);
    $name  = trim($b['name'] ?? '');
    if (!$name) rtErr('El nombre es requerido');
    $db->prepare("INSERT INTO resources (name,description,type,price_per_day,price_per_hour,capacity,color,amenities,position) VALUES (?,?,?,?,?,?,?,?,?)")
       ->execute([
           $name,
           trim($b['description'] ?? ''),
           $b['type'] ?? 'room',
           floatval($b['price_per_day'] ?? 0),
           isset($b['price_per_hour']) && $b['price_per_hour'] !== '' ? floatval($b['price_per_hour']) : null,
           intval($b['capacity'] ?? 1),
           substr(trim($b['color'] ?? '#0d9488'), 0, 20),
           $b['amenities'] ?? null,
           intval($b['position'] ?? 0),
       ]);
    $newId = $db->lastInsertId();
    $st = $db->prepare("SELECT * FROM resources WHERE id=?"); $st->execute([$newId]);
    rtOut(['resource' => $st->fetch()], 201);
}

// ── PUT update ───────────────────────────────────────────────
if ($method === 'PUT' && $action === 'update') {
    rtRequireAdmin();
    if (!$id) rtErr('ID requerido');
    $b = json_decode(file_get_contents('php://input'), true);
    $sets = []; $vals = [];
    $fields = ['name','description','type','price_per_day','price_per_hour','capacity','color','amenities','position','photo','active'];
    foreach ($fields as $f) {
        if (array_key_exists($f, $b)) {
            $sets[] = "$f=?";
            $vals[] = ($f === 'color') ? substr(trim($b[$f]),0,20) : $b[$f];
        }
    }
    if ($sets) {
        $vals[] = $id;
        $db->prepare("UPDATE resources SET " . implode(',', $sets) . " WHERE id=?")->execute($vals);
    }
    rtOut(['ok' => true]);
}

// ── DELETE ───────────────────────────────────────────────────
if ($method === 'DELETE' && $action === 'delete') {
    rtRequireAdmin();
    if (!$id) rtErr('ID requerido');
    $db->prepare("UPDATE resources SET active=0 WHERE id=?")->execute([$id]);
    rtOut(['ok' => true]);
}

// ── POST reorder ─────────────────────────────────────────────
if ($method === 'POST' && $action === 'reorder') {
    rtRequireAdmin();
    $b = json_decode(file_get_contents('php://input'), true);
    foreach (($b['order'] ?? []) as $pos => $rid) {
        $db->prepare("UPDATE resources SET position=? WHERE id=?")->execute([$pos, $rid]);
    }
    rtOut(['ok' => true]);
}

rtErr('Acción no encontrada', 404);
