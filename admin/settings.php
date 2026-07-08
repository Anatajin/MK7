<?php
require_once 'init.php';
require_once '../config.php';
require_once '../includes/Database.php';

$db = new Database($pdo);
$conn = $pdo;

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_settings'])) {
    foreach ($_POST['intervals'] as $id => $seconds) {
        $isActive = isset($_POST['active'][$id]) ? 1 : 0;
        $startTime = !empty($_POST['start_times'][$id]) ? $_POST['start_times'][$id] : null;
        
        $stmt = $conn->prepare("UPDATE scraper_settings SET interval_seconds = ?, start_time = ?, is_active = ? WHERE id = ?");
        $stmt->execute([(int)$seconds, $startTime, $isActive, (int)$id]);
    }
    $message = "تم حفظ الإعدادات بنجاح!";
}

// Handle Translation API Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_translation_key'])) {
        $name = $_POST['key_name'] ?? 'Gemini Key';
        $key = $_POST['api_key'] ?? '';
        if (!empty($key)) {
            $stmt = $conn->prepare("INSERT INTO translation_api_keys (provider, api_key, is_active) VALUES (?, ?, 0)");
            $stmt->execute([$name, $key]);
            $message = "تم إضافة مفتاح الترجمة بنجاح!";
        }
    } elseif (isset($_POST['delete_translation_key'])) {
        $id = $_POST['key_id'] ?? 0;
        $stmt = $conn->prepare("DELETE FROM translation_api_keys WHERE id = ?");
        $stmt->execute([(int)$id]);
        $message = "تم حذف المفتاح بنجاح!";
    } elseif (isset($_POST['toggle_translation_key'])) {
        $id = $_POST['key_id'] ?? 0;
        // Deactivate all first
        $conn->exec("UPDATE translation_api_keys SET is_active = 0");
        // Activate selected
        $stmt = $conn->prepare("UPDATE translation_api_keys SET is_active = 1 WHERE id = ?");
        $stmt->execute([(int)$id]);
        $message = "تم تفعيل المفتاح المحدد!";
    }
}

