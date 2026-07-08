<?php
require_once 'init.php';
require_once '../config.php';
require_once '../includes/Database.php';

$db = new Database($pdo);
$apiSettings = $db->getApiSettings();
$embedPlayerSignTtlSeconds = max(60, (int)($apiSettings['embed_player_signed_ttl'] ?? 900));
$embedCastSignTtlSeconds = max(120, (int)($apiSettings['embed_cast_signed_ttl'] ?? 7200));
$embedDomainRestrictionEnabled = (string)($apiSettings['embed_domain_restriction_enabled'] ?? '1') !== '0';

// Handle Add Channel
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_channel'])) {
    $db->addChannel(
        $_POST['name'],
        $_POST['logo'],
        $_POST['stream_url'] ?? null
    );
    header('Location: channels.php?msg=added');
    exit;
}

// Handle Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_channel'])) {
    $db->updateChannel(
        $_POST['id'], 
        $_POST['name'],
        $_POST['logo'],
        $_POST['stream_url'] ?? null,
        isset($_POST['is_active']) ? 1 : 0
    );
    header('Location: channels.php?msg=updated');
    exit;
}

// Handle Delete Selected
if (isset($_POST['delete_selected']) && isset($_POST['selected_ids'])) {
    foreach ($_POST['selected_ids'] as $id) {
        $db->deleteChannel($id);
    }
    header('Location: channels.php?msg=deleted_bulk');
    exit;
}

