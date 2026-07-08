<?php
require_once 'init.php';
require_once '../config.php';
require_once '../includes/Database.php';

$db = new Database($pdo);

// Handle Add Player
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_player'])) {
    $db->addPlayer(
        $_POST['name'],
        $_POST['name_ar'] ?? null,
        $_POST['name_en'] ?? null,
        $_POST['image_url'] ?? null,
        !empty($_POST['team_id']) ? $_POST['team_id'] : null,
        $_POST['position'] ?? null,
        !empty($_POST['number']) ? $_POST['number'] : null
    );
    header('Location: players.php?msg=added');
    exit;
}

// Handle Update Player
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_player'])) {
    $db->updatePlayer(
        $_POST['id'], 
        $_POST['name'],
        $_POST['name_ar'] ?? null,
        $_POST['name_en'] ?? null,
        $_POST['image_url'] ?? null,
        !empty($_POST['team_id']) ? $_POST['team_id'] : null,
        $_POST['position'] ?? null,
        !empty($_POST['number']) ? $_POST['number'] : null
    );
    header('Location: players.php?msg=updated');
    exit;
}

// Handle Delete Selected
if (isset($_POST['delete_selected']) && isset($_POST['selected_ids'])) {
    foreach ($_POST['selected_ids'] as $id) {
        $db->deletePlayer($id);
    }
    header('Location: players.php?msg=deleted_bulk');
    exit;
}

// Pagination & Filtering
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 50;
$offset = ($page - 1) * $limit;
$search = $_GET['search'] ?? null;
$teamId = !empty($_GET['team_id']) ? $_GET['team_id'] : null;
$filter_type = $_GET['filter_type'] ?? 'all';

$players = $db->getAllPlayers($teamId, $limit, $offset, $search, $filter_type);
$totalPlayers = $db->getPlayersCount($teamId, $search, $filter_type);
$totalPages = ceil($totalPlayers / $limit);

// Filter Labels Mapping
$filterLabels = [
    'all' => 'الكل',
    'default_logo' => 'شعار افتراضي',
    'external_logo' => 'شعار خارجي',
    'both' => 'اللغتين معاً',
    'ar' => 'ترجمة عربية',
    'en' => 'ترجمة إنجليزية',
    'missing' => 'نقص في الترجمة',
    'coach' => 'المدربين'
];
$current_filter_label = $filterLabels[$filter_type] ?? 'فلتر';

// Get all teams for dropdown
$teams = $db->getAllTeams();

$pageTitle = 'إدارة اللاعبين';
require_once 'includes/header.php';
?>

