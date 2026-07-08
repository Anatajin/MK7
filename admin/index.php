<?php
require_once 'init.php';
require_once '../config.php';
require_once '../includes/Database.php';
require_once __DIR__ . '/includes/dashboard_snapshot.php';

function formatSmartCount($n) {
    if ($n >= 1000000) {
        $val = floor($n / 100000) / 10;
        return ($val == (int)$val ? (int)$val : $val) . 'M';
    }
    if ($n >= 1000) {
        $val = floor($n / 100) / 10;
        return ($val == (int)$val ? (int)$val : $val) . 'K';
    }
    return $n;
}


$db = new Database($pdo);
$stats = $db->getStats();
$todayStats = $db->getTodayStats();
$apiStats = $db->getApiUsageHistory('24h');

// Calculate matches starting within 45 minutes (Soon)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM matches WHERE match_date = CURDATE() AND match_time >= CURTIME() AND match_time <= ADDTIME(CURTIME(), '00:45:00') AND status NOT IN ('Live', 'Finished', 'Postponed', 'Cancelled')");
$stmt->execute();
$soonMatchesCount = $stmt->fetchColumn();

// Fetch next upcoming match
$stmt = $pdo->prepare("SELECT m.*, 
        t1.name as home_team, t1.logo_url as home_logo,
        t2.name as away_team, t2.logo_url as away_logo
        FROM matches m
        JOIN teams t1 ON m.home_team_id = t1.id
        JOIN teams t2 ON m.away_team_id = t2.id
        WHERE m.match_date >= ? AND m.status != 'Finished'
        ORDER BY m.match_date ASC, m.match_time ASC LIMIT 2");
$stmt->execute([date('Y-m-d')]);
$nextMatches = $stmt->fetchAll();

// Fetch FIFA Rankings
$fifaRankings = $db->getFifaRankings(10);
$allFifaRankings = $db->getFifaRankings(); // Get all for modal

// Data Distribution in MB
$distribution = [];
$tables = ['matches', 'teams', 'players', 'news', 'leagues'];
$totalSizeMB = 0;
foreach ($tables as $table) {
    $stmt = $db->pdo->prepare("SELECT ROUND(((data_length + index_length) / 1024 / 1024), 2) FROM information_schema.TABLES WHERE table_schema = ? AND table_name = ?");
    $stmt->execute([DB_NAME, $table]);
    $size = $stmt->fetchColumn();
    $distribution[$table] = $size ?: 0;
    $totalSizeMB += $distribution[$table];
}

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
    ['id' => 'news', 'name' => 'الأخبار الرياضية', 'icon' => 'fa-newspaper', 'pct' => 100]
];

// Database Growth History (Accurate Daily Growth)
$totalMatches = (int)$stats['total_matches'];
$totalTeams = (int)$stats['teams_count'];
$totalCountries = (int)$stats['countries_count'];
$totalLeagues = (int)$stats['leagues_count'];
$totalActiveCountries = (int)$pdo->query("SELECT COUNT(*) FROM countries WHERE is_active = 1")->fetchColumn();
$totalActiveLeagues = (int)$pdo->query("SELECT COUNT(*) FROM leagues WHERE is_active = 1")->fetchColumn();

// Fetch daily additions for each category
$dailyGrowth = [];
$tables = ['matches', 'teams', 'countries', 'leagues'];

foreach ($tables as $table) {
    $stmt = $pdo->prepare("SELECT DATE(created_at) as date, COUNT(*) as count FROM $table WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY DATE(created_at)");
    $stmt->execute();
    $dailyGrowth[$table] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

// Daily additions for active ones (Using the new activated_at column)
$stmt = $pdo->prepare("SELECT DATE(activated_at) as date, COUNT(*) as count FROM countries WHERE is_active = 1 AND activated_at > DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY DATE(activated_at)");
$stmt->execute();
$dailyActiveCountries = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$stmt = $pdo->prepare("SELECT DATE(activated_at) as date, COUNT(*) as count FROM leagues WHERE is_active = 1 AND activated_at > DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY DATE(activated_at)");
$stmt->execute();
$dailyActiveLeagues = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$dbHistory = [];
$daysAr = ['Sun' => 'الأحد', 'Mon' => 'الاثنين', 'Tue' => 'الثلاثاء', 'Wed' => 'الأربعاء', 'Thu' => 'الخميس', 'Fri' => 'الجمعة', 'Sat' => 'السبت'];

// Start from today and go backwards
$currM = $totalMatches;
$currT = $totalTeams;
$currC = $totalCountries;
$currL = $totalLeagues;
$currAC = $totalActiveCountries;
$currAL = $totalActiveLeagues;

for ($i = 0; $i < 7; $i++) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $dayEn = date('D', strtotime($date));
    $label = $daysAr[$dayEn] ?? $dayEn;
    
    $dbHistory[] = [
        'label' => $label,
        'matches' => $currM,
        'teams' => $currT,
        'countries' => $currC,
        'leagues' => $currL,
        'active_countries' => $currAC,
        'active_leagues' => $currAL
    ];
    
    $currM -= ($dailyGrowth['matches'][$date] ?? 0);
    $currT -= ($dailyGrowth['teams'][$date] ?? 0);
    $currC -= ($dailyGrowth['countries'][$date] ?? 0);
    $currL -= ($dailyGrowth['leagues'][$date] ?? 0);
    $currAC -= ($dailyActiveCountries[$date] ?? 0);
    $currAL -= ($dailyActiveLeagues[$date] ?? 0);
}
$dbHistory = array_reverse($dbHistory);