// Handle AJAX request for updating status only
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_channel') {
    header('Content-Type: application/json');
    try {
        $id = $_POST['id'];
        $status = $_POST['status']; // 1 or 0
        $db->updateChannelStatus($id, $status);
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_live_embed_settings') {
    header('Content-Type: application/json; charset=UTF-8');

    try {
        requireAdminAPI();

        $playerSignMinutes = max(1, min(720, (int)($_POST['player_sign_minutes'] ?? 15)));
        $castSignMinutes = max(2, min(720, (int)($_POST['cast_sign_minutes'] ?? 120)));
        $domainRestrictionEnabledValue = (string)($_POST['domain_restriction_enabled'] ?? '1') === '0' ? '0' : '1';

        $db->updateApiSetting('embed_player_signed_ttl', (string)($playerSignMinutes * 60));
        $db->updateApiSetting('embed_cast_signed_ttl', (string)($castSignMinutes * 60));
        $db->updateApiSetting('embed_domain_restriction_enabled', $domainRestrictionEnabledValue);

        echo json_encode([
            'status' => 'success',
            'message' => 'تم حفظ الإعدادات المتقدمة',
            'player_sign_minutes' => $playerSignMinutes,
            'cast_sign_minutes' => $castSignMinutes,
            'domain_restriction_enabled' => $domainRestrictionEnabledValue === '1',
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$search = $_GET['search'] ?? null;
$filter_param = $_GET['status'] ?? 'all';

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 52; // Using a multiple of 4 for grid
$offset = ($page - 1) * $limit;

$channels = $db->getAllChannels($limit, $offset, $search, $filter_param);
$totalChannels = $db->getChannelsCount($search, $filter_param);
$totalPages = ceil($totalChannels / $limit);

// Filter label for UI
$filter_label = "الكل";
if ($filter_param === 'active') $filter_label = "النشطة";
elseif ($filter_param === 'inactive') $filter_label = "غير النشطة";
elseif ($filter_param === 'with_stream') $filter_label = "بث متاح";

$pageTitle = 'إدارة القنوات';
require_once 'includes/header.php';
?>

<div class="table-container">
    <form method="POST" id="bulkActionForm">
        <input type="hidden" name="delete_selected" value="1">
        
        <div class="table-header">
            <div style="display:flex; align-items:center; gap:15px;">
                <h3>
                    إدارة القنوات
                    <span style="color: var(--text-secondary); font-size: 0.9rem; font-weight: normal; margin-right: 10px; background: rgba(255,255,255,0.05); padding: 2px 10px; border-radius: 20px;">
                        <?= $totalChannels ?> قناة
                    </span>
                </h3>
                <div style="display:flex; align-items:center; gap:10px;">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; background: rgba(255,255,255,0.03); padding: 5px 12px; border-radius: 10px; border: 1px solid var(--border-color); font-size: 0.85rem;">
                        <input type="checkbox" id="selectAll" onclick="toggleSelectAll(this)" style="width: 16px; height: 16px; cursor: pointer;">
                        <span>تحديد الكل</span>
                    </label>
                    <button type="submit" class="btn-danger" id="deleteSelectedBtn" style="display:none;" onclick="return confirm('هل أنت متأكد من حذف القنوات المحددة؟')">
                        <i class="fas fa-trash"></i> حذف المحدد
                    </button>
                    <button type="button" class="btn-primary" onclick="openAddModal()">
                        <i class="fas fa-plus"></i> إضافة قناة
                    </button>
                    <button type="button" class="btn-secondary" onclick="openScraperSourcesModal()">
                        <i class="fas fa-spider"></i> مصادر الجلب
                    </button>
                    <?php if (function_exists('isAdmin') && isAdmin()): ?>
                    <button type="button" class="btn-secondary" onclick="openAdvancedEmbedDrawer()">
                        <i class="fas fa-sliders-h"></i> إعدادات متقدمة
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <div style="display: flex; gap: 15px; align-items: center;">
                <div class="filter-container">
                    <button type="button" class="btn-filter" onclick="toggleFilterDropdown()">
                        <i class="fas fa-filter"></i>
                        <span><?= $filter_label ?></span>
                        <i class="fas fa-chevron-down" style="font-size: 0.7rem; margin-right: 5px; opacity: 0.6;"></i>
                    </button>
                    <div id="filterDropdown" class="filter-dropdown">
                        <a href="channels.php?status=all&search=<?= $search ?>" class="filter-item <?= $filter_param === 'all' ? 'active' : '' ?>">
                            <span>الكل</span>
                            <i class="fas fa-check"></i>
                        </a>
                        <a href="channels.php?status=active&search=<?= $search ?>" class="filter-item <?= $filter_param === 'active' ? 'active' : '' ?>">
                            <span>النشطة</span>
                            <i class="fas fa-check"></i>
                        </a>
                        <a href="channels.php?status=inactive&search=<?= $search ?>" class="filter-item <?= $filter_param === 'inactive' ? 'active' : '' ?>">
                            <span>غير النشطة</span>
                            <i class="fas fa-check"></i>
                        </a>
                        <a href="channels.php?status=with_stream&search=<?= $search ?>" class="filter-item <?= $filter_param === 'with_stream' ? 'active' : '' ?>">
                            <span>بث متاح</span>
                            <i class="fas fa-check"></i>
                        </a>
                    </div>
                </div>
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchInput" name="search" value="<?= htmlspecialchars($search ?? '') ?>" placeholder="بحث عن قناة..." class="form-control" style="width: 250px;" onkeydown="if(event.key === 'Enter') { window.location.href='channels.php?status=<?= $filter_param ?>&search=' + this.value; return false; }">
                </div>
            </div>
        </div>
        
        <div class="channels-grid" id="channelsGrid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 15px; padding: 20px;">
            <!-- No Channels Message -->
            <div id="noChannelsMessage" style="<?= empty($channels) ? '' : 'display: none;' ?> grid-column: 1 / -1; text-align: center; padding: 80px 20px; color: var(--text-secondary); background: rgba(255,255,255,0.02); border-radius: 20px; border: 1px dashed rgba(255,255,255,0.1);">
                <i class="fas fa-broadcast-tower" style="font-size: 4rem; opacity: 0.1; margin-bottom: 20px; display: block;"></i>
                <h3 style="color: #f1f5f9; margin-bottom: 10px;">لا توجد قنوات حالياً</h3>
                <p style="font-size: 0.95rem; opacity: 0.7;">لم يتم العثور على أي قنوات.</p>
            </div>

            <?php if (!empty($channels)): ?>
                <?php foreach ($channels as $channel): ?>
                <div class="channel-card" 
                     style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 16px; padding: 12px 15px; display: flex; align-items: center; justify-content: space-between; transition: all 0.3s ease; box-shadow: var(--card-shadow); position: relative; gap: 15px; min-height: 80px;">
                    
                    <div style="position:absolute; top:-8px; right:-8px; z-index: 10;">
                        <input type="checkbox" name="selected_ids[]" value="<?= $channel['id'] ?>" class="channel-checkbox" onclick="updateDeleteBtn()" style="width: 20px; height: 20px; cursor: pointer; border-radius: 6px;">
                    </div>

                    <div class="channel-main" style="display: flex; align-items: center; gap: 12px; flex: 1; min-width: 0;">
                        <div class="channel-logo" style="width: 50px; height: 50px; border-radius: 12px; background: rgba(255,255,255,0.05); display: flex; align-items: center; justify-content: center; flex-shrink: 0; overflow: hidden; border: 1px solid var(--border-color);">
                            <?php if($channel['logo']): ?>
                                <img src="<?= htmlspecialchars($channel['logo']) ?>" alt="<?= htmlspecialchars($channel['name']) ?>" style="width: 100%; height: 100%; object-fit: contain; padding: 4px;">
                            <?php else: ?>
                                <i class="fas fa-tv" style="color: var(--text-secondary); font-size: 1.2rem; opacity: 0.5;"></i>
                            <?php endif; ?>
                        </div>
                        <div style="min-width: 0;">
                            <h4 style="color: var(--text-primary); margin: 0; font-size: 0.95rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($channel['name']) ?>">
                                <?= htmlspecialchars($channel['name']) ?>
                            </h4>
                            <div style="display: flex; align-items: center; gap: 6px; margin-top: 4px;">
                                <span style="color: var(--text-secondary); font-size: 0.75rem;">ID: <?= $channel['id'] ?></span>
                                <?php if(!empty($channel['stream_url'])): ?>
                                    <span style="width: 4px; height: 4px; background: #22c55e; border-radius: 50%; display: inline-block; box-shadow: 0 0 5px #22c55e;"></span>
                                    <span style="color: #22c55e; font-size: 0.7rem; font-weight: 600;">بث متاح</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="channel-actions" style="display: flex; align-items: center; gap: 10px;">
                        <?php if(!empty($channel['stream_url'])): ?>
                        <button type="button" class="btn-icon" style="width: 34px; height: 34px; border-radius: 10px; background: rgba(34, 197, 94, 0.1); border: 1px solid rgba(34, 197, 94, 0.2); display: flex; align-items: center; justify-content: center; transition: all 0.2s;" onclick="openPreviewModal(<?= htmlspecialchars(json_encode($channel), ENT_QUOTES, 'UTF-8') ?>)" title="مشاهدة البث">
                            <i class="fas fa-play" style="font-size: 0.75rem; color: #22c55e;"></i>
                        </button>
                        <?php endif; ?>
                        <button type="button" class="btn-icon" style="width: 34px; height: 34px; border-radius: 10px; background: rgba(56, 189, 248, 0.1); border: 1px solid rgba(56, 189, 248, 0.2); display: flex; align-items: center; justify-content: center; transition: all 0.2s;" onclick="openEditModal(<?= htmlspecialchars(json_encode($channel), ENT_QUOTES, 'UTF-8') ?>)" title="تعديل">
                            <i class="fas fa-edit" style="font-size: 0.85rem; color: #38bdf8;"></i>
                        </button>
                        <div class="status-toggle">
                            <label class="switch" style="transform: scale(0.7); margin: 0;">
                                <input type="checkbox" 
                                    onchange="toggleChannel(<?= $channel['id'] ?>, this)" 
                                    <?= $channel['is_active'] ? 'checked' : '' ?>>
                                <span class="slider round"></span>
                            </label>
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
                <a href="channels.php?page=1&status=<?= $filter_param ?>&search=<?= $search ?>" class="btn btn-secondary"><i class="fas fa-angle-double-right"></i></a>
                <a href="channels.php?page=<?= $page - 1 ?>&status=<?= $filter_param ?>&search=<?= $search ?>" class="btn btn-secondary"><i class="fas fa-angle-right"></i></a>
            <?php endif; ?>

            <?php
            $range = 2;
            $start = max(1, $page - $range);
            $end = min($totalPages, $page + $range);

            for ($i = $start; $i <= $end; $i++) {
                $activeClass = ($page == $i) ? 'btn-primary' : 'btn-secondary';
                echo '<a href="channels.php?page='.$i.'&status='.$filter_param.'&search='.$search.'" class="btn '.$activeClass.'">'.$i.'</a>';
            }
            ?>

            <?php if ($page < $totalPages): ?>
                <a href="channels.php?page=<?= $page + 1 ?>&status=<?= $filter_param ?>&search=<?= $search ?>" class="btn btn-secondary"><i class="fas fa-angle-left"></i></a>
                <a href="channels.php?page=<?= $totalPages ?>&status=<?= $filter_param ?>&search=<?= $search ?>" class="btn btn-secondary"><i class="fas fa-angle-double-left"></i></a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </form>
</div>

<?php if (function_exists('isAdmin') && isAdmin()): ?>
<div id="advancedEmbedDrawerBackdrop" class="advanced-drawer-backdrop" onclick="closeAdvancedEmbedDrawer()"></div>
<aside id="advancedEmbedDrawer" class="advanced-drawer" aria-hidden="true">
    <div class="advanced-drawer-header">
        <div>
            <h2>الإعدادات المتقدمة</h2>
            <p>تحكم في مدة توقيع روابط البث وقيود الدومين المرتبط.</p>
        </div>
        <button type="button" class="btn-icon" onclick="closeAdvancedEmbedDrawer()">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <div class="advanced-drawer-body">
        <div class="advanced-setting-card">
            <label for="embed_player_sign_minutes">مدة توقيع المشغل العادي</label>
            <p>المدة بالدقائق لروابط <code>stream_player</code> و<code>frame_player</code>.</p>
            <div class="advanced-input-row">
                <input
                    type="number"
                    id="embed_player_sign_minutes"
                    min="1"
                    max="720"
                    step="1"
                    value="<?= max(1, (int)round($embedPlayerSignTtlSeconds / 60)) ?>"
                    class="form-control"
                >
                <span>دقيقة</span>
            </div>
        </div>

        <div class="advanced-setting-card">
            <label for="embed_cast_sign_minutes">مدة توقيع البث للتلفاز</label>
            <p>المدة بالدقائق لروابط <code>Cast</code> و<code>AirPlay</code>.</p>
            <div class="advanced-input-row">
                <input
                    type="number"
                    id="embed_cast_sign_minutes"
                    min="2"
                    max="720"
                    step="1"
                    value="<?= max(2, (int)round($embedCastSignTtlSeconds / 60)) ?>"
                    class="form-control"
                >
                <span>دقيقة</span>
            </div>
        </div>

        <div class="advanced-setting-card">
            <div class="advanced-switch-row">
                <div>
                    <label for="embed_domain_restriction_enabled" style="margin-bottom: 6px;">تقييد اللايف حسب الدومين المرتبط</label>
                    <p>عند الإيقاف يمكن تضمين اللايف من أي دومين، حتى إذا كان الرابط غير مقيّد بالدومين الحالي.</p>
                </div>
                <label class="switch" style="margin: 0;">
                    <input
                        type="checkbox"
                        id="embed_domain_restriction_enabled"
                        <?= $embedDomainRestrictionEnabled ? 'checked' : '' ?>
                    >
                    <span class="slider round"></span>
                </label>
            </div>
        </div>
    </div>
    <div class="advanced-drawer-footer">
        <button type="button" class="btn btn-secondary" onclick="closeAdvancedEmbedDrawer()">إلغاء</button>
        <button type="button" class="btn btn-primary" onclick="saveAdvancedEmbedSettings()">
            <i class="fas fa-save" style="margin-left: 8px;"></i> حفظ الإعدادات
        </button>
    </div>
</aside>
<?php endif; ?>

<!-- Add Channel Modal -->
<div id="addModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>إضافة قناة جديدة</h2>
            <button type="button" class="btn-icon" onclick="closeAddModal()"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="add_channel" value="1">
                
                <div class="form-group" style="margin-bottom: 20px;">
                    <label>اسم القناة</label>
                    <input type="text" name="name" required class="form-control" placeholder="مثال: beIN Sports 1">
                </div>

                <div class="form-group" style="margin-bottom: 20px;">
                    <label>رابط الشعار</label>
                    <input type="url" name="logo" id="add_logo" class="form-control" placeholder="https://example.com/logo.png">
                </div>

                <div class="form-group" style="margin-bottom: 20px;">
                    <label>رابط البث (اختياري)</label>
                    <input type="text" name="stream_url" class="form-control" placeholder="رابط IPTV أو صفحة البث">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeAddModal()">إلغاء</button>
                <button type="submit" class="btn-primary">إضافة القناة</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Channel Modal -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>تعديل القناة</h2>
            <button type="button" class="btn-icon" onclick="closeEditModal()"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="update_channel" value="1">
                <input type="hidden" name="id" id="edit_id">
                
                <div class="form-group" style="margin-bottom: 20px;">
                    <label>اسم القناة</label>
                    <input type="text" name="name" id="edit_name" required class="form-control">
                </div>

                <div class="form-group" style="margin-bottom: 20px;">
                    <label>رابط الشعار</label>
                    <input type="url" name="logo" id="edit_logo" class="form-control">
                </div>

                <div class="form-group" style="margin-bottom: 20px;">
                    <label>رابط البث (اختياري)</label>
                    <input type="text" name="stream_url" id="edit_stream_url" class="form-control">
                </div>

                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                        <input type="checkbox" name="is_active" id="edit_is_active">
                        <span>قناة مفعلة</span>
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeEditModal()">إلغاء</button>
                <button type="submit" class="btn-primary">حفظ التغييرات</button>
            </div>
        </form>
    </div>
</div>

<!-- Preview Stream Modal -->
<div id="previewModal" class="modal">
    <div class="modal-content" style="max-width: 800px; padding: 0; overflow: hidden; border-radius: 20px;">
        <div class="modal-header" style="padding: 20px; border-bottom: 1px solid var(--border-color);">
            <h2 id="preview_title" style="font-size: 1.1rem;">عرض البث</h2>
            <div style="display: flex; align-items: center; gap: 10px;">
<button type="button" class="btn-icon" onclick="closePreviewModal()"><i class="fas fa-times"></i></button>
            </div>
        </div>
        <div class="modal-body" style="padding: 0; background: #000; aspect-ratio: 16/9; display: flex; align-items: center; justify-content: center; position: relative;">
            <div id="preview_container" style="width: 100%; height: 100%;">
                <!-- Iframe will be injected here -->
            </div>
        </div>
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

function toggleSelectAll(source) {
    const checkboxes = document.querySelectorAll('.channel-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = source.checked;
        cb.closest('.channel-card').style.borderColor = source.checked ? 'var(--primary)' : 'var(--border-color)';
    });
    updateDeleteBtn();
}

function updateDeleteBtn() {
    const checkboxes = document.querySelectorAll('.channel-checkbox:checked');
    const btn = document.getElementById('deleteSelectedBtn');
    
    // Highlight selected cards
    document.querySelectorAll('.channel-card').forEach(card => {
        const cb = card.querySelector('.channel-checkbox');
        card.style.borderColor = cb.checked ? 'var(--primary)' : 'var(--border-color)';
        card.style.background = cb.checked ? 'rgba(56, 189, 248, 0.05)' : 'var(--card-bg)';
    });

    if (checkboxes.length > 0) {
        btn.style.display = 'flex';
        btn.innerHTML = `<i class="fas fa-trash"></i> حذف (${checkboxes.length})`;
    } else {
        btn.style.display = 'none';
    }
}

function toggleChannel(id, checkbox) {
    const status = checkbox.checked ? 1 : 0;
    const card = checkbox.closest('.channel-card');
    
    fetch('channels.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=toggle_channel&id=${id}&status=${status}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.status !== 'success') {
            alert('حدث خطأ أثناء تحديث الحالة');
            checkbox.checked = !checkbox.checked;
        } else {
            // Optional: visual feedback
            card.style.opacity = status ? '1' : '0.5';
        }
    })
    .catch(err => {
        alert('خطأ في الاتصال بالسيرفر');
        checkbox.checked = !checkbox.checked;
    });
}


function openPreviewModal(channel) {
    document.getElementById('preview_title').innerText = 'بث مباشر: ' + channel.name;
    const container = document.getElementById('preview_container');
    let content = channel.stream_url;
    if (content && content.indexOf('<iframe') === -1 && (content.startsWith('http') || content.startsWith('/'))) {
        content = `<iframe src="${content}" width="100%" height="100%" frameborder="0" allowfullscreen style="position:absolute; top:0; left:0; width:100%; height:100%;"></iframe>`;
    } else if (content && content.indexOf('<iframe') !== -1) {
        // Extract src from existing iframe HTML
        content = content.replace(/\s+sandbox=(["'])[\s\S]*?\1/gi, '');
        // Force width/height to 100% for existing iframes
        content = content.replace(/width="[^"]*"/, 'width="100%"').replace(/height="[^"]*"/, 'height="100%"');
        if (content.indexOf('style="') !== -1) {
            content = content.replace('style="', 'style="position:absolute; top:0; left:0; width:100%; height:100%; ');
        } else {
            content = content.replace('<iframe ', '<iframe style="position:absolute; top:0; left:0; width:100%; height:100%;" ');
        }
    }
container.innerHTML = content || '<div style="color:white;">لا يوجد رابط بث متوفر</div>';
    document.getElementById('previewModal').style.display = 'flex';
}


function closePreviewModal() {
    document.getElementById('previewModal').style.display = 'none';
    document.getElementById('preview_container').innerHTML = ''; // Stop video/audio
}

function openAddModal() {
    document.getElementById('addModal').style.display = 'flex';
}

function closeAddModal() {
    document.getElementById('addModal').style.display = 'none';
}

function openEditModal(channel) {
    document.getElementById('edit_id').value = channel.id;
    document.getElementById('edit_name').value = channel.name;
    document.getElementById('edit_logo').value = channel.logo || '';
    document.getElementById('edit_stream_url').value = channel.stream_url || '';
    document.getElementById('edit_is_active').checked = channel.is_active == 1;
    
    document.getElementById('editModal').style.display = 'flex';
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
}

window.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal')) {
        closeAddModal();
        closeEditModal();
        closePreviewModal();
    }
});

function openAdvancedEmbedDrawer() {
    const drawer = document.getElementById('advancedEmbedDrawer');
    const backdrop = document.getElementById('advancedEmbedDrawerBackdrop');
    if (!drawer || !backdrop) return;

    drawer.classList.add('is-open');
    backdrop.classList.add('is-open');
    drawer.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
}

function closeAdvancedEmbedDrawer() {
    const drawer = document.getElementById('advancedEmbedDrawer');
    const backdrop = document.getElementById('advancedEmbedDrawerBackdrop');
    if (!drawer || !backdrop) return;

    drawer.classList.remove('is-open');
    backdrop.classList.remove('is-open');
    drawer.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
}

async function saveAdvancedEmbedSettings() {
    const playerInput = document.getElementById('embed_player_sign_minutes');
    const castInput = document.getElementById('embed_cast_sign_minutes');
    const restrictionInput = document.getElementById('embed_domain_restriction_enabled');

    if (!playerInput || !castInput || !restrictionInput) {
        return;
    }

    const formData = new URLSearchParams();
    formData.set('action', 'save_live_embed_settings');
    formData.set('player_sign_minutes', playerInput.value || '15');
    formData.set('cast_sign_minutes', castInput.value || '120');
    formData.set('domain_restriction_enabled', restrictionInput.checked ? '1' : '0');

    try {
        const response = await fetch('channels.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: formData.toString()
        });

        const data = await response.json();
        if (!response.ok || data.status !== 'success') {
            throw new Error(data.message || 'تعذر حفظ الإعدادات');
        }

        playerInput.value = String(data.player_sign_minutes || 15);
        castInput.value = String(data.cast_sign_minutes || 120);
        restrictionInput.checked = !!data.domain_restriction_enabled;
        closeAdvancedEmbedDrawer();
        alert(data.message || 'تم حفظ الإعدادات');
    } catch (error) {
        alert(error.message || 'تعذر حفظ الإعدادات');
    }
}

