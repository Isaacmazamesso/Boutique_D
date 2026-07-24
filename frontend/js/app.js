// ── XSS-safe HTML escaping ────────────────────────────────────────────────────
function escHtml(str) {
  const el = document.createElement('span');
  el.textContent = String(str ?? '');
  return el.innerHTML;
}

// ── Lucide icons ──────────────────────────────────────────────────────────────
function refreshIcons() {
  if (window.lucide) window.lucide.createIcons();
}

// ── Skeleton loader pour tableaux ────────────────────────────────────────────
function showTableSkeleton(tbodyId, rows = 4) {
  const tbody = document.getElementById(tbodyId);
  if (!tbody) return;
  const cols = tbody.closest('table')?.querySelectorAll('th').length || 4;
  const widths = ['70%', '45%', '85%', '55%', '65%', '40%'];
  let html = '';
  for (let r = 0; r < rows; r++) {
    html += '<tr class="skeleton-row">';
    for (let c = 0; c < cols; c++) {
      html += `<td><div class="skeleton-line" style="width:${widths[(r + c) % widths.length]}"></div></td>`;
    }
    html += '</tr>';
  }
  tbody.innerHTML = html;
}

// ── Sparkline SVG ────────────────────────────────────────────────────────────
function sparklineSvg(values, opts = {}) {
  if (!Array.isArray(values) || values.length < 2) return '';
  const w = opts.width ?? 140, h = opts.height ?? 38, stroke = opts.stroke ?? 'var(--accent)';
  const max = Math.max(...values), min = Math.min(...values);
  const range = max - min || 1;
  const pts = values.map((v, i) => [
    (i / (values.length - 1)) * w,
    h - 3 - ((v - min) / range) * (h - 6),
  ]);
  const line = pts.map(p => `${p[0].toFixed(1)},${p[1].toFixed(1)}`).join(' ');
  const area = `0,${h} ${line} ${w},${h}`;
  return `<svg width="${w}" height="${h}" viewBox="0 0 ${w} ${h}" aria-hidden="true">
    <defs><linearGradient id="sparkfill" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0%" stop-color="#2563EB" stop-opacity=".18"/>
      <stop offset="100%" stop-color="#2563EB" stop-opacity="0"/>
    </linearGradient></defs>
    <polygon points="${area}" fill="url(#sparkfill)"/>
    <polyline points="${line}" fill="none" stroke="${stroke}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
  </svg>`;
}

// ── Auth guard ───────────────────────────────────────────────────────────────
function requireAuth() {
  const token = localStorage.getItem('token');
  if (!token) { window.location.href = 'login.html'; return null; }
  return JSON.parse(localStorage.getItem('user') || '{}');
}

function currentUser() {
  return JSON.parse(localStorage.getItem('user') || '{}');
}

function hasRole(...roles) {
  const user = currentUser();
  return roles.includes(user.role);
}

// ── Topbar & sidebar ─────────────────────────────────────────────────────────
function initLayout() {
  const user = currentUser();

  // Populate topbar user info (textContent pour éviter XSS)
  const el = document.getElementById('topbar-user');
  if (el) {
    el.textContent = '';
    const nameSpan = document.createElement('span');
    nameSpan.textContent = user.name || '';
    const roleBadge = document.createElement('small');
    roleBadge.className = 'badge badge-success';
    roleBadge.textContent = user.role || '';
    el.appendChild(nameSpan);
    el.appendChild(roleBadge);
  }

  // Logout button
  const logoutBtn = document.getElementById('btn-logout');
  if (logoutBtn) logoutBtn.addEventListener('click', logout);

  // Mobile sidebar toggle
  const menuBtn = document.getElementById('menu-btn');
  const sidebar = document.querySelector('.sidebar');
  const overlay = document.querySelector('.sidebar-overlay');

  if (menuBtn && sidebar) {
    menuBtn.addEventListener('click', () => {
      sidebar.classList.toggle('open');
      overlay?.classList.toggle('show');
    });
    overlay?.addEventListener('click', () => {
      sidebar.classList.remove('open');
      overlay.classList.remove('show');
    });
  }

  // Active nav item
  const currentPage = location.pathname.split('/').pop();
  document.querySelectorAll('.nav-item[href]').forEach(a => {
    if (a.getAttribute('href') === currentPage) a.classList.add('active');
  });

  // Hide items by role
  document.querySelectorAll('[data-role]').forEach(el => {
    const allowed = el.dataset.role.split(',');
    if (!hasRole(...allowed)) el.style.display = 'none';
  });

  // Load alert count in sidebar badge
  refreshAlertBadge();

  refreshIcons();
}

