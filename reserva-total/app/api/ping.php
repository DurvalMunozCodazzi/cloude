<?php
require_once '../config.php';
header('Content-Type: application/json');
echo json_encode(['ok' => true, 'app' => 'Reserva Total', 'version' => RT_VERSION, 'time' => date('c')]);