window.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeAdvancedEmbedDrawer();
    }
});
</script>

<!-- Scraper Sources Modal -->
<div id="sourcesModal" class="modal" style="display:none;">
    <div class="modal-content" style="max-width: 900px;">
        <div class="modal-header">
            <h2>إدارة مصادر جلب القنوات</h2>
            <button type="button" class="btn-icon" onclick="closeScraperSourcesModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <div id="sourcesList">
                <div class="table-responsive">
                    <table class="standings-table">
                        <thead>
                            <tr>
                                <th>الاسم</th>
                                <th>الرابط</th>
                                <th>المسار</th>
                                <th>الحالة</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody id="sourcesTableBody">
                            <!-- Loaded via AJAX -->
                        </tbody>
                    </table>
                </div>
                <div style="margin-top: 25px; display: flex; gap: 12px; justify-content: flex-end;">
                    <button class="btn btn-secondary" onclick="seedKoooraSport()">
                        <i class="fas fa-magic" style="margin-left: 8px;"></i> استعادة الافتراضي
                    </button>
                    <button class="btn btn-primary" onclick="showSourceForm()">
                        <i class="fas fa-plus" style="margin-left: 8px;"></i> إضافة مصدر جديد
                    </button>
                </div>
            </div>

            <div id="sourceForm" style="display:none;">
                <form id="scraperSourceForm" onsubmit="saveScraperSource(event)">
                    <input type="hidden" id="source_id">
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px;">
                        <div class="form-group">
                            <label>الاسم <i class="fas fa-info-circle info-icon" title="اسم تعريفي للمصدر"></i></label>
                            <input type="text" id="source_name" required placeholder="Kooora Sport" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>رابط الموقع <i class="fas fa-info-circle info-icon" title="الرابط الأساسي"></i></label>
                            <input type="url" id="source_base_url" required placeholder="https://..." class="form-control" style="direction: ltr; text-align: left;">
                        </div>
                        <div class="form-group">
                            <label>مسار مباريات اليوم <i class="fas fa-info-circle info-icon" title="المسار للقائمة"></i></label>
                            <input type="text" id="source_matches_path" required placeholder="/matches-today/" class="form-control" style="direction: ltr; text-align: left;">
                        </div>
                        <div class="form-group">
                            <label>حاوية المباراة (XPath) <i class="fas fa-info-circle info-icon" title="Container Selector"></i></label>
                            <input type="text" id="source_container_selector" required placeholder="//div[...]" class="form-control" style="direction: ltr; text-align: left;">
                        </div>
                        <div class="form-group">
                            <label>أسماء الفرق (XPath) <i class="fas fa-info-circle info-icon" title="Teams Selector"></i></label>
                            <input type="text" id="source_teams_selector" required placeholder=".//div[...]" class="form-control" style="direction: ltr; text-align: left;">
                        </div>
                        <div class="form-group">
                            <label>رابط التفاصيل (XPath) <i class="fas fa-info-circle info-icon" title="Link Selector"></i></label>
                            <input type="text" id="source_link_selector" required placeholder=".//a[...]" class="form-control" style="direction: ltr; text-align: left;">
                        </div>
                        <div class="form-group">
                            <label>رابط البث (XPath) <i class="fas fa-info-circle info-icon" title="Live Link Selector"></i></label>
                            <input type="text" id="source_live_link_selector" required placeholder="./a" class="form-control" style="direction: ltr; text-align: left;">
                        </div>
                        <div class="form-group" style="display: flex; align-items: center; gap: 10px; padding-top: 30px;">
                            <label style="margin:0;">تفعيل المصدر</label>
                            <label class="switch" style="transform: scale(0.8);">
                                <input type="checkbox" id="source_is_active" checked>
                                <span class="slider round"></span>
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer" style="padding: 20px 0 0 0; background: transparent;">
                        <button type="button" class="btn btn-secondary" onclick="hideSourceForm()">إلغاء</button>
                        <button type="submit" class="btn btn-primary">حفظ الإعدادات</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