// Ensure table exists
$conn->exec("CREATE TABLE IF NOT EXISTS translation_api_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(50) NOT NULL,
    api_key TEXT NOT NULL,
    is_active TINYINT(1) DEFAULT 0,
    request_count INT DEFAULT 0,
    last_used_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Add missing columns if they don't exist
try {
    $conn->exec("ALTER TABLE translation_api_keys ADD COLUMN request_count INT DEFAULT 0 AFTER is_active");
    $conn->exec("ALTER TABLE translation_api_keys ADD COLUMN last_used_at DATETIME NULL AFTER request_count");
} catch (Exception $e) {
    // Columns might already exist
}

// Fetch Settings
$stmt = $conn->query("SELECT * FROM scraper_settings ORDER BY id ASC");
$settings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Define default tasks that should exist
// Define default tasks that should exist
$defaultTasks = [
    ['task_key' => 'matches_today', 'task_name' => 'مباريات اليوم', 'interval_seconds' => 120, 'start_time' => null, 'description' => 'جلب وتحديث مباريات اليوم'],
    ['task_key' => 'matches_tomorrow_full', 'task_name' => 'مباريات الغد (كامل)', 'interval_seconds' => 86400, 'start_time' => '00:30:00', 'description' => 'جلب مباريات الغد مع كافة التفاصيل'],
    ['task_key' => 'matches_next10', 'task_name' => 'مباريات 10 أيام (كامل)', 'interval_seconds' => 86400, 'start_time' => '01:00:00', 'description' => 'جلب جدول المباريات للـ 10 أيام القادمة مع كافة التفاصيل'],
    ['task_key' => 'summaries', 'task_name' => 'تفاصيل المباريات', 'interval_seconds' => 3600, 'start_time' => null, 'description' => 'جلب تفاصيل وملخصات المباريات'],
    ['task_key' => 'lineups', 'task_name' => 'التشكيلات', 'interval_seconds' => 18000, 'start_time' => null, 'description' => 'جلب تشكيلات الفرق للمباريات'],
    ['task_key' => 'standings', 'task_name' => 'جداول الترتيب', 'interval_seconds' => 21600, 'start_time' => '06:00:00', 'description' => 'تحديث جداول الترتيب لجميع الدوريات'],
    ['task_key' => 'previous_matches', 'task_name' => 'المواجهات السابقة', 'interval_seconds' => 43200, 'start_time' => '03:00:00', 'description' => 'جلب تاريخ المواجهات السابقة بين الفرق'],
    ['task_key' => 'news', 'task_name' => 'الأخبار الرياضية', 'interval_seconds' => 1800, 'start_time' => null, 'description' => 'جلب آخر الأخبار الرياضية'],
    ['task_key' => 'fifa_rankings', 'task_name' => 'ترتيب FIFA العالمي', 'interval_seconds' => 604800, 'start_time' => '03:00:00', 'description' => 'تحديث تصنيف المنتخبات العالمي (أسبوعياً)']
];


// Remove deprecated tasks (old matches_tomorrow, live_stream, events) - REMOVED lineups from here
$deprecatedTasks = ['matches_tomorrow', 'live_stream', 'events'];
$conn->exec("DELETE FROM scraper_settings WHERE task_key IN ('matches_tomorrow', 'live_stream', 'events')");

// Check and insert missing tasks
$stmt = $conn->query("SELECT * FROM scraper_settings ORDER BY id ASC");
$settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
$existingKeys = array_column($settings, 'task_key');

$insertStmt = $conn->prepare("INSERT INTO scraper_settings (task_key, task_name, interval_seconds, start_time, is_active, description) VALUES (?, ?, ?, ?, 0, ?)");
$updateStmt = $conn->prepare("UPDATE scraper_settings SET task_name = ? WHERE task_key = ?");

$needsRefresh = false;
foreach ($defaultTasks as $task) {
    if (!in_array($task['task_key'], $existingKeys)) {
        $insertStmt->execute([$task['task_key'], $task['task_name'], $task['interval_seconds'], $task['start_time'], $task['description']]);
        $needsRefresh = true;
    } else {
        // Ensure name is updated (e.g. changing "Summaries" to "Match Details")
        $updateStmt->execute([$task['task_name'], $task['task_key']]);
    }
}

// Scraper Source Settings
$conn->exec("CREATE TABLE IF NOT EXISTS scraper_source_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    source_key VARCHAR(50) NOT NULL UNIQUE,
    source_name VARCHAR(50) NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$defaultSources = [
    ['source_key' => 'kooora', 'source_name' => 'Kooora'],
    ['source_key' => 'uniscore', 'source_name' => 'Uni score'],
    ['source_key' => 'yssscore', 'source_name' => 'yssScore']
];

foreach ($defaultSources as $src) {
    $check = $conn->prepare("SELECT id FROM scraper_source_settings WHERE source_key = ?");
    $check->execute([$src['source_key']]);
    if (!$check->fetch()) {
        $insert = $conn->prepare("INSERT INTO scraper_source_settings (source_key, source_name, is_active) VALUES (?, ?, 1)");
        $insert->execute([$src['source_key'], $src['source_name']]);
    }
}

$stmt = $conn->query("SELECT * FROM scraper_source_settings ORDER BY id ASC");
$scraperSources = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Refresh settings if we added new ones
if ($needsRefresh) {
    $stmt = $conn->query("SELECT * FROM scraper_settings ORDER BY id ASC");
    $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch Translation API Keys
$translationKeys = $conn->query("SELECT * FROM translation_api_keys ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

// Stats for Mini Cards
$stats = $db->getStats();
$todayStats = $db->getTodayStats();





$pcts = [
    'matches' => $todayStats['total'] > 0 ? 100 : 0,
    'summaries' => $todayStats['total'] > 0 ? round(($todayStats['with_summary'] / $todayStats['total']) * 100) : 0,
    'lineups' => $todayStats['total'] > 0 ? round(($todayStats['with_lineups'] / $todayStats['total']) * 100) : 0,
    'live' => $todayStats['total'] > 0 ? round(($todayStats['with_live'] / $todayStats['total']) * 100) : 0,
    'stats' => $todayStats['total'] > 0 ? round(($todayStats['with_stats'] / $todayStats['total']) * 100) : 0,
    'events' => $todayStats['total'] > 0 ? round(($todayStats['with_events'] / $todayStats['total']) * 100) : 0,
    'previous_matches' => $todayStats['total'] > 0 ? round(($todayStats['with_previous_matches'] / $todayStats['total']) * 100) : 0,
    'standings' => $todayStats['total'] > 0 ? round(($todayStats['with_standings'] / $todayStats['total']) * 100) : 0,
    'tomorrow' => $todayStats['tomorrow_matches'] > 0 ? 100 : 0,
    'next10' => round(($todayStats['next_10_days_coverage'] / 10) * 100),
    'news' => $db->getNewsCount()
];

$scrapeItems = [
    ['id' => 'matches', 'name' => 'مباريات اليوم', 'icon' => 'fa-futbol', 'pct' => $pcts['matches']],
    ['id' => 'tomorrow', 'name' => 'مباريات الغد', 'icon' => 'fa-calendar-plus', 'pct' => $pcts['tomorrow']],
    ['id' => 'next10', 'name' => 'مباريات 10 أيام', 'icon' => 'fa-calendar-alt', 'pct' => $pcts['next10']],
    ['id' => 'summaries', 'name' => 'تفاصيل المباريات', 'icon' => 'fa-info-circle', 'pct' => $pcts['summaries']],
    ['id' => 'lineups', 'name' => 'التشكيلات', 'icon' => 'fa-users', 'pct' => $pcts['lineups']],
    ['id' => 'live', 'name' => 'روابط البث', 'icon' => 'fa-broadcast-tower', 'pct' => $pcts['live']],
    ['id' => 'events', 'name' => 'أحداث المباريات', 'icon' => 'fa-history', 'pct' => $pcts['events']],
    ['id' => 'standings', 'name' => 'جداول الترتيب', 'icon' => 'fa-table', 'pct' => $pcts['standings']],
    ['id' => 'stats', 'name' => 'إحصائيات الفرق', 'icon' => 'fa-chart-pie', 'pct' => $pcts['stats']],
    ['id' => 'previous_matches', 'name' => 'المواجهات السابقة', 'icon' => 'fa-exchange-alt', 'pct' => $pcts['previous_matches']],
    ['id' => 'news', 'name' => 'الأخبار الرياضية', 'icon' => 'fa-newspaper', 'pct' => 100],
    ['id' => 'fifa_rankings', 'name' => 'ترتيب الفيفا', 'icon' => 'fa-trophy', 'pct' => 100]
];



// API Usage History (Last 24h)
$apiStats = $db->getApiUsageHistory('24h');

// Smart Runner: shared state from database
$stmtSR = $pdo->prepare("SELECT is_active FROM scraper_settings WHERE task_key = 'smart_runner'");
$stmtSR->execute();
$smartRunnerEnabled = (bool)$stmtSR->fetchColumn();

$pageTitle = 'إعدادات النظام';
require_once 'includes/header.php';
?>
<!-- Honeypot to prevent browser from autofilling search box -->
<div style="position: absolute; left: -9999px; top: -9999px;">
    <input type="text" name="fake_user_name_prevent_autofill" value="">
    <input type="password" name="fake_password_prevent_autofill" value="">
</div>

<script>window.SMART_RUNNER_SERVER_STATE = <?= $smartRunnerEnabled ? 'true' : 'false' ?>;</script>

<div class="dashboard-grid">
    <!-- Left Column: API Chart and Tasks -->
    <div class="dashboard-column">
        <!-- API Pressure Chart -->
        <!-- Scrape Controls (Horizontal Rows) -->
        <div id="scrapeSection" class="card" style=" background: #fff; padding: 20px; border-radius: 12px; border: 1px solid #f1f5f9;">
            <div class="card-header" style="padding: 0; margin-bottom: 20px; border-bottom: none; display: flex; justify-content: space-between; align-items: center;">
                <h3 class="card-title" style="font-size: 0.9rem; color: var(--text-primary); display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-sync-alt" style="color: var(--accent);"></i> تحديث البيانات
                </h3>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <span style="font-size: 0.75rem; color: var(--text-secondary);">إظهار السجلات</span>
                    <label class="switch" style="transform: scale(0.7);">
                        <input type="checkbox" id="consoleToggle" onchange="saveConsolePreference(this.checked)">
                        <span class="slider round"></span>
                    </label>
                </div>
            </div>
            <div class="stats-grid" style="grid-template-columns: repeat(2, 1fr); gap: 15px;">
                <?php foreach ($scrapeItems as $item): ?>
                <div class="stat-card-mini task-card-interactive" id="<?= $item['id'] ?>Card" style="height: 100px; padding: 15px; cursor: pointer; position: relative; background: transparent; border: 1px solid transparent; box-shadow: none; transition: all 0.3s ease;" onclick="startScraping('<?= $item['id'] ?>')">
                    <div class="mini-icon" style="background: rgba(59, 130, 246, 0.1); color: #3b82f6;"><i class="fas <?= $item['icon'] ?>"></i></div>
                    <div class="mini-info" style="flex: 1;">
                        <p class="scrape-status" id="<?= $item['id'] ?>Status" style="margin: 0; font-size: 0.75rem;">جاهز</p>
                        <h4 style="font-size: 0.85rem; margin-top: 5px; color: var(--text-primary);"><?= $item['name'] ?></h4>
                        <div style="display: flex; align-items: center; gap: 10px; margin-top: 5px;">
                            <div class="scrape-progress-bar" style="flex: 1; height: 4px; background: #f1f5f9; border-radius: 2px; overflow: hidden; max-width: none;">
                                <div class="scrape-progress-fill" style="width: <?= $item['pct'] ?>%; height: 100%; background: var(--accent); transition: width 0.3s ease;"></div>
                            </div>
                            <span style="font-size: 0.7rem; font-weight: 600; color: var(--accent);"><?= $item['pct'] ?>%</span>
                        </div>
                    </div>
                    <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 10px;" onclick="event.stopPropagation()">
                         <button class="btn-primary" style="padding: 5px 10px; font-size: 0.75rem; border-radius: 6px;" onclick="startScraping('<?= $item['id'] ?>')">
                            <i class="fas fa-sync-alt"></i>
                         </button>
                         <?php if ($item['id'] === 'tomorrow'): ?>
                            <button class="btn-primary" style="padding: 5px 10px; font-size: 0.75rem; border-radius: 6px; background: var(--accent);" onclick="startScrapingTomorrowFull()">الكل</button>
                         <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>






        <!-- Scraper Tasks -->
        <!-- Scraper Tasks -->
        <div class="card" style=" background: #fff; padding: 20px; border-radius: 12px; border: 1px solid #f1f5f9;">
            <div class="card-header" style="padding: 0; margin-bottom: 20px; border-bottom: none; display: flex; justify-content: space-between; align-items: center;">
                <h3 class="card-title" style="font-size: 0.9rem; color: var(--text-primary); display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-calendar-alt" style="color: var(--accent);"></i> إعدادات الجدولة والذكاء الاصطناعي
                </h3>
                <?php if (isset($message)): ?>
                    <span style="font-size: 0.8rem; color: #10b981; font-weight: 600;"><i class="fas fa-check"></i> تم الحفظ</span>
                <?php endif; ?>
            </div>

            <!-- Smart Runner Control -->
            <div style="background: rgba(99, 102, 241, 0.05); border: 1px solid rgba(99, 102, 241, 0.1); border-radius: 12px; padding: 20px; margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center;">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <div class="mini-icon" style="background: linear-gradient(135deg, #6366f1 0%, #a855f7 100%); color: #fff; width: 45px; height: 45px; font-size: 1.2rem; border-radius: 12px; box-shadow: 0 4px 12px rgba(99, 102, 241, 0.2);"><i class="fa-solid fa-wand-sparkles"></i></div>
                    <div>
                        <h4 style="margin: 0; font-size: 0.95rem; color: var(--text-primary);">المشغل الذكي (Smart Runner)</h4>
                        <p style="margin: 5px 0 0; font-size: 0.75rem; color: var(--text-secondary);">تحديث تلقائي للمباريات المباشرة والوشيكة كل 10 ثوانٍ (التشكيلات، الأحداث، الإحصائيات، البث)</p>
                    </div>
                </div>
                <div style="display: flex; align-items: center; gap: 15px;">
                    <div id="smartRunnerStatus" style="display: flex; align-items: center; gap: 8px; font-size: 0.75rem; font-weight: 600; color: var(--text-secondary);">
                        <span class="status-dot" style="width: 8px; height: 8px; border-radius: 50%; background: #cbd5e1;"></span>
                        متوقف
                    </div>
                    <!-- Toggle only visible to editors and admins -->
                    <label class="switch switch-ai">
                        <input type="checkbox" id="smartRunnerToggle" onchange="toggleSmartRunner(this.checked)">
                        <span class="slider round"></span>
                    </label>
                </div>
            </div>
            
            <form method="post" action="">
                <div class="stats-grid" style="grid-template-columns: repeat(2, 1fr); gap: 12px;">
                    <?php 
                    foreach ($settings as $task): 
                    ?>
                        <div class="task-card-wrapper" style="position: relative;">
                            <div class="stat-card-mini task-card-interactive" style="height: 70px; padding: 12px; cursor: pointer; position: relative; background: transparent; border: 1px solid #f1f5f9; box-shadow: none; transition: all 0.3s ease; border-radius: 10px;" onclick="toggleTaskSettings(<?= $task['id'] ?>)">
                                <div class="mini-icon" style="background: rgba(59, 130, 246, 0.1); color: #3b82f6; width: 36px; height: 36px; font-size: 0.9rem;"><i class="fas fa-clock"></i></div>
                                <div class="mini-info" style="flex: 1;">
                                    <p style="margin: 0; font-size: 0.65rem; color: var(--text-secondary);">آخر: <?= $task['last_run'] ? date('H:i', strtotime($task['last_run'])) : '---' ?></p>
                                    <h4 style="font-size: 0.75rem; margin-top: 3px; color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?= htmlspecialchars($task['task_name']) ?></h4>
                                </div>
                                <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 5px;" onclick="event.stopPropagation()">
                                    <label class="switch" style="transform: scale(0.55);">
                                        <input type="checkbox" name="active[<?= $task['id'] ?>]" <?= $task['is_active'] ? 'checked' : '' ?> data-task-key="<?= $task['task_key'] ?>" data-id="<?= $task['id'] ?>" class="task-switch" onchange="handleTaskToggle(this)">
                                        <span class="slider round"></span>
                                    </label>
                                    <i class="fas fa-cog" style="font-size: 0.65rem; opacity: 0.3; cursor: pointer;" onclick="toggleTaskSettings(<?= $task['id'] ?>)"></i>
                                </div>
                            </div>
                            
                            <!-- Settings Panel (Popup style) -->
                            <div id="settings-panel-<?= $task['id'] ?>" style="display: none; position: absolute; bottom: 110%; left: 0; right: 0; background: #fff; padding: 15px; border-radius: 12px; border: 1px solid #f1f5f9; z-index: 1000; box-shadow: 0 15px 35px rgba(0,0,0,0.2);" onclick="event.stopPropagation()">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; border-bottom: 1px solid #f1f5f9; padding-bottom: 8px;">
                                    <span style="font-size: 0.75rem; font-weight: 600; color: var(--text-primary);">إعدادات الجدولة</span>
                                    <div style="display: flex; gap: 10px; align-items: center;">
                                        <i class="fas fa-check settings-btn-save" style="cursor: pointer; font-size: 0.8rem; color: #10b981; opacity: 0.8; transition: all 0.2s;" title="حفظ" onclick="saveTaskSettings()"></i>
                                        <i class="fas fa-times settings-btn-close" style="cursor: pointer; font-size: 0.8rem; opacity: 0.5; transition: all 0.2s;" title="إغلاق" onclick="toggleTaskSettings(<?= $task['id'] ?>)"></i>
                                    </div>
                                </div>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                                    <div style="display: flex; flex-direction: column; gap: 4px;">
                                        <label style="font-size: 0.65rem; color: var(--text-secondary); font-weight: 600;">وقت البدء</label>
                                        <input type="time" name="start_times[<?= $task['id'] ?>]" value="<?= $task['start_time'] ?>" class="form-control" style="font-size: 0.75rem; padding: 5px; height: 32px;">
                                    </div>
                                    <div style="display: flex; flex-direction: column; gap: 4px;">
                                        <label style="font-size: 0.65rem; color: var(--text-secondary); font-weight: 600;">التكرار (يوم:ساعة:دقيقة)</label>
                                        <?php
                                        $seconds = $task['interval_seconds'];
                                        $days = floor($seconds / 86400);
                                        $hours = floor(($seconds % 86400) / 3600);
                                        $minutes = floor(($seconds % 3600) / 60);
                                        ?>
                                        <input type="hidden" name="intervals[<?= $task['id'] ?>]" id="interval_<?= $task['id'] ?>" value="<?= $seconds ?>">
                                        <div style="display: flex; gap: 8px; direction: ltr; align-items: center;">
                                            <div style="position: relative; flex: 1;">
                                                <span style="position: absolute; left: 5px; top: 50%; transform: translateY(-50%); font-size: 0.7rem; color: var(--text-secondary); font-weight: bold;">d</span>
                                                <input type="number" min="0" value="<?= $days ?>" class="form-control" style="font-size: 0.75rem; padding: 5px 5px 5px 15px; height: 32px; text-align: center;" onchange="updateInterval(<?= $task['id'] ?>)">
                                            </div>
                                            <div style="position: relative; flex: 1;">
                                                <span style="position: absolute; left: 5px; top: 50%; transform: translateY(-50%); font-size: 0.7rem; color: var(--text-secondary); font-weight: bold;">h</span>
                                                <input type="number" min="0" max="23" value="<?= $hours ?>" class="form-control" style="font-size: 0.75rem; padding: 5px 5px 5px 15px; height: 32px; text-align: center;" onchange="updateInterval(<?= $task['id'] ?>)">
                                            </div>
                                            <div style="position: relative; flex: 1;">
                                                <span style="position: absolute; left: 5px; top: 50%; transform: translateY(-50%); font-size: 0.7rem; color: var(--text-secondary); font-weight: bold;">m</span>
                                                <input type="number" min="0" max="59" value="<?= $minutes ?>" class="form-control" style="font-size: 0.75rem; padding: 5px 5px 5px 15px; height: 32px; text-align: center;" onchange="updateInterval(<?= $task['id'] ?>)">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div style="margin-top: 20px; text-align: left;">
                    <button type="submit" name="update_settings" class="btn-primary" style="padding: 8px 25px; font-size: 0.85rem; border-radius: 8px;">
                        <i class="fas fa-save"></i> حفظ التغييرات
                    </button>
                </div>
            </form>
        </div>

    </div>

    <!-- Right Column: Stats, Backup, and Combined Chart/Logs -->
    <div class="dashboard-column">
        <!-- Mini Stats Grid -->
        <!-- Action Banner -->


        <!-- Mini Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card-mini">
                <div class="mini-icon"><i class="fas fa-futbol"></i></div>
                <div class="mini-info">
                    <p>إجمالي المباريات</p>
                    <h4><?= $stats['total_matches'] ?></h4>
                </div>
            </div>
            <div class="stat-card-mini">
                <div class="mini-icon" style="background: rgba(16, 185, 129, 0.1); color: #10b981;"><i class="fas fa-broadcast-tower"></i></div>
                <div class="mini-info">
                    <p>مباريات مباشرة</p>
                    <h4><?= $stats['live_matches'] ?></h4>
                </div>
            </div>
            <div class="stat-card-mini">
                <div class="mini-icon" style="background: rgba(245, 158, 11, 0.1); color: #f59e0b;"><i class="fas fa-newspaper"></i></div>
                <div class="mini-info">
                    <p>إجمالي الأخبار</p>
                    <h4><?= $pcts['news'] ?></h4>
                </div>
            </div>
            <div class="stat-card-mini">
                <div class="mini-icon" style="background: rgba(139, 92, 246, 0.1); color: #8b5cf6;"><i class="fas fa-shield-alt"></i></div>
                <div class="mini-info">
                    <p>عدد الفرق</p>
                    <h4><?= $stats['teams_count'] ?></h4>
                </div>
            </div>
            <div class="stat-card-mini">
                <div class="mini-icon" style="background: rgba(56, 189, 248, 0.1); color: #38bdf8;"><i class="fas fa-trophy"></i></div>
                <div class="mini-info">
                    <p>عدد الدوريات</p>
                    <h4><?= $stats['leagues_count'] ?></h4>
                </div>
            </div>
            <div class="stat-card-mini">
                <div class="mini-icon" style="background: rgba(236, 72, 153, 0.1); color: #ec4899;"><i class="fas fa-globe"></i></div>
                <div class="mini-info">
                    <p>عدد البلدان</p>
                    <h4><?= $stats['countries_count'] ?></h4>
                </div>
            </div>
        </div>

        <!-- Backup Action Banner -->
        <div class="action-banner" style=" background: linear-gradient(135deg, #ec4899 0%, #be185d 100%); border: none;">
            <div class="banner-content">
                <p class="banner-tag" style=" color: white;">أمان البيانات</p>
                <h2 class="banner-title" style="color: white;">نسخة احتياطية<br>لقاعدة البيانات</h2>
                <a href="backup.php?run=1" class="banner-btn" style="text-decoration: none; display: inline-block; text-align: center; background: white; color: #be185d;">تحميل SQL</a>
            </div>
            <i class="fas fa-database banner-img" style="font-size: 8rem; position: absolute; left: -20px; bottom: -20px; opacity: 0.15; transform: rotate(-15deg); color: white;"></i>
        </div>

        <!-- Data Distribution Chart -->


        <!-- Runner Logs -->
        <div class="card" style=" padding: 0; overflow: hidden;">
            <div id="runnerWindow" class="code-window" style="border-radius: 0; border: none; box-shadow: none;">
                <div class="code-header" style="background: #1e293b;">
                    <div class="code-dots">
                        <div class="code-dot dot-red"></div>
                        <div class="code-dot dot-yellow"></div>
                        <div class="code-dot dot-green"></div>
                    </div>
                    <div class="code-actions" style="display: flex; gap: 5px;">
                        <button type="button" class="theme-btn" onclick="toggleFullScreenLogs()" title="تكبير السجلات" style="background: rgba(255,255,255,0.1); border: none; color: #cbd5e1; cursor: pointer; padding: 4px 8px; border-radius: 4px;">
                            <i class="fas fa-expand"></i>
                        </button>
                        <button type="button" class="theme-btn" onclick="toggleRunnerTheme(this)" title="الوضع النهاري" style="background: rgba(255,255,255,0.1); border: none; color: #cbd5e1; cursor: pointer; padding: 4px 8px; border-radius: 4px;">
                            <i class="fas fa-sun"></i>
                        </button>
                    </div>
                </div>
                <div id="runnerLog" style="padding: 15px; height: 250px; overflow-y: auto; font-family: 'Fira Code', 'Consolas', monospace; font-size: 0.75rem; line-height: 1.6; transition: all 0.3s ease;">
                    <div style="color: #64748b;">> جاري الانتظار...</div>
                </div>
            </div>
        </div>

        <!-- Data Management Section -->
        <div>
            <div style="background: #fff; padding: 15px 20px; border-radius: 12px; border: 1px solid #f1f5f9; margin-bottom: 15px;">
                <h4 style="font-size: 0.9rem; margin: 0; color: var(--text-primary); display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-database" style="color: var(--accent);"></i> إدارة البيانات والنظام
                </h4>
            </div>
            
            <div class="stats-grid" style="grid-template-columns: repeat(2, 1fr); gap: 15px;">
                <!-- Clear Table Card -->
                <div class="stat-card-mini" style="height: 100px; padding: 15px; cursor: pointer; position: relative; overflow: visible; background: #fff; border: 1px solid #f1f5f9;" onclick="toggleTableDropdown(event)">
                    <div class="mini-icon" style="background: rgba(59, 130, 246, 0.1); color: #3b82f6;"><i class="fas fa-broom"></i></div>
                    <div class="mini-info" style="flex: 1;">
                        <p style="margin: 0; font-size: 0.75rem;">تنظيف جدول</p>
                        <h4 id="selectedTableLabel" style="font-size: 0.85rem; margin-top: 5px; color: #3b82f6;">اختر الجدول</h4>
                    </div>
                    <i class="fas fa-chevron-left" style="font-size: 0.7rem; opacity: 0.3; margin-right: auto;"></i>
                    
                    <!-- Hidden Dropdown -->
                    <div id="tableDropdown" style="display: none; position: absolute; bottom: 100%; left: 0; right: 0; background: #fff; border: 1px solid var(--border-color); border-radius: 12px; margin-bottom: 10px; z-index: 1000; box-shadow: 0 15px 35px rgba(0,0,0,0.2); overflow: hidden;" onclick="event.stopPropagation()">
                        <div style="padding: 10px; border-bottom: 1px solid var(--border-color); background: #f8fafc;">
                            <input type="text" id="tableSearchInput" placeholder="بحث عن جدول..." onkeyup="filterTableSelect()" class="form-control" style="padding: 6px 12px; font-size: 0.75rem; width: 100%; height: 34px; border-radius: 8px; border: 1px solid #e2e8f0;">
                        </div>
                        <div id="tableOptionsList" style="max-height: 180px; overflow-y: auto;">
                            <?php 
                            $tables_list = [
                                'matches' => 'المباريات',
                                'teams' => 'الفرق',
                                'players' => 'اللاعبين',
                                'news' => 'الأخبار',
                                'scrape_logs' => 'سجلات السحب',
                                'api_logs' => 'سجلات API'
                            ];
                            foreach($tables_list as $val => $lab): ?>
                                <div class="table-option" data-value="<?= $val ?>" data-label="<?= $lab ?>" onclick="selectTableOption('<?= $val ?>', '<?= $lab ?>')" style="padding: 10px 15px; cursor: pointer; font-size: 0.8rem; color: #334155; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center;">
                                    <?= $lab ?>
                                    <i class="fas fa-arrow-left" style="font-size: 0.6rem; opacity: 0.2;"></i>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <input type="hidden" id="tableToClear" value="">
                </div>

                <!-- Clear Cache Card -->
                <div class="stat-card-mini" style="height: 100px; padding: 15px; cursor: pointer; background: #fff; border: 1px solid #f1f5f9;" onclick="manageData('clear_cache')">
                    <div class="mini-icon" style="background: rgba(16, 185, 129, 0.1); color: #10b981;"><i class="fas fa-sync-alt"></i></div>
                    <div class="mini-info">
                        <p style="margin: 0; font-size: 0.75rem;">مسح الكاش</p>
                        <h4 style="font-size: 0.85rem; margin-top: 5px; color: #10b981;">تنظيف الملفات</h4>
                    </div>
                    <i class="fas fa-chevron-left" style="font-size: 0.7rem; opacity: 0.3; margin-right: auto;"></i>
                </div>

                <!-- Import SQL Card -->
                <div class="stat-card-mini" style="height: 100px; padding: 15px; cursor: pointer; background: #fff; border: 1px solid #f1f5f9;" onclick="document.getElementById('sqlFileInput').click()">
                    <div class="mini-icon" style="background: rgba(139, 92, 246, 0.1); color: #8b5cf6;"><i class="fas fa-upload"></i></div>
                    <div class="mini-info" style="flex: 1;">
                        <p style="margin: 0; font-size: 0.75rem;">استيراد SQL</p>
                        <h4 id="uploadStatusLabel" style="font-size: 0.85rem; margin-top: 5px; color: #8b5cf6;">اختر ملفاً</h4>
                    </div>
                    <i class="fas fa-chevron-left" style="font-size: 0.7rem; opacity: 0.3; margin-right: auto;"></i>
                    <input type="file" id="sqlFileInput" style="display: none;" accept=".sql">
                </div>

                <!-- Full Reset Card -->
                <div class="stat-card-mini" style="height: 100px; padding: 15px; cursor: pointer; border: 1px solid rgba(239, 68, 68, 0.1); background: #fff;" onclick="manageData('clear_all')">
                    <div class="mini-icon" style="background: rgba(239, 68, 68, 0.1); color: #ef4444;"><i class="fas fa-bomb"></i></div>
                    <div class="mini-info">
                        <p style="margin: 0; font-size: 0.75rem; color: #ef4444;">تصفير شامل</p>
                        <h4 style="font-size: 0.85rem; margin-top: 5px; color: #ef4444;">حذف الكل</h4>
                    </div>
                    <i class="fas fa-chevron-left" style="font-size: 0.7rem; opacity: 0.3; margin-right: auto;"></i>
                </div>
            </div>
        </div>

        <!-- Scraper Sources Section -->
        <div class="card" style=" background: #fff; padding: 20px; border-radius: 12px; border: 1px solid #f1f5f9;">
            <div class="card-header" style="padding: 0; margin-bottom: 20px; border-bottom: none;">
                <h3 class="card-title" style="font-size: 0.9rem; color: var(--text-primary); display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-server" style="color: var(--accent);"></i> اختيار محركات جلب البيانات
                </h3>
            </div>
            <div style="display: flex; flex-direction: column; gap: 10px;">
                <?php foreach ($scraperSources as $source): ?>
                <div class="source-item-card" style="display: flex; align-items: center; padding: 15px; background: #f8fafc; border: 1px solid #f1f5f9; border-radius: 12px; gap: 15px;">
                    <div class="mini-icon" style="background: rgba(99, 102, 241, 0.1); color: #6366f1; width: 40px; height: 40px; font-size: 1rem; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                        <i class="fas fa-globe"></i>
                    </div>
                    <div class="mini-info" style="flex: 1;">
                        <h4 style="font-size: 0.85rem; margin: 0; color: var(--text-primary); font-weight: 600;"><?= htmlspecialchars($source['source_name']) ?></h4>
                        <p style="margin: 3px 0 0; font-size: 0.7rem; color: var(--text-secondary);"><?= $source['is_active'] ? 'نشط' : 'معطل' ?></p>
                    </div>
                    <label class="switch" style="transform: scale(0.7);">
                        <input type="checkbox" onchange="handleSourceToggle(this)" 
                               data-id="<?= $source['id'] ?>" 
                               data-key="<?= $source['source_key'] ?>"
                               <?= $source['is_active'] ? 'checked' : '' ?>>
                        <span class="slider round"></span>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

    </div>
</div>

<!-- Translation API Management -->
<div class="card" style="margin-top: 25px;">
    <div class="card-header">
        <h3 class="card-title">إدارة مفاتيح محركات ترجمة Gemini AI</h3>
    </div>
    
    <!-- Add New Key Form -->
    <form method="POST" style="display: flex; gap: 15px; align-items: flex-end; padding: 15px; background: rgba(255,255,255,0.02); border-radius: 12px; margin-bottom: 20px; border: 1px dashed var(--glass-border);">
        <div style="flex: 1;">
            <label style="display: block; margin-bottom: 8px; font-size: 0.8rem; color: var(--text-secondary);">اسم المفتاح (للتذكير)</label>
            <input type="text" name="key_name" class="form-control" placeholder="مثال: Gemini Key 1" required autocomplete="off" style="height: 38px; font-size: 0.85rem;">
        </div>
        <div style="flex: 2;">
            <label style="display: block; margin-bottom: 8px; font-size: 0.8rem; color: var(--text-secondary);">مفتاح الـ API</label>
            <input type="password" name="api_key" class="form-control" placeholder="أدخل مفتاح Gemini هنا..." required autocomplete="new-password" style="height: 38px; font-size: 0.85rem;">
        </div>
        <button type="submit" name="add_translation_key" class="btn-primary" style="height: 38px; padding: 0 20px;">إضافة</button>
    </form>

    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th style="text-align: right; padding-right: 20px;">الاسم</th>
                    <th>المفتاح</th>
                    <th style="text-align: center;">الحالة</th>
                    <th style="text-align: center;">الطلبات</th>
                    <th style="text-align: center;">آخر استخدام</th>
                    <th style="width: 150px; text-align: left; padding-left: 20px;">الإجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($translationKeys)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 30px; color: var(--text-secondary);">لا توجد مفاتيح مضافة حالياً</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($translationKeys as $key): ?>
                    <tr>
                        <td style="padding-right: 20px;">
                            <span style="font-weight: 600; font-size: 0.85rem;"><?= htmlspecialchars($key['provider']) ?></span>
                        </td>
                        <td style="display: flex; align-items: center; justify-content: space-between; gap: 15px; min-width: 250px;">
                            <div style="display: flex; align-items: center; gap: 10px; overflow: hidden; flex: 1;">
                                <code id="trans_key_<?= $key['id'] ?>" style="display:none;"><?= htmlspecialchars($key['api_key']) ?></code>
                                <code id="trans_display_<?= $key['id'] ?>" style="font-size: 0.8rem; letter-spacing: 0.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block; width: 100%;" title="انقر على العين لإظهار المفتاح">
                                    ******************************************
                                </code>
                            </div>
                            <div style="display: flex; gap: 5px; flex-shrink: 0;">
                                <button onclick="toggleKeyVisibility(<?= $key['id'] ?>, this)" class="btn-icon" style="font-size: 0.8rem; padding: 4px 8px;" title="إظهار/إخفاء المفتاح">
                                    <i class="fas fa-eye" style="color: var(--text-secondary);"></i>
                                </button>
                                <button onclick="copyToClipboard('trans_key_<?= $key['id'] ?>', this)" class="btn-icon" style="font-size: 0.8rem; padding: 4px 8px;" title="نسخ المفتاح">
                                    <i class="fas fa-copy" style="color: var(--text-secondary);"></i>
                                </button>
                            </div>
                        </td>
                        <td style="text-align: center;">
                            <span class="badge <?= $key['is_active'] ? 'badge-success' : 'badge-danger' ?>" style="font-size: 0.65rem;">
                                <?= $key['is_active'] ? 'نشط' : 'معطل' ?>
                            </span>
                        </td>
                        <td style="text-align: center; font-size: 0.75rem;">
                            <?= number_format($key['request_count'] ?? 0) ?>
                        </td>
                        <td style="text-align: center; font-size: 0.7rem; color: var(--text-secondary);">
                            <?= $key['last_used_at'] ? date('Y-m-d H:i', strtotime($key['last_used_at'])) : 'لم يستخدم' ?>
                        </td>
                        <td>
                            <div style="display: flex; gap: 8px; justify-content: flex-end; padding-left: 10px;">
                                <button type="button" class="btn-icon" onclick="testTranslationKey('<?= htmlspecialchars($key['api_key']) ?>', this)" title="اختبار المفتاح" style="background: rgba(139, 92, 246, 0.1); color: #8b5cf6;">
                                    <i class="fas fa-flask"></i>
                                </button>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="key_id" value="<?= $key['id'] ?>">
                                    <input type="hidden" name="toggle_translation_key" value="1">
                                    <button type="submit" class="btn-icon" title="<?= $key['is_active'] ? 'نشط حالياً' : 'تفعيل' ?>" 
                                            style="<?= $key['is_active'] ? 'background: rgba(16, 185, 129, 0.2); color: #10b981;' : 'background: rgba(255,255,255,0.05); color: var(--text-secondary);' ?>">
                                        <i class="fas <?= $key['is_active'] ? 'fa-check-circle' : 'fa-play' ?>"></i>
                                    </button>
                                </form>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('هل أنت متأكد من حذف هذا المفتاح؟')">
                                    <input type="hidden" name="key_id" value="<?= $key['id'] ?>">
                                    <input type="hidden" name="delete_translation_key" value="1">
                                    <button type="submit" class="btn-icon" style="background: rgba(239, 68, 68, 0.1); color: #ef4444;">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Users Management Section -->
<div class="card require-admin" style="margin-top: 25px;">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
        <h3 class="card-title">إدارة المستخدمين</h3>
        <button class="btn-primary" style="padding: 8px 18px; font-size: 0.8rem; border-radius: 8px;" onclick="openAddUserModal()">
            <i class="fas fa-user-plus"></i> إضافة مستخدم
        </button>
    </div>

    <div class="table-responsive">
        <table id="usersTable">
            <thead>
                <tr>
                    <th style="text-align: right; padding-right: 20px;">المستخدم</th>
                    <th style="text-align: center;">الحالة</th>
                    <th style="text-align: center;">متصل</th>
                    <th style="text-align: center;">الدور</th>
                    <th style="text-align: center;">آخر دخول</th>
                    <th style="text-align: center;">محاولات فاشلة</th>
                    <th style="width: 120px; text-align: left; padding-left: 20px;">الإجراءات</th>
                </tr>
            </thead>
            <tbody id="usersTableBody">
                <tr><td colspan="7" style="text-align: center; padding: 30px; color: var(--text-secondary);">جاري التحميل...</td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Add/Edit User Modal -->
<div id="userModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.7); z-index: 99999; justify-content: center; align-items: center; backdrop-filter: blur(5px);">
    <div style="background: #fff; width: 95%; max-width: 450px; border-radius: 16px; padding: 30px; box-shadow: 0 25px 50px rgba(0,0,0,0.3); animation: modalFadeIn 0.3s ease-out;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
            <h3 id="userModalTitle" style="font-size: 1.1rem; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-user-plus" style="color: var(--accent);"></i> إضافة مستخدم
            </h3>
            <button onclick="closeUserModal()" style="background: #f1f5f9; border: none; width: 35px; height: 35px; border-radius: 10px; cursor: pointer; color: #64748b; font-size: 1rem; transition: all 0.3s;">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <input type="hidden" id="editUserId" value="">

        <div style="display: flex; flex-direction: column; gap: 18px;">
            <div>
                <label style="display: block; margin-bottom: 6px; font-size: 0.8rem; color: var(--text-secondary); font-weight: 600;">اسم المستخدم</label>
                <input type="text" id="modalUsername" class="form-control" placeholder="أدخل اسم المستخدم..." style="height: 42px; font-size: 0.9rem; border-radius: 10px;">
            </div>
            <div>
                <label style="display: block; margin-bottom: 6px; font-size: 0.8rem; color: var(--text-secondary); font-weight: 600;">كلمة المرور <span id="pwdHint" style="font-weight: 400; color: #94a3b8;"></span></label>
                <input type="password" id="modalPassword" class="form-control" placeholder="أدخل كلمة المرور..." style="height: 42px; font-size: 0.9rem; border-radius: 10px;">
            </div>
            <div id="statusToggleContainer" style="display: flex; align-items: center; justify-content: space-between;">
                <label style="font-size: 0.8rem; color: var(--text-secondary); font-weight: 600;">حالة الحساب</label>
                <label class="switch" style="transform: scale(0.85); margin: 0;">
                    <input type="checkbox" id="modalIsActive" checked>
                    <span class="slider round"></span>
                </label>
            </div>
            <div>
                <label style="display: block; margin-bottom: 6px; font-size: 0.8rem; color: var(--text-secondary); font-weight: 600;">الدور</label>
                <select id="modalRole" class="form-control" style="height: 42px; font-size: 0.9rem; border-radius: 10px;">
                    <option value="admin">مسؤول (Admin)</option>
                    <option value="editor">محرر (Editor)</option>
                    <option value="viewer">مشاهد (Viewer)</option>
                </select>
            </div>

        </div>

        <div style="display: flex; gap: 10px; margin-top: 25px;">
            <button id="userModalSaveBtn" onclick="saveUser()" class="btn-primary" style="flex: 1; height: 42px; font-size: 0.9rem; border-radius: 10px;">
                <i class="fas fa-save"></i> حفظ
            </button>
            <button onclick="closeUserModal()" style="flex: 0.5; height: 42px; font-size: 0.9rem; border-radius: 10px; background: #f1f5f9; border: none; cursor: pointer; color: #64748b; font-weight: 600;">إلغاء</button>
        </div>
    </div>
</div>

<style>
/* Online status pulse */
.online-dot {
    width: 10px; height: 10px; border-radius: 50%; display: inline-block;
}
.online-dot.online {
    background: #10b981;
    box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2);
    animation: pulse-online 2s infinite;
}
.online-dot.offline {
    background: #cbd5e1;
}
@keyframes pulse-online {
    0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.4); }
    70% { box-shadow: 0 0 0 8px rgba(16, 185, 129, 0); }
    100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
}
@keyframes modalFadeIn {
    from { opacity: 0; transform: translateY(20px) scale(0.95); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}
#usersTable tbody tr { transition: all 0.2s; }
#usersTable tbody tr:hover { background: #f8fafc; }
</style>


<style>
.scrape-row:hover { background: transparent; }
.task-row-container:hover .mini-icon { background: var(--accent); color: white; }
.btn-icon:hover { background: #e2e8f0 !important; color: var(--accent); }

.switch { position: relative; display: inline-block; width: 46px; height: 24px; flex-shrink: 0; box-sizing: border-box; }
.switch input { opacity: 0; width: 0; height: 0; }
.slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #cbd5e1; transition: .4s; border-radius: 24px; box-sizing: border-box; }

/* Hide number input spinners */
input[type=number]::-webkit-inner-spin-button, 
input[type=number]::-webkit-outer-spin-button { 
  -webkit-appearance: none; 
  margin: 0; 
}
input[type=number] {
  -moz-appearance: textfield;
}
.slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; box-sizing: border-box; }
input:checked + .slider { background-color: var(--accent); }
input:checked + .slider:before { transform: translateX(22px); }
.switch-ai input:checked + .slider { background: linear-gradient(135deg, #6366f1 0%, #a855f7 100%); }

.table-option:hover { background: #f1f5f9; color: var(--accent) !important; }
.task-card-interactive:hover { background: #f8fafc !important; border-color: #f1f5f9 !important; transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.05); }

/* Runner Log Theme Styles */
/* Modern Runner Log Styles */
.log-entry {
    display: flex;
    gap: 8px;
    margin-bottom: 2px;
    padding: 0;
    border-radius: 0;
    transition: background 0.2s;
}
.log-entry:hover { background: rgba(255,255,255,0.03); }
#runnerWindow.light .log-entry:hover { background: rgba(0,0,0,0.02); }

.log-time {
    color: #64748b;
    font-size: 0.7rem;
    flex-shrink: 0;
    padding-top: 2px;
    opacity: 0.7;
}
.log-msg {
    color: #e2e8f0;
    font-size: 0.75rem;
    word-break: break-word;
    line-height: 1.4;
}
#runnerWindow.light .log-msg { color: #334155; }

.typing {
    display: inline;
    font-family: 'JetBrains Mono', monospace;
}

@keyframes blink { 50% { opacity: 0; } }
.cursor {
    display: inline-block;
    width: 6px;
    height: 14px;
    background: currentColor;
    margin-right: 4px;
    vertical-align: middle;
    animation: blink 0.8s infinite;
}

#runnerLog::-webkit-scrollbar { width: 4px; }
#runnerLog::-webkit-scrollbar-track { background: rgba(255,255,255,0.05); }
#runnerLog::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 10px; }

#terminalBody::-webkit-scrollbar {
    width: 6px;
}
#terminalBody::-webkit-scrollbar-track {
    background: rgba(30, 41, 59, 0.5);
}
#terminalBody::-webkit-scrollbar-thumb {
    background: #334155;
    border-radius: 3px;
}
#terminalBody::-webkit-scrollbar-thumb:hover {
    background: #475569;
}
/* Fullscreen Runner log styles */
.fullscreen-log-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: #0f172a;
    z-index: 110000;
    flex-direction: column;
    animation: fadeInOverlay 0.3s ease-out;
    box-sizing: border-box;
    direction: rtl;
}

@keyframes fadeInOverlay {
    from { opacity: 0; }
    to { opacity: 1; }
}

.fullscreen-log-overlay.active {
    display: flex;
}

.runner-log-fullscreen {
    font-size: 1rem !important;
    height: 100% !important;
    max-height: none !important;
    padding: 30px !important;
    border: none !important;
    background: #0b1120 !important;
}

.runner-log-fullscreen .log-time {
    font-size: 0.9rem !important;
}

.runner-log-fullscreen .log-msg {
    font-size: 1rem !important; /* Larger text as requested */
    line-height: 1.3 !important;
}

.fullscreen-log-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 1px solid rgba(255,255,255,0.05);
}

