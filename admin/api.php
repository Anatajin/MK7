<?php
session_start();
require_once 'init.php';
require_once '../config.php';
require_once '../includes/Database.php';

$db = new Database($pdo);

// معالجة الإجراءات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'generate') {
        $name = $_POST['name'] ?? 'New API Key';
        $allowedOriginId = !empty($_POST['allowed_origin_id']) ? $_POST['allowed_origin_id'] : null;
        try {
            $newKey = $db->generateApiKey($name, $allowedOriginId);
            $success = "تم إنشاء مفتاح API جديد بنجاح!";
        } catch (Exception $e) {
            $error = "خطأ في إنشاء المفتاح: " . $e->getMessage();
        }
    } elseif ($action === 'delete') {
        $id = $_POST['id'] ?? 0;
        try {
            $db->deleteApiKey($id);
            $success = "تم حذف المفتاح بنجاح!";
        } catch (Exception $e) {
            $error = "خطأ في حذف المفتاح: " . $e->getMessage();
        }
    } elseif ($action === 'toggle') {
        $id = $_POST['id'] ?? 0;
        $isActive = $_POST['is_active'] ?? 0;
        try {
            $db->toggleApiKey($id, $isActive);
            $success = "تم تحديث حالة المفتاح بنجاح!";
        } catch (Exception $e) {
            $error = "خطأ في تحديث المفتاح: " . $e->getMessage();
        }
    } elseif ($action === 'update_api_settings') {
        try {
            $cors = isset($_POST['cors_enabled']) ? '1' : '0';
            $keyReq = isset($_POST['api_key_required']) ? '1' : '0';
            $allowAllOrigins = isset($_POST['allow_all_origins']) ? '1' : '0';
            $enforceForwardedVisitorIp = isset($_POST['enforce_forwarded_visitor_ip']) ? '1' : '0';
            
            $db->updateApiSetting('cors_enabled', $cors);
            $db->updateApiSetting('api_key_required', $keyReq);
            $db->updateApiSetting('allow_all_origins', $allowAllOrigins);
            $db->updateApiSetting('enforce_forwarded_visitor_ip', $enforceForwardedVisitorIp);
            
            // Methods
            $methods = $_POST['methods'] ?? [];
            $db->updateApiSetting('allowed_methods', implode(', ', $methods));
            
            // Headers
            $headers = $_POST['headers'] ?? [];
            $db->updateApiSetting('allowed_headers', implode(', ', $headers));
            
            $success = "تم تحديث إعدادات API بنجاح!";
        } catch (Exception $e) {
            $error = "خطأ في تحديث الإعدادات: " . $e->getMessage();
        }
    } elseif ($action === 'add_origin') {
        $origin = trim($_POST['origin'] ?? '');
        $planType = $_POST['plan_type'] ?? 'free';
        if (!empty($origin)) {
            $db->addAllowedOrigin($origin, $planType);
            $success = "تم إضافة النطاق بنجاح!";
        }
    } elseif ($action === 'delete_origin') {
        $id = $_POST['id'] ?? 0;
        $db->deleteAllowedOrigin($id);
        $success = "تم حذف النطاق!";
    } elseif ($action === 'toggle_origin') {
        $id = $_POST['id'] ?? 0;
        $status = $_POST['is_active'] ?? 0;
        $db->toggleAllowedOrigin($id, $status);
        $success = "تم تحديث حالة النطاق!";
    } elseif ($action === 'edit_origin') {
        $id = $_POST['id'] ?? 0;
        $origin = trim($_POST['origin'] ?? '');
        $planType = $_POST['plan_type'] ?? null;
        if (!empty($origin)) {
            $db->updateAllowedOrigin($id, $origin, $planType);
            $success = "تم تعديل النطاق بنجاح!";
        }
    } elseif ($action === 'renew_origin') {
        $id = $_POST['id'] ?? 0;
        if ($db->renewAllowedOrigin($id)) {
            $success = "تم تجديد الاشتراك بنجاح!";
        } else {
            $error = "فشل تجديد الاشتراك.";
        }
    }
}

$apiSettings = $db->getApiSettings();
$allowedOrigins = $db->getAllowedOrigins();

try {
    $apiKeys = $db->getApiKeys();
} catch (Exception $e) {
    $apiKeys = [];
    $error = "خطأ في جلب المفاتيح: " . $e->getMessage() . " - يرجى التأكد من إنشاء جدول api_keys";
}

$normalizeOwnerDisplay = static function ($value, string $fallback = 'غير مرتبط'): string {
    $normalized = trim((string)$value);
    return $normalized !== '' ? $normalized : $fallback;
};

$linkedOwnerIds = [];
foreach ($allowedOrigins as $originRow) {
    $ownerUserId = (int)($originRow['owner_user_id'] ?? 0);
    if ($ownerUserId > 0) {
        $linkedOwnerIds[$ownerUserId] = true;
    }
}
$hasMultipleOwnersInOrigins = count($linkedOwnerIds) > 1;

$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
$apiEndpoint = $baseUrl . dirname($_SERVER['PHP_SELF'], 2) . '/api/get';

$pageTitle = 'إدارة API';
require_once 'includes/header.php';
?>
<script>
    if (window.history.replaceState) {
        window.history.replaceState(null, null, window.location.href);
    }
</script>

