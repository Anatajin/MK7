<?php
require_once 'cors.php';

// Increase limits for scraping
@ini_set('max_execution_time', 300);
@ini_set('memory_limit', '256M');

// Enable error logging
@ini_set('display_errors', 0);
@ini_set('log_errors', 1);

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../includes/Database.php';
    require_once __DIR__ . '/../includes/Scraper.php';

    $db = new Database($pdo);
    $scraper = new Scraper($db);

    // Check if specific match ID is provided
    $matchId = $_GET['id'] ?? null;
    $date = $_GET['date'] ?? null;

    if ($matchId) {
        $result = $scraper->scrapeMatchLineup($matchId);
    } else {
        // Scrape lineups for all relevant matches
        $clearLogs = !defined('AUTO_SCRAPER');
        $result = $scraper->scrapeAllLineups($date, $clearLogs);
    }
    
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'خطأ في السيرفر: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
