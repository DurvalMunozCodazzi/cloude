<?php
require_once '../config.php';
$me     = rtRequireAdmin();
$db     = rtDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ── GET list — últimos movimientos, opcionalmente filtrados por entidad ──
if ($method === 'GET' && $action === 'list') {
    $entity = trim($_GET['entity'] ?? '');
    $limit  = min(500, max(1, intval($_GET['limit'] ?? 200)));
    $sql = "SELECT a.*, u.name AS user_name, u.username AS user_username
            FROM rt_audit_log a
            LEFT JOIN rt_users u ON u.id = a.user_id";
    $params = [];
    if ($entity) { $sql .= " WHERE a.entity=?"; $params[] = $entity; }
    $sql .= " ORDER BY a.id DESC LIMIT $limit";
    $st = $db->prepare($sql);
    $st->execute($params);
    rtOut(['log' => $st->fetchAll()]);
}

rtErr('Acción no encontrada', 404);
