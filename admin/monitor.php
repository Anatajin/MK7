<?php
require_once 'init.php';
$pageTitle = 'مراقبة الخادم';
require_once 'includes/header.php';
?>
<style>
body, body *:not(i):not([class*="fa-"]):not(script):not(style) {
    font-family: 'Outfit', 'Inter', sans-serif !important;
}
.monitor-shell{display:flex;flex-direction:column;gap:24px}
.monitor-toolbar-card,.monitor-card-clean{background:#fff;border:1px solid #edf2f7;border-radius:24px;box-shadow:var(--card-shadow)}
.monitor-toolbar{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;flex-wrap:wrap}
.monitor-toolbar-copy h2{margin:0 0 10px;color:var(--text-primary);font-size:1.35rem}
.monitor-toolbar-copy p{margin:0;max-width:760px;color:var(--text-secondary);line-height:1.9;font-size:.92rem}
.monitor-toolbar-actions,.monitor-card-header-tools{display:flex;flex-wrap:wrap;align-items:center;gap:10px}
.monitor-toolbar-actions{justify-content:flex-end}
.monitor-chip-group{display:inline-flex;gap:8px;padding:7px;background:#f8fbff;border:1px solid #dbeafe;border-radius:16px}
.monitor-chip-btn,.monitor-action-btn{border:0;cursor:pointer;transition:.2s;font-weight:700}
.monitor-chip-btn{border-radius:13px;padding:11px 16px;background:transparent;color:var(--text-secondary);font-size:.83rem}
.monitor-chip-btn:hover{background:rgba(59,130,246,.08);color:#2563eb}
.monitor-chip-btn.is-active,.monitor-action-btn.primary{background:linear-gradient(135deg,#4f8df7,#2563eb);color:#fff;box-shadow:0 12px 20px rgba(37,99,235,.20)}
.monitor-action-btn{border-radius:15px;padding:11px 16px;background:#eef4ff;color:#2563eb;font-size:.82rem;display:inline-flex;gap:8px;align-items:center}
.monitor-action-btn:hover{transform:translateY(-1px);background:#e0ebff}
.monitor-action-btn:disabled{opacity:.55;cursor:not-allowed;transform:none;box-shadow:none}
.monitor-generated-at,.monitor-section-tag{display:inline-flex;gap:8px;align-items:center;padding:10px 14px;border-radius:14px;background:#f8fbff;border:1px solid #dbeafe;color:#5b6b86;font-size:.8rem;font-weight:700}
.monitor-generated-at i{color:#10b981}
.monitor-top-grid,.monitor-body-grid{display:grid;grid-template-columns:minmax(320px,1fr) minmax(480px,1.55fr);gap:24px}
.monitor-side-stack,.monitor-main-stack,.monitor-alerts-grid,.monitor-service-list{display:flex;flex-direction:column;gap:14px}
.monitor-side-stack,.monitor-main-stack{gap:24px}
.card{border-radius:12px}
.monitor-card-clean .card-header{margin-bottom:18px;padding-bottom:16px;border-bottom:1px solid #f1f5f9;display:flex;align-items:flex-start;justify-content:space-between;gap:14px;flex-wrap:wrap}
.monitor-card-clean .card-title{margin:0;font-size:1.28rem;color:var(--text-primary)}
.monitor-card-lead,.monitor-alert-body,.key-sub,.monitor-service-description{font-size:.8rem;color:var(--text-secondary);line-height:1.8}
.monitor-chart{width:100%;height:320px}
.monitor-chart-sm{height:360px}
.monitor-chart-security{height:340px}
.monitor-panel-header{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 18px;margin-bottom:16px;background:#fff;border:1px solid #eef3f8;border-radius:12px;box-shadow:0 10px 26px rgba(148,163,184,.08)}
.monitor-panel-title{display:flex;align-items:center;gap:10px;font-size:1.04rem;font-weight:700;color:var(--text-primary)}
.monitor-panel-title i{color:#3b82f6}
.monitor-section-tag{background:rgba(16,185,129,.14);border-radius:12px;border:none;font-size:10px;color:#047857;padding:10px}
#monitorGeneratedAt{background:transparent;padding:0;font-size:12px;font-weight:500;color:var(--text-secondary)}
.monitor-compact-grid,.monitor-system-grid{display:grid;gap:14px}
.monitor-compact-grid{grid-template-columns:repeat(3,minmax(0,1fr))}
.monitor-system-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
.monitor-compact-card,.monitor-system-card,.monitor-alert,.monitor-cache-box{border:1px solid #edf2f7;border-radius:20px;background:#fff}
.monitor-compact-card{padding:14px 16px;display:flex;gap:12px;min-height:92px}
.monitor-compact-icon{width:38px;height:38px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:.92rem;flex-shrink:0}
.monitor-compact-info{display:flex;flex-direction:column;gap:4px;min-width:0}
.monitor-compact-info p{margin:0;font-size:.74rem;color:var(--text-secondary)}
.monitor-compact-info h4{margin:0;font-size:1.12rem;color:var(--text-primary)}
.monitor-compact-note{font-size:.7rem;color:var(--text-secondary);line-height:1.55}
.monitor-system-card{padding:14px 16px}
.monitor-system-card .meta-label{font-size:.74rem;color:var(--text-secondary);margin-bottom:8px}
.monitor-system-card .meta-value,.monitor-cache-box .value{font-size:.95rem;font-weight:700;color:var(--text-primary);word-break:break-word}
.monitor-alert,.monitor-cache-box{padding:15px 16px}
.monitor-alert{position:relative;border-radius:15px 2px 2px 15px;overflow:hidden;box-shadow:0 4px 18px rgba(226,232,240,0.4);border:1px solid #e2e8f0}
.monitor-alert::before{content:'';position:absolute;right:0;top:-1px;bottom:-1px;width:5px}
.monitor-alert.ok::before{background:#10b981}
.monitor-alert.warning::before{background:#f59e0b}
.monitor-alert.danger::before{background:#ef4444}
.monitor-alert-head{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:8px}
.monitor-alert-title,.monitor-service-name{font-size:.95rem;font-weight:700;color:var(--text-primary)}
.monitor-alert-badge{border-radius:999px;padding:6px 10px;font-size:.72rem;font-weight:700}
.monitor-alert.ok .monitor-alert-badge{background:rgba(16,185,129,.14);color:#047857}
.monitor-alert.warning .monitor-alert-badge{background:rgba(245,158,11,.14);color:#b45309}
.monitor-alert.danger .monitor-alert-badge{background:rgba(239,68,68,.14);color:#b91c1c}
.monitor-cache-box .label{font-size:.79rem;color:var(--text-secondary);margin-bottom:8px}
.monitor-service-card{display:grid;grid-template-columns:auto 1fr auto;align-items:center;gap:14px;padding:12px 16px;direction:rtl;transition:.2s;background:#fff;border-radius:20px}
.monitor-service-card.is-active{box-shadow:none}
.monitor-service-card.is-busy{opacity:.65;pointer-events:none}
.monitor-service-icon{width:56px;height:56px;border-radius:18px;display:flex;align-items:center;justify-content:center;font-size:1.15rem}
.theme-blue{background:rgba(59,130,246,.10);color:#3b82f6}
.theme-purple{background:rgba(139,92,246,.10);color:#8b5cf6}
.theme-violet{background:rgba(124,58,237,.10);color:#7c3aed}
.theme-green{background:rgba(16,185,129,.10);color:#10b981}
.monitor-service-content{display:flex;flex-direction:column;gap:4px;min-width:0}
.monitor-service-state{font-size:.79rem;font-weight:700}
.monitor-service-state.active{color:#10b981}
.monitor-service-state.inactive{color:#64748b}
.monitor-switch{position:relative;display:inline-block;width:50px;height:28px}
.monitor-switch input{opacity:0;width:0;height:0}
.monitor-switch-slider{position:absolute;inset:0;cursor:pointer;background:#cbd5e1;border-radius:999px;transition:.2s}
.monitor-switch-slider:before{content:'';position:absolute;height:20px;width:20px;left:4px;top:4px;background:#fff;border-radius:50%;transition:.2s;box-shadow:0 2px 8px rgba(15,23,42,.16)}
.monitor-switch input:checked+.monitor-switch-slider{background:#3b82f6}
.monitor-switch input:checked+.monitor-switch-slider:before{transform:translateX(22px)}
.monitor-switch input:disabled+.monitor-switch-slider{cursor:not-allowed;background:#dbe4ee}
.top-keys-table{width:100%;border-collapse:collapse}
.top-keys-table th,.top-keys-table td{padding:14px 12px;text-align:right;border-bottom:1px solid #edf2f7;vertical-align:middle}
.top-keys-table th{color:var(--text-secondary);font-size:.79rem;font-weight:700}
.top-keys-table td{color:var(--text-primary);font-size:.88rem}
.monitor-ips-toolbar{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap}
.monitor-ips-actions,.monitor-ips-filters{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.monitor-ips-table-wrap{overflow:auto;border-radius:18px}
.monitor-ips-table{width:100%;border-collapse:collapse}
.monitor-ips-table th,.monitor-ips-table td{padding:14px 12px;text-align:right;border-bottom:1px solid #edf2f7;vertical-align:middle}
.monitor-ips-table th{color:var(--text-secondary);font-size:.79rem;font-weight:700}
.monitor-ips-table td{color:var(--text-primary);font-size:.87rem}
.monitor-ip-checkbox{width:18px;height:18px;accent-color:#2563eb;cursor:pointer}
.monitor-ip-main{display:flex;flex-direction:column;gap:5px}
.monitor-ip-meta{font-size:.75rem;color:var(--text-secondary)}
.monitor-ip-badge{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;font-size:.72rem;font-weight:700}
.monitor-ip-badge.blocked{background:rgba(239,68,68,.12);color:#b91c1c}
.monitor-ip-badge.active{background:rgba(16,185,129,.12);color:#047857}
.monitor-ip-badge.rate{background:rgba(59,130,246,.12);color:#1d4ed8}
.monitor-ip-target{display:inline-block;max-width:260px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.monitor-ip-link{display:inline-flex;align-items:center;gap:6px;color:#1d4ed8;font:inherit;font-weight:800;cursor:pointer;text-align:right;text-decoration:none}
.monitor-ip-link:hover{color:#2563eb;text-decoration:underline}
.key-name{font-weight:700;margin-bottom:4px}
.empty-state{padding:40px 20px;color:#94a3b8;font-size:1.05rem;font-weight:700;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;border:none;background:transparent;text-align:center}
.empty-state i.empty-icon{font-size:3.5rem;color:#e2e8f0;margin-bottom:4px}
.monitor-loading{display:inline-flex;gap:10px;align-items:center}
.monitor-loading i{color:#2563eb}
@media (max-width:1200px){.monitor-compact-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:1024px){.monitor-top-grid,.monitor-body-grid{grid-template-columns:1fr}}
@media (max-width:900px){.monitor-toolbar{flex-direction:column}.monitor-toolbar-actions{justify-content:flex-start}.monitor-system-grid,.monitor-compact-grid{grid-template-columns:1fr}.monitor-service-card{grid-template-columns:1fr;justify-items:flex-start}.monitor-ips-toolbar{align-items:flex-start}.monitor-ips-table th,.monitor-ips-table td{padding:12px 10px}}
</style>

<div class="monitor-shell">
  <section class="monitor-panel-shell">
    <div class="monitor-panel-header">
      <div class="monitor-panel-title"><i class="fas fa-server"></i><span>معلومات الخادم</span></div>
      <span class="monitor-section-tag" id="monitorGeneratedAt">جاري التحميل...</span>
    </div>
    <div id="systemMetaGrid" class="monitor-system-grid" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));"><div class="empty-state"><div class="monitor-loading"><i class="fas fa-circle-notch fa-spin"></i> جاري جلب معلومات الخادم...</div></div></div>
  </section>

  <div class="monitor-top-grid">
    <section class="card monitor-card-clean">
      <div class="card-header" style="align-items:center;border-bottom:none;">
        <div><h3 class="card-title">توزيع الكاش</h3></div>
        <div class="monitor-card-header-tools"><span class="monitor-section-tag" id="cacheHitPill">Hit Ratio --</span></div>
      </div>
      <div id="cacheChart" class="monitor-chart monitor-chart-sm"></div>
    </section>

    <section class="card monitor-card-clean">
      <div class="card-header" style="align-items:center;border-bottom:none;">
        <div><h3 class="card-title">ضغط طلبات API</h3></div>
        <div class="monitor-card-header-tools">
          <div class="monitor-chip-group" id="monitorWindowButtons" style="background:transparent;border:none;padding:0;">
            <button class="monitor-chip-btn" data-window="15" type="button">15 دقيقة</button>
            <button class="monitor-chip-btn is-active" data-window="60" type="button">ساعة</button>
            <button class="monitor-chip-btn" data-window="1440" type="button">24 ساعة</button>
          </div>
        </div>
      </div>
      <div id="requestsChart" class="monitor-chart"></div>
    </section>
  </div>

  <div class="monitor-body-grid">
    <div class="monitor-side-stack">
      <section class="monitor-panel-shell">
        <div class="monitor-panel-header">
          <div class="monitor-panel-title"><i class="fas fa-database"></i><span>تفاصيل الكاش</span></div>
          <span class="monitor-section-tag" id="cacheStorageTag">الإجمالي: --</span>
        </div>
        <div id="cacheStatsList" class="monitor-system-grid"><div class="empty-state"><div class="monitor-loading"><i class="fas fa-circle-notch fa-spin"></i> جاري تحليل الكاش...</div></div></div>
      </section>

      <section class="monitor-panel-shell">
        <div class="monitor-panel-header">
          <div class="monitor-panel-title"><i class="fas fa-bell"></i><span>تنبيهات ذكية</span></div>
        </div>
        <div id="alertsGrid" class="monitor-alerts-grid"><div class="empty-state"><div class="monitor-loading"><i class="fas fa-circle-notch fa-spin"></i> جاري تحليل الحالة...</div></div></div>
      </section>

      <section class="monitor-panel-shell">
        <div class="monitor-panel-header">
          <div class="monitor-panel-title"><i class="fas fa-cogs"></i><span>الخدمات الحرجة</span></div>
        </div>
        <div id="servicesList" class="monitor-service-list"><div class="empty-state"><div class="monitor-loading"><i class="fas fa-circle-notch fa-spin"></i> جاري فحص الخدمات...</div></div></div>
      </section>
    </div>

    <div class="monitor-main-stack">
      <section class="monitor-panel-shell">
        <div class="monitor-panel-header">
          <div class="monitor-panel-title"><i class="fas fa-layer-group"></i><span>ملخص فوري</span></div>
          <span class="monitor-section-tag" id="monitorWindowBadge">آخر ساعة</span>
        </div>
        <div class="monitor-compact-grid">
          <div class="monitor-compact-card"><div class="monitor-compact-icon" style="background:rgba(59,130,246,.12);color:#2563eb;"><i class="fas fa-microchip"></i></div><div class="monitor-compact-info"><p>Load 1m</p><h4 id="metricLoadOne">--</h4><span class="monitor-compact-note" id="metricCpuMeta">المعالجات: --</span></div></div>
          <div class="monitor-compact-card"><div class="monitor-compact-icon" style="background:rgba(16,185,129,.12);color:#10b981;"><i class="fas fa-memory"></i></div><div class="monitor-compact-info"><p>استخدام الذاكرة</p><h4 id="metricMemoryUsage">--</h4><span class="monitor-compact-note" id="metricMemoryMeta">المتاح: --</span></div></div>
          <div class="monitor-compact-card"><div class="monitor-compact-icon" style="background:rgba(245,158,11,.12);color:#f59e0b;"><i class="fas fa-hard-drive"></i></div><div class="monitor-compact-info"><p>استخدام القرص</p><h4 id="metricDiskUsage">--</h4><span class="monitor-compact-note" id="metricDiskMeta">المتبقي: --</span></div></div>
          <div class="monitor-compact-card"><div class="monitor-compact-icon" style="background:rgba(99,102,241,.12);color:#6366f1;"><i class="fas fa-bolt"></i></div><div class="monitor-compact-info"><p>طلبات النافذة</p><h4 id="metricWindowRequests">--</h4><span class="monitor-compact-note" id="metricWindowMeta">متوسط/دقيقة: --</span></div></div>
          <div class="monitor-compact-card"><div class="monitor-compact-icon" style="background:rgba(14,165,233,.12);color:#0ea5e9;"><i class="fas fa-database"></i></div><div class="monitor-compact-info"><p>Cache Hit Ratio</p><h4 id="metricHitRatio">--</h4><span class="monitor-compact-note" id="metricCacheMeta">HIT: -- â€¢ MISS: --</span></div></div>
          <div class="monitor-compact-card"><div class="monitor-compact-icon" style="background:rgba(239,68,68,.16);color:#ef4444;"><i class="fas fa-triangle-exclamation"></i></div><div class="monitor-compact-info"><p>طلبات 429</p><h4 id="metric429">--</h4><span class="monitor-compact-note" id="metric429Meta">5xx: --</span></div></div>
        </div>
      </section>

      <section class="card monitor-card-clean">
        <div class="card-header"><div><h3 class="card-title">أكثر المفاتيح استهلاكًا</h3><div class="monitor-card-lead">ترتيب حي للمفاتيح الأعلى نشاطًا خلال النافذة الحالية.</div></div><span class="monitor-section-tag" id="topKeysTag">--</span></div>
        <div id="topKeysWrap"><div class="empty-state"><div class="monitor-loading"><i class="fas fa-circle-notch fa-spin"></i> جاري تحميل المفاتيح...</div></div></div>
      </section>

      <section class="card monitor-card-clean">
        <div class="card-header"><div><h3 class="card-title">أكثر إجراءات الـ API نشاطًا</h3><div class="monitor-card-lead">Bar Race ملوّن يعرض الإجراءات الأعلى استهلاكًا ويتبدل ترتيبها مع كل تحديث.</div></div><span class="monitor-section-tag" id="actionsTag">--</span></div>
        <div id="actionsRaceChart" class="monitor-chart monitor-chart-sm"></div>
      </section>
    </div>
  </div>

  <section class="card monitor-card-clean">
    <div class="card-header">
      <div>
        <h3 class="card-title">مؤشر محاولات الهجوم</h3>
        <div class="monitor-card-lead">يجمع إشارات مشبوهة من سجل الخادم مثل طلبات `429`، ومحاولات فحص المسارات الحساسة، والطلبات المحجوبة.</div>
      </div>
      <span class="monitor-section-tag" id="securityTag">--</span>
    </div>
    <div id="securityChart" class="monitor-chart monitor-chart-security"></div>
  </section>

  <section class="card monitor-card-clean">
    <div class="card-header">
      <div>
        <h3 class="card-title">أكثر الـ IPs كثافة</h3>
        <div class="monitor-card-lead">يمكنك تحديد عناوين IP المشبوهة من الجدول ثم حظرها مباشرة من الخادم، أو فلترة الجدول لإظهار المحظورين فقط.</div>
      </div>
      <span class="monitor-section-tag" id="attackIpsTag">--</span>
    </div>
    <div class="monitor-ips-toolbar">
      <div class="monitor-ips-filters">
        <div class="monitor-chip-group" id="blockedIpFilterButtons" style="background:transparent;border:none;padding:0;">
          <button class="monitor-chip-btn is-active" data-filter="all" type="button">الكل</button>
          <button class="monitor-chip-btn" data-filter="blocked" type="button">المحظورون فقط</button>
        </div>
      </div>
      <div class="monitor-ips-actions">
        <button class="monitor-action-btn" data-ip-action="unblock" type="button" onclick="unblockSelectedIps()"><i class="fas fa-unlock"></i><span>رفع الحظر</span></button>
        <button class="monitor-action-btn primary" data-ip-action="block" type="button" onclick="blockSelectedIps()"><i class="fas fa-ban"></i><span>حظر المحدد</span></button>
      </div>
    </div>
    <div id="attackIpsWrap" style="margin-top:16px;"><div class="empty-state"><div class="monitor-loading"><i class="fas fa-circle-notch fa-spin"></i> جاري تحليل عناوين IP...</div></div></div>
  </section>
</div>

<script>
const monitorState = {
  windowMinutes: 60,
  requestsChart: null,
  cacheChart: null,
  actionsChart: null,
  securityChart: null,
  refreshTimer: null,
  securityIpFilter: 'all',
  securityIpRows: [],
  blockedIpsTotal: 0,
  suspiciousIpsTotal: 0,
  selectedAttackIps: new Set(),
  isSubmittingIpAction: false
};

const fmtNum = (value) => Number(value || 0).toLocaleString('en-US');
const fmtBytes = (value) => {
  let numeric = Number(value || 0);
  if (!numeric) return '--';
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  let index = 0;
  while (numeric >= 1024 && index < units.length - 1) {
    numeric /= 1024;
    index += 1;
  }
  return `${numeric.toFixed(numeric >= 100 || index === 0 ? 0 : 1)} ${units[index]}`;
};
const fmtPct = (value) => {
  const numeric = Number(value);
  return Number.isFinite(numeric) ? `${numeric.toFixed(numeric >= 100 ? 0 : 1)}%` : '--';
};
const fmtDate = (value) => {
  if (!value) return '--';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString('ar-MA');
};
const escapeHtml = (value) => String(value ?? '')
  .replace(/&/g, '&amp;')
  .replace(/</g, '&lt;')
  .replace(/>/g, '&gt;')
  .replace(/"/g, '&quot;')
  .replace(/'/g, '&#39;');
const windowLabel = (minutes) => {
  if (minutes === 15) return 'آخر 15 دقيقة';
  if (minutes === 60) return 'آخر ساعة';
  if (minutes === 1440) return 'آخر 24 ساعة';
  return `آخر ${minutes} دقيقة`;
};
const endpoint = () => `ajax_monitor_stats.php?window=${monitorState.windowMinutes}`;

function exportMonitor(format) {
  window.location.href = `monitor_export.php?format=${encodeURIComponent(format)}&window=${monitorState.windowMinutes}`;
}

function refreshMonitor() {
  loadMonitorData(true);
}

function setActiveWindow(minutes) {
  monitorState.windowMinutes = minutes;
  document.querySelectorAll('#monitorWindowButtons .monitor-chip-btn').forEach((button) => {
    button.classList.toggle('is-active', Number(button.dataset.window) === minutes);
  });
  document.getElementById('monitorWindowBadge').textContent = windowLabel(minutes);
}

function ensureCharts() {
  if (!monitorState.requestsChart) monitorState.requestsChart = echarts.init(document.getElementById('requestsChart'));
  if (!monitorState.cacheChart) monitorState.cacheChart = echarts.init(document.getElementById('cacheChart'));
  if (!monitorState.actionsChart) monitorState.actionsChart = echarts.init(document.getElementById('actionsRaceChart'));
  if (!monitorState.securityChart) monitorState.securityChart = echarts.init(document.getElementById('securityChart'));
}

function renderSystemMeta(system) {
  const items = [
    ['اسم الخادم', system.hostname || '--'],
    ['النظام', system.os || '--'],
    ['إصدار PHP', system.php_version || '--'],
    ['إصدار Nginx', system.nginx_version || '--'],
    ['إصدار قاعدة البيانات', system.database_version || '--'],
    ['وقت الخادم UTC', system.server_time_utc || '--'],
    ['عدد الأنوية', system.cpu_cores || '--'],
    ['Uptime', system.uptime_human || '--']
  ];
  document.getElementById('systemMetaGrid').innerHTML = items
    .map(([label, value]) => `<div class="monitor-system-card"><div class="meta-label">${label}</div><div class="meta-value">${escapeHtml(value)}</div></div>`)
    .join('');
}

function renderServices(services) {
  const wrap = document.getElementById('servicesList');
  if (!Array.isArray(services) || !services.length) {
    wrap.innerHTML = '<div class="empty-state"><i class="fas fa-server empty-icon"></i><p>لا توجد بيانات خدمات حالية</p></div>';
    return;
  }
  wrap.innerHTML = services.map((service) => `
    <div class="monitor-service-card ${service.is_active ? 'is-active' : ''}" data-service-card="${escapeHtml(service.service)}">
      <div class="monitor-service-icon theme-${escapeHtml(service.theme || 'blue')}">
        <i class="fas ${escapeHtml(service.icon || 'fa-server')}"></i>
      </div>
      <div class="monitor-service-content">
        <div class="monitor-service-name">${escapeHtml(service.label)}</div>
        <div class="monitor-service-state ${service.is_active ? 'active' : 'inactive'}">${escapeHtml(service.status_label || service.status || '--')}</div>
      </div>
      <label class="monitor-switch" title="${service.toggleable ? 'تشغيل أو إيقاف الخدمة' : 'الخدمة الأساسية محمية'}">
        <input type="checkbox" ${service.is_active ? 'checked' : ''} ${service.toggleable ? '' : 'disabled'} data-service="${escapeHtml(service.service)}" onchange="toggleServiceState(this)">
        <span class="monitor-switch-slider"></span>
      </label>
    </div>
  `).join('');
}

async function toggleServiceState(input) {
  const service = input.dataset.service || '';
  if (!service) return;
  const desired = input.checked ? 1 : 0;
  const revertChecked = !input.checked;
  const card = input.closest('[data-service-card]');
  if (!desired && !window.confirm('سيتم إيقاف هذه الخدمة الخلفية. هل تريد المتابعة؟')) {
    input.checked = true;
    return;
  }
  if (card) card.classList.add('is-busy');
  input.disabled = true;
  try {
    const body = new URLSearchParams({ service, desired_active: String(desired) });
    const response = await fetch('ajax_monitor_service.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json'
      },
      body: body.toString()
    });
    const payload = await response.json();
    if (!response.ok || payload.status !== 'success') throw new Error(payload.message || 'تعذر تحديث الخدمة');
    await loadMonitorData(true);
  } catch (error) {
    console.error('Service toggle error:', error);
    input.checked = revertChecked;
    alert(error.message || 'تعذر تحديث الخدمة الآن.');
  } finally {
    input.disabled = false;
    if (card) card.classList.remove('is-busy');
  }
}
function renderTopKeys(keys) {
  document.getElementById('topKeysTag').textContent = `${fmtNum((keys || []).length)} مفاتيح`;
  document.getElementById('topKeysWrap').innerHTML = Array.isArray(keys) && keys.length ? `
    <div style="overflow:auto;">
      <table class="top-keys-table">
        <thead>
          <tr>
            <th>#</th>
            <th>المفتاح</th>
            <th>آخر 24 ساعة</th>
            <th>آخر طلب</th>
            <th>النطاق / الخطة</th>
          </tr>
        </thead>
        <tbody>
          ${keys.map((key, index) => `
            <tr>
              <td>${index + 1}</td>
              <td><div class="key-name">${escapeHtml(key.name)}</div><div class="key-sub">${escapeHtml(key.masked_key)}</div></td>
              <td>${fmtNum(key.requests_24h)}</td>
              <td>${escapeHtml(fmtDate(key.last_request_at))}</td>
              <td><div class="key-sub">${escapeHtml(key.origin_label || '--')}</div></td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    </div>
  ` : '<div class="empty-state"><i class="fas fa-key empty-icon"></i><p>لا توجد مفاتيح نشطة كافية بعد</p></div>';
}

function renderCacheStats(cache, api) {
  const counts = cache.status_counts || {};
  const totalSize = Number(cache.nginx_microcache?.size_bytes || 0) + Number(cache.php_response_cache?.size_bytes || 0);
  document.getElementById('cacheStorageTag').textContent = `الإجمالي: ${fmtBytes(totalSize)}`;
  const items = [
    ['Cache HIT', fmtNum(counts.HIT || 0)],
    ['Cache MISS', fmtNum(counts.MISS || 0)],
    ['طلبات 429', fmtNum(api.rate_limited || 0)],
    ['ملفات Microcache', fmtNum(cache.nginx_microcache?.files || 0)],
    ['ملفات PHP Cache', fmtNum(cache.php_response_cache?.files || 0)],
    ['حجم Microcache', fmtBytes(cache.nginx_microcache?.size_bytes || 0)]
  ];
  document.getElementById('cacheStatsList').innerHTML = items
    .map(([label, value]) => `<div class="monitor-system-card"><div class="meta-label">${label}</div><div class="meta-value">${escapeHtml(value)}</div></div>`)
    .join('');
}

function buildAlerts(data) {
  const alerts = [];
  const system = data.system || {};
  const api = data.api || {};
  const cache = data.cache || {};
  const memory = system.memory || {};
  const disk = system.disk || {};
  const loadAverage = system.load_average || {};
  const cores = Number(system.cpu_cores || 0);
  const load = Number(loadAverage.one || 0);
  const loadRatio = cores > 0 ? load / cores : 0;
  const rateLimited = Number(api.rate_limited || 0);
  const totalRequests = Number(api.total_requests || 0);

  if (loadRatio >= 1) alerts.push({ type: 'danger', badge: 'حرج', title: 'الحمل مرتفع على المعالجات', body: `Load 1m = ${load.toFixed(2)} مقارنة بـ ${cores} نواة.` });
  else if (loadRatio >= 0.75) alerts.push({ type: 'warning', badge: 'تحذير', title: 'الحمل يقترب من الحد المريح', body: `Load 1m = ${load.toFixed(2)} وهو قريب من السقف الحالي.` });

  if (Number(memory.used_percent || 0) >= 85) alerts.push({ type: 'danger', badge: 'حرج', title: 'استهلاك الذاكرة مرتفع', body: `الذاكرة المستخدمة وصلت إلى ${fmtPct(memory.used_percent)}.` });
  else if (Number(memory.used_percent || 0) >= 70) alerts.push({ type: 'warning', badge: 'تحذير', title: 'استهلاك الذاكرة ملحوظ', body: `الاستهلاك الحالي ${fmtPct(memory.used_percent)}.` });

  if (Number(disk.used_percent || 0) >= 90) alerts.push({ type: 'danger', badge: 'حرج', title: 'المساحة الحرة منخفضة جدًا', body: `استخدام القرص وصل إلى ${fmtPct(disk.used_percent)}.` });
  else if (Number(disk.used_percent || 0) >= 80) alerts.push({ type: 'warning', badge: 'تحذير', title: 'المساحة الحرة بدأت تنخفض', body: `القرص مستخدم بنسبة ${fmtPct(disk.used_percent)}.` });

  if (Number(api.server_errors || 0) > 0) alerts.push({ type: 'danger', badge: 'حرج', title: 'تم رصد أخطاء 5xx', body: `تم تسجيل ${fmtNum(api.server_errors || 0)} أخطاء خادمية.` });
  if (rateLimited >= 50 || (totalRequests > 0 && (rateLimited / totalRequests) >= 0.1)) alerts.push({ type: 'warning', badge: 'تنبيه', title: 'هناك ضغط مقصوص بواسطة Rate Limit', body: `تم قص ${fmtNum(rateLimited)} طلبات بـ 429 داخل ${windowLabel(monitorState.windowMinutes)}.` });
  if (Number(cache.hit_ratio || 0) < 65 && totalRequests > 30) alerts.push({ type: 'warning', badge: 'ملاحظة', title: 'نسبة الكاش أقل من المتوقع', body: `Hit Ratio الحالية ${fmtPct(cache.hit_ratio)}.` });
  if (!alerts.length) alerts.push({ type: 'ok', badge: 'جيد', title: 'الوضع الحالي مستقر', body: 'لا توجد مؤشرات ضغط أو أخطاء حرجة في النافذة الحالية.' });
  return alerts;
}

function renderAlerts(data) {
  const alerts = buildAlerts(data);
  document.getElementById('alertsGrid').innerHTML = alerts.map((alertItem) => `
    <div class="monitor-alert ${alertItem.type}">
      <div class="monitor-alert-head">
        <div class="monitor-alert-title">${escapeHtml(alertItem.title)}</div>
        <span class="monitor-alert-badge">${escapeHtml(alertItem.badge)}</span>
      </div>
      <div class="monitor-alert-body">${escapeHtml(alertItem.body)}</div>
    </div>
  `).join('');
}

function updateActionsChart(actions) {
  ensureCharts();
  const palette = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#14b8a6', '#ec4899', '#6366f1'];
  const sorted = (actions || []).slice().sort((a, b) => a.count - b.count);
  document.getElementById('actionsTag').textContent = `${fmtNum(sorted.length)} إجراءات`;
  if (!sorted.length) {
    if (monitorState.actionsChart) {
      monitorState.actionsChart.dispose();
      monitorState.actionsChart = null;
    }
    document.getElementById('actionsRaceChart').innerHTML = '<div class="empty-state" style="height:100%"><i class="fas fa-chart-bar empty-icon"></i><p>لا توجد بيانات كافية بعد</p></div>';
    return;
  }
  monitorState.actionsChart = echarts.getInstanceByDom(document.getElementById('actionsRaceChart')) || echarts.init(document.getElementById('actionsRaceChart'));
  monitorState.actionsChart.setOption({
    textStyle: { fontFamily: 'Outfit, Inter, sans-serif' },
    animationDuration: 300,
    animationDurationUpdate: 700,
    animationEasingUpdate: 'linear',
    grid: { top: 12, left: 16, right: 32, bottom: 8, containLabel: true },
    tooltip: { trigger: 'item', formatter: (params) => `${params.name}<br>${fmtNum(params.value)} طلب` },
    xAxis: { type: 'value', splitLine: { lineStyle: { color: 'rgba(148,163,184,0.12)' } }, axisLabel: { color: '#64748b' } },
    yAxis: { type: 'category', inverse: true, axisTick: { show: false }, axisLine: { show: false }, axisLabel: { color: '#334155', fontWeight: 700 }, data: sorted.map((item) => item.action) },
    series: [{
      type: 'bar',
      barMaxWidth: 36,
      realtimeSort: true,
      data: sorted.map((item, index) => ({ value: item.count, name: item.action, itemStyle: { color: palette[index % palette.length], borderRadius: [2, 9, 9, 2] } })),
      label: { show: true, position: 'right', color: '#1e293b', fontWeight: 700, formatter: (params) => fmtNum(params.value) }
    }]
  });
}
function updateSecurityChart(security) {
  ensureCharts();
  const series = security.series || [];
  document.getElementById('securityTag').textContent = `${fmtNum(security.total_signals || 0)} إشارة`;
  if (!series.length) {
    if (monitorState.securityChart) {
      monitorState.securityChart.dispose();
      monitorState.securityChart = null;
    }
    document.getElementById('securityChart').innerHTML = '<div class="empty-state" style="height:100%"><i class="fas fa-shield-halved empty-icon"></i><p>لا توجد مؤشرات هجومية ضمن النافذة الحالية</p></div>';
    return;
  }
  monitorState.securityChart = echarts.getInstanceByDom(document.getElementById('securityChart')) || echarts.init(document.getElementById('securityChart'));
  monitorState.securityChart.setOption({
    textStyle: { fontFamily: 'Outfit, Inter, sans-serif' },
    animationDuration: 350,
    grid: { left: 18, right: 18, top: 34, bottom: 18, containLabel: true },
    tooltip: { trigger: 'axis' },
    legend: { data: ['إجمالي الإشارات', 'مسارات حساسة', 'طلبات 429', 'طلبات محجوبة'], top: 0, itemGap: 18, textStyle: { color: '#64748b' } },
    xAxis: { type: 'category', data: series.map((item) => item.label), axisLine: { lineStyle: { color: '#e2e8f0' } }, axisLabel: { color: '#64748b' } },
    yAxis: { type: 'value', splitLine: { lineStyle: { color: 'rgba(148,163,184,0.12)' } }, axisLabel: { color: '#64748b' } },
    series: [
      { name: 'إجمالي الإشارات', type: 'line', smooth: true, data: series.map((item) => item.total), lineStyle: { color: '#ef4444', width: 3 }, itemStyle: { color: '#ef4444' }, areaStyle: { color: 'rgba(239,68,68,0.12)' } },
      { name: 'مسارات حساسة', type: 'bar', barMaxWidth: 16, data: series.map((item) => item.probes), itemStyle: { color: '#f59e0b', borderRadius: [4, 4, 0, 0] } },
      { name: 'طلبات 429', type: 'bar', barMaxWidth: 16, data: series.map((item) => item.rate_limited), itemStyle: { color: '#8b5cf6', borderRadius: [4, 4, 0, 0] } },
      { name: 'طلبات محجوبة', type: 'bar', barMaxWidth: 16, data: series.map((item) => item.blocked), itemStyle: { color: '#0ea5e9', borderRadius: [4, 4, 0, 0] } }
    ]
  });
}

function updateCharts(api, cache, security) {
  ensureCharts();
  const series = api.series || [];
  const counts = cache.status_counts || {};
  monitorState.requestsChart.setOption({
    textStyle: { fontFamily: 'Outfit, Inter, sans-serif' },
    animationDuration: 350,
    grid: { left: 18, right: 18, top: 32, bottom: 18, containLabel: true },
    tooltip: { trigger: 'axis' },
    legend: { data: ['إجمالي الطلبات', 'طلبات 429'], top: 0, itemGap: 24, textStyle: { color: '#64748b' } },
    xAxis: { type: 'category', data: series.map((item) => item.label), axisLine: { lineStyle: { color: '#e2e8f0' } }, axisLabel: { color: '#64748b' } },
    yAxis: { type: 'value', splitLine: { lineStyle: { color: 'rgba(148,163,184,0.12)' } }, axisLabel: { color: '#64748b' } },
    series: [
      { name: 'إجمالي الطلبات', type: 'line', smooth: true, data: series.map((item) => item.requests), lineStyle: { color: '#22c1c3', width: 3 }, itemStyle: { color: '#22c1c3' }, areaStyle: { color: 'rgba(34,193,195,0.18)' } },
      { name: 'طلبات 429', type: 'line', smooth: true, data: series.map((item) => item.rate_limited), lineStyle: { color: '#3b82f6', width: 2 }, itemStyle: { color: '#3b82f6' } }
    ]
  });
  monitorState.cacheChart.setOption({
    textStyle: { fontFamily: 'Outfit, Inter, sans-serif' },
    animationDuration: 300,
    tooltip: { trigger: 'item' },
    legend: { bottom: 0, textStyle: { color: '#64748b' } },
    series: [{
      type: 'pie',
      radius: ['32%', '72%'],
      center: ['50%', '43%'],
      roseType: 'area',
      label: { color: '#334155', formatter: '{b}\n{c}' },
      data: [
        { value: counts.HIT || 0, name: 'HIT', itemStyle: { color: '#3b82f6' } },
        { value: counts.MISS || 0, name: 'MISS', itemStyle: { color: '#10b981' } },
        { value: counts.BYPASS || 0, name: 'BYPASS', itemStyle: { color: '#f59e0b' } },
        { value: counts.NONE || 0, name: 'NONE', itemStyle: { color: '#8b5cf6' } }
      ]
    }]
  });
  updateActionsChart(api.top_actions || []);
  updateSecurityChart(security || {});
}

function updateHighlights(data) {
  const system = data.system || {};
  const api = data.api || {};
  const cache = data.cache || {};
  const memory = system.memory || {};
  const disk = system.disk || {};
  const loadAverage = system.load_average || {};
  const counts = cache.status_counts || {};
  document.getElementById('monitorGeneratedAt').textContent = `آخر تحديث: ${fmtDate(data.generated_at)}`;
  document.getElementById('metricLoadOne').textContent = loadAverage.one ?? '--';
  document.getElementById('metricCpuMeta').textContent = `المعالجات: ${system.cpu_cores || '--'} • 5m: ${loadAverage.five ?? '--'}`;
  document.getElementById('metricMemoryUsage').textContent = fmtPct(memory.used_percent);
  document.getElementById('metricMemoryMeta').textContent = `المتاح: ${fmtBytes(memory.available_bytes)} / الإجمالي: ${fmtBytes(memory.total_bytes)}`;
  document.getElementById('metricDiskUsage').textContent = fmtPct(disk.used_percent);
  document.getElementById('metricDiskMeta').textContent = `المتبقي: ${fmtBytes(disk.free_bytes)} / الإجمالي: ${fmtBytes(disk.total_bytes)}`;
  document.getElementById('metricWindowRequests').textContent = fmtNum(api.total_requests || 0);
  document.getElementById('metricWindowMeta').textContent = `متوسط/دقيقة: ${api.requests_per_minute_avg || 0} • الذروة: ${fmtNum(api.requests_per_minute_peak || 0)}`;
  document.getElementById('metricHitRatio').textContent = fmtPct(cache.hit_ratio);
  document.getElementById('metricCacheMeta').textContent = `HIT: ${fmtNum(counts.HIT || 0)} • MISS: ${fmtNum(counts.MISS || 0)}`;
  document.getElementById('metric429').textContent = fmtNum(api.rate_limited || 0);
  document.getElementById('metric429Meta').textContent = `5xx: ${fmtNum(api.server_errors || 0)} • زمن الطلب: ${api.avg_request_time_ms || 0}ms`;
  document.getElementById('cacheHitPill').textContent = `Hit Ratio ${fmtPct(cache.hit_ratio)}`;
}

function setAttackIpFilter(filter) {
  monitorState.securityIpFilter = filter === 'blocked' ? 'blocked' : 'all';
  document.querySelectorAll('#blockedIpFilterButtons .monitor-chip-btn').forEach((button) => {
    button.classList.toggle('is-active', button.dataset.filter === monitorState.securityIpFilter);
  });
  renderAttackIpsTable();
}

function getVisibleAttackIps() {
  return monitorState.securityIpFilter === 'blocked'
    ? monitorState.securityIpRows.filter((row) => Boolean(row.is_blocked))
    : monitorState.securityIpRows.slice();
}

function toggleAttackIpSelection(ip, checked) {
  if (!ip) return;
  if (checked) monitorState.selectedAttackIps.add(ip);
  else monitorState.selectedAttackIps.delete(ip);
  renderAttackIpsTable();
}

function toggleAllAttackIps(checked) {
  getVisibleAttackIps().forEach((row) => {
    if (checked) monitorState.selectedAttackIps.add(row.ip);
    else monitorState.selectedAttackIps.delete(row.ip);
  });
  renderAttackIpsTable();
}

function updateAttackIpActionButtons() {
  const selectedRows = monitorState.securityIpRows.filter((row) => monitorState.selectedAttackIps.has(row.ip));
  const hasSelection = selectedRows.length > 0;
  const hasBlockedSelection = selectedRows.some((row) => Boolean(row.is_blocked));
  const hasUnblockedSelection = selectedRows.some((row) => !row.is_blocked);
  document.querySelectorAll('.monitor-ips-actions .monitor-action-btn').forEach((button) => {
    const isBlockButton = button.dataset.ipAction === 'block';
    const enabled = isBlockButton ? hasUnblockedSelection : hasBlockedSelection;
    button.disabled = monitorState.isSubmittingIpAction || !hasSelection || !enabled;
  });
}

function renderAttackIpsTable(security = null) {
  if (security) {
    const rows = Array.isArray(security.top_ips) ? security.top_ips : [];
    monitorState.securityIpRows = rows.map((row) => ({
      ip: String(row.ip || '').trim(),
      requests_total: Number(row.requests_total || 0),
      count: Number(row.count || 0),
      rate_limited: Number(row.rate_limited || 0),
      probes: Number(row.probes || 0),
      blocked_hits: Number(row.blocked_hits || 0),
      last_seen: row.last_seen || null,
      last_target: row.last_target || null,
      is_blocked: Boolean(row.is_blocked),
      blocked_at: row.blocked_at || null
    })).filter((row) => row.ip !== '');
    monitorState.blockedIpsTotal = Number(security.blocked_ips_total || 0);
    monitorState.suspiciousIpsTotal = Number(security.suspicious_total || monitorState.securityIpRows.length || 0);
    const knownIps = new Set(monitorState.securityIpRows.map((row) => row.ip));
    Array.from(monitorState.selectedAttackIps).forEach((ip) => {
      if (!knownIps.has(ip)) monitorState.selectedAttackIps.delete(ip);
    });
  }

  const rows = getVisibleAttackIps();
  const wrap = document.getElementById('attackIpsWrap');
  const selectedVisibleCount = rows.filter((row) => monitorState.selectedAttackIps.has(row.ip)).length;
  const allVisibleSelected = rows.length > 0 && selectedVisibleCount === rows.length;
  const totalRequests = rows.reduce((sum, row) => sum + Number(row.requests_total || 0), 0);
  document.getElementById('attackIpsTag').textContent = `المشبوهة: ${fmtNum(monitorState.suspiciousIpsTotal)} • المعروض: ${fmtNum(rows.length)} • المحظورون: ${fmtNum(monitorState.blockedIpsTotal)} • الطلبات: ${fmtNum(totalRequests)}`;
  updateAttackIpActionButtons();

  if (!rows.length) {
    wrap.innerHTML = `
      <div class="empty-state">
        <i class="fas fa-shield-halved empty-icon"></i>
        <p>${monitorState.securityIpFilter === 'blocked' ? 'لا توجد عناوين IP محظورة ضمن القائمة المشبوهة حاليًا.' : 'لا توجد عناوين IP مشبوهة كفاية ضمن النافذة الحالية.'}</p>
      </div>
    `;
    return;
  }

  wrap.innerHTML = `
    <div class="monitor-ips-table-wrap">
      <table class="monitor-ips-table">
        <thead>
          <tr>
            <th style="width:42px;"><input class="monitor-ip-checkbox" type="checkbox" ${allVisibleSelected ? 'checked' : ''} onclick="toggleAllAttackIps(this.checked)"></th>
            <th>عنوان IP</th>
            <th>عدد الطلبات</th>
            <th>إشارات الهجوم</th>
            <th>429</th>
            <th>فحوصات</th>
            <th>طلبات محجوبة</th>
            <th>آخر نشاط</th>
            <th>آخر ظهور</th>
            <th>الحالة</th>
          </tr>
        </thead>
        <tbody>
          ${rows.map((row) => {
            const isSelected = monitorState.selectedAttackIps.has(row.ip);
            const statusBadge = row.is_blocked
              ? '<span class="monitor-ip-badge blocked"><i class="fas fa-ban"></i><span>محظور</span></span>'
              : '<span class="monitor-ip-badge active"><i class="fas fa-wave-square"></i><span>نشط</span></span>';
            const rateBadge = row.rate_limited > 0
              ? `<span class="monitor-ip-badge rate"><i class="fas fa-gauge-high"></i><span>429: ${fmtNum(row.rate_limited)}</span></span>`
              : '';
            const activityLabel = row.last_target || '--';
            return `
              <tr>
                <td><input class="monitor-ip-checkbox" type="checkbox" ${isSelected ? 'checked' : ''} onclick="toggleAttackIpSelection('${row.ip}', this.checked)"></td>
                <td><div class="monitor-ip-main"><a class="monitor-ip-link" href="monitor_ip_map.php?ip=${encodeURIComponent(row.ip)}" target="_blank" rel="noopener noreferrer">${escapeHtml(row.ip)}</a><div class="monitor-ip-meta">${row.blocked_at ? `حُظر في ${escapeHtml(fmtDate(row.blocked_at))}` : 'لا يوجد حظر بعد'}</div></div></td>
                <td>${fmtNum(row.requests_total)}</td>
                <td>${fmtNum(row.count)}</td>
                <td>${fmtNum(row.rate_limited)}</td>
                <td>${fmtNum(row.probes)}</td>
                <td>${fmtNum(row.blocked_hits)}</td>
                <td><span class="monitor-ip-target" title="${escapeHtml(activityLabel)}">${escapeHtml(activityLabel)}</span></td>
                <td>${escapeHtml(fmtDate(row.last_seen))}</td>
                <td><div style="display:flex;gap:6px;flex-wrap:wrap;">${statusBadge}${rateBadge}</div></td>
              </tr>
            `;
          }).join('')}
        </tbody>
      </table>
    </div>
  `;
}

async function submitIpBlockAction(action) {
  if (monitorState.isSubmittingIpAction) return;
  const selectedRows = monitorState.securityIpRows.filter((row) => monitorState.selectedAttackIps.has(row.ip));
  if (!selectedRows.length) {
    alert('حدد عنوان IP واحدًا على الأقل أولًا.');
    return;
  }
  const targetRows = action === 'block' ? selectedRows.filter((row) => !row.is_blocked) : selectedRows.filter((row) => row.is_blocked);
  if (!targetRows.length) {
    alert(action === 'block' ? 'كل العناوين المحددة محظورة بالفعل.' : 'كل العناوين المحددة غير محظورة أصلًا.');
    return;
  }
  const ips = targetRows.map((row) => row.ip);
  const confirmationMessage = action === 'block'
    ? `سيتم حظر ${ips.length} عنوان IP من الخادم مباشرة. هل تريد المتابعة؟`
    : `سيتم رفع الحظر عن ${ips.length} عنوان IP. هل تريد المتابعة؟`;
  if (!window.confirm(confirmationMessage)) return;

  monitorState.isSubmittingIpAction = true;
  updateAttackIpActionButtons();
  try {
    const body = new URLSearchParams();
    body.append('action', action);
    ips.forEach((ip) => body.append('ips[]', ip));
    const response = await fetch('ajax_monitor_ip_block.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json'
      },
      body: body.toString()
    });
    const payload = await response.json();
    if (!response.ok || payload.status !== 'success') throw new Error(payload.message || 'تعذر تحديث قائمة الحظر.');
    ips.forEach((ip) => monitorState.selectedAttackIps.delete(ip));
    await loadMonitorData(true);
    alert(payload.message || 'تم تحديث قائمة الحظر بنجاح.');
  } catch (error) {
    console.error('IP block action error:', error);
    alert(error.message || 'تعذر تنفيذ العملية الآن.');
  } finally {
    monitorState.isSubmittingIpAction = false;
    updateAttackIpActionButtons();
  }
}

function blockSelectedIps() {
  submitIpBlockAction('block');
}

function unblockSelectedIps() {
  submitIpBlockAction('unblock');
}

async function loadMonitorData(forceFresh = false) {
  try {
    const response = await fetch(`${endpoint()}&_=${forceFresh ? Date.now() : ''}`, {
      headers: { Accept: 'application/json' },
      cache: 'no-store'
    });
    const payload = await response.json();
    if (!response.ok || payload.status !== 'success') throw new Error(payload.message || 'فشل تحميل البيانات');
    const data = payload.data || {};
    updateHighlights(data);
    renderAlerts(data);
    renderSystemMeta(data.system || {});
    renderServices(data.services || []);
    renderTopKeys(data.top_keys || []);
    renderCacheStats(data.cache || {}, data.api || {});
    updateCharts(data.api || {}, data.cache || {}, data.security || {});
    renderAttackIpsTable(data.security || {});
  } catch (error) {
    console.error('Monitor load error:', error);
    document.getElementById('monitorGeneratedAt').textContent = 'تعذر تحميل البيانات الآن';
  }
}

document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('#monitorWindowButtons .monitor-chip-btn').forEach((button) => {
    button.addEventListener('click', () => {
      const nextWindow = Number(button.dataset.window || 60);
      if (nextWindow === monitorState.windowMinutes) return;
      setActiveWindow(nextWindow);
      loadMonitorData(true);
    });
  });

  document.querySelectorAll('#blockedIpFilterButtons .monitor-chip-btn').forEach((button) => {
    button.addEventListener('click', () => setAttackIpFilter(button.dataset.filter || 'all'));
  });

  setActiveWindow(monitorState.windowMinutes);
  setAttackIpFilter(monitorState.securityIpFilter);
  loadMonitorData(true);
  if (window.AdminAutoRefresh && typeof window.AdminAutoRefresh.register === 'function') {
    monitorState.refreshTimer = window.AdminAutoRefresh.register('admin-monitor-dashboard', () => loadMonitorData(false), {
      interval: 15000,
      runImmediately: false,
      refreshOnVisible: true
    });
  } else {
    monitorState.refreshTimer = setInterval(() => loadMonitorData(false), 15000);
  }

  window.addEventListener('resize', () => {
    if (monitorState.requestsChart) monitorState.requestsChart.resize();
    if (monitorState.cacheChart) monitorState.cacheChart.resize();
    if (monitorState.actionsChart) monitorState.actionsChart.resize();
    if (monitorState.securityChart) monitorState.securityChart.resize();
  });
});
</script>
<?php require_once 'includes/footer.php'; ?>

