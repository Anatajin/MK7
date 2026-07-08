<?php
require_once 'init.php';
require_once '../config.php';
require_once '../includes/AdminMonitor.php';

header('Content-Type: application/json; charset=utf-8');

$windowMinutes = isset($_GET['window']) ? (int)$_GET['window'] : 60;

try {
    $monitor = new AdminMonitor($pdo);
    $data = $monitor->getSnapshot($windowMinutes);

    echo json_encode([
        'status' => 'success',
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
