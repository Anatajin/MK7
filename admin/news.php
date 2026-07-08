<?php
require_once 'init.php';
require_once '../config.php';
require_once '../includes/Database.php';

$db = new Database($pdo);

// Handle Delete Single
if (isset($_POST['delete_id'])) {
    $db->deleteNews($_POST['delete_id']);
    header('Location: news.php?msg=deleted');
    exit;
}

// Handle Delete Selected
if (isset($_POST['delete_selected']) && isset($_POST['selected_ids'])) {
    foreach ($_POST['selected_ids'] as $id) {
        $db->deleteNews($id);
    }
    header('Location: news.php?msg=deleted_bulk');
    exit;
}

// Handle Add News
if (isset($_POST['add_news'])) {
    $newsData = [
        'title' => $_POST['title'],
        'title_en' => $_POST['title_en'] ?? null,
        'image' => $_POST['image'] ?? null,
        'url' => $_POST['url'] ?? '#',
        'date' => $_POST['date'] . ' ' . ($_POST['time'] ?? '00:00:00'),
        'body' => $_POST['body'] ?? null,
        'body_en' => $_POST['body_en'] ?? null
    ];
    $db->saveNews($newsData);
    header('Location: news.php?msg=added');
    exit;
}

// Handle Update News
if (isset($_POST['update_news'])) {
    $newsData = [
        'title' => $_POST['title'],
        'title_en' => $_POST['title_en'] ?? null,
        'image' => $_POST['image'] ?? null,
        'url' => $_POST['url'] ?? '#',
        'date' => $_POST['date'] . ' ' . ($_POST['time'] ?? '00:00:00'),
        'body' => $_POST['body'] ?? null,
        'body_en' => $_POST['body_en'] ?? null
    ];
    $db->updateNewsById($_POST['news_id'], $newsData);
    header('Location: news.php?msg=updated');
    exit;
}

// Pagination & Filters
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;
$search = $_GET['search'] ?? null;
$dateFilter = $_GET['date'] ?? 'today'; // Default to today

$newsList = $db->getAllNews($limit, $offset, $search, $dateFilter);
$totalNews = $db->getNewsCount($search, $dateFilter);
$totalPages = ceil($totalNews / $limit);

$filterLabels = [
    'all' => 'الكل',
    'today' => 'اليوم',
    'yesterday' => 'الأمس',
    'translated' => 'المترجمة',
    'missing' => 'غير المترجمة'
];
$current_filter_label = $filterLabels[$dateFilter] ?? 'فلتر';

$pageTitle = 'إدارة الأخبار';
require_once 'includes/header.php';
?>

