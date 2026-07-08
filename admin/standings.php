<?php
require_once 'init.php';
require_once '../config.php';
require_once '../includes/Database.php';

$db = new Database($pdo);
$standingsLeagues = $db->getLatestLeaguesStandings();

$pageTitle = 'جداول الترتيب';
require_once 'includes/header.php';
?>

<div class="table-container standings-page-container">
    <div class="table-header standings-table-header">
        <div class="search-box">
            <i class="fas fa-search"></i>
            <input type="text" id="leagueSearch" class="form-control" placeholder="ابحث عن دوري..." onkeyup="filterLeagues()">
        </div>
        <div class="standings-toolbar">
            <div class="stats-badge">
                <span>عدد الدوريات: <?= count($standingsLeagues) ?></span>
            </div>
            <button class="btn-primary" onclick="openAddStandingsModal()">
                <i class="fas fa-plus"></i> إضافة جدول
            </button>
        </div>
    </div>

    <div class="standings-grid" id="standingsGrid">
        <?php if (empty($standingsLeagues)): ?>
            <div class="empty-state">
                <i class="fas fa-list-ol"></i>
                <h3>لا توجد جداول ترتيب متاحة</h3>
                <p>سيتم عرض جداول الترتيب هنا بمجرد توفرها من عمليات الجلب.</p>
            </div>
        <?php else: ?>
            <?php foreach ($standingsLeagues as $league): ?>
                <div class="standings-card" data-name="<?= strtolower($league['league_name_ar'] ?: $league['league_name']) ?>">
                    <div class="league-info">
                        <div class="league-logo">
                            <?php if (!empty($league['league_logo'])): ?>
                                <img src="<?= htmlspecialchars($league['league_logo']) ?>" alt="">
                            <?php else: ?>
                                <i class="fas fa-trophy"></i>
                            <?php endif; ?>
                        </div>
                        <div class="league-details">
                            <h3><?= htmlspecialchars($league['league_name_ar'] ?: $league['league_name']) ?></h3>
                            <small>آخر تحديث: <?= $league['standings_updated_at'] ? date('Y-m-d H:i', strtotime($league['standings_updated_at'])) : 'غير معروف' ?></small>
                        </div>
                        <button class="btn-view" title="عرض الجدول" onclick="showStandings(<?= htmlspecialchars(json_encode($league['standings']), ENT_QUOTES, 'UTF-8') ?>, '<?= htmlspecialchars($league['league_name_ar'] ?: $league['league_name']) ?>')">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    
                    <div class="standings-preview">
                        <table>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>الفريق</th>
                                    <th>لعب</th>
                                    <th>نقاط</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $topTeams = array_slice($league['standings'], 0, 5);
                                foreach ($topTeams as $team): 
                                ?>
                                    <tr>
                                        <td><?= $team['rank'] ?></td>
                                        <td class="team-cell">
                                            <img src="<?= $team['team_logo'] ?>" alt="" onerror="this.style.display='none'">
                                            <span><?= $team['team'] ?></span>
                                        </td>
                                        <td><?= $team['played'] ?></td>
                                        <td><strong><?= $team['points'] ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Add Standings Modal -->
