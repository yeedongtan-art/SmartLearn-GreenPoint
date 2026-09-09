/**
 * SmartLearn Teacher Portal – shared UI (layout, dark mode, notifications, toast, logout)
 */
(function () {
  'use strict';

  const STORAGE_THEME = 'smartlearn-theme';
  const STORAGE_NOTIFICATIONS = 'smartlearn-notifications-read';

  const ICONS = {
    dashboard: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><rect x="14" y="14" width="6" height="6" rx="1"/></svg>',
    createQuiz: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14"/><path d="M5 12h14"/></svg>',
    manageQuiz: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 9h.01"/><path d="M9 15h.01"/><path d="M12 3h4.5A2.5 2.5 0 0 1 19 5.5v13A2.5 2.5 0 0 1 16.5 21h-9A2.5 2.5 0 0 1 5 18.5v-13A2.5 2.5 0 0 1 7.5 3H8"/><path d="M12 15a3 3 0 1 0 0-6"/></svg>',
    materials: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M4 4.5A2.5 2.5 0 0 1 6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15Z"/></svg>',
    analytics: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M3 3v18h18"/><path d="M7 16V9"/><path d="M12 16V5"/><path d="M17 16v-3"/></svg>',
    studentView: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
    report: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/></svg>',
    profile: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
    search: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>',
    moon: '<svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>',
    sun: '<svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>',
    bell: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M10 21h4"/><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/></svg>',
    logout: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg>',
  };

  const TEACHER_NAV = [
    { page: 'dashboard', href: 'TDashboard.php', label: 'Dashboard', icon: ICONS.dashboard },
    { page: 'create-quiz', href: '/SmartLearn/Teacher/TCreateQuiz.php', label: 'Create Quiz', icon: ICONS.createQuiz },
    { page: 'manage-quiz', href: '/SmartLearn/Teacher/TQuizManagement.php', label: 'Manage Quiz', icon: ICONS.manageQuiz },
    { page: 'materials', href: '/SmartLearn/Teacher/TLearningMaterials.php', label: 'Upload Materials', icon: ICONS.materials },
    { page: 'analytics', href: '/SmartLearn/Teacher/TClassPerformance.php', label: 'Analytics', icon: ICONS.analytics },
    { page: 'student-view', href: '/SmartLearn/Teacher/TStudentAnalytic.php', label: 'Student View', icon: ICONS.studentView },
    { page: 'report', href: '/SmartLearn/Teacher/TReport.php', label: 'Report', icon: ICONS.report },
    { page: 'profile', href: '/SmartLearn/Teacher/TProfile.php', label: 'Profile', icon: ICONS.profile },
  ];

  const PAGE_BY_FILE = {
    'TDashboard.php': 'dashboard',
    'TCreateQuiz.php': 'create-quiz',
    'TQuizManagement.php': 'manage-quiz',
    'TLearningMaterials.php': 'materials',
    'TClassPerformance.php': 'analytics',
    'TStudentAnalytic.php': 'student-view',
    'TReport.php': 'report',
    'TProfile.php': 'profile',
  };

  let ACTIVITY_FEED = [];
  let activityFeedLoaded = false;

  function getActivePage() {
    if (document.body.dataset.page) return document.body.dataset.page;
    const file = location.pathname.split('/').pop() || 'TDashboard.php';
    return PAGE_BY_FILE[file] || 'dashboard';
  }

  function renderSidebar() {
    const nav = document.querySelector('.sidebar .nav');
    const sidebar = document.querySelector('.sidebar');
    if (!nav || !sidebar) return;

    const active = getActivePage();
    nav.innerHTML = TEACHER_NAV.map((item) => {
      const cls = item.page === active ? ' class="active"' : '';
      return `<a href="${item.href}"${cls}>${item.icon}<span>${item.label}</span></a>`;
    }).join('');

    let logout = document.getElementById('logoutBtn');
    if (logout && logout.closest('.profile')) {
      logout.remove();
      logout = null;
    }

    if (!logout) {
      logout = document.createElement('button');
      logout.className = 'sidebar-logout';
      logout.id = 'logoutBtn';
      logout.type = 'button';
      sidebar.appendChild(logout);
    } else if (!sidebar.contains(logout)) {
      sidebar.appendChild(logout);
    }

    logout.className = 'sidebar-logout';
    logout.innerHTML = `${ICONS.logout}<span>Logout</span>`;
  }

  function normalizeTopbar() {
    const topbar = document.querySelector('.topbar');
    if (!topbar) return;

    topbar.id = 'topbar';
    topbar.querySelector('.profile #logoutBtn, .profile .btn-logout')?.remove();

    topbar.querySelector('.search, .search-wrap')?.remove();

    let profile = topbar.querySelector('.profile');
    if (!profile) {
      profile = document.createElement('div');
      profile.className = 'profile';
      topbar.appendChild(profile);
    }

    if (!document.getElementById('themeToggle')) {
      const themeBtn = document.createElement('button');
      themeBtn.className = 'top-icon theme-toggle';
      themeBtn.id = 'themeToggle';
      themeBtn.type = 'button';
      themeBtn.setAttribute('aria-label', 'Toggle dark mode');
      themeBtn.innerHTML = ICONS.moon + ICONS.sun;
      profile.insertBefore(themeBtn, profile.firstChild);
    }

    let notifBtn = document.getElementById('notificationBtn');
    if (!notifBtn) {
      const legacyBell = profile.querySelector('.top-icon:not(.theme-toggle)');
      if (legacyBell && !legacyBell.id) {
        legacyBell.id = 'notificationBtn';
        legacyBell.type = 'button';
        legacyBell.setAttribute('aria-label', 'Notifications');
        notifBtn = legacyBell;
      } else {
        notifBtn = document.createElement('button');
        notifBtn.className = 'top-icon';
        notifBtn.id = 'notificationBtn';
        notifBtn.type = 'button';
        notifBtn.setAttribute('aria-label', 'Notifications');
        notifBtn.innerHTML = ICONS.bell;
        const theme = document.getElementById('themeToggle');
        theme.insertAdjacentElement('afterend', notifBtn);
      }
    }

    if (!profile.querySelector('.avatar')) {
      const avatar = document.createElement('div');
      avatar.className = 'avatar';
      avatar.setAttribute('aria-hidden', 'true');
      profile.appendChild(avatar);
    }

    const avatarEl = profile.querySelector('.avatar');
    if (avatarEl && window.TEACHER_PROFILE_PICTURE && !avatarEl.style.backgroundImage) {
      avatarEl.style.backgroundImage = `url(${window.TEACHER_PROFILE_PICTURE}?v=${Date.now()})`;
      avatarEl.style.backgroundSize = 'cover';
      avatarEl.style.backgroundPosition = 'center';
    }

    const nameBlock = profile.querySelector('strong')?.parentElement;
    if (!nameBlock || !nameBlock.querySelector('strong')) {
      const info = document.createElement('div');
      info.innerHTML = '<strong>Dr. Anita Sharma</strong><span>Computer Science</span>';
      profile.appendChild(info);
    }
  }

  function getScrollRoot() {
    return document.querySelector('.main .content') || document.documentElement;
  }

  function initScrollLayout() {
    const content = document.querySelector('.main .content');
    const topbar = document.getElementById('topbar');
    const progress = document.getElementById('scrollProgress');
    const backToTop = document.getElementById('backToTop');

    const onScroll = () => {
      const root = content || document.documentElement;
      const scrollTop = content ? content.scrollTop : window.scrollY;
      const max = content
        ? content.scrollHeight - content.clientHeight
        : root.scrollHeight - root.clientHeight;
      const pct = max > 0 ? (scrollTop / max) * 100 : 0;

      if (progress) progress.style.width = `${pct}%`;
      if (topbar) topbar.classList.toggle('scrolled', scrollTop > 12);
      if (backToTop) backToTop.classList.toggle('show', scrollTop > 420);
    };

    if (content) {
      content.addEventListener('scroll', onScroll, { passive: true });
    } else {
      window.addEventListener('scroll', onScroll, { passive: true });
    }
    onScroll();

    if (backToTop) {
      backToTop.addEventListener('click', () => {
        const target = content || window;
        if (content) {
          content.scrollTo({ top: 0, behavior: 'smooth' });
        } else {
          window.scrollTo({ top: 0, behavior: 'smooth' });
        }
      });
    }
  }

  function getReadIds() {
    try {
      return JSON.parse(localStorage.getItem(STORAGE_NOTIFICATIONS) || '[]');
    } catch {
      return [];
    }
  }

  function saveReadIds(ids) {
    localStorage.setItem(STORAGE_NOTIFICATIONS, JSON.stringify(ids));
  }

  function initTheme() {
    const saved = localStorage.getItem(STORAGE_THEME);
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    const theme = saved || (prefersDark ? 'dark' : 'light');
    document.documentElement.setAttribute('data-theme', theme);

    const toggle = document.getElementById('themeToggle');
    if (!toggle) return;

    toggle.replaceWith(toggle.cloneNode(true));
    document.getElementById('themeToggle').addEventListener('click', () => {
      const current = document.documentElement.getAttribute('data-theme');
      const next = current === 'dark' ? 'light' : 'dark';
      document.documentElement.setAttribute('data-theme', next);
      localStorage.setItem(STORAGE_THEME, next);
      showToast(next === 'dark' ? 'Dark mode enabled' : 'Light mode enabled');
    });
  }

  function showToast(message) {
    let toast = document.getElementById('toastMsg');
    if (!toast) {
      toast = document.createElement('div');
      toast.id = 'toastMsg';
      toast.className = 'toast-msg';
      toast.setAttribute('role', 'status');
      toast.setAttribute('aria-live', 'polite');
      document.body.appendChild(toast);
    }
    toast.textContent = message;
    toast.classList.add('show');
    clearTimeout(showToast._timer);
    showToast._timer = setTimeout(() => toast.classList.remove('show'), 2400);
  }
  window.showToast = showToast;

  function timeAgo(dateString) {
    const then = new Date(dateString.replace(' ', 'T'));
    if (isNaN(then.getTime())) return '';
    const seconds = Math.max(0, Math.floor((Date.now() - then.getTime()) / 1000));
    if (seconds < 60) return 'Just now';
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes} min ago`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours} hour${hours === 1 ? '' : 's'} ago`;
    const days = Math.floor(hours / 24);
    if (days < 7) return `${days} day${days === 1 ? '' : 's'} ago`;
    return then.toLocaleDateString();
  }

  async function loadActivityFeed(panel) {
    try {
      const res = await fetch('TDashboard.php?feed=activity', { credentials: 'same-origin' });
      const data = await res.json();
      ACTIVITY_FEED = Array.isArray(data.items) ? data.items : [];
    } catch {
      ACTIVITY_FEED = [];
    }
    activityFeedLoaded = true;
    renderNotifications(panel);
  }

  function renderNotifications(panel) {
    const readIds = getReadIds();
    let unreadCount = 0;

    panel.querySelector('.note-list')?.remove();
    panel.querySelector('.note-empty')?.remove();

    const container = document.createElement('div');
    container.className = 'note-list';

    if (!activityFeedLoaded) {
      container.innerHTML = '<div class="note-empty">Loading…</div>';
    } else if (ACTIVITY_FEED.length === 0) {
      container.innerHTML = '<div class="note-empty">No recent activity yet — create a quiz or upload a material to see it here.</div>';
    } else {
      ACTIVITY_FEED.forEach((n) => {
        const isUnread = !readIds.includes(n.id);
        if (isUnread) unreadCount++;
        const div = document.createElement('div');
        div.className = 'note-item' + (isUnread ? ' unread' : ' read');
        div.dataset.id = n.id;
        div.innerHTML = `<strong>${n.title}</strong><span>${n.message}</span><span class="note-time">${timeAgo(n.date)}</span>`;
        div.addEventListener('click', () => {
          markOneRead(n.id, panel);
          if (n.url) window.location.href = n.url;
        });
        container.appendChild(div);
      });
    }

    const header = panel.querySelector('.note-header');
    if (header && header.nextSibling) {
      panel.insertBefore(container, header.nextSibling);
    } else {
      panel.appendChild(container);
    }

    updateBadge(unreadCount);
  }

  function markOneRead(id, panel) {
    const readIds = getReadIds();
    if (!readIds.includes(id)) {
      readIds.push(id);
      saveReadIds(readIds);
    }
    renderNotifications(panel);
  }

  function markAllRead(panel) {
    const ids = ACTIVITY_FEED.map((n) => n.id);
    saveReadIds([...new Set([...getReadIds(), ...ids])]);
    renderNotifications(panel);
    showToast('All notifications marked as read');
  }

  function updateBadge(count) {
    const btn = document.getElementById('notificationBtn');
    if (!btn) return;
    let badge = btn.querySelector('.notification-badge');
    if (count > 0) {
      if (!badge) {
        badge = document.createElement('span');
        badge.className = 'notification-badge';
        btn.appendChild(badge);
      }
      badge.textContent = count > 9 ? '9+' : String(count);
      btn.classList.add('has-badge');
    } else {
      badge?.remove();
      btn.classList.remove('has-badge');
    }
  }

  function initNotifications() {
    let panel = document.getElementById('notificationPanel');
    const btn = document.getElementById('notificationBtn');

    if (!panel && btn) {
      panel = document.createElement('div');
      panel.id = 'notificationPanel';
      panel.className = 'notification-panel';
      panel.setAttribute('role', 'dialog');
      panel.setAttribute('aria-label', 'Notifications');
      panel.innerHTML = `
        <div class="note-header">
          <h4>Notifications</h4>
          <button type="button" class="mark-read" id="markAllRead">Mark all read</button>
        </div>
      `;
      document.body.appendChild(panel);
    }

    if (!panel || !btn) return;

    btn.replaceWith(btn.cloneNode(true));
    const freshBtn = document.getElementById('notificationBtn');

    renderNotifications(panel);
    loadActivityFeed(panel);
    panel.querySelector('#markAllRead')?.addEventListener('click', (e) => {
      e.stopPropagation();
      markAllRead(panel);
    });

    freshBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      panel.classList.toggle('show');
    });

    document.addEventListener('click', (e) => {
      if (!panel.contains(e.target) && !freshBtn.contains(e.target)) {
        panel.classList.remove('show');
      }
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') panel.classList.remove('show');
    });
  }

  function initLogout() {
    const btn = document.getElementById('logoutBtn');
    if (!btn) return;
    btn.replaceWith(btn.cloneNode(true));
    document.getElementById('logoutBtn').addEventListener('click', () => {
      if (confirm('Are you sure you want to logout?')) {

        window.location.href = 'logout.php';
      }
    });
  }

  function initAvatarLink() {
    document.querySelector('.topbar .avatar')?.addEventListener('click', () => {
      window.location.href = '/SmartLearn/Teacher/TProfile.php';
    });
  }

  function highlightScores() {
    document.querySelectorAll('[data-score]').forEach((el) => {
      const score = parseInt(el.dataset.score, 10);
      if (score >= 80) el.classList.add('score-high');
      else if (score < 60) el.classList.add('score-low');
    });
  }

  function initSubjectFilter() {
    const filter = document.getElementById('subjectFilter');
    const tbody = document.getElementById('studentTableBody');
    if (!filter || !tbody) return;
    filter.addEventListener('change', () => {
      const subject = filter.value;
      tbody.querySelectorAll('tr').forEach((row) => {
        if (!subject || subject === 'all') {
          row.style.display = '';
        } else {
          row.style.display = row.dataset.subject === subject ? '' : 'none';
        }
      });
      showToast(subject === 'all' ? 'Showing all subjects' : `Filtered by ${filter.options[filter.selectedIndex].text}`);
    });
  }

  function initTableSearch(inputId, tableSelector) {
    const input = document.getElementById(inputId);
    const table = document.querySelector(tableSelector);
    if (!input || !table) return;
    input.addEventListener('input', () => {
      const q = input.value.toLowerCase().trim();
      table.querySelectorAll('tbody tr').forEach((row) => {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
      });
    });
  }

  document.addEventListener('DOMContentLoaded', () => {
    renderSidebar();
    normalizeTopbar();
    initScrollLayout();
    initTheme();
    initNotifications();
    initLogout();
    initAvatarLink();
    highlightScores();
    initTableSearch('quizSearch', '#quizTable');
    initTableSearch('materialSearch', '#materialTable');
  });
})();