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
    updateThemeIcon(saved);
})();

function toggleTheme() {
    const current = document.documentElement.getAttribute('data-theme');
    const next    = current === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    localStorage.setItem(THEME_KEY, next);
    updateThemeIcon(next);
}

function updateThemeIcon(theme) {
    const iconEl = document.getElementById('theme-icon');
    if (iconEl) iconEl.textContent = theme === 'dark' ? '☀️' : '🌙';
}


// ============================================================
// SIDEBAR TOGGLE (mobile)
// ============================================================
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    if (sidebar) sidebar.classList.toggle('open');
}

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
    const fineCalc  = document.getElementById('kci-fine-calc');
    const fineAmt   = document.getElementById('kci-fine-amt');

    if (hoursOverdue > 0) {
        const fine       = hoursOverdue * fineRates.equipment;
        fineCalc.textContent    = hoursOverdue + 'h overdue × KES ' + fineRates.equipment + '/h = KES ' + fine.toFixed(2);
        fineAmt.value           = fine.toFixed(2);
        finePanel.style.display = 'block';
    } else {
        finePanel.style.display = 'none';
        fineAmt.value = '0';
    }

    openModal('kit-checkin-modal');
}

function openSingleCheckin(txnId, assetName, hoursOverdue, assetType) {
    document.getElementById('sci-txn').value        = txnId;
    document.getElementById('sci-name').textContent = assetName;

    const finePanel = document.getElementById('sci-fine-panel');
    const fineCalc  = document.getElementById('sci-fine-calc');
    const fineAmt   = document.getElementById('sci-fine-amt');

    if (hoursOverdue > 0) {
        let fine, desc;
        if (assetType === 'equipment') {
            fine = hoursOverdue * fineRates.equipment;
            desc = hoursOverdue + 'h overdue × KES ' + fineRates.equipment + '/h = KES ' + fine.toFixed(2);
        } else {
            const days = Math.ceil(hoursOverdue / 24);
            fine = days * fineRates.book;
            desc = days + ' day(s) overdue × KES ' + fineRates.book + '/day = KES ' + fine.toFixed(2);
        }
        fineCalc.textContent    = desc;
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
