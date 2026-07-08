<?php
require_once 'init.php';
require_once '../config.php';
require_once '../includes/Database.php';

$db = new Database($pdo);

// Handle Add League
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_league') {
    header('Content-Type: application/json');
    try {
        $name = $_POST['name'];
        $nameEn = $_POST['name_en'] ?? null;
        $nameAr = $_POST['name_ar'] ?? null;
        $logoUrl = $_POST['logo_url'] ?? null;
        $country = $_POST['country'] ?? null;
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        
        $db->addLeague($name, $nameEn, $nameAr, $isActive, $logoUrl, $country);
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle Update League Data
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_league_data') {
    header('Content-Type: application/json');
    try {
        $id = $_POST['id'];
        $nameEn = $_POST['name_en'] ?? null;
        $nameAr = $_POST['name_ar'] ?? null;
        $logoUrl = $_POST['logo_url'] ?? null;
        $country = $_POST['country'] ?? null;
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        
        $db->updateLeague($id, $nameEn, $nameAr, $isActive, $logoUrl, $country);
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle AJAX request for updating status only (from switch)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_league') {
    header('Content-Type: application/json');
    try {
        $id = $_POST['id'];
        $status = $_POST['status']; // 1 or 0
        $db->updateLeagueStatus($id, $status);
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle Delete League
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_league') {
    header('Content-Type: application/json');
    try {
        $id = $_POST['id'];
        $db->deleteLeague($id);
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Pagination & Filtering
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 50;
$offset = ($page - 1) * $limit;
$search = $_GET['search'] ?? null;
$filter_param = $_GET['status'] ?? 'active';

$leagues = $db->getAllLeagues($limit, $offset, $search, $filter_param);
$totalLeagues = $db->getLeaguesCount($search, $filter_param);
$totalPages = ceil($totalLeagues / $limit);

$countries_list = $db->getAllCountries();

// Filter label for UI
$filter_label = "الكل";
if ($filter_param === 'active') $filter_label = "النشطة";
elseif ($filter_param === 'inactive') $filter_label = "غير النشطة";
elseif ($filter_param === 'no_country') $filter_label = "بدون دولة";

$activeCount = $db->getLeaguesCount(null, 'active');
$pageTitle = 'إدارة الدوريات';

require_once 'includes/header.php';
?>

<div class="table-container leagues-page-container">
    <div class="table-header leagues-table-header">
        <div class="search-box">
            <i class="fas fa-search"></i>
            <input type="text" id="leagueSearch" class="form-control" value="<?= htmlspecialchars($search ?? '') ?>" placeholder="ابحث عن دوري..." onkeydown="if(event.key === 'Enter') { window.location.href='leagues.php?status=<?= $filter_param ?>&search=' + this.value; return false; }">
        </div>
        <div class="leagues-toolbar">
            <div class="filter-container">
                <button type="button" class="btn-filter" onclick="toggleFilterDropdown()">
                    <i class="fas fa-filter"></i>
                    <span><?= $filter_label ?></span>
                    <i class="fas fa-chevron-down" style="font-size: 0.7rem; margin-right: 5px; opacity: 0.6;"></i>
                </button>
                <div id="filterDropdown" class="filter-dropdown">
                    <a href="leagues.php?status=all&search=<?= $search ?>" class="filter-item <?= $filter_param === 'all' ? 'active' : '' ?>">
                        <span>الكل</span>
                        <i class="fas fa-check"></i>
                    </a>
                    <a href="leagues.php?status=active&search=<?= $search ?>" class="filter-item <?= $filter_param === 'active' ? 'active' : '' ?>">
                        <span>النشطة</span>
                        <i class="fas fa-check"></i>
                    </a>
                    <a href="leagues.php?status=inactive&search=<?= $search ?>" class="filter-item <?= $filter_param === 'inactive' ? 'active' : '' ?>">
                        <span>غير النشطة</span>
                        <i class="fas fa-check"></i>
                    </a>
                    <a href="leagues.php?status=no_country&search=<?= $search ?>" class="filter-item <?= $filter_param === 'no_country' ? 'active' : '' ?>">
                        <span>بدون دولة</span>
                        <i class="fas fa-check"></i>
                    </a>
                </div>
            </div>
            <div class="stats-badge">
                <span>العدد: <?= $totalLeagues ?></span>
            </div>
            <div class="stats-badge active">
                <span>النشطة: <?= $activeCount ?></span>
            </div>
            <button type="button" class="btn-primary" onclick="openAddLeagueModal()">
                <i class="fas fa-plus"></i> إضافة دوري
            </button>
            <button type="button" class="btn-ai" id="aiTranslateBtn" onclick="startAiTranslation()" title="ترجمة الدوريات المفقودة باستخدام الذكاء الاصطناعي">
                <i class="fa-solid fa-wand-sparkles"></i>
            </button>
        </div>
    </div>

    <div class="leagues-grid" id="leaguesGrid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px; padding: 20px;">
        <?php if (empty($leagues)): ?>
            <div style="grid-column: 1 / -1; text-align: center; padding: 60px 20px; color: var(--text-secondary); background: rgba(255,255,255,0.02); border-radius: 16px; border: 1px dashed rgba(255,255,255,0.1);">
                <i class="fas fa-trophy" style="font-size: 3rem; opacity: 0.1; margin-bottom: 15px; display: block;"></i>
                <h3 style="color: #f1f5f9; margin-bottom: 5px; font-size: 1.1rem;">لا توجد دوريات حالياً</h3>
                <p style="font-size: 0.85rem; opacity: 0.7;">لم يتم العثور على أي دوريات تطابق الفلتر المختار.</p>
                <a href="leagues.php?status=all" style="display: inline-block; margin-top: 20px; color: #38bdf8; text-decoration: none; font-weight: 600; border: 1px solid rgba(56, 189, 248, 0.3); padding: 8px 25px; border-radius: 12px; transition: all 0.3s;" onmouseover="this.style.background='rgba(56, 189, 248, 0.1)'" onmouseout="this.style.background='transparent'">عرض الكل</a>
            </div>
        <?php else: ?>
            <?php foreach ($leagues as $league): ?>
            <div class="league-card" 
                data-name="<?= strtolower($league['name']) ?>" 
                data-name-ar="<?= strtolower($league['name_ar'] ?? '') ?>" 
                data-name-en="<?= strtolower($league['name_en'] ?? '') ?>"
                style="background: var(--card-bg); border-radius: 12px; padding: 10px 15px 10px 45px; position: relative; border: 1px solid var(--border-color); display: flex; align-items: center; gap: 15px; transition: all 0.2s; height: 80px;">
                
                <div class="league-card-actions" style="position:absolute; top:50%; left:10px; transform: translateY(-50%); z-index: 10; display: flex; flex-direction: column; gap: 8px; align-items: center;">
                    <label class="switch" style="transform: scale(0.6);">
                        <input type="checkbox" 
                            onchange="toggleLeague(<?= $league['id'] ?>, this)" 
                            <?= $league['is_active'] ? 'checked' : '' ?>>
                        <span class="slider round"></span>
                    </label>
                    <button type="button" class="btn-icon" onclick="openEditLeagueModal(<?= htmlspecialchars(json_encode($league), ENT_QUOTES, 'UTF-8') ?>)" title="تعديل" style="width: 28px; height: 28px; background: rgba(255,255,255,0.05); border-radius: 6px; display: flex; align-items: center; justify-content: center; color: var(--text-secondary); transition: all 0.2s; border: none; cursor: pointer;">
                        <i class="fas fa-edit" style="font-size: 0.8rem; color: #4657f0ff;"></i>
                    </button>
                </div>

                <div class="league-icon">
                    <?php if(!empty($league['logo_url'])): ?>
                        <img src="<?= htmlspecialchars($league['logo_url']) ?>" style="width: 100%; height: 100%; object-fit: cover; box-shadow: none;">
                    <?php else: ?>
                        <i class="fas fa-trophy" style="font-size: 1.2rem; color: #cbd5e1;"></i>
                    <?php endif; ?>
                </div>

                <div class="league-card-body" style="flex: 1; overflow: hidden; display: flex; flex-direction: column; justify-content: center;">
                    <h3 class="league-card-title" style="color: var(--text-primary); margin-bottom: 2px; font-size: 0.95rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($league['name']) ?>">
                        <?= htmlspecialchars($league['name_ar'] ?: $league['name']) ?>
                    </h3>
                    <div class="league-card-meta" style="display: flex; align-items: center; gap: 5px; color: var(--text-secondary); font-size: 0.75rem;">
                        <?php if(!empty($league['country'])): ?>
                            <span style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100px;">
                                <?= htmlspecialchars($league['country']) ?>
                            </span>
                        <?php endif; ?>
                        <?php if(!empty($league['name_en'])): ?>
                            <?php if(!empty($league['country'])): ?>
                                <span style="width: 3px; height: 3px; background: var(--text-secondary); border-radius: 50%; opacity: 0.5;"></span>
                            <?php endif; ?>
                            <span style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100px; opacity: 0.8;">
                                <?= htmlspecialchars($league['name_en']) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination" style="display: flex; justify-content: center; align-items: center; gap: 8px; margin: 30px 0;">
        <?php if ($page > 1): ?>
            <a href="leagues.php?page=1&status=<?= $filter_param ?>&search=<?= $search ?>" class="btn btn-secondary" title="الصفحة الأولى"><i class="fas fa-angle-double-right"></i></a>
            <a href="leagues.php?page=<?= $page - 1 ?>&status=<?= $filter_param ?>&search=<?= $search ?>" class="btn btn-secondary" title="السابق"><i class="fas fa-angle-right"></i></a>
        <?php endif; ?>

        <?php
        $range = 2;
        $start = max(1, $page - $range);
        $end = min($totalPages, $page + $range);

        if ($start > 1) {
            echo '<a href="leagues.php?page=1&status='.$filter_param.'&search='.$search.'" class="btn btn-secondary">1</a>';
            if ($start > 2) echo '<span style="color: var(--text-secondary); padding: 0 5px;">...</span>';
        }

        for ($i = $start; $i <= $end; $i++) {
            $activeClass = ($page == $i) ? 'btn-primary' : 'btn-secondary';
            echo '<a href="leagues.php?page='.$i.'&status='.$filter_param.'&search='.$search.'" class="btn '.$activeClass.'">'.$i.'</a>';
        }

        if ($end < $totalPages) {
            if ($end < $totalPages - 1) echo '<span style="color: var(--text-secondary); padding: 0 5px;">...</span>';
            echo '<a href="leagues.php?page='.$totalPages.'&status='.$filter_param.'&search='.$search.'" class="btn btn-secondary">'.$totalPages.'</a>';
        }
        ?>

        <?php if ($page < $totalPages): ?>
            <a href="leagues.php?page=<?= $page + 1 ?>&status=<?= $filter_param ?>&search=<?= $search ?>" class="btn btn-secondary" title="التالي"><i class="fas fa-angle-left"></i></a>
            <a href="leagues.php?page=<?= $totalPages ?>&status=<?= $filter_param ?>&search=<?= $search ?>" class="btn btn-secondary" title="الصفحة الأخيرة"><i class="fas fa-angle-double-left"></i></a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Add League Modal -->
<div id="addLeagueModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>إضافة دوري جديد</h2>
            <button type="button" class="btn-icon" onclick="closeAddLeagueModal()"><i class="fas fa-times"></i></button>
        </div>
        <form id="addLeagueForm">
            <div class="modal-body">
                <input type="hidden" name="action" value="add_league">
                
                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">الاسم الأساسي</label>
                    <input type="text" name="name" required class="form-control" placeholder="اسم الدوري الأساسي">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">الاسم بالعربية</label>
                        <input type="text" name="name_ar" class="form-control" placeholder="الاسم بالعربية">
                    </div>
                    
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">الاسم بالإنجليزية</label>
                        <input type="text" name="name_en" class="form-control" placeholder="League Name in English">
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">رابط الشعار (Logo URL)</label>
                    <input type="text" name="logo_url" class="form-control" placeholder="https://example.com/logo.png">
                </div>

                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">البلد (Country)</label>
                    <div class="custom-select-wrapper" style="position: relative;">
                        <div class="custom-select-trigger" onclick="toggleAddCountryDropdown()" id="addSelectedCountryTrigger" style="width: 100%; padding: 12px 18px; border-radius: 14px; font-size: 0.95rem; cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                            <span id="addSelectedCountryText">اختر البلد...</span>
                            <i class="fas fa-chevron-down" style="font-size: 0.8rem; color: var(--text-secondary);"></i>
                        </div>
                        <input type="hidden" name="country" id="add_league_country">
                        
                        <div id="addCountryDropdown" style="display: none; position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid var(--border-color); border-radius: 18px; margin-top: 10px; z-index: 1000; box-shadow: 0 20px 40px rgba(0,0,0,0.1); overflow: hidden;">
                            <div style="padding: 10px; border-bottom: 1px solid var(--border-color); background: #f8fafc;">
                                <input type="text" id="addCountrySearchInput" placeholder="ابحث عن بلد..." onkeyup="filterAddCountrySelect()" class="form-control" style="padding: 8px 12px; font-size: 0.9rem;">
                            </div>
                            <div id="addCountryOptionsList" style="max-height: 200px; overflow-y: auto;">
                                <?php foreach($countries_list as $c): ?>
                                    <div class="country-option" 
                                         data-value="<?= htmlspecialchars($c['name']) ?>" 
                                         data-search="<?= strtolower($c['name'] . ' ' . ($c['name_ar'] ?? '') . ' ' . ($c['name_en'] ?? '')) ?>"
                                         onclick="selectAddCountry('<?= htmlspecialchars($c['name']) ?>', '<?= htmlspecialchars($c['name_ar'] ?: $c['name']) ?>')"
                                         style="padding: 12px 15px; cursor: pointer; color: var(--text-secondary); font-size: 0.9rem; transition: all 0.2s; display: flex; align-items: center; gap: 10px;">
                                        <?php if(!empty($c['logo_url'])): ?>
                                            <img src="<?= htmlspecialchars($c['logo_url']) ?>" style="width: 20px; height: 15px; object-fit: contain;">
                                        <?php else: ?>
                                            <i class="fas fa-globe" style="font-size: 0.8rem; color: var(--text-secondary);"></i>
                                        <?php endif; ?>
                                        <span><?= htmlspecialchars($c['name_ar'] ?: $c['name']) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div style="display: flex; align-items: center; gap: 15px; background: #f8fafc; border: 1px solid var(--border-color); border-radius: 14px; padding: 15px;">
                    <label class="switch">
                        <input type="checkbox" name="is_active" checked>
                        <span class="slider round"></span>
                    </label>
                    <span style="color: var(--text-primary); font-size: 0.95rem; font-weight: 500;">حالة الدوري (نشط / غير نشط)</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeAddLeagueModal()">إلغاء</button>
                <button type="submit" class="btn-primary">إضافة الدوري</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit League Modal -->
<div id="editLeagueModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>تعديل بيانات الدوري</h2>
            <button type="button" class="btn-icon" onclick="closeEditLeagueModal()"><i class="fas fa-times"></i></button>
        </div>
        <form id="editLeagueForm">
            <div class="modal-body">
                <input type="hidden" name="action" value="update_league_data">
                <input type="hidden" name="id" id="edit_league_id">
                
                <!-- Protected Name Section -->
                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">
                        الاسم الأساسي (محمي)
                    </label>
                    <div style="position: relative;">
                        <input type="text" id="edit_league_name" readonly class="form-control" style="background: #f1f5f9; cursor: not-allowed; opacity: 0.7;">
                        <i class="fas fa-lock" style="position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #f59e0b; font-size: 0.9rem;"></i>
                    </div>
                </div>

                <!-- Editable Names Section -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">الاسم بالعربية</label>
                        <input type="text" name="name_ar" id="edit_league_name_ar" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">الاسم بالإنجليزية</label>
                        <input type="text" name="name_en" id="edit_league_name_en" class="form-control">
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">رابط الشعار (Logo URL)</label>
                    <input type="text" name="logo_url" id="edit_league_logo_url" class="form-control">
                </div>

                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">البلد (Country)</label>
                    <div class="custom-select-wrapper" style="position: relative;">
                        <div class="custom-select-trigger" onclick="toggleCountryDropdown()" id="selectedCountryTrigger" style="width: 100%; padding: 12px 18px; border-radius: 14px; font-size: 0.95rem; cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                            <span id="selectedCountryText">اختر البلد...</span>
                            <i class="fas fa-chevron-down" style="font-size: 0.8rem; color: var(--text-secondary);"></i>
                        </div>
                        <input type="hidden" name="country" id="edit_league_country">
                        
                        <div id="countryDropdown" style="display: none; position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid var(--border-color); border-radius: 18px; margin-top: 10px; z-index: 1000; box-shadow: 0 20px 40px rgba(0,0,0,0.1); overflow: hidden;">
                            <div style="padding: 10px; border-bottom: 1px solid var(--border-color); background: #f8fafc;">
                                <input type="text" id="countrySearchInput" placeholder="ابحث عن بلد..." onkeyup="filterCountrySelect()" class="form-control" style="padding: 8px 12px; font-size: 0.9rem;">
                            </div>
                            <div id="countryOptionsList" style="max-height: 200px; overflow-y: auto;">
                                <?php foreach($countries_list as $c): ?>
                                    <div class="country-option" 
                                         data-value="<?= htmlspecialchars($c['name']) ?>" 
                                         data-search="<?= strtolower($c['name'] . ' ' . ($c['name_ar'] ?? '') . ' ' . ($c['name_en'] ?? '')) ?>"
                                         onclick="selectCountry('<?= htmlspecialchars($c['name']) ?>', '<?= htmlspecialchars($c['name_ar'] ?: $c['name']) ?>')"
                                         style="padding: 12px 15px; cursor: pointer; color: var(--text-secondary); font-size: 0.9rem; transition: all 0.2s; display: flex; align-items: center; gap: 10px;">
                                        <?php if(!empty($c['logo_url'])): ?>
                                            <img src="<?= htmlspecialchars($c['logo_url']) ?>" style="width: 20px; height: 15px; object-fit: contain;">
                                        <?php else: ?>
                                            <i class="fas fa-globe" style="font-size: 0.8rem; color: var(--text-secondary);"></i>
                                        <?php endif; ?>
                                        <span><?= htmlspecialchars($c['name_ar'] ?: $c['name']) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Status Section -->
                <div style="display: flex; align-items: center; gap: 15px; background: #f8fafc; border: 1px solid var(--border-color); border-radius: 14px; padding: 15px;">
                    <label class="switch">
                        <input type="checkbox" name="is_active" id="edit_league_active">
                        <span class="slider round"></span>
                    </label>
                    <span style="color: var(--text-primary); font-size: 0.95rem; font-weight: 500;">حالة الدوري (نشط / غير نشط)</span>
                </div>
            </div>
            <div class="modal-footer" style="justify-content: space-between;">
                <button type="button" class="btn-danger" onclick="deleteLeague()" style="background: #ef4444; color: white; border: none; padding: 10px 20px; border-radius: 12px; font-weight: 600; cursor: pointer;">
                    <i class="fas fa-trash"></i> حذف الدوري
                </button>
                <div style="display: flex; gap: 10px;">
                    <button type="button" class="btn-secondary" onclick="closeEditLeagueModal()">إلغاء</button>
                    <button type="submit" class="btn-primary">حفظ التغييرات</button>
                </div>
            </div>
        </form>
    </div>
</div>

<style>
.leagues-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
    flex-wrap: wrap;
    gap: 15px;
}

.search-box {
    position: relative;
    flex: 1;
    max-width: 400px;
}

.search-box i {
    position: absolute;
    right: 15px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-secondary);
}

.search-box input {
    width: 100%;
    padding: 12px 45px 12px 15px;
    border-radius: 12px;
    border: 1px solid var(--glass-border);
    background: rgba(255, 255, 255, 0.05);
    color: var(--text-primary);
    font-size: 0.95rem;
    transition: all 0.3s;
}

.stats-badge {
    background: rgba(56, 189, 248, 0.1);
    color: #38bdf8;
    padding: 8px 16px;
    border-radius: 20px;
    font-size: 0.9rem;
    font-weight: 600;
}

.stats-badge.active {
    background: rgba(16, 185, 129, 0.1);
    color: #10b981;
}

.leagues-toolbar {
    display: flex;
    gap: 10px;
    align-items: center;
    justify-content: flex-end;
    flex-wrap: wrap;
    min-width: 0;
}

.leagues-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 15px;
    padding: 0px 10px;
}

.league-card {
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid var(--glass-border);
    border-radius: 12px;
    padding: 15px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    transition: all 0.3s ease;
}

.league-card:hover {
    transform: translateY(-2px);
    border-color: var(--primary);
    background: rgba(255, 255, 255, 0.05);
}

.league-info {
    display: flex;
    align-items: center;
    gap: 12px;
    flex: 1;
    min-width: 0;
}

.league-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: #ffffffff;
    border: 1px solid #dcdcdcff;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.1rem;
    flex-shrink: 0;
    box-shadow: none;
    overflow: hidden;
}

