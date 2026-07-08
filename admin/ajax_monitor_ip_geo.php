<?php
require_once 'init.php';
require_once '../config.php';
require_once '../includes/AdminMonitor.php';

header('Content-Type: application/json; charset=utf-8');

$ip = trim((string)($_GET['ip'] ?? ''));

if ($ip === '') {
    http_response_code(422);
    echo json_encode([
        'status' => 'error',
        'message' => 'يرجى تمرير عنوان IP صالح.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $monitor = new AdminMonitor($pdo);
    $result = $monitor->lookupIpLocation($ip);

    if (!($result['success'] ?? false)) {
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => $result['message'] ?? 'تعذر تحديد موقع الـ IP.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'status' => 'success',
        'data' => $result['data'] ?? [],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'حدث خطأ أثناء معالجة الطلب.',
    ], JSON_UNESCAPED_UNICODE);
}
