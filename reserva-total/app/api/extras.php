<?php
require_once '../config.php';
$me     = rtRequireAuth();
$db     = rtDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$id     = intval($_GET['id'] ?? 0);

// ── GET list (activos, para usar en reservas) ─────────────────
if ($method === 'GET' && $action === 'list') {
    $rows = $db->query("SELECT * FROM extras_catalog WHERE active=1 ORDER BY category,position,name")->fetchAll();
    rtOut(['extras' => $rows]);
}

// ── GET all (admin, incluye inactivos) ────────────────────────
if ($method === 'GET' && $action === 'all') {
    rtRequireAdmin();
    $rows = $db->query("SELECT * FROM extras_catalog ORDER BY category,position,name")->fetchAll();
    rtOut(['extras' => $rows]);
}

// ── POST create ───────────────────────────────────────────────
if ($method === 'POST' && $action === 'create') {
    rtRequireAdmin();
    $b    = json_decode(file_get_contents('php://input'), true);
    $name = trim($b['name'] ?? '');
    if (!$name) rtErr('El nombre es requerido');
    $db->prepare("INSERT INTO extras_catalog (name,description,price,category,icon,active,position)
                  VALUES (?,?,?,?,?,1,(SELECT COALESCE(MAX(position),0)+1 FROM extras_catalog ec))")
       ->execute([
           $name,
           trim($b['description'] ?? ''),
           floatval($b['price'] ?? 0),
           trim($b['category'] ?? 'General'),
           trim($b['icon'] ?? 'fa-star'),
       ]);
    rtOut(['ok' => true, 'id' => $db->lastInsertId()], 201);
}

// ── PUT update ────────────────────────────────────────────────
if ($method === 'PUT' && $action === 'update') {
    rtRequireAdmin();
    if (!$id) rtErr('ID requerido');
    $b    = json_decode(file_get_contents('php://input'), true);
    $sets = []; $vals = [];
    foreach (['name','description','price','category','icon','active','position'] as $f) {
        if (array_key_exists($f, $b)) { $sets[] = "$f=?"; $vals[] = $b[$f]; }
    }
    if ($sets) { $vals[] = $id; $db->prepare("UPDATE extras_catalog SET ".implode(',',$sets)." WHERE id=?")->execute($vals); }
    rtOut(['ok' => true]);
}

// ── DELETE (borrado físico) ───────────────────────────────────
if ($method === 'DELETE' && $action === 'delete') {
    rtRequireAdmin();
    if (!$id) rtErr('ID requerido');
    $db->prepare("DELETE FROM extras_catalog WHERE id=?")->execute([$id]);
    rtOut(['ok' => true]);
}

// ── POST toggle active ────────────────────────────────────────
if ($method === 'POST' && $action === 'toggle') {
    rtRequireAdmin();
    if (!$id) rtErr('ID requerido');
    $db->prepare("UPDATE extras_catalog SET active = 1 - active WHERE id=?")->execute([$id]);
    rtOut(['ok' => true]);
}

rtErr('Acción no encontrada', 404);