<style>
    /* Modern Custom Select Styling */
    .custom-plan-select {
        position: relative;
        width: 100%;
    }
    
    .custom-plan-trigger {
        width: 100%;
        padding: 10px 15px;
        border-radius: 12px;
        font-size: 0.9rem;
        cursor: pointer;
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: #ffffff;
        border: 1px solid #dbe3e9;
        color: var(--text-primary);
        transition: all 0.3s;
    }
    
    .custom-plan-trigger:hover {
        border-color: var(--accent);
        background: #f8fafc;
    }
    
    .custom-plan-dropdown {
        display: none;
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: #ffffff;
        border: 1px solid #dbe3e9;
        border-radius: 12px;
        margin-top: 5px;
        box-shadow: 0 10px 25px rgba(0,0,0,0.08);
        overflow: hidden;
        z-index: 1000;
        min-width: 150px;
    }
    
    .plan-option {
        padding: 10px 15px;
        cursor: pointer;
        color: var(--text-primary);
        font-size: 0.85rem;
        display: flex;
        align-items: center;
        gap: 10px;
        border-bottom: 1px solid rgba(0,0,0,0.05);
        transition: all 0.2s;
    }
    
    .plan-option:last-child {
        border-bottom: none;
    }
    
    .plan-option:hover {
        background: rgba(16, 185, 129, 0.05);
        color: var(--accent);
    }
    
    .plan-option i {
        font-size: 0.8rem;
        width: 14px;
        text-align: center;
    }

    /* Small version for edit row */
    .custom-plan-trigger-sm {
        height: 32px;
        padding: 0 10px;
        font-size: 0.8rem;
        border-radius: 10px;
    }

    .api-key-toolbar {
        display: flex;
        align-items: center;
        gap: 12px;
        width: min(100%, 430px);
        direction: ltr;
    }

    .api-key-search {
        position: relative;
        flex: 1;
        min-width: 0;
    }

    .api-key-add-btn {
        width: 48px;
        height: 48px;
        border: none;
        border-radius: 14px;
        background: linear-gradient(135deg, #3b82f6, #2563eb);
        color: #ffffff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        box-shadow: 0 16px 35px rgba(37, 99, 235, 0.22);
        transition: transform 0.25s ease, box-shadow 0.25s ease, filter 0.25s ease;
        flex-shrink: 0;
    }

    .api-key-add-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 20px 40px rgba(37, 99, 235, 0.28);
        filter: brightness(1.03);
    }

    .api-key-add-btn i {
        font-size: 1rem;
    }

    .api-key-modal {
        position: fixed;
        inset: 0;
        width: 100vw;
        height: 100vh;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 24px;
        z-index: 20000;
    }

    .api-key-modal.is-open {
        display: flex;
    }

    .api-key-modal-backdrop {
        position: absolute;
        inset: 0;
        background: rgba(15, 23, 42, 0.26);
        backdrop-filter: blur(12px);
    }

    .api-key-modal-panel {
        position: relative;
        width: min(760px, 100%);
        background: rgba(255, 255, 255, 0.88);
        border: 1px solid rgba(255, 255, 255, 0.7);
        border-radius: 28px;
        padding: 28px;
        box-shadow: 0 30px 80px rgba(15, 23, 42, 0.18);
        backdrop-filter: blur(18px);
        z-index: 1;
    }

    .api-key-modal-close {
        position: absolute;
        top: 18px;
        left: 18px;
        width: 40px;
        height: 40px;
        border: none;
        border-radius: 12px;
        background: rgba(148, 163, 184, 0.12);
        color: #475569;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: background 0.2s ease, color 0.2s ease, transform 0.2s ease;
    }

    .api-key-modal-close:hover {
        background: rgba(239, 68, 68, 0.12);
        color: #ef4444;
        transform: rotate(90deg);
    }

    .api-key-modal-header {
        margin-bottom: 24px;
        padding-left: 42px;
    }

    .api-key-modal-title {
        margin: 0 0 8px;
        font-size: 1.35rem;
        color: var(--text-primary);
    }

    .api-key-modal-subtitle {
        margin: 0;
        color: var(--text-secondary);
        font-size: 0.92rem;
        line-height: 1.8;
    }

    .api-key-modal-form {
        display: grid;
        grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr);
        gap: 18px;
        align-items: end;
    }

    .api-key-modal-field label {
        display: block;
        margin-bottom: 8px;
        font-size: 0.9rem;
        color: var(--text-secondary);
    }

    .api-key-modal-field .form-control,
    .api-key-modal-field .custom-select-trigger,
    .api-key-modal-field .custom-plan-trigger {
        height: 52px;
        background: rgba(255, 255, 255, 0.8);
    }

    .api-key-modal-field .custom-select-wrapper {
        width: 100%;
    }

    .api-key-modal-field .custom-plan-select {
        width: 100%;
    }

    .api-key-modal-field .custom-select-trigger,
    .api-key-modal-field .custom-plan-trigger {
        width: 100%;
        padding: 0 18px;
        border-radius: 18px;
        border: 1px solid #dbe3e9;
        display: flex;
        align-items: center;
        justify-content: space-between;
        color: var(--text-primary);
        transition: border-color 0.25s ease, background 0.25s ease, box-shadow 0.25s ease;
    }

    .api-key-modal-field .custom-select-trigger:hover,
    .api-key-modal-field .custom-plan-trigger:hover {
        background: rgba(255, 255, 255, 0.95);
        border-color: #cbd5e1;
    }

    .api-key-modal-field .custom-select-trigger:focus-within,
    .api-key-modal-field .custom-plan-trigger:focus-within {
        border-color: rgba(59, 130, 246, 0.45);
        box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.08);
    }

    .api-key-modal-actions {
        grid-column: 1 / -1;
        display: flex;
        justify-content: flex-start;
        gap: 12px;
        margin-top: 4px;
    }

    .api-key-modal-note {
        grid-column: 1 / -1;
        margin: -4px 0 0;
        color: #64748b;
        font-size: 0.82rem;
    }

    @media (max-width: 900px) {
        .api-key-modal-form {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 640px) {
        .api-key-toolbar {
            width: 100%;
        }

        .api-key-modal {
            padding: 16px;
        }

        .api-key-modal-panel {
            padding: 22px 18px;
            border-radius: 24px;
        }

        .api-key-modal-header {
            padding-left: 28px;
        }
    }
</style>

<div class="dashboard-grid" style="grid-template-columns: 1fr;">
    <?php if (isset($success)): ?>
        <div id="apiSuccessMsg" style="background: rgba(16, 185, 129, 0.1); border: 1px solid #10b981; color: #10b981; margin-bottom: 20px; padding: 15px; padding-right: 20px; width: 100%; border-radius: 12px; font-weight: 600; font-size: 0.95rem; display: flex; align-items: center; gap: 10px;">
            <i class="fas fa-check-circle" style="font-size: 1.2rem;"></i> <span><?= $success ?></span>
        </div>
        <script>
            setTimeout(function() {
                var el = document.getElementById('apiSuccessMsg');
                if(el) { el.style.transition = 'opacity 0.4s ease'; el.style.opacity = '0'; setTimeout(() => el.remove(), 400); }
            }, 2000);
        </script>
    <?php endif; ?>
    
    <?php if (isset($error)): ?>
        <div style="background: rgba(239, 68, 68, 0.1); border: 1px solid #ef4444; color: #ef4444; margin-bottom: 20px; padding: 15px; padding-right: 20px; width: 100%; border-radius: 12px; font-weight: 600; font-size: 0.95rem; display: flex; align-items: center; gap: 10px;">
            <i class="fas fa-exclamation-triangle" style="font-size: 1.2rem;"></i> <span><?= $error ?></span>
        </div>
    <?php endif; ?>
    
    <?php if (isset($newKey)): ?>
        <div id="newApiKeyContainer" style="background: var(--card-bg, #ffffff); padding: 25px; border-radius: 12px; margin-bottom: 0px; position: relative; box-shadow: var(--shadow, 0 4px 6px rgba(0,0,0,0.05));">
            <button onclick="document.getElementById('newApiKeyContainer').style.display='none'" style="position: absolute; left: 15px; top: 15px; background: transparent; color: var(--text-secondary); border: none; font-size: 1.2rem; cursor: pointer; transition: 0.2s;" title="إغلاق">
                <i class="fas fa-times"></i>
            </button>
            <div style="display: flex; align-items: flex-start; gap: 15px; margin-bottom: 20px;">
                <div style="background: rgba(16, 185, 129, 0.1); color: #10b981; width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; flex-shrink: 0;">
                    <i class="fas fa-check"></i>
                </div>
                <div>
                    <h3 style="margin: 0 0 8px 0; color: var(--text-primary); font-size: 1.2rem; font-weight: bold;">تم إنشاء مفتاح API بنجاح!</h3>
                    <p style="margin: 0 0 5px 0; color: var(--text-secondary); font-size: 0.95rem;">هذا هو المفتاح الخاص بك. يرجى توخي الحذر عند مشاركته أو استخدامه.</p>
                    <p style="margin: 0; color: #ef4444; font-size: 0.85rem; font-weight: 600;"><i class="fas fa-exclamation-triangle" style="margin-left: 5px;"></i>تنبيه: لن نتمكن من عرض هذا المفتاح لك مرة أخرى لاحقاً لأسباب أمنية. انسخه الآن!</p>
                </div>
            </div>
            
            <div style="background: rgba(0, 0, 0, 0.02); border: 1px solid var(--border-color, #e2e8f0); border-radius: 12px; display: flex; align-items: center; padding: 6px; margin-top: 15px;">
                <div id="newApiKeyValue" style="padding: 10px 15px; font-family: monospace; font-size: 1.1rem; color: var(--text-primary); letter-spacing: 0.5px; word-break: break-all; flex: 1;">
                    <?= $newKey ?>
                </div>
                <button onclick="copyToClipboard('newApiKeyValue', this)" class="btn-primary" style="background-color: #10b981; border: none; padding: 10px 25px; display: flex; align-items: center; justify-content: center; gap: 8px; border-radius: 8px; font-weight: 600; box-shadow: 0 2px 4px rgba(16, 185, 129, 0.2);" title="نسخ المفتاح">
                    <i class="fas fa-copy"></i>
                    نسخ
                </button>
            </div>
        </div>
    <?php endif; ?>

    <div class="card" style="margin-bottom: 25px;">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; gap: 15px; flex-wrap: wrap;">
            <h3 class="card-title">إعدادات CORS والأمان</h3>
            <span class="badge badge-info">API Control Panel</span>
        </div>
        <form method="POST" style="padding: 20px;" class="rbac-editor">
            <input type="hidden" name="action" value="update_api_settings">
            
            <!-- Main Toggles -->
            <div style="display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 20px; margin-bottom: 30px;">
                <div style="background: #f8fafc; min-height: 84px; padding: 18px; border-radius: 12px; border: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; gap: 16px;">
                    <div>
                        <h4 style="margin: 0; font-size: 0.9rem; color: var(--text-primary);">تفعيل CORS</h4>
                        <p style="margin: 6px 0 0; font-size: 0.78rem; color: var(--text-secondary);">
                            الحالة:
                            <span style="color: <?= ($apiSettings['cors_enabled'] ?? '1') == '1' ? '#10b981' : '#ef4444' ?>; font-weight: 700;">
                                <?= ($apiSettings['cors_enabled'] ?? '1') == '1' ? 'مفعل' : 'مغلق' ?>
                            </span>
                        </p>
                    </div>
                    <label class="switch">
                        <input type="checkbox" name="cors_enabled" <?= ($apiSettings['cors_enabled'] ?? '1') == '1' ? 'checked' : '' ?>>
                        <span class="slider round"></span>
                    </label>
                </div>

                <div style="background: #f8fafc; min-height: 84px; padding: 18px; border-radius: 12px; border: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; gap: 16px;">
                    <div>
                        <h4 style="margin: 0; font-size: 0.9rem; color: var(--text-primary);">فرض مفتاح API</h4>
                        <p style="margin: 6px 0 0; font-size: 0.78rem; color: var(--text-secondary);">
                            الحالة:
                            <span style="color: <?= ($apiSettings['api_key_required'] ?? '1') == '1' ? '#10b981' : '#ef4444' ?>; font-weight: 700;">
                                <?= ($apiSettings['api_key_required'] ?? '1') == '1' ? 'مفعل' : 'مغلق' ?>
                            </span>
                        </p>
                    </div>
                    <label class="switch">
                        <input type="checkbox" name="api_key_required" <?= ($apiSettings['api_key_required'] ?? '1') == '1' ? 'checked' : '' ?>>
                        <span class="slider round"></span>
                    </label>
                </div>

                <div style="background: rgba(16, 185, 129, 0.05); min-height: 84px; padding: 18px; border-radius: 12px; border: 1px dashed #10b981; display: flex; justify-content: space-between; align-items: center; gap: 16px;">
                    <div>
                        <h4 style="margin: 0; font-size: 0.9rem; color: #065f46;">السماح للكل (Dev Mode)</h4>
                        <p style="margin: 6px 0 0; font-size: 0.78rem; color: var(--text-secondary);">
                            الحالة:
                            <span style="color: <?= ($apiSettings['allow_all_origins'] ?? '0') == '1' ? '#10b981' : '#ef4444' ?>; font-weight: 700;">
                                <?= ($apiSettings['allow_all_origins'] ?? '0') == '1' ? 'مفعل' : 'مغلق' ?>
                            </span>
                        </p>
                    </div>
                    <label class="switch">
                        <input type="checkbox" name="allow_all_origins" <?= ($apiSettings['allow_all_origins'] ?? '0') == '1' ? 'checked' : '' ?>>
                        <span class="slider round"></span>
                    </label>
                </div>

                <div style="background: #f8fafc; min-height: 84px; padding: 18px; border-radius: 12px; border: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; gap: 16px;">
                    <div>
                        <h4 style="margin: 0; font-size: 0.9rem; color: var(--text-primary);">فرض IP الزائر الحقيقي</h4>
                        <p style="margin: 6px 0 0; font-size: 0.78rem; color: var(--text-secondary);">
                            الحالة:
                            <span style="color: <?= ($apiSettings['enforce_forwarded_visitor_ip'] ?? '1') == '1' ? '#10b981' : '#ef4444' ?>; font-weight: 700;">
                                <?= ($apiSettings['enforce_forwarded_visitor_ip'] ?? '1') == '1' ? 'مفعل' : 'مغلق' ?>
                            </span>
                        </p>
                    </div>
                    <label class="switch">
                        <input type="checkbox" name="enforce_forwarded_visitor_ip" <?= ($apiSettings['enforce_forwarded_visitor_ip'] ?? '1') == '1' ? 'checked' : '' ?>>
                        <span class="slider round"></span>
                    </label>
                </div>
            </div>

            <?php if (($apiSettings['allow_all_origins'] ?? '0') == '1' && ($apiSettings['enforce_forwarded_visitor_ip'] ?? '1') == '1'): ?>
            <div style="background: rgba(59, 130, 246, 0.08); border: 1px solid rgba(59, 130, 246, 0.18); padding: 15px; border-radius: 12px; margin-bottom: 25px; color: #1e3a8a; display: flex; align-items: flex-start; gap: 12px;">
                <i class="fas fa-info-circle" style="font-size: 1.2rem; margin-top: 2px;"></i>
                <div>
                    <h5 style="margin: 0; font-size: 0.9rem;">مهم: Dev Mode لا يعني السماح الكامل لكل الطلبات</h5>
                    <p style="margin: 5px 0 0; font-size: 0.78rem;">سيعمل فقط مع مفاتيح التطوير غير المربوطة بنطاق، ومع بقاء هذا الخيار مفعلاً يجب على أدوات مثل Postman وطلبات الخادم إرسال <code>X-Forwarded-For</code> أو <code>X-Visitor-IP</code>.</p>
                </div>
            </div>
            <?php endif; ?>

            <?php if (($apiSettings['cors_enabled'] ?? '1') == '0' && ($apiSettings['api_key_required'] ?? '1') == '0'): ?>
            <div style="background: #fef2f2; border: 1px solid #fee2e2; padding: 15px; border-radius: 12px; margin-bottom: 25px; color: #991b1b; display: flex; align-items: center; gap: 15px;">
                <i class="fas fa-exclamation-triangle" style="font-size: 1.5rem;"></i>
                <div>
                    <h5 style="margin: 0; font-size: 0.9rem;">تحذير أمني: الـ API حالياً مفتوح بالكامل!</h5>
                    <p style="margin: 5px 0 0; font-size: 0.75rem;">لقد قمت بتعطيل الـ CORS وفرض مفتاح الـ API معاً. هذا يعني أن أي شخص يمكنه سحب بياناتك دون أي قيود من أي مكان.</p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Methods & Headers Switches -->
            <div style="display: grid; grid-template-columns: 1fr 1.5fr; gap: 30px; margin-bottom: 30px;">
                <!-- Methods -->
                <div>
                    <h4 style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 15px; display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-exchange-alt"></i> الطرق المسموحة (Methods)
                    </h4>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <?php 
                        $allMethods = ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS', 'PATCH'];
                        $currentMethods = array_map('trim', explode(',', $apiSettings['allowed_methods'] ?? 'GET, POST, OPTIONS'));
                        foreach ($allMethods as $method): 
                        ?>
                        <label style="background: #fff; border: 1px solid #e2e8f0; padding: 8px 12px; border-radius: 8px; display: flex; justify-content: space-between; align-items: center; cursor: pointer; transition: 0.2s;" class="method-label">
                            <span style="font-size: 0.8rem; font-weight: 600;"><?= $method ?></span>
                            <input type="checkbox" name="methods[]" value="<?= $method ?>" <?= in_array($method, $currentMethods) ? 'checked' : '' ?> style="width: 16px; height: 16px; accent-color: var(--accent);">
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Headers -->
                <div>
                    <h4 style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 15px; display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-heading"></i> الترويسات المسموحة (Headers)
                    </h4>
                    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px;">
                        <?php 
                        $allHeaders = ['Content-Type', 'X-API-Key', 'Authorization', 'X-Requested-With', 'Accept', 'Origin'];
                        $currentHeaders = array_map('trim', explode(',', $apiSettings['allowed_headers'] ?? 'Content-Type, X-API-Key'));
                        foreach ($allHeaders as $header): 
                        ?>
                        <label style="background: #fff; border: 1px solid #e2e8f0; padding: 8px 12px; border-radius: 8px; display: flex; justify-content: space-between; align-items: center; cursor: pointer; transition: 0.2s;" class="header-label">
                            <span style="font-size: 0.75rem; white-space: nowrap;"><?= $header ?></span>
                            <input type="checkbox" name="headers[]" value="<?= $header ?>" <?= in_array($header, $currentHeaders) ? 'checked' : '' ?> style="width: 16px; height: 16px; accent-color: var(--accent);">
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div style="border-top: 1px solid #f1f5f9; padding-top: 20px; text-align: left;">
                <button type="submit" class="btn-primary" style="padding: 10px 40px; border-radius: 10px; font-weight: 600; box-shadow: 0 4px 12px rgba(59, 130, 246, 0.2);">
                    <i class="fas fa-save" style="margin-left: 10px;"></i> حفظ الإعدادات
                </button>
            </div>
        </form>
    </div>

    <!-- Domain Management Card -->
    <div class="card" style="margin-bottom: 25px;">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; gap: 15px; flex-wrap: wrap;">
            <h3 class="card-title">إدارة النطاقات المسموحة (Whitelisted Domains)</h3>
            <span class="badge badge-success"><?= count($allowedOrigins) ?> نطاق</span>
            <div class="api-key-toolbar">
                <button type="button" class="api-key-add-btn rbac-editor" onclick="openCreateOriginModal()" title="إضافة نطاق جديد">
                    <i class="fas fa-plus"></i>
                </button>
                <div class="api-key-search">
                    <i class="fas fa-search" style="position: absolute; right: 15px; top: 50%; transform: translateY(-50%); color: var(--text-secondary);"></i>
                    <input type="text" data-role="domain-search" class="form-control" placeholder="بحث بالنطاق أو البريد أو ID..." onkeyup="filterDomains()" style="width: 100%; padding-right: 40px;">
                </div>
            </div>
        </div>
        <div style="padding: 20px;">
            <?php if ($hasMultipleOwnersInOrigins): ?>
            <div style="background: rgba(59, 130, 246, 0.08); border: 1px solid rgba(59, 130, 246, 0.18); padding: 14px 16px; border-radius: 12px; margin-bottom: 18px; color: #1e3a8a; display: flex; align-items: flex-start; gap: 12px;">
                <i class="fas fa-info-circle" style="font-size: 1.1rem; margin-top: 2px;"></i>
                <div>
                    <h5 style="margin: 0; font-size: 0.9rem;">هذه القائمة تعرض أكثر من حساب مالك</h5>
                    <p style="margin: 5px 0 0; font-size: 0.8rem;">تم العثور على نطاقات مرتبطة بعدة `owner_user_id` مختلفة. استخدم بيانات المالك أسفل كل نطاق حتى لا تتم مقارنة اشتراك حساب مع اشتراك حساب آخر.</p>
                </div>
            </div>
            <?php endif; ?>
            <div style="display: none; gap: 15px; margin-bottom: 25px; flex-wrap: wrap;">
                <div class="api-key-toolbar">
                    <button type="button" class="api-key-add-btn rbac-editor" onclick="openCreateOriginModal()" title="إضافة نطاق جديد">
                        <i class="fas fa-plus"></i>
                    </button>
                    <div class="api-key-search">
                    <i class="fas fa-search" style="position: absolute; right: 15px; top: 50%; transform: translateY(-50%); color: var(--text-secondary);"></i>
                    <input type="text" id="domainSearchInput" class="form-control" placeholder="بحث باسم النطاق..." onkeyup="filterDomains()" style="width: 100%; padding-right: 40px;">
                </div>
                </div>
                <form method="POST" style="display: none;" class="rbac-editor">
                    <input type="hidden" name="action" value="add_origin">
                    <input type="text" name="origin" class="form-control" placeholder="مثال: https://www.yoursite.com" required style="flex: 1;">
                    
                    <div class="custom-plan-select" style="width: 150px;">
                        <input type="hidden" name="plan_type" id="add_origin_plan_type" value="free">
                        <div class="custom-plan-trigger" onclick="togglePlanDropdown('addOriginPlanDropdown')">
                            <span id="add_origin_plan_text">خطة مجانية</span>
                            <i class="fas fa-chevron-down"></i>
                        </div>
                        <div id="addOriginPlanDropdown" class="custom-plan-dropdown">
                            <div class="plan-option" onclick="selectPlan('add_origin', 'free', 'خطة مجانية')">
                                <i class="fas fa-seedling" style="color: #94a3b8;"></i> <span>خطة مجانية</span>
                            </div>
                            <div class="plan-option" onclick="selectPlan('add_origin', 'professional', 'خطة احترافية')">
                                <i class="fas fa-briefcase" style="color: #8b5cf6;"></i> <span>خطة احترافية</span>
                            </div>
                            <div class="plan-option" onclick="selectPlan('add_origin', 'premium', 'خطة مميزة')">
                                <i class="fas fa-crown" style="color: #10b981;"></i> <span>خطة مميزة</span>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn-primary" style="padding: 0 25px; flex-shrink: 0;">
                        <i class="fas fa-plus" style="margin-left: 8px;"></i> إضافة
                    </button>
                </form>
            </div>

            <div id="createOriginModal" class="api-key-modal" aria-hidden="true">
                <div class="api-key-modal-backdrop" onclick="closeCreateOriginModal()"></div>
                <div class="api-key-modal-panel">
                    <button type="button" class="api-key-modal-close" onclick="closeCreateOriginModal()" title="إغلاق">
                        <i class="fas fa-times"></i>
                    </button>
                    <div class="api-key-modal-header">
                        <h3 class="api-key-modal-title">إضافة نطاق جديد</h3>
                        <p class="api-key-modal-subtitle">أضف نطاقًا جديدًا وربطه بالخطة المناسبة، ثم سيظهر مباشرة داخل قائمة النطاقات المسموح بها.</p>
                    </div>
                    <form method="POST" class="api-key-modal-form rbac-editor">
                        <input type="hidden" name="action" value="add_origin">
                        <div class="api-key-modal-field">
                            <label>رابط النطاق</label>
                            <input type="text" name="origin" class="form-control" placeholder="مثال: https://www.yoursite.com" required>
                        </div>
                        <div class="api-key-modal-field" style="position: relative; z-index: 999;">
                            <label>نوع الخطة</label>
                            <input type="hidden" name="plan_type" data-role="origin-plan-type" value="free">
                            <div class="custom-plan-select">
                                <div class="custom-plan-trigger" onclick="togglePlanDropdown('originCreatePlanDropdown')">
                                    <span data-role="origin-plan-label">خطة مجانية</span>
                                    <i class="fas fa-chevron-down"></i>
                                </div>
                                <div id="originCreatePlanDropdown" class="custom-plan-dropdown">
                                    <div class="plan-option" onclick="selectCreateOriginPlan('free', 'خطة مجانية')">
                                        <i class="fas fa-seedling" style="color: #94a3b8;"></i> <span>خطة مجانية</span>
                                    </div>
                                    <div class="plan-option" onclick="selectCreateOriginPlan('professional', 'خطة احترافية')">
                                        <i class="fas fa-briefcase" style="color: #8b5cf6;"></i> <span>خطة احترافية</span>
                                    </div>
                                    <div class="plan-option" onclick="selectCreateOriginPlan('premium', 'خطة مميزة')">
                                        <i class="fas fa-crown" style="color: #10b981;"></i> <span>خطة مميزة</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <p class="api-key-modal-note">اكتب النطاق كاملًا مع البروتوكول الصحيح مثل <code>https://example.com</code> حتى يعمل الربط كما تتوقع.</p>
                        <div class="api-key-modal-actions">
                            <button type="submit" class="btn-primary" style="height: 46px; padding: 0 24px;">إضافة النطاق</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>النطاق</th>
                            <th>نوع الخطة</th>
                            <th>الطلبات</th>
                            <th>الحالة</th>
                            <th>تاريخ الانتهاء</th>
                            <th>إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($allowedOrigins)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; color: var(--text-secondary);">لا توجد نطاقات مخصصة. إذا كان "Dev Mode" معطلاً، فسيتم رفض جميع الطلبات الخارجية.</td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($allowedOrigins as $index => $origin): 
                                $isExpired = !empty($origin['expires_at']) && strtotime($origin['expires_at']) < time();
                                $limitReached = !empty($origin['request_limit']) && (int)$origin['request_limit'] > 0 && (int)($origin['total_requests'] ?? 0) >= (int)$origin['request_limit'];
                                $isBlocked = $isExpired || $limitReached;
                                $ownerUserId = (int)($origin['owner_user_id'] ?? 0);
                                $ownerEmail = $normalizeOwnerDisplay($origin['owner_email'] ?? '');
                                $ownerName = $normalizeOwnerDisplay($origin['owner_name'] ?? '');
                                $ownerPlanSlug = $normalizeOwnerDisplay($origin['owner_plan_slug'] ?? '');
                                $domainSearchText = strtolower(trim(implode(' ', [
                                    (string)($origin['origin'] ?? ''),
                                    $ownerUserId > 0 ? (string)$ownerUserId : '',
                                    (string)($origin['owner_email'] ?? ''),
                                    (string)($origin['owner_name'] ?? ''),
                                    (string)($origin['owner_plan_slug'] ?? ''),
                                    (string)($origin['owner_plan_name'] ?? ''),
                                ])));
                                
                                $planClass = 'background: rgba(59, 130, 246, 0.1); color: #3b82f6;';
                                $planText = 'مجانية';
                                if ($origin['plan_type'] === 'professional') { $planClass = 'background: rgba(139, 92, 246, 0.1); color: #8b5cf6;'; $planText = 'احترافية'; }
                                elseif ($origin['plan_type'] === 'premium') { $planClass = 'background: rgba(16, 185, 129, 0.1); color: #10b981;'; $planText = 'مميزة'; }
                            ?>
                            <tr class="domain-row" data-search="<?= htmlspecialchars($domainSearchText) ?>" style="<?= $index >= 5 ? 'display: none;' : '' ?>">
                                <td style="font-weight: 500; font-family: monospace;">
                                    <span id="origin-text-<?= $origin['id'] ?>"><?= htmlspecialchars($origin['origin']) ?></span>
                                    <div style="margin-top: 8px; display: flex; flex-wrap: wrap; gap: 8px; font-family: inherit;">
                                        <span class="badge" style="background: rgba(15, 23, 42, 0.06); color: #334155; font-size: 0.7rem; padding: 4px 8px;">Owner ID: <?= $ownerUserId > 0 ? $ownerUserId : '-' ?></span>
                                        <span class="badge" style="background: rgba(59, 130, 246, 0.08); color: #1d4ed8; font-size: 0.7rem; padding: 4px 8px;"><?= htmlspecialchars($ownerEmail) ?></span>
                                        <span class="badge" style="background: rgba(16, 185, 129, 0.08); color: #047857; font-size: 0.7rem; padding: 4px 8px;"><?= htmlspecialchars($ownerPlanSlug) ?></span>
                                    </div>
                                    <div style="margin-top: 6px; font-size: 0.75rem; color: var(--text-secondary); font-family: inherit;">
                                        المالك: <?= htmlspecialchars($ownerName) ?>
                                    </div>
                                    <form id="edit-form-<?= $origin['id'] ?>" method="POST" style="display: none; gap: 10px; align-items: center;">
                                        <input type="hidden" name="action" value="edit_origin">
                                        <input type="hidden" name="id" value="<?= $origin['id'] ?>">
                                        <input type="text" name="origin" class="form-control" value="<?= htmlspecialchars($origin['origin']) ?>" style="padding: 4px 8px; height: 32px; font-size: 0.85rem; width: 180px;">
                                        
                                        <div class="custom-plan-select" style="width: 120px;">
                                            <input type="hidden" name="plan_type" id="edit_plan_type_<?= $origin['id'] ?>" value="<?= $origin['plan_type'] ?>">
                                            <div class="custom-plan-trigger custom-plan-trigger-sm" onclick="togglePlanDropdown('editPlanDropdown_<?= $origin['id'] ?>')">
                                                <span id="edit_plan_text_<?= $origin['id'] ?>"><?= $planText ?></span>
                                                <i class="fas fa-chevron-down"></i>
                                            </div>
                                            <div id="editPlanDropdown_<?= $origin['id'] ?>" class="custom-plan-dropdown">
                                                <div class="plan-option" onclick="selectPlan('edit_<?= $origin['id'] ?>', 'free', 'مجانية')">مجانية</div>
                                                <div class="plan-option" onclick="selectPlan('edit_<?= $origin['id'] ?>', 'professional', 'احترافية')">احترافية</div>
                                                <div class="plan-option" onclick="selectPlan('edit_<?= $origin['id'] ?>', 'premium', 'مميزة')">مميزة</div>
                                            </div>
                                        </div>

                                        <button type="submit" class="btn-primary" style="padding: 0 10px; height: 32px; font-size: 0.75rem;">حفظ</button>
                                        <button type="button" class="btn-icon" onclick="toggleEditOrigin(<?= $origin['id'] ?>)" style="height: 32px; width: 32px;"><i class="fas fa-times"></i></button>
                                    </form>
                                </td>
                                <td>
                                    <span class="badge" style="<?= $planClass ?> font-size: 0.75rem; padding: 4px 10px;">
                                        <?= $planText ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="font-size: 0.85rem; display: flex; align-items: center; gap: 4px;">
                                        <span style="font-weight: 600; color: <?= $limitReached ? '#ef4444' : 'var(--text-secondary)' ?>;">
                                            <?= number_format($origin['total_requests'] ?? 0) ?>
                                        </span>
                                        <span style="color: #94a3b8; font-size: 0.75rem;">/</span>
                                        <?php if (!empty($origin['request_limit']) && (int)$origin['request_limit'] > 0): ?>
                                            <span style="color: #94a3b8; font-size: 0.75rem;"><?= number_format($origin['request_limit']) ?></span>
                                        <?php else: ?>
                                            <i class="fas fa-infinity" style="color: #94a3b8; font-size: 0.75rem;" title="غير محدود"></i>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($isBlocked): ?>
                                        <span class="badge badge-danger" title="<?= $isExpired ? 'انتهت الصلاحية' : 'تجاوز الحد المسموح' ?>">
                                            <?= $isExpired ? 'منتهية' : 'متوقفة' ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge <?= ($origin['is_active'] ?? 1) ? 'badge-success' : 'badge-danger' ?>">
                                            <?= ($origin['is_active'] ?? 1) ? 'نشط' : 'معطل' ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size: 0.85rem; <?= $isExpired ? 'color: #ef4444; font-weight: bold;' : 'color: var(--text-secondary);' ?>">
                                    <?= $origin['expires_at'] ? date('Y-m-d', strtotime($origin['expires_at'])) : 'دائم' ?>
                                    <?php if ($isExpired): ?> <i class="fas fa-clock" style="margin-right: 4px;"></i> <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 8px;">
                                        <form method="POST" style="display: inline;" class="rbac-editor">
                                            <input type="hidden" name="action" value="renew_origin">
                                            <input type="hidden" name="id" value="<?= $origin['id'] ?>">
                                            <button type="submit" class="btn-icon" style="background: rgba(16, 185, 129, 0.1); color: #10b981;" title="تجديد الاشتراك">
                                                <i class="fas fa-sync-alt"></i>
                                            </button>
                                        </form>
                                        <button type="button" class="btn-icon" onclick="toggleOriginDetails(<?= $origin['id'] ?>)" title="عرض التفاصيل والمفاتيح">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <form method="POST" style="display: inline;" class="rbac-editor">
                                            <input type="hidden" name="action" value="toggle_origin">
                                            <input type="hidden" name="id" value="<?= $origin['id'] ?>">
                                            <input type="hidden" name="is_active" value="<?= ($origin['is_active'] ?? 1) ? 0 : 1 ?>">
                                            <button type="submit" class="btn-icon" title="<?= ($origin['is_active'] ?? 1) ? 'تعطيل' : 'تفعيل' ?>">
                                                <i class="fas <?= ($origin['is_active'] ?? 1) ? 'fa-pause' : 'fa-play' ?>"></i>
                                            </button>
                                        </form>
                                        <button type="button" class="btn-icon rbac-editor" onclick="toggleEditOrigin(<?= $origin['id'] ?>)" title="تعديل النطاق">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('حذف هذا النطاق سيلغي ارتباط أي مفتاح API يعتمد عليه. هل أنت متأكد؟')" class="rbac-admin">
                                            <input type="hidden" name="action" value="delete_origin">
                                            <input type="hidden" name="id" value="<?= $origin['id'] ?>">
                                            <button type="submit" class="btn-icon" style="background: rgba(239, 68, 68, 0.1); color: #ef4444;">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <tr id="origin-details-<?= $origin['id'] ?>" style="display: none;">
                                <td colspan="6" style="padding: 20px; background: rgba(0,0,0,0.01); border-bottom: 2px solid var(--border-color);">
                                    <h4 style="margin: 0 0 15px 0; color: var(--accent); font-size: 0.95rem;">مفاتيح API المرتبطة بهذا النطاق (<?= htmlspecialchars($origin['origin']) ?>)</h4>
                                    <?php
                                    $hasKeys = false;
                                    foreach ($apiKeys as $ak) {
                                        if ($ak['allowed_origin_id'] == $origin['id']) {
                                            $hasKeys = true;
                                            break;
                                        }
                                    }
                                    
                                    if (!$hasKeys): ?>
                                    <div style="padding: 15px; text-align: center; color: var(--text-secondary); background: #f8fafc; border-radius: 8px;">لا توجد مفاتيح API مخصصة لهذا النطاق حالياً.</div>
                                    <?php else: ?>
                                    <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
                                        <thead style="background: #f1f5f9;">
                                            <tr>
                                                <th style="padding: 8px 10px; text-align: right; border-bottom: 2px solid #e2e8f0;">اسم المفتاح</th>
                                                <th style="padding: 8px 10px; text-align: left; border-bottom: 2px solid #e2e8f0;">المفتاح</th>
                                                <th style="padding: 8px 10px; text-align: center; border-bottom: 2px solid #e2e8f0;">الحالة</th>
                                                <th style="padding: 8px 10px; text-align: center; border-bottom: 2px solid #e2e8f0;">الطلبات</th>
                                                <th style="padding: 8px 10px; text-align: right; border-bottom: 2px solid #e2e8f0;">آخر استخدام</th>
                                                <th style="padding: 8px 10px; text-align: right; border-bottom: 2px solid #e2e8f0;">تاريخ الإنشاء</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($apiKeys as $ak): if ($ak['allowed_origin_id'] == $origin['id']): ?>
                                            <tr>
                                                <td style="padding: 10px; border-bottom: 1px solid #e2e8f0; font-weight: 500;"><?= htmlspecialchars($ak['name']) ?></td>
                                                <td style="padding: 10px; border-bottom: 1px solid #e2e8f0; font-family: monospace; color: #475569; text-align: left; direction: ltr;"><?= htmlspecialchars(substr($ak['api_key'], 0, 15)) ?>...</td>
                                                <td style="padding: 10px; border-bottom: 1px solid #e2e8f0; text-align: center;">
                                                    <span style="color: <?= $ak['is_active'] ? '#10b981' : '#ef4444' ?>;"><i class="fas fa-circle" style="font-size: 8px;"></i> <?= $ak['is_active'] ? 'نشط' : 'معطل' ?></span>
                                                </td>
                                                <td style="padding: 10px; border-bottom: 1px solid #e2e8f0; text-align: center; font-weight: bold;"><?= number_format($ak['request_count']) ?></td>
                                                <td style="padding: 10px; border-bottom: 1px solid #e2e8f0; color: #64748b;"><?= $ak['last_used_at'] ? date('Y-m-d H:i', strtotime($ak['last_used_at'])) : '-' ?></td>
                                                <td style="padding: 10px; border-bottom: 1px solid #e2e8f0; color: #64748b;"><?= date('Y-m-d', strtotime($ak['created_at'])) ?></td>
                                            </tr>
                                            <?php endif; endforeach; ?>
                                        </tbody>
                                    </table>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card rbac-editor" style="position: relative; z-index: 50; display: none;">
        <div class="card-header">
            <h3 class="card-title">إنشاء مفتاح API جديد</h3>
        </div>
        <form method="POST" style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
            <input type="hidden" name="action" value="generate">
            <div style="flex: 2; min-width: 200px;">
                <label style="display: block; margin-bottom: 8px; font-size: 0.9rem; color: var(--text-secondary);">اسم المفتاح</label>
                <input type="text" name="name" class="form-control" placeholder="مثال: موقع الأخبار الرياضية" required>
            </div>
            <div style="flex: 1.5; min-width: 200px; position: relative; z-index: 999;">
                <label style="display: block; margin-bottom: 8px; font-size: 0.9rem; color: var(--text-secondary);">ربط بنطاق معين (اختياري)</label>
                <input type="hidden" name="allowed_origin_id" id="hidden_origin_id" value="">
                
                <div class="custom-select-wrapper" style="position: relative; width: 100%;">
                    <div class="custom-select-trigger" onclick="toggleOriginDropdown()" style="width: 100%; padding: 10px 15px; border-radius: 12px; font-size: 0.9rem; cursor: pointer; display: flex; justify-content: space-between; align-items: center; background: rgba(255, 255, 255, 0.05); border: 1px solid var(--glass-border, #e2e8f0); color: var(--text-primary);">
                        <span id="selectedOriginText">مفاتيح تطوير / تعمل فقط عند تشغيل Dev Mode</span>
                        <i class="fas fa-chevron-down" style="font-size: 0.8rem; color: var(--text-secondary);"></i>
                    </div>
                    
                    <div id="originDropdown" style="display: none; position: absolute; top: 100%; left: 0; right: 0; background: var(--card-bg, #ffffff); border: 1px solid var(--glass-border, #e2e8f0); border-radius: 12px; margin-top: 5px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); overflow: hidden; z-index: 1000;">
                        <div style="padding: 10px; border-bottom: 1px solid rgba(0,0,0,0.05);">
                            <input type="text" id="originSearchInput" placeholder="ابحث عن نطاق..." onkeyup="filterOriginSelect()" class="form-control" style="padding: 8px 12px; font-size: 0.9rem; width: 100%;">
                        </div>
                        <div id="originOptionsList" style="max-height: 250px; overflow-y: auto;">
                            <div class="origin-option" onclick="selectOrigin('', 'مفاتيح تطوير / تعمل فقط عند تشغيل Dev Mode')" style="padding: 10px 15px; cursor: pointer; color: var(--text-secondary); font-size: 0.9rem; border-bottom: 1px solid rgba(0,0,0,0.05); display: flex; align-items: center; gap: 10px;">
                                <i class="fas fa-globe" style="color: var(--text-secondary);"></i>
                                <span>مفاتيح تطوير / تعمل فقط عند تشغيل Dev Mode</span>
                            </div>
                            <?php foreach ($allowedOrigins as $origin): ?>
                                <div class="origin-option" data-search="<?= strtolower($origin['origin']) ?>" onclick="selectOrigin('<?= $origin['id'] ?>', '<?= htmlspecialchars($origin['origin']) ?>')" style="padding: 10px 15px; cursor: pointer; color: var(--text-primary); font-size: 0.9rem; display: flex; align-items: center; gap: 10px; border-bottom: 1px solid rgba(0,0,0,0.05);">
                                    <i class="fas fa-link" style="color: var(--primary);"></i>
                                    <span><?= htmlspecialchars($origin['origin']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <button type="submit" class="btn-primary" style="height: 42px;">إنشاء مفتاح جديد</button>
        </form>
    </div>

    <div id="createApiKeyModal" class="api-key-modal" aria-hidden="true">
        <div class="api-key-modal-backdrop" onclick="closeCreateApiKeyModal()"></div>
        <div class="api-key-modal-panel">
            <button type="button" class="api-key-modal-close" onclick="closeCreateApiKeyModal()" title="إغلاق">
                <i class="fas fa-times"></i>
            </button>
            <div class="api-key-modal-header">
                <h3 class="api-key-modal-title">إنشاء مفتاح API جديد</h3>
                <p class="api-key-modal-subtitle">أنشئ مفتاحًا جديدًا بسرعة، ثم اربطه بنطاق محدد أو اتركه كمفتاح تطوير يعمل فقط عند تشغيل Dev Mode.</p>
            </div>
            <form method="POST" class="api-key-modal-form rbac-editor">
                <input type="hidden" name="action" value="generate">
                <div class="api-key-modal-field">
                    <label>اسم المفتاح</label>
                    <input type="text" name="name" class="form-control" placeholder="مثال: موقع الأخبار الرياضية" required>
                </div>
                <div class="api-key-modal-field" style="position: relative; z-index: 999;">
                    <label>ربط بنطاق معين (اختياري)</label>
                    <input type="hidden" name="allowed_origin_id" data-role="origin-id" value="">
                    <div class="custom-select-wrapper" style="position: relative; width: 100%;">
                        <div class="custom-select-trigger" onclick="toggleOriginDropdown()">
                            <span data-role="origin-label">مفاتيح تطوير / تعمل فقط عند تشغيل Dev Mode</span>
                            <i class="fas fa-chevron-down" style="font-size: 0.8rem; color: var(--text-secondary);"></i>
                        </div>
                        <div data-role="origin-dropdown" style="display: none; position: absolute; top: 100%; left: 0; right: 0; background: var(--card-bg, #ffffff); border: 1px solid var(--glass-border, #e2e8f0); border-radius: 12px; margin-top: 5px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); overflow: hidden; z-index: 1000;">
                            <div style="padding: 10px; border-bottom: 1px solid rgba(0,0,0,0.05);">
                                <input type="text" data-role="origin-search" placeholder="ابحث عن نطاق..." onkeyup="filterOriginSelect()" class="form-control" style="padding: 8px 12px; font-size: 0.9rem; width: 100%;">
                            </div>
                            <div data-role="origin-options" style="max-height: 250px; overflow-y: auto;">
                                <div class="origin-option" onclick="selectOrigin('', 'مفاتيح تطوير / تعمل فقط عند تشغيل Dev Mode')" style="padding: 10px 15px; cursor: pointer; color: var(--text-secondary); font-size: 0.9rem; border-bottom: 1px solid rgba(0,0,0,0.05); display: flex; align-items: center; gap: 10px;">
                                    <i class="fas fa-globe" style="color: var(--text-secondary);"></i>
                                    <span>مفاتيح تطوير / تعمل فقط عند تشغيل Dev Mode</span>
                                </div>
                                <?php foreach ($allowedOrigins as $origin): ?>
                                    <div class="origin-option" data-search="<?= strtolower($origin['origin']) ?>" onclick="selectOrigin('<?= $origin['id'] ?>', '<?= htmlspecialchars($origin['origin']) ?>')" style="padding: 10px 15px; cursor: pointer; color: var(--text-primary); font-size: 0.9rem; display: flex; align-items: center; gap: 10px; border-bottom: 1px solid rgba(0,0,0,0.05);">
                                        <i class="fas fa-link" style="color: var(--primary);"></i>
                                        <span><?= htmlspecialchars($origin['origin']) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <p class="api-key-modal-note">المفاتيح التطويرية لا تعمل إلا عند تفعيل Dev Mode، أما المفاتيح المربوطة بنطاق فتلتزم بالنطاق نفسه.</p>
                <div class="api-key-modal-actions">
                    <button type="submit" class="btn-primary" style="height: 46px; padding: 0 24px;">إنشاء المفتاح</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
            <h3 class="card-title">المفاتيح الحالية</h3>
            <div class="api-key-toolbar">
                <button type="button" class="api-key-add-btn rbac-editor" onclick="openCreateApiKeyModal()" title="إضافة مفتاح API جديد">
                    <i class="fas fa-plus"></i>
                </button>
                <div class="api-key-search">
                <i class="fas fa-search" style="position: absolute; right: 15px; top: 50%; transform: translateY(-50%); color: var(--text-secondary);"></i>
                <input type="text" id="apiKeySearchInput" class="form-control" placeholder="بحث بالاسم أو البريد أو ID..." onkeyup="filterApiKeys()" style="width: 100%; padding-right: 40px;">
                </div>
            </div>
        </div>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>الاسم</th>
                        <th>المفتاح</th>
                        <th>نوع الربط</th>
                        <th>الحالة</th>
                        <th>عدد الطلبات</th>
                        <th>آخر استخدام</th>
                        <th>تاريخ الإنشاء</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($apiKeys as $index => $key): ?>
                        <?php
                            $keyOwnerUserId = (int)($key['owner_user_id'] ?? 0);
                            $keyOwnerEmail = $normalizeOwnerDisplay($key['owner_email'] ?? '');
                            $keyOwnerName = $normalizeOwnerDisplay($key['owner_name'] ?? '');
                            $keyOwnerPlanSlug = $normalizeOwnerDisplay($key['owner_plan_slug'] ?? '');
                            $keySearchText = strtolower(trim(implode(' ', [
                                (string)($key['name'] ?? ''),
                                (string)($key['allowed_domain'] ?? ''),
                                $keyOwnerUserId > 0 ? (string)$keyOwnerUserId : '',
                                (string)($key['owner_email'] ?? ''),
                                (string)($key['owner_name'] ?? ''),
                                (string)($key['owner_plan_slug'] ?? ''),
                            ])));
                        ?>
                        <tr class="api-key-row" data-search="<?= htmlspecialchars($keySearchText) ?>" style="<?= $index >= 6 ? 'display: none;' : '' ?>">
                            <td>
                                <div style="font-weight: 600;"><?= htmlspecialchars($key['name']) ?></div>
                                <div style="margin-top: 6px; display: flex; flex-wrap: wrap; gap: 8px;">
                                    <span class="badge" style="background: rgba(15, 23, 42, 0.06); color: #334155; font-size: 0.7rem; padding: 4px 8px;">Owner ID: <?= $keyOwnerUserId > 0 ? $keyOwnerUserId : '-' ?></span>
                                    <span class="badge" style="background: rgba(59, 130, 246, 0.08); color: #1d4ed8; font-size: 0.7rem; padding: 4px 8px;"><?= htmlspecialchars($keyOwnerEmail) ?></span>
                                    <span class="badge" style="background: rgba(16, 185, 129, 0.08); color: #047857; font-size: 0.7rem; padding: 4px 8px;"><?= htmlspecialchars($keyOwnerPlanSlug) ?></span>
                                </div>
                                <div style="margin-top: 6px; font-size: 0.75rem; color: var(--text-secondary);">
                                    المالك: <?= htmlspecialchars($keyOwnerName) ?>
                                </div>
                            </td>
                            <td style="display: flex; align-items: center; justify-content: space-between; gap: 10px;">
                                <div style="display: flex; align-items: center; gap: 10px; overflow: hidden;">
                                    <code id="key_<?= $key['id'] ?>" style="display:none;"><?= htmlspecialchars($key['api_key']) ?></code>
                                    <code style="font-size: 0.85rem; letter-spacing: 0.5px;" title="<?= htmlspecialchars($key['api_key']) ?>"><?= htmlspecialchars(substr($key['api_key'], 0, 15)) ?>...</code>
                                </div>
                                <button onclick="copyToClipboard('key_<?= $key['id'] ?>', this)" class="btn-icon" style="font-size: 0.8rem; padding: 4px 8px; flex-shrink: 0;" title="نسخ المفتاح">
                                    <i class="fas fa-copy" style=" color: var(--text-secondary);"></i>
                                </button>
                            </td>
                            <td>
                                <?php if (!empty($key['allowed_domain'])): ?>
                                    <span class="badge" style="background: rgba(16, 185, 129, 0.1); color: #059669; font-size: 0.7rem;" title="هذا المفتاح سيعمل فقط مع هذا النطاق">
                                        <i class="fas fa-link"></i> <?= htmlspecialchars($key['allowed_domain']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge" style="background: #f1f5f9; color: #64748b; font-size: 0.7rem;">مفاتيح تطوير</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= $key['is_active'] ? 'badge-success' : 'badge-danger' ?>">
                                    <?= $key['is_active'] ? 'نشط' : 'معطل' ?>
                                </span>
                            </td>
                            <td><?= number_format($key['request_count']) ?></td>
                            <td><?= $key['last_used_at'] ? date('Y-m-d H:i', strtotime($key['last_used_at'])) : 'لم يستخدم بعد' ?></td>
                            <td><?= date('Y-m-d', strtotime($key['created_at'])) ?></td>
                            <td>
                                <div style="display: flex; gap: 10px;">
                                    <form method="POST" style="display: inline;" class="rbac-editor">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?= $key['id'] ?>">
                                        <input type="hidden" name="is_active" value="<?= $key['is_active'] ? 0 : 1 ?>">
                                        <button type="submit" class="btn-icon" title="<?= $key['is_active'] ? 'تعطيل' : 'تفعيل' ?>">
                                            <i class="fas <?= $key['is_active'] ? 'fa-pause' : 'fa-play' ?>"></i>
                                        </button>
                                    </form>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('هل أنت متأكد من الحذف؟')" class="rbac-admin">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $key['id'] ?>">
                                        <button type="submit" class="btn-icon" style="color: #ef4444; background: rgba(239, 68, 68, 0.1);">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card" style="margin-top: 25px;">
        <div class="card-header">
            <h3 class="card-title">دليل استخدام الـ API (Documentation)</h3>
        </div>
        <div style="padding: 40px; text-align: center;">
            <a href="../documentation.html" target="_blank" style="background: rgba(59, 130, 246, 0.03); border: 1px solid rgba(59, 130, 246, 0.4); color: #3b82f6; padding: 12px 35px; border-radius: 12px; text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 10px; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); font-size: 1rem; letter-spacing: 0.5px;">
                <i class="fas fa-file-alt" style="font-size: 1.1rem; opacity: 0.8;"></i> View Documentation
            </a>
            <style>
                .card a[href="../documentation.html"]:hover {
                    background: rgba(59, 130, 246, 0.1) !important;
                    border-color: rgba(59, 130, 246, 0.8) !important;
                    transform: translateY(-2px);
                    box-shadow: 0 8px 25px rgba(59, 130, 246, 0.12);
                }
            </style>
        </div>
    </div>
</div>

<script>
function fallbackCopyText(text) {
    if (typeof document.execCommand !== 'function') {
        return false;
    }

    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    textarea.style.pointerEvents = 'none';

    document.body.appendChild(textarea);
    textarea.focus();
    textarea.select();
    textarea.setSelectionRange(0, textarea.value.length);

    try {
        return document.execCommand('copy');
    } finally {
        document.body.removeChild(textarea);
    }
}

function showManualCopyPrompt(text) {
    window.prompt('Copy manually: Ctrl+C, Enter', text);
}

function writeTextToClipboard(text) {
    const clipboardApi = typeof navigator !== 'undefined' ? navigator.clipboard : null;
    const canUseClipboardApi = clipboardApi && typeof clipboardApi.writeText === 'function';

    if (canUseClipboardApi && window.isSecureContext) {
        return clipboardApi.writeText(text);
    }

    return new Promise((resolve, reject) => {
        try {
            if (fallbackCopyText(text)) {
                resolve();
            } else {
                reject(new Error('Copy command was rejected'));
            }
        } catch (error) {
            reject(error);
        }
    });
}

function copyToClipboard(elementId, btn) {
    const text = document.getElementById(elementId).innerText;
    const originalHtml = btn.innerHTML;
    
    writeTextToClipboard(text).then(() => {
        btn.innerHTML = '<i class="fas fa-check" style="color: #10b981;"></i>';
        setTimeout(() => {
            btn.innerHTML = originalHtml;
        }, 2000);
    }).catch(err => {
        console.error('Failed to copy: ', err);
        showManualCopyPrompt(text);
    });
}

function getCreateApiKeyModal() {
    return document.getElementById('createApiKeyModal');
}

function getCreateApiKeyModalContext() {
    const modal = getCreateApiKeyModal();
    if (!modal) {
        return {};
    }

    return {
        modal,
        dropdown: modal.querySelector('[data-role="origin-dropdown"]'),
        search: modal.querySelector('[data-role="origin-search"]'),
        hiddenInput: modal.querySelector('[data-role="origin-id"]'),
        label: modal.querySelector('[data-role="origin-label"]'),
        options: modal.querySelectorAll('[data-role="origin-options"] .origin-option'),
        nameInput: modal.querySelector('input[name="name"]')
    };
}

function openCreateApiKeyModal() {
    const { modal, nameInput } = getCreateApiKeyModalContext();
    if (!modal) {
        return;
    }

    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';

    if (nameInput) {
        setTimeout(() => nameInput.focus(), 30);
    }
}

function closeCreateApiKeyModal() {
    const { modal, dropdown, search } = getCreateApiKeyModalContext();
    if (!modal) {
        return;
    }

    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';

    if (dropdown) {
        dropdown.style.display = 'none';
    }

    if (search) {
        search.value = '';
    }
}

function getCreateOriginModal() {
    return document.getElementById('createOriginModal');
}

function getCreateOriginModalContext() {
    const modal = getCreateOriginModal();
    if (!modal) {
        return {};
    }

    return {
        modal,
        planTypeInput: modal.querySelector('[data-role="origin-plan-type"]'),
        planLabel: modal.querySelector('[data-role="origin-plan-label"]'),
        planDropdown: modal.querySelector('#originCreatePlanDropdown'),
        originInput: modal.querySelector('input[name="origin"]')
    };
}

function openCreateOriginModal() {
    const { modal, originInput } = getCreateOriginModalContext();
    if (!modal) {
        return;
    }

    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';

    if (originInput) {
        setTimeout(() => originInput.focus(), 30);
    }
}

function closeCreateOriginModal() {
    const { modal, planDropdown } = getCreateOriginModalContext();
    if (!modal) {
        return;
    }

    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';

    if (planDropdown) {
        planDropdown.style.display = 'none';
    }
}

function selectCreateOriginPlan(type, text) {
    const { planTypeInput, planLabel, planDropdown } = getCreateOriginModalContext();
    if (!planTypeInput || !planLabel || !planDropdown) {
        return;
    }

    planTypeInput.value = type;
    planLabel.innerText = text;
    planDropdown.style.display = 'none';
}

function mountOverlayModal(id) {
    const modal = document.getElementById(id);
    if (!modal || modal.parentElement === document.body) {
        return;
    }

    document.body.appendChild(modal);
}

function toggleEditOrigin(id) {
    const textSpan = document.getElementById('origin-text-' + id);
    const editForm = document.getElementById('edit-form-' + id);
    if (textSpan.style.display === 'none') {
        textSpan.style.display = 'inline';
        editForm.style.display = 'none';
    } else {
        textSpan.style.display = 'none';
        editForm.style.display = 'inline-flex';
    }
}

function toggleOriginDetails(id) {
    const row = document.getElementById('origin-details-' + id);
    if (row.style.display === 'none') {
        // Hide any other open rows first
        document.querySelectorAll('[id^="origin-details-"]').forEach(el => {
            el.style.display = 'none';
        });
        row.style.display = 'table-row';
    } else {
        row.style.display = 'none';
    }
}

function toggleOriginDropdown() {
    const { dropdown, search } = getCreateApiKeyModalContext();
    if (!dropdown) {
        return;
    }

    const isBlock = dropdown.style.display === 'block';
    dropdown.style.display = isBlock ? 'none' : 'block';

    if (!isBlock && search) {
        search.focus();
        search.value = '';
        filterOriginSelect();
    }
}

function selectOrigin(id, text) {
    const { hiddenInput, label, dropdown } = getCreateApiKeyModalContext();
    if (!hiddenInput || !label || !dropdown) {
        return;
    }

    hiddenInput.value = id;
    label.innerText = text;
    dropdown.style.display = 'none';
}

function filterOriginSelect() {
    const { search, options } = getCreateApiKeyModalContext();
    if (!search || !options.length) {
        return;
    }

    const filter = search.value.toLowerCase();
    options.forEach(opt => {
        if (!opt.hasAttribute('data-search')) return;
        const searchText = opt.getAttribute('data-search').toLowerCase();
        opt.style.display = searchText.includes(filter) ? 'flex' : 'none';
    });
}

function togglePlanDropdown(id) {
    const dropdown = document.getElementById(id);
    const isBlock = dropdown.style.display === 'block';
    
    // Close other dropdowns
    document.querySelectorAll('.custom-plan-dropdown').forEach(d => {
        if (d.id !== id) d.style.display = 'none';
    });
    
    dropdown.style.display = isBlock ? 'none' : 'block';
}

function selectPlan(context, type, text) {
    if (context === 'add_origin') {
        document.getElementById('add_origin_plan_type').value = type;
        document.getElementById('add_origin_plan_text').innerText = text;
        document.getElementById('addOriginPlanDropdown').style.display = 'none';
    } else if (context.startsWith('edit_')) {
        const id = context.split('_')[1];
        document.getElementById('edit_plan_type_' + id).value = type;
        document.getElementById('edit_plan_text_' + id).innerText = text;
        document.getElementById('editPlanDropdown_' + id).style.display = 'none';
    }
}

// Update click listener to handle both origin and plan dropdowns
window.addEventListener('click', function(e) {
    if (!e.target.closest('.custom-select-wrapper') && !e.target.closest('.custom-plan-select')) {
        const { dropdown: originDropdown } = getCreateApiKeyModalContext();
        if(originDropdown) originDropdown.style.display = 'none';
        
        document.querySelectorAll('.custom-plan-dropdown').forEach(d => {
            d.style.display = 'none';
        });
    }
});

window.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeCreateApiKeyModal();
        closeCreateOriginModal();
    }
});

