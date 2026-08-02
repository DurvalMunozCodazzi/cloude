<?php
header('Content-Type: application/json');
echo json_encode(['ok' => true, 'app' => 'Reserva Total', 'version' => '3.2.0', 'time' => date('c')]);