$countryStats = $db->getApiCountryStats();
$top5TotalRequests = 0;
foreach (array_slice($countryStats, 0, 5) as $stat) {
    $top5TotalRequests += $stat['count'];
}

$pageTitle = 'لوحة التحكم';
?>
<style>
.modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(15, 23, 42, 0.8);
    z-index: 9999;
    justify-content: center;
    align-items: center;
    backdrop-filter: blur(8px);
    padding: 20px;
}

.globe-loader {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 50px;
    height: 50px;
    border: 3px dashed #10b981;
    border-radius: 50%;
    animation: rotate-loader 2s linear infinite;
    z-index: 5;
    transition: opacity 0.3s ease;
}

@keyframes rotate-loader {
    from { transform: translate(-50%, -50%) rotate(0deg); }
    to { transform: translate(-50%, -50%) rotate(360deg); }
}
.modal-content {
    background: #fff;
    width: 95%;
    max-width: 1200px;
    height: 85vh;
    border-radius: 20px;
    position: relative;
    padding: 30px;
    display: flex;
    flex-direction: column;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
    animation: modalFadeIn 0.3s ease-out;
}
@keyframes modalFadeIn {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}
.modal-close {
    position: absolute;
    top: 20px;
    left: 20px;
    width: 40px;
    height: 40px;
    background: #f1f5f9;
    color: #64748b;
    border-radius: 12px;
    display: flex;
    justify-content: center;
    align-items: center;
    cursor: pointer;
    transition: all 0.3s;
}
.modal-close:hover {
    background: #ef4444;
    color: #fff;
    transform: rotate(90deg);
}
</style>
<?php
require_once 'includes/header.php';
?>

