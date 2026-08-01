<?php
header('Content-Type: application/json');
echo json_encode(['ok' => true, 'app' => 'Reserva Total', 'version' => '2.8.1', 'time' => date('c')]);
