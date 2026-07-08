<?php
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once dirname(__DIR__) . '/config.php';
chdir(__DIR__);

$options = getopt('', ['match-index::', 'step-index::', 'client-id::']);
$matchIndex = isset($options['match-index']) ? (int) $options['match-index'] : 0;
$stepIndex = isset($options['step-index']) ? (int) $options['step-index'] : 0;
$clientId = trim((string) ($options['client-id'] ?? 'server_worker'));

if ($clientId === '') {
    $clientId = 'server_worker';
}

$stmt = $pdo->query("SELECT id FROM users WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
$userId = (int) ($stmt->fetchColumn() ?: 0);

if ($userId <= 0) {
    echo json_encode([
        'status' => 'error',
        'message' => 'No active admin user is available for Smart Runner CLI.'
    ], JSON_UNESCAPED_UNICODE);
    exit(1);
}

if (session_status() === PHP_SESSION_NONE) {
    session_id('smart_runner_cli');
    session_start();
}

define('SMART_RUNNER_INTERNAL', true);
define('SMART_RUNNER_INTERNAL_USER_ID', $userId);

$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_user_id'] = $userId;
$_SESSION['admin_role'] = 'admin';

$_GET = [
    'action' => 'run',
    'match_index' => $matchIndex,
    'step_index' => $stepIndex,
    'client_id' => $clientId
];

$_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/admin/smart_runner.php';
$_SERVER['REQUEST_URI'] = '/admin/smart_runner.php';
$_SERVER['HTTP_ACCEPT'] = 'application/json';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

include __DIR__ . '/smart_runner.php';
