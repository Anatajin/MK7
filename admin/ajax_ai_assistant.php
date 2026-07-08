<?php
require_once 'init.php';
require_once '../config.php';
require_once '../includes/AdminAssistant.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $raw = file_get_contents('php://input');
    $payload = json_decode((string)$raw, true);

    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'الطلب غير صالح.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $message = trim((string)($payload['message'] ?? ''));
    if ($message === '') {
        http_response_code(422);
        echo json_encode([
            'status' => 'error',
            'message' => 'الرسالة مطلوبة.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $history = $payload['history'] ?? [];
    if (!is_array($history)) {
        $history = [];
    }

    $assistant = new AdminAssistant($pdo);
    $result = $assistant->handleMessage($message, $history, [
        'role' => getAdminRole(),
        'page' => (string)($payload['page'] ?? ''),
    ]);

    if (empty($result['success'])) {
        http_response_code(422);
        echo json_encode([
            'status' => 'error',
            'message' => $result['message'] ?? 'تعذر تنفيذ الطلب.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'status' => 'success',
        'reply' => $result['reply'] ?? '',
        'action' => $result['action'] ?? null,
        'context' => $result['context'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'حدث خطأ داخلي داخل المساعد الإداري: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