.league-icon.active-icon {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
}

.league-card-actions {
    flex-shrink: 0;
}

.league-card-body {
    min-width: 0;
    text-align: right;
}

.league-card-meta {
    flex-wrap: wrap;
}

.league-info h3 {
    font-size: 0.95rem;
    color: var(--text-primary);
    margin: 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.btn-edit-icon {
    background: rgba(56, 189, 248, 0.1);
    border: 1px solid rgba(56, 189, 248, 0.2);
    color: #38bdf8;
    width: 32px;
    height: 32px;
    border-radius: 8px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s;
}

.btn-edit-icon:hover {
    background: #38bdf8;
    color: white;
}

/* Switch Toggle */
.switch {
    position: relative;
    display: inline-block;
    width: 46px;
    height: 24px;
    flex-shrink: 0;
}

.switch input {
    opacity: 0;
    width: 0;
    height: 0;
}

.slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #abbdd6ff;
    transition: .4s;
}

.slider:before {
    position: absolute;
    content: "";
    height: 18px;
    width: 18px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: .4s;
}

input:checked + .slider {
    background-color: #10b981;
}

input:checked + .slider:before {
    transform: translateX(22px);
}

.slider.round {
    border-radius: 24px;
}

.slider.round:before {
    border-radius: 50%;
}

