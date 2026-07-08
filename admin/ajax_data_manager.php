<?php
session_start();

require_once 'init.php';
require_once '../config.php';
require_once '../includes/ApiResponseCache.php';
require_once '../includes/Database.php';

$db = new Database($pdo);
$apiResponseCache = new ApiResponseCache();
$response = ['status' => 'error', 'message' => 'Invalid action'];

if (isset($_POST['action'])) {
    $action = $_POST['action'];

    try {
        switch ($action) {
            case 'clear_cache':
                $deleted = $apiResponseCache->clear();
                $response = [
                    'status' => 'success',
                    'message' => 'تم تنظيف التخزين المؤقت بنجاح',
                    'deleted' => $deleted
                ];
                break;

            case 'clear_table':
                $table = $_POST['table'] ?? '';
                $allowedTables = ['matches', 'teams', 'players', 'news', 'leagues', 'countries', 'scrape_logs', 'api_logs', 'api_logs_24', 'match_lineups', 'ai_cache'];

                if (in_array($table, $allowedTables, true)) {
                    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

                    if ($table === 'matches') {
                        $pdo->exec("DELETE FROM `match_lineups`");
                        $pdo->exec("DELETE FROM `ai_cache`");
                        $pdo->exec("DELETE FROM `matches`");
                        $message = 'تم تنظيف جدول المباريات وجميع البيانات المرتبطة بها بنجاح';
                    } else {
                        $pdo->exec("DELETE FROM `$table`");
                        $message = "تم تنظيف جدول $table بنجاح";
                    }

                    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
                    $deleted = $apiResponseCache->clear();
                    $response = [
                        'status' => 'success',
                        'message' => $message,
                        'deleted' => $deleted
                    ];
                } else {
                    $response = ['status' => 'error', 'message' => 'جدول غير صالح'];
                }
                break;

            case 'clear_all':
                $tables = ['match_lineups', 'ai_cache', 'matches', 'teams', 'players', 'news', 'leagues', 'countries', 'scrape_logs', 'api_logs', 'api_logs_24'];
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

                foreach ($tables as $table) {
                    try {
                        $pdo->exec("DELETE FROM `$table`");
                        $pdo->exec("ALTER TABLE `$table` AUTO_INCREMENT = 1");
                    } catch (Exception $e) {
                        // Ignore missing tables and continue clearing the rest.
                    }
                }

                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
                $deleted = $apiResponseCache->clear();
                $response = [
                    'status' => 'success',
                    'message' => 'تم تنظيف قاعدة البيانات بالكامل بنجاح',
                    'deleted' => $deleted
                ];
                break;

            case 'upload_sql':
                if (isset($_FILES['sql_file']) && $_FILES['sql_file']['error'] === UPLOAD_ERR_OK) {
                    set_time_limit(600);
                    $sql = file_get_contents($_FILES['sql_file']['tmp_name']);

                    $sql = preg_replace('/--.*?\n/', '', $sql);
                    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
                    $queries = explode(';', $sql);

                    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
                    $successCount = 0;

                    foreach ($queries as $query) {
                        $query = trim($query);
                        if ($query === '') {
                            continue;
                        }

                        try {
                            $pdo->exec($query);
                            $successCount++;
                        } catch (Exception $e) {
                            continue;
                        }
                    }

                    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
                    $apiResponseCache->clear();
                    $response = ['status' => 'success', 'message' => 'تم استيراد قاعدة البيانات بنجاح (' . $successCount . ' استعلام)'];
                } else {
                    $response = ['status' => 'error', 'message' => 'خطأ في رفع الملف'];
                }
                break;

            case 'toggle_task':
                $id = (int)($_POST['task_id'] ?? 0);
                $isActive = (int)($_POST['is_active'] ?? 0);

                if ($id > 0) {
                    $stmt = $pdo->prepare("UPDATE scraper_settings SET is_active = ? WHERE id = ?");
                    $stmt->execute([$isActive, $id]);

                    if ($isActive === 0) {
                        $stmt = $pdo->prepare("SELECT task_key FROM scraper_settings WHERE id = ?");
                        $stmt->execute([$id]);
                        $taskKey = $stmt->fetchColumn();

                        if ($taskKey === 'matches_today') {
                            $dependentTasks = ['lineups', 'previous_matches', 'standings', 'summaries'];
                            $placeholders = implode(',', array_fill(0, count($dependentTasks), '?'));
                            $stmt = $pdo->prepare("UPDATE scraper_settings SET is_active = 0 WHERE task_key IN ($placeholders)");
                            $stmt->execute($dependentTasks);
                        }
                    }

                    $response = ['status' => 'success', 'message' => 'تم تحديث حالة المهمة بنجاح'];
                } else {
                    $response = ['status' => 'error', 'message' => 'معرف المهمة غير صحيح'];
                }
                break;

            case 'toggle_source':
                $id = (int)($_POST['source_id'] ?? 0);
                $isActive = (int)($_POST['is_active'] ?? 0);

                if ($id > 0) {
                    if ($isActive === 1) {
                        $pdo->exec("UPDATE scraper_source_settings SET is_active = 0");
                    }

                    $stmt = $pdo->prepare("UPDATE scraper_source_settings SET is_active = ? WHERE id = ?");
                    $stmt->execute([$isActive, $id]);
                    $response = ['status' => 'success', 'message' => 'تم تحديث حالة المحرك بنجاح'];
                } else {
                    $response = ['status' => 'error', 'message' => 'معرف المحرك غير صحيح'];
                }
                break;

            case 'get_runner_state':
                $stmt = $pdo->prepare("SELECT is_active FROM scraper_settings WHERE task_key = 'smart_runner'");
                $stmt->execute();
                $isActive = $stmt->fetchColumn();

                if ($isActive === false) {
                    $pdo->exec("INSERT INTO scraper_settings (task_key, task_name, is_active, interval_seconds) VALUES ('smart_runner', 'المشغل الذكي', 0, 10)");
                    $isActive = 0;
                }

                $response = ['status' => 'success', 'enabled' => (bool)$isActive];
                break;

            case 'toggle_smart_runner':
                $enabled = (isset($_POST['enabled']) && $_POST['enabled'] === '1') ? 1 : 0;

                $stmt = $pdo->prepare("SELECT id FROM scraper_settings WHERE task_key = 'smart_runner'");
                $stmt->execute();
                if (!$stmt->fetch()) {
                    $pdo->exec("INSERT INTO scraper_settings (task_key, task_name, is_active, interval_seconds) VALUES ('smart_runner', 'المشغل الذكي', 0, 10)");
                }

                $stmt = $pdo->prepare("UPDATE scraper_settings SET is_active = ? WHERE task_key = 'smart_runner'");
                $success = $stmt->execute([$enabled]);

                if ($success) {
                    $response = ['status' => 'success'];
                } else {
                    $response = ['status' => 'error', 'message' => 'Could not update database state.'];
                }
                break;
        }
    } catch (Exception $e) {
        $response = ['status' => 'error', 'message' => 'خطأ: ' . $e->getMessage()];
    }
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