<div class="dashboard-grid">
    <!-- Left Column -->
    <div class="dashboard-column">
        <!-- API Usage Chart -->
        <div class="card">
            <div class="card-header" style="flex-wrap: wrap; gap: 10px;">
                <h3 class="card-title">ضغط طلبات API</h3>
                <div class="chart-filters" style="display: flex; gap: 5px;">
                    <button class="filter-btn active" style="padding: 5px 12px; font-size: 0.8rem;" onclick="updateApiChart('24h', this)">24 ساعة</button>
                    <button class="filter-btn" style="padding: 5px 12px; font-size: 0.8rem;" onclick="updateApiChart('7d', this)">7 أيام</button>
                    <button class="filter-btn" style="padding: 5px 12px; font-size: 0.8rem;" onclick="updateApiChart('1m', this)">شهر</button>
                    <button class="filter-btn" style="padding: 5px 12px; font-size: 0.8rem;" onclick="updateApiChart('6m', this)">6 أشهر</button>
                    <button class="filter-btn" style="padding: 5px 12px; font-size: 0.8rem;" onclick="updateApiChart('1y', this)">سنة</button>
                </div>
            </div>
            <div style="height: 300px; position: relative;">
                <div id="apiUsageChart" style="width: 100%; height: 100%;"></div>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 25px;">
            <!-- Next Game Card -->
            <div class="card next-game-card">
                <div class="card-header">
                    <h3 class="card-title">المباريات القادمة</h3>
                    <a href="matches.php" class="view-all">الجدول الزمني</a>
                </div>
                <div id="nextMatchesList">
                <?php if (!empty($nextMatches)): ?>
                    <?php foreach ($nextMatches as $index => $match): ?>
                        <p class="competition-info"><?= htmlspecialchars($match['league_name']) ?> • <?= date('D d M Y', strtotime($match['match_date'])) ?>, <?= !empty($match['details_match_time']) ? htmlspecialchars($match['details_match_time']) : date('H:i', strtotime($match['match_time'])) ?></p>
                        <div class="teams-vs">
                            <div class="team-display">
                                <img src="<?= htmlspecialchars($match['home_logo']) ?>" class="team-logo-large" alt="Home">
                                <span class="team-name-text"><?= htmlspecialchars($match['home_team']) ?></span>
                            </div>
                            <span class="vs-badge">VS</span>
                            <div class="team-display">
                                <img src="<?= htmlspecialchars($match['away_logo']) ?>" class="team-logo-large" alt="Away">
                                <span class="team-name-text"><?= htmlspecialchars($match['away_team']) ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="padding: 40px; text-align: center;">
                        <i class="fas fa-calendar-times" style="font-size: 3rem; opacity: 0.1; margin-bottom: 15px; display: block;"></i>
                        <p style="color: var(--text-secondary);">لا توجد مباريات قادمة</p>
                    </div>
                <?php endif; ?>
                </div>
            </div>


            <!-- Games Statistic -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">إحصائيات اليوم</h3>
                    <a href="matches.php" class="view-all">عرض الكل</a>
                </div>
                <div class="chart-container" style="position: relative; height: 300px; margin: 20px 0;">
                    <div id="todayStatsChart" style="width: 100%; height: 100%;"></div>
                </div>
            </div>
        </div>

        <!-- FIFA Rankings Card -->
        <div class="card" style="display: flex; flex-direction: column; overflow: hidden;">
            <div class="card-header">
                <h3 class="card-title">ترتيب الفيفا العالمي</h3>
                <button onclick="openFifaModal()" style="background: rgba(0,0,0,0.05); border: none; width: 35px; height: 35px; border-radius: 8px; color: #64748b; cursor: pointer; transition: all 0.3s; display: flex; justify-content: center; align-items: center;">
                    <i class="fas fa-expand-arrows-alt"></i>
                </button>
            </div>
            <div class="table-responsive" style="flex: 1; overflow-y: auto; max-height: 500px;">
                <table class="standings-table">
                    <thead>
                        <tr>
                            <th>المركز</th>
                            <th>الدولة</th>
                            <th>النقاط</th>
                            <th>التغيير</th>
                            <th>الاتحاد</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($fifaRankings && count($fifaRankings) > 0): ?>
                            <?php foreach ($fifaRankings as $row): ?>
                                <tr>
                                    <td class="pos-cell"><?= $row['ranking'] ?></td>
                                    <td class="team-cell">
                                        <img src="<?= htmlspecialchars($row['flag_url']) ?>" alt="<?= htmlspecialchars($row['country_name']) ?>">
                                        <span><?= htmlspecialchars($row['country_name']) ?></span>
                                    </td>
                                    <td><?= number_format($row['points'], 2) ?></td>
                                    <td style="color: <?= $row['rank_change'] > 0 ? '#10b981' : ($row['rank_change'] < 0 ? '#ef4444' : '#64748b') ?>;">
                                        <?php if ($row['rank_change'] > 0): ?>
                                            <i class="fas fa-arrow-up"></i> <?= abs($row['rank_change']) ?>
                                        <?php elseif ($row['rank_change'] < 0): ?>
                                            <i class="fas fa-arrow-down"></i> <?= abs($row['rank_change']) ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-size: 0.75rem; color: var(--text-secondary);"><?= htmlspecialchars($row['confederation'] ?? '-') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" style="text-align:center; padding: 40px; color: var(--text-secondary);">لا توجد بيانات ترتيب FIFA متاحة حاليًا. قم بتحديث البيانات من صفحة الإعدادات.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Database Growth Chart -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"> إحصائيات نمو البيانات</h3>
            </div>
            <div style="height: 350px; position: relative;">
                <div id="dbGrowthChart" style="width: 100%; height: 100%;"></div>
            </div>
        </div>

    </div>

    <!-- Right Column -->
    <div class="dashboard-column">
        <!-- Data Distribution Chart -->
        <div class="card">
            <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
                <h3 class="card-title"> التوزيع (MB)</h3>
                <div style="font-size: 0.85rem; font-weight: 600; color: #10b981; background: rgba(16, 185, 129, 0.1); padding: 5px 12px; border-radius: 10px; display: flex; align-items: center; gap: 6px;">
                    <i class="fas fa-database" style="font-size: 0.75rem;"></i>
                    <span id="distributionTotalSizeValue">الإجمالي: <?= number_format($totalSizeMB, 2) ?> MB</span>
                </div>
            </div>
            <div style="height: 350px; position: relative; ">
                <div id="dataDistChart" style="width: 100%; height: 100%;"></div>
            </div>
        </div>

        <!-- Completion Charts Grid -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">نسبة اكتمال البيانات</h3>
            </div>
            <div class="chart-container" style="position: relative; height: 300px; margin: 20px 0;">
                <div id="completionChart" style="width: 100%; height: 100%;"></div>
            </div>
        </div>

        <!-- Action Banner -->
        <div class="action-banner">
            <div class="banner-content">
                <p class="banner-tag">مركز التحكم</p>
                <h2 class="banner-title">تحديث بيانات<br>المباريات والنتائج</h2>
                <button class="banner-btn" onclick="window.location.href='settings.php#scrapeSection'">ابدأ التحديث</button>
            </div>
            <i class="fas fa-futbol banner-img" style="font-size: 8rem; position: absolute; left: -20px; bottom: -20px; opacity: 0.1; transform: rotate(-15deg);"></i>
        </div>

        <!-- Globe Card -->
        <div class="card globe-card" id="globeCard" style="background: #fff; overflow: hidden; position: relative; height: 400px; border: 1px solid #e2e8f0; padding: 0; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
            <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; position: absolute; top: 0; left: 0; right: 0; z-index: 10;  border: none; padding: 15px 20px;">
                <h3 class="card-title" style="color: #1e293b; margin: 0; font-weight: 700;"> التوزيع الجغرافي للطلبات</h3>
                <button onclick="openGeoModal()" style="background: rgba(0,0,0,0.05); border: none; width: 35px; height: 35px; border-radius: 8px; color: #64748b; cursor: pointer; transition: all 0.3s; display: flex; justify-content: center; align-items: center;">
                    <i class="fas fa-expand-arrows-alt"></i>
                </button>
            </div>
            <div id="globe_div" style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; opacity: 0; transition: opacity 0.6s ease;"></div>
            <div class="globe-loader" id="main_globe_loader"></div>
            <div id="geoLegend" style="position: absolute; bottom: 0; left: 0; right: 0; padding: 15px 20px; font-size: 0.8rem; color: #64748b; background: linear-gradient(transparent, rgba(255, 255, 255, 0.95)); z-index: 10; pointer-events: none;">
                <div id="geoCountryList">جاري تحميل الكوكب...</div>
            </div>
        </div>

        <!-- API Bar Race Chart -->
        <div class="card">
            <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
                <h3 class="card-title">أكثر 5 دول طلبًا لـ API</h3>
                <div style="font-size: 0.85rem; font-weight: 600; color: #3b82f6; background: rgba(59, 130, 246, 0.1); padding: 5px 12px; border-radius: 10px; display: flex; align-items: center; gap: 6px;">
                    <i class="fas fa-chart-line" style="font-size: 0.75rem;"></i>
                    <span id="top5ApiTotalValue">الإجمالي: <?= formatSmartCount($top5TotalRequests) ?> طلب</span>
                </div>
            </div>
            <div style="height: 300px; padding: 0 0 0 10px;">
                <div id="apiBarRaceChart" style="width: 100%; height: 100%;"></div>
            </div>
        </div>

        <!-- Geo Modal -->
        <div id="geoModal" class="modal-overlay" onclick="closeGeoModal()">
            <div class="modal-content" style="background: #fff; border: 1px solid #e2e8f0;" onclick="event.stopPropagation()">
                <div class="modal-close" onclick="closeGeoModal()" style="background: rgba(0,0,0,0.05); color: #64748b;">
                    <i class="fas fa-times"></i>
                </div>
                <div class="card-header" style="margin-bottom: 20px; border: none; padding: 0;">
                    <h3 class="card-title" style="font-size: 1.5rem; color: #1e293b; font-weight: 700;"> التوزيع الجغرافي العالمي</h3>
                </div>
                <div id="modal_globe_div" style="width: 100%; flex: 1; height: 100%; position: relative; opacity: 0; transition: opacity 0.6s ease;"></div>
                <div class="globe-loader" id="modal_globe_loader"></div>
            </div>
        </div>

        <!-- FIFA Rankings Modal -->
        <div id="fifaModal" class="modal-overlay" onclick="closeFifaModal()">
            <div class="modal-content" style="background: #fff; border: 1px solid #e2e8f0; max-width: 900px;" onclick="event.stopPropagation()">
                <button type="button" class="btn-ai" id="aiTranslateFifaBtn" onclick="startAiFifaTranslation()" title="ترجمة الدول المفقودة باستخدام الذكاء الاصطناعي" style="position: absolute !important; bottom: 20px; left: 20px; z-index: 100; width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                    <i class="fa-solid fa-wand-sparkles"></i>
                </button>
                <div class="modal-close" onclick="closeFifaModal()" style="background: rgba(0,0,0,0.05); color: #64748b;">
                    <i class="fas fa-times"></i>
                </div>
                <div class="card-header" style="margin-bottom: 20px; border: none; padding: 0;">
                    <h3 class="card-title" style="font-size: 1.5rem; color: #1e293b; font-weight: 700;">ترتيب الفيفا العالمي - جميع الدول</h3>
                </div>

                <div style="flex: 1; overflow-y: auto; max-height: calc(85vh - 120px);">
                    <table class="standings-table">
                        <thead style="position: sticky; top: 0; background: #fff; z-index: 10;">
                            <tr>
                                <th>المركز</th>
                                <th>الدولة</th>
                                <th>النقاط</th>
                                <th>التغيير</th>
                                <th>الاتحاد</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($allFifaRankings && count($allFifaRankings) > 0): ?>
                                <?php foreach ($allFifaRankings as $row): ?>
                                    <tr>
                                        <td class="pos-cell"><?= $row['ranking'] ?></td>
                                        <td class="team-cell">
                                            <img src="<?= htmlspecialchars($row['flag_url']) ?>" alt="<?= htmlspecialchars($row['country_name']) ?>">
                                            <span><?= htmlspecialchars($row['country_name']) ?></span>
                                        </td>
                                        <td><?= number_format($row['points'], 2) ?></td>
                                        <td style="color: <?= $row['rank_change'] > 0 ? '#10b981' : ($row['rank_change'] < 0 ? '#ef4444' : '#64748b') ?>;">
                                            <?php if ($row['rank_change'] > 0): ?>
                                                <i class="fas fa-arrow-up"></i> <?= abs($row['rank_change']) ?>
                                            <?php elseif ($row['rank_change'] < 0): ?>
                                                <i class="fas fa-arrow-down"></i> <?= abs($row['rank_change']) ?>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-size: 0.75rem; color: var(--text-secondary);"><?= htmlspecialchars($row['confederation'] ?? '-') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="5" style="text-align:center; padding: 40px; color: var(--text-secondary);">لا توجد بيانات ترتيب FIFA متاحة حاليًا.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>


    </div>
