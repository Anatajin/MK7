<?php

function getAdminDashboardSnapshot(PDO $pdo, string $period = '24h', ?Database $db = null): array
{
    $db = $db ?: new Database($pdo);

    $stats = $db->getStats();
    $todayStats = $db->getTodayStats();
    $apiStats = $db->getApiUsageHistory($period);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM matches WHERE match_date = CURDATE() AND match_time >= CURTIME() AND match_time <= ADDTIME(CURTIME(), '00:45:00') AND status NOT IN ('Live', 'Finished', 'Postponed', 'Cancelled')");
    $stmt->execute();
    $soonMatchesCount = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT m.*,
               t1.name AS home_team,
               t1.logo_url AS home_logo,
               t2.name AS away_team,
               t2.logo_url AS away_logo
        FROM matches m
        JOIN teams t1 ON m.home_team_id = t1.id
        JOIN teams t2 ON m.away_team_id = t2.id
        WHERE m.match_date >= ? AND m.status != 'Finished'
        ORDER BY m.match_date ASC, m.match_time ASC
        LIMIT 2
    ");
    $stmt->execute([date('Y-m-d')]);
    $nextMatches = array_map(static function (array $match): array {
        return [
            'league_name' => $match['league_name'] ?? '',
            'date_label' => date('D d M Y', strtotime((string) ($match['match_date'] ?? 'now'))),
            'time_label' => !empty($match['details_match_time'])
                ? (string) $match['details_match_time']
                : date('H:i', strtotime((string) ($match['match_time'] ?? 'now'))),
            'home_team' => $match['home_team'] ?? '',
            'home_logo' => $match['home_logo'] ?? '',
            'away_team' => $match['away_team'] ?? '',
            'away_logo' => $match['away_logo'] ?? '',
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    $fifaRankings = $db->getFifaRankings(10);
    $allFifaRankings = $db->getFifaRankings();

    $distribution = [];
    $tables = ['matches', 'teams', 'players', 'news', 'leagues'];
    $totalSizeMB = 0.0;

    foreach ($tables as $table) {
        $stmt = $pdo->prepare("
            SELECT ROUND(((data_length + index_length) / 1024 / 1024), 2)
            FROM information_schema.TABLES
            WHERE table_schema = ? AND table_name = ?
        ");
        $stmt->execute([DB_NAME, $table]);
        $size = (float) ($stmt->fetchColumn() ?: 0);
        $distribution[$table] = $size;
        $totalSizeMB += $size;
    }

    $scrapeItems = [
        ['id' => 'matches', 'name' => 'مباريات اليوم', 'icon' => 'fa-futbol', 'pct' => $todayStats['total'] > 0 ? 100 : 0],
        ['id' => 'tomorrow', 'name' => 'مباريات الغد', 'icon' => 'fa-calendar-plus', 'pct' => $todayStats['tomorrow_matches'] > 0 ? 100 : 0],
        ['id' => 'next10', 'name' => 'مباريات 10 أيام', 'icon' => 'fa-calendar-alt', 'pct' => (int) round(($todayStats['next_10_days_coverage'] / 10) * 100)],
        ['id' => 'summaries', 'name' => 'تفاصيل المباريات', 'icon' => 'fa-info-circle', 'pct' => $todayStats['total'] > 0 ? (int) round(($todayStats['with_summary'] / $todayStats['total']) * 100) : 0],
        ['id' => 'lineups', 'name' => 'التشكيلات', 'icon' => 'fa-users', 'pct' => $todayStats['total'] > 0 ? (int) round(($todayStats['with_lineups'] / $todayStats['total']) * 100) : 0],
        ['id' => 'live', 'name' => 'روابط البث', 'icon' => 'fa-broadcast-tower', 'pct' => $todayStats['total'] > 0 ? (int) round(($todayStats['with_live'] / $todayStats['total']) * 100) : 0],
        ['id' => 'events', 'name' => 'أحداث المباريات', 'icon' => 'fa-history', 'pct' => $todayStats['total'] > 0 ? (int) round(($todayStats['with_events'] / $todayStats['total']) * 100) : 0],
        ['id' => 'standings', 'name' => 'جداول الترتيب', 'icon' => 'fa-table', 'pct' => $todayStats['total'] > 0 ? (int) round(($todayStats['with_standings'] / $todayStats['total']) * 100) : 0],
        ['id' => 'stats', 'name' => 'إحصائيات الفرق', 'icon' => 'fa-chart-pie', 'pct' => $todayStats['total'] > 0 ? (int) round(($todayStats['with_stats'] / $todayStats['total']) * 100) : 0],
        ['id' => 'previous_matches', 'name' => 'المواجهات السابقة', 'icon' => 'fa-exchange-alt', 'pct' => $todayStats['total'] > 0 ? (int) round(($todayStats['with_previous_matches'] / $todayStats['total']) * 100) : 0],
        ['id' => 'news', 'name' => 'الأخبار الرياضية', 'icon' => 'fa-newspaper', 'pct' => 100],
    ];

    $totalMatches = (int) $stats['total_matches'];
    $totalTeams = (int) $stats['teams_count'];
    $totalCountries = (int) $stats['countries_count'];
    $totalLeagues = (int) $stats['leagues_count'];
    $totalActiveCountries = (int) $pdo->query("SELECT COUNT(*) FROM countries WHERE is_active = 1")->fetchColumn();
    $totalActiveLeagues = (int) $pdo->query("SELECT COUNT(*) FROM leagues WHERE is_active = 1")->fetchColumn();

    $dailyGrowth = [];
    foreach (['matches', 'teams', 'countries', 'leagues'] as $table) {
        $stmt = $pdo->prepare("SELECT DATE(created_at) AS date, COUNT(*) AS count FROM {$table} WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY DATE(created_at)");
        $stmt->execute();
        $dailyGrowth[$table] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    $stmt = $pdo->prepare("SELECT DATE(activated_at) AS date, COUNT(*) AS count FROM countries WHERE is_active = 1 AND activated_at > DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY DATE(activated_at)");
    $stmt->execute();
    $dailyActiveCountries = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $stmt = $pdo->prepare("SELECT DATE(activated_at) AS date, COUNT(*) AS count FROM leagues WHERE is_active = 1 AND activated_at > DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY DATE(activated_at)");
    $stmt->execute();
    $dailyActiveLeagues = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $dbHistory = [];
    $daysAr = [
        'Sun' => 'الأحد',
        'Mon' => 'الاثنين',
        'Tue' => 'الثلاثاء',
        'Wed' => 'الأربعاء',
        'Thu' => 'الخميس',
        'Fri' => 'الجمعة',
        'Sat' => 'السبت',
    ];

    $currMatches = $totalMatches;
    $currTeams = $totalTeams;
    $currCountries = $totalCountries;
    $currLeagues = $totalLeagues;
    $currActiveCountries = $totalActiveCountries;
    $currActiveLeagues = $totalActiveLeagues;

    for ($i = 0; $i < 7; $i++) {
        $date = date('Y-m-d', strtotime("-{$i} days"));
        $dayEn = date('D', strtotime($date));
        $label = $daysAr[$dayEn] ?? $dayEn;

        $dbHistory[] = [
            'label' => $label,
            'matches' => $currMatches,
            'teams' => $currTeams,
            'countries' => $currCountries,
            'leagues' => $currLeagues,
            'active_countries' => $currActiveCountries,
            'active_leagues' => $currActiveLeagues,
        ];

        $currMatches -= (int) ($dailyGrowth['matches'][$date] ?? 0);
        $currTeams -= (int) ($dailyGrowth['teams'][$date] ?? 0);
        $currCountries -= (int) ($dailyGrowth['countries'][$date] ?? 0);
        $currLeagues -= (int) ($dailyGrowth['leagues'][$date] ?? 0);
        $currActiveCountries -= (int) ($dailyActiveCountries[$date] ?? 0);
        $currActiveLeagues -= (int) ($dailyActiveLeagues[$date] ?? 0);
    }

    $dbHistory = array_reverse($dbHistory);
    $countryStats = $db->getApiCountryStats();
    $top5TotalRequests = 0;

    foreach (array_slice($countryStats, 0, 5) as $stat) {
        $top5TotalRequests += (int) ($stat['count'] ?? 0);
    }

    return [
        'stats' => $stats,
        'today_stats' => $todayStats,
        'today_overview' => [
            'live' => (int) $stats['today_live'],
            'soon' => $soonMatchesCount,
            'scheduled' => (int) $stats['today_scheduled'],
            'finished' => (int) $stats['today_finished'],
        ],
        'soon_matches_count' => $soonMatchesCount,
        'next_matches' => $nextMatches,
        'fifa_rankings' => $fifaRankings,
        'all_fifa_rankings' => $allFifaRankings,
        'distribution' => $distribution,
        'distribution_total_mb' => round($totalSizeMB, 2),
        'scrape_items' => $scrapeItems,
        'db_history' => $dbHistory,
        'country_stats' => $countryStats,
        'top5_total_requests' => $top5TotalRequests,
        'api_stats' => $apiStats,
        'period' => $period,
        'server_time_utc' => gmdate('Y-m-d H:i:s'),
    ];
}
