<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/auth.php';

// Ensure last_activity column exists and update it
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN last_activity DATETIME DEFAULT NULL AFTER locked_until");
} catch (Exception $e) { /* Column may already exist */ }

if (isset($_SESSION['admin_user_id'])) {
    $pdo->prepare("UPDATE users SET last_activity = NOW() WHERE id = ?")->execute([$_SESSION['admin_user_id']]);
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة التحكم - Koora AI</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="../assets/css/admin.css?v=<?= time() ?>">
    <link rel="stylesheet" href="../assets/css/admin_sidebar.css?v=<?= time() ?>">
    <link rel="stylesheet" href="../assets/css/admin_docs.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/echarts/dist/echarts.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/echarts-gl/dist/echarts-gl.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/echarts/map/js/world.js"></script>
    <script type="text/javascript" src="https://www.gstatic.com/charts/loader.js"></script>
    <script src="//unpkg.com/globe.gl"></script>
    <script>
        window.USER_ROLE = "<?= getAdminRole() ?>";
        window.IS_ADMIN = <?= isAdmin() ? 'true' : 'false' ?>;
        window.IS_EDITOR = <?= isEditor() ? 'true' : 'false' ?>;
    </script>
    <style>
        <?php if (!isAdmin()): ?>
        /* === NON-ADMIN: Hide delete/remove/reset/clear buttons only === */
        .require-admin, .rbac-admin,
        button[onclick*="delete"], button[onclick*="Delete"],
        a[onclick*="delete"], a[onclick*="Delete"],
        button[onclick*="remove"], button[onclick*="Remove"],
        button[onclick*="reset"], button[onclick*="Reset"],
        button[onclick*="clear"], button[onclick*="Clear"],
        .delete-btn, .btn-delete, .btn-danger,
        .modal-footer .btn-danger { display: none !important; }
        <?php endif; ?>

        <?php if (!isEditor()): ?>
        /* === VIEWER: Hide action buttons only, keep page content visible === */
        .require-editor, .rbac-editor, .rbac-admin,
        /* Add/Create buttons */
        button[onclick*="openAdd"], button[onclick*="Add"],
        button[onclick*="create"], button[onclick*="Create"],
        .btn-primary[onclick], a.btn-primary[onclick],
        /* Edit buttons */
        button[onclick*="edit"], button[onclick*="Edit"],
        button[onclick*="openEdit"],
        .edit-btn, .btn-edit, .btn-icon[title="تعديل"],
        /* Save/Update buttons */
        button[onclick*="save"], button[onclick*="Save"],
        button[onclick*="update"], button[onclick*="Update"],
        input[type="submit"],
        /* Toggle switches */
        .switch, label.switch,
        /* Scraping/Fetch buttons */
        button[onclick*="scrape"], button[onclick*="Scrape"],
        button[onclick*="startScraping"], button[onclick*="fetch"],
        button[onclick*="Fetch"],
        /* AI Translation buttons */
        .btn-ai, button[onclick*="Translation"], button[onclick*="translate"],
        /* Delete buttons */
        button[onclick*="delete"], button[onclick*="Delete"],
        .delete-btn, .btn-delete, .btn-danger,
        /* Modals (add/edit forms) */
        .modal { display: none !important; }
        <?php endif; ?>

        .content-wrapper {
            width: 100%;
            min-width: 0;
        }

        .content-wrapper > * {
            min-width: 0;
        }

        .header-left,
        .header-right,
        .header-actions,
        .user-profile {
            min-width: 0;
        }

        .table-responsive {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .table-responsive::-webkit-scrollbar {
            height: 8px;
        }

        .table-responsive::-webkit-scrollbar-thumb {
            background: rgba(148, 163, 184, 0.35);
            border-radius: 999px;
        }

        #google_translate_element,
        .goog-te-banner-frame.skiptranslate,
        .goog-logo-link,
        .goog-te-gadget span,
        .goog-te-gadget-icon {
            display: none !important;
        }

        body {
            top: 0 !important;
        }

        .skiptranslate iframe {
            display: none !important;
        }

        .admin-translate-host {
            position: fixed;
            width: 1px;
            height: 1px;
            opacity: 0;
            pointer-events: none;
            overflow: hidden;
            z-index: -1;
        }

        .admin-language-switcher {
            position: relative;
        }

        .admin-language-toggle {
            width: 48px !important;
            min-width: 48px;
            height: 48px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #4f8df7;
        }

        .admin-language-icon {
            width: 20px;
            height: 20px;
            display: block;
            flex-shrink: 0;
        }

        .admin-language-menu {
            position: absolute;
            top: calc(100% + 10px);
            left: 0;
            min-width: 160px;
            padding: 10px;
            border-radius: 18px;
            border: 1px solid rgba(226, 232, 240, 0.95);
            background: rgba(255, 255, 255, 0.96);
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.12);
            display: flex;
            flex-direction: column;
            gap: 8px;
            z-index: 1205;
        }

        .admin-language-menu[hidden] {
            display: none !important;
        }

        .admin-language-option {
            border: 0;
            background: #f8fbff;
            color: var(--text-primary);
            border-radius: 14px;
            padding: 12px 14px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            font-weight: 700;
            transition: all 0.2s ease;
        }

        .admin-language-option:hover {
            background: rgba(59, 130, 246, 0.08);
            color: #2563eb;
        }

        .admin-language-option.is-active {
            background: linear-gradient(135deg, #4f8df7, #2563eb);
            color: #fff;
            box-shadow: 0 10px 20px rgba(37, 99, 235, 0.18);
        }

        .admin-language-option small {
            font-size: 0.76rem;
            opacity: 0.82;
            font-weight: 600;
        }

        html[data-admin-lang="en"] {
            direction: ltr;
        }

        html[data-admin-lang="en"] body,
        html[data-admin-lang="en"] .admin-main,
        html[data-admin-lang="en"] .content-wrapper {
            direction: ltr;
        }

        html[data-admin-lang="en"] .admin-sidebar {
            left: 0 !important;
            right: auto !important;
            border-right: 1px solid var(--border-color) !important;
            border-left: none !important;
        }

        html[data-admin-lang="en"] .admin-main {
            margin-left: 110px !important;
            margin-right: 0 !important;
            padding: 20px 20px 20px 30px !important;
        }

        html[data-admin-lang="en"] .nav-link:hover:not(.active),
        html[data-admin-lang="en"] .nav-link.logout:hover {
            transform: translateX(5px);
        }

        html[data-admin-lang="en"] .top-header,
        html[data-admin-lang="en"] .header-right,
        html[data-admin-lang="en"] .header-actions,
        html[data-admin-lang="en"] .user-profile,
        html[data-admin-lang="en"] .card-header,
        html[data-admin-lang="en"] .table-header,
        html[data-admin-lang="en"] .monitor-panel-header,
        html[data-admin-lang="en"] .monitor-toolbar,
        html[data-admin-lang="en"] .monitor-ips-toolbar {
            direction: ltr;
        }

        html[data-admin-lang="en"] .page-title-text,
        html[data-admin-lang="en"] .card-title,
        html[data-admin-lang="en"] .card,
        html[data-admin-lang="en"] th,
        html[data-admin-lang="en"] td,
        html[data-admin-lang="en"] .monitor-card-lead,
        html[data-admin-lang="en"] .monitor-alert-body,
        html[data-admin-lang="en"] .monitor-service-description,
        html[data-admin-lang="en"] .empty-state,
        html[data-admin-lang="en"] .user-info,
        html[data-admin-lang="en"] .table-container,
        html[data-admin-lang="en"] .modal-content {
            text-align: left;
        }

        html[data-admin-lang="en"] .table-responsive table,
        html[data-admin-lang="en"] .table-container table {
            direction: ltr;
        }

        html[data-admin-lang="en"] .search-box i {
            right: auto;
            left: 15px;
        }

        html[data-admin-lang="en"] .search-box .form-control,
        html[data-admin-lang="en"] .search-box input {
            padding-left: 45px !important;
            padding-right: 18px !important;
            text-align: left !important;
        }

        html[data-admin-lang="en"] .admin-language-menu {
            left: 0;
            right: auto;
        }

        html[data-admin-lang="en"] .admin-ai-panel {
            left: auto !important;
            right: 0 !important;
            border-right: none !important;
            border-left: 1px solid rgba(203, 213, 225, 0.7) !important;
            transform: translateX(100%) !important;
        }

        html[data-admin-lang="en"] .admin-ai-panel.is-open {
            transform: translateX(0) !important;
        }

        html[data-admin-lang="en"] .admin-ai-toggle {
            left: auto !important;
            right: 0 !important;
            border-radius: 20px 0 0 20px !important;
        }

        @media (max-width: 1100px) {
            body {
                padding-bottom: 112px;
            }

            .admin-layout {
                display: block !important;
            }

            .admin-sidebar {
                position: fixed !important;
                right: 12px !important;
                left: 12px !important;
                top: auto !important;
                bottom: 12px !important;
                width: auto !important;
                height: 78px !important;
                margin: 0 !important;
                padding: 10px 12px !important;
                border-radius: 24px !important;
                border-left: none !important;
                border-top: 1px solid var(--border-color) !important;
                display: flex !important;
                flex-direction: row !important;
                align-items: center !important;
                overflow: hidden !important;
            }

            .admin-sidebar:hover {
                width: auto !important;
            }

            .admin-sidebar .nav-link span {
                display: none !important;
            }

            .sidebar-nav {
                flex-direction: row !important;
                align-items: center !important;
                gap: 8px !important;
                padding: 0 !important;
                overflow-x: auto !important;
                overflow-y: hidden !important;
                scrollbar-width: none;
            }

            .sidebar-nav::-webkit-scrollbar {
                display: none;
            }

            .nav-link {
                min-width: 52px !important;
                min-height: 52px !important;
                padding: 12px !important;
                justify-content: center !important;
                gap: 0 !important;
                flex-shrink: 0 !important;
            }

            .nav-link:hover:not(.active),
            .nav-link.logout:hover {
                transform: none !important;
            }

            .sidebar-footer {
                padding: 0 !important;
                margin-right: 8px !important;
                flex-shrink: 0;
            }

            .sidebar-footer .nav-link {
                padding: 12px !important;
            }

            .admin-main {
                margin-right: 0 !important;
                padding: 18px 18px 124px !important;
            }

            .top-header {
                flex-direction: column !important;
                align-items: stretch !important;
                gap: 14px !important;
                padding: 18px !important;
            }

            .header-right,
            .header-actions {
                width: 100% !important;
                justify-content: space-between !important;
                flex-wrap: wrap !important;
                gap: 12px !important;
            }

            .header-actions {
                align-items: stretch !important;
            }

            .user-profile {
                width: 100% !important;
                justify-content: space-between !important;
            }

            .search-box {
                width: 100% !important;
                max-width: none !important;
            }

            .search-box .form-control {
                width: 100% !important;
            }

            .dashboard-grid,
            .stats-grid {
                grid-template-columns: 1fr !important;
            }

            .table-header {
                flex-direction: column !important;
                align-items: stretch !important;
                gap: 14px !important;
            }

            .table-header > div {
                width: 100% !important;
                flex-wrap: wrap !important;
                gap: 12px !important;
            }

            .table-header .search-box {
                flex: 1 1 100% !important;
            }

            .table-responsive table,
            .table-container table {
                min-width: 760px;
            }

            .card,
            .table-container {
                border-radius: 20px !important;
            }

            .modal {
                padding: 12px !important;
                align-items: flex-end !important;
            }

            .modal-content {
                width: 100% !important;
                max-width: min(100%, 780px) !important;
                max-height: 90vh !important;
            }

            .modal-body {
                padding: 20px !important;
            }

            .modal-header,
            .modal-footer {
                padding: 18px 20px !important;
            }

            [style*="grid-template-columns: repeat(2, 1fr)"],
            [style*="grid-template-columns:repeat(2,1fr)"],
            [style*="grid-template-columns: 1.5fr 1fr"],
            [style*="grid-template-columns:1.5fr 1fr"] {
                grid-template-columns: 1fr !important;
            }
        }

        @media (max-width: 768px) {
            body {
                padding-bottom: 104px;
            }

            .admin-main {
                padding: 14px 14px 114px !important;
            }

            .page-title-text {
                font-size: 1.3rem !important;
            }

            .top-header {
                border-radius: 18px !important;
                margin-bottom: 20px !important;
            }

            .header-actions .action-btn {
                display: none !important;
            }

            .table-responsive table,
            .table-container table {
                min-width: 640px;
            }

            th,
            td {
                padding: 12px 14px !important;
                font-size: 0.82rem !important;
            }

            .table-header .btn-primary,
            .table-header .btn-secondary,
            .table-header .btn-danger {
                width: 100% !important;
                justify-content: center !important;
            }

            .channels-grid {
                grid-template-columns: 1fr !important;
                padding: 14px !important;
            }

            .channel-card {
                flex-wrap: wrap !important;
                gap: 12px !important;
                min-height: auto !important;
            }

            .channel-main,
            .channel-actions {
                width: 100% !important;
            }

            .channel-actions {
                justify-content: space-between !important;
            }

            .modal {
                padding: 0 !important;
            }

            .modal-content {
                max-width: 100% !important;
                border-radius: 24px 24px 0 0 !important;
            }
        }
    </style>
</head>
<body>
    <!-- Heartbeat: sends activity signal every 2 minutes -->
    <script>
    setInterval(() => {
        fetch('ajax_users.php?action=heartbeat', { method: 'GET', cache: 'no-store' }).catch(() => {});
    }, 120000); // 2 minutes
    </script>

    <div class="admin-layout">
        <!-- Sidebar -->
        <aside class="admin-sidebar">

            
            <nav class="sidebar-nav">
                <a href="index.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : '' ?>">
                    <i class="fas fa-home"></i>
                    <span>الرئيسية</span>
                </a>
                <a href="matches.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'matches.php' ? 'active' : '' ?>">
                    <i class="fas fa-futbol"></i>
                    <span>المباريات</span>
                </a>
                <a href="leagues.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'leagues.php' ? 'active' : '' ?>">
                    <i class="fas fa-trophy"></i>
                    <span>الدوريات</span>
                </a>
                <a href="standings.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'standings.php' ? 'active' : '' ?>">
                    <i class="fas fa-list-ol"></i>
                    <span>جداول الترتيب</span>
                </a>
                <a href="countries.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'countries.php' ? 'active' : '' ?>">
                    <i class="fas fa-globe"></i>
                    <span>البلدان</span>
                </a>
                <a href="teams.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'teams.php' ? 'active' : '' ?>">
                    <i class="fas fa-users"></i>
                    <span>الفرق</span>
                </a>
                <a href="players.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'players.php' ? 'active' : '' ?>">
                    <i class="fas fa-running"></i>
                    <span>اللاعبين</span>
                </a>
                <a href="news.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'news.php' ? 'active' : '' ?>">
                    <i class="fas fa-newspaper"></i>
                    <span>الأخبار</span>
                </a>
                <a href="settings.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : '' ?>">
                    <i class="fas fa-cog"></i>
                    <span>الإعدادات</span>
                </a>
                <a href="channels.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'channels.php' ? 'active' : '' ?>">
                    <i class="fas fa-broadcast-tower"></i>
                    <span>القنوات</span>
                </a>
                <a href="api.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'api.php' ? 'active' : '' ?>">
                    <i class="fas fa-code"></i>
                    <span>API</span>
                </a>
                <a href="monitor.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'monitor.php' ? 'active' : '' ?>">
                    <i class="fas fa-server"></i>
                    <span>المراقبة</span>
                </a>
            </nav>

            <div class="sidebar-footer">
                <a href="logout.php" class="nav-link logout">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>تسجيل الخروج</span>
                </a>
            </div>
        </aside>

        <!-- Main Content Wrapper -->
        <div class="admin-main">
            <header class="top-header">
                <div class="header-left">
                    
                    <h1 class="page-title-text"><?= $pageTitle ?? 'لوحة التحكم' ?></h1>
                </div>
                <div class="header-right">
                    <div class="header-actions">
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="search" name="search_query_<?= time() ?>" class="form-control" placeholder="بحث..." style="width: 250px;" autocomplete="new-password">
                        </div>
                        <div class="admin-language-switcher notranslate" translate="no">
                            <button type="button" class="action-btn admin-language-toggle" id="adminLanguageToggle" aria-haspopup="true" aria-expanded="false" title="Language">
                                <svg class="admin-language-icon" viewBox="0 0 512 512" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
                                    <path fill="currentColor" d="M478.33,433.6l-90-218a22,22,0,0,0-40.67,0l-90,218a22,22,0,1,0,40.67,16.79L316.66,406H419.33l18.33,44.39A22,22,0,0,0,458,464a22,22,0,0,0,20.32-30.4ZM334.83,362,368,281.65,401.17,362Z"></path>
                                    <path fill="currentColor" d="M267.84,342.92a22,22,0,0,0-4.89-30.7c-.2-.15-15-11.13-36.49-34.73,39.65-53.68,62.11-114.75,71.27-143.49H330a22,22,0,0,0,0-44H214V70a22,22,0,0,0-44,0V90H54a22,22,0,0,0,0,44H251.25c-9.52,26.95-27.05,69.5-53.79,108.36-31.41-41.68-43.08-68.65-43.17-68.87a22,22,0,0,0-40.58,17c.58,1.38,14.55,34.23,52.86,83.93.92,1.19,1.83,2.35,2.74,3.51-39.24,44.35-77.74,71.86-93.85,80.74a22,22,0,1,0,21.07,38.63c2.16-1.18,48.6-26.89,101.63-85.59,22.52,24.08,38,35.44,38.93,36.1a22,22,0,0,0,30.75-4.9Z"></path>
                                </svg>
                            </button>
                            <div class="admin-language-menu" id="adminLanguageMenu" hidden>
                                <button type="button" class="admin-language-option is-active" data-lang="ar">
                                    <span>AR</span>
                                    <small>العربية</small>
                                </button>
                                <button type="button" class="admin-language-option" data-lang="en">
                                    <span>EN</span>
                                    <small>English</small>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="user-profile">
                        <div class="user-info">
                            <span class="user-name"><?= htmlspecialchars($_SESSION['admin_username'] ?? 'Admin') ?></span>
                        </div>
                        <div class="avatar">
                            <i class="fas fa-user"></i>
                        </div>
                    </div>
                </div>
            </header>
            
            <div class="content-wrapper">
                <div id="google_translate_element" class="admin-translate-host notranslate" translate="no" aria-hidden="true"></div>