</div>





<script>
const initialDashboardSnapshot = {
    stats: <?= json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    today_stats: <?= json_encode($todayStats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    today_overview: {
        live: <?= (int) $stats['today_live'] ?>,
        soon: <?= (int) $soonMatchesCount ?>,
        scheduled: <?= (int) $stats['today_scheduled'] ?>,
        finished: <?= (int) $stats['today_finished'] ?>
    },
    soon_matches_count: <?= (int) $soonMatchesCount ?>,
    next_matches: <?= json_encode(array_map(static function ($match) {
        return [
            'league_name' => $match['league_name'] ?? '',
            'date_label' => date('D d M Y', strtotime((string) ($match['match_date'] ?? 'now'))),
            'time_label' => !empty($match['details_match_time']) ? (string) $match['details_match_time'] : date('H:i', strtotime((string) ($match['match_time'] ?? 'now'))),
            'home_team' => $match['home_team'] ?? '',
            'home_logo' => $match['home_logo'] ?? '',
            'away_team' => $match['away_team'] ?? '',
            'away_logo' => $match['away_logo'] ?? ''
        ];
    }, $nextMatches), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    distribution: <?= json_encode($distribution, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    distribution_total_mb: <?= json_encode((float) $totalSizeMB) ?>,
    scrape_items: <?= json_encode($scrapeItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    db_history: <?= json_encode($dbHistory, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    country_stats: <?= json_encode($countryStats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    top5_total_requests: <?= (int) $top5TotalRequests ?>,
    api_stats: <?= json_encode($apiStats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    period: '24h'
};

const countryNamesMap = {
    MA: 'المغرب', SA: 'السعودية', EG: 'مصر', FR: 'فرنسا',
    US: 'أمريكا', AE: 'الإمارات', QA: 'قطر', KW: 'الكويت',
    DZ: 'الجزائر', TN: 'تونس', LY: 'ليبيا', JO: 'الأردن',
    OM: 'عمان', BH: 'البحرين', IQ: 'العراق', PS: 'فلسطين',
    LB: 'لبنان', SY: 'سوريا', SD: 'السودان', YE: 'اليمن',
    TR: 'تركيا', DE: 'ألمانيا', ES: 'إسبانيا', IT: 'إيطاليا',
    GB: 'بريطانيا', CA: 'كندا', AU: 'أستراليا', BR: 'البرازيل'
};

const dashboardState = {
    snapshot: initialDashboardSnapshot,
    apiRange: initialDashboardSnapshot.period || '24h',
    charts: {},
    refreshInFlight: false,
    refreshHandle: null,
    geoJson: null
};

let mainGlobe;
let modalGlobe;

function formatSmartCountJs(value) {
    const numeric = Number(value || 0);
    if (numeric >= 1000000) {
        const shortValue = Math.floor(numeric / 100000) / 10;
        return `${Number.isInteger(shortValue) ? shortValue.toFixed(0) : shortValue}M`;
    }
    if (numeric >= 1000) {
        const shortValue = Math.floor(numeric / 100) / 10;
        return `${Number.isInteger(shortValue) ? shortValue.toFixed(0) : shortValue}K`;
    }
    return `${numeric}`;
}

function formatFixedNumber(value, digits = 2) {
    const numeric = Number(value || 0);
    return numeric.toLocaleString('en-US', {
        minimumFractionDigits: digits,
        maximumFractionDigits: digits
    });
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function getSnapshot() {
    return dashboardState.snapshot || {};
}

function getCountryStats() {
    return Array.isArray(getSnapshot().country_stats) ? getSnapshot().country_stats : [];
}

function ensureCharts() {
    if (!dashboardState.charts.completionChart) {
        dashboardState.charts.completionChart = echarts.init(document.getElementById('completionChart'));
    }
    if (!dashboardState.charts.todayStatsChart) {
        dashboardState.charts.todayStatsChart = echarts.init(document.getElementById('todayStatsChart'));
    }
    if (!dashboardState.charts.apiChart) {
        dashboardState.charts.apiChart = echarts.init(document.getElementById('apiUsageChart'));
    }
    if (!dashboardState.charts.distChart) {
        dashboardState.charts.distChart = echarts.init(document.getElementById('dataDistChart'));
    }
    if (!dashboardState.charts.dbGrowthChart) {
        dashboardState.charts.dbGrowthChart = echarts.init(document.getElementById('dbGrowthChart'));
    }
    if (!dashboardState.charts.apiFunnelChart) {
        dashboardState.charts.apiFunnelChart = echarts.init(document.getElementById('apiBarRaceChart'));
    }
}

function renderNextMatches(matches) {
    const wrap = document.getElementById('nextMatchesList');
    if (!wrap) return;

    if (!Array.isArray(matches) || !matches.length) {
        wrap.innerHTML = `
            <div style="padding: 40px; text-align: center;">
                <i class="fas fa-calendar-times" style="font-size: 3rem; opacity: 0.1; margin-bottom: 15px; display: block;"></i>
                <p style="color: var(--text-secondary);">لا توجد مباريات قادمة</p>
            </div>
        `;
        return;
    }

    wrap.innerHTML = matches.map((match) => `
        <p class="competition-info">${escapeHtml(match.league_name)} • ${escapeHtml(match.date_label)}, ${escapeHtml(match.time_label)}</p>
        <div class="teams-vs">
            <div class="team-display">
                <img src="${escapeHtml(match.home_logo)}" class="team-logo-large" alt="Home">
                <span class="team-name-text">${escapeHtml(match.home_team)}</span>
            </div>
            <span class="vs-badge">VS</span>
            <div class="team-display">
                <img src="${escapeHtml(match.away_logo)}" class="team-logo-large" alt="Away">
                <span class="team-name-text">${escapeHtml(match.away_team)}</span>
            </div>
        </div>
    `).join('');
}

function buildCompletionOption(scrapeItems) {
    const items = Array.isArray(scrapeItems) ? scrapeItems : [];
    return {
        tooltip: { trigger: 'item' },
        radar: {
            indicator: items.map((item) => ({ name: item.name, max: 100 })),
            radius: '65%',
            center: ['50%', '50%'],
            shape: 'circle',
            splitNumber: 4,
            axisName: { color: '#64748b', fontSize: 10 },
            splitLine: {
                lineStyle: {
                    color: [
                        'rgba(238, 197, 102, 0.1)', 'rgba(238, 197, 102, 0.2)',
                        'rgba(238, 197, 102, 0.4)', 'rgba(238, 197, 102, 0.6)',
                        'rgba(238, 197, 102, 0.8)', 'rgba(238, 197, 102, 1)'
                    ].reverse()
                }
            },
            splitArea: { show: false },
            axisLine: { lineStyle: { color: 'rgba(238, 197, 102, 0.5)' } }
        },
        series: [{
            name: 'نسبة اكتمال البيانات',
            type: 'radar',
            lineStyle: { width: 3, color: '#10b981' },
            areaStyle: {
                color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
                    { offset: 0, color: 'rgba(16, 185, 129, 0.8)' },
                    { offset: 1, color: 'rgba(16, 185, 129, 0.1)' }
                ])
            },
            data: [{
                value: items.map((item) => Number(item.pct || 0)),
                name: 'نسبة الاكتمال'
            }]
        }]
    };
}

function buildTodayStatsOption(snapshot) {
    const overview = snapshot.today_overview || {};
    const colors = ['#5470C6', '#91CC75', '#EE6666'];
    const dataCount = [
        Number(overview.live || 0),
        Number(overview.soon || 0),
        Number(overview.scheduled || 0),
        Number(overview.finished || 0)
    ];
    const total = dataCount.reduce((sum, current) => sum + current, 0);
    const dataPct = dataCount.map((value) => total > 0 ? Number(((value / total) * 100).toFixed(1)) : 0);

    return {
        color: colors,
        tooltip: { trigger: 'axis', axisPointer: { type: 'cross' } },
        grid: { top: '25%', left: '3%', right: '3%', bottom: '0%', containLabel: true },
        legend: { data: ['العدد', 'النسبة'], top: '0%' },
        xAxis: [{ type: 'category', axisTick: { alignWithLabel: true }, data: ['مباشر', 'قريبة', 'مجدول', 'منتهي'] }],
        yAxis: [
            { type: 'value', name: 'العدد', position: 'left', alignTicks: true, axisLine: { show: true, lineStyle: { color: colors[0] } }, axisLabel: { formatter: '{value}' } },
            { type: 'value', name: 'النسبة', position: 'right', alignTicks: true, axisLine: { show: true, lineStyle: { color: colors[1] } }, axisLabel: { formatter: '{value} %' } }
        ],
        series: [
            {
                name: 'العدد',
                type: 'bar',
                data: dataCount,
                itemStyle: {
                    borderRadius: [5, 5, 0, 0],
                    color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
                        { offset: 0, color: '#83bff6' },
                        { offset: 0.5, color: '#188df0' },
                        { offset: 1, color: '#188df0' }
                    ])
                }
            },
            { name: 'النسبة', type: 'line', yAxisIndex: 1, data: dataPct, smooth: true, lineStyle: { width: 3, color: '#91CC75' } }
        ]
    };
}

function buildApiUsageOption(apiStats) {
    const items = Array.isArray(apiStats) ? apiStats : [];
    return {
        color: ['#80FFA5', '#00DDFF', '#37A2FF', '#FF0087', '#FFBF00'],
        tooltip: { trigger: 'axis', axisPointer: { type: 'cross', label: { backgroundColor: '#6a7985' } } },
        grid: { left: '3%', right: '4%', bottom: '3%', containLabel: true },
        xAxis: [{ type: 'category', boundaryGap: false, data: items.map((item) => item.label) }],
        yAxis: [{ type: 'value' }],
        series: [{
            name: 'عدد الطلبات',
            type: 'line',
            stack: 'Total',
            smooth: true,
            lineStyle: { width: 0 },
            showSymbol: false,
            areaStyle: {
                opacity: 0.8,
                color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
                    { offset: 0, color: 'rgb(128, 255, 165)' },
                    { offset: 1, color: 'rgb(1, 191, 236)' }
                ])
            },
            emphasis: { focus: 'series' },
            data: items.map((item) => Number(item.count || 0))
        }]
    };
}

function buildDistributionOption(distribution) {
    const distData = distribution || {};
    const colors = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6'];
    return {
        tooltip: { trigger: 'item', formatter: '{b}: {c} MB ({d}%)' },
        legend: { bottom: '0%', left: 'center' },
        series: [{
            name: 'توزيع البيانات',
            type: 'pie',
            radius: [20, 100],
            center: ['50%', '45%'],
            roseType: 'area',
            itemStyle: { borderRadius: 8 },
            data: Object.keys(distData).map((key, index) => ({
                value: Number(distData[key] || 0),
                name: key.charAt(0).toUpperCase() + key.slice(1),
                itemStyle: { color: colors[index % colors.length] }
            }))
        }]
    };
}

function buildDbGrowthOption(history) {
    const items = Array.isArray(history) ? history : [];
    return {
        tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } },
        legend: { data: ['المباريات', 'الفرق', 'الدول', 'الدول النشطة', 'الدوريات', 'الدوريات النشطة'], bottom: 0, textStyle: { fontSize: 11 } },
        grid: { left: '3%', right: '4%', bottom: '18%', top: '10%', containLabel: true },
        xAxis: { type: 'category', data: items.map((item) => item.label), axisPointer: { type: 'shadow' } },
        yAxis: [
            { type: 'value', name: 'المباريات والفرق', min: 0, axisLabel: { formatter: '{value}' } },
            { type: 'value', name: 'الدول والدوريات', min: 0, axisLabel: { formatter: '{value}' } }
        ],
        series: [
            { name: 'المباريات', type: 'bar', data: items.map((item) => Number(item.matches || 0)), color: '#10b981', itemStyle: { borderRadius: [4, 4, 0, 0] } },
            { name: 'الفرق', type: 'bar', data: items.map((item) => Number(item.teams || 0)), color: '#3b82f6', itemStyle: { borderRadius: [4, 4, 0, 0] } },
            { name: 'الدول', type: 'line', yAxisIndex: 1, data: items.map((item) => Number(item.countries || 0)), color: '#f59e0b', symbolSize: 6, lineStyle: { width: 2 } },
            { name: 'الدول النشطة', type: 'line', yAxisIndex: 1, data: items.map((item) => Number(item.active_countries || 0)), color: '#f59e0b', symbolSize: 6, lineStyle: { width: 2, type: 'dashed' }, emphasis: { focus: 'series' } },
            { name: 'الدوريات', type: 'line', yAxisIndex: 1, data: items.map((item) => Number(item.leagues || 0)), color: '#8b5cf6', symbolSize: 6, lineStyle: { width: 2 } },
            { name: 'الدوريات النشطة', type: 'line', yAxisIndex: 1, data: items.map((item) => Number(item.active_leagues || 0)), color: '#8b5cf6', symbolSize: 6, lineStyle: { width: 2, type: 'dashed' }, emphasis: { focus: 'series' } }
        ]
    };
}

