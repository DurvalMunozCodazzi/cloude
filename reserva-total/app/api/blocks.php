<?php
require_once '../config.php';
$me     = rtRequireAuth();
$db     = rtDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$id     = intval($_GET['id'] ?? 0);

// ── GET list — por mes (para el calendario) o todos los vigentes/futuros ──
if ($method === 'GET' && $action === 'list') {
    if (isset($_GET['all'])) {
        $rows = $db->query("SELECT b.*, res.name as resource_name, res.color as resource_color
            FROM rt_blocked_dates b JOIN resources res ON res.id = b.resource_id
            WHERE b.date_end >= NOW() ORDER BY b.date_start ASC")->fetchAll();
        rtOut(['blocks' => $rows]);
    }

    $year  = intval($_GET['year']  ?? date('Y'));
    $month = intval($_GET['month'] ?? date('n'));
    $resId = intval($_GET['resource_id'] ?? 0);

    $from = sprintf('%04d-%02d-01 00:00:00', $year, $month);
    $to   = date('Y-m-t 23:59:59', strtotime($from));

    $sql = "SELECT b.*, res.name as resource_name, res.color as resource_color
            FROM rt_blocked_dates b JOIN resources res ON res.id = b.resource_id
            WHERE b.date_start <= ? AND b.date_end >= ?";
    $params = [$to, $from];
    if ($resId) { $sql .= " AND b.resource_id=?"; $params[] = $resId; }
    $sql .= " ORDER BY b.date_start ASC";

    $st = $db->prepare($sql); $st->execute($params);
    rtOut(['blocks' => $st->fetchAll()]);
}

// ── POST create ────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'create') {
    rtRequireAdmin();
    $b = json_decode(file_get_contents('php://input'), true);

    $resourceId = intval($b['resource_id'] ?? 0);
    $dateStart  = trim($b['date_start'] ?? '');
    $dateEnd    = trim($b['date_end']   ?? '');
    $reason     = trim($b['reason']     ?? '');

    if (!$resourceId) rtErr('Seleccioná un recurso');
    if (!$dateStart || !$dateEnd) rtErr('Fechas de inicio y fin requeridas');
    if ($dateEnd <= $dateStart) rtErr('La fecha de fin debe ser posterior a la de inicio');

    $conflictRes = $db->prepare("SELECT id FROM reservations
        WHERE resource_id=? AND status!='cancelled' AND check_in < ? AND check_out > ?");
    $conflictRes->execute([$resourceId, $dateEnd, $dateStart]);
    if ($conflictRes->fetch()) rtErr('Ya hay una reserva de un huésped en ese período — cancelala primero si querés bloquear esas fechas');

    $conflictBlk = $db->prepare("SELECT id FROM rt_blocked_dates
        WHERE resource_id=? AND date_start < ? AND date_end > ?");
    $conflictBlk->execute([$resourceId, $dateEnd, $dateStart]);
    if ($conflictBlk->fetch()) rtErr('Ese período ya está bloqueado');

    $db->prepare("INSERT INTO rt_blocked_dates (resource_id,date_start,date_end,reason,source,created_by)
                  VALUES (?,?,?,?,'manual',?)")
       ->execute([$resourceId, $dateStart, $dateEnd, $reason, $me['id']]);

    rtOut(['ok' => true, 'id' => $db->lastInsertId()], 201);
}

// ── DELETE ─────────────────────────────────────────────────────────────
if ($method === 'DELETE' && $action === 'delete') {
    rtRequireAdmin();
    if (!$id) rtErr('ID requerido');
    $db->prepare("DELETE FROM rt_blocked_dates WHERE id=?")->execute([$id]);
    rtOut(['ok' => true]);
}

rtErr('Acción no encontrada', 404);
