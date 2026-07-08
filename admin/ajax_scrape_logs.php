<?php
require_once 'init.php';
require_once '../config.php';
require_once '../includes/Database.php';

header('Content-Type: application/json');

$db = new Database($pdo);
$logs = $db->getLatestScrapeLogs(100);

echo json_encode([
    'status' => 'success',
    'data' => $logs
]);
?>
