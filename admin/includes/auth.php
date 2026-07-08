<?php
// admin/includes/auth.php

function getAdminRole() {
    return $_SESSION['admin_role'] ?? 'viewer'; // Default lowest privilege
}

function isAdmin() {
    return getAdminRole() === 'admin';
}

function isEditor() {
    // Both admin and editor count as 'editor' for purposes of "can they edit?"
    $role = getAdminRole();
    return $role === 'admin' || $role === 'editor';
}

function requireAdminAPI() {
    if (!isAdmin()) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'عذراً، هذه العملية تتطلب صلاحية مسؤول (Admin).']);
        exit;
    }
}

function requireEditorAPI() {
    if (!isEditor()) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'عذراً، هذه العملية تتطلب صلاحية محرر (Editor) على الأقل.']);
        exit;
    }
}
?>
