<?php
header('Content-Type: application/json');
require_once '../config.php';
require_once '../includes/Database.php';
require_once '../includes/Scraper.php';

try {
    $db = new Database($pdo);
    $scraper = new Scraper($db);
    
    $db->clearScrapeLogs();
    $db->addScrapeLog('info', "بدء جلب الأخبار [0%]");
    
    // 1. Get News List (Auto-detects source)
    $newsList = $scraper->scrapeNews(25);
    
    if (empty($newsList)) {
        $db->addScrapeLog('error', "لم يتم العثور على أخبار جديدة [100%]");
        echo json_encode(['status' => 'error', 'message' => 'لم يتم العثور على أخبار']);
        exit;
    }
    
    $total = count($newsList);
    $db->addScrapeLog('info', "تم العثور على $total خبر، جاري جلب التفاصيل... [10%]");

    $count = 0;
    foreach ($newsList as $index => $news) {
        $progress = 10 + round((($index + 1) / $total) * 85);
        
        // Check if news already exists with body
        $existing = $db->getNewsByUrl($news['url']);
        
        if ($existing && !empty($existing['body'])) {
            // Preserve the existing high-quality image from detail page
            if (!empty($existing['image'])) {
                $news['image'] = $existing['image'];
            }
            $db->saveNews($news);
            $db->addScrapeLog('info', "تحديث خبر موجود: {$news['title']} [$progress%]");
            $count++;
            continue;
        }
        
        $db->addScrapeLog('info', "جاري جلب تفاصيل الخبر: {$news['title']} [$progress%]");
        
        // Fetch details
        $details = $scraper->scrapeNewsDetails($news['url'], $news['source'] ?? 'kooora');
        
        if ($details) {
            $newsData = array_merge($news, $details);
            // If detail page image is empty, keep the listing thumbnail
            if (empty($newsData['image']) && !empty($news['image'])) {
                $newsData['image'] = $news['image'];
            }
            $db->saveNews($newsData);
            $db->addScrapeLog('success', "تم حفظ الخبر مع التفاصيل: {$news['title']} [$progress%]");
            $count++;
        } else {
            // Save without body if details fail
            $db->saveNews($news);
            $db->addScrapeLog('filter', "تم حفظ الخبر بدون تفاصيل: {$news['title']} (فشل جلب التفاصيل) [$progress%]");
            $count++;
        }
        
        usleep(100000); // 0.1 seconds
    }
    
    $db->addScrapeLog('info', "اكتمل جلب الأخبار: تم تحديث $count خبر [100%]");
    echo json_encode([
        'status' => 'success', 
        'message' => "تم جلب وتحديث $count خبر بنجاح",
        'total' => $total
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
