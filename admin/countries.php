<?php
require_once 'init.php';
require_once '../config.php';
require_once '../includes/Database.php';

$db = new Database($pdo);

// Handle Add Country
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_country') {
    header('Content-Type: application/json');
    try {
        $name = $_POST['name'];
        $nameAr = $_POST['name_ar'] ?? null;
        $nameEn = $_POST['name_en'] ?? null;
        $logoUrl = $_POST['logo_url'] ?? null;
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        
        $db->addCountry($name, $nameAr, $nameEn, $isActive, $logoUrl);
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle Update Country Data
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_country_data') {
    header('Content-Type: application/json');
    try {
        $id = $_POST['id'];
        $nameAr = $_POST['name_ar'] ?? null;
        $nameEn = $_POST['name_en'] ?? null;
        $logoUrl = $_POST['logo_url'] ?? null;
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        
        $db->updateCountry($id, $nameAr, $nameEn, $isActive, $logoUrl);
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle AJAX request for updating status only
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_country') {
    header('Content-Type: application/json');
    try {
        $id = $_POST['id'];
        $status = $_POST['status']; // 1 or 0
        $db->updateCountryStatus($id, $status);
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle Delete Country
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_country') {
    header('Content-Type: application/json');
    try {
        $id = $_POST['id'];
        $db->deleteCountry($id);
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle Fetch Leagues for Country
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_country_leagues') {
    header('Content-Type: application/json');
    try {
        $countryName = $_POST['country_name'];
        $leagues = $db->getLeaguesByCountryName($countryName);
        echo json_encode(['status' => 'success', 'leagues' => $leagues]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle Toggle League Status (Reusable from leagues.php)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_league') {
    header('Content-Type: application/json');
    try {
        $id = $_POST['id'];
        $status = $_POST['status'];
        $db->updateLeagueStatus($id, $status);
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

$filter_param = $_GET['status'] ?? 'active';
$countries = $db->getAllCountries();

// Filter countries based on status
if ($filter_param === 'active') {
    $countries = array_filter($countries, function($c) { return $c['is_active'] == 1; });
    $filter_label = "النشطة";
} elseif ($filter_param === 'inactive') {
    $countries = array_filter($countries, function($c) { return $c['is_active'] == 0; });
    $filter_label = "غير النشطة";
} else {
    $filter_label = "الكل";
}

// Sort countries: Active first, then by name
usort($countries, function($a, $b) {
    if ($a['is_active'] != $b['is_active']) {
        return $b['is_active'] - $a['is_active'];
    }
    return strcmp($a['name'], $b['name']);
});

$activeCount = count(array_filter($db->getAllCountries(), function($c) {
    return $c['is_active'];
}));
$pageTitle = 'إدارة البلدان';

require_once 'includes/header.php';
?>

<div class="table-container">
    <div class="table-header">
        <div style="display:flex; align-items:center; gap:15px;">
            <h3>
                إدارة البلدان
                <span style="color: var(--text-secondary); font-size: 0.9rem; font-weight: normal; margin-right: 10px; background: rgba(255,255,255,0.05); padding: 2px 10px; border-radius: 20px;">
                    <?= count($countries) ?> بلد
                </span>
            </h3>
            <div class="stats-badge active" style="background: rgba(16, 185, 129, 0.1); color: #10b981; padding: 5px 15px; border-radius: 20px; font-size: 0.85rem; font-weight: 600;">
                النشطة: <?= $activeCount ?>
            </div>
            <button type="button" class="btn-primary" onclick="openAddCountryModal()">
                <i class="fas fa-plus"></i> إضافة بلد
            </button>
            <button type="button" class="btn-ai" id="aiTranslateBtn" onclick="startAiTranslation()" title="ترجمة البلدان المفقودة باستخدام الذكاء الاصطناعي">
                <i class="fa-solid fa-wand-sparkles"></i>
            </button>
        </div>
        <div style="display: flex; gap: 15px; align-items: center;">
            <div class="filter-container">
                <button type="button" class="btn-filter" onclick="toggleFilterDropdown()">
                    <i class="fas fa-filter"></i>
                    <span><?= $filter_label ?></span>
                    <i class="fas fa-chevron-down" style="font-size: 0.7rem; margin-right: 5px; opacity: 0.6;"></i>
                </button>
                <div id="filterDropdown" class="filter-dropdown">
                    <a href="countries.php?status=all" class="filter-item <?= $filter_param === 'all' ? 'active' : '' ?>">
                        <span>الكل</span>
                        <i class="fas fa-check"></i>
                    </a>
                    <a href="countries.php?status=active" class="filter-item <?= $filter_param === 'active' ? 'active' : '' ?>">
                        <span>النشطة</span>
                        <i class="fas fa-check"></i>
                    </a>
                    <a href="countries.php?status=inactive" class="filter-item <?= $filter_param === 'inactive' ? 'active' : '' ?>">
                        <span>غير النشطة</span>
                        <i class="fas fa-check"></i>
                    </a>
                </div>
            </div>
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="countrySearch" placeholder="بحث عن بلد..." class="form-control" style="width: 250px;" onkeyup="filterCountries()">
            </div>
        </div>
    </div>

    <div class="countries-grid" id="countriesGrid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; padding: 20px;">
        <?php if (empty($countries)): ?>
            <div style="grid-column: 1 / -1; text-align: center; padding: 80px 20px; color: var(--text-secondary); background: rgba(255,255,255,0.02); border-radius: 20px; border: 1px dashed rgba(255,255,255,0.1);">
                <i class="fas fa-globe" style="font-size: 4rem; opacity: 0.1; margin-bottom: 20px; display: block;"></i>
                <h3 style="color: var(--text-primary); margin-bottom: 10px;">لا توجد بلدان حالياً</h3>
                <p style="font-size: 0.95rem; opacity: 0.7;">لم يتم العثور على أي بلدان تطابق الفلتر المختار.</p>
                <a href="countries.php?status=all" style="display: inline-block; margin-top: 20px; color: var(--accent); text-decoration: none; font-weight: 600; border: 1px solid var(--accent); padding: 8px 25px; border-radius: 12px; transition: all 0.3s;">عرض الكل</a>
            </div>
        <?php else: ?>
            <?php foreach ($countries as $country): ?>
            <div class="country-card" 
                data-name="<?= strtolower($country['name']) ?>" 
                data-name-ar="<?= strtolower($country['name_ar'] ?? '') ?>" 
                data-name-en="<?= strtolower($country['name_en'] ?? '') ?>"
                style="background: white; border: 1px solid var(--border-color); border-radius: 20px; padding: 20px; display: flex; align-items: center; justify-content: space-between; transition: all 0.3s ease; box-shadow: var(--card-shadow);">
                <div class="country-info" style="display: flex; align-items: center; gap: 15px; flex: 1; min-width: 0;">
                    <div class="country-icon" style="width: 45px; height: 45px; border-radius: 12px; background: #f1f5f9; display: flex; align-items: center; justify-content: center; flex-shrink: 0; overflow: hidden;">
                        <?php if(!empty($country['logo_url'])): ?>
                            <img src="<?= htmlspecialchars($country['logo_url']) ?>" style="width: 100%; height: 100%; object-fit: cover;">
                        <?php else: ?>
                            <i class="fas fa-globe" style="color: var(--text-secondary);"></i>
                        <?php endif; ?>
                    </div>
                    <div style="min-width: 0;">
                        <h3 style="font-size: 1rem; color: var(--text-primary); margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($country['name']) ?>"><?= htmlspecialchars($country['name_ar'] ?: $country['name']) ?></h3>
                        <?php if(!empty($country['name_en'])): ?>
                            <small style="color: var(--text-secondary); display: block; font-size: 0.8rem;"><?= htmlspecialchars($country['name_en']) ?></small>
                        <?php endif; ?>
                    </div>
                </div>
                <div style="display: flex; align-items: center; gap: 12px;">
                    <button type="button" class="btn-icon" onclick="openEditCountryModal(<?= htmlspecialchars(json_encode($country), ENT_QUOTES, 'UTF-8') ?>)" title="تعديل">
                        <i class="fas fa-edit"></i>
                    </button>
                    <div class="country-action">
                        <label class="switch">
                            <input type="checkbox" 
                                onchange="toggleCountry(<?= $country['id'] ?>, this)" 
                                <?= $country['is_active'] ? 'checked' : '' ?>>
                            <span class="slider round"></span>
                        </label>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Add Country Modal -->
<div id="addCountryModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>إضافة بلد جديد</h2>
            <button type="button" class="btn-icon" onclick="closeAddCountryModal()"><i class="fas fa-times"></i></button>
        </div>
        <form id="addCountryForm">
            <div class="modal-body">
                <input type="hidden" name="action" value="add_country">
                
                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">الاسم الأساسي</label>
                    <input type="text" name="name" required class="form-control" placeholder="اسم البلد الأساسي">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">الاسم بالعربية</label>
                        <input type="text" name="name_ar" class="form-control" placeholder="الاسم بالعربية">
                    </div>
                    
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">الاسم بالإنجليزية</label>
                        <input type="text" name="name_en" class="form-control" placeholder="Country Name in English">
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">رابط الشعار (Flag URL)</label>
                    <input type="text" name="logo_url" class="form-control" placeholder="https://example.com/flag.png">
                </div>

                <div style="display: flex; align-items: center; gap: 15px; background: #f8fafc; border: 1px solid var(--border-color); border-radius: 14px; padding: 15px; margin-bottom: 25px;">
                    <label class="switch">
                        <input type="checkbox" name="is_active" checked>
                        <span class="slider round"></span>
                    </label>
                    <span style="color: var(--text-primary); font-size: 0.95rem; font-weight: 500;">حالة البلد (نشط / غير نشط)</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeAddCountryModal()">إلغاء</button>
                <button type="submit" class="btn-primary">إضافة البلد</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Country Modal -->
<div id="editCountryModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>تعديل بيانات البلد</h2>
            <button type="button" class="btn-icon" onclick="closeEditCountryModal()"><i class="fas fa-times"></i></button>
        </div>
        <form id="editCountryForm">
            <div class="modal-body">
                <input type="hidden" name="action" value="update_country_data">
                <input type="hidden" name="id" id="edit_country_id">
                
                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">الاسم الأساسي (محمي)</label>
                    <input type="text" id="edit_country_name" readonly class="form-control" style="background: #f1f5f9; cursor: not-allowed; opacity: 0.7;">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">الاسم بالعربية</label>
                        <input type="text" name="name_ar" id="edit_country_name_ar" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">الاسم بالإنجليزية</label>
                        <input type="text" name="name_en" id="edit_country_name_en" class="form-control">
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">رابط الشعار (Flag URL)</label>
                    <input type="text" name="logo_url" id="edit_country_logo_url" class="form-control">
                </div>

                <div style="display: flex; align-items: center; gap: 15px; background: #f8fafc; border: 1px solid var(--border-color); border-radius: 14px; padding: 15px; margin-bottom: 25px;">
                    <label class="switch">
                        <input type="checkbox" name="is_active" id="edit_country_active">
                        <span class="slider round"></span>
                    </label>
                    <span style="color: var(--text-primary); font-size: 0.95rem; font-weight: 500;">حالة البلد (نشط / غير نشط)</span>
                </div>

                <!-- Leagues Section -->
                <div style="margin-bottom: 10px;">
                    <label style="display: block; margin-bottom: 15px; color: var(--text-primary); font-size: 1rem; font-weight: 600; border-bottom: 1px solid var(--border-color); padding-bottom: 10px;">
                        <i class="fas fa-trophy" style="color: #f59e0b; margin-left: 8px;"></i>
                        الدوريات التابعة
                    </label>
                    <div id="countryLeaguesList" style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; max-height: 250px; overflow-y: auto; padding-right: 5px;">
                        <!-- Leagues will be loaded here -->
                        <div style="grid-column: 1 / -1; text-align: center; color: var(--text-secondary); padding: 20px;">جاري تحميل الدوريات...</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="justify-content: space-between;">
                <button type="button" class="btn-danger" onclick="deleteCountry()" style="background: #ef4444; color: white; border: none; padding: 10px 20px; border-radius: 12px; font-weight: 600; cursor: pointer;">
                    <i class="fas fa-trash"></i> حذف البلد
                </button>
                <div style="display: flex; gap: 10px;">
                    <button type="button" class="btn-secondary" onclick="closeEditCountryModal()">إلغاء</button>
                    <button type="submit" class="btn-primary">حفظ التغييرات</button>
                </div>
            </div>
        </form>
    </div>
</div>

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

function filterCountries() {
    const input = document.getElementById('countrySearch');
    const filter = input.value.toLowerCase();
    const cards = document.getElementsByClassName('country-card');
    for (let i = 0; i < cards.length; i++) {
        const name = cards[i].getAttribute('data-name');
        const nameAr = cards[i].getAttribute('data-name-ar');
        const nameEn = cards[i].getAttribute('data-name-en');
        if (name.includes(filter) || nameAr.includes(filter) || nameEn.includes(filter)) {
            cards[i].style.display = "flex";
        } else {
            cards[i].style.display = "none";
        }
    }
}

function toggleCountry(id, checkbox) {
    const status = checkbox.checked ? 1 : 0;
    fetch('countries.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=toggle_country&id=${id}&status=${status}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.status !== 'success') {
            alert('حدث خطأ أثناء تحديث الحالة');
            checkbox.checked = !checkbox.checked;
        }
    });
}

function openAddCountryModal() {
    document.getElementById('addCountryModal').style.display = 'flex';
}

function closeAddCountryModal() {
    document.getElementById('addCountryModal').style.display = 'none';
}

document.getElementById('addCountryForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    fetch('countries.php', {
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

function openEditCountryModal(country) {
    document.getElementById('edit_country_id').value = country.id;
    document.getElementById('edit_country_name').value = country.name;
    document.getElementById('edit_country_name_ar').value = country.name_ar || country.name;
    document.getElementById('edit_country_name_en').value = country.name_en || '';
    document.getElementById('edit_country_logo_url').value = country.logo_url || '';
    document.getElementById('edit_country_active').checked = country.is_active == 1;
    document.getElementById('editCountryModal').style.display = 'flex';

    // Load Leagues
    loadCountryLeagues(country.name);
}

function loadCountryLeagues(countryName) {
    const list = document.getElementById('countryLeaguesList');
    list.innerHTML = '<div style="grid-column: 1 / -1; text-align: center; color: var(--text-secondary); padding: 20px;"><i class="fas fa-spinner fa-spin"></i> جاري التحميل...</div>';

    fetch('countries.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=get_country_leagues&country_name=${encodeURIComponent(countryName)}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            if (data.leagues.length === 0) {
                list.innerHTML = '<div style="grid-column: 1 / -1; text-align: center; color: var(--text-secondary); padding: 20px;">لا توجد دوريات تابعة لهذا البلد</div>';
                return;
            }

            let html = '';
            data.leagues.forEach(league => {
                html += `
                    <div style="display: flex; align-items: center; justify-content: space-between; background: #f8fafc; padding: 10px 15px; border-radius: 12px; border: 1px solid var(--border-color);">
                        <div style="display: flex; align-items: center; gap: 10px; min-width: 0;">
                            ${league.logo_url ? `<img src="${league.logo_url}" style="width: 24px; height: 24px; object-fit: contain;">` : '<i class="fas fa-trophy" style="color: #f59e0b; font-size: 0.9rem;"></i>'}
                            <span style="color: var(--text-primary); font-size: 0.85rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${league.name_ar || league.name}</span>
                        </div>
                        <label class="switch" style="transform: scale(0.8);">
                            <input type="checkbox" onchange="toggleLeagueStatus(${league.id}, this)" ${league.is_active == 1 ? 'checked' : ''}>
                            <span class="slider round"></span>
                        </label>
                    </div>
                `;
            });
            list.innerHTML = html;
        } else {
            list.innerHTML = '<div style="grid-column: 1 / -1; text-align: center; color: #ef4444; padding: 20px;">خطأ في تحميل الدوريات</div>';
        }
    });
}

function toggleLeagueStatus(id, checkbox) {
    const status = checkbox.checked ? 1 : 0;
    fetch('countries.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=toggle_league&id=${id}&status=${status}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.status !== 'success') {
            alert('حدث خطأ أثناء تحديث حالة الدوري');
            checkbox.checked = !checkbox.checked;
        }
    });
}

function closeEditCountryModal() {
    document.getElementById('editCountryModal').style.display = 'none';
}

document.getElementById('editCountryForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    fetch('countries.php', {
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

function deleteCountry() {
    const id = document.getElementById('edit_country_id').value;
    const name = document.getElementById('edit_country_name').value;
    
    if (confirm(`هل أنت متأكد من حذف البلد "${name}"؟ سيؤدي هذا لحذف البلد نهائياً.`)) {
        fetch('countries.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=delete_country&id=${id}`
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
    
    if (!confirm('هل تريد بدء ترجمة البلدان التي تنقصها الترجمة باستخدام الذكاء الاصطناعي؟')) {
        return;
    }

    try {
        btn.classList.add('loading');
        
        const response = await fetch('ajax_translate_countries.php');
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

window.onclick = function(event) {
    const addModal = document.getElementById('addCountryModal');
    const editModal = document.getElementById('editCountryModal');
    if (event.target == addModal) closeAddCountryModal();
    if (event.target == editModal) closeEditCountryModal();
}
</script>

<?php require_once 'includes/footer.php'; ?>
