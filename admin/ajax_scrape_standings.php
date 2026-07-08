<?php
header('Content-Type: application/json');
require_once 'init.php';
require_once '../config.php';
require_once '../includes/Database.php';
require_once '../includes/Scraper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
    exit;
}

$leagueId = $_POST['league_id'] ?? null;
$matchUrl = $_POST['match_url'] ?? null;

if (!$leagueId || !$matchUrl) {
    echo json_encode(['status' => 'error', 'message' => 'جميع الحقول مطلوبة']);
    exit;
}

$db = new Database($pdo);
$scraper = new Scraper($db);

// Get league info
$stmt = $pdo->prepare("SELECT * FROM leagues WHERE id = ?");
$stmt->execute([$leagueId]);
$league = $stmt->fetch();

if (!$league) {
    echo json_encode(['status' => 'error', 'message' => 'الدوري غير موجود']);
    exit;
}

try {
    // 1. First, try to scrape the match from the provided URL to ensure it exists in our DB
    // and to get the most up-to-date info for that match.
    $matchId = $scraper->scrapeAndSaveMatchByUrl($matchUrl);

    if (!$matchId) {
        // Fallback: If scraping by URL failed, try to find any existing match for this league
        $stmt = $pdo->prepare("SELECT id FROM matches WHERE league_name = ? ORDER BY match_date DESC LIMIT 1");
        $stmt->execute([$league['name']]);
        $match = $stmt->fetch();
        
        if ($match) {
            $matchId = $match['id'];
            // Update its URL to the provided one so scrapeMatchStandings can use it
            $updateUrlStmt = $pdo->prepare("UPDATE matches SET match_url = ? WHERE id = ?");
            $updateUrlStmt->execute([$matchUrl, $matchId]);
        }
    }

    if (!$matchId) {
        echo json_encode(['status' => 'error', 'message' => 'فشل في التعرف على المباراة من الرابط. يرجى التأكد من صحة الرابط.']);
        exit;
    }

    // 2. Now scrape the standings using the match ID
    $result = $scraper->scrapeMatchStandings($matchId);

    if ($result['status'] === 'success') {
        echo json_encode(['status' => 'success', 'message' => 'تم جلب الجدول بنجاح']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'فشل في استخراج الجدول من الرابط. تأكد من أن الرابط لمباراة تحتوي على جدول ترتيب.']);
    }

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
