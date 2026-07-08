<?php
// admin/init.php - Global init and RBAC enforcer
$isInternalSmartRunnerCli = (
    php_sapi_name() === 'cli' &&
    defined('SMART_RUNNER_INTERNAL') &&
    SMART_RUNNER_INTERNAL === true
);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($isInternalSmartRunnerCli) {
    $_SESSION['admin_logged_in'] = true;
    if (defined('SMART_RUNNER_INTERNAL_USER_ID')) {
        $_SESSION['admin_user_id'] = SMART_RUNNER_INTERNAL_USER_ID;
    }
    $_SESSION['admin_role'] = $_SESSION['admin_role'] ?? 'admin';
}

if (
    !$isInternalSmartRunnerCli &&
    (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true)
) {
    // Improved AJAX/API detection for VPS/Production to ensure JSON responses are never broken by HTML errors
    $isJsonRequest = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') || 
                     (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) ||
                     (isset($_GET['ajax']) || isset($_POST['ajax'])) ||
                     strpos($_SERVER['SCRIPT_NAME'], 'ajax_') !== false ||
                     strpos($_SERVER['SCRIPT_NAME'], 'smart_runner.php') !== false ||
                     strpos($_SERVER['SCRIPT_NAME'], '/api/') !== false;

    if ($isJsonRequest) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'غير مصرح - الرجاء تسجيل الدخول']);
        exit;
    }
    header("Location: login.php");
    exit;
}

// Ensure the user actually exists and is still active in the database
require_once dirname(__DIR__) . '/config.php';
try {
    $userId = $_SESSION['admin_user_id'] ?? 0;
    $stmt = $pdo->prepare("SELECT is_active FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $userCheck = $stmt->fetch();
    
    if (!$userCheck || $userCheck['is_active'] == 0) {
        // Force logout if user is deleted or disabled
        session_destroy();
        if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'تم إنهاء الجلسة']);
            exit;
        }
        header("Location: login.php");
        exit;
    }
} catch (Exception $e) {
    // Failsafe
}

require_once __DIR__ . '/includes/auth.php';

// Global RBAC Enforcer for POST actions (Forms and AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && basename($_SERVER['PHP_SELF']) !== 'ajax_users.php') {
    $action = strtolower($_POST['action'] ?? '');
    $isDelete = false;
    
    // Auto-detect delete/remove/clear/reset from POST keys or action value
    foreach ($_POST as $key => $val) {
        $lkey = strtolower($key);
        if (strpos($lkey, 'delete') !== false || strpos($lkey, 'remove') !== false || strpos($lkey, 'clear') !== false || strpos($lkey, 'reset') !== false) {
            $isDelete = true;
            break;
        }
    }
    if (strpos($action, 'delete') !== false || strpos($action, 'remove') !== false || strpos($action, 'clear') !== false || strpos($action, 'reset') !== false) {
        $isDelete = true;
    }

    if (!isEditor()) { // Viewer
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'عذراً، صلاحيات المشاهد لا تسمح بإجراء تعديلات.']);
        exit;
    }

    if (!isAdmin() && $isDelete) { // Editor trying to delete
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'عذراً، الحذف يتطلب صلاحيات مسؤول (Admin).']);
        exit;
    }
}

// Force enable error reporting for admin area AFTER config.php is loaded
// But suppress display_errors for AJAX/API requests to avoid breaking JSON
// We use more robust detection here
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$isAjaxOrAPI = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') || 
               (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) ||
               strpos($scriptName, 'ajax_') !== false || 
               strpos($scriptName, 'smart_runner.php') !== false ||
               strpos($scriptName, '/api/') !== false;

error_reporting(E_ALL);
if ($isAjaxOrAPI) {
    ini_set('display_errors', 0);
    ini_set('log_errors', 1); // Ensure errors are still logged to server logs
} else {
    ini_set('display_errors', 1);
}
?>
