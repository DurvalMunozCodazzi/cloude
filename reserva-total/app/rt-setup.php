<?php
/**
 * Reserva Total — Asistente de configuración de base de datos
 * Acceder una sola vez: /wp-content/plugins/reserva-total/app/rt-setup.php
 * ELIMINAR del servidor después de configurar.
 */

define('RT_SETUP_VERSION', '2.4.0');
$configFile = __DIR__ . '/rt-config.php';
$msg = '';
$msgType = '';

// ── Procesar formulario ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'test') {
        $h = trim($_POST['db_host'] ?? 'localhost');
        $d = trim($_POST['db_name'] ?? '');
        $u = trim($_POST['db_user'] ?? '');
        $p = trim($_POST['db_pass'] ?? '');
        try {
            $dsn = "mysql:host={$h};dbname={$d};charset=utf8mb4";
            $pdo = new PDO($dsn, $u, $p, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $v   = $pdo->query("SELECT VERSION()")->fetchColumn();
            $msg = "✅ Conexión exitosa — MySQL $v · Base de datos: <strong>$d</strong>";
            $msgType = 'ok';
        } catch (PDOException $e) {
            $msg = '❌ Error: ' . htmlspecialchars($e->getMessage());
            $msgType = 'err';
        }
    }

    if ($_POST['action'] === 'save') {
        $h   = trim($_POST['db_host']    ?? 'localhost');
        $d   = trim($_POST['db_name']    ?? '');
        $u   = trim($_POST['db_user']    ?? '');
        $p   = trim($_POST['db_pass']    ?? '');
        $url = rtrim(trim($_POST['site_url'] ?? ''), '/');
        $secret = bin2hex(random_bytes(16));

        // Validación básica
        if (!$d || !$u) {
            $msg = '❌ Nombre de base de datos y usuario son obligatorios.';
            $msgType = 'err';
        } else {
            // Probar conexión antes de guardar
            try {
                $dsn = "mysql:host={$h};dbname={$d};charset=utf8mb4";
                $pdo = new PDO($dsn, $u, $p, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            } catch (PDOException $e) {
                $msg = '❌ No se pudo conectar: ' . htmlspecialchars($e->getMessage());
                $msgType = 'err';
                goto render;
            }

            if (!$url) {
                // Autodetectar URL del plugin
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
                // Subir 2 niveles desde app/ hasta plugin root, luego bajar a app/
                $url    = $scheme . '://' . $host;
            }
            $siteUrl   = $url . '/wp-content/plugins/reserva-total/app/';
            $uploadUrl = $siteUrl . 'uploads/';
            $uploadDir = __DIR__ . '/uploads/';

            $c  = "<?php\n// Reserva Total — generado por rt-setup.php\n// " . date('Y-m-d H:i:s') . "\n\n";
            $c .= "define('RT_DB_HOST',    " . var_export($h,         true) . ");\n";
            $c .= "define('RT_DB_NAME',    " . var_export($d,         true) . ");\n";
            $c .= "define('RT_DB_USER',    " . var_export($u,         true) . ");\n";
            $c .= "define('RT_DB_PASS',    " . var_export($p,         true) . ");\n";
            $c .= "define('RT_DB_CHARSET', 'utf8mb4');\n";
            $c .= "define('RT_SITE_URL',   " . var_export($siteUrl,   true) . ");\n";
            $c .= "define('RT_UPLOAD_URL', " . var_export($uploadUrl, true) . ");\n";
            $c .= "define('RT_UPLOAD_DIR', " . var_export($uploadDir, true) . ");\n";
            $c .= "define('RT_CRON_SECRET'," . var_export($secret,    true) . ");\n";
            $c .= "define('RT_SESSION_HOURS', 24);\n";
            $c .= "define('RT_LICENSE_KEY', '');\n";

            if (@file_put_contents($configFile, $c) !== false) {
                // Crear tablas y admin por defecto
                $created = setupTables($pdo);
                $msg = "✅ Configuración guardada. $created<br>
                    <strong>¡Eliminar este archivo del servidor!</strong>
                    Ya puede ingresar a la app.";
                $msgType = 'ok';
            } else {
                $msg = '❌ No se pudo escribir rt-config.php — verificar permisos del directorio app/';
                $msgType = 'err';
            }
        }
    }
}
render:

// ── Crear tablas si no existen ───────────────────────────────────────────────
function setupTables(PDO $pdo) {
    $charset = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $pdo->exec("CREATE TABLE IF NOT EXISTS `rt_users` (
        `id`         INT NOT NULL AUTO_INCREMENT,
        `username`   VARCHAR(80)  NOT NULL,
        `name`       VARCHAR(200) NOT NULL,
        `email`      VARCHAR(200) DEFAULT NULL,
        `password`   VARCHAR(255) NOT NULL,
        `role`       ENUM('admin','member') DEFAULT 'member',
        `active`     TINYINT DEFAULT 1,
        `color`      VARCHAR(20)  DEFAULT '#0d9488',
        `photo`      VARCHAR(255) DEFAULT NULL,
        `phone`      VARCHAR(30)  DEFAULT NULL,
        `notes`      TEXT         DEFAULT NULL,
        `last_login` DATETIME     DEFAULT NULL,
        `created_at` DATETIME     DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `username` (`username`)
    ) $charset");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `rt_sessions` (
        `id`         INT NOT NULL AUTO_INCREMENT,
        `user_id`    INT NOT NULL,
        `token`      VARCHAR(64) NOT NULL,
        `expires_at` DATETIME NOT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `token` (`token`)
    ) $charset");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `resources` (
        `id`             INT NOT NULL AUTO_INCREMENT,
        `name`           VARCHAR(200) NOT NULL,
        `description`    TEXT         DEFAULT NULL,
        `type`           VARCHAR(50)  DEFAULT 'room',
        `price_per_day`  DECIMAL(10,2) DEFAULT 0,
        `price_per_hour` DECIMAL(10,2) DEFAULT NULL,
        `capacity`       INT DEFAULT 1,
        `color`          VARCHAR(20)  DEFAULT '#0d9488',
        `photo`          TEXT         DEFAULT NULL,
        `amenities`      TEXT         DEFAULT NULL,
        `active`         TINYINT DEFAULT 1,
        `position`       INT DEFAULT 0,
        `created_at`     DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at`     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) $charset");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `reservations` (
        `id`             INT NOT NULL AUTO_INCREMENT,
        `resource_id`    INT NOT NULL,
        `guest_name`     VARCHAR(200) NOT NULL,
        `guest_email`    VARCHAR(200) DEFAULT NULL,
        `guest_phone`    VARCHAR(50)  DEFAULT NULL,
        `guest_doc`      VARCHAR(50)  DEFAULT NULL,
        `check_in`       DATETIME NOT NULL,
        `check_out`      DATETIME NOT NULL,
        `is_hourly`      TINYINT DEFAULT 0,
        `adults`         INT DEFAULT 1,
        `children`       INT DEFAULT 0,
        `total_price`    DECIMAL(10,2) DEFAULT 0,
        `status`         VARCHAR(20) DEFAULT 'confirmed',
        `payment_method` VARCHAR(50)  DEFAULT 'cash',
        `payment_id`     VARCHAR(200) DEFAULT NULL,
        `notes`          TEXT         DEFAULT NULL,
        `internal_notes` TEXT         DEFAULT NULL,
        `created_by`     INT          DEFAULT NULL,
        `created_at`     DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at`     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `resource_id` (`resource_id`),
        KEY `check_in`    (`check_in`),
        KEY `status`      (`status`)
    ) $charset");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `rt_settings` (
        `id`         INT NOT NULL AUTO_INCREMENT,
        `meta_key`   VARCHAR(100) NOT NULL,
        `meta_value` LONGTEXT DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `meta_key` (`meta_key`)
    ) $charset");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `reservation_extras` (
        `id`             INT NOT NULL AUTO_INCREMENT,
        `reservation_id` INT NOT NULL,
        `name`           VARCHAR(200) NOT NULL,
        `price`          DECIMAL(10,2) DEFAULT 0,
        `qty`            INT DEFAULT 1,
        `created_at`     DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `reservation_id` (`reservation_id`)
    ) $charset");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `extras_catalog` (
        `id`          INT NOT NULL AUTO_INCREMENT,
        `name`        VARCHAR(200) NOT NULL,
        `description` VARCHAR(500) DEFAULT NULL,
        `price`       DECIMAL(10,2) DEFAULT 0,
        `category`    VARCHAR(100) DEFAULT 'General',
        `icon`        VARCHAR(50)  DEFAULT 'fa-star',
        `active`      TINYINT DEFAULT 1,
        `position`    INT DEFAULT 0,
        `created_at`  DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) $charset");

    // Admin por defecto si no existe
    $admins = $pdo->query("SELECT COUNT(*) FROM rt_users WHERE role='admin'")->fetchColumn();
    if (!$admins) {
        $hash = password_hash('admin123', PASSWORD_DEFAULT);
        $st = $pdo->prepare("INSERT INTO rt_users (username,name,email,password,role,color) VALUES (?,?,?,?,?,?)");
        $st->execute(['admin','Administrador','',$hash,'admin','#0d9488']);
        return "Tablas creadas · Admin creado (usuario: <strong>admin</strong>, contraseña: <strong>admin123</strong>).";
    }
    return "Tablas verificadas.";
}