.country-option:hover {
    background: rgba(56, 189, 248, 0.15);
    color: #38bdf8 !important;
}

.country-option.selected {
    background: rgba(56, 189, 248, 0.2);
    color: #38bdf8 !important;
    font-weight: 600;
}

@keyframes modalSlideIn {
    from { opacity: 0; transform: translateY(-20px) scale(0.95); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}

html[data-admin-lang="en"] .leagues-page-container,
html[data-admin-lang="en"] .leagues-page-container * {
    direction: ltr;
}

html[data-admin-lang="en"] .leagues-page-container .leagues-table-header {
    align-items: flex-start;
}

html[data-admin-lang="en"] .leagues-page-container .search-box {
    max-width: 420px;
}

html[data-admin-lang="en"] .leagues-page-container .leagues-toolbar {
    justify-content: flex-end;
}

html[data-admin-lang="en"] .leagues-page-container .btn-filter,
html[data-admin-lang="en"] .leagues-page-container .filter-container,
html[data-admin-lang="en"] .leagues-page-container .filter-dropdown {
    direction: ltr;
}

html[data-admin-lang="en"] .leagues-page-container .filter-dropdown {
    left: 0;
    right: auto;
}

html[data-admin-lang="en"] .leagues-page-container .league-card {
    direction: ltr;
    text-align: left;
    padding: 10px 45px 10px 15px !important;
}

html[data-admin-lang="en"] .leagues-page-container .league-card-actions {
    left: auto !important;
    right: 10px !important;
}

html[data-admin-lang="en"] .leagues-page-container .league-card-body,
html[data-admin-lang="en"] .leagues-page-container .league-card-title {
    text-align: left !important;
}

html[data-admin-lang="en"] .leagues-page-container .league-card-meta {
    justify-content: flex-start;
}

@media (max-width: 1200px) {
    .leagues-toolbar {
        justify-content: flex-start;
    }
}
</style>

<script>
function toggleFilterDropdown() {
    document.getElementById('filterDropdown').classList.toggle('show');
}

// Close dropdown when clicking outside
window.addEventListener('click', function(e) {
    if (!e.target.closest('.filter-container')) {
        const dropdown = document.getElementById('filterDropdown');
        if (dropdown) dropdown.classList.remove('show');
    }
});

function filterLeagues() {
    // Moved to server-side search via Enter key
}

function toggleLeague(id, checkbox) {
    const status = checkbox.checked ? 1 : 0;
    const card = checkbox.closest('.league-card');
    const icon = card.querySelector('.league-icon');
    
    if (checkbox.checked) icon.classList.add('active-icon');
    else icon.classList.remove('active-icon');
    
    fetch('leagues.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=toggle_league&id=${id}&status=${status}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.status !== 'success') {
            alert('حدث خطأ أثناء تحديث الحالة');
            checkbox.checked = !checkbox.checked;
        }
    });
}