async function refreshAlertBadge() {
  try {
    if (!hasRole('proprietaire', 'gestionnaire')) return;
    const data = await api.get('/stock/alerts');
    const badge = document.getElementById('alert-badge');
    if (badge && data.count > 0) {
      badge.textContent = data.count;
      badge.classList.remove('hidden');
    }
  } catch { /* silent */ }
}

function logout() {
  api.post('/auth/logout').catch(() => {});
  localStorage.clear();
  window.location.href = 'login.html';
}

// ── Toast notifications ───────────────────────────────────────────────────────
function toast(msg, type = 'success', duration = 3500) {
  const icons = {
    success: '<i data-lucide="circle-check-big" class="icon"></i>',
    danger:  '<i data-lucide="circle-x" class="icon"></i>',
    warning: '<i data-lucide="triangle-alert" class="icon"></i>',
  };
  const container = document.getElementById('toast-container');
  if (!container) return;

  const t = document.createElement('div');
  t.className = `toast ${type}`;
  const iconSpan = document.createElement('span');
  iconSpan.innerHTML = icons[type] || '<i data-lucide="info" class="icon"></i>';
  const msgSpan = document.createElement('span');
  msgSpan.textContent = msg;
  t.appendChild(iconSpan);
  t.appendChild(msgSpan);
  container.appendChild(t);
  refreshIcons();

  setTimeout(() => {
    t.style.opacity = '0';
    t.style.transform = 'translateX(20px)';
    t.style.transition = 'all .3s';
    setTimeout(() => t.remove(), 300);
  }, duration);
}

// ── Modal helpers ─────────────────────────────────────────────────────────────
function openModal(id) {
  const m = document.getElementById(id);
  if (!m) return;
  m.classList.add('show');
  m.querySelector('.modal')?.focus?.();
}
function closeModal(id) {
  document.getElementById(id)?.classList.remove('show');
}
function initModals() {
  document.querySelectorAll('.modal-close, [data-dismiss]').forEach(btn => {
    btn.addEventListener('click', () => {
      btn.closest('.modal-backdrop')?.classList.remove('show');
    });
  });
  document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
    backdrop.addEventListener('click', e => {
      if (e.target === backdrop) backdrop.classList.remove('show');
    });
  });
}

// ── Formatters ────────────────────────────────────────────────────────────────
function fmt(amount) {
  return new Intl.NumberFormat('fr-FR').format(Math.round(amount)) + ' FCFA';
}
function fmtDate(str) {
  if (!str) return '—';
  return str; // Already formatted by backend
}

// ── Generic table renderer ────────────────────────────────────────────────────
function renderTable(tbodyId, rows, emptyMsg = 'Aucune donnée', emptyIcon = 'inbox') {
  const tbody = document.getElementById(tbodyId);
  if (!tbody) return;
  if (!rows || rows.length === 0) {
    const cols = tbody.closest('table')?.querySelectorAll('th').length || 4;
    tbody.innerHTML = `<tr><td colspan="${cols}" style="padding:0">
      <div class="empty-state">
        <div class="icon-wrap"><i data-lucide="${emptyIcon}" class="icon"></i></div>
        <h4>${emptyMsg}</h4>
      </div>
    </td></tr>`;
    refreshIcons();
    return;
  }
  tbody.innerHTML = rows.join('');
  refreshIcons();
}

// ── Confirm dialog ────────────────────────────────────────────────────────────
function confirm(msg) {
  return window.confirm(msg);
}

// Init on load
document.addEventListener('DOMContentLoaded', () => {
  initLayout();
  initModals();
});
