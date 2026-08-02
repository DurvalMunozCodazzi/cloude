<?php
// ── Galería de fotos por recurso ─────────────────────────────────────────────
// Subida/borrado solo admin; el listado autenticado lo usa el modal de
// Recursos, y book.php las recibe vía booking_public.php (sin sesión).
require_once '../config.php';
$me     = rtRequireAuth();
$db     = rtDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$id     = intval($_GET['id'] ?? 0);

// ── GET list por recurso ─────────────────────────────────────────────────
if ($method === 'GET' && $action === 'list') {
    $resId = intval($_GET['resource_id'] ?? 0);
    if (!$resId) rtErr('resource_id requerido');
    $st = $db->prepare("SELECT * FROM resource_photos WHERE resource_id=? ORDER BY position, id");
    $st->execute([$resId]);
    rtOut(['photos' => $st->fetchAll()]);
}

// ── POST subir foto (multipart) ──────────────────────────────────────────
if ($method === 'POST' && $action === 'upload') {
    rtRequireAdmin();
    $resId = intval($_POST['resource_id'] ?? 0);
    if (!$resId) rtErr('resource_id requerido');

    $chk = $db->prepare("SELECT id FROM resources WHERE id=?"); $chk->execute([$resId]);
    if (!$chk->fetch()) rtErr('Recurso no encontrado', 404);

    if (empty($_FILES['photo']['tmp_name']) || !is_uploaded_file($_FILES['photo']['tmp_name'])) {
        rtErr('No llegó ningún archivo');
    }
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mime    = mime_content_type($_FILES['photo']['tmp_name']);
    if (!isset($allowed[$mime])) rtErr('La foto debe ser JPG, PNG o WEBP');
    if ($_FILES['photo']['size'] > 10 * 1024 * 1024) rtErr('La foto no puede superar 10MB');

    if (!is_dir(RT_UPLOAD_DIR)) @mkdir(RT_UPLOAD_DIR, 0755, true);
    $fname = 'res_' . $resId . '_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($_FILES['photo']['tmp_name'], RT_UPLOAD_DIR . $fname)) {
        rtErr('No se pudo guardar la foto — revisá permisos de app/uploads/');
    }

    $pos = intval($db->query("SELECT COALESCE(MAX(position),0)+1 FROM resource_photos WHERE resource_id=" . intval($resId))->fetchColumn());
    $db->prepare("INSERT INTO resource_photos (resource_id, filename, caption, position) VALUES (?,?,?,?)")
       ->execute([$resId, $fname, trim($_POST['caption'] ?? ''), $pos]);

    $st = $db->prepare("SELECT * FROM resource_photos WHERE id=?");
    $st->execute([$db->lastInsertId()]);
    rtOut(['photo' => $st->fetch()], 201);
}

// ── PUT actualizar epígrafe ──────────────────────────────────────────────
if ($method === 'PUT' && $action === 'update') {
    rtRequireAdmin();
    if (!$id) rtErr('ID requerido');
    $b = json_decode(file_get_contents('php://input'), true);
    if (array_key_exists('caption', $b)) {
        $db->prepare("UPDATE resource_photos SET caption=? WHERE id=?")->execute([trim($b['caption']), $id]);
    }
    rtOut(['ok' => true]);
}

// ── DELETE ───────────────────────────────────────────────────────────────
if ($method === 'DELETE' && $action === 'delete') {
    rtRequireAdmin();
    if (!$id) rtErr('ID requerido');
    $st = $db->prepare("SELECT filename FROM resource_photos WHERE id=?");
    $st->execute([$id]);
    if ($row = $st->fetch()) {
        // El nombre viene de nuestra propia DB pero igual lo saneamos antes de tocar disco
        $safe = basename($row['filename']);
        if ($safe && file_exists(RT_UPLOAD_DIR . $safe)) @unlink(RT_UPLOAD_DIR . $safe);
    }
    $db->prepare("DELETE FROM resource_photos WHERE id=?")->execute([$id]);
    rtOut(['ok' => true]);
}

rtErr('Acción no encontrada', 404);
