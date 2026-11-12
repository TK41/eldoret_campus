// ============================================================
// assets/js/main.js
// KIMC Eldoret Campus Inventory System — Main JavaScript
// ============================================================

// Defined first — inline onclick handlers depend on these
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }


// ============================================================
// THEME MANAGEMENT
// ============================================================
const THEME_KEY = 'kimc_theme';

(function initTheme() {
    const saved = localStorage.getItem(THEME_KEY) || 'light';
    document.documentElement.setAttribute('data-theme', saved);
    document.documentElement.style.colorScheme = saved;
    updateThemeIcon(saved);
})();

function toggleTheme() {
    const current = document.documentElement.getAttribute('data-theme');
    const next    = current === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    document.documentElement.style.colorScheme = next;
    localStorage.setItem(THEME_KEY, next);
    updateThemeIcon(next);
}

function updateThemeIcon(theme) {
    const iconEl = document.getElementById('theme-icon');
    if (iconEl) iconEl.dataset.theme = theme;
}

// ============================================================
// HEARTBEAT FOR ADMIN PRESENCE
// Automatically update last_seen for the current admin on every page.
// ============================================================
(function initHeartbeat() {
    let basePath = '';
    const scripts = Array.from(document.getElementsByTagName('script'));
    const mainScript = scripts.find(s => s.src && /\/assets\/js\/main\.js(?:\?|$)/.test(s.src));

    if (mainScript && mainScript.src) {
        try {
            const url = new URL(mainScript.src, window.location.origin);
            const match = url.pathname.match(/^(.*)\/assets\/js\/main\.js$/);
            if (match) {
                basePath = match[1];
            }
        } catch (e) {
            basePath = '';
        }
    }

    const heartbeatUrl = (basePath ? basePath : '') + '/api/heartbeat.php';
    const heartbeat = () => fetch(heartbeatUrl, { credentials: 'same-origin' }).catch(() => {});

    document.addEventListener('DOMContentLoaded', function () {
        heartbeat();
        setInterval(heartbeat, 45000);
    });
})();


// ============================================================
// SIDEBAR TOGGLE (mobile)
// ============================================================
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    if (window.innerWidth > 768) {
        const collapsed = document.body.classList.toggle('sidebar-collapsed');
        try { localStorage.setItem('sidebarCollapsed', collapsed ? '1' : '0'); } catch (e) {}
        return;
    }
    if (sidebar) sidebar.classList.toggle('open');
}

try {
    if (localStorage.getItem('sidebarCollapsed') === '1') document.body.classList.add('sidebar-collapsed');
} catch (e) {}

document.addEventListener('click', function(e) {
    const sidebar = document.getElementById('sidebar');
    const toggle  = document.querySelector('.sidebar-toggle');
    if (sidebar && sidebar.classList.contains('open')) {
        if (!sidebar.contains(e.target) && e.target !== toggle && !toggle?.contains(e.target)) {
            sidebar.classList.remove('open');
        }
    }
});