window.addEventListener('DOMContentLoaded', function() {
    mountOverlayModal('createApiKeyModal');
    mountOverlayModal('createOriginModal');
});

function filterDomains() {
    const searchInput = document.querySelector('[data-role="domain-search"]') || document.getElementById('domainSearchInput');
    if (!searchInput) {
        return;
    }

    let input = searchInput.value.toLowerCase();
    let rows = document.querySelectorAll('.domain-row');
    let count = 0;
    
    rows.forEach(row => {
        let name = row.getAttribute('data-search') || '';
        if (input === '') {
            row.style.display = (count < 5) ? 'table-row' : 'none';
            count++;
        } else {
            row.style.display = name.includes(input) ? 'table-row' : 'none';
        }
    });
}

function filterApiKeys() {
    let input = document.getElementById('apiKeySearchInput').value.toLowerCase();
    let rows = document.querySelectorAll('.api-key-row');
    let count = 0;
    
    rows.forEach(row => {
        let name = row.getAttribute('data-search') || '';
        if (input === '') {
            row.style.display = (count < 6) ? 'table-row' : 'none';
            count++;
        } else {
            row.style.display = name.includes(input) ? 'table-row' : 'none';
        }
    });
}
</script>

<style>
.origin-option:hover {
    background: rgba(16, 185, 129, 0.05);
    color: var(--primary) !important;
}
</style>

<?php require_once 'includes/footer.php'; ?>
