<?php
require_once 'init.php';
require_once '../config.php';
require_once '../includes/AdminMonitor.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'status' => 'error',
        'message' => 'طريقة الطلب غير مسموحة.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = trim((string)($_POST['action'] ?? ''));
$ips = $_POST['ips'] ?? [];

if (!in_array($action, ['block', 'unblock'], true)) {
    http_response_code(422);
    echo json_encode([
        'status' => 'error',
        'message' => 'الإجراء المطلوب غير مدعوم.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_array($ips)) {
    $ips = [$ips];
}

$monitor = new AdminMonitor($pdo);
$result = $monitor->updateBlockedIps($ips, $action === 'block');

if (empty($result['success'])) {
    http_response_code(422);
    echo json_encode([
        'status' => 'error',
        'message' => $result['message'] ?? 'تعذر تحديث قائمة الحظر.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'status' => 'success',
    'message' => $result['message'] ?? 'تم تحديث قائمة الحظر بنجاح.',
], JSON_UNESCAPED_UNICODE);