function openAddLeagueModal() {
    document.getElementById('addLeagueModal').style.display = 'flex';
}

function closeAddLeagueModal() {
    document.getElementById('addLeagueModal').style.display = 'none';
}

document.getElementById('addLeagueForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    
    fetch('leagues.php', {
        method: 'POST',
        body: new URLSearchParams(formData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            location.reload();
        } else {
            alert('خطأ: ' + data.message);
        }
    });
});

function openEditLeagueModal(league) {
    document.getElementById('edit_league_id').value = league.id;
    document.getElementById('edit_league_name').value = league.name;
    document.getElementById('edit_league_name_ar').value = league.name_ar || league.name;
    document.getElementById('edit_league_name_en').value = league.name_en || '';
    document.getElementById('edit_league_logo_url').value = league.logo_url || '';
    
    // Set Country Select
    const countryName = league.country || '';
    document.getElementById('edit_league_country').value = countryName;
    const countryOption = document.querySelector(`.country-option[data-value="${countryName}"]`);
    if (countryOption) {
        document.getElementById('selectedCountryText').innerText = countryOption.querySelector('span').innerText;
    } else {
        document.getElementById('selectedCountryText').innerText = countryName || 'اختر البلد...';
    }

    document.getElementById('edit_league_active').checked = league.is_active == 1;
    
    document.getElementById('editLeagueModal').style.display = 'flex';
}

