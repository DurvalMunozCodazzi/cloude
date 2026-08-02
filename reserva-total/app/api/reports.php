<?php
require_once '../config.php';
$me     = rtRequireAuth();
$db     = rtDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ── Helper: arma el resumen de un mes — reutilizado por summary/export_csv/print ──
function rtBuildReportSummary(PDO $db, $year, $month) {
    $monthStart = new DateTime(sprintf('%04d-%02d-01', $year, $month));
    $monthEnd   = (clone $monthStart)->modify('first day of next month'); // exclusivo
    $daysInMonth = intval($monthStart->format('t'));

    $st = $db->prepare("SELECT r.* FROM reservations r
        WHERE r.status != 'cancelled'
          AND r.check_in < ? AND r.check_out > ?");
    $st->execute([$monthEnd->format('Y-m-d 00:00:00'), $monthStart->format('Y-m-d 00:00:00')]);
    $rows = $st->fetchAll();

    $resources = $db->query("SELECT id,name,color FROM resources WHERE active=1 ORDER BY position,name")->fetchAll();
    $byResource = [];
    foreach ($resources as $r) {
        $byResource[$r['id']] = ['id'=>$r['id'],'name'=>$r['name'],'color'=>$r['color'],'nights'=>0,'revenue'=>0];
    }

    $accommodationTotal = 0; $extrasTotal = 0; $paidTotal = 0;
    $extrasByName = [];
    $reservationIds = [];

    foreach ($rows as $row) {
        $reservationIds[] = $row['id'];

        $ciDate = (new DateTime($row['check_in']))->setTime(0,0,0);
        $coDate = (new DateTime($row['check_out']))->setTime(0,0,0);
        $rangeStart = max($ciDate, $monthStart);
        $rangeEnd   = min($coDate, $monthEnd);
        $nights = $rangeEnd > $rangeStart ? $rangeStart->diff($rangeEnd)->days : 0;

        if (isset($byResource[$row['resource_id']])) {
            $byResource[$row['resource_id']]['nights']  += $nights;
            $byResource[$row['resource_id']]['revenue'] += floatval($row['total_price']);
        }
        $accommodationTotal += floatval($row['total_price']);
    }

    if ($reservationIds) {
        $ph = implode(',', array_fill(0, count($reservationIds), '?'));

        $exSt = $db->prepare("SELECT name, price, qty FROM reservation_extras WHERE reservation_id IN ($ph)");
        $exSt->execute($reservationIds);
        foreach ($exSt->fetchAll() as $ex) {
            $sub = floatval($ex['price']) * intval($ex['qty']);
            $extrasTotal += $sub;
            if (!isset($extrasByName[$ex['name']])) $extrasByName[$ex['name']] = ['name'=>$ex['name'],'qty'=>0,'revenue'=>0];
            $extrasByName[$ex['name']]['qty']     += intval($ex['qty']);
            $extrasByName[$ex['name']]['revenue'] += $sub;
        }

        $payStl = $db->prepare("SELECT COALESCE(SUM(amount),0) as total FROM reservation_payments WHERE reservation_id IN ($ph)");
        $payStl->execute($reservationIds);
        $paidTotal = floatval($payStl->fetch()['total']);
    }

    $grandTotal = round($accommodationTotal + $extrasTotal, 2);

    $occupancy = array_values(array_map(function($r) use ($daysInMonth) {
        $r['nights']  = round($r['nights'], 0);
        $r['revenue'] = round($r['revenue'], 2);
        $r['occupancy_pct'] = $daysInMonth > 0 ? round(min(100, $r['nights'] / $daysInMonth * 100), 1) : 0;
        return $r;
    }, $byResource));
    usort($occupancy, fn($a,$b) => $b['occupancy_pct'] <=> $a['occupancy_pct']);

    $topExtras = array_values($extrasByName);
    usort($topExtras, fn($a,$b) => $b['revenue'] <=> $a['revenue']);
    $topExtras = array_map(function($e){ $e['revenue'] = round($e['revenue'],2); return $e; }, array_slice($topExtras, 0, 10));

    return [
        'year' => $year, 'month' => $month, 'days_in_month' => $daysInMonth,
        'reservations_count'  => count($rows),
        'accommodation_total' => round($accommodationTotal, 2),
        'extras_total'        => round($extrasTotal, 2),
        'grand_total'         => $grandTotal,
        'paid_total'          => round($paidTotal, 2),
        'balance'             => round($grandTotal - $paidTotal, 2),
        'occupancy'           => $occupancy,
        'top_extras'          => $topExtras,
    ];
}

$MESES_ES = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',
             7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];

// ── GET resumen del mes: ocupación, ingresos, ranking de extras ──────────────
if ($method === 'GET' && $action === 'summary') {
    $year  = intval($_GET['year']  ?? date('Y'));
    $month = intval($_GET['month'] ?? date('n'));
    rtOut(rtBuildReportSummary($db, $year, $month));
}

