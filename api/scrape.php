<?php
// تضمين إعدادات CORS
require_once 'cors.php';

// Increase limits for scraping
@ini_set('max_execution_time', 300);
@ini_set('memory_limit', '256M');

// Clear all caches
if (function_exists('opcache_reset')) {
    @opcache_reset();
}
if (function_exists('apc_clear_cache')) {
    @apc_clear_cache();
}

// Enable error logging to file instead of displaying
@ini_set('display_errors', 0);
@ini_set('log_errors', 1);


try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../includes/Database.php';
    require_once __DIR__ . '/../includes/Scraper.php';

    $db = new Database($pdo);
    $scraper = new Scraper($db);

    // Calculate today's date or use provided date
    $today = $_GET['date'] ?? date('Y-m-d');
    $clearLogs = !defined('AUTO_SCRAPER');
    
    // Get active source
    $activeSource = $db->getActiveScraperSource(); // Returns 'kooora', 'yssscore', etc. OR false
    
    if (!$activeSource) {
        if ($clearLogs) $db->clearScrapeLogs();
        $db->addScrapeLog('filter', "لم يتم تحديد مصدر للبيانات (جميع المصادر معطلة)");
        echo json_encode(['status' => 'error', 'message' => 'Scraping is disabled (No active source)']);
        exit;
    }

    if ($activeSource === 'yssscore' || $activeSource === 'ysscores') {
        $result = $scraper->scrapeYssScore($today, $clearLogs);
    } else {
        $result = $scraper->scrapeKooora($today, $clearLogs);
    }
    
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    // Log error to file
    
    
    // Return error as JSON
    echo json_encode([
        'status' => 'error',
        'message' => 'خطأ في السيرفر: ' . $e->getMessage(),
        'error_details' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