function buildApiCountriesOption(countryStats) {
    const top5Data = (Array.isArray(countryStats) ? countryStats : []).slice(0, 5).map((item) => ({
        name: countryNamesMap[item.country_code] || item.country_code,
        value: Number(item.count || 0)
    }));

    return {
        color: ['#7e00c7ff', '#e25a00ff', '#00d412ff', '#0035e2ff', '#ce00a1ff'],
        tooltip: { trigger: 'item', formatter: '{a} <br/>{b} : {c} طلب' },
        legend: { data: top5Data.map((item) => item.name), bottom: 0, textStyle: { fontSize: 10 } },
        series: [
            {
                name: 'المتوقع',
                type: 'funnel',
                left: '10%',
                width: '80%',
                label: { formatter: '{b}', position: 'left', color: '#64748b' },
                labelLine: { show: false },
                itemStyle: { opacity: 0.1, borderColor: '#e2e8f0', borderWidth: 1 },
                data: top5Data.map((item) => ({ name: item.name, value: item.value }))
            },
            {
                name: 'الفعلي',
                type: 'funnel',
                left: '10%',
                width: '80%',
                maxSize: '80%',
                label: { position: 'inside', formatter: '{c}', color: '#fff', fontWeight: 'bold' },
                itemStyle: { opacity: 0.9, borderColor: '#fff', borderWidth: 2, borderRadius: 5 },
                emphasis: { label: { position: 'inside', formatter: '{b}: {c} طلب' } },
                data: top5Data.map((item) => ({ name: item.name, value: item.value })),
                z: 100
            }
        ]
    };
}

