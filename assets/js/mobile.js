/* ============================================================
   assets/js/mobile.js
   KIMC Eldoret — Mobile companion script
   Add ONE line to every module header partial, AFTER main.js:
       <script src="<?= APP_ROOT ?>/assets/js/mobile.js"></script>
   ============================================================ */

(function () {
    'use strict';

    // ── 1. Inject sidebar scrim element ──────────────────────
    const scrim = document.createElement('div');
    scrim.className = 'sidebar-scrim';
    scrim.id = 'sidebar-scrim';
    document.body.appendChild(scrim);

    // ── 2. Enhanced sidebar toggle with scrim ─────────────────
    function openSidebar() {
        const sb = document.getElementById('sidebar');
        if (!sb) return;
        sb.classList.add('open');
        scrim.classList.add('visible');
        document.body.style.overflow = 'hidden'; // prevent background scroll
    }

    function closeSidebar() {
        const sb = document.getElementById('sidebar');
        if (!sb) return;
        sb.classList.remove('open');
        scrim.classList.remove('visible');
        document.body.style.overflow = '';
    }

    // Override the existing toggleSidebar with the enhanced version
    window.toggleSidebar = function () {
        const sb = document.getElementById('sidebar');
        if (!sb) return;
        sb.classList.contains('open') ? closeSidebar() : openSidebar();
    };

    // Close when tapping the scrim
    scrim.addEventListener('click', closeSidebar);

    // Close sidebar when a nav item is tapped (on mobile)
    document.addEventListener('click', function (e) {
        const navItem = e.target.closest('.nav-item');
        if (navItem && window.innerWidth <= 768) {
            closeSidebar();
        }
    });

    // Close sidebar on resize to desktop
    window.addEventListener('resize', function () {
        if (window.innerWidth > 768) closeSidebar();
    });


    // ── 3. Fix topbar height offset on mobile ─────────────────
    function adjustTopbarOffset() {
        const topbar = document.querySelector('.topbar');
        if (!topbar) return;
        const h = topbar.offsetHeight;
        if (window.innerWidth <= 768) {
            const layout  = document.querySelector('.layout');
            const sidebar = document.getElementById('sidebar');
            if (layout)  layout.style.paddingTop = h + 'px';
            if (sidebar) sidebar.style.top       = h + 'px';
        } else {
            const layout  = document.querySelector('.layout');
            const sidebar = document.getElementById('sidebar');
            if (layout)  layout.style.paddingTop = '';
            if (sidebar) sidebar.style.top       = '';
        }
    }
    adjustTopbarOffset();
    window.addEventListener('resize', adjustTopbarOffset);


    // ── 4. Make tables scroll with momentum on iOS ────────────
    document.querySelectorAll('.card-body, .marks-wrap, .marks-grid-wrap').forEach(function (el) {
        el.style.webkitOverflowScrolling = 'touch';
    });


    // ── 5. Auto-close user dropdown on outside tap ────────────
    document.addEventListener('touchstart', function (e) {
        const menu = document.querySelector('.user-menu');
        const dd   = document.getElementById('user-dropdown');
        if (dd && menu && !menu.contains(e.target)) {
            dd.classList.remove('open');
        }
    }, { passive: true });


    // ── 6. Prevent double-tap zoom on buttons ─────────────────
    let lastTouchEnd = 0;
    document.addEventListener('touchend', function (e) {
        const now = Date.now();
        if (now - lastTouchEnd <= 300) {
            const target = e.target.closest('.btn, .nav-item, .icon-btn, .step, .file-drop');
            if (target) e.preventDefault();
        }
        lastTouchEnd = now;
    }, { passive: false });


    // ── 7. Add mobile-specific class to body for CSS targeting ─
    if (window.innerWidth <= 768) {
        document.body.classList.add('is-mobile');
    }
    window.addEventListener('resize', function () {
        document.body.classList.toggle('is-mobile', window.innerWidth <= 768);
    });


    // ── 8. Inline-style grid fixer (runtime) ──────────────────
    // Some grids are set via inline style attributes on PHP-generated
    // divs which CSS attribute selectors can't always match perfectly.
    // This runs once on load to override them on mobile.
    function fixInlineGrids() {
        if (window.innerWidth > 768) return;
        document.querySelectorAll('[style]').forEach(function (el) {
            const s = el.getAttribute('style') || '';
            // Two-column or wider inline grids → single column
            if (/grid-template-columns\s*:\s*(2fr|3fr|1fr 1fr|repeat\(2)/.test(s)) {
                el.style.gridTemplateColumns = '1fr';
            }
            // Three-column → two column
            if (/grid-template-columns\s*:\s*(1fr 1fr 1fr|repeat\(3)/.test(s)) {
                el.style.gridTemplateColumns = '1fr 1fr';
            }
        });
    }
    // Run after DOM settles
    setTimeout(fixInlineGrids, 100);
    window.addEventListener('resize', fixInlineGrids);

})();
