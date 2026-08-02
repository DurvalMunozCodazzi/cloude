<?php
require_once '../config.php';
$me     = rtRequireAuth();
$db     = rtDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$id     = intval($_GET['id'] ?? 0);

// ── GET list ─────────────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'list') {
    $rows = $db->query("SELECT * FROM rt_long_stay_discounts ORDER BY min_nights")->fetchAll();
    rtOut(['discounts' => $rows]);
}

// ── POST create ──────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'create') {
    rtRequireAdmin();
    $b = json_decode(file_get_contents('php://input'), true);
    $minNights = intval($b['min_nights'] ?? 0);
    $pct       = floatval($b['discount_pct'] ?? 0);
    if ($minNights < 1) rtErr('El mínimo de noches debe ser al menos 1');
    if ($pct <= 0 || $pct > 100) rtErr('El descuento debe ser un porcentaje entre 1 y 100');

    $db->prepare("INSERT INTO rt_long_stay_discounts (min_nights,discount_pct) VALUES (?,?)")
       ->execute([$minNights, $pct]);
    rtOut(['ok' => true, 'id' => $db->lastInsertId()], 201);
}

// ── DELETE ─────────────────────────────────────────────────────────────
if ($method === 'DELETE' && $action === 'delete') {
    rtRequireAdmin();
    if (!$id) rtErr('ID requerido');
    $db->prepare("DELETE FROM rt_long_stay_discounts WHERE id=?")->execute([$id]);
    rtOut(['ok' => true]);
}

rtErr('Acción no encontrada', 404);
