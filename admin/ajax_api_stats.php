<?php
require_once 'init.php';
require_once '../config.php';
require_once '../includes/Database.php';

header('Content-Type: application/json');

$db = new Database($pdo);
$period = $_GET['period'] ?? '24h';

try {
    $data = $db->getApiUsageHistory($period);
    
    // Process data to ensure all labels are present (optional, but good for charts)
    // For now, just return what DB gives, Chart.js can handle it or we can fill gaps in JS
    
    echo json_encode(['status' => 'success', 'data' => $data]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