<div class="table-container">
    <form method="POST" id="bulkActionForm">
        <input type="hidden" name="delete_selected" value="1">
        
        <div class="table-header">
            <div style="display:flex; align-items:center; gap:15px;">
                <h3>
                    <?= $current_filter_label === 'الكل' ? 'جميع الأخبار' : 'أخبار ' . $current_filter_label ?>
                    <span style="color: var(--text-secondary); font-size: 0.9rem; font-weight: normal; margin-right: 10px; background: rgba(255,255,255,0.05); padding: 2px 10px; border-radius: 20px;">
                        <?= $totalNews ?> خبر
                    </span>
                </h3>
                <div style="display:flex; align-items:center; gap:10px;">
                    <label style="display:flex; align-items:center; gap:5px; cursor:pointer;">
                        <input type="checkbox" id="selectAll" onclick="toggleSelectAll(this)">
                        <span>تحديد الكل</span>
                    </label>
                    <button type="submit" class="btn-danger" id="deleteSelectedBtn" style="display:none;" onclick="return confirm('هل أنت متأكد من حذف الأخبار المحددة؟')">
                        <i class="fas fa-trash"></i> حذف المحدد
                    </button>
                </div>
            </div>
            <div style="display: flex; gap: 15px; align-items: center;">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="newsSearch" class="form-control" placeholder="بحث في الأخبار..." value="<?= htmlspecialchars($search ?? '') ?>" onkeyup="if(event.key === 'Enter') window.location.href='news.php?date=<?= $dateFilter ?>&search=' + this.value" style="width: 250px;">
                </div>
                <div class="filter-container">
                    <button type="button" class="btn-filter" onclick="toggleFilterDropdown()">
                        <i class="fas fa-calendar-alt"></i>
                        <span><?= $current_filter_label ?></span>
                        <i class="fas fa-chevron-down" style="font-size: 0.7rem; margin-right: 5px; opacity: 0.6;"></i>
                    </button>
                    <div id="filterDropdown" class="filter-dropdown">
                        <a href="news.php?date=all<?= $search ? '&search='.urlencode($search) : '' ?>" class="filter-item <?= $dateFilter === 'all' ? 'active' : '' ?>">
                            <span>الكل</span>
                            <i class="fas fa-check"></i>
                        </a>
                        <a href="news.php?date=today<?= $search ? '&search='.urlencode($search) : '' ?>" class="filter-item <?= $dateFilter === 'today' ? 'active' : '' ?>">
                            <span>اليوم</span>
                            <i class="fas fa-check"></i>
                        </a>
                        <a href="news.php?date=yesterday<?= $search ? '&search='.urlencode($search) : '' ?>" class="filter-item <?= $dateFilter === 'yesterday' ? 'active' : '' ?>">
                            <span>الأمس</span>
                            <i class="fas fa-check"></i>
                        </a>
                        <a href="news.php?date=translated<?= $search ? '&search='.urlencode($search) : '' ?>" class="filter-item <?= $dateFilter === 'translated' ? 'active' : '' ?>">
                            <span>المترجمة</span>
                            <i class="fas fa-check"></i>
                        </a>
                        <a href="news.php?date=missing<?= $search ? '&search='.urlencode($search) : '' ?>" class="filter-item <?= $dateFilter === 'missing' ? 'active' : '' ?>">
                            <span>غير المترجمة</span>
                            <i class="fas fa-check"></i>
                        </a>
                    </div>
                </div>
                <button type="button" class="btn-primary" onclick="openAddModal()">
                    <i class="fas fa-plus"></i> إضافة خبر
                </button>
                <button type="button" class="btn-ai" id="aiTranslateBtn" onclick="startAiTranslation()" title="ترجمة الأخبار المفقودة باستخدام الذكاء الاصطناعي">
                    <i class="fa-solid fa-wand-sparkles"></i>
                </button>
            </div>
        </div>
        
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th style="width: 40px;"></th>
                        <th>الصورة</th>
                        <th>العنوان</th>
                        <th>التاريخ</th>
                        <th style="text-align: left;">إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($newsList)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 80px 20px; color: var(--text-secondary);">
                            <div style="display: flex; flex-direction: column; align-items: center; gap: 15px;">
                                <i class="fas fa-newspaper" style="font-size: 4rem; opacity: 0.1;"></i>
                                <h3 style="color: var(--text-primary);">لا توجد أخبار حالياً</h3>
                                <p>ابدأ بإضافة أول خبر للموقع</p>
                                <button type="button" class="btn-primary" onclick="openAddModal()" style="margin-top: 10px;">إضافة خبر</button>
                            </div>
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($newsList as $news): ?>
                        <tr>
                            <td>
                                <input type="checkbox" name="selected_ids[]" value="<?= $news['id'] ?>" class="news-checkbox" onclick="updateDeleteBtn()">
                            </td>
                            <td>
                                <?php if($news['image']): ?>
                                    <img src="<?= htmlspecialchars($news['image']) ?>" class="news-image-preview">
                                <?php else: ?>
                                    <div class="news-image-preview" style="background: #f1f5f9; display:flex; align-items:center; justify-content:center;">
                                        <i class="fas fa-image" style="color:#cbd5e1;"></i>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="news-title-cell" title="<?= htmlspecialchars($news['title']) ?>">
                                <div style="font-weight: 600; color: var(--text-primary); margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                    <?= htmlspecialchars($news['title']) ?>
                                </div>
                                <?php if(!empty($news['title_en'])): ?>
                                    <div style="font-size: 0.8rem; color: var(--text-secondary); font-style: italic; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($news['title_en']) ?>">
                                        <?= htmlspecialchars($news['title_en']) ?>
                                    </div>
                                <?php else: ?>
                                    <div style="font-size: 0.75rem; color: #ef4444; opacity: 0.7;">
                                        <i class="fas fa-language"></i> لم تتم الترجمة بعد
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="font-weight: 500;"><?= date('Y-m-d', strtotime($news['date'])) ?></div>
                                <small style="color: var(--text-secondary);"><?= date('H:i', strtotime($news['date'])) ?></small>
                            </td>
                            </td>
                            <td>
                                <div style="display:flex; gap:8px; justify-content:flex-end;">
                                    <button type="button" class="btn-icon" title="تعديل" onclick="openEditModal(<?= htmlspecialchars(json_encode($news), ENT_QUOTES, 'UTF-8') ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button type="button" class="btn-icon" style="color:#ef4444;" title="حذف" onclick="deleteSingle(<?= $news['id'] ?>)">
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
        <div class="pagination-container" style="padding: 20px; display: flex; justify-content: center; gap: 10px; border-top: 1px solid var(--border-color);">
            <?php if ($page > 1): ?>
                <a href="news.php?page=<?= $page - 1 ?>&date=<?= $dateFilter ?><?= $search ? '&search='.urlencode($search) : '' ?>" class="btn-filter"><i class="fas fa-chevron-right"></i></a>
            <?php endif; ?>

            <?php
            $start = max(1, $page - 2);
            $end = min($totalPages, $page + 2);
            for ($i = $start; $i <= $end; $i++):
            ?>
                <a href="news.php?page=<?= $i ?>&date=<?= $dateFilter ?><?= $search ? '&search='.urlencode($search) : '' ?>" class="btn-filter <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>

            <?php if ($page < $totalPages): ?>
                <a href="news.php?page=<?= $page + 1 ?>&date=<?= $dateFilter ?><?= $search ? '&search='.urlencode($search) : '' ?>" class="btn-filter"><i class="fas fa-chevron-left"></i></a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </form>
