<?php
require_once 'init.php';
require_once '../config.php';
require_once '../includes/Database.php';

$db = new Database($pdo);

// Handle Delete Single
if (isset($_POST['delete_id'])) {
    $db->deleteMatch($_POST['delete_id']);
    header('Location: matches.php?msg=deleted');
    exit;
}

// Handle Delete Selected
if (isset($_POST['delete_selected']) && isset($_POST['selected_ids'])) {
    foreach ($_POST['selected_ids'] as $id) {
        $db->deleteMatch($id);
    }
    header('Location: matches.php?msg=deleted_bulk');
    exit;
}

// Handle Add Match
if (isset($_POST['add_match'])) {
    $matchData = [
        'home_team' => $_POST['home_team'],
        'home_team_logo' => $_POST['home_team_logo'] ?? null,
        'away_team' => $_POST['away_team'],
        'away_team_logo' => $_POST['away_team_logo'] ?? null,
        'match_date' => $_POST['match_date'],
        'match_time' => $_POST['match_time'],
        'status' => 'Scheduled',
        'score_home' => 0,
        'score_away' => 0,
        'league' => $_POST['league'],
        'live_iframe' => $_POST['live_iframe'] ?? null
    ];
    $db->saveMatch($matchData);
    header('Location: matches.php?msg=added');
    exit;
}

// Handle Update Match
if (isset($_POST['update_match'])) {
    $matchData = [
        'home_team' => $_POST['home_team'],
        'home_team_logo' => $_POST['home_team_logo'] ?? null,
        'away_team' => $_POST['away_team'],
        'away_team_logo' => $_POST['away_team_logo'] ?? null,
        'match_date' => $_POST['match_date'],
        'match_time' => $_POST['match_time'],
        'status' => $_POST['status'],
        'score_home' => $_POST['score_home'],
        'score_away' => $_POST['score_away'],
        'league_name' => $_POST['league'],
        'live_iframe' => $_POST['live_iframe'] ?? null
    ];
    $db->updateMatch($_POST['match_id'], $matchData);
    header('Location: matches.php?msg=updated');
    exit;
}


$filter_param = $_GET['date'] ?? 'today';
$filter_date = null;
$search = $_GET['search'] ?? null;

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 30;
$offset = ($page - 1) * $limit;

if ($filter_param === 'all') {
    $filter_date = null;
    $filter_label = "الكل";
} elseif ($filter_param === 'missing') {
    $filter_date = 'missing';
    $filter_label = "مباريات ناقصة";
} else {
    $filter_date = ($filter_param === 'today') ? date('Y-m-d') : $filter_param;
    
    if ($filter_date == date('Y-m-d')) $filter_label = "اليوم";
    elseif ($filter_date == date('Y-m-d', strtotime('+1 day'))) $filter_label = "الغد";
    elseif ($filter_date == date('Y-m-d', strtotime('-1 day'))) $filter_label = "الأمس";
    else $filter_label = $filter_date;
}

$matches = $db->getAllMatches($filter_date, $limit, $offset, $search);
$totalMatches = $db->getMatchesCount($filter_date, $search);
$totalPages = ceil($totalMatches / $limit);

$pageTitle = 'إدارة المباريات';
require_once 'includes/header.php';
?>

