<?php
// Reserva Total — diagnóstico de conexión a base de datos.
// Borrar este archivo del servidor después de usarlo (no requiere login,
// no expone contraseñas, pero confirma configuración interna).
header('Content-Type: text/plain; charset=utf-8');

echo "=== Reserva Total — Diagnóstico ===\n\n";

$cfgFile = __DIR__ . '/rt-config.php';
echo "1) Archivo de configuración esperado:\n   $cfgFile\n";
echo "   ¿Existe? " . (file_exists($cfgFile) ? "SÍ" : "NO") . "\n";

if (file_exists($cfgFile)) {
    echo "   ¿Es legible? " . (is_readable($cfgFile) ? "SÍ" : "NO") . "\n";
    echo "   Tamaño: " . filesize($cfgFile) . " bytes\n";
    echo "   Última modificación: " . date('Y-m-d H:i:s', filemtime($cfgFile)) . "\n\n";

    $raw = file_get_contents($cfgFile);
    $masked = preg_replace("/(define\('RT_DB_PASS',\s*')[^']*(')/", '$1***OCULTA***$2', $raw);
    echo "2) Contenido (contraseña oculta):\n";
    echo "---------------------------------\n";
    echo $masked . "\n";
    echo "---------------------------------\n\n";
} else {
    echo "\n";
}

echo "3) ¿La carpeta app/ permite que el plugin escriba el archivo?\n";
echo "   Permisos de " . __DIR__ . ": " . substr(sprintf('%o', fileperms(__DIR__)), -4) . "\n";
echo "   ¿Escribible por PHP? " . (is_writable(__DIR__) ? "SÍ" : "NO — este es probablemente el problema") . "\n\n";

echo "4) Intento real de conexión con las credenciales actuales:\n";
$_rt_cred = $cfgFile;
if (file_exists($_rt_cred)) require_once $_rt_cred;
if (!defined('RT_DB_HOST')) {
    define('RT_DB_HOST', 'localhost');
    define('RT_DB_NAME', 'admin_reservatotal');
    define('RT_DB_USER', 'root');
    define('RT_DB_PASS', '');
    define('RT_DB_CHARSET', 'utf8mb4');
}
echo "   RT_DB_HOST = " . RT_DB_HOST . "\n";
echo "   RT_DB_NAME = " . RT_DB_NAME . "\n";
echo "   RT_DB_USER = " . RT_DB_USER . "\n";
echo "   RT_DB_PASS = " . (RT_DB_PASS === '' ? '(vacía)' : '(definida, ' . strlen(RT_DB_PASS) . ' caracteres)') . "\n\n";

try {
    $pdo = new PDO(
        "mysql:host=" . RT_DB_HOST . ";dbname=" . RT_DB_NAME . ";charset=" . (defined('RT_DB_CHARSET') ? RT_DB_CHARSET : 'utf8mb4'),
        RT_DB_USER, RT_DB_PASS,
        [PDO::ATTR_TIMEOUT => 5]
    );
    $count = $pdo->query("SELECT COUNT(*) FROM rt_users")->fetchColumn();
    echo "   ✓ CONEXIÓN EXITOSA. Usuarios en rt_users: $count\n";
} catch (\Throwable $e) {
    echo "   ✗ FALLÓ LA CONEXIÓN:\n   " . $e->getMessage() . "\n";
}

echo "\n=== Fin del diagnóstico — borrá este archivo del servidor ===\n";