<div class="table-container">
    <form method="POST" id="bulkActionForm">
        <input type="hidden" name="delete_selected" value="1">
        
        <div class="table-header">
            <div style="display:flex; align-items:center; gap:15px;">
                <h3>
                    إدارة اللاعبين
                    <span style="color: var(--text-secondary); font-size: 0.9rem; font-weight: normal; margin-right: 10px; background: rgba(255,255,255,0.05); padding: 2px 10px; border-radius: 20px;">
                        <?= $totalPlayers ?> لاعب
                    </span>
                </h3>
                <div style="display:flex; align-items:center; gap:10px;">
                    <label style="display:flex; align-items:center; gap:5px; cursor:pointer;">
                        <input type="checkbox" id="selectAll" onclick="toggleSelectAll(this)">
                        <span>تحديد الكل</span>
                    </label>
                    <button type="submit" class="btn-danger" id="deleteSelectedBtn" style="display:none;" onclick="return confirm('هل أنت متأكد من حذف اللاعبين المحدد؟')">
                        <i class="fas fa-trash"></i>
                    </button>
                    <button type="button" class="btn-primary" onclick="openAddModal()">
                        <i class="fas fa-plus"></i>
                    </button>
                    <button type="button" class="btn-ai" id="aiTranslateBtn" onclick="startAiTranslation()" title="ترجمة اللاعبين المفقودين باستخدام الذكاء الاصطناعي">
                        <i class="fa-solid fa-wand-sparkles"></i>
                    </button>
                </div>
            </div>
            <div style="display: flex; gap: 15px; align-items: center;">
                <div class="custom-select-wrapper" style="position: relative; width: 250px;">
                    <div class="custom-select-trigger" onclick="toggleTeamDropdown()" id="selectedTeamTrigger" style="width: 100%; padding: 10px 15px; border-radius: 12px; font-size: 0.9rem; cursor: pointer; display: flex; justify-content: space-between; align-items: center; background: rgba(255, 255, 255, 0.05); border: 1px solid var(--glass-border); color: var(--text-primary);">
                        <span id="selectedTeamText" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                            <?php 
                                $selectedTeamName = 'جميع الفرق';
                                if ($teamId) {
                                    foreach ($teams as $team) {
                                        if ($team['id'] == $teamId) {
                                            $selectedTeamName = htmlspecialchars($team['name']);
                                            break;
                                        }
                                    }
                                }
                                echo $selectedTeamName;
                            ?>
                        </span>
                        <i class="fas fa-chevron-down" style="font-size: 0.8rem; color: var(--text-secondary);"></i>
                    </div>
                    
                    <div id="teamDropdown" style="display: none; position: absolute; top: 100%; left: 0; right: 0; background: #ffffffff; border: 1px solid var(--border-color); border-radius: 12px; margin-top: 5px; z-index: 1000; box-shadow: 0 10px 25px rgba(0,0,0,0.5); overflow: hidden;">
                        <div style="padding: 10px; border-bottom: 1px solid var(--border-color); background: #ffffffff;">
                            <input type="text" id="teamSearchInput" placeholder="ابحث عن فريق..." onkeyup="filterTeamSelect()" class="form-control" style="padding: 8px 12px; font-size: 0.9rem; width: 100%;">
                        </div>
                        <div id="teamOptionsList" style="max-height: 250px; overflow-y: auto;">
                            <div class="team-option" onclick="window.location.href='players.php'" style="padding: 10px 15px; cursor: pointer; color: var(--text-secondary); font-size: 0.9rem; border-bottom: 1px solid rgba(255,255,255,0.05);">
                                <span>جميع الفرق</span>
                            </div>
                            <?php foreach ($teams as $team): ?>
                                <div class="team-option" 
                                     data-search="<?= strtolower($team['name']) ?>"
                                     onclick="window.location.href='players.php?team_id=<?= $team['id'] ?>'"
                                     style="padding: 10px 15px; cursor: pointer; color: var(--text-secondary); font-size: 0.9rem; display: flex; align-items: center; gap: 10px; border-bottom: 1px solid rgba(255,255,255,0.05);">
                                    <?php if(!empty($team['logo_url'])): ?>
                                        <img src="<?= htmlspecialchars($team['logo_url']) ?>" style="width: 20px; height: 20px; object-fit: contain;">
                                    <?php else: ?>
                                        <i class="fas fa-shield-alt" style="width: 20px; text-align: center;"></i>
                                    <?php endif; ?>
                                    <span><?= htmlspecialchars($team['name']) ?></span>
                                </div>
                            <?php endforeach; ?>
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
                        <a href="players.php?team_id=<?= $teamId ?>&search=<?= $search ?>&filter_type=<?= $key ?>" class="filter-item <?= $filter_type === $key ? 'active' : '' ?>">
                            <span><?= $label ?></span>
                            <i class="fas fa-check"></i>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" name="search" value="<?= htmlspecialchars($search ?? '') ?>" placeholder="بحث عن لاعب..." class="form-control" style="width: 250px;" onkeydown="if(event.key === 'Enter') { window.location.href='players.php?team_id=<?= $teamId ?>&filter_type=<?= $filter_type ?>&search=' + this.value; return false; }">
                </div>
            </div>
        </div>
        
        <div class="players-grid" id="playersGrid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px; padding: 20px;">
            <?php if (empty($players)): ?>
                <div style="grid-column: 1 / -1; text-align: center; padding: 60px 20px; color: var(--text-secondary); background: rgba(255,255,255,0.02); border-radius: 16px; border: 1px dashed rgba(255,255,255,0.1); margin: 30px;">
                    <i class="fas fa-user-slash" style="font-size: 3rem; opacity: 0.1; margin-bottom: 15px; display: block;"></i>
                    <h3 style="color: #f1f5f9; margin-bottom: 5px; font-size: 1.1rem;">لا يوجد لاعبين حالياً</h3>
                    <p style="font-size: 0.85rem; opacity: 0.7;">لم يتم العثور على أي لاعبين.</p>
                </div>
            <?php else: ?>
                <?php foreach ($players as $player): ?>
                <?php 
                    $isCoach = ($player['position'] === 'Coach');
                    if ($isCoach) {
                        $cardStyle = 'background: linear-gradient(135deg, #ffd700 0%, #fdb931 50%, #e7a500 100%); border: 1px solid #fff; box-shadow: 0 4px 15px rgba(218, 165, 32, 0.4);';
                        $nameColor = '#1a1a1a';
                        $teamColor = '#333';
                        $posColor = '#000';
                        $imgBorder = '#fff';
                    } else {
                        $cardStyle = 'background: var(--card-bg); border: 1px solid var(--border-color);';
                        $nameColor = 'var(--text-primary)';
                        $teamColor = 'var(--text-secondary)';
                        $posColor = 'var(--primary)';
                        $imgBorder = 'var(--bg-dark)';
                    }
                ?>
                <div class="player-card" style="<?= $cardStyle ?> border-radius: 12px; padding: 10px 15px 10px 45px; position: relative; display: flex; align-items: center; gap: 15px; transition: all 0.2s; height: 80px;">
                    <div style="position:absolute; top:50%; left:10px; transform: translateY(-50%); z-index: 10; display: flex; flex-direction: column; gap: 8px; align-items: center;">
                        <input type="checkbox" name="selected_ids[]" value="<?= $player['id'] ?>" class="player-checkbox" onclick="updateDeleteBtn()" style="width: 16px; height: 16px; cursor: pointer; opacity: 0.6; accent-color: var(--primary);">
                        <button type="button" class="btn-icon" style="width: 28px; height: 28px; background: rgba(255,255,255,0.2); border-radius: 6px; display: flex; align-items: center; justify-content: center; color: <?= $isCoach ? '#000' : 'var(--text-secondary)' ?>; transition: all 0.2s; border: none; cursor: pointer;" onclick="openEditModal(<?= htmlspecialchars(json_encode($player), ENT_QUOTES, 'UTF-8') ?>)" title="تعديل">
                            <i class="fas fa-edit" style="font-size: 0.8rem; color: <?= $isCoach ? '#000' : '#4657f0ff' ?>;"></i>
                        </button>
                    </div>
                    
                    <div class="player-image-container" style="position: relative; flex-shrink: 0;">
                        <div style="width: 50px; height: 50px; border-radius: 50%; overflow: hidden; background: #f1f5f9; border: 2px solid <?= $imgBorder ?>;">
                            <img src="<?= htmlspecialchars($player['image_url'] ?: 'https://cdn.sportfeeds.io/sdl/images/person/head/medium/default.png') ?>" alt="<?= htmlspecialchars($player['name']) ?>" style="width: 100%; height: 100%; object-fit: cover;">
                        </div>
                        <?php if($player['number']): ?>
                            <div style="position: absolute; bottom: -2px; right: -5px; background: #ffffffff; color: black; width: 22px; height: 22px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 0.75rem;  box-shadow: 0 2px 4px rgba(0,0,0,0.2);">
                                <?= $player['number'] ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="player-info" style="flex: 1; overflow: hidden; display: flex; flex-direction: column; justify-content: center;">
                        <h4 style="color: <?= $nameColor ?>; margin-bottom: 2px; font-size: 0.95rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-weight: 700;" title="<?= htmlspecialchars($player['name']) ?>">
                            <?= htmlspecialchars($player['name']) ?>
                        </h4>
                        <div style="display: flex; align-items: center; gap: 5px; color: <?= $teamColor ?>; font-size: 0.75rem;">
                            <span style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100px; font-weight: 500;"><?= htmlspecialchars($player['team_name'] ?? 'بدون فريق') ?></span>
                            <?php if($player['position']): ?>
                                <span style="width: 3px; height: 3px; background: <?= $isCoach ? '#333' : 'var(--text-secondary)' ?>; border-radius: 50%; opacity: 0.5;"></span>
                                <span style="color: <?= $posColor ?>; font-weight: 600;"><?= htmlspecialchars($player['position']) ?></span>
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
                <a href="players.php?page=1&team_id=<?= $teamId ?>&search=<?= $search ?>&filter_type=<?= $filter_type ?>" class="btn btn-secondary" title="الصفحة الأولى"><i class="fas fa-angle-double-right"></i></a>
                <a href="players.php?page=<?= $page - 1 ?>&team_id=<?= $teamId ?>&search=<?= $search ?>&filter_type=<?= $filter_type ?>" class="btn btn-secondary" title="السابق"><i class="fas fa-angle-right"></i></a>
            <?php endif; ?>

            <?php
            $range = 2;
            $start = max(1, $page - $range);
            $end = min($totalPages, $page + $range);

            if ($start > 1) {
                echo '<a href="players.php?page=1&team_id='.$teamId.'&search='.$search.'&filter_type='.$filter_type.'" class="btn btn-secondary">1</a>';
                if ($start > 2) echo '<span style="color: var(--text-secondary); padding: 0 5px;">...</span>';
            }

            for ($i = $start; $i <= $end; $i++) {
                $activeClass = ($page == $i) ? 'btn-primary' : 'btn-secondary';
                echo '<a href="players.php?page='.$i.'&team_id='.$teamId.'&search='.$search.'&filter_type='.$filter_type.'" class="btn '.$activeClass.'">'.$i.'</a>';
            }

            if ($end < $totalPages) {
                if ($end < $totalPages - 1) echo '<span style="color: var(--text-secondary); padding: 0 5px;">...</span>';
                echo '<a href="players.php?page='.$totalPages.'&team_id='.$teamId.'&search='.$search.'&filter_type='.$filter_type.'" class="btn btn-secondary">'.$totalPages.'</a>';
            }
            ?>

            <?php if ($page < $totalPages): ?>
                <a href="players.php?page=<?= $page + 1 ?>&team_id=<?= $teamId ?>&search=<?= $search ?>&filter_type=<?= $filter_type ?>" class="btn btn-secondary" title="التالي"><i class="fas fa-angle-left"></i></a>
                <a href="players.php?page=<?= $totalPages ?>&team_id=<?= $teamId ?>&search=<?= $search ?>&filter_type=<?= $filter_type ?>" class="btn btn-secondary" title="الصفحة الأخيرة"><i class="fas fa-angle-double-left"></i></a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </form>
