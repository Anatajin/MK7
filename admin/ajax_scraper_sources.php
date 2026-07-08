<?php
require_once 'init.php';
require_once '../config.php';
require_once '../includes/Database.php';

$db = new Database($pdo);
header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'list':
            echo json_encode(['status' => 'success', 'data' => $db->getAllScraperSources()]);
            break;

        case 'save':
            $data = [
                'id' => $_POST['id'] ?? null,
                'name' => $_POST['name'] ?? '',
                'base_url' => $_POST['base_url'] ?? '',
                'matches_path' => $_POST['matches_path'] ?? '',
                'container_selector' => $_POST['container_selector'] ?? '',
                'teams_selector' => $_POST['teams_selector'] ?? '',
                'link_selector' => $_POST['link_selector'] ?? '',
                'live_link_selector' => $_POST['live_link_selector'] ?? '',
                'is_active' => isset($_POST['is_active']) ? 1 : 0
            ];
            $res = $db->saveScraperSource($data);
            echo json_encode(['status' => $res ? 'success' : 'error']);
            break;

        case 'delete':
            $id = $_POST['id'] ?? null;
            $res = $db->deleteScraperSource($id);
            echo json_encode(['status' => $res ? 'success' : 'error']);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
            break;
    }
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