// ── GET export CSV (se abre directo en Excel) ────────────────────────────────
if ($method === 'GET' && $action === 'export_csv') {
    $year  = intval($_GET['year']  ?? date('Y'));
    $month = intval($_GET['month'] ?? date('n'));
    $d = rtBuildReportSummary($db, $year, $month);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reporte-' . $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT) . '.csv"');

    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF"); // BOM — para que Excel muestre bien los acentos
    fputcsv($out, ['Reporte ' . $MESES_ES[$month] . ' ' . $year]);
    fputcsv($out, []);
    fputcsv($out, ['Ingresos totales', $d['grand_total']]);
    fputcsv($out, ['Cobrado', $d['paid_total']]);
    fputcsv($out, ['Pendiente de cobro', $d['balance']]);
    fputcsv($out, ['Reservas del mes', $d['reservations_count']]);
    fputcsv($out, []);
    fputcsv($out, ['Ocupación por recurso']);
    fputcsv($out, ['Recurso', 'Noches ocupadas', 'Ocupación %', 'Ingresos']);
    foreach ($d['occupancy'] as $r) fputcsv($out, [$r['name'], $r['nights'], $r['occupancy_pct'], $r['revenue']]);
    fputcsv($out, []);
    fputcsv($out, ['Extras más vendidos']);
    fputcsv($out, ['Extra', 'Cantidad', 'Ingresos']);
    foreach ($d['top_extras'] as $e) fputcsv($out, [$e['name'], $e['qty'], $e['revenue']]);
    fclose($out);
    exit;
}

// ── GET vista imprimible / guardar como PDF (mismo patrón que el comprobante) ──
if ($method === 'GET' && $action === 'print') {
    $year  = intval($_GET['year']  ?? date('Y'));
    $month = intval($_GET['month'] ?? date('n'));
    $d = rtBuildReportSummary($db, $year, $month);

    $fmtMoney = fn($n) => number_format(floatval($n), 2, ',', '.');
    $now = date('d/m/Y H:i');

    $occRows = '';
    foreach ($d['occupancy'] as $r) {
        $occRows .= '<tr><td>' . htmlspecialchars($r['name']) . '</td><td>' . $r['nights'] . '</td>'
                  . '<td>' . $r['occupancy_pct'] . '%</td><td>$' . $fmtMoney($r['revenue']) . '</td></tr>';
    }
    if (!$occRows) $occRows = '<tr><td colspan="4">Sin recursos activos</td></tr>';

    $extRows = '';
    foreach ($d['top_extras'] as $e) {
        $extRows .= '<tr><td>' . htmlspecialchars($e['name']) . '</td><td>' . $e['qty'] . '</td><td>$' . $fmtMoney($e['revenue']) . '</td></tr>';
    }
    if (!$extRows) $extRows = '<tr><td colspan="3">Sin ventas de extras este mes</td></tr>';

    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Reporte {$MESES_ES[$month]} {$d['year']} — Reserva Total</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#1a1a2e;background:#f4f4f4;padding:24px}
  .wrap{max-width:680px;margin:0 auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 20px rgba(0,0,0,.1)}
  .hdr{background:#0d9488;color:#fff;padding:20px 24px}
  .hdr h1{font-size:18px}
  .hdr span{font-size:12px;opacity:.85}
  .body{padding:20px 24px}
  .cards{display:flex;gap:12px;margin-bottom:20px}
  .card{flex:1;background:#f8fffe;border:1px solid #0d948840;border-radius:8px;padding:12px;text-align:center}
  .card .lbl{font-size:10px;color:#888;text-transform:uppercase;letter-spacing:.4px}
  .card .val{font-size:18px;font-weight:800;margin-top:4px}
  h2{font-size:13px;color:#0d9488;text-transform:uppercase;letter-spacing:.5px;margin:20px 0 8px}
  table{width:100%;border-collapse:collapse;font-size:12px}
  th,td{padding:6px 8px;border-bottom:1px solid #e8eaf0;text-align:left}
  th{color:#888;font-weight:700;text-transform:uppercase;font-size:10px}
  .footer{padding:14px 24px;text-align:center;font-size:10px;color:#999}
  @media print{ body{background:white;padding:0} .wrap{box-shadow:none;border-radius:0} .no-print{display:none} }
</style>
</head>
<body>
<div class="wrap">
  <div class="hdr">
    <h1>🗓 Reserva Total</h1>
    <span>Reporte de ocupación e ingresos — {$MESES_ES[$month]} {$d['year']}</span>
  </div>
  <div class="body">
    <div class="cards">
      <div class="card"><div class="lbl">Ingresos totales</div><div class="val" style="color:#0d9488">\$ {$fmtMoney($d['grand_total'])}</div></div>
      <div class="card"><div class="lbl">Cobrado</div><div class="val" style="color:#16a34a">\$ {$fmtMoney($d['paid_total'])}</div></div>
      <div class="card"><div class="lbl">Pendiente</div><div class="val" style="color:#ef4444">\$ {$fmtMoney($d['balance'])}</div></div>
    </div>
    <h2>Ocupación por recurso ({$d['reservations_count']} reserva(s))</h2>
    <table><thead><tr><th>Recurso</th><th>Noches</th><th>Ocupación</th><th>Ingresos</th></tr></thead><tbody>{$occRows}</tbody></table>
    <h2>Extras más vendidos</h2>
    <table><thead><tr><th>Extra</th><th>Cantidad</th><th>Ingresos</th></tr></thead><tbody>{$extRows}</tbody></table>
  </div>
  <div class="footer">Generado el {$now}</div>
</div>
<div class="no-print" style="text-align:center;margin-top:20px">
  <button onclick="window.print()" style="background:#0d9488;color:#fff;border:none;padding:10px 28px;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer">
    🖨 Imprimir / Guardar PDF
  </button>
</div>
</body>
</html>
HTML;
    exit;
}

rtErr('Acción no encontrada', 404);
