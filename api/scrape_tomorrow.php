<?php
@ini_set('max_execution_time', 300);
@ini_set('memory_limit', '256M');

// Clear all caches
if (function_exists('opcache_reset')) {
    @opcache_reset();
}
if (function_exists('apc_clear_cache')) {
    @apc_clear_cache();
}

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

    // Get active source
    $activeSource = $db->getActiveScraperSource();
    
    if ($activeSource === 'yssscore') {
        $result = $scraper->scrapeYssScore($tomorrow);
    } else {
        $result = $scraper->scrapeKooora($tomorrow);
    }
    
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    
    echo json_encode([
        'status' => 'error',
        'message' => 'خطأ في جلب مباريات الغد: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