function closeEditLeagueModal() {
    document.getElementById('editLeagueModal').style.display = 'none';
}

document.getElementById('editLeagueForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    
    fetch('leagues.php', {
        method: 'POST',
        body: new URLSearchParams(formData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            location.reload();
        } else {
            alert('خطأ: ' + data.message);
        }
    });
});

function deleteLeague() {
    const id = document.getElementById('edit_league_id').value;
    const name = document.getElementById('edit_league_name').value;
    
    if (confirm(`هل أنت متأكد من حذف الدوري "${name}"؟ سيؤدي هذا لحذف الدوري نهائياً.`)) {
        fetch('leagues.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=delete_league&id=${id}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                location.reload();
            } else {
                alert('خطأ: ' + data.message);
            }
        });
    }
}

// AI Translation Functionality
async function startAiTranslation() {
    const btn = document.getElementById('aiTranslateBtn');
    const originalContent = btn.innerHTML;
    
    if (!confirm('هل تريد بدء ترجمة الدوريات التي تنقصها الترجمة باستخدام الذكاء الاصطناعي؟')) {
        return;
    }

    try {
        btn.classList.add('loading');
        
        const response = await fetch('ajax_translate_leagues.php');
        const data = await response.json();
        
        if (data.success) {
            alert(data.message);
            if (data.count > 0) {
                window.location.reload();
            }
        } else {
            alert('خطأ: ' + (data.error || 'حدث خطأ غير متوقع'));
        }
    } catch (error) {
        console.error('AI Translation Error:', error);
        alert('حدث خطأ أثناء الاتصال بالخادم');
    } finally {
        btn.classList.remove('loading');
    }
}