<div id="addStandingsModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h2>إضافة جدول ترتيب جديد</h2>
            <button type="button" class="btn-icon" onclick="closeAddModal()"><i class="fas fa-times"></i></button>
        </div>
        <form id="addStandingsForm">
            <div class="modal-body">
                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">اختر الدوري</label>
                    <div class="custom-select-wrapper" style="position: relative;">
                        <div class="custom-select-trigger" onclick="toggleLeagueDropdown()" id="leagueSelectTrigger" style="width: 100%; padding: 12px 18px; border-radius: 14px; font-size: 0.95rem; cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                            <span id="selectedLeagueText">اختر الدوري...</span>
                            <i class="fas fa-chevron-down" style="font-size: 0.8rem; color: var(--text-secondary);"></i>
                        </div>
                        <input type="hidden" name="league_id" id="hiddenLeagueId" required>
                        
                        <div id="leagueDropdown" style="display: none; position: absolute; top: 100%; left: 0; right: 0; background: #ffffff; border: 1px solid #dbe3e9; border-radius: 18px; margin-top: 10px; z-index: 1000; box-shadow: 0 20px 40px rgba(0,0,0,0.1); overflow: hidden;">
                            <div style="padding: 10px; border-bottom: 1px solid #f1f5f9; background: #f8fafc;">
                                <input type="text" id="leagueSearchInput" placeholder="ابحث عن دوري..." onkeyup="filterLeagueSelect()" class="form-control" style="padding: 8px 12px; font-size: 0.9rem;">
                            </div>
                            <div id="leagueOptionsList" style="max-height: 250px; overflow-y: auto;">
                                <?php 
                                $allLeagues = $db->getAllLeagues();
                                foreach ($allLeagues as $l): 
                                ?>
                                    <div class="league-option" 
                                         data-value="<?= $l['id'] ?>" 
                                         data-search="<?= strtolower(($l['name_ar'] ?: $l['name']) . ' ' . $l['name']) ?>"
                                         onclick="selectLeague('<?= $l['id'] ?>', '<?= htmlspecialchars($l['name_ar'] ?: $l['name']) ?>')"
                                         style="padding: 12px 15px; cursor: pointer; color: var(--text-primary); font-size: 0.9rem; transition: all 0.2s; display: flex; align-items: center; gap: 10px; border-bottom: 1px solid #f1f5f9;">
                                        <?php if(!empty($l['logo_url'])): ?>
                                            <img src="<?= htmlspecialchars($l['logo_url']) ?>" style="width: 24px; height: 24px; object-fit: contain; border-radius: 4px;">
                                        <?php else: ?>
                                            <i class="fas fa-trophy" style="font-size: 0.8rem; color: var(--text-secondary); width: 24px; text-align: center;"></i>
                                        <?php endif; ?>
                                        <span><?= htmlspecialchars($l['name_ar'] ?: $l['name']) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; color: var(--text-secondary);">رابط مباراة من كووورة (لجلب الترتيب)</label>
                    <input type="url" name="match_url" class="form-control" placeholder="https://www.kooora.com/?m=..." required>
                    <small style="display: block; margin-top: 5px; color: var(--text-secondary); font-size: 0.8rem;">
                        سيقوم النظام بزيارة الرابط واستخراج جدول الترتيب الخاص بالدوري منه.
                    </small>
                </div>
                <div id="scrapeStatus" style="display: none; padding: 10px; border-radius: 8px; margin-top: 10px; font-size: 0.9rem;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeAddModal()">إلغاء</button>
                <button type="submit" class="btn-primary" id="submitBtn">
                    <i class="fas fa-sync"></i> جلب وحفظ
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Standings Modal -->
<div id="standingsModal" class="modal">
    <div class="modal-content" style="max-width: 900px;">
        <div class="modal-header">
            <h2 id="modalLeagueName">جدول الترتيب</h2>
            <button type="button" class="btn-icon" onclick="closeModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <div class="table-responsive">
                <table class="full-standings-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>الفريق</th>
                            <th>لعب</th>
                            <th>فوز</th>
                            <th>تعادل</th>
                            <th>خسارة</th>
                            <th>له</th>
                            <th>عليه</th>
                            <th>الفارق</th>
                            <th>النقاط</th>
                        </tr>
                    </thead>
                    <tbody id="standingsTableBody">
                        <!-- Data will be injected here -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<style>
.standings-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 20px;
    padding: 10px;
}

.standings-toolbar {
    display: flex;
    gap: 15px;
    align-items: center;
    justify-content: flex-end;
    flex-wrap: wrap;
}

.standings-card {
    background: #ffffff;
    border: 1px solid #f1f5f9;
    border-radius: 16px;
    padding: 20px;
    transition: all 0.3s ease;
    position: relative;
    box-shadow: var(--card-shadow);
}

.standings-card:hover {
    border-color: var(--accent);
    transform: translateY(-5px);
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
}

.league-info {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-bottom: 20px;
    position: relative;
}

.league-logo {
    width: 50px;
    height: 50px;
    background: #f8fafc;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 5px;
    border: 1px solid #f1f5f9;
}

.league-logo img {
    width: 100%;
    height: 100%;
    object-fit: contain;
}

.league-details {
    flex: 1;
}

.league-details h3 {
    font-size: 1.1rem;
    margin: 0 0 4px 0;
    color: var(--text-primary);
}

