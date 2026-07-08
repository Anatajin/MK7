<?php
require_once 'init.php';
require_once '../config.php';
require_once '../includes/Database.php';

header('Content-Type: application/json');

// Get active API Key from database
$stmt = $pdo->query("SELECT api_key FROM translation_api_keys WHERE is_active = 1 LIMIT 1");
$activeKey = $stmt->fetchColumn();

if (!$activeKey) {
    echo json_encode(['success' => false, 'error' => 'لا يوجد مفتاح API نشط للترجمة. يرجى تفعيل مفتاح من الإعدادات.']);
    exit;
}

$apiKey = $activeKey;
$db = new Database($pdo);

// 1. Get news with missing translation
$sql = "SELECT id, title, body FROM news WHERE title_en IS NULL OR title_en = '' OR body_en IS NULL OR body_en = '' LIMIT 20";
$stmt = $pdo->query($sql);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($items)) {
    echo json_encode(['success' => true, 'message' => 'No news found needing translation.', 'count' => 0]);
    exit;
}

// 2. Prepare data for Gemini
$dataToTranslate = [];
foreach ($items as $item) {
    $dataToTranslate[] = [
        'id' => $item['id'],
        'title' => $item['title'],
        'body' => strip_tags($item['body']) // Strip tags for translation prompt to save tokens and avoid breaking JSON
    ];
}

// 3. Call Gemini API
function translateWithGemini($dataToTranslate, $apiKey) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . $apiKey;
    
    $prompt = "Translate the following football news titles and content into English. 
    Return ONLY a valid JSON array of objects. Each object must have:
    - 'id': the original ID
    - 'title_en': the translation of the title in English
    - 'body_en': the translation of the body in English
    
    Data: " . json_encode($dataToTranslate);

    $data = [
        "contents" => [["parts" => [["text" => $prompt]]]]
    ];

    $maxRetries = 3;
    $retryDelay = 3;
    $response = '';
    $httpCode = 0;

    for ($i = 0; $i < $maxRetries; $i++) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) break;
        if ($httpCode === 429 && $i < $maxRetries - 1) {
            sleep($retryDelay);
            $retryDelay *= 2;
            continue;
        }
        break;
    }

    if ($httpCode !== 200) return ['error' => "API Error: HTTP $httpCode"];

    $result = json_decode($response, true);
    if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
        $jsonText = $result['candidates'][0]['content']['parts'][0]['text'];
        $jsonText = preg_replace('/```json\s?/', '', $jsonText);
        $jsonText = preg_replace('/```/', '', $jsonText);
        return json_decode(trim($jsonText), true);
    }
    return ['error' => 'Invalid API response'];
}

$translations = translateWithGemini($dataToTranslate, $apiKey);

if (isset($translations['error'])) {
    echo json_encode(['success' => false, 'error' => $translations['error']]);
    exit;
}

// 4. Update Database
$updatedCount = 0;
foreach ($translations as $trans) {
    if (isset($trans['id'], $trans['title_en'], $trans['body_en'])) {
        $stmt = $pdo->prepare("UPDATE news SET title_en = ?, body_en = ? WHERE id = ?");
        if ($stmt->execute([$trans['title_en'], $trans['body_en'], $trans['id']])) {
            $updatedCount++;
        }
    }
}

if ($updatedCount > 0) {
    $pdo->prepare("UPDATE translation_api_keys SET request_count = request_count + 1, last_used_at = NOW() WHERE is_active = 1")->execute();
}

echo json_encode(['success' => true, 'message' => "Successfully translated $updatedCount news articles.", 'count' => $updatedCount]);