.info-icon {
    color: var(--accent);
    cursor: help;
    margin-right: 5px;
    font-size: 0.8rem;
    opacity: 0.7;
}
.standings-table th {
    background: rgba(0,0,0,0.02);
    font-weight: 600;
}
.standings-table td {
    border-bottom: 1px solid rgba(0,0,0,0.03);
}
#scraperSourceForm .form-group label {
    display: block;
    margin-bottom: 8px;
    font-size: 0.9rem;
    font-weight: 500;
    color: var(--text-secondary);
}
</style>

<style>
.advanced-drawer-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.55);
    backdrop-filter: blur(6px);
    z-index: 1090;
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.25s ease, visibility 0.25s ease;
}

.advanced-drawer-backdrop.is-open {
    opacity: 1;
    visibility: visible;
}

.advanced-drawer {
    position: fixed;
    top: 0;
    left: 0;
    width: min(420px, calc(100vw - 24px));
    height: 100vh;
    background: var(--card-bg);
    border-right: 1px solid var(--border-color);
    box-shadow: 0 30px 80px rgba(15, 23, 42, 0.28);
    z-index: 1100;
    transform: translateX(-100%);
    transition: transform 0.28s ease;
    display: flex;
    flex-direction: column;
}

.advanced-drawer.is-open {
    transform: translateX(0);
}