<div class="table-container">
    <form method="POST" id="bulkActionForm">
        <input type="hidden" name="delete_selected" value="1">
        
        <div class="table-header">
            <div style="display:flex; align-items:center; gap:15px;">
                <h3>
                    <?php 
                        if($filter_param === 'all') echo "جميع المباريات";
                        elseif($filter_date == date('Y-m-d')) echo "مباريات اليوم";
                        elseif($filter_date == date('Y-m-d', strtotime('+1 day'))) echo "مباريات الغد";
                        elseif($filter_date == date('Y-m-d', strtotime('-1 day'))) echo "مباريات الأمس";
                        elseif($filter_param === 'missing') echo "مباريات ناقصة البيانات";
                        else echo "مباريات تاريخ: " . $filter_date;
                    ?>
                    <span style="color: var(--text-secondary); font-size: 0.9rem; font-weight: normal; margin-right: 10px; background: rgba(255,255,255,0.05); padding: 2px 10px; border-radius: 20px;">
                        <?= $totalMatches ?> مباراة
                    </span>
                </h3>
                <button type="submit" class="btn-danger" id="deleteSelectedBtn" style="display:none;" onclick="return confirm('هل أنت متأكد من حذف المباريات المحددة؟')">
                    <i class="fas fa-trash"></i> حذف المحدد
                </button>
            </div>
            <div style="display: flex; gap: 15px; align-items: center;">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="matchSearch" class="form-control" name="search" value="<?= htmlspecialchars($search ?? '') ?>" placeholder="بحث عن مباراة أو دوري..." style="width: 250px;" onkeydown="if(event.key === 'Enter') { window.location.href='matches.php?date=<?= $filter_param ?>&search=' + encodeURIComponent(this.value); return false; }">
                </div>
                <div class="filter-container">
                    <button type="button" class="btn-filter" onclick="toggleFilterDropdown()">
                        <i class="fas fa-calendar-alt"></i>
                        <span><?= $filter_label ?></span>
                        <i class="fas fa-chevron-down" style="font-size: 0.7rem; margin-right: 5px; opacity: 0.6;"></i>
                    </button>
                    <div id="filterDropdown" class="filter-dropdown">
                        <a href="matches.php?date=all&search=<?= $search ?>" class="filter-item <?= $filter_param === 'all' ? 'active' : '' ?>">
                            <span>الكل</span>
                            <i class="fas fa-check"></i>
                        </a>
                        <a href="matches.php?date=today&search=<?= $search ?>" class="filter-item <?= ($filter_param === 'today' || ($filter_param !== 'all' && $filter_date == date('Y-m-d'))) ? 'active' : '' ?>">
                            <span>اليوم</span>
                            <i class="fas fa-check"></i>
                        </a>
                        <a href="matches.php?date=<?= date('Y-m-d', strtotime('+1 day')) ?>&search=<?= $search ?>" class="filter-item <?= $filter_param == date('Y-m-d', strtotime('+1 day')) ? 'active' : '' ?>">
                            <span>الغد</span>
                            <i class="fas fa-check"></i>
                        </a>
                        <a href="matches.php?date=<?= date('Y-m-d', strtotime('-1 day')) ?>&search=<?= $search ?>" class="filter-item <?= $filter_param == date('Y-m-d', strtotime('-1 day')) ? 'active' : '' ?>">
                            <span>الأمس</span>
                            <i class="fas fa-check"></i>
                        </a>
                        <a href="matches.php?date=missing&search=<?= $search ?>" class="filter-item <?= $filter_param === 'missing' ? 'active' : '' ?>">
                            <span>مباريات ناقصة</span>
                            <i class="fas fa-check"></i>
                        </a>
                        <div class="date-input-container">
                            <label>تاريخ مخصص</label>
                            <input type="date" id="customDateFilter" value="<?= $filter_date !== 'missing' ? $filter_date : '' ?>" onchange="window.location.href='matches.php?search=<?= $search ?>&date=' + this.value">
                        </div>
                    </div>
                </div>
                <button type="button" class="btn-primary btn-add-match" onclick="openAddModal()">
                    <i class="fas fa-plus"></i> إضافة مباراة
                </button>
            </div>
        </div>
        
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th style="width: 40px;">
                            <input type="checkbox" id="selectAll" onclick="toggleSelectAll(this)">
                        </th>
                        <th>التاريخ</th>
                        <th>الوقت</th>
                        <th>الفريق المضيف</th>
                        <th>النتيجة</th>
                        <th>الفريق الضيف</th>
                        <th>الحالة</th>
                        <th>الدوري</th>
                        <th>إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($matches)): ?>
                    <tr>
                        <td colspan="9" style="text-align: center; padding: 50px; color: var(--text-secondary);">
                            <div style="display: flex; flex-direction: column; align-items: center; gap: 15px; margin: 30px;">
                                <i class="fas fa-calendar-times" style="font-size: 3rem; opacity: 0.2;"></i>
                                <span style="font-size: 1.1rem; font-weight: 500;">لا توجد مباريات في هذا التاريخ</span>
                                <a href="matches.php?date=all" style="color: var(--primary); text-decoration: none; font-size: 0.9rem; border: 1px solid var(--primary); padding: 5px 15px; border-radius: 20px; margin-top: 10px;">عرض جميع المباريات</a>
                            </div>
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($matches as $match): ?>
                        <tr class="match-row">
                            <td>
                                <input type="checkbox" name="selected_ids[]" value="<?= $match['id'] ?>" class="match-checkbox" onclick="updateDeleteBtn()">
                            </td>
                            <td><?= $match['match_date'] ?></td>
                            <td><?= !empty($match['details_match_time']) ? htmlspecialchars($match['details_match_time']) : date('H:i', strtotime($match['match_time'])) ?></td>
                            <td>
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <?php if($match['home_logo']): ?>
                                        <img src="<?= htmlspecialchars($match['home_logo']) ?>" style="width:24px; height:24px;">
                                    <?php endif; ?>
                                    <?= htmlspecialchars($match['home_team'] ?? 'فريق غير معروف') ?>
                                </div>
                            </td>
                            <td style="font-weight:bold; color:var(--accent); text-align:center;">
                                <?= $match['score_home'] ?> - <?= $match['score_away'] ?>
                            </td>
                            <td>
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <?php if($match['away_logo']): ?>
                                        <img src="<?= htmlspecialchars($match['away_logo']) ?>" style="width:24px; height:24px;">
                                    <?php endif; ?>
                                    <?= htmlspecialchars($match['away_team'] ?? 'فريق غير معروف') ?>
                                </div>
                            </td>
                            <td>
                                <span class="match-status-badge <?= strtolower($match['status']) ?>" style="font-size:0.8rem; padding:4px 8px;">
                                    <?= $match['status'] ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($match['league_name']) ?></td>
                            <td>
                                <div style="display:flex; gap:5px; justify-content:flex-end;">
                                    <button type="button" class="btn-icon" title="تعديل" onclick="openEditModal(<?= htmlspecialchars(json_encode($match), ENT_QUOTES, 'UTF-8') ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button type="button" class="btn-icon" style="color:var(--danger); background:rgba(248,113,113,0.1);" title="حذف" onclick="deleteSingle(<?= $match['id'] ?>)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination" style="display: flex; justify-content: center; align-items: center; gap: 8px; margin: 30px 0;">
            <?php if ($page > 1): ?>
                <a href="matches.php?page=1&date=<?= $filter_param ?>&search=<?= $search ?>" class="btn btn-secondary" title="الصفحة الأولى"><i class="fas fa-angle-double-right"></i></a>
                <a href="matches.php?page=<?= $page - 1 ?>&date=<?= $filter_param ?>&search=<?= $search ?>" class="btn btn-secondary" title="السابق"><i class="fas fa-angle-right"></i></a>
            <?php endif; ?>

            <?php
            $range = 2;
            $start = max(1, $page - $range);
            $end = min($totalPages, $page + $range);

            if ($start > 1) {
                echo '<a href="matches.php?page=1&date='.$filter_param.'&search='.$search.'" class="btn btn-secondary">1</a>';
                if ($start > 2) echo '<span style="color: var(--text-secondary); padding: 0 5px;">...</span>';
            }

            for ($i = $start; $i <= $end; $i++) {
                $activeClass = ($page == $i) ? 'btn-primary' : 'btn-secondary';
                echo '<a href="matches.php?page='.$i.'&date='.$filter_param.'&search='.$search.'" class="btn '.$activeClass.'">'.$i.'</a>';
            }

            if ($end < $totalPages) {
                if ($end < $totalPages - 1) echo '<span style="color: var(--text-secondary); padding: 0 5px;">...</span>';
                echo '<a href="matches.php?page='.$totalPages.'&date='.$filter_param.'&search='.$search.'" class="btn btn-secondary">'.$totalPages.'</a>';
            }
            ?>

            <?php if ($page < $totalPages): ?>
                <a href="matches.php?page=<?= $page + 1 ?>&date=<?= $filter_param ?>&search=<?= $search ?>" class="btn btn-secondary" title="التالي"><i class="fas fa-angle-left"></i></a>
                <a href="matches.php?page=<?= $totalPages ?>&date=<?= $filter_param ?>&search=<?= $search ?>" class="btn btn-secondary" title="الصفحة الأخيرة"><i class="fas fa-angle-double-left"></i></a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </form>