function renderGeoLegend(countryStats) {
    const geoCountryListDom = document.getElementById('geoCountryList');
    if (!geoCountryListDom) return;

    const items = Array.isArray(countryStats) ? countryStats : [];
    if (!items.length) {
        geoCountryListDom.textContent = 'لا توجد بيانات كافية حالياً';
        return;
    }

    geoCountryListDom.innerHTML = items.slice(0, 8).map((item) => `
        <span style="margin-right: 15px; display: inline-block;">
            <i class="fas fa-circle" style="font-size: 0.5rem; color: #10b981; vertical-align: middle;"></i>
            ${escapeHtml(countryNamesMap[item.country_code] || item.country_code)}: <b>${Number(item.count || 0)}</b>
        </span>
    `).join('');
}

function updateDistributionBadge(totalMb) {
    const badge = document.getElementById('distributionTotalSizeValue') || document.querySelector('#dataDistChart')?.closest('.card')?.querySelector('.card-header div span');
    if (badge) {
        badge.textContent = `الإجمالي: ${formatFixedNumber(totalMb, 2)} MB`;
    }
}

function updateTopApiBadge(totalRequests) {
    const badge = document.getElementById('top5ApiTotalValue') || document.querySelector('#apiBarRaceChart')?.closest('.card')?.querySelector('.card-header div span');
    if (badge) {
        badge.textContent = `الإجمالي: ${formatSmartCountJs(totalRequests)} طلب`;
    }
}