.advanced-drawer-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    padding: 26px 24px 18px;
    border-bottom: 1px solid var(--border-color);
}

.advanced-drawer-header h2 {
    margin: 0 0 8px;
    font-size: 1.15rem;
    color: var(--text-primary);
}

.advanced-drawer-header p {
    margin: 0;
    color: var(--text-secondary);
    font-size: 0.88rem;
    line-height: 1.7;
}

.advanced-drawer-body {
    flex: 1;
    overflow-y: auto;
    padding: 20px 24px;
    display: grid;
    gap: 16px;
}

.advanced-setting-card {
    background: rgba(255,255,255,0.04);
    border: 1px solid var(--border-color);
    border-radius: 18px;
    padding: 18px;
}

.advanced-setting-card label {
    display: block;
    font-weight: 700;
    font-size: 0.95rem;
    color: var(--text-primary);
    margin-bottom: 8px;
}

.advanced-setting-card p {
    margin: 0 0 14px;
    color: var(--text-secondary);
    font-size: 0.84rem;
    line-height: 1.7;
}

.advanced-input-row {
    display: flex;
    align-items: center;
    gap: 12px;
}

.advanced-input-row input {
    max-width: 140px;
    text-align: center;
}

.advanced-input-row span {
    color: var(--text-secondary);
    font-size: 0.88rem;
    font-weight: 600;
}

