<?php
require_once 'init.php';
require_once '../config.php';
require_once '../includes/Database.php';

$db = new Database($pdo);

// Handle Add Team
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_team'])) {
    $db->addTeam(
        $_POST['name'],
        $_POST['logo_url'],
        $_POST['name_en'] ?? null,
        $_POST['name_ar'] ?? null
    );
    header('Location: teams.php?msg=added');
    exit;
}

// Handle Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_team'])) {
    $db->updateTeam(
        $_POST['id'], 
        $_POST['logo_url'],
        $_POST['name_en'] ?? null,
        $_POST['name_ar'] ?? null
    );
    header('Location: teams.php?msg=updated');
    exit;
}

// Handle Delete Selected
if (isset($_POST['delete_selected']) && isset($_POST['selected_ids'])) {
    foreach ($_POST['selected_ids'] as $id) {
        $db->deleteTeam($id);
    }
    header('Location: teams.php?msg=deleted_bulk');
    exit;
}

$filter_param = $_GET['date'] ?? 'today';
$filter_date = null;
$search = $_GET['search'] ?? null;
$filter_type = $_GET['filter_type'] ?? 'all';

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 50;
$offset = ($page - 1) * $limit;

if ($filter_param === 'all') {
    $filter_date = null;
    $filter_label = "الكل";
} else {
    $filter_date = ($filter_param === 'today') ? date('Y-m-d') : $filter_param;
    
    if ($filter_date == date('Y-m-d')) $filter_label = "اليوم";
    elseif ($filter_date == date('Y-m-d', strtotime('+1 day'))) $filter_label = "الغد";
    elseif ($filter_date == date('Y-m-d', strtotime('-1 day'))) $filter_label = "الأمس";
    else $filter_label = $filter_date;
}

$teams = $db->getAllTeams($filter_date, $limit, $offset, $search, $filter_type);
$totalTeams = $db->getTeamsCount($filter_date, $search, $filter_type);
$totalPages = ceil($totalTeams / $limit);

// Filter Labels Mapping
$filterLabels = [
    'all' => 'الكل',
    'default_logo' => 'شعار افتراضي',
    'external_logo' => 'شعار خارجي',
    'both' => 'اللغتين معاً',
    'ar' => 'ترجمة عربية',
    'en' => 'ترجمة إنجليزية',
    'missing' => 'نقص في الترجمة'
];
$current_filter_label = $filterLabels[$filter_type] ?? 'فلتر';

$pageTitle = 'إدارة الفرق';
require_once 'includes/header.php';
?>