.fullscreen-log-title {
    color: #e2e8f0;
    font-size: 1.1rem;
    font-weight: 600;
}
</style>

<!-- Scrape Terminal (Cmd) -->
<div class="terminal-card" id="terminalSection" 
     onmouseenter="isMouseOverTerminal = true" 
     onmouseleave="isMouseOverTerminal = false; if(shouldHideTerminal) hideTerminalWithDelay();"
     style="position: fixed; top: 10px; left: 0; right: 0; margin-left: auto; margin-right: auto; width: 450px; background: #0f172a; border: 1px solid #1e293b; border-radius: 12px; overflow: hidden; display: none; z-index: 99999; box-shadow: 0 20px 50px rgba(0,0,0,0.5); transition: all 0.3s ease;">
    <div class="terminal-header" style="background: #1e293b; padding: 8px 15px; display: flex; justify-content: space-between; align-items: center; cursor: move;">
        <div style="display: flex; align-items: center; gap: 10px;">
            <div style="display: flex; gap: 5px;">
                <span style="width: 10px; height: 10px; border-radius: 50%; background: #ff5f56;"></span>
                <span style="width: 10px; height: 10px; border-radius: 50%; background: #ffbd2e;"></span>
                <span style="width: 10px; height: 10px; border-radius: 50%; background: #27c93f;"></span>
            </div>
            <span style="color: #94a3b8; font-size: 0.75rem; font-family: 'Courier New', Courier, monospace; font-weight: 600;">SCRAPE_CONSOLE v1.0</span>
        </div>
        <div style="display: flex; gap: 10px;">
            <button onclick="toggleTerminalSize()" style="background: transparent; border: none; color: #64748b; cursor: pointer; font-size: 0.7rem;"><i class="fas fa-expand-alt" id="expandIcon"></i></button>
            <button onclick="document.getElementById('terminalSection').style.display='none'" style="background: transparent; border: none; color: #64748b; cursor: pointer; font-size: 0.7rem;"><i class="fas fa-times"></i></button>
        </div>
    </div>
    <div id="terminalBody" style="height: 180px; overflow-y: auto; padding: 12px; font-family: 'Courier New', Courier, monospace; font-size: 0.75rem; line-height: 1.5; color: #e2e8f0; scroll-behavior: smooth; background: rgba(15, 23, 42, 0.95);">
        <div style="color: #64748b;">> Initializing...</div>
    </div>
