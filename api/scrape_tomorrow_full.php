<?php
/**
 * Full Tomorrow Scraper
 * Fetches tomorrow's matches with ALL details: lineups, events, stats, standings, previous matches
 */
@ini_set('max_execution_time', 600);
@ini_set('memory_limit', '512M');
@ini_set('display_errors', 0);
@ini_set('log_errors', 1);

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../includes/Database.php';
    require_once __DIR__ . '/../includes/Scraper.php';

    $db = new Database($pdo);
    $scraper = new Scraper($db);

    // Calculate tomorrow's date
    $tomorrow = date('Y-m-d', strtotime('+1 day'));

    // Step 1: Fetch basic matches
    $db->addScrapeLog('info', "بدء جلب مباريات الغد الكاملة: $tomorrow [5%]");
    
    // Get active source
    $activeSource = $db->getActiveScraperSource();
    if ($activeSource === 'yssscore') {
        $scraper->scrapeYssScore($tomorrow, false);
    } else {
        $scraper->scrapeKooora($tomorrow, false);
    }
    
    $db->addScrapeLog('info', "تم جلب المباريات الأساسية [20%]");
    
    // Step 2: Fetch match details/summaries
    $db->addScrapeLog('info', "جاري جلب تفاصيل المباريات... [25%]");
    $scraper->scrapeAllDetails($tomorrow, false);
    $db->addScrapeLog('info', "تم جلب تفاصيل المباريات [40%]");
    
    // Step 3: Fetch lineups
    $db->addScrapeLog('info', "جاري جلب التشكيلات... [45%]");
    $scraper->scrapeAllLineups($tomorrow, false);
    $db->addScrapeLog('info', "تم جلب التشكيلات [55%]");
    
    // Step 4: Fetch standings
    $db->addScrapeLog('info', "جاري جلب جداول الترتيب... [60%]");
    $scraper->scrapeAllStandings($tomorrow, false);
    $db->addScrapeLog('info', "تم جلب جداول الترتيب [70%]");
    
    // Step 5: Fetch previous matches
    $db->addScrapeLog('info', "جاري جلب المواجهات السابقة... [75%]");
    $scraper->scrapeAllPreviousMatches($tomorrow, false);
    $db->addScrapeLog('info', "تم جلب المواجهات السابقة [85%]");
    
    // Step 6: Fetch live streams (if available)
    $db->addScrapeLog('info', "جاري جلب روابط البث... [90%]");
    $scraper->scrapeLive($tomorrow, false);
    $db->addScrapeLog('info', "تم جلب روابط البث [95%]");
    
    $db->addScrapeLog('success', "اكتملت عملية جلب مباريات الغد الكاملة بنجاح! [100%]");
    
    echo json_encode([
        'status' => 'success',
        'message' => "تم جلب مباريات الغد ($tomorrow) بكافة التفاصيل بنجاح"
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'خطأ في جلب مباريات الغد الكاملة: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