// ============================================================
// SHARED SHELL: breadcrumbs, notifications, and global search
// ============================================================
(function initSharedShell() {
    const breadcrumbSource = document.getElementById('page-breadcrumb-source');
    const pageHeading = document.querySelector('.main-content .page-title, .main-content h1');
    if (breadcrumbSource && pageHeading) {
        const breadcrumb = document.createElement('nav');
        breadcrumb.className = 'page-breadcrumbs';
        breadcrumb.setAttribute('aria-label', 'Breadcrumb');

        const list = document.createElement('ol');
        const moduleItem = document.createElement('li');
        const moduleLink = document.createElement('a');
        moduleLink.href = breadcrumbSource.dataset.homeUrl;
        moduleLink.textContent = breadcrumbSource.dataset.moduleLabel;
        moduleItem.appendChild(moduleLink);

        const pageItem = document.createElement('li');
        pageItem.setAttribute('aria-current', 'page');
        pageItem.textContent = breadcrumbSource.dataset.pageTitle;
        list.append(moduleItem, pageItem);
        breadcrumb.appendChild(list);
        pageHeading.insertAdjacentElement('afterend', breadcrumb);
    }

    const notificationMenu = document.getElementById('notification-menu');
    if (notificationMenu) {
        notificationMenu.addEventListener('toggle', function() {
            notificationMenu.querySelector('summary').setAttribute('aria-expanded', notificationMenu.open ? 'true' : 'false');
        });
        document.addEventListener('click', function(e) {
            if (notificationMenu.open && !notificationMenu.contains(e.target)) notificationMenu.open = false;
        });
    }

    const markReadButton = document.getElementById('mark-all-notifications-read');
    if (markReadButton) {
        markReadButton.addEventListener('click', async function() {
            const status = document.getElementById('notification-action-status');
            markReadButton.disabled = true;
            status.textContent = 'Marking notifications as read...';
            try {
                const response = await fetch(markReadButton.dataset.endpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'mark_all_read',
                        csrf_token: markReadButton.dataset.csrf
                    })
                });
                const result = await response.json();
                if (!response.ok || !result.success) throw new Error('Unable to update notifications.');

                document.querySelectorAll('.notification-item.unread').forEach(item => item.classList.remove('unread'));
                document.querySelector('.notif-bell .badge-dot')?.remove();
                notificationMenu.querySelector('summary').setAttribute('aria-label', 'Notifications');
                status.textContent = 'All notifications marked as read.';
            } catch (error) {
                status.textContent = 'Could not mark notifications as read. Please try again.';
                markReadButton.disabled = false;
            }
        });
    }

    const overlay = document.getElementById('global-search-overlay');
    const trigger = document.getElementById('global-search-trigger');
    const input = document.getElementById('global-search-input');
    const results = document.getElementById('global-search-results');
    const closeButton = document.getElementById('global-search-close');
    if (!overlay || !trigger || !input || !results || !closeButton) return;

    let searchTimer = null;
    let searchController = null;

    function showSearchMessage(className, message) {
        const paragraph = document.createElement('p');
        paragraph.className = className;
        paragraph.textContent = message;
        results.replaceChildren(paragraph);
    }

    function closeSearch() {
        overlay.hidden = true;
        input.value = '';
        results.replaceChildren();
        searchController?.abort();
        trigger.focus();
    }

    function renderSearchGroups(groups) {
        results.replaceChildren();
        const matchingGroups = groups.filter(group => group.items.length > 0);
        if (matchingGroups.length === 0) {
            showSearchMessage('global-search-empty', 'No matching records found.');
            return;
        }

        matchingGroups.forEach(group => {
            const section = document.createElement('section');
            section.className = 'global-search-group';
            const heading = document.createElement('h3');
            heading.textContent = group.label;
            section.appendChild(heading);

            group.items.forEach(item => {
                const link = document.createElement('a');
                link.className = 'global-search-result';
                link.href = item.url;
                const title = document.createElement('strong');
                title.textContent = item.label;
                const meta = document.createElement('span');
                meta.textContent = item.meta;
                link.append(title, meta);
                link.addEventListener('click', closeSearch);
                section.appendChild(link);
            });
            results.appendChild(section);
        });
    }

    async function runGlobalSearch() {
        const query = input.value.trim();
        if (query.length < 2) {
            results.replaceChildren();
            return;
        }

        searchController?.abort();
        searchController = new AbortController();
        const endpoint = new URL(overlay.querySelector('.global-search-dialog').dataset.endpoint, window.location.href);
        endpoint.searchParams.set('q', query);
        showSearchMessage('global-search-hint', 'Searching...');
        try {
            const response = await fetch(endpoint, {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
                signal: searchController.signal
            });
            const data = await response.json();
            if (!response.ok || !data.success) throw new Error('Search request failed.');
            renderSearchGroups(data.groups);
        } catch (error) {
            if (error.name === 'AbortError') return;
            showSearchMessage('global-search-error', 'Search is unavailable. Please try again.');
        }
    }

    function openSearch() {
        overlay.hidden = false;
        input.focus();
        input.select();
    }

    trigger.addEventListener('click', openSearch);
    closeButton.addEventListener('click', closeSearch);
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) closeSearch();
    });
    input.addEventListener('input', function() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(runGlobalSearch, 180);
    });
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
            e.preventDefault();
            openSearch();
        } else if (e.key === 'Escape' && !overlay.hidden) {
            closeSearch();
        }
    });
})();


