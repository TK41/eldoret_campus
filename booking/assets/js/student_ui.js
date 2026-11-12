// Student portal UI: theme and user menu. Uses its own storage key so toggling the
// theme here never changes the staff system's theme (and vice versa).
var STUDENT_THEME_KEY = 'kimc_student_theme';

function studentApplyTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    document.documentElement.style.colorScheme = theme;
    var icon = document.getElementById('theme-icon');
    if (icon) icon.dataset.theme = theme;
}

function studentToggleTheme() {
    var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    try { localStorage.setItem(STUDENT_THEME_KEY, next); } catch (e) {}
    studentApplyTheme(next);
}

function studentToggleMenu() {
    var dd = document.getElementById('user-dropdown');
    var trigger = document.getElementById('user-menu-trigger');
    if (dd) {
        var isOpen = dd.classList.toggle('open');
        if (trigger) trigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    }
}

document.addEventListener('click', function (e) {
    var menu = document.querySelector('.user-menu');
    var dd = document.getElementById('user-dropdown');
    if (dd && menu && !menu.contains(e.target)) {
        dd.classList.remove('open');
        var trigger = document.getElementById('user-menu-trigger');
        if (trigger) trigger.setAttribute('aria-expanded', 'false');
    }
});

studentApplyTheme(document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light');
