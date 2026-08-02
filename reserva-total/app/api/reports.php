<?php
require_once '../config.php';
$me     = rtRequireAuth();
$db     = rtDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ── GET resumen del mes: ocupación, ingresos, ranking de extras ──────────────
if ($method === 'GET' && $action === 'summary') {
    $year  = intval($_GET['year']  ?? date('Y'));
    $month = intval($_GET['month'] ?? date('n'));

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

    rtOut([
        'year' => $year, 'month' => $month, 'days_in_month' => $daysInMonth,
        'reservations_count'  => count($rows),
        'accommodation_total' => round($accommodationTotal, 2),
        'extras_total'        => round($extrasTotal, 2),
        'grand_total'         => $grandTotal,
        'paid_total'          => round($paidTotal, 2),
        'balance'             => round($grandTotal - $paidTotal, 2),
        'occupancy'           => $occupancy,
        'top_extras'          => $topExtras,
    ]);
}

rtErr('Acción no encontrada', 404);
