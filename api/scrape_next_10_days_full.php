<?php
/**
 * Full Next 10 Days Scraper
 * Fetches matches for the next 10 days with ALL details: lineups, events, stats, standings, previous matches
 */
@ini_set('max_execution_time', 3600); // 1 hour execution time
@ini_set('memory_limit', '1024M');
@ini_set('display_errors', 0);
@ini_set('log_errors', 1);

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../includes/Database.php';
    require_once __DIR__ . '/../includes/Scraper.php';

    $db = new Database($pdo);
    $scraper = new Scraper($db);
    
    $db->clearScrapeLogs();

    $totalMatches = 0;
    $daysProcessed = 0;
    $errors = [];

    $db->addScrapeLog('info', "بدء جلب مباريات 10 أيام القادمة (كاملة)...");

    // Loop for next 10 days (starting from tomorrow)
    for ($i = 1; $i <= 10; $i++) {
        $date = date('Y-m-d', strtotime("+$i day"));
        
        $db->addScrapeLog('info', "جاري معالجة يوم: $date...");

        // Step 1: Fetch basic matches
        // Get active source
        $activeSource = $db->getActiveScraperSource();
        if ($activeSource === 'yssscore') {
            $result = $scraper->scrapeYssScore($date, false);
        } else {
            $result = $scraper->scrapeKooora($date, false);
        }
        
        if ($result['status'] === 'success' && $result['total'] > 0) {
            $totalMatches += $result['total'];
            $daysProcessed++;
            
            // Step 2: Fetch match details/summaries
            $scraper->scrapeAllDetails($date, false);
            
            // Step 3: Fetch lineups
            $scraper->scrapeAllLineups($date, false);
            
            // Step 4: Fetch standings
            $scraper->scrapeAllStandings($date, false);
            
            // Step 5: Fetch previous matches
            $scraper->scrapeAllPreviousMatches($date, false);
            
            // Step 6: Fetch live streams (if available) - usually not available this far in advance but good to check
            $scraper->scrapeLive($date, false);
            
            $db->addScrapeLog('success', "تم اكتمال يوم $date بنجاح.");
        } else {
            $db->addScrapeLog('filter', "لا توجد مباريات ليوم $date أو حدث خطأ.");
            if (isset($result['message'])) $errors[] = "Day $date: " . $result['message'];
        }
        
        // Add delay to prevent server overload
        sleep(2);
    }
    
    $db->addScrapeLog('success', "اكتملت عملية جلب 10 أيام القادمة بنجاح! إجمالي المباريات: $totalMatches");
    
    echo json_encode([
        'status' => 'success',
        'message' => "تم جلب $totalMatches مباراة للأيام الـ $daysProcessed القادمة بكافة التفاصيل",
        'total' => $totalMatches,
        'days' => $daysProcessed,
        'errors' => $errors
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'خطأ في جلب مباريات 10 أيام القادمة: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