<div class="table-container">
    <form method="POST" id="bulkActionForm">
        <input type="hidden" name="delete_selected" value="1">
        
        <div class="table-header">
            <div style="display:flex; align-items:center; gap:15px;">
                <h3>
                    <?php 
                        if($filter_param === 'all') echo "جميع الفرق";
                        elseif($filter_date == date('Y-m-d')) echo "فرق مباريات اليوم";
                        elseif($filter_date == date('Y-m-d', strtotime('+1 day'))) echo "فرق مباريات الغد";
                        elseif($filter_date == date('Y-m-d', strtotime('-1 day'))) echo "فرق مباريات الأمس";
                        else echo "فرق تاريخ: " . $filter_date;
                    ?>
                    <span style="color: var(--text-secondary); font-size: 0.9rem; font-weight: normal; margin-right: 10px; background: rgba(255,255,255,0.05); padding: 2px 10px; border-radius: 20px;">
                        <?= $totalTeams ?> فريق
                    </span>
                </h3>
                <div style="display:flex; align-items:center; gap:10px;">
                    <label style="display:flex; align-items:center; gap:5px; cursor:pointer;">
                        <input type="checkbox" id="selectAll" onclick="toggleSelectAll(this)">
                        <span>تحديد الكل</span>
                    </label>
                    <button type="submit" class="btn-danger" id="deleteSelectedBtn" style="display:none;" onclick="return confirm('هل أنت متأكد من حذف الفرق المحددة؟')">
                        <i class="fas fa-trash"></i> حذف المحدد
                    </button>
                    <button type="button" class="btn-primary" onclick="openAddModal()">
                        <i class="fas fa-plus"></i> إضافة فريق
                    </button>
                    <button type="button" class="btn-ai" id="aiTranslateBtn" onclick="startAiTranslation()" title="ترجمة الفرق المفقودة باستخدام الذكاء الاصطناعي">
                        <i class="fa-solid fa-wand-sparkles"></i>
                    </button>
                </div>
            </div>
            <div style="display: flex; gap: 15px; align-items: center;">
                <div class="filter-container">
                    <button type="button" class="btn-filter" onclick="toggleFilterDropdown()">
                        <i class="fas fa-calendar-alt"></i>
                        <span><?= $filter_label ?></span>
                        <i class="fas fa-chevron-down" style="font-size: 0.7rem; margin-right: 5px; opacity: 0.6;"></i>
                    </button>
                    <div id="filterDropdown" class="filter-dropdown">
                        <a href="teams.php?date=all" class="filter-item <?= $filter_param === 'all' ? 'active' : '' ?>">
                            <span>الكل</span>
                            <i class="fas fa-check"></i>
                        </a>
                        <a href="teams.php?date=today" class="filter-item <?= ($filter_param === 'today' || ($filter_param !== 'all' && $filter_date == date('Y-m-d'))) ? 'active' : '' ?>">
                            <span>اليوم</span>
                            <i class="fas fa-check"></i>
                        </a>
                        <a href="teams.php?date=<?= date('Y-m-d', strtotime('+1 day')) ?>" class="filter-item <?= $filter_param == date('Y-m-d', strtotime('+1 day')) ? 'active' : '' ?>">
                            <span>الغد</span>
                            <i class="fas fa-check"></i>
                        </a>
                        <a href="teams.php?date=<?= date('Y-m-d', strtotime('-1 day')) ?>" class="filter-item <?= $filter_param == date('Y-m-d', strtotime('-1 day')) ? 'active' : '' ?>">
                            <span>الأمس</span>
                            <i class="fas fa-check"></i>
                        </a>
                        <div class="date-input-container">
                            <label>تاريخ مخصص</label>
                            <input type="date" id="customDateFilter" value="<?= $filter_date ?>" onchange="window.location.href='teams.php?date=' + this.value">
                        </div>
                    </div>
                </div>

                <div class="filter-container">
                    <button type="button" class="btn-filter" onclick="toggleFilterOptionsDropdown()">
                        <i class="fas fa-filter"></i>
                        <span id="filterLabel"><?= $current_filter_label ?></span>
                        <i class="fas fa-chevron-down" style="font-size: 0.7rem; margin-right: 5px; opacity: 0.6;"></i>
                    </button>
                    <div id="filterOptionsDropdown" class="filter-dropdown">
                        <?php foreach($filterLabels as $key => $label): ?>
                        <a href="teams.php?date=<?= $filter_param ?>&search=<?= $search ?>&filter_type=<?= $key ?>" class="filter-item <?= $filter_type === $key ? 'active' : '' ?>">
                            <span><?= $label ?></span>
                            <i class="fas fa-check"></i>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchInput" name="search" value="<?= htmlspecialchars($search ?? '') ?>" placeholder="بحث عن فريق..." class="form-control" style="width: 250px;" onkeydown="if(event.key === 'Enter') { window.location.href='teams.php?date=<?= $filter_param ?>&filter_type=<?= $filter_type ?>&search=' + this.value; return false; }">
                </div>
            </div>
        </div>
        
        <div class="info-grid" id="teamsGrid" style="padding: 20px; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));">
            <!-- No Teams Message (Used by both PHP and JS) -->
            <div id="noTeamsMessage" style="<?= empty($teams) ? '' : 'display: none;' ?> grid-column: 1 / -1; text-align: center; padding: 80px 20px; color: var(--text-secondary); background: rgba(255,255,255,0.02); border-radius: 20px; border: 1px dashed rgba(255,255,255,0.1);">
                <i class="fas fa-users-slash" style="font-size: 4rem; opacity: 0.1; margin-bottom: 20px; display: block;"></i>
                <h3 style="color: #f1f5f9; margin-bottom: 10px;">لا توجد فرق حالياً</h3>
                <p style="font-size: 0.95rem; opacity: 0.7;">لم يتم العثور على أي فرق تطابق الفلاتر المختارة.</p>
                <a href="teams.php?date=all" style="display: inline-block; margin-top: 20px; color: #38bdf8; text-decoration: none; font-weight: 600; border: 1px solid rgba(56, 189, 248, 0.3); padding: 8px 25px; border-radius: 12px; transition: all 0.3s;" onmouseover="this.style.background='rgba(56, 189, 248, 0.1)'" onmouseout="this.style.background='transparent'">عرض جميع الفرق</a>
            </div>

            <?php if (!empty($teams)): ?>
                <?php foreach ($teams as $team): ?>
                <div class="stat-card team-card" 
                     data-team-name="<?= htmlspecialchars($team['name']) ?>" 
                     data-team-name-en="<?= htmlspecialchars($team['name_en'] ?? '') ?>" 
                     data-team-name-ar="<?= htmlspecialchars($team['name_ar'] ?? '') ?>" 
                     data-team-logo="<?= htmlspecialchars($team['logo_url'] ?? '') ?>"
                     style="flex-direction: column; align-items: center; text-align: center; gap: 15px; position:relative;">
                    <div style="position:absolute; top:10px; right:10px;">
                        <input type="checkbox" name="selected_ids[]" value="<?= $team['id'] ?>" class="team-checkbox" onclick="updateDeleteBtn()">
                    </div>
                    
                    <div class="team-logo" style="width: 80px; height: 80px;">
                        <?php if($team['logo_url']): ?>
                            <img src="<?= htmlspecialchars($team['logo_url']) ?>" alt="<?= htmlspecialchars($team['name']) ?>">
                        <?php else: ?>
                            <div class="placeholder-logo" style="font-size: 2rem;"><?= mb_substr($team['name'], 0, 1) ?></div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="team-info">
                        <h4 style="color: var(--text-primary); margin-bottom: 5px;"><?= htmlspecialchars($team['name']) ?></h4>
                        <?php if(!empty($team['name_en'])): ?>
                            <p style="color: var(--text-secondary); font-size: 0.85rem; margin-bottom: 3px;"><?= htmlspecialchars($team['name_en']) ?></p>
                        <?php endif; ?>
                        <p style="color: var(--text-secondary); font-size: 0.8rem;">ID: <?= $team['id'] ?></p>
                    </div>

                    
                    <div style="display:flex; gap:10px; width:100%;">
                        <button type="button" class="btn-primary btn-t3dil" style="flex:1; justify-content:center; font-size:0.8rem; margin-top: 15px; border-radius: 10px; background: #dbe6fcff; color: var(--text-primary);" onclick="openEditModal(<?= htmlspecialchars(json_encode($team), ENT_QUOTES, 'UTF-8') ?>)">
                            <i class="fas fa-edit"></i> تعديل
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination" style="display: flex; justify-content: center; align-items: center; gap: 8px; margin: 30px 0;">
            <?php if ($page > 1): ?>
                <a href="teams.php?page=1&date=<?= $filter_param ?>&search=<?= $search ?>&filter_type=<?= $filter_type ?>" class="btn btn-secondary" title="الصفحة الأولى"><i class="fas fa-angle-double-right"></i></a>
                <a href="teams.php?page=<?= $page - 1 ?>&date=<?= $filter_param ?>&search=<?= $search ?>&filter_type=<?= $filter_type ?>" class="btn btn-secondary" title="السابق"><i class="fas fa-angle-right"></i></a>
            <?php endif; ?>

            <?php
            $range = 2;
            $start = max(1, $page - $range);
            $end = min($totalPages, $page + $range);

            if ($start > 1) {
                echo '<a href="teams.php?page=1&date='.$filter_param.'&search='.$search.'&filter_type='.$filter_type.'" class="btn btn-secondary">1</a>';
                if ($start > 2) echo '<span style="color: var(--text-secondary); padding: 0 5px;">...</span>';
            }

            for ($i = $start; $i <= $end; $i++) {
                $activeClass = ($page == $i) ? 'btn-primary' : 'btn-secondary';
                echo '<a href="teams.php?page='.$i.'&date='.$filter_param.'&search='.$search.'&filter_type='.$filter_type.'" class="btn '.$activeClass.'">'.$i.'</a>';
            }

            if ($end < $totalPages) {
                if ($end < $totalPages - 1) echo '<span style="color: var(--text-secondary); padding: 0 5px;">...</span>';
                echo '<a href="teams.php?page='.$totalPages.'&date='.$filter_param.'&search='.$search.'&filter_type='.$filter_type.'" class="btn btn-secondary">'.$totalPages.'</a>';
            }
            ?>

            <?php if ($page < $totalPages): ?>
                <a href="teams.php?page=<?= $page + 1 ?>&date=<?= $filter_param ?>&search=<?= $search ?>&filter_type=<?= $filter_type ?>" class="btn btn-secondary" title="التالي"><i class="fas fa-angle-left"></i></a>
                <a href="teams.php?page=<?= $totalPages ?>&date=<?= $filter_param ?>&search=<?= $search ?>&filter_type=<?= $filter_type ?>" class="btn btn-secondary" title="الصفحة الأخيرة"><i class="fas fa-angle-double-left"></i></a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </form>