// ============================================================
// USER DROPDOWN MENU
// ============================================================
function toggleUserMenu() {
    const dd = document.getElementById('user-dropdown');
    if (dd) dd.classList.toggle('open');
}

document.addEventListener('click', function(e) {
    const menu = document.querySelector('.user-menu');
    const dd   = document.getElementById('user-dropdown');
    if (dd && menu && !menu.contains(e.target)) {
        dd.classList.remove('open');
    }
});


// Close modal on backdrop click
document.querySelectorAll('.modal-overlay').forEach(o => {
    o.addEventListener('click', e => { if (e.target === o) o.classList.remove('open'); });
});


// ============================================================
// FLASH MESSAGE AUTO-DISMISS (5 seconds)
// ============================================================
document.querySelectorAll('.alert').forEach(function(alert) {
    setTimeout(function() {
        alert.style.transition = 'opacity .5s';
        alert.style.opacity    = '0';
        setTimeout(function() { alert.remove(); }, 500);
    }, 5000);
});


// ============================================================
// TYPE TOGGLE (Add Asset page)
// ============================================================
document.querySelectorAll('.type-toggle input[type="radio"]').forEach(function(radio) {
    radio.addEventListener('change', function() {
        document.querySelectorAll('.type-toggle').forEach(t => t.classList.remove('active'));
        this.closest('.type-toggle').classList.add('active');
    });
});


// ============================================================
// FORM CONFIRMATION (data-confirm attribute)
// ============================================================
document.querySelectorAll('[data-confirm]').forEach(function(el) {
    el.addEventListener('click', function(e) {
        if (!confirm(this.getAttribute('data-confirm'))) e.preventDefault();
    });
});


// ============================================================
// ASSET CODE AUTO-UPPERCASE
// ============================================================
const assetCodeField = document.getElementById('asset_code');
if (assetCodeField) {
    assetCodeField.addEventListener('input', function() {
        const pos  = this.selectionStart;
        this.value = this.value.toUpperCase();
        this.setSelectionRange(pos, pos);
    });
}


// ============================================================
// PRINT HELPER
// ============================================================
function printPage() { window.print(); }


