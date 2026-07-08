<?php
/**
 * Smart Runner V8.0 - "Event-Driven" Edition
 * 
 * Architecture:
 * - Priority Queue: matches sorted by timestamp (nearest first)
 * - Micro-interval checks: 10s polling in monitoring mode
 * - Real-time countdown: server sends wake_at timestamp, frontend calculates live countdown
 * - All logging (steps + errors) fully preserved
 * 
 * Modes:
 * - active:   Live/Starting-soon matches being processed (interval = 0ms)
 * - monitoring: No active matches, checking every 10s for upcoming ones
 * - hero:     Processing old uncompleted matches
 * - sleep:    No matches at all today (check every 60s)
 */

require_once 'init.php';
require_once '../config.php';
require_once '../includes/Database.php';
require_once '../includes/Scraper.php';

// Check if Smart Runner is globally enabled in the database
$stmtSR = $pdo->query("SELECT is_active FROM scraper_settings WHERE task_key = 'smart_runner'");
$srActive = $stmtSR->fetchColumn();
if ($srActive === '0' || $srActive === 0) {
    if (php_sapi_name() !== 'cli') {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['status' => 'error', 'message' => 'Smart Runner is currently disabled by admin.'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// Check for required extensions
if (!extension_loaded('curl')) {
    if (php_sapi_name() !== 'cli') {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['status' => 'error', 'message' => 'PHP cURL extension is missing on this server.'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

class LiveDatabase extends Database {
    public $smartLogs = [];
    public function addScrapeLog($type, $message) {
        $this->smartLogs[] = [
            'type' => $type,
            'message' => $message,
            'created_at' => date('Y-m-d H:i:s')
        ];
        return true;
    }
    public function clearScrapeLogs() { return true; }
}

$db = new LiveDatabase($pdo);
$scraper = new Scraper($db);

if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');
}

// ═══════════════════════════════════════════════════════════
// Internet Health Check: pings a known URL to verify connectivity
// Returns: true (internet works) | false (internet down)
// ═══════════════════════════════════════════════════════════
function checkInternetHealth($sourceUrl) {
    // Extract the homepage of the source website
    $parsed = parse_url($sourceUrl);
    $homepage = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');
    
    // Test 1: Ping the source website's homepage
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $homepage);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_NOBODY, true); // HEAD request only (fast)
    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36");
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // Homepage responds with 200-399 = site is up, internet works
    if ($result !== false && $httpCode >= 200 && $httpCode < 400) {
        return true;
    }
    
    // Test 2: Fallback - try Google as neutral check
    $ch2 = curl_init();
    curl_setopt($ch2, CURLOPT_URL, 'https://www.google.com');
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch2, CURLOPT_NOBODY, true);
    curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
    
    $result2 = curl_exec($ch2);
    $httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);
    
    return ($result2 !== false && $httpCode2 >= 200 && $httpCode2 < 400);
}

// ═══════════════════════════════════════════════════════════
// URL Validator: Smart diagnosis with internet-first check
// 
// Algorithm:
//   1. Fetch the match URL → get HTTP code
//   2. If URL works (200 + valid content) → 'ok'
//   3. If URL fails (404/410/500/redirect/empty):
//      a. Check internet health (ping source homepage + Google)
//      b. Internet OK + URL fails → 'dead' (match removed from site)
//      c. Internet DOWN → 'no_internet' (don't delete, retry later)
//
// Returns: 'ok' | 'dead' | 'redirected' | 'no_internet'
// ═══════════════════════════════════════════════════════════
function validateMatchUrl($url) {
    if (empty($url)) return 'dead';
    
    // Step 1: Fetch the match URL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36");
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_NOBODY, false);
    curl_setopt($ch, CURLOPT_HEADER, false);
    
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    
    // Step 2: If URL loaded successfully (200), check content quality
    if ($httpCode === 200 && $html) {
        // Check if redirected to homepage (match removed, site redirects)
        if ($finalUrl !== $url) {
            $parsedFinal = parse_url($finalUrl);
            $finalPath = trim($parsedFinal['path'] ?? '/', '/');
            if (empty($finalPath) || $finalPath === 'ar' || $finalPath === 'ar/today_matches' || $finalPath === 'default.aspx') {
                return 'redirected'; // Homepage redirect = match removed
            }
        }
        
        // Check if page content says "not found"
        $lowerHtml = mb_strtolower($html, 'UTF-8');
        if (
            (strpos($lowerHtml, '404') !== false && strpos($lowerHtml, 'not found') !== false) ||
            strpos($lowerHtml, 'الصفحة غير موجودة') !== false ||
            strpos($lowerHtml, 'تم حذف') !== false ||
            (strpos($lowerHtml, 'غير متوفر') !== false && strpos($lowerHtml, 'المباراة') !== false) ||
            strpos($lowerHtml, 'page not found') !== false
        ) {
            return 'dead';
        }
        
        return 'ok'; // Page loaded with valid content
    }
    
    // Step 3: URL failed (404, 500, timeout, etc.) → diagnose the cause
    // Is it MY internet or the match URL that's broken?
    $internetWorks = checkInternetHealth($url);
    
    if (!$internetWorks) {
        // My internet is down → don't delete anything
        return 'no_internet';
    }
    
    // Internet is fine but the match URL is broken → match is dead
    // This covers: 404, 410, 500, 502, 503, timeout on match URL only
    if ($httpCode === 404 || $httpCode === 410) {
        return 'dead';
    }
    
    // Server errors (500, 502, 503) WITH working internet = match page is broken/removed
    if ($httpCode >= 500) {
        return 'dead'; // Internet works, but match page returns server error = removed
    }
    
    // Connection failed entirely but internet works = match URL is invalid
    if ($html === false || $httpCode === 0) {
        return 'dead';
    }
    
    return 'dead'; // Any other failure with working internet = dead
}

