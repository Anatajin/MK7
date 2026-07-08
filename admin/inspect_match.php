<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$id = 128; // The ID from the dump

$stmt = $pdo->prepare("SELECT * FROM matches WHERE id = ?");
$stmt->execute([$id]);
$match = $stmt->fetch(PDO::FETCH_ASSOC);

echo "MATCH $id: Time: {$match['match_time']} | Status: {$match['status']} | Visited: {$match['is_visited']} | Completed: {$match['is_completed']}\n";

// Check current server time
echo "\nMySQL NOW(): " . $pdo->query("SELECT NOW()")->fetchColumn() . "\n";
echo "PHP date(): " . date('Y-m-d H:i:s') . "\n";
echo "PHP timestamp: " . time() . "\n";
?>
