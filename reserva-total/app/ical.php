<?php
// Feed iCal de solo lectura para un recurso — para importar en Booking/Airbnb
// y evitar overbooking. Sin datos de huéspedes, solo bloques "Ocupado".
require_once 'config.php';
$db = rtDB();

$token = $_GET['token'] ?? '';
$resource = rtResourceByToken($db, $token);

header('Content-Type: text/calendar; charset=utf-8');
if (!$resource) { http_response_code(404); echo "Enlace inválido"; exit; }

$st = $db->prepare("SELECT id, check_in, check_out FROM reservations
    WHERE resource_id=? AND status != 'cancelled'");
$st->execute([$resource['id']]);
$reservations = $st->fetchAll();

$st2 = $db->prepare("SELECT id, date_start, date_end FROM rt_blocked_dates WHERE resource_id=?");
$st2->execute([$resource['id']]);
$blocks = $st2->fetchAll();

$fmt = fn($s) => (new DateTime($s))->format('Ymd');
$now = (new DateTime())->format('Ymd\THis\Z');

$lines   = [];
$lines[] = 'BEGIN:VCALENDAR';
$lines[] = 'VERSION:2.0';
$lines[] = 'PRODID:-//Reserva Total//' . $resource['name'] . '//ES';
$lines[] = 'CALSCALE:GREGORIAN';
$lines[] = 'X-WR-CALNAME:' . $resource['name'] . ' — Reserva Total';

foreach ($reservations as $r) {
    $lines[] = 'BEGIN:VEVENT';
    $lines[] = 'UID:rt-res-' . $r['id'] . '@reservatotal';
    $lines[] = 'DTSTAMP:' . $now;
    $lines[] = 'DTSTART;VALUE=DATE:' . $fmt($r['check_in']);
    $lines[] = 'DTEND;VALUE=DATE:'   . $fmt($r['check_out']);
    $lines[] = 'SUMMARY:Ocupado (Reserva Total)';
    $lines[] = 'END:VEVENT';
}
foreach ($blocks as $b) {
    $lines[] = 'BEGIN:VEVENT';
    $lines[] = 'UID:rt-block-' . $b['id'] . '@reservatotal';
    $lines[] = 'DTSTAMP:' . $now;
    $lines[] = 'DTSTART;VALUE=DATE:' . $fmt($b['date_start']);
    $lines[] = 'DTEND;VALUE=DATE:'   . $fmt($b['date_end']);
    $lines[] = 'SUMMARY:No disponible (Reserva Total)';
    $lines[] = 'END:VEVENT';
}
$lines[] = 'END:VCALENDAR';

echo implode("\r\n", $lines);