// Leer configuración actual si existe
$current = [];
if (file_exists($configFile)) {
    $lines = file($configFile);
    foreach ($lines as $line) {
        if (preg_match("/define\('RT_DB_HOST',\s*'([^']*)'\)/", $line, $m))  $current['host'] = $m[1];
        if (preg_match("/define\('RT_DB_NAME',\s*'([^']*)'\)/", $line, $m))  $current['name'] = $m[1];
        if (preg_match("/define\('RT_DB_USER',\s*'([^']*)'\)/", $line, $m))  $current['user'] = $m[1];
        if (preg_match("/define\('RT_SITE_URL',\s*'([^']*)'\)/", $line, $m)) $current['url']  = $m[1];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reserva Total — Configuración</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#0a0a14;color:#e2e8f0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
  .card{background:#13131f;border:1px solid #1e2035;border-radius:16px;padding:40px;width:100%;max-width:520px;box-shadow:0 20px 60px rgba(0,0,0,.5)}
  .logo{display:flex;align-items:center;gap:12px;margin-bottom:8px}
  .logo svg{width:42px;height:42px}
  .logo h1{font-size:24px;font-weight:700;color:#fff}
  .subtitle{color:#64748b;font-size:13px;margin-bottom:32px}
  .section{margin-bottom:24px}
  label{display:block;font-size:12px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px}
  input{width:100%;background:#0a0a14;border:1px solid #1e2035;border-radius:8px;color:#e2e8f0;padding:10px 14px;font-size:14px;outline:none;transition:border .2s}
  input:focus{border-color:#0d9488}
  .row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  .btns{display:flex;gap:10px;margin-top:28px}
  .btn{flex:1;padding:12px;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;transition:opacity .2s}
  .btn:hover{opacity:.85}
  .btn-test{background:#1e2035;color:#94a3b8}
  .btn-save{background:#0d9488;color:#fff}
  .msg{padding:14px 16px;border-radius:8px;font-size:13px;line-height:1.6;margin-bottom:20px}
  .msg.ok{background:#0d948820;border:1px solid #0d9488;color:#5eead4}
  .msg.err{background:#ef444420;border:1px solid #ef4444;color:#fca5a5}
  .info{background:#1e2035;border-radius:8px;padding:16px;font-size:12px;color:#64748b;line-height:1.8;margin-top:24px}
  .info strong{color:#94a3b8}
  .warn{background:#f59e0b20;border:1px solid #f59e0b;border-radius:8px;padding:12px 16px;font-size:12px;color:#fcd34d;margin-top:16px}
  footer{text-align:center;color:#334155;font-size:11px;margin-top:24px}
</style>
</head>
<body>
<div class="card">
  <div class="logo">
    <svg viewBox="0 0 40 40" fill="none">
      <rect width="40" height="40" rx="10" fill="#0d9488"/>
      <path d="M10 14h20M10 20h14M10 26h17" stroke="#fff" stroke-width="2.5" stroke-linecap="round"/>
      <circle cx="30" cy="26" r="5" fill="#fff" fill-opacity=".15" stroke="#fff" stroke-width="2"/>
      <path d="M28 26l1.5 1.5L32 24" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
    <h1>Reserva Total</h1>
  </div>
  <p class="subtitle">Asistente de configuración · v<?= RT_SETUP_VERSION ?></p>

  <?php if ($msg): ?>
  <div class="msg <?= $msgType ?>"><?= $msg ?></div>
  <?php endif; ?>

  <form method="POST">
    <div class="section">
      <div class="row">
        <div>
          <label>Host MySQL</label>
          <input name="db_host" value="<?= htmlspecialchars($_POST['db_host'] ?? $current['host'] ?? 'localhost') ?>" placeholder="localhost">
        </div>
        <div>
          <label>Base de datos</label>
          <input name="db_name" value="<?= htmlspecialchars($_POST['db_name'] ?? $current['name'] ?? 'admin_reservatotal') ?>" placeholder="admin_reservatotal">
        </div>
      </div>
    </div>
    <div class="section">
      <div class="row">
        <div>
          <label>Usuario MySQL</label>
          <input name="db_user" value="<?= htmlspecialchars($_POST['db_user'] ?? $current['user'] ?? '') ?>" placeholder="usuario_db">
        </div>
        <div>
          <label>Contraseña MySQL</label>
          <input type="password" name="db_pass" value="" placeholder="••••••••">
        </div>
      </div>
    </div>
    <div class="section">
      <label>URL del sitio (opcional)</label>
      <input name="site_url" value="<?= htmlspecialchars($_POST['site_url'] ?? rtrim($current['url'] ?? '', '/wp-content/plugins/reserva-total/app/')) ?>" placeholder="https://reservatotal.com.ar">
      <p style="font-size:11px;color:#475569;margin-top:4px">Si se deja vacío se detecta automáticamente desde el servidor.</p>
    </div>
    <div class="btns">
      <button class="btn btn-test" type="submit" name="action" value="test">Probar conexión</button>
      <button class="btn btn-save" type="submit" name="action" value="save">Guardar y crear tablas</button>
    </div>
  </form>

  <div class="info">
    <strong>El archivo rt-config.php se genera en:</strong><br>
    <code><?= htmlspecialchars($configFile) ?></code><br><br>
    <strong>Credenciales para reservatotal.com.ar:</strong><br>
    Host: <code>localhost</code> · DB: <code>admin_reservatotal</code><br>
    Usuario y contraseña: los del panel de hosting cPanel
  </div>

  <div class="warn">
    ⚠️ <strong>Eliminar este archivo del servidor</strong> una vez configurado.<br>
    No protege el acceso con contraseña.
  </div>

  <footer>Diseñado y programado por Durval Muñoz Codazzi</footer>
</div>
</body>
</html>