</div>

<!-- Single Delete Form -->
<form id="singleDeleteForm" method="POST" style="display:none;">
    <input type="hidden" name="delete_id" id="delete_id_input">
</form>

<!-- Add Match Modal -->
<div id="addMatchModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>إضافة مباراة جديدة</h2>
            <button type="button" class="btn-icon" onclick="document.getElementById('addMatchModal').style.display='none'"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="add_match" value="1">
                
                <!-- Home Team Row -->
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-bottom: 20px;">
                    <div class="form-group">
                        <label style="margin-bottom: 8px; display: block; color: var(--text-secondary); font-size: 0.9rem;">الفريق المضيف</label>
                        <input type="text" name="home_team" class="form-control" required placeholder="اسم الفريق المضيف">
                    </div>
                    <div class="form-group">
                        <label style="margin-bottom: 8px; display: block; color: var(--text-secondary); font-size: 0.9rem;">شعار الفريق (رابط)</label>
                        <input type="url" name="home_team_logo" class="form-control" placeholder="https://example.com/logo.png">
                    </div>
                </div>
                
                <!-- Away Team Row -->
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-bottom: 20px;">
                    <div class="form-group">
                        <label style="margin-bottom: 8px; display: block; color: var(--text-secondary); font-size: 0.9rem;">الفريق الضيف</label>
                        <input type="text" name="away_team" class="form-control" required placeholder="اسم الفريق الضيف">
                    </div>
                    <div class="form-group">
                        <label style="margin-bottom: 8px; display: block; color: var(--text-secondary); font-size: 0.9rem;">شعار الفريق (رابط)</label>
                        <input type="url" name="away_team_logo" class="form-control" placeholder="https://example.com/logo.png">
                    </div>
                </div>
                
                <!-- Date & Time Row -->
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-bottom: 20px;">
                    <div class="form-group">
                        <label style="margin-bottom: 8px; display: block; color: var(--text-secondary); font-size: 0.9rem;">التاريخ</label>
                        <input type="date" name="match_date" class="form-control" required value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="form-group">
                        <label style="margin-bottom: 8px; display: block; color: var(--text-secondary); font-size: 0.9rem;">الوقت</label>
                        <input type="time" name="match_time" class="form-control" required value="<?= date('H:i') ?>">
                    </div>
                </div>
                
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
                    <div class="form-group">
                        <label style="margin-bottom: 8px; display: block; color: var(--text-secondary); font-size: 0.9rem;">الدوري / البطولة</label>
                        <input type="text" name="league" class="form-control" required placeholder="مثال: الدوري الإنجليزي">
                    </div>
                    <div class="form-group" style="grid-column: span 2;">
                        <label style="margin-bottom: 8px; display: block; color: var(--text-secondary); font-size: 0.9rem;">رابط الـ Iframe</label>
                        <input type="text" name="live_iframe" class="form-control" placeholder='https://example.com/embed/...'>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="document.getElementById('addMatchModal').style.display='none'">إلغاء</button>
                <button type="submit" class="btn-primary">إضافة المباراة</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Match Modal -->