.advanced-switch-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}

.advanced-drawer-footer {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    padding: 18px 24px 24px;
    border-top: 1px solid var(--border-color);
    background: linear-gradient(180deg, rgba(255,255,255,0), rgba(255,255,255,0.02));
}

@media (max-width: 768px) {
    .advanced-drawer {
        width: 100vw;
    }

    .advanced-switch-row {
        align-items: flex-start;
    }
}
</style>

<script>
function seedKoooraSport() {
    showSourceForm();
    document.getElementById('source_name').value = 'Kooora-Sport';
    document.getElementById('source_base_url').value = 'https://www.kooora-sport.com';
    document.getElementById('source_matches_path').value = '/matches-today/';
    document.getElementById('source_container_selector').value = "//div[contains(@class, 'AY_Match')]";
    document.getElementById('source_teams_selector').value = ".//div[@class='TM_Name']";
    document.getElementById('source_link_selector').value = ".//a[contains(@href, '/matches/')]";
    document.getElementById('source_live_link_selector').value = "./a";
    document.getElementById('source_is_active').checked = true;
}

function openScraperSourcesModal() {
    loadScraperSources();
    document.getElementById('sourcesModal').style.display = 'flex';
}

function closeScraperSourcesModal() {
    document.getElementById('sourcesModal').style.display = 'none';
    hideSourceForm();
}