</div>

<!-- Add Team Modal -->
<div id="addModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>إضافة فريق جديد</h2>
            <button type="button" class="btn-icon" onclick="closeAddModal()"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="add_team" value="1">
                
                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">الاسم الأساسي</label>
                    <input type="text" name="name" required class="form-control" placeholder="اسم الفريق الأساسي">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">الاسم بالعربية</label>
                        <input type="text" name="name_ar" class="form-control" placeholder="الاسم بالعربية">
                    </div>
                    
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">الاسم بالإنجليزية</label>
                        <input type="text" name="name_en" class="form-control" placeholder="Team Name in English">
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">رابط الشعار (Logo URL)</label>
                    <input type="url" name="logo_url" id="add_logo" class="form-control" placeholder="https://example.com/logo.png">
                </div>
                
                <div id="addLogoPreview" style="display: none; text-align: center; margin-top: 15px; padding: 20px; background: #f8fafc; border: 2px dashed var(--border-color); border-radius: 14px;">
                    <label style="display: block; margin-bottom: 12px; color: var(--accent); font-size: 0.85rem; font-weight: 600;">معاينة الشعار</label>
                    <div style="display: flex; justify-content: center; align-items: center; min-height: 100px;">
                        <img id="addLogoPreviewImg" src="" alt="معاينة" style="max-width: 100px; max-height: 100px; object-fit: contain;">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeAddModal()">إلغاء</button>
                <button type="submit" class="btn-primary">إضافة الفريق</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Team Modal -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>تعديل بيانات الفريق</h2>
            <button type="button" class="btn-icon" onclick="closeEditModal()"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="update_team" value="1">
                <input type="hidden" name="id" id="edit_id">
                
                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">الاسم الأساسي (محمي)</label>
                    <input type="text" id="edit_name" readonly class="form-control" style="background: #f1f5f9; cursor: not-allowed; opacity: 0.7;">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">الاسم بالعربية</label>
                        <input type="text" name="name_ar" id="edit_name_ar" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">الاسم بالإنجليزية</label>
                        <input type="text" name="name_en" id="edit_name_en" class="form-control" placeholder="Team Name in English">
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">رابط الشعار (Logo URL)</label>
                    <input type="url" name="logo_url" id="edit_logo" class="form-control" placeholder="https://example.com/logo.png">
                </div>
                
                <div id="logoPreview" style="display: none; text-align: center; margin-top: 15px; padding: 20px; background: #f8fafc; border: 2px dashed var(--border-color); border-radius: 14px;">
                    <label style="display: block; margin-bottom: 12px; color: var(--accent); font-size: 0.85rem; font-weight: 600;">معاينة الشعار</label>
                    <div style="display: flex; justify-content: center; align-items: center; min-height: 100px;">
                        <img id="logoPreviewImg" src="" alt="معاينة" style="max-width: 100px; max-height: 100px; object-fit: contain;">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeEditModal()">إلغاء</button>
                <button type="submit" class="btn-primary">حفظ التغييرات</button>
            </div>
        </form>
    </div>