function renderCharts(snapshot) {
    ensureCharts();
    dashboardState.charts.completionChart.setOption(buildCompletionOption(snapshot.scrape_items), true);
    dashboardState.charts.todayStatsChart.setOption(buildTodayStatsOption(snapshot), true);
    dashboardState.charts.apiChart.setOption(buildApiUsageOption(snapshot.api_stats), true);
    dashboardState.charts.distChart.setOption(buildDistributionOption(snapshot.distribution), true);
    dashboardState.charts.dbGrowthChart.setOption(buildDbGrowthOption(snapshot.db_history), true);
    dashboardState.charts.apiFunnelChart.setOption(buildApiCountriesOption(snapshot.country_stats), true);
}

function applyCountryStatsToGlobe(globe) {
    if (!globe || !dashboardState.geoJson) return;
    const stats = getCountryStats();

    globe
        .hexPolygonsData(dashboardState.geoJson.features)
        .hexPolygonResolution(3)
        .hexPolygonMargin(0.3)
        .hexPolygonColor((feature) => {
            const countryCode = feature.properties.ISO_A2;
            const stat = stats.find((item) => item.country_code === countryCode);
            return stat ? '#10b981' : '#475569';
        })
        .hexPolygonLabel((feature) => {
            const countryCode = feature.properties.ISO_A2;
            const stat = stats.find((item) => item.country_code === countryCode);
            return `
                <div style="background: rgba(255,255,255,0.95); padding: 12px; border-radius: 8px; border: 1px solid #10b981; color: #1e293b; font-family: 'Outfit', sans-serif; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1);">
                    <b style="color: #10b981;">${escapeHtml(feature.properties.NAME)}</b><br/>
                    <span style="font-weight: 600;">الطلبات: ${Number(stat?.count || 0)}</span>
                </div>
            `;
        });
}

function applyDashboardSnapshot(snapshot) {
    dashboardState.snapshot = snapshot;
    renderNextMatches(snapshot.next_matches);
    renderCharts(snapshot);
    renderGeoLegend(snapshot.country_stats);
    updateDistributionBadge(snapshot.distribution_total_mb || 0);
    updateTopApiBadge(snapshot.top5_total_requests || 0);
    applyCountryStatsToGlobe(mainGlobe);
    applyCountryStatsToGlobe(modalGlobe);
}