</div>

<script>
function toggleTaskSettings(id) {
    const panel = document.getElementById(`settings-panel-${id}`);
    const isVisible = panel.style.display === 'block';
    
    // Close all other panels
    document.querySelectorAll('[id^="settings-panel-"]').forEach(p => p.style.display = 'none');
    
    // Toggle current panel
    if (!isVisible) {
        panel.style.display = 'block';
    }
}

function saveTaskSettings() {
    const form = document.querySelector('form');
    // Add hidden input for update_settings to ensure form is processed
    let input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'update_settings';
    input.value = '1';
    form.appendChild(input);
    form.submit();
}

// Add hover effects for settings panel buttons
document.addEventListener('DOMContentLoaded', function() {
    // Settings save button hover
    document.querySelectorAll('.settings-btn-save').forEach(btn => {
        btn.addEventListener('mouseenter', function() {
            this.style.opacity = '1';
            this.style.transform = 'scale(1.2)';
        });
        btn.addEventListener('mouseleave', function() {
            this.style.opacity = '0.8';
            this.style.transform = 'scale(1)';
        });
    });
    
    // Settings close button hover
    document.querySelectorAll('.settings-btn-close').forEach(btn => {
        btn.addEventListener('mouseenter', function() {
            this.style.opacity = '1';
            this.style.color = '#ef4444';
            this.style.transform = 'scale(1.2)';
        });
        btn.addEventListener('mouseleave', function() {
            this.style.opacity = '0.5';
            this.style.color = '';
            this.style.transform = 'scale(1)';
        });
    });
});