// Close modal on outside click
window.onclick = function(event) {
    const addModal = document.getElementById('addLeagueModal');
    const editModal = document.getElementById('editLeagueModal');
    if (event.target == addModal) closeAddLeagueModal();
    if (event.target == editModal) closeEditLeagueModal();
    
    // Close custom select if clicking outside
    if (!event.target.closest('.custom-select-wrapper')) {
        document.getElementById('countryDropdown').style.display = 'none';
        document.getElementById('addCountryDropdown').style.display = 'none';
    }
}

function toggleCountryDropdown() {
    const dropdown = document.getElementById('countryDropdown');
    const isVisible = dropdown.style.display === 'block';
    dropdown.style.display = isVisible ? 'none' : 'block';
    if (!isVisible) {
        document.getElementById('countrySearchInput').focus();
        document.getElementById('countrySearchInput').value = '';
        filterCountrySelect();
    }
}

function toggleAddCountryDropdown() {
    const dropdown = document.getElementById('addCountryDropdown');
    const isVisible = dropdown.style.display === 'block';
    dropdown.style.display = isVisible ? 'none' : 'block';
    if (!isVisible) {
        document.getElementById('addCountrySearchInput').focus();
        document.getElementById('addCountrySearchInput').value = '';
        filterAddCountrySelect();
    }
}

