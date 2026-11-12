<?php
// ============================================================
// admissions/dashboard.php  — ADMIN (requires login)
// Admissions officer view of all submitted applications
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../shared/dashboard_components.php';
requireLogin();
ini_set('display_errors', 0); error_reporting(0);

$pageTitle  = 'Admissions Dashboard';
$activePage = 'adm_dashboard';
$db = getDB();

// ── Filters ──
if (!array_key_exists('status', $_GET)) {
    $query = $_GET;
    $query['status'] = 'pending';
    header('Location: ' . APP_ROOT . '/admissions/dashboard.php?' . http_build_query($query));
    exit;
}

$statusValue = $_GET['status'];
$statusFilter = is_string($statusValue) ? trim($statusValue) : 'pending';
$allowedStatuses = ['pending', 'shortlisted', 'admitted', 'rejected'];
if ($statusFilter !== '' && !in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'pending';
}
$progValue = $_GET['prog'] ?? '';
$progFilter = is_string($progValue) ? trim($progValue) : '';
$searchValue = $_GET['q'] ?? '';
$search = is_string($searchValue) ? trim($searchValue) : '';

$sortColumns = [
    'reference' => 'a.reference_no',
    'name' => ['a.surname', 'a.first_name'],
    'mobile' => 'a.mobile_no',
    'programme' => 'a.programme_name',
    'documents' => 'required_doc_count',
    'submitted' => 'a.submitted_at',
    'status' => 'a.status',
];
$requestedSort = $_GET['sort'] ?? 'submitted';
$sortKey = is_string($requestedSort) && isset($sortColumns[$requestedSort]) ? $requestedSort : 'submitted';
$requestedDirection = $_GET['dir'] ?? 'desc';
$sortDirection = is_string($requestedDirection) && strtolower($requestedDirection) === 'asc' ? 'ASC' : 'DESC';
$perPageOptions = [10, 25, 50, 100];
$requestedPerPageValue = $_GET['per_page'] ?? 25;
$requestedPerPage = is_scalar($requestedPerPageValue) ? (int) $requestedPerPageValue : 25;
$perPage = in_array($requestedPerPage, $perPageOptions, true) ? $requestedPerPage : 25;
$requestedPageValue = $_GET['page'] ?? 1;
$pageNumber = max(1, is_scalar($requestedPageValue) ? (int) $requestedPageValue : 1);

$where  = ['1=1'];
$params = [];

if ($statusFilter !== '') {
    $where[] = 'a.status=?';
    $params[] = $statusFilter;
}
if ($progFilter)   { $where[] = 'a.programme_type=?'; $params[] = $progFilter; }
if ($search)       { $where[] = '(CONCAT(a.surname," ",a.first_name) LIKE ? OR a.reference_no LIKE ? OR a.mobile_no LIKE ?)';
                     $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }
$whereSQL = implode(' AND ', $where);
$buildDashboardUrl = static function (array $changes = [], array $remove = []): string {
    $query = $_GET;
    foreach ($remove as $key) {
        unset($query[$key]);
    }
    foreach ($changes as $key => $value) {
        $query[$key] = $value;
    }
    return APP_ROOT . '/admissions/dashboard.php' . ($query ? '?' . http_build_query($query) : '');
};
$sortHeader = static function (string $label, string $key) use ($sortKey, $sortDirection, $buildDashboardUrl): string {
    $nextDirection = $sortKey === $key && $sortDirection === 'ASC' ? 'desc' : 'asc';
    $ariaSort = $sortKey === $key ? ($sortDirection === 'ASC' ? 'ascending' : 'descending') : 'none';
    $indicator = $sortKey === $key ? ($sortDirection === 'ASC' ? '↑' : '↓') : '↕';
    $href = htmlspecialchars($buildDashboardUrl(['sort' => $key, 'dir' => $nextDirection, 'page' => 1]), ENT_QUOTES, 'UTF-8');
    return '<th scope="col" aria-sort="' . $ariaSort . '"><a class="table-sort-link" href="' . $href . '">' .
        htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '<span aria-hidden="true">' . $indicator . '</span></a></th>';
};