</div>

<!-- Add Player Modal -->
<div id="addModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>إضافة لاعب جديد</h2>
            <button type="button" class="btn-icon" onclick="closeAddModal()"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="add_player" value="1">
                
                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">الاسم</label>
                    <input type="text" name="name" required class="form-control" placeholder="اسم اللاعب">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">الاسم بالعربية</label>
                        <input type="text" name="name_ar" class="form-control" placeholder="الاسم بالعربية">
                    </div>
                    
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">الاسم بالإنجليزية</label>
                        <input type="text" name="name_en" class="form-control" placeholder="Player Name in English">
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">الفريق</label>
                    <select name="team_id" class="form-control">
                        <option value="">اختر الفريق</option>
                        <?php foreach ($teams as $team): ?>
                            <option value="<?= $team['id'] ?>"><?= htmlspecialchars($team['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">المركز</label>
                        <input type="text" name="position" class="form-control" placeholder="مثال: مهاجم">
                    </div>
                    
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">الرقم</label>
                        <input type="number" name="number" class="form-control" placeholder="رقم القميص">
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">رابط الصورة (Image URL)</label>
                    <input type="url" name="image_url" id="add_image" class="form-control" placeholder="https://example.com/player.png">
                </div>
                
                <div id="addImagePreview" style="display: none; text-align: center; margin-top: 15px; padding: 20px; background: #f8fafc; border: 2px dashed var(--border-color); border-radius: 14px;">
                    <label style="display: block; margin-bottom: 12px; color: var(--accent); font-size: 0.85rem; font-weight: 600;">معاينة الصورة</label>
                    <div style="display: flex; justify-content: center; align-items: center; min-height: 100px;">
                        <img id="addImagePreviewImg" src="" alt="معاينة" style="max-width: 100px; max-height: 100px; object-fit: cover; border-radius: 50%;">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeAddModal()">إلغاء</button>
                <button type="submit" class="btn-primary">إضافة اللاعب</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Player Modal -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>تعديل بيانات اللاعب</h2>
            <button type="button" class="btn-icon" onclick="closeEditModal()"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="update_player" value="1">
                <input type="hidden" name="id" id="edit_id">
                
                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">الاسم</label>
                    <input type="text" name="name" id="edit_name" required class="form-control">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">الاسم بالعربية</label>
                        <input type="text" name="name_ar" id="edit_name_ar" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">الاسم بالإنجليزية</label>
                        <input type="text" name="name_en" id="edit_name_en" class="form-control">
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">الفريق</label>
                    <select name="team_id" id="edit_team_id" class="form-control">
                        <option value="">اختر الفريق</option>
                        <?php foreach ($teams as $team): ?>
                            <option value="<?= $team['id'] ?>"><?= htmlspecialchars($team['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">المركز</label>
                        <input type="text" name="position" id="edit_position" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">الرقم</label>
                        <input type="number" name="number" id="edit_number" class="form-control">
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">رابط الصورة (Image URL)</label>
                    <input type="url" name="image_url" id="edit_image" class="form-control">
                </div>
                
                <div id="editImagePreview" style="display: none; text-align: center; margin-top: 15px; padding: 20px; background: #f8fafc; border: 2px dashed var(--border-color); border-radius: 14px;">
                    <label style="display: block; margin-bottom: 12px; color: var(--accent); font-size: 0.85rem; font-weight: 600;">معاينة الصورة</label>
                    <div style="display: flex; justify-content: center; align-items: center; min-height: 100px;">
                        <img id="editImagePreviewImg" src="" alt="معاينة" style="max-width: 100px; max-height: 100px; object-fit: cover; border-radius: 50%;">
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

<script>
function toggleSelectAll(source) {
    const checkboxes = document.querySelectorAll('.player-checkbox');
    checkboxes.forEach(cb => cb.checked = source.checked);
    updateDeleteBtn();
}

function updateDeleteBtn() {
    const checkboxes = document.querySelectorAll('.player-checkbox:checked');
    const btn = document.getElementById('deleteSelectedBtn');
    if (checkboxes.length > 0) {
        btn.style.display = 'flex';
        btn.innerHTML = `<i class="fas fa-trash"></i>(${checkboxes.length})`;
    } else {
        btn.style.display = 'none';
    }
}

function openAddModal() {
    document.getElementById('addModal').style.display = 'flex';
}

function closeAddModal() {
    document.getElementById('addModal').style.display = 'none';
    document.getElementById('addImagePreview').style.display = 'none';
}

function openEditModal(player) {
    document.getElementById('edit_id').value = player.id;
    document.getElementById('edit_name').value = player.name;
    document.getElementById('edit_name_ar').value = player.name_ar || '';
    document.getElementById('edit_name_en').value = player.name_en || '';
    document.getElementById('edit_team_id').value = player.team_id || '';
    document.getElementById('edit_position').value = player.position || '';
    document.getElementById('edit_number').value = player.number || '';
    document.getElementById('edit_image').value = player.image_url || '';
    
    // Show image preview if exists
    if (player.image_url) {
        showImagePreview(player.image_url, 'editImagePreview', 'editImagePreviewImg');
    } else {
        hideImagePreview('editImagePreview');
    }
    
    document.getElementById('editModal').style.display = 'flex';
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
    hideImagePreview('editImagePreview');
}

function showImagePreview(url, previewId, imgId) {
    const preview = document.getElementById(previewId);
    const img = document.getElementById(imgId);
    img.src = url;
    preview.style.display = 'block';
}

function hideImagePreview(previewId) {
    const preview = document.getElementById(previewId);
    preview.style.display = 'none';
}

// Image preview on URL input change
document.getElementById('edit_image').addEventListener('input', function(e) {
    const url = e.target.value.trim();
    if (url) {
        showImagePreview(url, 'editImagePreview', 'editImagePreviewImg');
    } else {
        hideImagePreview('editImagePreview');
    }
});

document.getElementById('add_image').addEventListener('input', function(e) {
    const url = e.target.value.trim();
    if (url) {
        showImagePreview(url, 'addImagePreview', 'addImagePreviewImg');
    } else {
        hideImagePreview('addImagePreview');
    }
});

// AI Translation Functionality
async function startAiTranslation() {
    const btn = document.getElementById('aiTranslateBtn');
    const originalContent = btn.innerHTML;
    
    if (!confirm('هل تريد بدء ترجمة اللاعبين الذين تنقصهم الترجمة باستخدام الذكاء الاصطناعي؟ (سيتم ترجمة أول 100 لاعب مفقود)')) {
        return;
    }

    try {
        btn.classList.add('loading');
        
        const response = await fetch('ajax_translate_players.php');
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
});

// Close modal with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeAddModal();
        closeEditModal();
        const dropdown = document.getElementById('teamDropdown');
        if(dropdown) dropdown.style.display = 'none';
        const filterDropdown = document.getElementById('filterOptionsDropdown');
        if(filterDropdown) filterDropdown.classList.remove('show');
    }
});

function toggleTeamDropdown() {
    const dropdown = document.getElementById('teamDropdown');
    const isVisible = dropdown.style.display === 'block';
    dropdown.style.display = isVisible ? 'none' : 'block';
    if (!isVisible) {
        document.getElementById('teamSearchInput').focus();
        document.getElementById('teamSearchInput').value = '';
        filterTeamSelect();
        // Close filter dropdown if open
        document.getElementById('filterOptionsDropdown').classList.remove('show');
    }
}

function toggleFilterOptionsDropdown() {
    const dropdown = document.getElementById('filterOptionsDropdown');
    dropdown.classList.toggle('show');
    // Close team dropdown if open
    document.getElementById('teamDropdown').style.display = 'none';
}

function filterTeamSelect() {
    const filter = document.getElementById('teamSearchInput').value.toLowerCase();
    const options = document.querySelectorAll('#teamOptionsList .team-option');
    options.forEach(opt => {
        if (!opt.hasAttribute('data-search')) return;
        const searchText = opt.getAttribute('data-search');
        if (searchText.includes(filter)) {
            opt.style.display = 'flex';
        } else {
            opt.style.display = 'none';
        }
    });
}

// Close dropdown when clicking outside
window.addEventListener('click', function(e) {
    if (!e.target.closest('.custom-select-wrapper') && !e.target.closest('.filter-container')) {
        const teamDropdown = document.getElementById('teamDropdown');
        if(teamDropdown) teamDropdown.style.display = 'none';
        const filterDropdown = document.getElementById('filterOptionsDropdown');
        if(filterDropdown) filterDropdown.classList.remove('show');
    }
});
</script>

<style>
.team-option:hover {
    background: rgba(255, 255, 255, 0.05);
    color: var(--primary) !important;
}
.show_flex {
    display: flex !important;
    flex-direction: column;
}
</style>

<?php require_once 'includes/footer.php'; ?>