let scraperSourcesData = [];

function loadScraperSources() {
    fetch('ajax_scraper_sources.php?action=list')
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                scraperSourcesData = data.data;
                const tbody = document.getElementById('sourcesTableBody');
                tbody.innerHTML = '';
                scraperSourcesData.forEach(source => {
                    tbody.innerHTML += `
                        <tr>
                            <td style="font-weight: 600;">${source.name}</td>
                            <td><code style="font-size: 0.8rem; color: var(--text-secondary)">${source.base_url}</code></td>
                            <td><span class="badge" style="background: rgba(59, 130, 246, 0.05); color: var(--accent);">${source.matches_path}</span></td>
                            <td>
                                <span class="badge ${source.is_active == 1 ? 'badge-success' : 'badge-danger'}">
                                    ${source.is_active == 1 ? 'نشط' : 'متوقف'}
                                </span>
                            </td>
                            <td>
                                <div style="display: flex; gap: 8px;">
                                    <button class="btn-icon" onclick="editSource(${source.id})" title="تعديل">
                                        <i class="fas fa-edit" style="color: #38bdf8;"></i>
                                    </button>
                                    <button class="btn-icon" onclick="deleteSource(${source.id})" title="حذف" style="background: rgba(239, 68, 68, 0.1);">
                                        <i class="fas fa-trash-alt" style="color: #ef4444;"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    `;
                });
            }
        });
}

