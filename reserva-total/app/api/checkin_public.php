<?php
// Envío del checklist de check-in digital — sin sesión, protegido por el
// checkin_token de la reserva (igual patrón que el token público de recursos).
require_once '../config.php';
$db     = rtDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'POST' && $action === 'submit') {
    $token = trim($_GET['token'] ?? '');
    $st = $db->prepare("SELECT id FROM reservations WHERE checkin_token=?");
    $st->execute([$token]);
    $rv = $st->fetch();
    if (!$rv) rtErr('Enlace inválido o vencido', 404);
    $id = $rv['id'];

    $name      = trim($_POST['guest_name']    ?? '');
    $email     = trim($_POST['guest_email']   ?? '');
    $phone     = trim($_POST['guest_phone']   ?? '');
    $doc       = trim($_POST['guest_doc']     ?? '');
    $address   = trim($_POST['guest_address'] ?? '');
    $signature = trim($_POST['signature_name'] ?? '');
    $accepted  = !empty($_POST['accepted_terms']);
    $valuables = trim($_POST['valuables'] ?? '');

    if (!$name)      rtErr('El nombre es requerido');
    if (!$doc)       rtErr('El DNI o pasaporte es obligatorio para el registro de huéspedes');
    if (!$signature) rtErr('Ingresá tu nombre completo como firma');
    if (!$accepted)  rtErr('Debés aceptar los términos y condiciones');

    $db->prepare("UPDATE reservations SET guest_name=?, guest_email=?, guest_phone=?, guest_doc=?, guest_address=? WHERE id=?")
       ->execute([$name, $email, $phone, $doc, $address, $id]);

    $photoPath = null;
    if (!empty($_FILES['dni_photo']['tmp_name']) && is_uploaded_file($_FILES['dni_photo']['tmp_name'])) {
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $mime    = mime_content_type($_FILES['dni_photo']['tmp_name']);
        if (!isset($allowed[$mime])) rtErr('La foto del DNI debe ser JPG, PNG o WEBP');
        if ($_FILES['dni_photo']['size'] > 8 * 1024 * 1024) rtErr('La foto no puede superar 8MB');
        if (!is_dir(RT_UPLOAD_DIR)) @mkdir(RT_UPLOAD_DIR, 0755, true);
        $fname = 'dni_' . $id . '_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
        if (!move_uploaded_file($_FILES['dni_photo']['tmp_name'], RT_UPLOAD_DIR . $fname)) {
            rtErr('No se pudo guardar la foto');
        }
        $photoPath = $fname;
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $existing = $db->prepare("SELECT id, dni_photo_path FROM rt_checkin_submissions WHERE reservation_id=?");
    $existing->execute([$id]);
    $row = $existing->fetch();

    if ($row) {
        $finalPhoto = $photoPath ?: $row['dni_photo_path'];
        $db->prepare("UPDATE rt_checkin_submissions
                      SET signature_name=?, accepted_terms=1, valuables=?, submitted_at=NOW(), ip_address=?, dni_photo_path=?
                      WHERE reservation_id=?")
           ->execute([$signature, $valuables, $ip, $finalPhoto, $id]);
    } else {
        $db->prepare("INSERT INTO rt_checkin_submissions
                      (reservation_id,dni_photo_path,signature_name,accepted_terms,valuables,submitted_at,ip_address)
                      VALUES (?,?,?,1,?,NOW(),?)")
           ->execute([$id, $photoPath, $signature, $valuables, $ip]);
    }

    rtOut(['ok' => true]);
}

rtErr('Acción no encontrada', 404);