// ── Stats ──
$stats = $db->query("
    SELECT
        COUNT(*) AS total,
        SUM(status='pending')     AS pending,
        SUM(status='shortlisted') AS shortlisted,
        SUM(status='admitted')    AS admitted,
        SUM(status='rejected')    AS rejected
    FROM admissions
")->fetch();
foreach (['total', 'pending', 'shortlisted', 'admitted', 'rejected'] as $statusKey) {
    $stats[$statusKey] = (int) ($stats[$statusKey] ?? 0);
}

$latestSubmittedAt = $db->query("SELECT MAX(submitted_at) FROM admissions")->fetchColumn();
$weeklyRows = [];
$weeklyApplications = [];
$weeklyTotal = 0;
$weeklyMax = 0;
$weeklyChartPeriod = '';
if ($latestSubmittedAt) {
    $latestWeekStart = (new DateTimeImmutable($latestSubmittedAt))->modify('monday this week');
    $chartWeekEnd = $latestWeekStart->modify('+1 week');
    $chartWeekStart = $chartWeekEnd->modify('-8 weeks');
    $weeklyQuery = $db->prepare("
    SELECT YEARWEEK(submitted_at, 3) AS week_key, COUNT(*) AS application_count
    FROM admissions
    WHERE submitted_at >= ? AND submitted_at < ?
    GROUP BY YEARWEEK(submitted_at, 3)
    ORDER BY week_key
    ");
    $weeklyQuery->execute([$chartWeekStart->format('Y-m-d H:i:s'), $chartWeekEnd->format('Y-m-d H:i:s')]);
    $weeklyRows = $weeklyQuery->fetchAll();
    $weeklyCounts = [];
    foreach ($weeklyRows as $weekRow) {
        $weeklyCounts[(int) $weekRow['week_key']] = (int) $weekRow['application_count'];
    }
    for ($weekOffset = 0; $weekOffset < 8; $weekOffset++) {
        $weekDate = $chartWeekStart->modify('+' . $weekOffset . ' weeks');
        $weekKey = (int) $weekDate->format('oW');
        $weeklyApplications[] = [
            'label' => $weekDate->format('j M'),
            'count' => $weeklyCounts[$weekKey] ?? 0,
        ];
    }
    $weeklyTotal = array_sum(array_column($weeklyApplications, 'count'));
    $weeklyMax = max(array_column($weeklyApplications, 'count'));
    $weeklyChartPeriod = $chartWeekStart->format('j M') . ' – ' . $chartWeekEnd->modify('-1 day')->format('j M Y');
}
$currentWeekStart = new DateTimeImmutable('monday this week');
$currentWeekEnd = $currentWeekStart->modify('+1 week');
$currentWeekQuery = $db->prepare("SELECT COUNT(*) FROM admissions WHERE submitted_at >= ? AND submitted_at < ?");
$currentWeekQuery->execute([$currentWeekStart->format('Y-m-d H:i:s'), $currentWeekEnd->format('Y-m-d H:i:s')]);
$newThisWeek = (int) $currentWeekQuery->fetchColumn();

// ── Applications list ──
$totalApplications = (int) $db->query("SELECT COUNT(*) FROM admissions")->fetchColumn();
$filteredCountQuery = $db->prepare("SELECT COUNT(*) FROM admissions a WHERE $whereSQL");
$filteredCountQuery->execute($params);
$filteredApplications = (int) $filteredCountQuery->fetchColumn();
$totalPages = max(1, (int) ceil($filteredApplications / $perPage));
$pageNumber = min($pageNumber, $totalPages);
$offset = ($pageNumber - 1) * $perPage;
$orderColumns = (array) $sortColumns[$sortKey];
$orderBy = implode(', ', array_map(
    static function (string $column) use ($sortDirection): string {
        return $column . ' ' . $sortDirection;
    },
    $orderColumns
)) . ', a.admission_id DESC';

$apps = $db->prepare("
    SELECT a.*,
           COUNT(DISTINCT CASE
               WHEN d.doc_type IN ('application_form','kcse_cert','kcpe_cert') THEN d.doc_type
           END) + MAX(CASE WHEN d.doc_type IN ('birth_cert','national_id') THEN 1 ELSE 0 END) AS required_doc_count
    FROM admissions a
    LEFT JOIN admission_documents d ON d.admission_id = a.admission_id
    WHERE $whereSQL
    GROUP BY a.admission_id
    ORDER BY $orderBy
    LIMIT $perPage OFFSET $offset
");
$apps->execute($params);
$apps = $apps->fetchAll();

$filterChips = [];
if ($statusFilter !== '') {
    $filterChips[] = [
        'label' => 'Status: ' . ucfirst($statusFilter),
        'href' => $buildDashboardUrl(['status' => '', 'page' => 1]),
    ];
}
if ($progFilter !== '') {
    $filterChips[] = [
        'label' => 'Programme: ' . formatTitleCase($progFilter),
        'href' => $buildDashboardUrl(['page' => 1], ['prog']),
    ];
}
if ($search !== '') {
    $filterChips[] = [
        'label' => 'Search: ' . $search,
        'href' => $buildDashboardUrl(['page' => 1], ['q']),
    ];
}
$clearFiltersUrl = APP_ROOT . '/admissions/dashboard.php?status=';

$admin = getCurrentAdmin();
include __DIR__ . '/partials/adm_header.php';
?>

<style>
.status-pill{display:inline-block;padding:3px 10px;border-radius:10px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px}
.sp-pending    {background:rgba(217,119,6,.1);  color:#b45309}
.sp-shortlisted{background:rgba(37,99,235,.1);  color:#1d4ed8}
.sp-admitted   {background:rgba(22,163,74,.1);  color:#15803d}
.sp-rejected   {background:rgba(220,38,38,.1);  color:#dc2626}
.admissions-filter-bar{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px;align-items:center}
.admissions-filter-bar input,.admissions-filter-bar select{padding:8px 12px;border-radius:8px;border:1px solid var(--border);background:var(--input-bg,var(--surface));color:var(--text-primary);font-family:inherit;font-size:13px}
.admissions-filter-bar input{flex:1;min-width:200px}
.admissions-filter-chips{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:12px;font-size:12px;color:var(--text-muted)}
.admissions-filter-chip{display:inline-flex;align-items:center;gap:7px;padding:5px 9px;border-radius:999px;background:var(--surface);border:1px solid var(--border);color:var(--text-primary);text-decoration:none}
.admissions-filter-chip:hover{border-color:var(--color-primary,#2563eb)}
.admissions-filter-chip span{font-weight:700;color:var(--text-muted)}
.admissions-table-card{overflow:visible}
.admissions-table-scroll{overflow-x:auto;max-width:100%;border-radius:0 0 12px 12px}
.admissions-table{min-width:1050px}
.admissions-table thead th{position:sticky;top:0;z-index:2;background:var(--surface)}
.admissions-table th,.admissions-table td{padding:12px 14px}
.table-sort-link{display:inline-flex;align-items:center;gap:6px;color:inherit;text-decoration:none;white-space:nowrap}
.table-sort-link:hover{color:var(--color-primary,#2563eb)}
.table-sort-link span{font-size:11px;color:var(--text-muted)}
.admissions-table .ref-cell,.admissions-table .date-cell,.admissions-table .docs-cell{white-space:nowrap}
.admissions-table .programme-cell{min-width:220px;max-width:320px;white-space:normal}
.programme-name{font-weight:600;line-height:1.35}
.programme-type-badge{display:inline-block;margin-top:5px;padding:2px 7px;border-radius:999px;background:rgba(37,99,235,.1);color:#1d4ed8;font-size:10px;font-weight:700;text-transform:capitalize}
.doc-completeness{display:flex;align-items:center;gap:7px;font-size:11px;font-weight:700}
.doc-progress{display:block;width:52px;height:5px;overflow:hidden;border-radius:999px;background:var(--border)}
.doc-progress span{display:block;height:100%;border-radius:inherit;background:#16a34a}
.doc-progress--incomplete span{background:var(--color-warning)}
.doc-incomplete{display:block;margin-top:4px;color:#b45309;font-size:10px}
.admissions-row{cursor:pointer}
.admissions-row:hover{background:rgba(37,99,235,.045)}
.admissions-row:focus-visible{outline:2px solid var(--color-primary,#2563eb);outline-offset:-2px}
.table-action-menu{position:relative}
.table-action-menu summary{display:flex;align-items:center;justify-content:center;width:34px;height:34px;border:1px solid var(--border);border-radius:8px;cursor:pointer;list-style:none;font-size:20px;color:var(--text-primary)}
.table-action-menu summary::-webkit-details-marker{display:none}
.table-action-menu[open] summary{background:var(--bg,#f9fafb)}
.table-action-menu-items{position:absolute;right:0;top:calc(100% + 4px);z-index:10;min-width:150px;padding:5px;background:var(--surface);border:1px solid var(--border);border-radius:9px;box-shadow:0 8px 24px rgba(0,0,0,.14)}
.table-action-menu-items a{display:block;padding:8px 10px;border-radius:6px;color:var(--text-primary);text-decoration:none;font-size:12px}
.table-action-menu-items a:hover{background:var(--bg,#f9fafb)}
.admissions-list-meta,.admissions-pagination{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:12px 16px;border-top:1px solid var(--border);font-size:12px;color:var(--text-muted)}
.admissions-pagination-controls{display:flex;align-items:center;gap:8px}
.admissions-page-size{display:flex;align-items:center;gap:7px}
.admissions-page-size select{padding:6px 8px;border:1px solid var(--border);border-radius:7px;background:var(--input-bg,var(--surface));color:var(--text-primary)}
.admissions-pagination a{padding:6px 9px;border:1px solid var(--border);border-radius:7px;color:var(--text-primary);text-decoration:none}
.admissions-pagination a[aria-disabled="true"]{opacity:.45;pointer-events:none}
@media(max-width:768px){.admissions-filter-bar{padding:0!important}.admissions-table-card .card-body{padding:0!important}.admissions-list-meta,.admissions-pagination{align-items:flex-start}}
</style>

<div class="page-header">
    <div>
        <h1 class="page-title"><?= appIcon('graduation-cap') ?> Admissions Dashboard</h1>
        <p class="page-subtitle">
            <?= $stats['pending'] > 0
                ? pluralizeCount($stats['pending'], 'application') . ' awaiting review · ' . pluralizeCount($newThisWeek, 'new application') . ' this week'
                : ($stats['total'] > 0 ? 'No applications awaiting review · ' . pluralizeCount($stats['total'], 'application') . ' in the register' : 'No applications have been submitted yet.') ?>
        </p>
    </div>
    <div class="dashboard-actions">
        <a href="<?= APP_ROOT ?>/admissions/dashboard.php?status=pending" class="btn btn-primary"><?= appIcon('file') ?> Review Pending</a>
        <a href="<?= APP_ROOT ?>/admissions/apply.php" target="_blank" rel="noopener" class="btn btn-ghost"><?= appIcon('eye') ?> Preview Public Form</a>
    </div>
</div>

<!-- Stats -->
<div class="dashboard-stat-grid">
    <?php
    renderDashboardStatCard([
        'label' => 'Total Applications',
        'value' => formatNumber($stats['total']),
        'summary' => $stats['total'] > 0 ? 'All application statuses' : 'No applications submitted yet',
        'href' => APP_ROOT . '/admissions/dashboard.php?status=',
        'tone' => 'info',
        'problem' => true,
        'active' => $stats['total'] > 0,
    ]);
    renderDashboardStatCard([
        'label' => 'Pending Review',
        'value' => formatNumber($stats['pending']),
        'summary' => $stats['pending'] > 0 ? 'Applications awaiting review' : 'No applications awaiting review',
        'href' => APP_ROOT . '/admissions/dashboard.php?status=pending',
        'tone' => 'warning',
        'problem' => true,
        'active' => $stats['pending'] > 0,
    ]);
    renderDashboardStatCard([
        'label' => 'Shortlisted',
        'value' => formatNumber($stats['shortlisted']),
        'summary' => $stats['shortlisted'] > 0 ? 'Candidates ready for the next step' : 'No shortlisted applications',
        'href' => APP_ROOT . '/admissions/dashboard.php?status=shortlisted',
        'tone' => 'info',
        'problem' => true,
        'active' => $stats['shortlisted'] > 0,
    ]);
    renderDashboardStatCard([
        'label' => 'Admitted',
        'value' => formatNumber($stats['admitted']),
        'summary' => $stats['admitted'] > 0 ? 'Successful applications' : 'No admitted applications yet',
        'href' => APP_ROOT . '/admissions/dashboard.php?status=admitted',
        'tone' => 'success',
        'problem' => true,
        'active' => $stats['admitted'] > 0,
    ]);
    renderDashboardStatCard([
        'label' => 'Not Admitted',
        'value' => formatNumber($stats['rejected']),
        'summary' => $stats['rejected'] > 0 ? 'Applications not admitted' : 'No applications in this status',
        'href' => APP_ROOT . '/admissions/dashboard.php?status=rejected',
        'tone' => 'danger',
        'problem' => true,
        'active' => $stats['rejected'] > 0,
    ]);
    ?>
</div>

<div class="admissions-insights-grid">
    <section class="card admissions-insight-card">
        <div class="card-header"><h2 class="card-title">Application Status Breakdown</h2></div>
        <div class="card-body">
            <?php if ($stats['total'] > 0): ?>
                <div class="admissions-status-bar" role="img" aria-label="Application status breakdown">
                    <?php foreach (['pending', 'shortlisted', 'admitted', 'rejected'] as $status):
                        $statusPercent = ($stats[$status] / $stats['total']) * 100;
                    ?>
                        <span class="admissions-status-segment admissions-status-segment--<?= $status ?>"
                              style="width:<?= $statusPercent ?>%"
                              title="<?= htmlspecialchars(ucfirst($status) . ': ' . formatNumber($stats[$status])) ?>"></span>
                    <?php endforeach; ?>
                </div>
                <div class="admissions-status-legend">
                    <?php foreach (['pending', 'shortlisted', 'admitted', 'rejected'] as $status):
                        $statusPercent = ($stats[$status] / $stats['total']) * 100;
                    ?>
                        <div class="admissions-status-legend-item">
                            <span class="admissions-status-name">
                                <span class="admissions-status-dot admissions-status-dot--<?= $status ?>"></span>
                                <?= $status === 'rejected' ? 'Not admitted' : ucfirst($status) ?>
                            </span>
                            <strong><?= formatNumber($stats[$status]) ?> · <?= formatPercentage($statusPercent) ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="dashboard-empty-state">No applications have been submitted yet.</div>
            <?php endif; ?>
        </div>
    </section>

    <section class="card admissions-insight-card">
        <div class="card-header"><h2 class="card-title">Applications per Week</h2></div>
        <div class="card-body">
            <?php if ($weeklyTotal > 0): ?>
                <p class="admissions-chart-period">Eight weeks through <?= htmlspecialchars($weeklyChartPeriod) ?></p>
                <div class="admissions-week-chart" role="img" aria-label="Applications submitted per week over the eight-week period ending <?= htmlspecialchars($weeklyChartPeriod) ?>">
                    <?php foreach ($weeklyApplications as $week): ?>
                        <?php $barHeight = $weeklyMax > 0 ? max(3, ($week['count'] / $weeklyMax) * 100) : 0; ?>
                        <div class="admissions-week-column" title="<?= htmlspecialchars($week['label'] . ': ' . pluralizeCount($week['count'], 'application')) ?>">
                            <span class="admissions-week-count"><?= formatNumber($week['count']) ?></span>
                            <span class="admissions-week-bar-track">
                                <span class="admissions-week-bar" style="height:<?= $barHeight ?>%"></span>
                            </span>
                            <span class="admissions-week-label"><?= htmlspecialchars($week['label']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="dashboard-empty-state">
                    <p><?= $stats['total'] > 0 ? 'No applications were submitted in the last eight weeks.' : 'Weekly activity will appear after the first application is submitted.' ?></p>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<!-- Filters -->
<form class="admissions-filter-bar" method="GET" action="<?= APP_ROOT ?>/admissions/dashboard.php" id="admissions-filters">
    <input type="search" name="q" id="sq" placeholder="Search name, reference, or mobile"
           value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" aria-label="Search applications">
    <select name="status" aria-label="Filter by status" onchange="this.form.submit()">
        <option value="">All statuses</option>
        <?php foreach ($allowedStatuses as $s): ?>
        <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="prog" aria-label="Filter by programme type" onchange="this.form.submit()">
        <option value="">All programme types</option>
        <?php foreach (['certificate', 'diploma', 'postgraduate'] as $p): ?>
        <option value="<?= $p ?>" <?= $progFilter === $p ? 'selected' : '' ?>><?= ucfirst($p) ?></option>
        <?php endforeach; ?>
    </select>
    <input type="hidden" name="sort" value="<?= htmlspecialchars($sortKey) ?>">
    <input type="hidden" name="dir" value="<?= strtolower($sortDirection) ?>">
    <input type="hidden" name="per_page" value="<?= $perPage ?>">
    <button class="btn btn-primary btn-sm" type="submit">Apply filters</button>
    <?php if ($filterChips): ?>
    <a href="<?= htmlspecialchars($clearFiltersUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-ghost btn-sm">Clear</a>
    <?php endif; ?>
</form>
<?php if ($filterChips): ?>
<div class="admissions-filter-chips" aria-label="Active filters">
    <span>Active filters:</span>
    <?php foreach ($filterChips as $chip): ?>
    <a class="admissions-filter-chip" href="<?= htmlspecialchars($chip['href'], ENT_QUOTES, 'UTF-8') ?>">
        <?= htmlspecialchars($chip['label'], ENT_QUOTES, 'UTF-8') ?><span aria-hidden="true">×</span>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Applications table -->
<div class="card admissions-table-card">
    <div class="card-body" style="padding:0">
        <div class="admissions-list-meta">
            <span><strong><?= formatNumber($filteredApplications) ?> of <?= formatNumber($totalApplications) ?></strong> applications</span>
            <form class="admissions-page-size" method="GET" action="<?= APP_ROOT ?>/admissions/dashboard.php">
                <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="prog" value="<?= htmlspecialchars($progFilter, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="sort" value="<?= htmlspecialchars($sortKey) ?>">
                <input type="hidden" name="dir" value="<?= strtolower($sortDirection) ?>">
                <label for="admissions-page-size">Rows per page</label>
                <select id="admissions-page-size" name="per_page" onchange="this.form.submit()">
                    <?php foreach ($perPageOptions as $pageSize): ?>
                    <option value="<?= $pageSize ?>" <?= $perPage === $pageSize ? 'selected' : '' ?>><?= $pageSize ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
        <?php if (empty($apps)): ?>
        <div style="padding:60px;text-align:center;color:var(--text-muted)">
            <div style="font-size:40px;margin-bottom:12px"><?= appIcon('file') ?></div>
            <p>No applications found<?= $filterChips ? ' matching your filters' : ' yet' ?>.</p>
        </div>
        <?php else: ?>
        <div class="admissions-table-scroll">
        <table class="data-table admissions-table" id="apps-tbl">
            <caption class="sr-only">Admissions applications</caption>
            <thead><tr>
                <?= $sortHeader('Ref No', 'reference') ?>
                <?= $sortHeader('Full Name', 'name') ?>
                <?= $sortHeader('Mobile', 'mobile') ?>
                <?= $sortHeader('Programme', 'programme') ?>
                <?= $sortHeader('Documents', 'documents') ?>
                <?= $sortHeader('Submitted', 'submitted') ?>
                <?= $sortHeader('Status', 'status') ?>
                <th scope="col"><span class="sr-only">Actions</span></th>
            </tr></thead>
            <tbody>
            <?php foreach ($apps as $a): ?>
            <?php
            $applicationId = (int) $a['admission_id'];
            $applicationUrl = APP_ROOT . '/admissions/application.php?id=' . $applicationId;
            $requiredDocCount = (int) $a['required_doc_count'];
            $requiredDocPercent = min(100, ($requiredDocCount / 4) * 100);
            $fullName = formatPersonName($a['surname'] . ', ' . $a['first_name'] . ($a['middle_name'] ? ' ' . $a['middle_name'] : ''));
            $statusLabel = $a['status'] === 'rejected' ? 'Not admitted' : ucfirst($a['status']);
            ?>
            <tr class="admissions-row" data-row-href="<?= htmlspecialchars($applicationUrl, ENT_QUOTES, 'UTF-8') ?>"
                tabindex="0" aria-label="View application <?= htmlspecialchars($a['reference_no'] . ', ' . $fullName, ENT_QUOTES, 'UTF-8') ?>">
                <td class="ref-cell"><code style="font-size:11px;font-weight:700"><?= htmlspecialchars($a['reference_no']) ?></code></td>
                <td style="font-weight:600"><?= htmlspecialchars($fullName) ?></td>
                <td style="font-family:'Space Mono',monospace;font-size:12px"><?= htmlspecialchars($a['mobile_no']) ?></td>
                <td class="programme-cell">
                    <div class="programme-name" title="<?= htmlspecialchars($a['programme_name'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(formatTitleCase($a['programme_name'])) ?></div>
                    <span class="programme-type-badge"><?= htmlspecialchars(formatTitleCase($a['programme_type'])) ?></span>
                </td>
                <td class="docs-cell">
                    <div class="doc-completeness">
                        <span><?= $requiredDocCount ?>/4 docs</span>
                        <span class="doc-progress <?= $requiredDocCount < 4 ? 'doc-progress--incomplete' : '' ?>" role="progressbar" aria-label="<?= $requiredDocCount ?> of 4 required document types" aria-valuemin="0" aria-valuemax="4" aria-valuenow="<?= $requiredDocCount ?>">
                            <span style="width:<?= $requiredDocPercent ?>%"></span>
                        </span>
                    </div>
                    <?php if ($requiredDocCount < 4): ?>
                    <span class="doc-incomplete"><?= appIcon('alert-triangle') ?> Incomplete</span>
                    <?php endif; ?>
                </td>
                <td class="date-cell date-value" style="font-size:12px;color:var(--text-muted)"><?= formatDate($a['submitted_at']) ?></td>
                <td><span class="status-pill sp-<?= htmlspecialchars($a['status']) ?>"><?= htmlspecialchars($statusLabel) ?></span></td>
                <td class="table-action-cell">
                    <details class="table-action-menu">
                        <summary aria-label="Actions for <?= htmlspecialchars($a['reference_no'], ENT_QUOTES, 'UTF-8') ?>">⋮</summary>
                        <div class="table-action-menu-items">
                            <a href="<?= htmlspecialchars($applicationUrl, ENT_QUOTES, 'UTF-8') ?>">View</a>
                            <?php foreach (['shortlisted' => 'Shortlist', 'admitted' => 'Admit', 'rejected' => 'Reject'] as $targetStatus => $actionLabel):
                                if ($a['status'] === $targetStatus) continue;
                            ?>
                            <a href="<?= htmlspecialchars($applicationUrl . '&review_status=' . $targetStatus . '#review', ENT_QUOTES, 'UTF-8') ?>"><?= $actionLabel ?></a>
                            <?php endforeach; ?>
                        </div>
                    </details>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <nav class="admissions-pagination" aria-label="Applications pagination">
            <span>Page <?= $pageNumber ?> of <?= $totalPages ?><?= $filteredApplications > 0 ? ' · ' . formatNumber($offset + 1) . '–' . formatNumber(min($offset + $perPage, $filteredApplications)) . ' of ' . formatNumber($filteredApplications) : '' ?></span>
            <div class="admissions-pagination-controls">
                <a href="<?= htmlspecialchars($buildDashboardUrl(['page' => max(1, $pageNumber - 1)]), ENT_QUOTES, 'UTF-8') ?>" aria-disabled="<?= $pageNumber <= 1 ? 'true' : 'false' ?>">Previous</a>
                <a href="<?= htmlspecialchars($buildDashboardUrl(['page' => min($totalPages, $pageNumber + 1)]), ENT_QUOTES, 'UTF-8') ?>" aria-disabled="<?= $pageNumber >= $totalPages ? 'true' : 'false' ?>">Next</a>
            </div>
        </nav>
        <?php endif; ?>
    </div>
</div>

<script>
document.querySelectorAll('.admissions-row[data-row-href]').forEach(function(row) {
    row.addEventListener('click', function(event) {
        if (event.target.closest('a, button, summary, details, input, select, form')) return;
        window.location.href = row.dataset.rowHref;
    });
    row.addEventListener('keydown', function(event) {
        if (event.key === 'Enter' && event.target === row) window.location.href = row.dataset.rowHref;
    });
});
document.querySelectorAll('.table-action-menu').forEach(function(menu) {
    menu.addEventListener('toggle', function() {
        if (!menu.open) return;
        document.querySelectorAll('.table-action-menu[open]').forEach(function(otherMenu) {
            if (otherMenu !== menu) otherMenu.open = false;
        });
    });
});
</script>

<?php include __DIR__ . '/partials/adm_footer.php'; ?>
