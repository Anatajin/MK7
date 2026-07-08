<?php
require_once __DIR__ . '/../config.php';

try {
    echo "Starting robust migration...\n";

    $cols = $pdo->query("DESCRIBE matches")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('is_visited', $cols)) {
        $pdo->exec("ALTER TABLE matches ADD COLUMN is_visited TINYINT(1) DEFAULT 0");
        echo "Added is_visited.\n";
    }
    if (!in_array('last_visited', $cols)) {
        $pdo->exec("ALTER TABLE matches ADD COLUMN last_visited DATETIME NULL");
        echo "Added last_visited.\n";
    }
    if (!in_array('is_completed', $cols)) {
        $pdo->exec("ALTER TABLE matches ADD COLUMN is_completed TINYINT(1) DEFAULT 0");
        echo "Added is_completed.\n";
    }

    echo "Migration finished successfully!\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
?>