.league-details small {
    color: var(--text-secondary);
    font-size: 0.8rem;
}

.btn-view {
    position: absolute;
    top: 15px;
    left: 15px;
    background: rgba(59, 130, 246, 0.1);
    color: var(--accent);
    border: 1px solid rgba(59, 130, 246, 0.2);
    width: 35px;
    height: 35px;
    border-radius: 10px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s;
    z-index: 5;
}

.btn-view:hover {
    background: var(--accent);
    color: white;
    transform: rotate(15deg) scale(1.1);
}

.standings-preview table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.85rem;
}

.standings-preview th {
    text-align: right;
    color: var(--text-secondary);
    padding: 8px;
    border-bottom: 1px solid #f1f5f9;
}

.standings-preview td {
    padding: 10px 8px;
    color: var(--text-primary);
}

.team-cell {
    display: flex;
    align-items: center;
    gap: 10px;
}

.team-cell img {
    width: 20px;
    height: 20px;
    object-fit: contain;
}

.empty-state {
    grid-column: 1 / -1;
    text-align: center;
    padding: 60px;
    color: var(--text-secondary);
}

.empty-state i {
    font-size: 4rem;
    opacity: 0.1;
    margin-bottom: 20px;
}

/* Modal Styles */
.full-standings-table {
    width: 100%;
    border-collapse: collapse;
}

.full-standings-table th {
    background: #f8fafc;
    padding: 15px;
    text-align: center;
    color: var(--text-secondary);
    font-weight: 600;
    border-bottom: 2px solid #f1f5f9;
}

.full-standings-table td {
    padding: 12px 15px;
    text-align: center;
    border-bottom: 1px solid #f1f5f9;
}

.full-standings-table .team-cell {
    text-align: right;
    justify-content: flex-start;
}

.full-standings-table tr:hover {
    background: rgba(255,255,255,0.01);
}

.league-option:hover {
    background: rgba(59, 130, 246, 0.1);
    color: var(--accent) !important;
}

.league-option.selected {
    background: rgba(59, 130, 246, 0.2);
    color: var(--accent) !important;
    font-weight: 600;
}

#leagueOptionsList::-webkit-scrollbar {
    width: 5px;
}

#leagueOptionsList::-webkit-scrollbar-thumb {
    background: rgba(255,255,255,0.1);
    border-radius: 10px;
}

html[data-admin-lang="en"] .standings-page-container,
html[data-admin-lang="en"] .standings-page-container * {
    direction: ltr;
}

html[data-admin-lang="en"] .standings-page-container .standings-table-header {
    align-items: flex-start;
}

html[data-admin-lang="en"] .standings-page-container .standings-toolbar {
    justify-content: flex-end;
}

html[data-admin-lang="en"] .standings-page-container .standings-card {
    text-align: left;
}

html[data-admin-lang="en"] .standings-page-container .league-info {
    padding-right: 44px;
}

html[data-admin-lang="en"] .standings-page-container .btn-view {
    left: auto;
    right: 15px;
}

html[data-admin-lang="en"] .standings-page-container .league-details,
html[data-admin-lang="en"] .standings-page-container .league-details h3,
html[data-admin-lang="en"] .standings-page-container .league-details small,
html[data-admin-lang="en"] .standings-page-container .standings-preview th,
html[data-admin-lang="en"] .standings-page-container .standings-preview td {
    text-align: left;
}

html[data-admin-lang="en"] .standings-page-container .team-cell,
html[data-admin-lang="en"] .standings-page-container .full-standings-table .team-cell {
    justify-content: flex-start;
    text-align: left;
}

@media (max-width: 1200px) {
    .standings-toolbar {
        justify-content: flex-start;
    }
}
</style>

<script>
function filterLeagues() {
    const filter = document.getElementById('leagueSearch').value.toLowerCase();
    const cards = document.querySelectorAll('.standings-card');
    cards.forEach(card => {
        if (card.getAttribute('data-name').includes(filter)) {
            card.style.display = 'block';
        } else {
            card.style.display = 'none';
        }
    });
}

