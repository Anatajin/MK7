<?php
require_once 'init.php';
require_once '../config.php';

header('Content-Type: application/json');

if (!isset($_POST['api_key'])) {
    echo json_encode(['success' => false, 'message' => 'Missing API Key']);
    exit;
}

$apiKey = $_POST['api_key'];
$url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . $apiKey;

$data = [
    "contents" => [["parts" => [["text" => "Say 'Connection Successful' in Arabic."]]]]
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200) {
    $result = json_decode($response, true);
    if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
        echo json_encode(['success' => true, 'message' => 'تم الاتصال بنجاح: ' . $result['candidates'][0]['content']['parts'][0]['text']]);
    } else {
        echo json_encode(['success' => false, 'message' => 'استجابة غير صالحة من API']);
    }
} else {
    echo json_encode(['success' => false, 'message' => "خطأ في الاتصال (HTTP $httpCode)"]);
}
