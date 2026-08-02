<?php
header('Content-Type: application/json');
echo json_encode(['ok' => true, 'app' => 'Reserva Total', 'version' => '4.1.0', 'time' => date('c')]);
