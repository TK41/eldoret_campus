<?php
// ============================================================
// fees/export.php
// Clean export page — renders UI or streams Excel download
// ============================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/session.php';
requireLogin();
ini_set('display_errors', 1); error_reporting(E_ALL);

// Load PhpSpreadsheet at top level (use statements must be global scope)
$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) require_once $autoload;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

$db = getDB();
$groups = $db->query("SELECT *, CONCAT(name, IF(academic_year <> '', CONCAT(' (', academic_year, ')'), '')) AS group_label FROM fee_groups ORDER BY group_id")->fetchAll();

// ── If download requested, stream the Excel file ────────────
if (isset($_GET['download'])) {

    if (!file_exists($autoload)) {
        die('PhpSpreadsheet not installed. Run: composer require phpoffice/phpspreadsheet in your project root.');
    }

    $groupFilter = intval($_GET['group'] ?? 0);
    $today       = date('Y-m-d');

    if ($groupFilter > 0) {
        $s = $db->prepare("SELECT *, CONCAT(name, IF(academic_year <> '', CONCAT(' (', academic_year, ')'), '')) AS group_label FROM fee_groups WHERE group_id=?");
        $s->execute([$groupFilter]);
        $exportGroups = $s->fetchAll();
    } else {
        $exportGroups = $groups;
    }

    $spreadsheet = new Spreadsheet();
    $spreadsheet->getProperties()
        ->setCreator('KIMC Eldoret')
        ->setTitle('Fee Collection Report — ' . $today);

    $sheetIndex = 0;

    foreach ($exportGroups as $group) {
        $stmt = $db->prepare("
            SELECT
                s.student_id,
                s.full_name,
                s.programme,
                s.total_fees,
                COALESCE(SUM(p.amount), 0)                    AS paid,
                s.total_fees - COALESCE(SUM(p.amount), 0)     AS balance
            FROM fee_students s
            LEFT JOIN fee_payments p ON p.fee_student_id = s.fee_student_id
            WHERE s.group_id = ? AND s.is_active = 1
            GROUP BY s.fee_student_id
            ORDER BY s.full_name ASC
        ");
        $stmt->execute([$group['group_id']]);
        $students = $stmt->fetchAll();

        $sheet = $sheetIndex === 0
            ? $spreadsheet->getActiveSheet()
            : $spreadsheet->createSheet();
        $sheetIndex++;
        $rawLabel = $group['group_label'] ?? ($group['name'] . (isset($group['academic_year']) && $group['academic_year'] ? ' (' . $group['academic_year'] . ')' : ''));
        $sheetLabel = preg_replace('/[\\:\/?\*\[\]]+/', '-', $rawLabel);
        $sheetLabel = trim(substr($sheetLabel, 0, 31), " -_");
        if ($sheetLabel === '') {
            $sheetLabel = 'Sheet' . $sheetIndex;
        }
        $sheet->setTitle($sheetLabel);

        // Title
        $sheet->mergeCells('A1:H1');
        $sheet->setCellValue('A1', 'KIMC ELDORET — FEE COLLECTION REPORT');
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1A3A6B']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(28);

        // Subtitle
        $sheet->mergeCells('A2:H2');
        $sheet->setCellValue('A2', ($group['group_label'] ?? ($group['name'] . (isset($group['academic_year']) && $group['academic_year'] ? ' (' . $group['academic_year'] . ')' : ''))) . '   |   Exported: ' . date('d M Y'));
        $sheet->getStyle('A2')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D97706']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(18);

        // Headers
        $headers = ['#', 'Adm No', 'Student Name', 'Programme', 'Total Fees (KES)', 'Amount Paid (KES)', 'Balance (KES)', 'Status'];
        foreach ($headers as $col => $header) {
            $sheet->setCellValue(chr(65 + $col) . '3', $header);
        }
        $sheet->getStyle('A3:H3')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1A3A6B']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'FFFFFF']]],
        ]);
        $sheet->getRowDimension(3)->setRowHeight(18);

        // Data
        $row = 4; $totalFees = 0; $totalPaid = 0; $totalBal = 0;

        foreach ($students as $i => $s) {
            $balance = floatval($s['balance']);
            $paid    = floatval($s['paid']);
            $fees    = floatval($s['total_fees']);

            if ($paid > $fees)              $status = 'Overpaid';
            elseif ($balance <= 0)          $status = 'Fully Paid';
            elseif ($paid > 0)              $status = 'Partial';
            else                            $status = 'No Payment';

            $statusColor = match($status) {
                'Fully Paid'  => '16A34A',
                'Overpaid'    => '7C3AED',
                'Partial'     => 'D97706',
                default       => 'DC2626',
            };

            $sheet->setCellValue('A' . $row, $i + 1);
            $sheet->setCellValueExplicit('B' . $row, $s['student_id'], DataType::TYPE_STRING);
            $sheet->setCellValue('C' . $row, $s['full_name']);
            $sheet->setCellValue('D' . $row, $s['programme'] ?? '');
            $sheet->setCellValue('E' . $row, $fees);
            $sheet->setCellValue('F' . $row, $paid);
            $sheet->setCellValue('G' . $row, max(0, $balance));
            $sheet->setCellValue('H' . $row, $status);

            $sheet->getStyle('E' . $row . ':G' . $row)->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle('H' . $row)->applyFromArray([
                'font'      => ['bold' => true, 'color' => ['rgb' => $statusColor]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);

            $bg = ($i % 2 === 0) ? 'F8FAFC' : 'FFFFFF';
            $sheet->getStyle('A' . $row . ':H' . $row)->applyFromArray([
                'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bg]],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E2E8F0']]],
            ]);
            $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('B' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            $totalFees += $fees; $totalPaid += $paid; $totalBal += max(0, $balance);
            $row++;
        }

        // Totals row
        $sheet->mergeCells('A' . $row . ':D' . $row);
        $sheet->setCellValue('A' . $row, 'TOTALS — ' . count($students) . ' student(s)');
        $sheet->setCellValue('E' . $row, $totalFees);
        $sheet->setCellValue('F' . $row, $totalPaid);
        $sheet->setCellValue('G' . $row, $totalBal);
        $sheet->getStyle('E' . $row . ':G' . $row)->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle('A' . $row . ':H' . $row)->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1A3A6B']],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'FFFFFF']]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(20);

        // Column widths
        $sheet->getColumnDimension('A')->setWidth(5);
        $sheet->getColumnDimension('B')->setWidth(14);
        $sheet->getColumnDimension('C')->setWidth(30);
        $sheet->getColumnDimension('D')->setWidth(28);
        $sheet->getColumnDimension('E')->setWidth(18);
        $sheet->getColumnDimension('F')->setWidth(18);
        $sheet->getColumnDimension('G')->setWidth(16);
        $sheet->getColumnDimension('H')->setWidth(13);
        $sheet->freezePane('A4');
    }

    $filename = $groupFilter > 0
        ? 'fees-' . strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $exportGroups[0]['name'])) . '-' . $today . '.xlsx'
        : 'fees-all-groups-' . $today . '.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    header('Pragma: public');
    header('Expires: 0');
    if (ob_get_length()) {
        ob_end_clean();
    }
    (new Xlsx($spreadsheet))->save('php://output');
    exit;
}