function selectCountry(value, label) {
    document.getElementById('edit_league_country').value = value;
    document.getElementById('selectedCountryText').innerText = label;
    document.getElementById('countryDropdown').style.display = 'none';
    
    // Update active class
    document.querySelectorAll('#countryOptionsList .country-option').forEach(opt => {
        opt.classList.remove('selected');
        if (opt.getAttribute('data-value') === value) opt.classList.add('selected');
    });
}

function selectAddCountry(value, label) {
    document.getElementById('add_league_country').value = value;
    document.getElementById('addSelectedCountryText').innerText = label;
    document.getElementById('addCountryDropdown').style.display = 'none';
    
    // Update active class
    document.querySelectorAll('#addCountryOptionsList .country-option').forEach(opt => {
        opt.classList.remove('selected');
        if (opt.getAttribute('data-value') === value) opt.classList.add('selected');
    });
}

function filterCountrySelect() {
    const filter = document.getElementById('countrySearchInput').value.toLowerCase();
    const options = document.querySelectorAll('#countryOptionsList .country-option');
    options.forEach(opt => {
        const searchText = opt.getAttribute('data-search');
        if (searchText.includes(filter)) {
            opt.style.display = 'flex';
        } else {
            opt.style.display = 'none';
        }
    });
}

function filterAddCountrySelect() {
    const filter = document.getElementById('addCountrySearchInput').value.toLowerCase();
    const options = document.querySelectorAll('#addCountryOptionsList .country-option');
    options.forEach(opt => {
        const searchText = opt.getAttribute('data-search');
        if (searchText.includes(filter)) {
            opt.style.display = 'flex';
        } else {
            opt.style.display = 'none';
        }
    });
}
</script>

<?php require_once 'includes/footer.php'; ?>
