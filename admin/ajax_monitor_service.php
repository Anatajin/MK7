<?php
require_once 'init.php';
require_once '../includes/AdminMonitor.php';

header('Content-Type: application/json; charset=utf-8');
requireAdminAPI();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'status' => 'error',
        'message' => 'طريقة الطلب غير مسموحة.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$service = trim((string)($_POST['service'] ?? ''));
$desiredActive = isset($_POST['desired_active']) ? (int)$_POST['desired_active'] : null;

if ($service === '' || ($desiredActive !== 0 && $desiredActive !== 1)) {
    http_response_code(422);
    echo json_encode([
        'status' => 'error',
        'message' => 'بيانات الخدمة غير مكتملة.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$monitor = new AdminMonitor($pdo);
$result = $monitor->setServiceState($service, $desiredActive === 1);

if (empty($result['success'])) {
    http_response_code(422);
    echo json_encode([
        'status' => 'error',
        'message' => $result['message'] ?? 'تعذر تعديل حالة الخدمة.',
        'service' => $result['service'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'status' => 'success',
    'message' => $result['message'] ?? 'تم تحديث الخدمة بنجاح.',
    'service' => $result['service'] ?? null,
], JSON_UNESCAPED_UNICODE);
