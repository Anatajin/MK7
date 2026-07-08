<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';

$db = new Database($pdo);
$today = date('Y-m-d');
$matches = $db->getMatches($today);

echo "TODAY'S MATCHES ($today):\n";
foreach ($matches as $m) {
    echo "ID: {$m['id']} | {$m['home_team']} vs {$m['away_team']} | Time: {$m['match_time']} | Status: {$m['status']} | Visited: {$m['is_visited']}\n";
}
?>
