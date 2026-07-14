<?php
// ── Error handler — devuelve JSON en lugar de HTML ─────────────────────────
set_exception_handler(function($e) {
    http_response_code(500);
    @header('Content-Type: application/json');
    echo json_encode(['error' => 'Exception: ' . $e->getMessage(), 'line' => $e->getLine()]);
    exit;
});
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) return false;
    throw new \ErrorException($errstr, $errno, 0, $errfile, $errline);
}, E_ALL & ~E_NOTICE & ~E_DEPRECATED);

// 1) Cargar credenciales generadas por el plugin WP (si existen)
$_rt_cred = __DIR__ . '/rt-config.php';
if (file_exists($_rt_cred)) require_once $_rt_cred;

// 2) Fallback — credenciales de producción
if (!defined('RT_DB_HOST')) {
    define('RT_DB_HOST',    'localhost');
    define('RT_DB_NAME',    'admin_reservatotal');
    define('RT_DB_USER',    'root');
    define('RT_DB_PASS',    '');
    define('RT_DB_CHARSET', 'utf8mb4');
    define('RT_SITE_URL',   '');
    define('RT_UPLOAD_URL', '');
    define('RT_UPLOAD_DIR', __DIR__ . '/uploads/');
}

// 3) Defaults opcionales
if (!defined('RT_DB_CHARSET'))   define('RT_DB_CHARSET',   'utf8mb4');
if (!defined('RT_SESSION_HOURS'))define('RT_SESSION_HOURS', 24);
if (!defined('RT_VERSION'))      define('RT_VERSION',       '2.4.0');
if (!defined('RT_CRON_SECRET'))  define('RT_CRON_SECRET',   '');
if (!defined('RT_LICENSE_KEY'))  define('RT_LICENSE_KEY',   '');
if (!defined('RT_UPLOAD_DIR'))   define('RT_UPLOAD_DIR',    __DIR__ . '/uploads/');

// ── Conexión PDO (singleton) ────────────────────────────────────────────────
function rtDB() {
    static $pdo = null;
    if ($pdo) return $pdo;
    try {
        $host = RT_DB_HOST; $port = ''; $socket = '';
        if (strpos($host, ':/') !== false) {
            [$host, $socket] = explode(':', $host, 2);
        } elseif (preg_match('/^(.*):(\d+)$/', $host, $m)) {
            $host = $m[1]; $port = $m[2];
        }
        if ($socket)   $dsn = "mysql:unix_socket={$socket};dbname=" . RT_DB_NAME . ";charset=" . RT_DB_CHARSET;
        elseif ($port) $dsn = "mysql:host={$host};port={$port};dbname=" . RT_DB_NAME . ";charset=" . RT_DB_CHARSET;
        else           $dsn = "mysql:host={$host};dbname=" . RT_DB_NAME . ";charset=" . RT_DB_CHARSET;
        $pdo = new PDO($dsn, RT_DB_USER, RT_DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        die(json_encode(['error' => 'DB error: ' . $e->getMessage()]));
    }
}

// ── Helpers ─────────────────────────────────────────────────────────────────
function rtOut($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function rtErr($msg, $code = 400) { rtOut(['error' => $msg], $code); }

function rtBearerToken() {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $k => $v)
            if (strtolower($k) === 'authorization') { $h = $v; break; }
    }
    if (preg_match('/Bearer\s+(.+)/i', $h, $m)) return trim($m[1]);
    if (!empty($_GET['token']))        return trim($_GET['token']);
    if (!empty($_COOKIE['rt_token'])) return trim($_COOKIE['rt_token']);
    return null;
}

function rtRequireAuth() {
    $token = rtBearerToken();
    if (!$token) rtErr('No autenticado', 401);
    $db = rtDB();
    $st = $db->prepare("SELECT u.* FROM rt_sessions s
        JOIN rt_users u ON u.id = s.user_id
        WHERE s.token=? AND s.expires_at>NOW() AND u.active=1");
    $st->execute([$token]);
    $user = $st->fetch();
    if (!$user) rtErr('Sesión inválida o expirada', 401);
    return $user;
}

function rtRequireAdmin() {
    $user = rtRequireAuth();
    if ($user['role'] !== 'admin') rtErr('Solo el administrador puede realizar esta acción', 403);
    return $user;
}

// ── CORS ────────────────────────────────────────────────────────────────────
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header("Access-Control-Allow-Origin: $origin");
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204); exit;
}