// ── Page stats for preview cards ────────────────────────────
$pageTitle  = 'Export Excel';
$activePage = 'fees_export';

$groupStats = $db->query("
    SELECT
        g.group_id,
        CONCAT(g.name, IF(g.academic_year <> '', CONCAT(' (', g.academic_year, ')'), '')) AS group_label,
        g.name,
        g.academic_year,
        COUNT(s.fee_student_id)                                          AS total,
        COALESCE(SUM(COALESCE((SELECT SUM(p.amount) FROM fee_payments p WHERE p.fee_student_id=s.fee_student_id),0)),0) AS collected,
        COALESCE(SUM(s.total_fees),0)                                    AS expected
    FROM fee_groups g
    LEFT JOIN fee_students s ON s.group_id=g.group_id AND s.is_active=1
    GROUP BY g.group_id ORDER BY g.group_id
")->fetchAll();

include __DIR__ . '/partials/header.php';
?>

<style>
.export-wrap   { max-width: 760px; }
.group-cards   { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; margin-bottom: 28px; }
.group-card    { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 18px 20px; cursor: pointer; transition: border-color .2s, box-shadow .2s; position: relative; }
.group-card:hover { border-color: #d97706; box-shadow: 0 4px 16px rgba(217,119,6,.12); }
.group-card.selected { border-color: #d97706; background: rgba(217,119,6,.05); box-shadow: 0 0 0 3px rgba(217,119,6,.15); }
.group-card .check { position:absolute; top:12px; right:12px; width:20px; height:20px; border-radius:50%; border:2px solid var(--border); display:flex; align-items:center; justify-content:center; font-size:11px; transition:.2s; }
.group-card.selected .check { background:#d97706; border-color:#d97706; color:#fff; }
.group-card .gname  { font-weight: 700; font-size: 14px; margin-bottom: 6px; padding-right: 28px; }
.group-card .gmeta  { font-size: 12px; color: var(--text-muted); margin-bottom: 10px; }
.group-card .gprog  { height: 5px; background: var(--border); border-radius: 3px; overflow: hidden; }
.group-card .gfill  { height: 100%; background: linear-gradient(90deg,#d97706,#f59e0b); border-radius: 3px; }
.group-card .gamts  { display: flex; justify-content: space-between; font-size: 11px; margin-top: 6px; }

.export-options { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 24px; margin-bottom: 20px; }
.opt-title { font-size: 13px; font-weight: 700; margin-bottom: 14px; color: var(--text-primary); }
.opt-row   { display: flex; gap: 12px; flex-wrap: wrap; }
.opt-btn   { flex: 1; min-width: 180px; padding: 14px 18px; border-radius: 10px; border: 1.5px solid var(--border); background: var(--surface); cursor: pointer; text-align: left; transition: border-color .2s, background .2s; font-family: inherit; }
.opt-btn:hover { border-color: #d97706; background: rgba(217,119,6,.04); }
.opt-btn.active { border-color: #d97706; background: rgba(217,119,6,.06); }
.opt-btn .ob-icon  { font-size: 24px; margin-bottom: 6px; }
.opt-btn .ob-title { font-size: 13px; font-weight: 700; color: var(--text-primary); }
.opt-btn .ob-desc  { font-size: 11px; color: var(--text-muted); margin-top: 2px; }

.dl-btn { width: 100%; padding: 14px; font-size: 15px; font-weight: 700; border-radius: 10px; border: none; background: linear-gradient(135deg,#16a34a,#22c55e); color: #fff; cursor: pointer; transition: opacity .2s; display: flex; align-items: center; justify-content: center; gap: 10px; }
.dl-btn:hover { opacity: .9; }
.dl-btn:disabled { opacity: .5; cursor: not-allowed; }

.info-box { background: rgba(217,119,6,.06); border: 1px solid rgba(217,119,6,.2); border-radius: 10px; padding: 14px 16px; font-size: 13px; line-height: 1.65; margin-bottom: 20px; }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">📤 Export Excel</h1>
        <p class="page-subtitle">Download fee collection data as a formatted spreadsheet</p>
    </div>
    <a href="<?= APP_ROOT ?>/fees/students.php" class="btn btn-ghost btn-sm">← Students</a>
</div>

<div class="export-wrap">

    <!-- Info box -->
    <div class="info-box">
        Select the groups you want to export below. Each group will appear on its own sheet in the Excel file, with student details, fees, payments, balance and status. A totals row is included at the bottom of each sheet.
    </div>

    <!-- Group selection cards -->
    <div class="opt-title">Step 1 — Choose what to export</div>
    <div class="group-cards" id="group-cards">

        <!-- All Groups card -->
        <div class="group-card selected" id="card-0" onclick="selectGroup(0)">
            <div class="check">✓</div>
            <div class="gname">📊 All Groups</div>
            <div class="gmeta">
                <?= array_sum(array_column($groupStats, 'total')) ?> students total ·
                <?= count($groupStats) ?> sheets
            </div>
            <div class="gprog">
                <?php
                    $allExp = array_sum(array_column($groupStats, 'expected'));
                    $allCol = array_sum(array_column($groupStats, 'collected'));
                    $allPct = $allExp > 0 ? min(100, ($allCol / $allExp) * 100) : 0;
                ?>
                <div class="gfill" style="width:<?= $allPct ?>%"></div>
            </div>
            <div class="gamts">
                <span style="color:#16a34a;font-weight:600">KES <?= number_format($allCol) ?> paid</span>
                <span style="color:#dc2626">KES <?= number_format(max(0,$allExp-$allCol)) ?> owed</span>
            </div>
        </div>

        <?php foreach ($groupStats as $g):
            $pct = $g['expected'] > 0 ? min(100, ($g['collected'] / $g['expected']) * 100) : 0;
        ?>
        <div class="group-card" id="card-<?= $g['group_id'] ?>" onclick="selectGroup(<?= $g['group_id'] ?>)">
            <div class="check">✓</div>
            <div class="gname"><?= htmlspecialchars($g['group_label'] ?? ($g['name'] . (($g['academic_year'] ?? '') ? ' (' . $g['academic_year'] . ')' : ''))) ?></div>
            <div class="gmeta"><?= $g['total'] ?> student<?= $g['total'] != 1 ? 's' : '' ?></div>
            <div class="gprog">
                <div class="gfill" style="width:<?= $pct ?>%"></div>
            </div>
            <div class="gamts">
                <span style="color:#16a34a;font-weight:600">KES <?= number_format($g['collected']) ?> paid</span>
                <span style="color:#dc2626">KES <?= number_format(max(0,$g['expected']-$g['collected'])) ?> owed</span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Export options -->
    <div class="export-options">
        <div class="opt-title">Step 2 — Review &amp; Download</div>

        <div id="export-summary" style="background:rgba(22,163,74,.06);border:1px solid rgba(22,163,74,.2);border-radius:8px;padding:12px 16px;margin-bottom:18px;font-size:13px">
            <strong>📊 All Groups</strong> — <?= count($groupStats) ?> sheets,
            <?= array_sum(array_column($groupStats, 'total')) ?> students,
            KES <?= number_format(array_sum(array_column($groupStats, 'collected'))) ?> collected
        </div>

        <button class="dl-btn" id="dl-btn" onclick="triggerDownload()">
            <span>⬇</span> <span id="dl-label">Download Excel — All Groups</span>
        </button>

        <div style="margin-top:12px;font-size:11px;color:var(--text-muted);text-align:center">
            File will be named e.g. <code>fees-all-groups-<?= date('Y-m-d') ?>.xlsx</code>
        </div>
    </div>

</div>

<script>
let selectedGroup = 0;

const groupMeta = {
    0: {
        label: 'All Groups',
        sheets: <?= count($groupStats) ?>,
        students: <?= array_sum(array_column($groupStats, 'total')) ?>,
        collected: <?= array_sum(array_column($groupStats, 'collected')) ?>,
    },
    <?php foreach ($groupStats as $g): ?>
    <?= $g['group_id'] ?>: {
        label: <?= json_encode($g['group_label'] ?? ($g['name'] . (($g['academic_year'] ?? '') ? ' (' . $g['academic_year'] . ')' : ''))) ?>,
        sheets: 1,
        students: <?= $g['total'] ?>,
        collected: <?= $g['collected'] ?>,
    },
    <?php endforeach; ?>
};

function selectGroup(id) {
    // Deselect all
    document.querySelectorAll('.group-card').forEach(c => c.classList.remove('selected'));
    document.getElementById('card-' + id).classList.add('selected');
    selectedGroup = id;

    const m = groupMeta[id];
    const sheetStr = m.sheets > 1 ? m.sheets + ' sheets' : '1 sheet';

    document.getElementById('export-summary').innerHTML =
        '<strong>📊 ' + m.label + '</strong> — ' + sheetStr + ', ' +
        m.students + ' student' + (m.students !== 1 ? 's' : '') + ', ' +
        'KES ' + m.collected.toLocaleString() + ' collected';

    const slug = id === 0 ? 'all-groups' : m.label.toLowerCase().replace(/[^a-z0-9]+/g, '-');
    document.getElementById('dl-label').textContent = 'Download Excel — ' + m.label;
    document.querySelector('.dl-btn').nextElementSibling.querySelector('code').textContent =
        'fees-' + slug + '-<?= date('Y-m-d') ?>.xlsx';
}

function triggerDownload() {
    const btn = document.getElementById('dl-btn');
    btn.disabled = true;
    btn.innerHTML = '<span>⏳</span> <span>Generating file…</span>';
    window.location.href = '<?= APP_ROOT ?>/fees/export.php?download=1&group=' + selectedGroup;
    setTimeout(() => {
        btn.disabled = false;
        btn.innerHTML = '<span>⬇</span> <span id="dl-label">Download Excel — ' + groupMeta[selectedGroup].label + '</span>';
    }, 3000);
}
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
