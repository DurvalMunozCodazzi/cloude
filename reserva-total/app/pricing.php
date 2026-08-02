<?php
// ── Cotización de estadía server-side ────────────────────────────────────────
// Espejo exacto de la lógica del formulario de reserva (recalcPrice en
// index.html): precio por noche con tarifas de temporada (la tarifa de un
// recurso específico gana sobre la global) + descuento por estadía larga
// (se aplica el tramo mayor alcanzado). Usada por el motor de reserva online
// para que el precio que ve el huésped sea siempre el del sistema.

function rtQuoteStay(PDO $db, array $resource, $checkIn, $checkOut) {
    $ci = new DateTime($checkIn);
    $co = new DateTime($checkOut);
    if ($co <= $ci) return null;

    $nights = (int) ceil(($co->getTimestamp() - $ci->getTimestamp()) / 86400);
    if ($nights < 1) $nights = 1;

    $st = $db->prepare("SELECT * FROM rt_seasonal_rates
        WHERE (resource_id = ? OR resource_id IS NULL)");
    $st->execute([$resource['id']]);
    $rates = $st->fetchAll();

    $base = 0; $usedSeasonal = false;
    $night = (clone $ci)->setTime(0, 0, 0);
    for ($i = 0; $i < $nights; $i++) {
        $dateStr = $night->format('Y-m-d');
        $match = null;
        foreach ($rates as $r) {
            if ($dateStr >= $r['date_start'] && $dateStr <= $r['date_end']) {
                // Tarifa de recurso específico gana sobre la global
                if ($match === null || (!empty($r['resource_id']) && empty($match['resource_id']))) {
                    $match = $r;
                }
            }
        }
        if ($match) { $base += floatval($match['price_per_day']); $usedSeasonal = true; }
        else        { $base += floatval($resource['price_per_day']); }
        $night->modify('+1 day');
    }

    $discountPct = 0;
    $discounts = $db->query("SELECT * FROM rt_long_stay_discounts ORDER BY min_nights DESC")->fetchAll();
    foreach ($discounts as $d) {
        if ($nights >= intval($d['min_nights'])) { $discountPct = floatval($d['discount_pct']); break; }
    }

    $total = round($base * (1 - $discountPct / 100), 2);

    return [
        'nights'       => $nights,
        'base_total'   => round($base, 2),
        'discount_pct' => $discountPct,
        'seasonal'     => $usedSeasonal,
        'total'        => $total,
    ];
}

// Disponibilidad: sin reservas activas ni bloqueos superpuestos.
function rtIsAvailable(PDO $db, $resourceId, $checkIn, $checkOut, $excludeReservationId = 0) {
    $st = $db->prepare("SELECT id FROM reservations
        WHERE resource_id=? AND id!=? AND status!='cancelled'
          AND check_in < ? AND check_out > ?");
    $st->execute([$resourceId, $excludeReservationId, $checkOut, $checkIn]);
    if ($st->fetch()) return false;

    $st = $db->prepare("SELECT id FROM rt_blocked_dates
        WHERE resource_id=? AND date_start < ? AND date_end > ?");
    $st->execute([$resourceId, $checkOut, $checkIn]);
    if ($st->fetch()) return false;

    return true;
}