function showSourceForm() {
    document.getElementById('sourcesList').style.display = 'none';
    document.getElementById('sourceForm').style.display = 'block';
    document.getElementById('scraperSourceForm').reset();
    document.getElementById('source_id').value = '';
}

function hideSourceForm() {
    document.getElementById('sourcesList').style.display = 'block';
    document.getElementById('sourceForm').style.display = 'none';
}

function editSource(id) {
    const source = scraperSourcesData.find(s => s.id == id);
    if (!source) return;
    
    showSourceForm();
    document.getElementById('source_id').value = source.id;
    document.getElementById('source_name').value = source.name;
    document.getElementById('source_base_url').value = source.base_url;
    document.getElementById('source_matches_path').value = source.matches_path;
    document.getElementById('source_container_selector').value = source.container_selector;
    document.getElementById('source_teams_selector').value = source.teams_selector;
    document.getElementById('source_link_selector').value = source.link_selector;
    document.getElementById('source_live_link_selector').value = source.live_link_selector;
    document.getElementById('source_is_active').checked = source.is_active == 1;
}

function saveScraperSource(e) {
    e.preventDefault();
    const formData = new FormData();
    formData.append('action', 'save');
    formData.append('id', document.getElementById('source_id').value);
    formData.append('name', document.getElementById('source_name').value);
    formData.append('base_url', document.getElementById('source_base_url').value);
    formData.append('matches_path', document.getElementById('source_matches_path').value);
    formData.append('container_selector', document.getElementById('source_container_selector').value);
    formData.append('teams_selector', document.getElementById('source_teams_selector').value);
    formData.append('link_selector', document.getElementById('source_link_selector').value);
    formData.append('live_link_selector', document.getElementById('source_live_link_selector').value);
    if (document.getElementById('source_is_active').checked) {
        formData.append('is_active', '1');
    }

    fetch('ajax_scraper_sources.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            hideSourceForm();
            loadScraperSources();
        } else {
            alert('حدث خطأ أثناء الحفظ');
        }
    });
}

function deleteSource(id) {
    if (!confirm('هل أنت متأكد من حذف هذا المصدر؟')) return;
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('id', id);

    fetch('ajax_scraper_sources.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            loadScraperSources();
        }
    });
}
</script>

<?php require_once 'includes/footer.php'; ?>