function toggleRunnerTheme(btn) {
    const win = document.getElementById('runnerWindow');
    const isLight = win.classList.contains('light');
    const icon = btn.querySelector('i');
    
    if (isLight) {
        win.classList.remove('light');
        icon.className = 'fas fa-sun';
        btn.title = 'الوضع النهاري';
    } else {
        win.classList.add('light');
        icon.className = 'fas fa-moon';
        btn.title = 'الوضع الليلي';
    }
}

// Close panels when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('.task-card-wrapper')) {
        document.querySelectorAll('[id^="settings-panel-"]').forEach(p => p.style.display = 'none');
    }
});





// 3. Auto Scraper Runner
function runAutoScraper() {
    fetch('../api/auto_scraper.php')
        .then(response => response.json())
        .then(data => {
            const log = document.getElementById('runnerLog');
            if (data.results && data.results.length > 0) {
                data.results.forEach(res => {
                    const time = new Date().toLocaleTimeString();
                    const div = document.createElement('div');
                    div.style.marginBottom = '6px';
                    div.style.paddingBottom = '3px';
                    div.style.borderBottom = '1px solid rgba(255,255,255,0.05)';
                    if (typeof res === 'string') {
                         div.innerHTML = `<span style="color: #64748b;">[${time}]</span> ${res}`;
                    } else {
                         div.innerHTML = `<span style="color: #64748b;">[${time}]</span> <b style="color: #3b82f6;">${res.task}</b>: <span style="color: ${res.status.includes('Error') ? '#ef4444' : '#10b981'}">${res.status}</span>`;
                    }
                    log.prepend(div);
                });
            }
        })
        .catch(err => console.error('Auto Scraper Error:', err));
}

setInterval(runAutoScraper, 30000);
runAutoScraper();

function manageData(action) {
    if (!confirm('هل أنت متأكد؟ لا يمكن التراجع عن هذه العملية.')) return;

    const formData = new FormData();
    formData.append('action', action);
    
    if (action === 'clear_table') {
        formData.append('table', document.getElementById('tableToClear').value);
    }

    fetch('ajax_data_manager.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        alert(data.message);
        if (data.status === 'success') location.reload();
    })
    .catch(err => alert('حدث خطأ أثناء العملية'));
}

function toggleTableDropdown(event) {
    if (event) event.stopPropagation();
    const dropdown = document.getElementById('tableDropdown');
    const isVisible = dropdown.style.display === 'block';
    
    // Close other panels if any
    document.querySelectorAll('[id^="settings-panel-"]').forEach(p => p.style.display = 'none');
    
    dropdown.style.display = isVisible ? 'none' : 'block';
    if (!isVisible) {
        const input = document.getElementById('tableSearchInput');
        input.focus();
        input.value = '';
        filterTableSelect();
    }
}

function filterTableSelect() {
    const filter = document.getElementById('tableSearchInput').value.toLowerCase();
    const options = document.querySelectorAll('#tableOptionsList .table-option');
    options.forEach(opt => {
        const text = opt.getAttribute('data-label').toLowerCase();
        opt.style.display = text.includes(filter) ? 'flex' : 'none';
    });
}

function selectTableOption(value, label) {
    document.getElementById('tableToClear').value = value;
    document.getElementById('selectedTableLabel').innerText = label;
    document.getElementById('tableDropdown').style.display = 'none';
    
    // Trigger the management action immediately after selection
    setTimeout(() => {
        manageData('clear_table');
    }, 100);
}

document.getElementById('sqlFileInput')?.addEventListener('change', function(e) {
    if (!e.target.files[0]) return;
    
    const status = document.getElementById('uploadStatusLabel');
    const fileName = e.target.files[0].name;
    status.innerText = fileName;
    
    if (!confirm('رفع ملف SQL سيقوم بتعديل قاعدة البيانات الحالية. هل تريد المتابعة؟')) return;

    status.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري الرفع...';
    
    const formData = new FormData();
    formData.append('action', 'upload_sql');
    formData.append('sql_file', e.target.files[0]);

    fetch('ajax_data_manager.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        status.innerHTML = `<span style="color: ${data.status === 'success' ? '#10b981' : '#ef4444'}">${data.message}</span>`;
        if (data.status === 'success') setTimeout(() => location.reload(), 2000);
    })
    .catch(err => {
        status.innerHTML = '<span style="color: #ef4444;">خطأ في الاتصال بالسيرفر</span>';
    });
});

function toggleKeyVisibility(id, btn) {
    const display = document.getElementById('trans_display_' + id);
    const fullKey = document.getElementById('trans_key_' + id).innerText;
    const icon = btn.querySelector('i');
    
    if (display.innerText.includes('*')) {
        display.innerText = fullKey;
        icon.className = 'fas fa-eye-slash';
    } else {
        display.innerText = '******************************************';
        icon.className = 'fas fa-eye';
    }
}

function copyToClipboard(elementId, btn) {
    const text = document.getElementById(elementId).innerText;
    const icon = btn.querySelector('i');

    const fallbackCopyText = (value) => {
        if (typeof document.execCommand !== 'function') {
            return false;
        }

        const textarea = document.createElement('textarea');
        textarea.value = value;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        textarea.style.pointerEvents = 'none';

        document.body.appendChild(textarea);
        textarea.focus();
        textarea.select();
        textarea.setSelectionRange(0, textarea.value.length);

        try {
            return document.execCommand('copy');
        } finally {
            document.body.removeChild(textarea);
        }
    };

    const showManualCopyPrompt = (value) => {
        window.prompt('Copy manually: Ctrl+C, Enter', value);
    };

    const writeTextToClipboard = (value) => {
        const clipboardApi = typeof navigator !== 'undefined' ? navigator.clipboard : null;
        const canUseClipboardApi = clipboardApi && typeof clipboardApi.writeText === 'function';

        if (canUseClipboardApi && window.isSecureContext) {
            return clipboardApi.writeText(value);
        }

        return new Promise((resolve, reject) => {
            try {
                if (fallbackCopyText(value)) {
                    resolve();
                } else {
                    reject(new Error('Copy command was rejected'));
                }
            } catch (error) {
                reject(error);
            }
        });
    };

    writeTextToClipboard(text).then(() => {
        const originalClass = icon ? icon.className : '';

        if (icon) {
            icon.className = 'fas fa-check';
        }

        btn.style.color = '#10b981';

        setTimeout(() => {
            if (icon) {
                icon.className = originalClass;
            }
            btn.style.color = '';
        }, 2000);
    }).catch(err => {
        console.error('Failed to copy: ', err);
        showManualCopyPrompt(text);
    });
}

function testTranslationKey(apiKey, btn) {
    const icon = btn.querySelector('i');
    const originalClass = icon.className;
    
    icon.className = 'fas fa-spinner fa-spin';
    btn.style.pointerEvents = 'none';

    const formData = new FormData();
    formData.append('api_key', apiKey);

    fetch('ajax_test_gemini.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        alert(data.message);
    })
    .catch(err => {
        alert('خطأ في الاتصال بالسيرفر');
    })
    .finally(() => {
        icon.className = originalClass;
        btn.style.pointerEvents = 'auto';
    });
}