</div>

<!-- Single Delete Form -->
<form id="singleDeleteForm" method="POST" style="display:none;">
    <input type="hidden" name="delete_id" id="delete_id_input">
</form>

<!-- Add News Modal -->
<div id="addNewsModal" class="modal">
    <div class="modal-content" style="max-width: 800px;">
        <div class="modal-header">
            <h2>إضافة خبر جديد</h2>
            <button type="button" class="btn-icon" onclick="document.getElementById('addNewsModal').style.display='none'"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="add_news" value="1">
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">العنوان (بالعربية)</label>
                        <input type="text" name="title" class="form-control" required placeholder="عنوان الخبر بالعربية">
                    </div>
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">العنوان (بالإنجليزية)</label>
                        <input type="text" name="title_en" class="form-control" placeholder="News Title in English">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">رابط الصورة</label>
                        <input type="url" name="image" class="form-control" placeholder="https://example.com/image.jpg">
                    </div>
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">رابط الخبر الأصلي</label>
                        <input type="url" name="url" class="form-control" placeholder="https://www.kooora.com/...">
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">التاريخ</label>
                        <input type="date" name="date" class="form-control" required value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">الوقت</label>
                        <input type="time" name="time" class="form-control" required value="<?= date('H:i') ?>">
                    </div>
                </div>



                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">محتوى الخبر (بالعربية - HTML)</label>
                        <textarea name="body" class="form-control" rows="8" style="font-family: monospace; font-size: 0.85rem;"></textarea>
                    </div>
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">محتوى الخبر (بالإنجليزية - HTML)</label>
                        <textarea name="body_en" class="form-control" rows="8" style="font-family: monospace; font-size: 0.85rem;"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="document.getElementById('addNewsModal').style.display='none'">إلغاء</button>
                <button type="submit" class="btn-primary">إضافة الخبر</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit News Modal -->
