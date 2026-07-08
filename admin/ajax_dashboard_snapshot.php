<?php
require_once 'init.php';
require_once '../config.php';
require_once '../includes/Database.php';
require_once __DIR__ . '/includes/dashboard_snapshot.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$period = trim((string) ($_GET['period'] ?? '24h'));

try {
    $db = new Database($pdo);
    $snapshot = getAdminDashboardSnapshot($pdo, $period, $db);

    echo json_encode([
        'status' => 'success',
        'data' => $snapshot,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