// Close dropdown when clicking outside
window.addEventListener('click', function(e) {
    if (!e.target.closest('.custom-select-wrapper')) {
        const dropdown = document.getElementById('tableDropdown');
        if(dropdown) dropdown.style.display = 'none';
    }
});

let isTerminalExpanded = false;
let isMouseOverTerminal = false;
let shouldHideTerminal = false;

// Console Preference Logic
function saveConsolePreference(enabled) {
    localStorage.setItem('show_scrape_console', enabled ? '1' : '0');
}

function isConsoleEnabled() {
    return localStorage.getItem('show_scrape_console') !== '0'; // Default to true
}

// Initialize toggle state
document.addEventListener('DOMContentLoaded', () => {
    const toggle = document.getElementById('consoleToggle');
    if (toggle) {
        toggle.checked = isConsoleEnabled();
    }
});

function hideTerminalWithDelay() {
    if (isMouseOverTerminal) return;
    setTimeout(() => {
        if (!isMouseOverTerminal) {
            const term = document.getElementById('terminalSection');
            if(term) {
                term.style.setProperty('display', 'none', 'important');
                term.style.setProperty('visibility', 'hidden', 'important');
                term.style.setProperty('opacity', '0', 'important');
            }
            shouldHideTerminal = false;
        }
    }, 100);
}

let smartRunnerTimeout = null;
let countdownInterval = null;
let runnerWakeAt = null;
let runnerServerTimeDiff = 0; // Drift between server and client clocks
let smartRunnerAbort = null; // AbortController for in-flight fetch

let smartRunnerClientId = localStorage.getItem('smart_runner_client_id');
if (!smartRunnerClientId) {
    smartRunnerClientId = Math.random().toString(36).substr(2, 9) + '_' + Date.now();
    localStorage.setItem('smart_runner_client_id', smartRunnerClientId);
}

function toggleSmartRunner(enabled, updateServer = true) {
    const statusEl = document.getElementById('smartRunnerStatus');
    const runnerLog = document.getElementById('runnerLog');
    
    if (enabled) {
        statusEl.innerHTML = '<span class="status-dot" style="width: 8px; height: 8px; border-radius: 50%; background: #10b981; box-shadow: 0 0 10px #10b981;"></span> جاري العمل ....';
        if (runnerLog && updateServer) runnerLog.innerHTML = '<div style="color: #64748b;">> جاري بدء المشغل الذكي (V8 Event-Driven)...</div>';
        
        // FRESH START: Reset counters (user manually clicked toggle)
        currentRunnerIndex = 0;
        currentRunnerStep = 0;
        localStorage.setItem('smart_runner_index', 0);
        localStorage.setItem('smart_runner_step', 0);
        localStorage.setItem('smart_runner_enabled', '1');

        runSmartRunner();
    } else {
        // IMMEDIATELY mark as disabled so in-flight fetches won't overwrite
        localStorage.setItem('smart_runner_enabled', '0');

        // Abort any in-flight Smart Runner fetch
        if (smartRunnerAbort) { smartRunnerAbort.abort(); smartRunnerAbort = null; }

        statusEl.innerHTML = '<span class="status-dot" style="width: 8px; height: 8px; border-radius: 50%; background: #cbd5e1;"></span> متوقف';
        if (smartRunnerTimeout) clearTimeout(smartRunnerTimeout);
        if (countdownInterval) clearInterval(countdownInterval);
        smartRunnerTimeout = null;
        countdownInterval = null;
        runnerWakeAt = null;
        if (runnerLog && updateServer) runnerLog.innerHTML = '<div style="color: #64748b;">> تم إيقاف المشغل الذكي.</div>';
        else if (runnerLog) runnerLog.innerHTML = '<div style="color: #64748b;">> تم إيقاف المشغل الذكي (من خلال مستخدم آخر أو جهاز آخر).</div>';
    }

    if (updateServer) {
        // Save state to server (shared across all users)
        const formData = new FormData();
        formData.append('action', 'toggle_smart_runner');
        formData.append('enabled', enabled ? '1' : '0');

        fetch('ajax_data_manager.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'error') {
                console.error('Server failed to save Smart Runner state:', data.message);
                // If it failed, we show the message and revert the toggle if it's the official toggle
                const toggle = document.getElementById('smartRunnerToggle');
                if (toggle && updateServer) {
                    toggle.checked = !enabled;
                    localStorage.setItem('smart_runner_enabled', !enabled ? '1' : '0');
                    alert('خطأ في الاتصال بالخادم: ' + data.message);
                }
            }
        }).catch(err => {
            console.error('Network error saving Smart Runner state:', err);
        });
    }
}


let currentRunnerIndex = parseInt(localStorage.getItem('smart_runner_index')) || 0;
let currentRunnerStep = parseInt(localStorage.getItem('smart_runner_step')) || 0;

// ═══════════════════════════════════════════════════════════
// Live Countdown Timer - updates every second
// ═══════════════════════════════════════════════════════════
function startCountdown(wakeAt, serverTime, nearestMatch) {
    if (countdownInterval) clearInterval(countdownInterval);
    
    // Calculate clock drift between server and client
    runnerServerTimeDiff = Math.floor(Date.now() / 1000) - serverTime;
    runnerWakeAt = wakeAt;
    
    countdownInterval = setInterval(() => {
        if (!runnerWakeAt) { clearInterval(countdownInterval); return; }
        
        const nowServer = Math.floor(Date.now() / 1000) - runnerServerTimeDiff;
        const remaining = runnerWakeAt - nowServer;
        
        const statusEl = document.getElementById('smartRunnerStatus');
        if (!statusEl) return;
        
        if (remaining <= 0) {
            // Time to wake up! Stop countdown and trigger immediate check
            clearInterval(countdownInterval);
            countdownInterval = null;
            runnerWakeAt = null;
            statusEl.innerHTML = '<span class="status-dot" style="width: 8px; height: 8px; border-radius: 50%; background: #10b981; box-shadow: 0 0 10px #10b981; animation: pulse 1s infinite;"></span> ⚡ جاري التنشيط...';
            return;
        }
        
        const hours = Math.floor(remaining / 3600);
        const mins = Math.floor((remaining % 3600) / 60);
        const secs = remaining % 60;
        
        let timeStr = '';
        if (hours > 0) timeStr = `${hours}:${String(mins).padStart(2,'0')}:${String(secs).padStart(2,'0')}`;
        else timeStr = `${String(mins).padStart(2,'0')}:${String(secs).padStart(2,'0')}`;
        
        let matchInfo = nearestMatch ? ` | ${nearestMatch.name}` : '';
        let dotColor = remaining <= 120 ? '#f59e0b' : '#8b5cf6';
        let urgencyText = remaining <= 120 ? '🔥' : '👁️';
        
        statusEl.innerHTML = `<span class="status-dot" style="width: 8px; height: 8px; border-radius: 50%; background: ${dotColor}; box-shadow: 0 0 10px ${dotColor};"></span> ${urgencyText} مراقبة [${timeStr}]${matchInfo}`;
    }, 1000);
}

// ═══════════════════════════════════════════════════════════
// Smart Runner - Event-Driven V8
// ═══════════════════════════════════════════════════════════
function runSmartRunner() {
    if (localStorage.getItem('smart_runner_enabled') !== '1') return;

    // Create new AbortController for this request
    if (smartRunnerAbort) smartRunnerAbort.abort();
    smartRunnerAbort = new AbortController();

    fetch(`smart_runner.php?action=run&match_index=${currentRunnerIndex}&step_index=${currentRunnerStep}&client_id=${smartRunnerClientId}`, { 
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        signal: smartRunnerAbort.signal 
    })
        .then(response => response.json())
        .then(data => {
            // Re-check: if runner was disabled while fetch was in-flight, do nothing
            if (localStorage.getItem('smart_runner_enabled') !== '1') return;

            if (data.status === 'success') {
                const statusEl = document.getElementById('smartRunnerStatus');
                const runnerLog = document.getElementById('runnerLog');
                
                currentRunnerIndex = (data.next_index !== undefined) ? data.next_index : 0;
                currentRunnerStep = (data.next_step !== undefined) ? data.next_step : 0;
                
                localStorage.setItem('smart_runner_index', currentRunnerIndex);
                localStorage.setItem('smart_runner_step', currentRunnerStep);

                // ═══════════════════════════════════════════════════
                // Mode-based status display
                // ═══════════════════════════════════════════════════
                if (statusEl) {
                    let modeText = 'التحديث المباشر';
                    let dotColor = '#10b981';
                    let shadow = '0 0 10px #10b981';

                    if (data.mode === 'monitoring') {
                        modeText = 'وضع المراقبة';
                        dotColor = '#8b5cf6';
                        shadow = '0 0 10px #8b5cf6';
                        
                        // Start live countdown if we have wake_at
                        if (data.wake_at && data.server_time) {
                            startCountdown(data.wake_at, data.server_time, data.nearest_match || null);
                        }
                    } else if (data.mode === 'sleep') {
                        modeText = '😴 وضع الخمول';
                        dotColor = '#64748b';
                        shadow = 'none';
                        if (countdownInterval) { clearInterval(countdownInterval); countdownInterval = null; }
                    } else if (data.mode === 'hero_sleep') {
                        modeText = '🦸 وضع البطل (سكون)';
                        dotColor = '#f59e0b';
                        shadow = '0 0 10px #f59e0b';
                        if (countdownInterval) { clearInterval(countdownInterval); countdownInterval = null; }
                    } else if (data.mode === 'cooldown') {
                        modeText = '⏳ استراحة قصيرة...';
                        dotColor = '#3b82f6';
                        shadow = '0 0 10px #3b82f6';
                        if (countdownInterval) { clearInterval(countdownInterval); countdownInterval = null; }
                    } else {
                        // Active mode: stop countdown if running
                        if (countdownInterval) { clearInterval(countdownInterval); countdownInterval = null; }
                    }

                    // Only update status if countdown isn't running 
                    // (countdown updates its own status every second)
                    if (!countdownInterval) {
                        statusEl.innerHTML = `<span class="status-dot" style="width: 8px; height: 8px; border-radius: 50%; background: ${dotColor}; box-shadow: ${shadow};"></span> ${modeText}`;
                    }
                }

                // ═══════════════════════════════════════════════════
                // Log display (Modern Typewriter Effect)
                // ═══════════════════════════════════════════════════
                if (data.logs && runnerLog) {
                    const processLogs = async () => {
                        for (const log of data.logs) {
                            await typeLog(log, runnerLog);
                        }
                    };
                    processLogs();
                }
                
                // ═══════════════════════════════════════════════════
                // Schedule next check (micro-interval from server)
                // ═══════════════════════════════════════════════════
                let nextInterval = (data.next_interval !== undefined) ? data.next_interval : 0;
                smartRunnerTimeout = setTimeout(runSmartRunner, nextInterval);
            }
        })
        .catch(err => {
            console.error('Smart Runner Error:', err);
            
            if (localStorage.getItem('smart_runner_enabled') === '1') {
                smartRunnerTimeout = setTimeout(runSmartRunner, 10000);
            }
        });
}

