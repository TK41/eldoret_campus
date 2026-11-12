<?php
require_once __DIR__ . '/../../auth/rbac.php';
requireAccess('exams');

$shellModule = 'exams';
$shellModuleLabel = 'Exam Results';
$shellDashboard = APP_ROOT . '/exams/dashboard.php';
$shellAdmin = getCurrentAdmin();
$shellFlash = getFlash();
$shellNavItems = [
    ['type' => 'section', 'label' => 'Exam Results'],
    ['type' => 'link', 'label' => 'Dashboard', 'url' => APP_ROOT . '/exams/dashboard.php', 'key' => 'exam_dashboard', 'icon' => '📊'],
    ['type' => 'link', 'label' => 'Exam Sessions', 'url' => APP_ROOT . '/exams/sessions.php', 'key' => 'exam_sessions', 'icon' => '🗓'],
    ['type' => 'link', 'label' => 'Enter Marks', 'url' => APP_ROOT . '/exams/enter_marks.php', 'key' => 'exam_enter', 'icon' => '✏️'],
    ['type' => 'link', 'label' => 'View Results', 'url' => APP_ROOT . '/exams/results.php', 'key' => 'exam_results', 'icon' => '📋'],
    ['type' => 'link', 'label' => 'Transcripts', 'url' => APP_ROOT . '/exams/transcripts.php', 'key' => 'exam_transcripts', 'icon' => '🎓'],
    ['type' => 'link', 'label' => 'Analytics', 'url' => APP_ROOT . '/exams/analytics.php', 'key' => 'exam_analytics', 'icon' => '📈'],
    ['type' => 'section', 'label' => 'Setup'],
    ['type' => 'link', 'label' => 'Units/Subjects', 'url' => APP_ROOT . '/exams/units.php', 'key' => 'exam_units', 'icon' => '📚'],
];
$shellExtraHead = <<<'HTML'
<style>
.grade-A { background:rgba(22,163,74,.12); color:#15803d; font-weight:700; }
.grade-B { background:rgba(37,99,235,.12); color:#1d4ed8; font-weight:700; }
.grade-C { background:rgba(217,119,6,.12); color:#b45309; font-weight:700; }
.grade-D { background:rgba(234,88,12,.12); color:#c2410c; font-weight:700; }
.grade-F { background:rgba(220,38,38,.1); color:#dc2626; font-weight:700; }
.grade-pill {
    display:inline-block; padding:2px 10px; border-radius:10px;
    font-family:'Space Mono',monospace; font-size:12px;
}
.score-input {
    width:72px; padding:5px 8px; border:1px solid var(--border);
    border-radius:6px; background:var(--input-bg, var(--surface));
    color:var(--text-primary); font-family:'Space Mono',monospace;
    font-size:13px; text-align:center;
    transition:border-color .15s, box-shadow .15s;
}
.score-input:focus { outline:none; border-color:var(--border); box-shadow:0 0 0 3px rgba(148,163,184,.2); }
.score-input.saving { border-color:var(--color-warning); }
.score-input.saved { border-color:var(--color-success); }
.score-input.error { border-color:var(--color-danger-text); }
.live-total {
    font-family:'Space Mono',monospace; font-size:13px; font-weight:700;
    padding:4px 10px; border-radius:8px; min-width:52px; text-align:center;
    transition:background .3s, color .3s;
}
.locked-badge {
    display:inline-flex; align-items:center; gap:4px;
    background:var(--color-danger-bg); color:var(--color-danger-text);
    border:1px solid color-mix(in srgb, var(--color-danger-text) 25%, transparent);
    padding:3px 10px; border-radius:10px; font-size:11px; font-weight:700;
}
</style>
HTML;

require __DIR__ . '/../../shared/app_shell_header.php';
