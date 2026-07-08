<?php
header('Content-Type: application/json');
require_once '../config.php';
require_once '../includes/Database.php';
require_once '../includes/FifaScraper.php';

try {
    $db = new Database($pdo);
    $scraper = new FifaScraper();
    
    $db->clearScrapeLogs();
    $db->addScrapeLog('info', "بدء جلب ترتيب الفيفا العالمي [0%]");
    
    // Fetch rankings
    $db->addScrapeLog('info', "جاري الاتصال بموقع FIFA... [10%]");
    $result = $scraper->getLatestRankings();
    
    if ($result['status'] === 'error') {
        $db->addScrapeLog('error', $result['message'] . " [100%]");
        echo json_encode(['status' => 'error', 'message' => $result['message']]);
        exit;
    }
    
    $rankings = $result['rankings'];
    $total = $result['total'];
    
    $db->addScrapeLog('info', "تم جلب $total دولة، جاري الحفظ... [50%]");
    
    // Save to database
    $saved = $db->saveFifaRankings($rankings);
    
    if ($saved === false) {
        $db->addScrapeLog('error', "فشل حفظ البيانات في قاعدة البيانات [100%]");
        echo json_encode(['status' => 'error', 'message' => 'Failed to save rankings']);
        exit;
    }
    
    $db->addScrapeLog('success', "تم حفظ $saved دولة بنجاح [100%]");
    echo json_encode([
        'status' => 'success',
        'message' => "تم جلب وحفظ ترتيب $saved دولة بنجاح",
        'total' => $saved
    ]);

} catch (Exception $e) {
    $db->addScrapeLog('error', "خطأ: " . $e->getMessage() . " [100%]");
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