<div id="editMatchModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>تعديل المباراة</h2>
            <button type="button" class="btn-icon" onclick="document.getElementById('editMatchModal').style.display='none'"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="update_match" value="1">
                <input type="hidden" name="match_id" id="edit_match_id">
                
                <!-- Home Team Row -->
                <div style="display:grid; grid-template-columns: 2fr 2fr 1fr; gap:15px; margin-bottom: 20px;">
                    <div class="form-group">
                        <label style="margin-bottom: 8px; display: block; color: var(--text-secondary); font-size: 0.9rem;">الفريق المضيف</label>
                        <input type="text" name="home_team" id="edit_home_team" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label style="margin-bottom: 8px; display: block; color: var(--text-secondary); font-size: 0.9rem;">شعار الفريق (رابط)</label>
                        <input type="url" name="home_team_logo" id="edit_home_team_logo" class="form-control">
                    </div>
                    <div class="form-group">
                        <label style="margin-bottom: 8px; display: block; color: var(--text-secondary); font-size: 0.9rem;">النتيجة</label>
                        <input type="number" name="score_home" id="edit_score_home" class="form-control" required>
                    </div>
                </div>
                
                <!-- Away Team Row -->
                <div style="display:grid; grid-template-columns: 2fr 2fr 1fr; gap:15px; margin-bottom: 20px;">
                    <div class="form-group">
                        <label style="margin-bottom: 8px; display: block; color: var(--text-secondary); font-size: 0.9rem;">الفريق الضيف</label>
                        <input type="text" name="away_team" id="edit_away_team" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label style="margin-bottom: 8px; display: block; color: var(--text-secondary); font-size: 0.9rem;">شعار الفريق (رابط)</label>
                        <input type="url" name="away_team_logo" id="edit_away_team_logo" class="form-control">
                    </div>
                    <div class="form-group">
                        <label style="margin-bottom: 8px; display: block; color: var(--text-secondary); font-size: 0.9rem;">النتيجة</label>
                        <input type="number" name="score_away" id="edit_score_away" class="form-control" required>
                    </div>
                </div>

                <!-- Date & Time Row -->
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-bottom: 20px;">
                    <div class="form-group">
                        <label style="margin-bottom: 8px; display: block; color: var(--text-secondary); font-size: 0.9rem;">التاريخ</label>
                        <input type="date" name="match_date" id="edit_match_date" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label style="margin-bottom: 8px; display: block; color: var(--text-secondary); font-size: 0.9rem;">الوقت</label>
                        <input type="time" name="match_time" id="edit_match_time" class="form-control" required>
                    </div>
                </div>
                
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-bottom: 20px;">
                    <div class="form-group">
                        <label style="margin-bottom: 8px; display: block; color: var(--text-secondary); font-size: 0.9rem;">الدوري / البطولة</label>
                        <input type="text" name="league" id="edit_league" class="form-control" required>
                    </div>
                    <div class="form-group" style="grid-column: span 2;">
                        <label style="margin-bottom: 8px; display: block; color: var(--text-secondary); font-size: 0.9rem;">رابط الـ Iframe</label>
                        <input type="text" name="live_iframe" id="edit_live_iframe" class="form-control">
                    </div>
                </div>

                <div class="form-group">
                    <label style="margin-bottom: 8px; display: block; color: var(--text-secondary); font-size: 0.9rem;">الحالة</label>
                    <select name="status" id="edit_status" class="form-control">
                        <option value="Scheduled">Scheduled</option>
                        <option value="Live">Live</option>
                        <option value="Finished">Finished</option>
                        <option value="Postponed">Postponed</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="document.getElementById('editMatchModal').style.display='none'">إلغاء</button>
                <button type="submit" class="btn-primary">حفظ التعديلات</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleSelectAll(source) {
    const checkboxes = document.querySelectorAll('.match-checkbox');
    checkboxes.forEach(cb => cb.checked = source.checked);
    updateDeleteBtn();
}

