<?php
require_once '../config.php';
require_once '../ical_sync.php';
$me     = rtRequireAuth();
$db     = rtDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$id     = intval($_GET['id'] ?? 0);

// ── GET list ─────────────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'list') {
    $rows = $db->query("SELECT i.*, res.name as resource_name
                         FROM rt_ical_imports i JOIN resources res ON res.id = i.resource_id
                         ORDER BY i.created_at DESC")->fetchAll();
    rtOut(['imports' => $rows]);
}

// ── POST create ──────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'create') {
    rtRequireAdmin();
    $b = json_decode(file_get_contents('php://input'), true);
    $resourceId = intval($b['resource_id'] ?? 0);
    $url        = trim($b['url'] ?? '');
    if (!$resourceId) rtErr('Seleccioná un recurso');
    if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) rtErr('Ingresá una URL de calendario (.ics) válida');

    $db->prepare("INSERT INTO rt_ical_imports (resource_id,name,url) VALUES (?,?,?)")
       ->execute([$resourceId, trim($b['name'] ?? ''), $url]);
    $newId = $db->lastInsertId();

    $st = $db->prepare("SELECT * FROM rt_ical_imports WHERE id=?"); $st->execute([$newId]);
    $import = $st->fetch();
    rtSyncIcalImport($db, $import);

    $st->execute([$newId]);
    rtOut(['import' => $st->fetch()], 201);
}

// ── POST sync (manual) ────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'sync') {
    rtRequireAdmin();
    if (!$id) rtErr('ID requerido');
    $st = $db->prepare("SELECT * FROM rt_ical_imports WHERE id=?"); $st->execute([$id]);
    $import = $st->fetch();
    if (!$import) rtErr('Importación no encontrada', 404);

    rtSyncIcalImport($db, $import);
    $st->execute([$id]);
    rtOut(['import' => $st->fetch()]);
}

// ── DELETE ─────────────────────────────────────────────────────────────
if ($method === 'DELETE' && $action === 'delete') {
    rtRequireAdmin();
    if (!$id) rtErr('ID requerido');
    $db->prepare("DELETE FROM rt_blocked_dates WHERE source=?")->execute(['ical:' . $id]);
    $db->prepare("DELETE FROM rt_ical_imports WHERE id=?")->execute([$id]);
    rtOut(['ok' => true]);
}

rtErr('Acción no encontrada', 404);