<div id="editNewsModal" class="modal">
    <div class="modal-content" style="max-width: 800px;">
        <div class="modal-header">
            <h2>تعديل الخبر</h2>
            <button type="button" class="btn-icon" onclick="document.getElementById('editNewsModal').style.display='none'"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="update_news" value="1">
                <input type="hidden" name="news_id" id="edit_news_id">
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">العنوان (بالعربية)</label>
                        <input type="text" name="title" id="edit_title" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">العنوان (بالإنجليزية)</label>
                        <input type="text" name="title_en" id="edit_title_en" class="form-control">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">رابط الصورة</label>
                        <input type="url" name="image" id="edit_image" class="form-control">
                    </div>
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">رابط الخبر الأصلي</label>
                        <input type="url" name="url" id="edit_url" class="form-control">
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">التاريخ</label>
                        <input type="date" name="date" id="edit_date" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">الوقت</label>
                        <input type="time" name="time" id="edit_time" class="form-control" required>
                    </div>
                </div>



                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">محتوى الخبر (بالعربية - HTML)</label>
                        <textarea name="body" id="edit_body" class="form-control" rows="8" style="font-family: monospace; font-size: 0.85rem;"></textarea>
                    </div>
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">محتوى الخبر (بالإنجليزية - HTML)</label>
                        <textarea name="body_en" id="edit_body_en" class="form-control" rows="8" style="font-family: monospace; font-size: 0.85rem;"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="document.getElementById('editNewsModal').style.display='none'">إلغاء</button>
                <button type="submit" class="btn-primary">حفظ التعديلات</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleSelectAll(source) {
    const checkboxes = document.querySelectorAll('.news-checkbox');
    checkboxes.forEach(cb => cb.checked = source.checked);
    updateDeleteBtn();
}

function updateDeleteBtn() {
    const checkboxes = document.querySelectorAll('.news-checkbox:checked');
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
    document.getElementById('addNewsModal').style.display = 'flex';
}

function openEditModal(news) {
    document.getElementById('edit_news_id').value = news.id;
    document.getElementById('edit_title').value = news.title;
    document.getElementById('edit_image').value = news.image || '';
    document.getElementById('edit_url').value = news.url || '';
    
    // Split date and time
    const date = news.date.split(' ')[0];
    const time = news.date.split(' ')[1].substring(0, 5);
    
    document.getElementById('edit_date').value = date;
    document.getElementById('edit_time').value = time;

    document.getElementById('edit_body').value = news.body || '';
    document.getElementById('edit_title_en').value = news.title_en || '';
    document.getElementById('edit_body_en').value = news.body_en || '';
    
    document.getElementById('editNewsModal').style.display = 'flex';
}

// Close modal when clicking outside
document.getElementById('addNewsModal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});
document.getElementById('editNewsModal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});

function toggleFilterDropdown() {
    document.getElementById('filterDropdown').classList.toggle('show');
}

// AI Translation Functionality
async function startAiTranslation() {
    const btn = document.getElementById('aiTranslateBtn');
    
    if (!confirm('هل تريد بدء ترجمة الأخبار التي تنقصها الترجمة باستخدام الذكاء الاصطناعي؟ (سيتم ترجمة أحدث 20 خبر)')) {
        return;
    }

    try {
        btn.classList.add('loading');
        
        const response = await fetch('ajax_translate_news.php');
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

// Close dropdown when clicking outside
window.addEventListener('click', function(e) {
    if (!e.target.closest('.filter-container')) {
        const dropdown = document.getElementById('filterDropdown');
        if(dropdown) dropdown.classList.remove('show');
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>