function updateDeleteBtn() {
    const checkboxes = document.querySelectorAll('.match-checkbox:checked');
    const btn = document.getElementById('deleteSelectedBtn');
    if (checkboxes.length > 0) {
        btn.style.display = 'flex';
        btn.innerHTML = `<i class="fas fa-trash"></i> حذف المحدد (${checkboxes.length})`;
    } else {
        btn.style.display = 'none';
    }
}

function deleteSingle(id) {
    if (confirm('هل أنت متأكد من الحذف؟')) {
        document.getElementById('delete_id_input').value = id;
        document.getElementById('singleDeleteForm').submit();
    }
}

function openAddModal() {
    document.getElementById('addMatchModal').style.display = 'flex';
}

function openEditModal(match) {
    document.getElementById('edit_match_id').value = match.id;
    document.getElementById('edit_home_team').value = match.home_team;
    document.getElementById('edit_home_team_logo').value = match.home_logo || '';
    document.getElementById('edit_away_team').value = match.away_team;
    document.getElementById('edit_away_team_logo').value = match.away_logo || '';
    document.getElementById('edit_score_home').value = match.score_home;
    document.getElementById('edit_score_away').value = match.score_away;
    document.getElementById('edit_match_date').value = match.match_date;
    document.getElementById('edit_match_time').value = match.details_match_time || match.match_time || '';
    document.getElementById('edit_league').value = match.league_name;
    document.getElementById('edit_live_iframe').value = match.live_iframe || '';
    document.getElementById('edit_status').value = match.status;
    
    document.getElementById('editMatchModal').style.display = 'flex';
}

// Close modal when clicking outside
document.getElementById('addMatchModal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});
document.getElementById('editMatchModal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});

function toggleFilterDropdown() {
    document.getElementById('filterDropdown').classList.toggle('show');
}

// Close dropdown when clicking outside
window.addEventListener('click', function(e) {
    if (!e.target.closest('.filter-container')) {
        const dropdown = document.getElementById('filterDropdown');
        if(dropdown) dropdown.classList.remove('show');
    }
});

</script>

<?php require_once 'includes/footer.php'; ?>