// ═══════════════════════════════════════════════════════════
// Modern Typewriter Effect for Logs
// ═══════════════════════════════════════════════════════════
function typeLog(log, container) {
    return new Promise(resolve => {
        const entry = document.createElement('div');
        entry.className = 'log-entry';
        
        let color = '#60a5fa'; // info
        if (log.type === 'error') color = '#f87171';
        else if (log.type === 'success') color = '#10b981';
        else if (log.type === 'filter') color = '#fbbf24';
        else if (log.type === 'warning') color = '#fb923c';
        else if (log.type === 'hero') color = '#a855f7'; // Purple for Hero Mode
        
        const timestamp = log.created_at.split(' ')[1];
        entry.innerHTML = `
            <div class="log-time">[${timestamp}]</div>
            <div class="log-msg" style="color: ${color}">
                <span class="typing"></span><span class="cursor"></span>
            </div>
        `;
        
        container.appendChild(entry);
        
        // Auto-scroll as we type
        const autoScroll = () => { container.scrollTop = container.scrollHeight; };
        autoScroll();

        const typingSpan = entry.querySelector('.typing');
        const cursor = entry.querySelector('.cursor');
        const text = log.message;
        
        const finishLog = () => {
            typingSpan.textContent = text;
            cursor.remove();
            while (container.childNodes.length > 100) {
                container.removeChild(container.firstChild);
            }
            autoScroll();
            resolve();
        };

        if (document.hidden) {
            finishLog();
            return;
        }

        let i = 0;
        
        // Fast typewriter (3-10ms per char)
        function type() {
            if (document.hidden) {
                finishLog();
                return;
            }

            if (i < text.length) {
                typingSpan.textContent += text.charAt(i);
                i++;
                if (i % 3 === 0) autoScroll(); // Scroll every 3 chars to be smooth
                setTimeout(type, 8);
            } else {
                finishLog();
            }
        }
        type();
    });
}


// ═══════════════════════════════════════════════════════════
// Resume Smart Runner: continues from where it left off (page reload)
// Unlike toggleSmartRunner(true), this does NOT reset the index
// ═══════════════════════════════════════════════════════════
function resumeSmartRunner() {
    const statusEl = document.getElementById('smartRunnerStatus');
    const runnerLog = document.getElementById('runnerLog');
    
    // Read saved position from localStorage (survives page reload)
    currentRunnerIndex = parseInt(localStorage.getItem('smart_runner_index')) || 0;
    currentRunnerStep = parseInt(localStorage.getItem('smart_runner_step')) || 0;
    
    if (statusEl) {
        statusEl.innerHTML = '<span class="status-dot" style="width: 8px; height: 8px; border-radius: 50%; background: #10b981; box-shadow: 0 0 10px #10b981;"></span> جاري الاستئناف ....';
    }
    if (runnerLog) {
        runnerLog.innerHTML = `<div style="color: #64748b;">> استئناف المشغل الذكي (المباراة ${currentRunnerIndex + 1}، الخطوة ${currentRunnerStep})...</div>`;
    }
    
    localStorage.setItem('smart_runner_enabled', '1');
    runSmartRunner();
}

