<?php
// ── Sync de calendarios iCal externos (Booking/Airbnb → bloqueo de fechas) ──
// Sin librerías externas: parseo de VEVENT por regex, descarga por cURL o
// file_get_contents, mismo estilo minimalista que smtp.php.

function rtFetchUrl($url) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'ReservaTotal/1.0',
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($body === false) throw new Exception($err ?: 'Error de conexión');
        return $body;
    }
    $ctx  = stream_context_create(['http' => ['timeout' => 20], 'https' => ['timeout' => 20]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) throw new Exception('No se pudo descargar el calendario externo');
    return $body;
}

// Extrae pares DTSTART/DTEND de cada VEVENT — soporta DATE (YYYYMMDD) y
// DATE-TIME (YYYYMMDDTHHMMSSZ), ya que ambos comienzan igual.
function rtParseIcsEvents($ics) {
    $events = [];
    preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $ics, $blocks);
    foreach ($blocks[1] as $block) {
        $dtstart = null; $dtend = null;
        if (preg_match('/DTSTART[^:\r\n]*:(\d{8})/', $block, $m)) $dtstart = $m[1];
        if (preg_match('/DTEND[^:\r\n]*:(\d{8})/', $block, $m))   $dtend   = $m[1];
        if ($dtstart && $dtend) {
            $fmt = fn($d) => substr($d,0,4).'-'.substr($d,4,2).'-'.substr($d,6,2);
            $start = $fmt($dtstart); $end = $fmt($dtend);
            if ($end > $start) $events[] = ['start' => $start, 'end' => $end];
        }
    }
    return $events;
}

// Sincroniza una fila de rt_ical_imports: reemplaza sus bloqueos previos por
// los eventos actuales del feed externo (delete-then-reinsert, mismo patrón
// usado para extras/acompañantes de una reserva).
function rtSyncIcalImport(PDO $db, array $import) {
    try {
        $events = rtParseIcsEvents(rtFetchUrl($import['url']));
    } catch (Exception $e) {
        $db->prepare("UPDATE rt_ical_imports SET last_synced_at=NOW(), last_status=? WHERE id=?")
           ->execute(['Error: ' . $e->getMessage(), $import['id']]);
        return null;
    }
    $db->prepare("DELETE FROM rt_blocked_dates WHERE source=?")->execute(['ical:' . $import['id']]);
    $ins = $db->prepare("INSERT INTO rt_blocked_dates (resource_id,date_start,date_end,reason,source) VALUES (?,?,?,?,?)");
    foreach ($events as $ev) {
        $ins->execute([$import['resource_id'], $ev['start'] . ' 00:00:00', $ev['end'] . ' 00:00:00', 'Importado de calendario externo', 'ical:' . $import['id']]);
    }
    $db->prepare("UPDATE rt_ical_imports SET last_synced_at=NOW(), last_status=? WHERE id=?")
       ->execute(['OK — ' . count($events) . ' evento(s) importado(s)', $import['id']]);
    return count($events);
}
