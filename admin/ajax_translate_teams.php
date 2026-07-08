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

// 1. Get teams of today with missing English translation
$sql = "SELECT DISTINCT t.id, t.name, m.league_name
        FROM teams t
        JOIN matches m ON (t.id = m.home_team_id OR t.id = m.away_team_id)
        WHERE m.match_date = ?
        AND (t.name_en IS NULL OR t.name_en = '')";

$stmt = $pdo->prepare($sql);
$stmt->execute([date('Y-m-d')]);
$teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($teams)) {
    echo json_encode(['success' => true, 'message' => 'No teams found needing translation today.', 'count' => 0]);
    exit;
}

// 2. Prepare data for Gemini
$teamsData = [];
foreach ($teams as $team) {
    $teamsData[] = [
        'id' => $team['id'],
        'name' => $team['name'],
        'league' => $team['league_name']
    ];
}

// 3. Call Gemini API
function translateWithGemini($teamsData, $apiKey) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . $apiKey;
    
    $prompt = "Translate the following football team names into English and Arabic. 
    The 'name' provided might be in any language. 
    Return ONLY a valid JSON array of objects. Each object must have:
    - 'id': the original ID
    - 'name_en': the translation in English
    - 'name_ar': the translation in Arabic
    
    Data: " . json_encode($teamsData);

    $data = [
        "contents" => [
            [
                "parts" => [
                    ["text" => $prompt]
                ]
            ]
        ]
    ];

    $maxRetries = 3;
    $retryDelay = 3; // seconds
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

        if ($httpCode === 200) {
            break;
        } elseif ($httpCode === 429) {
            if ($i < $maxRetries - 1) {
                sleep($retryDelay);
                $retryDelay *= 2; // Exponential backoff
                continue;
            }
        } else {
            break;
        }
    }

    if ($httpCode !== 200) {
        error_log("Gemini API Error: HTTP $httpCode - Response: $response");
        return ['error' => "API Error: HTTP $httpCode", 'response' => $response];
    }

    $result = json_decode($response, true);
    if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
        $jsonText = $result['candidates'][0]['content']['parts'][0]['text'];
        // Clean markdown if present
        $jsonText = preg_replace('/```json\s?/', '', $jsonText);
        $jsonText = preg_replace('/```/', '', $jsonText);
        return json_decode(trim($jsonText), true);
    }

    return ['error' => 'Invalid API response structure', 'response' => $result];
}

$translations = translateWithGemini($teamsData, $apiKey);

if (isset($translations['error'])) {
    echo json_encode(['success' => false, 'error' => $translations['error']]);
    exit;
}

// 4. Update Database
$updatedCount = 0;
foreach ($translations as $trans) {
    if (isset($trans['id'], $trans['name_en'], $trans['name_ar'])) {
        $stmt = $pdo->prepare("UPDATE teams SET name_en = ?, name_ar = ? WHERE id = ?");
        if ($stmt->execute([$trans['name_en'], $trans['name_ar'], $trans['id']])) {
            $updatedCount++;
        }
    }
}

if ($updatedCount > 0) {
    $pdo->prepare("UPDATE translation_api_keys SET request_count = request_count + 1, last_used_at = NOW() WHERE is_active = 1")->execute();
}

echo json_encode([
    'success' => true, 
    'message' => "Successfully translated and updated $updatedCount teams.",
    'count' => $updatedCount
]);