// Initialize Smart Runner state from SERVER (shared state, not localStorage)
document.addEventListener('DOMContentLoaded', () => {
    const smartToggle = document.getElementById('smartRunnerToggle'); // Correct ID for toggle
    const serverEnabled = window.SMART_RUNNER_SERVER_STATE === true;

    if (smartToggle && serverEnabled) {
        // Editor/Admin: server says enabled → RESUME from saved position
        smartToggle.checked = true;
        resumeSmartRunner();
    } else if (!smartToggle && serverEnabled) {
        // Viewer: no toggle, but server says admin enabled it → RESUME
        resumeSmartRunner();
    }

    // Auto-sync Smart Runner state every 5 seconds (to detect remote changes)
    setInterval(async () => {
        try {
            // Check state from database instead of JSON file
            const res = await fetch('ajax_data_manager.php?action=get_runner_state&t=' + new Date().getTime(), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await res.json();
            const toggle = document.getElementById('smartRunnerToggle');
            
            // For admins (who have the toggle)
            if (toggle && data.enabled !== undefined && toggle.checked !== data.enabled) {
                toggle.checked = data.enabled;
                toggleSmartRunner(data.enabled, false); // false = don't broadcast to server again
            } 
            // For viewers (who don't have the toggle)
            else if (!toggle && data.enabled !== undefined) {
                const currentlyEnabled = localStorage.getItem('smart_runner_enabled') === '1';
                if (data.enabled !== currentlyEnabled) {
                    if (data.enabled) {
                        resumeSmartRunner();
                    } else {
                        localStorage.setItem('smart_runner_enabled', '0');
                        if (smartRunnerTimeout) clearTimeout(smartRunnerTimeout);
                        if (countdownInterval) clearInterval(countdownInterval);
                        const statusEl = document.getElementById('smartRunnerStatus');
                        if (statusEl) statusEl.innerHTML = '<span class="status-dot" style="width: 8px; height: 8px; border-radius: 50%; background: #cbd5e1;"></span> متوقف';
                    }
                }
            }
        } catch (e) {
            // Ignore network errors or missing file
        }
    }, 5000);
});

function toggleTerminalSize() {
    const section = document.getElementById('terminalSection');
    const body = document.getElementById('terminalBody');
    const icon = document.getElementById('expandIcon');
    
    if (isTerminalExpanded) {
        section.style.width = '450px';
        body.style.height = '180px';
        icon.className = 'fas fa-expand-alt';
    } else {
        section.style.width = '600px';
        body.style.height = '400px';
        icon.className = 'fas fa-compress-alt';
    }
    isTerminalExpanded = !isTerminalExpanded;
}

const apiEndpoints = {
    matches: '../api/scrape.php',
    tomorrow: '../api/scrape_tomorrow.php',
    next10: '../api/scrape_next_10_days.php',
    summaries: '../api/scrape_summaries.php',
    lineups: '../api/scrape_lineups.php',
    live: '../api/scrape_live.php',
    events: '../api/scrape_events.php',
    standings: '../api/scrape_standings.php',
    stats: '../api/scrape_statistics.php',
    previous_matches: '../api/scrape_previous_matches.php',
    news: '../api/scrape_news.php',
    fifa_rankings: '../api/scrape_fifa_rankings.php'
};

const cardIds = {
    matches: 'matchesCard',
    tomorrow: 'tomorrowCard',
    next10: 'next10Card',
    summaries: 'summariesCard',
    lineups: 'lineupsCard',
    live: 'liveCard',
    events: 'eventsCard',
    standings: 'standingsCard',
    stats: 'statsCard',
    previous_matches: 'previous_matchesCard',
    news: 'newsCard',
    fifa_rankings: 'fifa_rankingsCard'
};

function setCardStatus(cardId, status, isError = false) {
    const card = document.getElementById(cardId);
    if (!card) return;
    const statusEl = card.querySelector('.scrape-status');
    if (statusEl) {
        statusEl.textContent = status;
        statusEl.style.color = isError ? '#ef4444' : 'var(--text-secondary)';
    }
}

function setCardLoading(cardId, isLoading) {
    const card = document.getElementById(cardId);
    if (!card) return;
    const btn = card.querySelector('button');
    if (btn) {
        btn.disabled = isLoading;
        btn.innerHTML = isLoading ? '<i class="fas fa-spinner fa-spin"></i> جاري...' : 'تحديث';
    }
}

let logPollingInterval = null;

function startLogPolling() {
    // Bypass isConsoleEnabled to ensure it always shows when manually clicking refresh for now
    console.log("startLogPolling called forced");
    
    const terminal = document.getElementById('terminalSection');
    const terminalBody = document.getElementById('terminalBody');
    
    if (terminal) {
        terminal.style.setProperty('display', 'block', 'important');
        terminal.style.setProperty('visibility', 'visible', 'important');
        terminal.style.setProperty('opacity', '1', 'important');
        terminal.style.setProperty('top', '20px', 'important');
        terminal.style.setProperty('z-index', '9999999', 'important');
    }
    
    if (terminalBody) {
        terminalBody.innerHTML = '<div style="color: #64748b;">> Starting manual scrape process...</div>';
    }
    
    shouldHideTerminal = false; // Reset hide flag
    if (logPollingInterval) clearInterval(logPollingInterval);
    
    logPollingInterval = setInterval(() => {
        fetch('ajax_scrape_logs.php', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(response => response.json())
            .then(result => {
                if (result.status === 'success' && result.data.length > 0) {
                    if (terminalBody) terminalBody.innerHTML = '';
                    
                    result.data.forEach(log => {
                        const div = document.createElement('div');
                        let color = '#e2e8f0';
                        if (log.type === 'error') color = '#f87171';
                        else if (log.type === 'success') color = '#4ade80';
                        else if (log.type === 'filter') color = '#fbbf24';
                        else if (log.type === 'info') color = '#60a5fa';
                        
                        div.style.color = color;
                        div.innerHTML = `<span style="color: #64748b;">[${log.created_at.split(' ')[1]}]</span> > ${log.message}`;
                        
                        if (terminalBody) terminalBody.appendChild(div);
                    });
                    
                    if (terminalBody) terminalBody.scrollTop = terminalBody.scrollHeight;
                }
            });
    }, 1000);
}

function stopLogPolling() {
    if (logPollingInterval) {
        // Short delay to let the user see the "Success" message
        setTimeout(() => {
            clearInterval(logPollingInterval);
            logPollingInterval = null;
            shouldHideTerminal = true;
            hideTerminalWithDelay();
        }, 1500);
    }
}

function startScraping(type) {
    const cardId = cardIds[type];
    let apiUrl = apiEndpoints[type];
    
    if (type === 'matches') {
        const today = new Date().toISOString().split('T')[0];
        apiUrl += `?date=${today}`;
    }
    
    setCardLoading(cardId, true);
    setCardStatus(cardId, 'جاري التحديث...');
    startLogPolling();
    
    fetch(apiUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(response => response.json())
        .then(data => {
            setCardLoading(cardId, false);
            if (data.status === 'success' || data.success) {
                setCardStatus(cardId, 'تم التحديث بنجاح');
                setTimeout(stopLogPolling, 500);
            } else {
                setCardStatus(cardId, data.message || 'فشل التحديث', true);
                stopLogPolling();
            }
        })
        .catch(error => {
            setCardLoading(cardId, false);
            setCardStatus(cardId, 'خطأ في الاتصال', true);
            stopLogPolling();
        });
}

async function startScrapingTomorrowFull() {
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    const tomorrowStr = tomorrow.toISOString().split('T')[0];
    
    const cardId = 'tomorrowCard';
    const btn = document.querySelector(`#${cardId} button[onclick="startScrapingTomorrowFull()"]`);
    
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري...';
    }
    
    setCardStatus(cardId, 'جاري جلب قائمة المباريات...');
    startLogPolling();
    
    try {
        // 1. Get Tomorrow's matches list
        await fetch('../api/scrape_tomorrow.php', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        
        const steps = [
            { name: 'التشكيلات', url: '../api/scrape_lineups.php' },
            { name: 'التفاصيل', url: '../api/scrape_summaries.php' },
            { name: 'جداول الترتيب', url: '../api/scrape_standings.php' },
            { name: 'المواجهات السابقة', url: '../api/scrape_previous_matches.php' }
        ];
        
        for (const step of steps) {
            setCardStatus(cardId, `جاري جلب ${step.name}...`);
            await fetch(`${step.url}?date=${tomorrowStr}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        }
        
        setCardStatus(cardId, 'اكتمل جلب كل البيانات بنجاح');
    } catch (error) {
        console.error('Full scrape error:', error);
        setCardStatus(cardId, 'فشل الجلب الكامل', true);
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = 'جلب الكل';
        }
        stopLogPolling();
    }
}
</script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const taskSwitches = document.querySelectorAll('.task-switch');
    const dependentTasks = ['lineups', 'previous_matches', 'standings', 'summaries'];
    
    taskSwitches.forEach(sw => {
        sw.addEventListener('change', function() {
            const key = this.getAttribute('data-task-key');
            const matchesTodaySwitch = document.querySelector('.task-switch[data-task-key="matches_today"]');
            
            // Case 1: Toggling a dependent task
            if (dependentTasks.includes(key)) {
                if (this.checked && matchesTodaySwitch && !matchesTodaySwitch.checked) {
                    // Prevent enabling if matches_today is off
                    this.checked = false;
                    alert('عذراً، يجب تفعيل "مباريات اليوم" أولاً لتتمكن من تفعيل هذه الخاصية.');
                    return; // Don't trigger auto-save if rejected
                }
            }
            
            // Case 2: Toggling matches_today
            if (key === 'matches_today') {
                if (!this.checked) {
                    // If turning off matches_today, turn off all dependents
                    dependentTasks.forEach(depKey => {
                        const depSwitch = document.querySelector(`.task-switch[data-task-key="${depKey}"]`);
                        if (depSwitch && depSwitch.checked) {
                            depSwitch.checked = false;
                            // The handleTaskToggle for these will be handled by the server logic 
                            // for 'matches_today' deactivation, but we uncheck them visually here.
                        }
                    });
                }
            }
        });
    });
});

function handleTaskToggle(checkbox) {
    const taskId = checkbox.getAttribute('data-id');
    const isActive = checkbox.checked ? 1 : 0;
    const taskKey = checkbox.getAttribute('data-task-key');
    
    // Optional: show a small saving indicator
    console.log(`Saving task ${taskKey} (${taskId}): ${isActive}`);
    
    const formData = new FormData();
    formData.append('action', 'toggle_task');
    formData.append('task_id', taskId);
    formData.append('is_active', isActive);
    
    fetch('ajax_data_manager.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.status !== 'success') {
            alert('خطأ في حفظ الحالة: ' + data.message);
            checkbox.checked = !checkbox.checked; // Revert
        } else if (taskKey === 'matches_today' && isActive === 0) {
            // No need for reload, JS already unchecked them visually
        }
    })
    .catch(err => {
        console.error('Error saving task status:', err);
        checkbox.checked = !checkbox.checked; // Revert
    });
}

function handleSourceToggle(checkbox) {
    const sourceId = checkbox.getAttribute('data-id');
    const isActive = checkbox.checked ? 1 : 0;
    const sourceKey = checkbox.getAttribute('data-key');
    
    const formData = new FormData();
    formData.append('action', 'toggle_source');
    formData.append('source_id', sourceId);
    formData.append('is_active', isActive);
    
    fetch('ajax_data_manager.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.status !== 'success') {
            alert('خطأ في حفظ الحالة: ' + data.message);
            checkbox.checked = !checkbox.checked; // Revert
        } else {
            // Update the text label near the switch
            const statusLabel = checkbox.closest('.source-item-card').querySelector('p');
            if (statusLabel) {
                statusLabel.textContent = isActive ? 'نشط' : 'معطل';
            }

            // If activated, uncheck all others visually
            if (isActive) {
                document.querySelectorAll('input[onchange="handleSourceToggle(this)"]').forEach(input => {
                    if (input !== checkbox && input.checked) {
                        input.checked = false;
                        const label = input.closest('.source-item-card').querySelector('p');
                        if (label) label.textContent = 'معطل';
                    }
                });
            }
        }
    })
    .catch(err => {
        console.error('Error saving source status:', err);
        checkbox.checked = !checkbox.checked; // Revert
    });
}

function updateInterval(taskId) {
    const container = document.querySelector(`#interval_${taskId}`).nextElementSibling;
    const inputs = container.querySelectorAll('input');
    const days = parseInt(inputs[0].value) || 0;
    const hours = parseInt(inputs[1].value) || 0;
    const minutes = parseInt(inputs[2].value) || 0;
    
    const totalSeconds = (days * 86400) + (hours * 3600) + (minutes * 60);
    document.getElementById(`interval_${taskId}`).value = totalSeconds;
}

// ===== USER MANAGEMENT =====
function loadUsers() {
    fetch('ajax_users.php?action=list', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(data => {
            if (data.status !== 'success') return;
            const tbody = document.getElementById('usersTableBody');
            if (!data.data.length) {
                tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:30px; color:var(--text-secondary);">لا يوجد مستخدمين</td></tr>';
                return;
            }

            const roleLabels = { admin: 'مسؤول', editor: 'محرر', viewer: 'مشاهد' };
            const roleColors = { admin: '#ef4444', editor: '#f59e0b', viewer: '#3b82f6' };

            tbody.innerHTML = data.data.map(u => `
                <tr>
                    <td style="padding-right: 20px;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <div style="width: 36px; height: 36px; border-radius: 10px; background: rgba(59,130,246,0.1); display: flex; align-items: center; justify-content: center; color: #3b82f6; font-weight: 700; font-size: 0.85rem;">
                                ${u.username.charAt(0).toUpperCase()}
                            </div>
                            <div>
                                <span style="font-weight: 600; font-size: 0.85rem;">${u.username}</span>
                                <p style="margin: 2px 0 0; font-size: 0.65rem; color: var(--text-secondary);">مُنشَأ: ${u.created_at ? new Date(u.created_at).toLocaleDateString('ar') : '---'}</p>
                            </div>
                        </div>
                    </td>
                    <td style="text-align: center;">
                        <span class="badge ${u.is_active ? 'badge-success' : 'badge-danger'}" style="font-size: 0.65rem;">
                            ${u.is_active ? 'مفعل' : 'معطل'}
                        </span>
                    </td>
                    <td style="text-align: center;">
                        <span class="online-dot ${u.is_online ? 'online' : 'offline'}" title="${u.is_online ? 'متصل الآن' : 'غير متصل'}"></span>
                        <span style="font-size: 0.65rem; color: ${u.is_online ? '#10b981' : '#94a3b8'}; margin-right: 5px;">${u.is_online ? 'متصل' : 'غير متصل'}</span>
                    </td>
                    <td style="text-align: center;">
                        <span style="font-size: 0.7rem; font-weight: 600; color: ${roleColors[u.role] || '#64748b'}; background: ${roleColors[u.role] || '#64748b'}15; padding: 3px 10px; border-radius: 6px;">
                            ${roleLabels[u.role] || u.role}
                        </span>
                    </td>
                    <td style="text-align: center; font-size: 0.7rem; color: var(--text-secondary);">
                        ${u.last_login ? new Date(u.last_login).toLocaleString('ar') : 'لم يسجل دخول'}
                    </td>
                    <td style="text-align: center;">
                        <span style="font-size: 0.8rem; font-weight: 600; color: ${u.login_attempts > 0 ? '#ef4444' : '#10b981'};">
                            ${u.login_attempts}
                        </span>
                    </td>
                    <td>
                        <div style="display: flex; gap: 6px; justify-content: flex-end; padding-left: 10px;">
                            <button onclick="openEditUserModal(${u.id}, '${u.username}', '${u.role}', ${u.is_active})" class="btn-icon" title="تعديل" style="background: rgba(59,130,246,0.1); color: #3b82f6; width: 32px; height: 32px; border: none; border-radius: 8px; cursor: pointer;">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button onclick="deleteUser(${u.id}, '${u.username}')" class="btn-icon" title="حذف" style="background: rgba(239,68,68,0.1); color: #ef4444; width: 32px; height: 32px; border: none; border-radius: 8px; cursor: pointer;">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </td>

                </tr>
            `).join('');
        })
        .catch(err => console.error('Error loading users:', err));
}

function openAddUserModal() {
    document.getElementById('editUserId').value = '';
    document.getElementById('modalUsername').value = '';
    document.getElementById('modalPassword').value = '';
    document.getElementById('modalRole').value = 'admin';
    document.getElementById('modalIsActive').checked = true;
    document.getElementById('statusToggleContainer').style.display = 'none'; // Hide status when adding
    document.getElementById('userModalTitle').innerHTML = '<i class="fas fa-user-plus" style="color: var(--accent);"></i> إضافة مستخدم';
    document.getElementById('pwdHint').textContent = '';
    document.getElementById('userModal').style.display = 'flex';
}

function openEditUserModal(id, username, role, isActive) {
    document.getElementById('editUserId').value = id;
    document.getElementById('modalUsername').value = username;
    document.getElementById('modalPassword').value = '';
    document.getElementById('modalRole').value = role;
    document.getElementById('modalIsActive').checked = isActive === 1 || isActive === true;
    document.getElementById('statusToggleContainer').style.display = 'flex'; // Show status when editing
    document.getElementById('userModalTitle').innerHTML = '<i class="fas fa-user-edit" style="color: #f59e0b;"></i> تعديل مستخدم';
    document.getElementById('pwdHint').textContent = '(اتركه فارغاً للإبقاء على القديم)';
    document.getElementById('userModal').style.display = 'flex';
}


function closeUserModal() {
    document.getElementById('userModal').style.display = 'none';
}

function saveUser() {
    const userId = document.getElementById('editUserId').value;
    const username = document.getElementById('modalUsername').value.trim();
    const password = document.getElementById('modalPassword').value.trim();
    const role = document.getElementById('modalRole').value;
    const isActive = document.getElementById('modalIsActive').checked ? 1 : 0;
    const isEdit = !!userId;

    if (!username) { alert('الرجاء إدخال اسم المستخدم'); return; }
    if (!isEdit && !password) { alert('الرجاء إدخال كلمة المرور'); return; }

    const formData = new FormData();
    formData.append('action', isEdit ? 'update' : 'create');
    formData.append('username', username);
    formData.append('password', password);
    formData.append('role', role);
    if (isEdit) {
        formData.append('user_id', userId);
        formData.append('is_active', isActive);
    }

    fetch('ajax_users.php', { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') {
                closeUserModal();
                loadUsers();
            } else {
                alert(data.message || 'حدث خطأ');
            }
        })
        .catch(err => alert('خطأ في الاتصال'));
}

function deleteUser(id, username) {
    if (!confirm(`هل أنت متأكد من حذف المستخدم "${username}"؟`)) return;

    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('user_id', id);

    fetch('ajax_users.php', { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') {
                loadUsers();
            } else {
                alert(data.message || 'حدث خطأ');
            }
        })
        .catch(err => alert('خطأ في الاتصال'));
}

// Load users on page load and refresh every 30s for online status
document.addEventListener('DOMContentLoaded', function() {
    loadUsers();
    setInterval(loadUsers, 30000); // Refresh every 30s to update online status
});

// Close modal on backdrop click
document.getElementById('userModal').addEventListener('click', function(e) {
    if (e.target === this) closeUserModal();
});

function toggleFullScreenLogs() {
    const overlay = document.getElementById('runnerFullscreenOverlay');
    const container = document.getElementById('fullscreenLogContainer');
    const runnerLog = document.getElementById('runnerLog');
    const originalParent = document.getElementById('runnerWindow');
    const body = document.body;

    if (!overlay.classList.contains('active')) {
        // Open
        container.appendChild(runnerLog);
        runnerLog.classList.add('runner-log-fullscreen');
        overlay.classList.add('active');
        body.style.overflow = 'hidden'; // Prevent page scroll
    } else {
        // Close
        originalParent.appendChild(runnerLog);
        runnerLog.classList.remove('runner-log-fullscreen');
        overlay.classList.remove('active');
        body.style.overflow = ''; // Restore page scroll
        
        // Ensure log is scrolled to bottom after moving back
        setTimeout(() => { runnerLog.scrollTop = runnerLog.scrollHeight; }, 100);
    }
}
</script>

<!-- Fullscreen Log Overlay -->
<div id="runnerFullscreenOverlay" class="fullscreen-log-overlay">
    <div id="fullscreenLogContainer" style="flex: 1; overflow: hidden; background: #0b1120; border-radius: 12px; border: 1px solid rgba(255,255,255,0.05); display: flex; flex-direction: column;">
        <div class="code-header" style="background: #1e293b; border-bottom: 1px solid rgba(255,255,255,0.05);">
            <div class="code-dots">
                <div class="code-dot dot-red"></div>
                <div class="code-dot dot-yellow"></div>
                <div class="code-dot dot-green"></div>
            </div>
            <div class="code-actions">
                <button type="button" class="theme-btn" onclick="toggleFullScreenLogs()" title="تصغير الشاشة" style="background: rgba(255,255,255,0.1); border: none; color: #cbd5e1; cursor: pointer; padding: 4px 12px; border-radius: 4px; font-size: 0.75rem; display: flex; align-items: center; gap: 5px;">
                    <i class="fas fa-compress"></i> تصغير
                </button>
            </div>
        </div>
        <!-- runnerLog will be moved here -->
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
