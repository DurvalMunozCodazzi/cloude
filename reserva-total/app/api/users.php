<?php
require_once '../config.php';
$me     = rtRequireAuth();
$db     = rtDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$id     = intval($_GET['id'] ?? 0);

if ($method === 'GET' && $action === 'list') {
    rtRequireAdmin();
    $rows = $db->query("SELECT id,username,name,email,role,color,active,phone,notes,created_at,last_login FROM rt_users ORDER BY role,name")->fetchAll();
    rtOut(['users' => $rows]);
}

if ($method === 'GET' && $action === 'me') {
    $user = $me; unset($user['password']);
    rtOut(['user' => $user]);
}

if ($method === 'POST' && $action === 'create') {
    rtRequireAdmin();
    $b    = json_decode(file_get_contents('php://input'), true);
    $user = trim($b['username'] ?? '');
    $name = trim($b['name']     ?? '');
    $pass = trim($b['password'] ?? '');
    if (!$user || !$name || !$pass) rtErr('Usuario, nombre y contraseña son requeridos');
    $chk = $db->prepare("SELECT id FROM rt_users WHERE username=?"); $chk->execute([$user]);
    if ($chk->fetch()) rtErr('Ese nombre de usuario ya existe');
    $db->prepare("INSERT INTO rt_users (username,name,email,password,role,color,phone,notes,active) VALUES (?,?,?,?,?,?,?,?,?)")
       ->execute([$user, $name, trim($b['email']??''), password_hash($pass, PASSWORD_DEFAULT),
                  in_array($b['role']??'', ['admin','member']) ? $b['role'] : 'member',
                  substr(trim($b['color']??'#0d9488'),0,20), trim($b['phone']??''),
                  trim($b['notes']??''), 1]);
    rtOut(['ok' => true, 'id' => $db->lastInsertId()], 201);
}

if ($method === 'PUT' && $action === 'update') {
    if (!$id) rtErr('ID requerido');
    if ($me['role'] !== 'admin' && $me['id'] != $id) rtErr('Sin permisos', 403);
    $b = json_decode(file_get_contents('php://input'), true);
    $sets = []; $vals = [];
    foreach (['name','email','phone','color','notes','role','active'] as $f) {
        if (array_key_exists($f, $b)) {
            if ($f === 'role' && $me['role'] !== 'admin') continue;
            $sets[] = "$f=?"; $vals[] = $b[$f];
        }
    }
    if ($sets) { $vals[] = $id; $db->prepare("UPDATE rt_users SET ".implode(',',$sets)." WHERE id=?")->execute($vals); }
    rtOut(['ok' => true]);
}

if ($method === 'POST' && $action === 'change_password') {
    if (!$id) rtErr('ID requerido');
    if ($me['role'] !== 'admin' && $me['id'] != $id) rtErr('Sin permisos', 403);
    $b    = json_decode(file_get_contents('php://input'), true);
    $pass = trim($b['password'] ?? '');
    if (strlen($pass) < 4) rtErr('La contraseña debe tener al menos 4 caracteres');
    $db->prepare("UPDATE rt_users SET password=? WHERE id=?")->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
    rtOut(['ok' => true]);
}

if ($method === 'DELETE' && $action === 'delete') {
    rtRequireAdmin();
    if (!$id) rtErr('ID requerido');
    if ($id == $me['id']) rtErr('No podés eliminarte a vos mismo');
    $db->prepare("UPDATE rt_users SET active=0 WHERE id=?")->execute([$id]);
    rtOut(['ok' => true]);
}

rtErr('Acción no encontrada', 404);
