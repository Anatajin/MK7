<?php
/**
 * AJAX handler for User Management (CRUD)
 * Handles: list, create, update, delete users
 */
require_once 'init.php';

// Only Admins can manage users!
// But wait, list is read-only. Maybe Viewer can view? 
// No, the UI hides the User Management section for non-admins to be safe, but let's just protect the actions.

$action = $_POST['action'] ?? $_GET['action'] ?? '';
if ($action !== 'heartbeat' && $action !== 'list') {
    requireAdminAPI();
}

header('Content-Type: application/json; charset=utf-8');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {

        case 'list':
            $stmt = $pdo->query("SELECT id, username_encrypted, role, is_active, last_login, login_attempts, locked_until, last_activity, created_at FROM users ORDER BY id ASC");
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $result = [];
            foreach ($users as $user) {
                $decrypted = sport_decrypt_username($user['username_encrypted']) ?: '???';
                
                // Determine online status (active within last 5 minutes)
                $isOnline = false;
                if ($user['last_activity']) {
                    $lastActivity = strtotime($user['last_activity']);
                    $isOnline = (time() - $lastActivity) < 60; // 1 minute threshold
                }

                $result[] = [
                    'id' => $user['id'],
                    'username' => $decrypted,
                    'role' => $user['role'],
                    'is_active' => (int)$user['is_active'],
                    'is_online' => $isOnline,
                    'last_login' => $user['last_login'],
                    'last_activity' => $user['last_activity'],
                    'login_attempts' => (int)$user['login_attempts'],
                    'locked_until' => $user['locked_until'],
                    'created_at' => $user['created_at']
                ];
            }

            echo json_encode(['status' => 'success', 'data' => $result], JSON_UNESCAPED_UNICODE);
            break;

        case 'create':
            $username = trim($_POST['username'] ?? '');
            $password = trim($_POST['password'] ?? '');
            $role = $_POST['role'] ?? 'admin';

            if (empty($username) || empty($password)) {
                echo json_encode(['status' => 'error', 'message' => 'اسم المستخدم وكلمة المرور مطلوبان']);
                exit;
            }

            // Check if user already exists
            $hash = sport_username_hash($username);
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username_hash = ?");
            $stmt->execute([$hash]);
            if ($stmt->fetch()) {
                echo json_encode(['status' => 'error', 'message' => 'اسم المستخدم موجود مسبقاً']);
                exit;
            }

            $encryptedUsername = sport_encrypt_username($username);
            $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

            $stmt = $pdo->prepare("INSERT INTO users (username_encrypted, username_hash, password_hash, role, is_active) VALUES (?, ?, ?, ?, 1)");
            $stmt->execute([$encryptedUsername, $hash, $passwordHash, $role]);

            echo json_encode(['status' => 'success', 'message' => 'تم إنشاء المستخدم بنجاح']);
            break;

        case 'update':
            $userId = (int)($_POST['user_id'] ?? 0);
            $newUsername = trim($_POST['username'] ?? '');
            $newPassword = trim($_POST['password'] ?? '');
            $newRole = $_POST['role'] ?? '';
            $isActive = isset($_POST['is_active']) ? (int)$_POST['is_active'] : null;

            if (!$userId) {
                echo json_encode(['status' => 'error', 'message' => 'معرف المستخدم مطلوب']);
                exit;
            }

            // Prevent editing yourself out of admin
            if ($userId == ($_SESSION['admin_user_id'] ?? 0) && $isActive === 0) {
                echo json_encode(['status' => 'error', 'message' => 'لا يمكنك تعطيل حسابك الحالي']);
                exit;
            }

            $updates = [];
            $params = [];

            if (!empty($newUsername)) {
                $hash = sport_username_hash($newUsername);
                // Check if username is taken by another user
                $stmt = $pdo->prepare("SELECT id FROM users WHERE username_hash = ? AND id != ?");
                $stmt->execute([$hash, $userId]);
                if ($stmt->fetch()) {
                    echo json_encode(['status' => 'error', 'message' => 'اسم المستخدم مستخدم بالفعل']);
                    exit;
                }
                $encryptedUsername = sport_encrypt_username($newUsername);
                $updates[] = "username_encrypted = ?";
                $updates[] = "username_hash = ?";
                $params[] = $encryptedUsername;
                $params[] = $hash;
            }

            if (!empty($newPassword)) {
                $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
                $updates[] = "password_hash = ?";
                $params[] = $passwordHash;
            }

            if (!empty($newRole)) {
                $updates[] = "role = ?";
                $params[] = $newRole;
            }

            if ($isActive !== null) {
                $updates[] = "is_active = ?";
                $params[] = $isActive;
            }

            // Reset lock if editing
            $updates[] = "login_attempts = 0";
            $updates[] = "locked_until = NULL";

            if (!empty($updates)) {
                $params[] = $userId;
                $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            }

            echo json_encode(['status' => 'success', 'message' => 'تم تحديث المستخدم بنجاح']);
            break;

        case 'delete':
            $userId = (int)($_POST['user_id'] ?? 0);

            if (!$userId) {
                echo json_encode(['status' => 'error', 'message' => 'معرف المستخدم مطلوب']);
                exit;
            }

            // Prevent deleting yourself
            if ($userId == ($_SESSION['admin_user_id'] ?? 0)) {
                echo json_encode(['status' => 'error', 'message' => 'لا يمكنك حذف حسابك الحالي']);
                exit;
            }

            // Check if this is the last admin
            $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1");
            $adminCount = $stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();

            if ($user && $user['role'] === 'admin' && $adminCount <= 1) {
                echo json_encode(['status' => 'error', 'message' => 'لا يمكن حذف آخر مسؤول في النظام']);
                exit;
            }

            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$userId]);

            echo json_encode(['status' => 'success', 'message' => 'تم حذف المستخدم بنجاح']);
            break;

        case 'heartbeat':
            // Update last_activity for current session
            $userId = $_SESSION['admin_user_id'] ?? 0;
            if ($userId) {
                $stmt = $pdo->prepare("UPDATE users SET last_activity = NOW() WHERE id = ?");
                $stmt->execute([$userId]);
            }
            echo json_encode(['status' => 'success']);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'خطأ في النظام: ' . $e->getMessage()]);
}
?>
