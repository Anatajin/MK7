<?php
/**
 * Auto Scraper Runner
 * Checks scraper_settings and runs tasks if due.
 * Should be called periodically (e.g., via AJAX or Cron).
 */

require_once '../config.php';
require_once '../includes/Database.php';
require_once '../includes/Scraper.php';

const AUTO_SCRAPER_DB_LOCK = 'sport_auto_scraper_lock';

function acquireAutoScraperLock(PDO $connection): bool
{
    $stmt = $connection->prepare("SELECT GET_LOCK(?, 0)");
    $stmt->execute([AUTO_SCRAPER_DB_LOCK]);
    return (int) $stmt->fetchColumn() === 1;
}

function releaseAutoScraperLock(PDO $connection): void
{
    try {
        $stmt = $connection->prepare("SELECT RELEASE_LOCK(?)");
        $stmt->execute([AUTO_SCRAPER_DB_LOCK]);
    } catch (Throwable $e) {
        // Ignore unlock failures during shutdown.
    }
}

// Set unlimited execution time for this script
set_time_limit(300); 

header('Content-Type: application/json');

$db = new Database($pdo);
$conn = $pdo;

if (!acquireAutoScraperLock($conn)) {
    echo json_encode([
        'status' => 'success',
        'results' => [],
        'message' => 'Auto Scraper is already running.',
        'server_time' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

register_shutdown_function(static function () use ($conn): void {
    releaseAutoScraperLock($conn);
});

// Fetch active settings
$stmt = $conn->query("SELECT * FROM scraper_settings WHERE is_active = 1");
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

define('AUTO_SCRAPER', true);

$results = [];
$now = time();

foreach ($tasks as $task) {
    $lastRun = $task['last_run'] ? strtotime($task['last_run']) : 0;
    $interval = (int)$task['interval_seconds'];
    $startTime = $task['start_time']; // 'HH:MM:SS' or NULL
    $key = $task['task_key'];
    
    $shouldRun = false;

    if ($startTime && $interval >= 86000) {
        // Daily task logic with fixed start time
        // Check if we passed the start time today
        $todayScheduled = strtotime(date('Y-m-d') . ' ' . $startTime);
        
        // If now is after scheduled time
        if ($now >= $todayScheduled) {
            // Run if we haven't run since the scheduled time
            if ($lastRun < $todayScheduled) {
                $shouldRun = true;
            }
        }
    } else {
        // Standard interval logic
        if (($now - $lastRun) >= $interval) {
            $shouldRun = true;
        }
    }
    
    // Special Logic for Live Stream: Run if there are live matches
    if ($key === 'live_stream') {
        // Check for live matches
        $liveCountStmt = $conn->query("SELECT COUNT(*) FROM matches WHERE status = 'Live'");
        $liveCount = $liveCountStmt->fetchColumn();
        
        if ($liveCount > 0) {
            // If there are live matches, reduce interval to 10 minutes (600s) to ensure streams are fresh
            // But only if it hasn't run in the last 10 minutes
            if (($now - $lastRun) >= 600) {
                $shouldRun = true;
                $results[] = "Force running Live Stream check due to active matches.";
            }
        }
    }

    if ($shouldRun) {
        $startTime = microtime(true);
        $output = "";
        
        try {
            // Execute the specific scraper logic
            switch ($key) {
                case 'fifa_rankings':
                    ob_start();
                    include 'scrape_fifa_rankings.php';
                    $output = ob_get_clean();
                    break;

                case 'matches_today':
                    ob_start();
                    include 'scrape.php'; 
                    $output = ob_get_clean();
                    break;
                    
                case 'summaries':
                    ob_start();
                    include 'scrape_summaries.php';
                    $output = ob_get_clean();
                    break;

                case 'lineups':
                    ob_start();
                    include 'scrape_lineups.php';
                    $output = ob_get_clean();
                    break;

                case 'live_stream':
                    ob_start();
                    include 'scrape_live.php';
                    $output = ob_get_clean();
                    break;

                case 'matches_tomorrow_full':
                    ob_start();
                    include 'scrape_tomorrow_full.php';
                    $output = ob_get_clean();
                    break;

                case 'matches_next10':
                    ob_start();
                    include 'scrape_next_10_days_full.php';
                    $output = ob_get_clean();
                    break;

                case 'standings':
                    ob_start();
                    include 'scrape_standings.php';
                    $output = ob_get_clean();
                    break;

                case 'previous_matches':
                    ob_start();
                    include 'scrape_previous_matches.php';
                    $output = ob_get_clean();
                    break;

                case 'news':
                    $scraper = new Scraper($db);
                    
                    $db->clearScrapeLogs();
                    $db->addScrapeLog('info', "بدء جلب الأخبار [0%]");
                    
                    $newsList = $scraper->scrapeNews(25);
                    
                    if (empty($newsList)) {
                        $db->addScrapeLog('error', "لم يتم العثور على أخبار جديدة [100%]");
                        $output = "لم يتم العثور على أخبار";
                        break;
                    }
                    
                    $total = count($newsList);
                    $db->addScrapeLog('info', "تم العثور على $total خبر، جاري جلب التفاصيل... [10%]");
                    
                    $newsCount = 0;
                    foreach ($newsList as $index => $news) {
                        $progress = 10 + round((($index + 1) / $total) * 85);
                        
                        $existing = $db->getNewsByUrl($news['url']);
                        
                        if ($existing && !empty($existing['body'])) {
                            // Preserve the existing high-quality image from detail page
                            // Don't overwrite it with the low-quality thumbnail from listing
                            if (!empty($existing['image'])) {
                                $news['image'] = $existing['image'];
                            }
                            $db->saveNews($news);
                            $db->addScrapeLog('info', "تحديث خبر موجود: {$news['title']} [$progress%]");
                            $newsCount++;
                            continue;
                        }
                        
                        $db->addScrapeLog('info', "جاري جلب تفاصيل الخبر: {$news['title']} [$progress%]");
                        
                        $details = $scraper->scrapeNewsDetails($news['url'], $news['source'] ?? 'kooora');
                        
                        if ($details) {
                            $newsData = array_merge($news, $details);
                            // If detail page image is empty, keep the listing thumbnail
                            if (empty($newsData['image']) && !empty($news['image'])) {
                                $newsData['image'] = $news['image'];
                            }
                            $db->saveNews($newsData);
                            $db->addScrapeLog('success', "تم حفظ الخبر مع التفاصيل: {$news['title']} [$progress%]");
                            $newsCount++;
                        } else {
                            $db->saveNews($news);
                            $db->addScrapeLog('filter', "تم حفظ الخبر بدون تفاصيل: {$news['title']} (فشل جلب التفاصيل) [$progress%]");
                            $newsCount++;
                        }
                        
                        usleep(100000);
                    }
                    
                    $db->addScrapeLog('info', "اكتمل جلب الأخبار: تم تحديث $newsCount خبر [100%]");
                    $output = "تم جلب وتحديث $newsCount خبر بنجاح";
                    break;

                case 'statistics':
                    ob_start();
                    include 'scrape_statistics.php';
                    $output = ob_get_clean();
                    break;

                case 'clean_matches':
                    $dateLimit = date('Y-m-d', strtotime('-14 days'));

                    
                    // 1. Clean up old logs (Keep only last 7 days to save space)
                    $logLimit = date('Y-m-d H:i:s', strtotime('-7 days'));
                    $conn->prepare("DELETE FROM scrape_logs WHERE created_at < ?")->execute([$logLimit]);
                    $conn->prepare("DELETE FROM logs_scraper WHERE created_at < ?")->execute([$logLimit]);

                    // 2. Clean up file-based logs (e.g., cors_debug.log)
                    $corsLog = __DIR__ . '/cors_debug.log';
                    if (file_exists($corsLog)) {
                        file_put_contents($corsLog, ""); // Empty the file
                    }

                    // 3. Clean up old matches (foreign keys will handle match_lineups and lineup_players via CASCADE)
                    $stmt = $conn->prepare("DELETE FROM matches WHERE match_date < ?");
                    $stmt->execute([$dateLimit]);
                    $count = $stmt->rowCount();
                    
                    $output = "Deleted $count old matches, cleaned DB logs (7 days) and wiped cors_debug.log.";
                    break;



                case 'clean_news':
                    $dateLimit = date('Y-m-d H:i:s', strtotime('-7 days'));
                    $stmt = $conn->prepare("DELETE FROM news WHERE created_at < ?");
                    $stmt->execute([$dateLimit]);
                    $count = $stmt->rowCount();
                    $output = "Deleted $count old news articles.";
                    break;

                case 'aggregate_api_logs':
                    // 1. Aggregate yesterday's data
                    $yesterday = date('Y-m-d', strtotime('-1 day'));
                    $stmt = $conn->prepare("SELECT COUNT(*) FROM api_logs_24 WHERE DATE(created_at) = ?");
                    $stmt->execute([$yesterday]);
                    $count = $stmt->fetchColumn();
                    
                    if ($count > 0) {
                        $stmt = $conn->prepare("INSERT INTO api_logs (log_date, request_count) VALUES (?, ?) ON DUPLICATE KEY UPDATE request_count = ?");
                        $stmt->execute([$yesterday, $count, $count]);
                    }
                    
                    // 2. Clean up api_logs_24 (keep only last 24 hours)
                    $stmt = $conn->prepare("DELETE FROM api_logs_24 WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
                    $stmt->execute();
                    $deleted = $stmt->rowCount();
                    
                    $output = "Aggregated $count requests for $yesterday. Deleted $deleted old logs.";
                    break;
            }
            
            // Update last_run using PHP time to ensure consistency
            $updateStmt = $conn->prepare("UPDATE scraper_settings SET last_run = ? WHERE id = ?");
            $updateStmt->execute([date('Y-m-d H:i:s'), $task['id']]);
            
            $duration = round(microtime(true) - $startTime, 2);
            $results[] = [
                'task' => $task['task_name'],
                'status' => 'success',
                'duration' => $duration . 's',
                'message' => $output // Show the actual output (e.g., "Deleted 5 matches")
            ];

            
        } catch (Exception $e) {
            $results[] = [
                'task' => $task['task_name'],
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    } else {
        // Debug info for skipped tasks (useful for troubleshooting hosting issues)
        // $results[] = ['task' => $task['task_name'], 'status' => 'skipped', 'reason' => 'Not due yet', 'diff' => ($now - $lastRun), 'interval' => $interval];
    }
}

echo json_encode(['status' => 'success', 'results' => $results, 'server_time' => date('Y-m-d H:i:s')]);
?>