$action = $_GET['action'] ?? 'run';
$matchIndex = isset($_GET['match_index']) ? (int)$_GET['match_index'] : 0;
$stepIndex = isset($_GET['step_index']) ? (int)$_GET['step_index'] : 0;
$clientId = $_GET['client_id'] ?? 'default_client';

// ═══════════════════════════════════════════════════════════
// Train Carriage Feature (Distributed Workers - Database Backed)
// ═══════════════════════════════════════════════════════════
// 1. Ensure workers table exists
$pdo->exec("CREATE TABLE IF NOT EXISTS scraper_workers (
    client_id VARCHAR(50) PRIMARY KEY,
    last_seen INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// 1B. Ensure broadcast-attempt stage column exists for spaced retries
$streamStageColumnExistsStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'matches'
      AND COLUMN_NAME = 'live_stream_attempt_stage'
");
$streamStageColumnExistsStmt->execute();
if ((int) $streamStageColumnExistsStmt->fetchColumn() === 0) {
    $pdo->exec("ALTER TABLE matches ADD COLUMN live_stream_attempt_stage TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER live_stream_failures");
}

// 2. Cleanup old workers (>45 seconds ago - slightly more buffer than 30s)
$cutoff = time() - 45;
$pdo->prepare("DELETE FROM scraper_workers WHERE last_seen < ?")->execute([$cutoff]);

// 3. Update this client's heartbeat (Upsert)
$stmtUpsert = $pdo->prepare("INSERT INTO scraper_workers (client_id, last_seen) VALUES (?, ?) 
                             ON DUPLICATE KEY UPDATE last_seen = VALUES(last_seen)");
$stmtUpsert->execute([$clientId, time()]);

// 4. Get ALL active workers sorted by ID for consistent indexing
$stmtList = $pdo->query("SELECT client_id FROM scraper_workers ORDER BY client_id ASC");
$workerKeys = $stmtList->fetchAll(PDO::FETCH_COLUMN);
$totalWorkers = count($workerKeys);
$workerIndex = array_search($clientId, $workerKeys);
if ($workerIndex === false) $workerIndex = 0; // Fallback

const SMART_RUNNER_HERO_LOOKBACK_DAYS = 30;
const SMART_RUNNER_HERO_LIMIT = 200;
const SMART_RUNNER_LIVE_STREAM_WINDOWS = [
    ['label' => 'قبل البداية', 'minute' => null],
    ['label' => "الدقيقة '1", 'minute' => 1],
    ['label' => "الدقيقة '5", 'minute' => 5],
    ['label' => "الدقيقة '9", 'minute' => 9],
    ['label' => "الدقيقة '15", 'minute' => 15],
];

function isScheduledMatchStatus($status) {
    return in_array((string) $status, ['Scheduled', 'جدول'], true);
}

function isLiveMatchStatus($status) {
    return in_array((string) $status, ['Live', 'مباشر'], true);
}

function isFinishedMatchStatus($status) {
    return in_array((string) $status, ['Finished', 'إنتهت'], true);
}

function isFrozenMatchStatus($status) {
    return in_array((string) $status, ['Postponed', 'مؤجلة', 'Cancelled', 'ملغاة'], true);
}

function buildKickoffSql($alias = '') {
    $prefix = $alias !== '' ? $alias . '.' : '';
    return "COALESCE(
                {$prefix}start_time,
                CASE
                    WHEN {$prefix}match_date IS NOT NULL AND {$prefix}match_time REGEXP '^[0-9]{1,2}:[0-9]{2}$'
                    THEN STR_TO_DATE(CONCAT({$prefix}match_date, ' ', {$prefix}match_time), '%Y-%m-%d %H:%i')
                    ELSE NULL
                END
            )";
}

function getMatchKickoffTimestamp(array $match) {
    $timezone = new DateTimeZone('UTC');

    $startTime = trim((string) ($match['start_time'] ?? ''));
    if ($startTime !== '' && $startTime !== '0000-00-00 00:00:00') {
        try {
            return (new DateTimeImmutable($startTime, $timezone))->getTimestamp();
        } catch (Exception $e) {
            // Fall through to match_date + match_time.
        }
    }

    $matchDate = trim((string) ($match['match_date'] ?? ''));
    $matchTime = trim((string) ($match['match_time'] ?? ''));
    if ($matchDate === '' || !preg_match('/^\d{1,2}:\d{2}$/', $matchTime)) {
        return null;
    }

    try {
        return (new DateTimeImmutable($matchDate . ' ' . $matchTime, $timezone))->getTimestamp();
    } catch (Exception $e) {
        return null;
    }
}

function extractLiveMinuteFromMatch(array $match, $currentTime = null) {
    $matchTime = trim((string) ($match['match_time'] ?? ''));

    if ($matchTime !== '' && !preg_match('/^\d{1,2}:\d{2}$/', $matchTime) && preg_match("/(\d{1,3})\s*(?:\+\d+)?\s*'?/u", $matchTime, $minuteMatches)) {
        $minute = (int) $minuteMatches[1];
        if ($minute > 0 && $minute <= 130) {
            return $minute;
        }
    }

    $kickoffTimestamp = getMatchKickoffTimestamp($match);
    if ($kickoffTimestamp === null) {
        return null;
    }

    $now = $currentTime ?? time();
    if ($now < $kickoffTimestamp) {
        return 0;
    }

    return (int) floor(($now - $kickoffTimestamp) / 60);
}

function getEffectiveLiveStreamAttemptStage(array $match, $currentTime = null) {
    $legacyFailures = max(0, (int) ($match['live_stream_failures'] ?? 0));
    $storedStage = max(0, (int) ($match['live_stream_attempt_stage'] ?? 0), min(count(SMART_RUNNER_LIVE_STREAM_WINDOWS), $legacyFailures));
    if ($storedStage > 0) {
        return $storedStage;
    }

    $kickoffTimestamp = getMatchKickoffTimestamp($match);
    if ($kickoffTimestamp === null) {
        return 0;
    }

    $now = $currentTime ?? time();
    if ($now < $kickoffTimestamp) {
        return 0;
    }

    return 1;
}

function getLiveStreamAttemptPlan(array $match, $currentTime = null) {
    $windows = SMART_RUNNER_LIVE_STREAM_WINDOWS;
    $effectiveStage = getEffectiveLiveStreamAttemptStage($match, $currentTime);
    $maxStage = count($windows);

    if ($effectiveStage >= $maxStage) {
        return [
            'allowed' => false,
            'done' => true,
            'target_stage' => $effectiveStage,
            'attempt_number' => $maxStage,
            'max_attempts' => $maxStage,
            'reason' => "تم استهلاك جميع نوافذ البحث عن البث (" . $maxStage . " محاولات).",
        ];
    }

    $window = $windows[$effectiveStage];
    $kickoffTimestamp = getMatchKickoffTimestamp($match);
    $now = $currentTime ?? time();

    if ($window['minute'] === null) {
        $allowed = ($kickoffTimestamp !== null && $now < $kickoffTimestamp);
        return [
            'allowed' => $allowed,
            'done' => false,
            'target_stage' => $effectiveStage,
            'attempt_number' => $effectiveStage + 1,
            'max_attempts' => $maxStage,
            'window_label' => $window['label'],
            'reason' => $allowed ? null : "سيتم تأجيل محاولة البث التالية حتى {$windows[1]['label']}.",
        ];
    }

    $liveMinute = extractLiveMinuteFromMatch($match, $now);
    $allowed = ($liveMinute !== null && $liveMinute >= $window['minute']);

    return [
        'allowed' => $allowed,
        'done' => false,
        'target_stage' => $effectiveStage,
        'attempt_number' => $effectiveStage + 1,
        'max_attempts' => $maxStage,
        'window_label' => $window['label'],
        'required_minute' => $window['minute'],
        'current_minute' => $liveMinute,
        'reason' => $allowed ? null : "سيتم تأجيل محاولة البث التالية حتى {$window['label']}.",
    ];
}

function shouldAutoPromoteMatchToLive(array $match, $currentTime = null) {
    if (!isScheduledMatchStatus($match['status'] ?? '')) {
        return false;
    }

    if (isFrozenMatchStatus($match['status'] ?? '') || isFinishedMatchStatus($match['status'] ?? '')) {
        return false;
    }

    $kickoffTimestamp = getMatchKickoffTimestamp($match);
    if ($kickoffTimestamp === null) {
        return false;
    }

    $now = $currentTime ?? time();
    $elapsed = $now - $kickoffTimestamp;

    return $elapsed >= 0 && $elapsed <= (4 * 3600);
}

function autoPromoteMatchToLive(PDO $pdo, array &$match, $currentTime = null) {
    if (!shouldAutoPromoteMatchToLive($match, $currentTime) || empty($match['id'])) {
        return false;
    }

    $currentMatchTime = trim((string) ($match['match_time'] ?? ''));
    $currentDetailsTime = trim((string) ($match['details_match_time'] ?? ''));
    $preserveKickoffClock = preg_match('/^\d{1,2}:\d{2}$/', $currentMatchTime) === 1;

    if ($preserveKickoffClock) {
        $stmt = $pdo->prepare("
            UPDATE matches
            SET status = 'Live',
                details_match_time = CASE
                    WHEN (details_match_time IS NULL OR details_match_time = '')
                    THEN ?
                    ELSE details_match_time
                END,
                match_time = '0'
            WHERE id = ?
              AND status IN ('Scheduled', 'جدول')
        ");
        $stmt->execute([$currentMatchTime, $match['id']]);
    } else {
        $stmt = $pdo->prepare("UPDATE matches SET status = 'Live' WHERE id = ? AND status IN ('Scheduled', 'جدول')");
        $stmt->execute([$match['id']]);
    }

    if ($stmt->rowCount() <= 0) {
        return false;
    }

    $match['status'] = 'Live';
    if ($preserveKickoffClock) {
        if ($currentDetailsTime === '') {
            $match['details_match_time'] = $currentMatchTime;
        }
        $match['match_time'] = '0';
    }
    return true;
}

function hasLiveProgressEvidence(array $match) {
    if (isLiveMatchStatus($match['status'] ?? '')) {
        return true;
    }

    $matchTime = trim((string) ($match['match_time'] ?? ''));
    if ($matchTime !== '' && preg_match('/^\d{1,2}:\d{2}$/', $matchTime) !== 1 && preg_match("/(\d{1,3})\s*(?:\+\d+)?\s*'?/u", $matchTime)) {
        return true;
    }

    if (!empty($match['live_url']) || !empty($match['live_iframe'])) {
        return true;
    }

    return ((int) ($match['score_home'] ?? 0) > 0) || ((int) ($match['score_away'] ?? 0) > 0);
}

function isStrictPastMatch(array $match, $currentTime = null) {
    $now = $currentTime ?? time();
    $kickoffTimestamp = getMatchKickoffTimestamp($match);
    if ($kickoffTimestamp !== null) {
        return ($now - $kickoffTimestamp) >= (8 * 3600);
    }

    $matchDate = trim((string) ($match['match_date'] ?? ''));
    if ($matchDate === '') {
        return false;
    }

    try {
        $fallbackTimestamp = (new DateTimeImmutable($matchDate . ' 00:00:00', new DateTimeZone('UTC')))->getTimestamp();
        return ($now - $fallbackTimestamp) >= (36 * 3600);
    } catch (Exception $e) {
        return false;
    }
}

function shouldForcePostponeStaleScheduledMatch(array $match, $currentTime = null) {
    if (!isScheduledMatchStatus($match['status'] ?? '')) {
        return false;
    }

    if (isFinishedMatchStatus($match['status'] ?? '') || isFrozenMatchStatus($match['status'] ?? '')) {
        return false;
    }

    if (hasLiveProgressEvidence($match)) {
        return false;
    }

    return isStrictPastMatch($match, $currentTime);
}

function buildHeroMatchWhereSql($alias = '') {
    $prefix = $alias !== '' ? $alias . '.' : '';
    $startedAtSql = buildKickoffSql($alias);

    return "(
                {$prefix}match_date BETWEEN DATE_SUB(UTC_DATE(), INTERVAL " . SMART_RUNNER_HERO_LOOKBACK_DAYS . " DAY) AND UTC_DATE()
            )
            AND (
                {$prefix}match_date < UTC_DATE()
                OR {$prefix}status IN ('Finished', 'إنتهت', 'Live', 'مباشر')
                OR {$prefix}match_time NOT LIKE '%:%'
                OR ({$startedAtSql} IS NOT NULL AND {$startedAtSql} <= UTC_TIMESTAMP())
            )
            AND (
                {$prefix}is_visited = 0
                OR (
                    {$prefix}is_completed = 0
                    AND (
                        {$prefix}last_visited IS NULL
                        OR {$prefix}last_visited <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 MINUTE)
                    )
                )
            )";
}