</div>


<style>
    .btn-t3dil:hover {
        background-color: var(--accent) !important;
        color: #fff !important;
    }
</style>

<script>
// Search functionality
document.getElementById('searchInput').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        window.location.href = 'teams.php?date=<?= $filter_param ?>&filter_type=<?= $filter_type ?>&search=' + encodeURIComponent(this.value);
    }
});

function toggleFilterDropdown() {
    document.getElementById('filterDropdown').classList.toggle('show');
    document.getElementById('filterOptionsDropdown').classList.remove('show');
}

function toggleFilterOptionsDropdown() {
    document.getElementById('filterOptionsDropdown').classList.toggle('show');
    document.getElementById('filterDropdown').classList.remove('show');
}

function toggleSelectAll(source) {
    const checkboxes = document.querySelectorAll('.team-checkbox');
    checkboxes.forEach(cb => cb.checked = source.checked);
    updateDeleteBtn();
}

function updateDeleteBtn() {
    const checkboxes = document.querySelectorAll('.team-checkbox:checked');
    const btn = document.getElementById('deleteSelectedBtn');
    if (checkboxes.length > 0) {
        btn.style.display = 'flex';
        btn.innerHTML = `<i class="fas fa-trash"></i> حذف المحدد (${checkboxes.length})`;
    } else {
        btn.style.display = 'none';
    }
}

