<?php
// Calendario público de disponibilidad — sin login, protegido por el token
// del recurso. No expone datos de huéspedes, solo libre/ocupado por día.
require_once 'config.php';
$db = rtDB();

$token = $_GET['token'] ?? '';
$resource = rtResourceByToken($db, $token);

header('Content-Type: text/html; charset=utf-8');

if (!$resource) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Enlace inválido</title></head>
    <body style="font-family:Arial,sans-serif;text-align:center;padding:60px;color:#555">
    <h2>Enlace no válido o vencido</h2><p>Pedí un nuevo enlace al administrador.</p></body></html>';
    exit;
}

$year  = max(1970, intval($_GET['year']  ?? date('Y')));
$month = min(12, max(1, intval($_GET['month'] ?? date('n'))));

$monthStart = new DateTime(sprintf('%04d-%02d-01', $year, $month));
$monthEnd   = (clone $monthStart)->modify('first day of next month');
$daysInMonth = intval($monthStart->format('t'));
$firstWeekday = intval($monthStart->format('N')); // 1=lunes ... 7=domingo

$occupied = array_fill(1, $daysInMonth, false);

$st = $db->prepare("SELECT check_in, check_out FROM reservations
    WHERE resource_id=? AND status != 'cancelled' AND check_in < ? AND check_out > ?");
$st->execute([$resource['id'], $monthEnd->format('Y-m-d 00:00:00'), $monthStart->format('Y-m-d 00:00:00')]);
$ranges = $st->fetchAll();

$st2 = $db->prepare("SELECT date_start, date_end FROM rt_blocked_dates
    WHERE resource_id=? AND date_start < ? AND date_end > ?");
$st2->execute([$resource['id'], $monthEnd->format('Y-m-d 00:00:00'), $monthStart->format('Y-m-d 00:00:00')]);
$ranges = array_merge($ranges, $st2->fetchAll());

foreach ($ranges as $r) {
    $rs = max((new DateTime($r['check_in'] ?? $r['date_start']))->setTime(0,0,0), $monthStart);
    $re = min((new DateTime($r['check_out'] ?? $r['date_end']))->setTime(0,0,0), $monthEnd);
    for ($d = clone $rs; $d < $re; $d->modify('+1 day')) {
        $day = intval($d->format('j'));
        if (isset($occupied[$day])) $occupied[$day] = true;
    }
}

$monthsEs = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',
             7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];

$prevM = $month - 1; $prevY = $year; if ($prevM < 1)  { $prevM = 12; $prevY--; }
$nextM = $month + 1; $nextY = $year; if ($nextM > 12) { $nextM = 1;  $nextY++; }
$q = fn($y,$m) => '?token=' . urlencode($token) . '&year=' . $y . '&month=' . $m;

$cells = '';
for ($i = 1; $i < $firstWeekday; $i++) $cells .= '<div class="cell empty"></div>';
for ($d = 1; $d <= $daysInMonth; $d++) {
    $isOcc = $occupied[$d];
    $cells .= '<div class="cell ' . ($isOcc ? 'occ' : 'free') . '">' . $d . '</div>';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Disponibilidad — <?= htmlspecialchars($resource['name']) ?></title>
<style>
  *{box-sizing:border-box}
  body{font-family:Arial,Helvetica,sans-serif;background:#f4f5f9;color:#14161f;padding:24px;margin:0}
  .wrap{max-width:420px;margin:0 auto;background:#fff;border-radius:14px;padding:24px;box-shadow:0 2px 20px rgba(0,0,0,.08)}
  h1{font-size:18px;margin:0 0 2px}
  .sub{font-size:12px;color:#767b90;margin-bottom:18px}
  .nav{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
  .nav a{color:#0d9488;text-decoration:none;font-weight:700;font-size:13px;padding:4px 10px}
  .nav strong{font-size:14px}
  .grid{display:grid;grid-template-columns:repeat(7,1fr);gap:6px}
  .wd{font-size:10px;text-align:center;color:#767b90;font-weight:700;text-transform:uppercase}
  .cell{aspect-ratio:1;display:flex;align-items:center;justify-content:center;border-radius:8px;font-size:13px;font-weight:600}
  .cell.empty{visibility:hidden}
  .cell.free{background:#22c55e22;color:#16a34a}
  .cell.occ{background:#ef444422;color:#ef4444}
  .legend{display:flex;gap:16px;margin-top:16px;font-size:12px;color:#4a4f63}
  .legend span{display:inline-flex;align-items:center;gap:6px}
  .dot{width:10px;height:10px;border-radius:50%}
</style>
</head>
<body>
<div class="wrap">
  <h1><?= htmlspecialchars($resource['name']) ?></h1>
  <div class="sub">Disponibilidad — actualizado en tiempo real</div>
  <div class="nav">
    <a href="<?= $q($prevY,$prevM) ?>">‹ Anterior</a>
    <strong><?= $monthsEs[$month] ?> <?= $year ?></strong>
    <a href="<?= $q($nextY,$nextM) ?>">Siguiente ›</a>
  </div>
  <div class="grid">
    <div class="wd">Lu</div><div class="wd">Ma</div><div class="wd">Mi</div><div class="wd">Ju</div>
    <div class="wd">Vi</div><div class="wd">Sá</div><div class="wd">Do</div>
    <?= $cells ?>
  </div>
  <div class="legend">
    <span><span class="dot" style="background:#22c55e"></span> Libre</span>
    <span><span class="dot" style="background:#ef4444"></span> Ocupado</span>
  </div>
</div>
</body>
</html>