function showStandings(standings, leagueName) {
    const modal = document.getElementById('standingsModal');
    const tbody = document.getElementById('standingsTableBody');
    document.getElementById('modalLeagueName').innerText = 'جدول ترتيب ' + leagueName;
    
    tbody.innerHTML = '';
    standings.forEach(team => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>${team.rank}</td>
            <td class="team-cell">
                <img src="${team.team_logo}" onerror="this.style.display='none'">
                <span>${team.team}</span>
            </td>
            <td>${team.played}</td>
            <td>${team.won}</td>
            <td>${team.drawn}</td>
            <td>${team.lost}</td>
            <td>${team.goals_for}</td>
            <td>${team.goals_against}</td>
            <td>${team.goal_diff}</td>
            <td><strong>${team.points}</strong></td>
        `;
        tbody.appendChild(row);
    });
    
    modal.style.display = 'flex';
}

function closeModal() {
    document.getElementById('standingsModal').style.display = 'none';
}

function openAddStandingsModal() {
    document.getElementById('addStandingsModal').style.display = 'flex';
}

function closeAddModal() {
    document.getElementById('addStandingsModal').style.display = 'none';
    document.getElementById('addStandingsForm').reset();
    document.getElementById('scrapeStatus').style.display = 'none';
    document.getElementById('selectedLeagueText').innerText = 'اختر الدوري...';
    document.getElementById('hiddenLeagueId').value = '';
}

document.getElementById('addStandingsForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = document.getElementById('submitBtn');
    const status = document.getElementById('scrapeStatus');
    const formData = new FormData(this);
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري الجلب...';
    status.style.display = 'block';
    status.style.background = 'rgba(59, 130, 246, 0.1)';
    status.style.color = 'var(--accent)';
    status.innerText = 'جاري الاتصال بموقع كووورة واستخراج البيانات...';

    fetch('ajax_scrape_standings.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            status.style.background = 'rgba(16, 185, 129, 0.1)';
            status.style.color = '#10b981';
            status.innerText = '✅ تم جلب وحفظ جدول الترتيب بنجاح!';
            setTimeout(() => location.reload(), 1500);
        } else {
            status.style.background = 'rgba(239, 68, 68, 0.1)';
            status.style.color = '#ef4444';
            status.innerText = '❌ خطأ: ' + data.message;
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-sync"></i> جلب وحفظ';
        }
    })
    .catch(error => {
        status.style.background = 'rgba(239, 68, 68, 0.1)';
        status.style.color = '#ef4444';
        status.innerText = '❌ حدث خطأ في الاتصال بالسيرفر';
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-sync"></i> جلب وحفظ';
    });
});

function toggleLeagueDropdown() {
    const dropdown = document.getElementById('leagueDropdown');
    const isVisible = dropdown.style.display === 'block';
    dropdown.style.display = isVisible ? 'none' : 'block';
    if (!isVisible) {
        document.getElementById('leagueSearchInput').focus();
        document.getElementById('leagueSearchInput').value = '';
        filterLeagueSelect();
    }
}

function selectLeague(value, label) {
    document.getElementById('hiddenLeagueId').value = value;
    document.getElementById('selectedLeagueText').innerText = label;
    document.getElementById('leagueDropdown').style.display = 'none';
    
    // Update active class
    document.querySelectorAll('#leagueOptionsList .league-option').forEach(opt => {
        opt.classList.remove('selected');
        if (opt.getAttribute('data-value') === value) opt.classList.add('selected');
    });
}

function filterLeagueSelect() {
    const filter = document.getElementById('leagueSearchInput').value.toLowerCase();
    const options = document.querySelectorAll('#leagueOptionsList .league-option');
    options.forEach(opt => {
        const searchText = opt.getAttribute('data-search');
        if (searchText.includes(filter)) {
            opt.style.display = 'flex';
        } else {
            opt.style.display = 'none';
        }
    });
}

window.onclick = function(event) {
    if (event.target == document.getElementById('standingsModal')) {
        closeModal();
    }
    if (event.target == document.getElementById('addStandingsModal')) {
        closeAddModal();
    }
    
    // Close custom select if clicking outside
    if (!event.target.closest('.custom-select-wrapper')) {
        const dropdown = document.getElementById('leagueDropdown');
        if (dropdown) dropdown.style.display = 'none';
    }
}
</script>

<?php require_once 'includes/footer.php'; ?>
