<?php
// admin/debug_runner.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'init.php'; // This might still redirect if not CLI, but let's see.
require_once '../config.php';
require_once '../includes/Database.php';
require_once '../includes/Scraper.php';

$db = new Database($pdo);
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));

echo "Checking matches for $today and $yesterday...\n";

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM matches 
                           WHERE (match_date = ? OR match_date = ?) 
                           AND is_visited = 0 
                           AND status != 'Finished' 
                           AND status != 'إنتهت'");
    $stmt->execute([$yesterday, $today]);
    $count = $stmt->fetchColumn();
    echo "Found $count Hero Mode matches.\n";
    
    $stmt = $pdo->prepare("SELECT * FROM matches 
                           WHERE (match_date = ? OR match_date = ?) 
                           AND is_visited = 0 
                           AND status != 'Finished' 
                           AND status != 'إنتهت'
                           LIMIT 5");
    $stmt->execute([$yesterday, $today]);
    $matches = $stmt->fetchAll();
    foreach ($matches as $m) {
        echo "Match: [{$m['id']}] {$m['home_team_id']} vs {$m['away_team_id']} ({$m['match_date']})\n";
    }
    echo "Success!\n";

} catch (PDOException $e) {
    echo "SQL ERROR: " . $e->getMessage() . "\n";
}
?>