function openAddModal() {
    document.getElementById('addModal').style.display = 'flex';
}

function closeAddModal() {
    document.getElementById('addModal').style.display = 'none';
    document.getElementById('addLogoPreview').style.display = 'none';
}

function openEditModal(team) {
    document.getElementById('edit_id').value = team.id;
    document.getElementById('edit_name').value = team.name;
    document.getElementById('edit_name_ar').value = team.name_ar || team.name;
    document.getElementById('edit_name_en').value = team.name_en || '';
    document.getElementById('edit_logo').value = team.logo_url || '';
    
    // Show logo preview if exists
    if (team.logo_url) {
        showLogoPreview(team.logo_url, 'logoPreview', 'logoPreviewImg');
    } else {
        hideLogoPreview('logoPreview');
    }
    
    document.getElementById('editModal').style.display = 'flex';
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
    hideLogoPreview('logoPreview');
}

function showLogoPreview(url, previewId, imgId) {
    const preview = document.getElementById(previewId);
    const img = document.getElementById(imgId);
    img.src = url;
    preview.style.display = 'block';
}

function hideLogoPreview(previewId) {
    const preview = document.getElementById(previewId);
    preview.style.display = 'none';
}

// Logo preview on URL input change
document.getElementById('edit_logo').addEventListener('input', function(e) {
    const url = e.target.value.trim();
    if (url) {
        showLogoPreview(url, 'logoPreview', 'logoPreviewImg');
    } else {
        hideLogoPreview('logoPreview');
    }
});

document.getElementById('add_logo').addEventListener('input', function(e) {
    const url = e.target.value.trim();
    if (url) {
        showLogoPreview(url, 'addLogoPreview', 'addLogoPreviewImg');
    } else {
        hideLogoPreview('addLogoPreview');
    }
});

// AI Translation Functionality
async function startAiTranslation() {
    const btn = document.getElementById('aiTranslateBtn');
    const originalContent = btn.innerHTML;
    
    if (!confirm('هل تريد بدء ترجمة الفرق التي تنقصها الترجمة الإنجليزية لمباريات اليوم باستخدام الذكاء الاصطناعي؟')) {
        return;
    }

    try {
        btn.classList.add('loading');
        
        const response = await fetch('ajax_translate_teams.php');
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

// Close modal when clicking outside
window.addEventListener('click', function(e) {
    const addModal = document.getElementById('addModal');
    const editModal = document.getElementById('editModal');
    if (e.target === addModal) closeAddModal();
    if (e.target === editModal) closeEditModal();
    
    if (!e.target.closest('.filter-container')) {
        const dropdown = document.getElementById('filterDropdown');
        if (dropdown) dropdown.classList.remove('show');
        const filterOptionsDropdown = document.getElementById('filterOptionsDropdown');
        if (filterOptionsDropdown) filterOptionsDropdown.classList.remove('show');
    }
});

// Close modal with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeAddModal();
        closeEditModal();
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>