function buildHeroMatchOrderSql($alias = '') {
    $prefix = $alias !== '' ? $alias . '.' : '';
    $startedAtSql = buildKickoffSql($alias);

    return "CASE WHEN {$prefix}is_visited = 0 THEN 0 ELSE 1 END ASC,
            CASE WHEN {$prefix}is_completed = 0 THEN 0 ELSE 1 END ASC,
            CASE WHEN {$prefix}last_visited IS NULL THEN 0 ELSE 1 END ASC,
            COALESCE({$prefix}last_visited, '1970-01-01 00:00:00') ASC,
            {$prefix}match_date ASC,
            COALESCE({$startedAtSql}, '1970-01-01 00:00:00') ASC,
            {$prefix}id ASC";
}

if ($action === 'run') {
    $today = date('Y-m-d');
    $allTodayMatches = $db->getMatches($today);
    
    $activeMatches = [];
    $seenIds = [];
    $currentTime = time();
    $hasAnyLiveNow = false;
    $autoPromotedCount = 0;

    foreach ($allTodayMatches as &$match) {
        if (autoPromoteMatchToLive($pdo, $match, $currentTime)) {
            $autoPromotedCount++;
        }
    }
    unset($match);

    if ($autoPromotedCount > 0 && $matchIndex === 0 && $stepIndex === 0) {
        $db->addScrapeLog('info', "⏱️ تم تحويل {$autoPromotedCount} مباراة تلقائيًا إلى مباشر لأن وقت بدايتها وصل، وسيتم تأكيد حالتها الدقيقة من المصدر.");
    }

    // ═══════════════════════════════════════════════════════════
    // Phase 1: Priority Queue - Collect ALL actionable matches
    // ═══════════════════════════════════════════════════════════

    // 1A: Live matches (HIGHEST PRIORITY - timestamp = 0)
    foreach ($allTodayMatches as $match) {
        if ($match['status'] === 'Live' || $match['status'] === 'مباشر') {
            $hasAnyLiveNow = true;
            $match['_priority'] = 0; // Highest priority
            $activeMatches[] = $match;
            $seenIds[$match['id']] = true;
        }
    }

    // 1B: Scheduled but starting within 2 minutes (ALWAYS collected, even during Live matches)
    foreach ($allTodayMatches as $match) {
        if (isset($seenIds[$match['id']])) continue;
        
        $matchTimestamp = getMatchKickoffTimestamp($match);
        // THRESHOLD: 2 minutes (120 seconds) before start
        $isStartingSoon = (isScheduledMatchStatus($match['status'] ?? '') && $matchTimestamp !== null && $currentTime >= ($matchTimestamp - 120));
        
        if ($isStartingSoon) {
            $match['_priority'] = $matchTimestamp;
            $activeMatches[] = $match;
            $seenIds[$match['id']] = true;
        }
    }

    // 1C: Sort active matches by priority (Priority Queue - nearest first)
    usort($activeMatches, function($a, $b) {
        return ($a['_priority'] ?? PHP_INT_MAX) - ($b['_priority'] ?? PHP_INT_MAX);
    });

    // ═══════════════════════════════════════════════════════════
    // Phase 2 & Train Carriage Division
    // ═══════════════════════════════════════════════════════════
    
    // First, divide the actionable matches (Live/Soon) among workers
    $originalActiveMatches = $activeMatches;
    if ($totalWorkers > 1 && count($activeMatches) > 0) {
        $assignedMatches = [];
        foreach ($activeMatches as $idx => $match) {
            if ($idx % $totalWorkers === $workerIndex) {
                $assignedMatches[] = $match;
            }
        }
        $activeMatches = $assignedMatches;
    }

    $assignedActiveMatches = $activeMatches;
    $assignedHeroMatches = [];
    $allowHeroMode = !$hasAnyLiveNow;

    if ($allowHeroMode) {
        $heroWhereSql = buildHeroMatchWhereSql('m');
        $heroOrderSql = buildHeroMatchOrderSql('m');
        $stmt = $pdo->query("SELECT m.*, t1.name as home_team, t2.name as away_team 
                             FROM matches m
                             JOIN teams t1 ON m.home_team_id = t1.id
                             JOIN teams t2 ON m.away_team_id = t2.id
                             WHERE {$heroWhereSql}
                             ORDER BY {$heroOrderSql}
                             LIMIT " . SMART_RUNNER_HERO_LIMIT);
        $heroMatches = $stmt->fetchAll();

        if (count($heroMatches) > 0) {
            $heroIndexCounter = 0;
            foreach ($heroMatches as $hm) {
                if (!isset($seenIds[$hm['id']])) {
                    if ($heroIndexCounter % $totalWorkers === $workerIndex || count($assignedActiveMatches) === 0) {
                        $hm['_is_hero'] = true;
                        $assignedHeroMatches[] = $hm;
                    }
                    $seenIds[$hm['id']] = true;
                    $heroIndexCounter++;
                }
            }
        }
    } elseif ($matchIndex === 0 && $stepIndex === 0) {
        $db->addScrapeLog('filter', "⏸️ تم تعطيل وضع البطل مؤقتًا لأن هناك مباريات مباشرة قيد المتابعة الآن.");
    }

    if (count($assignedActiveMatches) > 0 && count($assignedHeroMatches) > 0) {
        $activeMatches = array_merge($assignedActiveMatches, $assignedHeroMatches);
        if ($matchIndex === 0 && $stepIndex === 0) {
            $db->addScrapeLog('hero', "🧹 وضع البطل المدمج: ستتم معالجة " . count($assignedHeroMatches) . " مباراة متأخرة بعد إنهاء المباريات المباشرة أو القريبة لهذه العربة.");
        }
    } elseif (count($assignedHeroMatches) > 0) {
        $activeMatches = $assignedHeroMatches;
        if ($matchIndex === 0 && $stepIndex === 0) {
            $db->addScrapeLog('hero', "🚀 وضع البطل: مساعدة في معالجة " . count($activeMatches) . " مباراة قديمة من قبل العربة " . ($workerIndex + 1));
        }
    } else {
        $activeMatches = $assignedActiveMatches;
    }

    // Fallback: If I STILL have no matches, but there WERE original active matches (e.g. 1 match and I'm worker 2)
    // and there aren't any hero matches to do, I will join forces and just take the full original list to help out.
    if (count($activeMatches) === 0 && count($originalActiveMatches) > 0) {
        $activeMatches = $originalActiveMatches;
        if ($matchIndex === 0 && $stepIndex === 0 && count($activeMatches) > 0) {
            $db->addScrapeLog('info', "🤝 تعاون: لا يوجد وضع بطل، العربة " . ($workerIndex + 1) . " تنضم للمساعدة في المباراة المتبقية.");
        }
    } else {
        // Normal log train distribution
        if ($totalWorkers > 1 && $matchIndex === 0 && $stepIndex === 0 && count($activeMatches) > 0 && empty($activeMatches[0]['_is_hero'])) {
            $db->addScrapeLog('info', "🚂 قطار العربات: أنت العربة رقم " . ($workerIndex + 1) . " من أصل $totalWorkers. نصيبك " . count($activeMatches) . " مباراة.");
        }
    }

    $total = count($activeMatches);

    // ═══════════════════════════════════════════════════════════
    // Phase 3: No active matches → Micro-interval monitoring
    // ═══════════════════════════════════════════════════════════
    if ($total === 0) {
        // Check for old uncompleted matches
        $heroWhereSql = buildHeroMatchWhereSql();
        $stmtHeroCheck = $pdo->query("SELECT COUNT(*) FROM matches WHERE {$heroWhereSql}");
        $uncompletedCount = (int) $stmtHeroCheck->fetchColumn();

        // Find ALL upcoming matches today
        $kickoffSql = buildKickoffSql('m');
        $stmt = $pdo->prepare("SELECT m.*, 
                               t1.name as home_team, t2.name as away_team,
                               {$kickoffSql} as kickoff_at 
                               FROM matches m
                               JOIN teams t1 ON m.home_team_id = t1.id
                               JOIN teams t2 ON m.away_team_id = t2.id
                               WHERE m.match_date = ? 
                               AND m.status IN ('Scheduled', 'جدول')
                               AND {$kickoffSql} IS NOT NULL
                               AND {$kickoffSql} > UTC_TIMESTAMP() 
                               ORDER BY {$kickoffSql} ASC");
        $stmt->execute([$today]);
        $allUpcoming = $stmt->fetchAll();
        $totalUpcoming = count($allUpcoming);
        
        if ($totalUpcoming > 0) {
            $nearest = $allUpcoming[0];
            $startTimestamp = strtotime($nearest['kickoff_at']);
            $wakeAt = $startTimestamp - 120; // Wake 2 minutes before start
            $realWaitSecs = $wakeAt - time();
            
            if ($realWaitSecs < 0) $realWaitSecs = 0;

            // Hero mode cap: if old matches pending and wait is long, check sooner
            if ($uncompletedCount > 0 && $realWaitSecs > 300) {
                $realWaitSecs = 300;
                $wakeAt = time() + 300;
            }

            // ═══════════════════════════════════════════════════════
            // MICRO-INTERVAL: Instead of sleeping for the full duration,
            // we use a 10-second check cycle. The frontend gets the 
            // real wake_at timestamp and shows a live countdown.
            // ═══════════════════════════════════════════════════════
            $microInterval = 10000; // 10 seconds in ms
            
            // If we're within 30 seconds of wake time, use faster polling
            if ($realWaitSecs <= 30) {
                $microInterval = 3000; // 3 seconds - ultra-fast near match start
            } elseif ($realWaitSecs <= 120) {
                $microInterval = 5000; // 5 seconds - fast near match start
            } elseif ($realWaitSecs > 600) {
                $microInterval = 30000; // 30 seconds - relaxed for distant matches
            }

            // Log with REAL time remaining
            $db->addScrapeLog('info', "📋 يوجد $totalUpcoming مباراة مجدولة لاحقاً اليوم.");
            
            $minsLeft = round(($startTimestamp - time()) / 60);
            $db->addScrapeLog('info', "📌 الأقرب: [{$nearest['home_team']} vs {$nearest['away_team']}] — الساعة {$nearest['match_time']} (بعد {$minsLeft} دقيقة)");

            if ($uncompletedCount > 0) {
                $db->addScrapeLog('hero', "⚠️ يوجد {$uncompletedCount} مباريات قديمة لم تكتمل (وضع البطل نشط)، سأقوم بمعالجتها فوراً.");
                $microInterval = 0;
            } else {
                // Show real countdown
                $hours = floor($realWaitSecs / 3600);
                $mins = floor(($realWaitSecs % 3600) / 60);
                $secs = $realWaitSecs % 60;
                
                if ($hours > 0) {
                    $countdownStr = "{$hours} ساعة و {$mins} دقيقة";
                } elseif ($mins > 0) {
                    $countdownStr = "{$mins} دقيقة و {$secs} ثانية";
                } else {
                    $countdownStr = "{$secs} ثانية";
                }
                
                $db->addScrapeLog('info', "⏰ سأستيقظ قبل دقيقتين من بداية المباراة — بعد {$countdownStr}");
                $db->addScrapeLog('info', "🔄 وضع المراقبة: فحص كل " . ($microInterval / 1000) . " ثوان");
            }
            
            echo json_encode([
                'status' => 'success', 
                'mode' => 'monitoring', 
                'logs' => $db->smartLogs, 
                'next_interval' => $microInterval,
                // Real-time countdown data for frontend
                'wake_at' => $wakeAt,
                'server_time' => time(),
                'nearest_match' => [
                    'name' => "{$nearest['home_team']} vs {$nearest['away_team']}",
                    'time' => date('H:i', $startTimestamp),
                    'timestamp' => $startTimestamp
                ],
                'total_upcoming' => $totalUpcoming
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        } else {
            if ($uncompletedCount > 0) {
                $db->addScrapeLog('hero', "⚠️ يوجد {$uncompletedCount} مباريات قديمة لم تكتمل، سأستيقظ بعد 5 دقائق للتحقق منها. (وضع البطل نشط)");
                echo json_encode([
                    'status' => 'success', 
                    'mode' => 'hero_sleep', 
                    'logs' => $db->smartLogs, 
                    'next_interval' => 300000,
                    'server_time' => time()
                ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            } else {
                $db->addScrapeLog('info', "لا توجد مباريات مجدولة حالياً ولا توجد مباريات معلقة. (وضع الخمول التام)");
                echo json_encode([
                    'status' => 'success', 
                    'mode' => 'sleep', 
                    'logs' => $db->smartLogs, 
                    'next_interval' => 60000, // 60s instead of 60min - micro-interval
                    'server_time' => time()
                ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            }
        }
        exit;
    }

    // ═══════════════════════════════════════════════════════════
    // Phase 4: Process active matches (Step-by-Step pipeline)
    // ═══════════════════════════════════════════════════════════

    // End of Cycle Logic
    if ($matchIndex >= $total) {
        $interval = ($total === 1) ? 5000 : 0;
        $msg = ($interval > 0) ? "--- استراحة قصيرة لتفادي الحظر ---" : "--- دورة جديدة فوراً ---";
        echo json_encode(['status' => 'success', 'mode' => 'cooldown', 'logs' => [['type' => 'info', 'message' => $msg, 'created_at' => date('Y-m-d H:i:s')]], 'next_interval' => $interval, 'next_index' => 0, 'next_step' => 0], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    // REFRESH MATCH DATA: To prevent using stale status/score between steps
    $queuedMatch = $activeMatches[$matchIndex];
    $isHeroMatch = !empty($queuedMatch['_is_hero']);
    $freshMatch = $db->getMatchById($queuedMatch['id']);
    if ($freshMatch) {
        $match = $freshMatch;
        if ($isHeroMatch) {
            $match['_is_hero'] = true;
        }
    } else {
        $match = $queuedMatch;
    }

    $matchId = $match['id'];
    $matchName = (isset($match['home_team']) && isset($match['away_team'])) 
                 ? "{$match['home_team']} vs {$match['away_team']}" 
                 : "Match #$matchId";
    
    $progressStr = "[" . ($matchIndex + 1) . "/$total]";
    
    $nextStep = $stepIndex + 1;
    $nextMatchIndex = $matchIndex;
    $logType = $isHeroMatch ? 'hero' : 'info';
    $currentStepTime = time();

    if (autoPromoteMatchToLive($pdo, $match, time())) {
        $db->addScrapeLog($logType, "⏱️ تم تحويل حالة المباراة تلقائيًا إلى مباشر لأن وقت البداية وصل، وسيتم تأكيد الحالة من المصدر.");
    }
    
    // RECALCULATE FLAGS using fresh data
    $isScheduled = ($match['status'] === 'Scheduled' || $match['status'] === 'جدول');
    $isLive = ($match['status'] === 'Live' || $match['status'] === 'مباشر');
    $isFinishedPre = ($match['status'] === 'Finished' || $match['status'] === 'إنتهت');
    
    $isScheduled = isScheduledMatchStatus($match['status'] ?? '');
    $isLive = isLiveMatchStatus($match['status'] ?? '');
    $isFinishedPre = isFinishedMatchStatus($match['status'] ?? '');

    $isPastMatch = isStrictPastMatch($match, $currentStepTime);
    $hasLiveEvidence = hasLiveProgressEvidence($match);

    // If it looks like a stale scheduled match, force the deeper checks.
    if ($isPastMatch && $isScheduled && !$hasLiveEvidence) {
        $isScheduled = false; // Force checking events/stats
    }

    $logType = $isHeroMatch ? 'hero' : 'info';

    try {
        switch ($stepIndex) {
            case 0:
                $logType = $isHeroMatch ? 'hero' : 'info';
                $db->addScrapeLog($logType, "⚡ $progressStr معالجة: $matchName" . ($isHeroMatch ? " [وضع البطل]" : ""));
                $db->addScrapeLog($logType, "→ الخطوة القادمة: تحديث الحالة والنتيجة...");
                break;

            case 1:
                $res = $scraper->scrapeAndSaveMatchByUrl($match['match_url'], $matchId);
                if ($res) {
                    $db->addScrapeLog('success', "✓ تم تحديث الحالة والنتيجة.");
                } else {
                    // Scraping failed → validate if the URL is still alive
                    $db->addScrapeLog('warning', "⚠ فشل جلب البيانات، جاري التحقق من صلاحية رابط المباراة...");
                    $urlStatus = validateMatchUrl($match['match_url'] ?? '');
                    
                    if ($urlStatus === 'dead' || $urlStatus === 'redirected') {
                        $reason = ($urlStatus === 'dead') ? 'الصفحة محذوفة (404)' : 'تم إعادة التوجيه للرئيسية (مباراة مؤجلة/محذوفة)';
                        $db->addScrapeLog('error', "🗑️ رابط المباراة ميت: $reason");
                        $db->addScrapeLog('error', "🗑️ حذف تلقائي: [$matchName] (ID: $matchId)");
                        
                        // Auto-delete the match and all related data
                        $db->deleteMatch($matchId);
                        
                        $db->addScrapeLog('success', "✓ تم حذف المباراة بنجاح من قاعدة البيانات.");
                        
                        // Skip all remaining steps for this match
                        $nextStep = 0;
                        $nextMatchIndex++;
                        break;
                    } else if ($urlStatus === 'no_internet') {
                        // Internet is down, don't touch anything
                        $db->addScrapeLog('warning', "🌐 لا يوجد اتصال بالإنترنت — لن يتم حذف أي مباراة. سيتم إعادة المحاولة لاحقاً.");
                    } else {
                        // URL is OK but scraper couldn't parse - data format issue
                        $db->addScrapeLog($logType, "ℹ️ الرابط يعمل لكن البيانات غير متاحة حالياً — سيتم إعادة المحاولة لاحقاً.");
                    }
                }
                break;


            case 2:
                if (empty($match['stadium_name']) || empty($match['channel']) || empty($match['details_match_time'])) {
                    $db->addScrapeLog($logType, "→ الخطوة القادمة: تحديث تفاصيل المباراة (ملعب، قنوات، وقت، نتيجة)...");
                } else {
                    $db->addScrapeLog('filter', "⚠ تخطي: تفاصيل المباراة الأساسية موجودة مسبقاً.");
                    $nextStep++; 
                }
                break;

            case 3:
                $details = $scraper->scrapeMatchDetails($matchId);
                if ($details['status'] === 'success') $db->addScrapeLog('success', "✓ تم جلب تفاصيل المباراة (الملعب، القنوات، الوقت الحالي، النتيجة).");
                else $db->addScrapeLog('error', "✗ فشل جلب تفاصيل المباراة الكاملة.");
                break;

            case 4:
                if ($isScheduled) {
                    $db->addScrapeLog('filter', "⚠ تخطي: الأرقام الفنية غير متاحة لمباراة لم تبدأ.");
                    $nextStep += 4; // Skip Events & Stats
                } else {
                    $db->addScrapeLog($logType, "→ الخطوة القادمة: تحديث أحداث المباراة...");
                }
                break;

            case 5:
                $events = $scraper->scrapeMatchEvents($matchId);
                if ($events['status'] === 'success') {
                    $cnt = count($events['events'] ?? []);
                    $db->addScrapeLog('success', "✓ تم تحديث سجل الأحداث (تم العثور على $cnt حدث).");
                } else {
                    $db->addScrapeLog('error', "✗ فشل جلب الأحداث.");
                }
                break;

            case 6:
                $db->addScrapeLog($logType, "→ الخطوة القادمة: تحديث الإحصائيات الفنية...");
                break;

            case 7:
                $stats = $scraper->scrapeMatchStatistics($matchId);
                if ($stats['status'] === 'success') $db->addScrapeLog('success', "✓ تمت مزامنة الإحصائيات.");
                else $db->addScrapeLog('error', "✗ الإحصائيات غير متوفرة حالياً.");
                break;

            case 8:
                if (empty($match['lineup_home']) || $isLive) {
                    $db->addScrapeLog($logType, "→ الخطوة القادمة: تحديث تشكيلة الفريقين...");
                } else {
                    $db->addScrapeLog('filter', "⚠ تخطي: التشكيلة موجودة مسبقاً.");
                    $nextStep++;
                }
                break;

            case 9:
                $lineup = $scraper->scrapeMatchLineup($matchId);
                if ($lineup['status'] === 'success') $db->addScrapeLog('success', "✓ تم استخراج التشكيلة بنجاح.");
                else $db->addScrapeLog('error', "✗ فشل جلب التشكيلة.");
                break;

            case 10:
                if (empty($match['standings_data'])) {
                    $db->addScrapeLog($logType, "→ الخطوة القادمة: تحديث جدول الترتيب...");
                } else {
                    $db->addScrapeLog('filter', "⚠ تخطي: جدول الترتيب موجود مسبقاً لهذه المباراة.");
                    $nextStep++;
                }
                break;

            case 11:
                $standings = $scraper->scrapeMatchStandings($matchId);
                if ($standings['status'] === 'success') $db->addScrapeLog('success', "✓ تم تحديث جدول الترتيب.");
                else $db->addScrapeLog('error', "✗ جدول الترتيب غير متوفر حالياً.");
                break;

            case 12:
                if ($isHeroMatch) {
                    $db->addScrapeLog('filter', "⚠ تخطي: وضع البطل لا يبحث عن روابط البث المباشر.");
                    $nextStep++;
                } else if (!empty($match['live_url']) || !empty($match['live_iframe'])) {
                    $db->addScrapeLog('filter', "⚠ تخطي: رابط البث موجود مسبقاً.");
                    $nextStep++;
                } else {
                    $attemptPlan = getLiveStreamAttemptPlan($match, $currentStepTime);
                    if (!empty($attemptPlan['done'])) {
                        $db->addScrapeLog('filter', "⚠ تخطي: {$attemptPlan['reason']}");
                        $nextStep++;
                    } else if (empty($attemptPlan['allowed'])) {
                        $db->addScrapeLog('filter', "⏳ تخطي مؤقت: {$attemptPlan['reason']}");
                        $nextStep++;
                    } else {
                        $attempts = $attemptPlan['attempt_number'];
                        $maxAttempts = $attemptPlan['max_attempts'];
                        $windowLabel = $attemptPlan['window_label'] ?? 'نافذة غير معروفة';
                        $db->addScrapeLog($logType, "→ الخطوة القادمة: البحث عن روبط البث المباشر (المحاولة $attempts/$maxAttempts - $windowLabel)...");
                    }
                }

                $postStepMatch = $db->getMatchById($matchId);
                if ($postStepMatch) {
                    if (autoPromoteMatchToLive($pdo, $postStepMatch, time())) {
                        $postStepMatch['status'] = 'Live';
                        $db->addScrapeLog($logType, "⏱️ تم تثبيت الحالة على مباشر لأن وقت البداية وصل، حتى لو تأخر تحديث المصدر لبضع لحظات.");
                    }
                    $match = $postStepMatch;
                }
                break;

            case 13:
                if ($isHeroMatch) {
                    $db->addScrapeLog('filter', "⚠ تخطي: تم تعطيل جلب البث المباشر داخل وضع البطل.");
                } else {
                    $attemptPlan = getLiveStreamAttemptPlan($match, $currentStepTime);
                    if (!empty($attemptPlan['done']) || empty($attemptPlan['allowed'])) {
                        $db->addScrapeLog('filter', "⏭️ تم تجاوز تنفيذ جلب البث لأن نافذته الحالية غير متاحة.");
                    } else {
                        $live = $scraper->scrapeMatchLive($matchId);
                        if ($live['status'] === 'success') {
                            $db->addScrapeLog('success', "✓ تم تحديث روابط البث المباشر.");
                            $pdo->prepare("UPDATE matches SET live_stream_failures = 0, live_stream_attempt_stage = 0 WHERE id = ?")->execute([$matchId]);
                        } else {
                            $db->addScrapeLog('error', "✗ لم يتم العثور على بث متاح.");
                            $nextStage = min(count(SMART_RUNNER_LIVE_STREAM_WINDOWS), ((int) ($attemptPlan['target_stage'] ?? 0)) + 1);
                            $pdo->prepare("UPDATE matches SET live_stream_failures = COALESCE(live_stream_failures, 0) + 1, live_stream_attempt_stage = ? WHERE id = ?")->execute([$nextStage, $matchId]);
                        }
                    }
                }
                break;

            case 14:
                $db->addScrapeLog('success', "🚀 $progressStr اكتملت جميع مراحل التحديث للمباراة.");
                
                // UPDATE VISIT TRACKING
                $isCompleted = 0;
                // Check if match is finished after scraping
                $updatedMatch = $db->getMatchById($matchId);
                if ($updatedMatch && ($updatedMatch['status'] === 'Finished' || $updatedMatch['status'] === 'إنتهت')) {
                    $isCompleted = 1;
                }
                
                // AUTO-COMPLETE PAST MATCHES: If it's from yesterday and we just finished all steps
                // even if status is still "Scheduled", we mark it completed and set status to "Postponed".
                if ($isCompleted === 0 && $updatedMatch && shouldForcePostponeStaleScheduledMatch($updatedMatch, $currentStepTime)) {
                    $isCompleted = 1; 
                    $pdo->prepare("UPDATE matches SET status = 'Postponed' WHERE id = ?")->execute([$matchId]);
                    $db->addScrapeLog('warning', "⚠ تنبيه: المباراة من تاريخ قديم ومازالت حالتها 'مجدولة'. تم تغيير الحالة إلى 'مؤجلة' وتعليمها كمكتملة.");
                }
                
                $upd = $pdo->prepare("UPDATE matches SET is_visited = 1, last_visited = UTC_TIMESTAMP(), is_completed = ? WHERE id = ?");
                $upd->execute([$isCompleted, $matchId]);
                
                $nextStep = 0;
                $nextMatchIndex++;
                break;
        }
    } catch (Exception $e) {
        $db->addScrapeLog('error', "✗ خطأ في الخطوة [$stepIndex]: " . $e->getMessage());
        
        // Even on error, mark as visited if we passed several steps or it's a critical error
        // to prevent infinite loops on failing matches
        if ($stepIndex > 3) {
             $pdo->prepare("UPDATE matches SET is_visited = 1, last_visited = UTC_TIMESTAMP() WHERE id = ?")->execute([$matchId]);
        }

        $nextStep = 0;
        $nextMatchIndex++;
    }

    if ($nextMatchIndex >= $total) {
        $nextMatchIndex = $total;
        $nextStep = 0;
    }

    echo json_encode([
        'status' => 'success',
        'logs' => $db->smartLogs,
        'next_index' => $nextMatchIndex,
        'next_step' => $nextStep,
        'next_interval' => 0,
        'server_time' => time()
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}