async function refreshDashboard(forceFresh = false) {
    if (dashboardState.refreshInFlight) return;

    dashboardState.refreshInFlight = true;
    if (dashboardState.charts.apiChart) {
        dashboardState.charts.apiChart.showLoading();
    }

    try {
        const cacheBuster = forceFresh ? `&_=${Date.now()}` : '';
        const response = await fetch(`ajax_dashboard_snapshot.php?period=${encodeURIComponent(dashboardState.apiRange)}${cacheBuster}`, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        const result = await response.json();
        if (result.status === 'success' && result.data) {
            applyDashboardSnapshot(result.data);
        }
    } catch (error) {
        console.error('Dashboard auto refresh failed:', error);
    } finally {
        if (dashboardState.charts.apiChart) {
            dashboardState.charts.apiChart.hideLoading();
        }
        dashboardState.refreshInFlight = false;
    }
}

function updateApiChart(range, btn) {
    dashboardState.apiRange = range;
    document.querySelectorAll('.chart-filters .filter-btn').forEach((button) => {
        button.classList.toggle('active', button === btn);
    });
    refreshDashboard(true);
}

window.addEventListener('resize', () => {
    Object.values(dashboardState.charts).forEach((chart) => chart && chart.resize());
    if (mainGlobe) {
        const container = document.getElementById('globe_div');
        if (container) {
            mainGlobe.width(container.offsetWidth).height(container.offsetHeight);
        }
    }
    if (modalGlobe) {
        const container = document.getElementById('modal_globe_div');
        if (container) {
            modalGlobe.width(container.offsetWidth).height(container.offsetHeight);
        }
    }
});

async function initGlobe(containerId = 'globe_div') {
    const container = document.getElementById(containerId);
    if (!container) return;

    renderGeoLegend(getCountryStats());

    const globe = Globe()(container);
    globe
        .globeImageUrl('data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=')
        .backgroundColor('rgba(0,0,0,0)')
        .showAtmosphere(false);

    globe.pointOfView({ altitude: 2.0 });
    globe.controls().autoRotate = true;
    globe.controls().autoRotateSpeed = 0.5;
    globe.controls().enableZoom = (containerId === 'modal_globe_div');

    if (containerId === 'globe_div') {
        mainGlobe = globe;
    } else {
        modalGlobe = globe;
    }

    const fitGlobe = () => {
        const width = container.clientWidth;
        const height = container.clientHeight;
        if (width > 10 && height > 10) {
            globe.width(width);
            globe.height(height);
        }
    };

    let dataLoaded = false;
    let timePassed = false;

    const checkReady = () => {
        if (!dataLoaded || !timePassed) return;
        fitGlobe();
        container.style.opacity = '1';
        const loader = document.getElementById(containerId === 'globe_div' ? 'main_globe_loader' : 'modal_globe_loader');
        if (loader) {
            loader.style.opacity = '0';
            setTimeout(() => loader.remove(), 300);
        }
    };

    fitGlobe();
    setTimeout(fitGlobe, 100);
    setTimeout(fitGlobe, 300);
    setTimeout(fitGlobe, 700);
    setTimeout(() => {
        timePassed = true;
        checkReady();
    }, 1500);

    try {
        if (!dashboardState.geoJson) {
            const response = await fetch('https://raw.githubusercontent.com/vasturiano/globe.gl/master/example/datasets/ne_110m_admin_0_countries.geojson');
            dashboardState.geoJson = await response.json();
        }

        applyCountryStatsToGlobe(globe);
        globe.pointOfView({ altitude: 2.0 }, 500);
        dataLoaded = true;
        checkReady();
    } catch (error) {
        console.error('Globe initialization failed:', error);
    }

    new ResizeObserver(fitGlobe).observe(container);
}

function openGeoModal() {
    document.getElementById('geoModal').style.display = 'flex';
    if (!modalGlobe) {
        setTimeout(() => initGlobe('modal_globe_div'), 100);
    }
}

function closeGeoModal() {
    document.getElementById('geoModal').style.display = 'none';
}

function openFifaModal() {
    document.getElementById('fifaModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeFifaModal() {
    document.getElementById('fifaModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

async function startAiFifaTranslation() {
    const btn = document.getElementById('aiTranslateFifaBtn');
    if (!confirm('هل تريد بدء ترجمة الدول المفقودة باستخدام الذكاء الاصطناعي؟')) {
        return;
    }

    try {
        btn.classList.add('loading');
        const response = await fetch('ajax_translate_fifa.php');
        const data = await response.json();

        if (data.success) {
            alert(data.message);
            if (data.count > 0) {
                window.location.reload();
            }
        } else {
            alert('خطأ: ' + (data.error || 'حدث خطأ غير متوقع'));
        }
    } catch (error) {
        console.error('AI Translation Error:', error);
        alert('حدث خطأ أثناء الاتصال بالخادم');
    } finally {
        btn.classList.remove('loading');
    }
}

document.addEventListener('DOMContentLoaded', () => {
    ensureCharts();
    applyDashboardSnapshot(initialDashboardSnapshot);
    setTimeout(() => initGlobe(), 500);

    if (window.AdminAutoRefresh && typeof window.AdminAutoRefresh.register === 'function') {
        dashboardState.refreshHandle = window.AdminAutoRefresh.register('admin-index-dashboard', () => refreshDashboard(false), {
            interval: 15000,
            runImmediately: false,
            refreshOnVisible: true
        });
    } else {
        dashboardState.refreshHandle = setInterval(() => refreshDashboard(false), 15000);
    }
});

window.addEventListener('load', () => {
    setTimeout(() => {
        if (mainGlobe) {
            const container = document.getElementById('globe_div');
            if (container) {
                mainGlobe.width(container.offsetWidth).height(container.offsetHeight);
            }
        }
    }, 100);
});
</script>

<?php require_once 'includes/footer.php'; ?>



