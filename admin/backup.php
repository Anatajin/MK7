<?php
session_start();
require_once 'init.php';
require_once '../config.php';

// Simple DB Backup Script
function backupDatabase($host, $user, $pass, $name) {
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$name;charset=utf8", $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $tables = [];
        $result = $pdo->query("SHOW TABLES");
        while ($row = $result->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }

        $return = "-- Koora AI Database Backup\n";
        $return .= "-- Date: " . date('Y-m-d H:i:s') . "\n\n";
        $return .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ($tables as $table) {
            // Create Table
            $result = $pdo->query("SHOW CREATE TABLE $table");
            $row = $result->fetch(PDO::FETCH_NUM);
            $return .= "\n\n" . $row[1] . ";\n\n";

            // Insert Data
            $result = $pdo->query("SELECT * FROM $table");
            $num_fields = $result->columnCount();

            while ($row = $result->fetch(PDO::FETCH_NUM)) {
                $return .= "INSERT INTO $table VALUES(";
                for ($j = 0; $j < $num_fields; $j++) {
                    if (isset($row[$j])) {
                        $row[$j] = addslashes($row[$j]);
                        $row[$j] = str_replace("\n", "\\n", $row[$j]);
                        $return .= '"' . $row[$j] . '"';
                    } else {
                        $return .= 'NULL';
                    }
                    if ($j < ($num_fields - 1)) {
                        $return .= ',';
                    }
                }
                $return .= ");\n";
            }
            $return .= "\n\n\n";
        }

        $return .= "SET FOREIGN_KEY_CHECKS=1;";

        // Output as file
        $filename = 'db-backup-' . date('Y-m-d-H-i-s') . '.sql';
        header('Content-Type: application/octet-stream');
        header("Content-Transfer-Encoding: Binary");
        header("Content-disposition: attachment; filename=\"" . $filename . "\"");
        echo $return;
        exit;

    } catch (Exception $e) {
        die("Error during backup: " . $e->getMessage());
    }
}

if (isset($_GET['run'])) {
    backupDatabase(DB_HOST, DB_USER, DB_PASS, DB_NAME);
} else {
    header('Location: settings.php');
}
?>
