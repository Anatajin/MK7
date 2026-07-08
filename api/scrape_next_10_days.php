<?php
// Clear caches and set unlimited execution time
if (function_exists('opcache_reset')) { opcache_reset(); }
set_time_limit(600); // 10 minutes to be safe

error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Scraper.php';

header('Content-Type: application/json');

$db = new Database($pdo);
$scraper = new Scraper($db);

$db->clearScrapeLogs();
$totalMatches = 0;
$daysProcessed = 0;
$errors = [];

// Loop for next 10 days (starting from tomorrow)
for ($i = 1; $i <= 10; $i++) {
    $date = date('Y-m-d', strtotime("+$i day"));
    
    // Add a small delay to be polite to the server
    if ($i > 1) usleep(500000); // 0.5s delay
    
    try {
        // Get active source
        $activeSource = $db->getActiveScraperSource();
        if ($activeSource === 'yssscore') {
            $result = $scraper->scrapeYssScore($date, false);
        } else {
            $result = $scraper->scrapeKooora($date, false);
        }
        
        if ($result['status'] === 'success') {
            $totalMatches += $result['total'];
            $daysProcessed++;
        } else {
            $errors[] = "Day $date: " . $result['message'];
        }
    } catch (Exception $e) {
        $errors[] = "Day $date: " . $e->getMessage();
    }
}

echo json_encode([
    "status" => "success",
    "message" => "تم جلب $totalMatches مباراة للأيام الـ $daysProcessed القادمة",
    "total" => $totalMatches,
    "days" => $daysProcessed,
    "errors" => $errors
]);
?>