// ============================================================
// CHECKOUT TAB SWITCHER (transactions page)
// ============================================================
function switchTab(tab, btn) {
    document.querySelectorAll('.co-tab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('form-kit').style.display    = tab === 'kit'    ? 'block' : 'none';
    document.getElementById('form-single').style.display = tab === 'single' ? 'block' : 'none';
}


// ============================================================
// KIT PREVIEW PANEL (checkout modal)
// kitData is injected by transactions.php as a global
// ============================================================
function previewKit(sel) {
    const kitId = parseInt(sel.value);
    const panel = document.getElementById('kit-preview');
    const list  = document.getElementById('kit-preview-list');

    if (!kitId || typeof kitData === 'undefined' || !kitData[kitId] || kitData[kitId].length === 0) {
        panel.style.display = 'none';
        return;
    }

    list.innerHTML = kitData[kitId].map(item =>
        `<div class="kp-item">
            <span class="kp-code">${item.code}</span>
            <span class="kp-name">${item.name}</span>
            <span class="kp-cond">${item.cond}</span>
        </div>`
    ).join('');

    panel.style.display = 'block';
}


// ============================================================
// KIT COMPONENT ROW TOGGLE (transaction table)
// ============================================================
function toggleComponents(groupId) {
    const panel = document.getElementById('panel-' + groupId);
    const btn   = document.querySelector(`.kit-row[data-group="${groupId}"] .expand-btn`);
    if (!panel) return;

    const isOpen = panel.style.display === 'table-row';
    panel.style.display = isOpen ? 'none' : 'table-row';
    btn.classList.toggle('open', !isOpen);
}


// ============================================================
// CHECK-IN MODALS
// fineRates is injected by transactions.php as a global
// ============================================================
function openKitCheckin(groupId, kitName, hoursOverdue, componentCount) {
    document.getElementById('kci-group').value       = groupId;
    document.getElementById('kci-name').textContent  = kitName;
    document.getElementById('kci-count').textContent = componentCount + ' item' + (componentCount !== 1 ? 's' : '');

    const finePanel = document.getElementById('kci-fine-panel');
    const fineAmt   = document.getElementById('kci-fine-amt');

    // Kits are equipment loans, so overdue fines are not applied here.
    finePanel.style.display = 'none';
    fineAmt.value = '0';

    openModal('kit-checkin-modal');
}

function openSingleCheckin(txnId, assetName, hoursOverdue, assetType) {
    document.getElementById('sci-txn').value        = txnId;
    document.getElementById('sci-name').textContent = assetName;

    const finePanel = document.getElementById('sci-fine-panel');
    const fineCalc  = document.getElementById('sci-fine-calc');
    const fineAmt   = document.getElementById('sci-fine-amt');

    if (hoursOverdue > 0 && assetType === 'book') {
        const days = Math.ceil(hoursOverdue / 24);
        const fine = days * fineRates.book;
        fineCalc.textContent    = days + ' day(s) overdue × KES ' + fineRates.book + '/day = KES ' + fine.toFixed(2);
        fineAmt.value           = fine.toFixed(2);
        finePanel.style.display = 'block';
    } else {
        finePanel.style.display = 'none';
        fineAmt.value = '0';
    }

    openModal('single-checkin-modal');
}


// ============================================================
// AUTOCOMPLETE
// acUsers and acAssets are injected by transactions.php as globals
// ============================================================
function acSearch(inputEl, hiddenId, dropdownId) {
    const q        = inputEl.value.trim().toLowerCase();
    const dropdown = document.getElementById(dropdownId);
    const hidden   = document.getElementById(hiddenId);
    hidden.value   = ''; // clear selection while typing

    const dataset = dropdownId.includes('users') ? acUsers : acAssets;

    if (q.length < 1) {
        dropdown.innerHTML = '';
        dropdown.classList.remove('open');
        return;
    }

    const matches = dataset.filter(item =>
        item.label.toLowerCase().includes(q) || item.sub.toLowerCase().includes(q)
    ).slice(0, 8);

    if (matches.length === 0) {
        dropdown.innerHTML = '<div class="ac-item ac-empty">No results found</div>';
    } else {
        dropdown.innerHTML = matches.map(item => `
            <div class="ac-item" data-id="${item.id}"
                 onclick="acSelect('${hiddenId}','${inputEl.id}','${dropdownId}',${item.id},'${item.label.replace(/'/g,"\\'")} (${item.sub.replace(/'/g,"\\'")})')">
                <span class="ac-main">${item.label}</span>
                <span class="ac-sub">${item.sub}</span>
                ${item.warn ? `<span class="ac-warn">${item.warn}</span>` : ''}
            </div>`
        ).join('');
    }
    dropdown.classList.add('open');
}

function acSelect(hiddenId, inputId, dropdownId, id, label) {
    document.getElementById(hiddenId).value = id;
    document.getElementById(inputId).value  = label;
    document.getElementById(dropdownId).classList.remove('open');
    document.getElementById(dropdownId).innerHTML = '';
}

// Close autocomplete dropdowns on outside click
document.addEventListener('click', function(e) {
    if (!e.target.closest('.ac-wrap')) {
        document.querySelectorAll('.ac-dropdown').forEach(d => {
            d.classList.remove('open');
            d.innerHTML = '';
        });
    }
});

// Clear autocomplete fields when checkout modal closes
const _coModal = document.getElementById('checkout-modal');
if (_coModal) {
    _coModal.querySelectorAll('.modal-close, .btn-ghost').forEach(btn => {
        btn.addEventListener('click', () => {
            ['kit-user-id', 'single-user-id', 'single-asset-id'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.value = '';
            });
            ['kit-user-search', 'single-user-search', 'single-asset-search'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.value = '';
            });
        });
    });
}
