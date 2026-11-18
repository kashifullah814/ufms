<?php
/**
 * Budget Utilization: active (Senate-approved) budget vs actual (posted vouchers) har head ke liye.
 * Payment heads: % used, 80% par warning, 100% par exceeded (bina budget ke kharch = No budget).
 * Receipt heads: sirf % realized. Group subtotals, print + PDF. Permission: accounts
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
$user = require_permission('accounts');

$today = date('Y-m-d');
$fy_now = fiscal_year_of($today);
$years = array_map('intval', array_column(db_select("SELECT DISTINCT fiscal_year FROM budgets UNION SELECT DISTINCT fiscal_year FROM vouchers ORDER BY 1 DESC")->fetch_all(MYSQLI_ASSOC), 'fiscal_year'));
if (!in_array($fy_now, $years, true)) {
    array_unshift($years, $fy_now);
}
rsort($years);
$fy = (int)($_GET['fy'] ?? $fy_now);
if (!in_array($fy, $years, true)) $fy = $fy_now;
$show_all = !empty($_GET['all']);

$u = budget_utilization($fy);
$budget = $u['budget'];
list($fy_from, $fy_to) = fiscal_year_range($fy);
$as_on = min($today, $fy_to);

$sections = ['Income' => [], 'Expense' => []];
$sum = ['Income' => ['budget' => 0, 'actual' => 0], 'Expense' => ['budget' => 0, 'actual' => 0]];
$count = ['warn' => 0, 'exceeded' => 0, 'nobudget' => 0];
foreach ($u['rows'] as $r) {
    if (!$show_all && $r['budget'] === 0 && $r['actual'] === 0) continue;
    $sections[$r['type']][] = $r;
    if ((int)$r['is_group'] === 0) {
        $sum[$r['type']]['budget'] += $r['budget'];
        $sum[$r['type']]['actual'] += $r['actual'];
        if ($r['type'] === 'Expense') {
            if ($r['level'] === 'warn') $count['warn']++;
            if ($r['level'] === 'exceeded') $count[$r['budget'] > 0 ? 'exceeded' : 'nobudget']++;
        }
    }
}
$pct = function ($a, $b) { return $b > 0 ? round($a * 100 / $b, 1) : null; };
$bar = function ($p, $cls) {
    $w = $p === null ? 0 : min(100, max(0, $p));
    return '<div class="progress" style="height:6px;min-width:70px"><div class="progress-bar ' . $cls . '" style="width:' . $w . '%"></div></div>';
};

$page_title = 'Budget Utilization';
$nav_title = 'Budget Utilization';
$nav_icon = 'fa-chart-pie';
$nav_back = 'dashboard';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/topnav.php';
?>
<style>
    #util td.num, #util th.num { text-align: right; white-space: nowrap; }
    #util tr.grp td { background: #f1f5f9; font-weight: 600; }
    #util table { font-size: .85rem; }
    @media print { #util { box-shadow: none !important; border: 0 !important; padding: 0 !important; } .progress { display: none; } }
</style>
<div class="container pb-5">
    <div class="no-print">
        <?php echo flash_render(); ?>
        <form method="GET" class="card p-3 border-0 shadow-sm rounded-4 mb-4 d-flex flex-row flex-wrap gap-2 align-items-center">
            <label class="small fw-semibold">Fiscal Year</label>
            <select name="fy" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
                <?php foreach ($years as $y): ?><option value="<?php echo $y; ?>" <?php echo $y === $fy ? 'selected' : ''; ?>>FY <?php echo e(fiscal_year_label($y)); ?></option><?php endforeach; ?>
            </select>
            <label class="small ms-2"><input type="checkbox" name="all" value="1" <?php echo $show_all ? 'checked' : ''; ?> onchange="this.form.submit()"> Show heads with no budget and no activity</label>
            <div class="ms-auto d-flex gap-2">
                <a href="budgets.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-clipboard-list me-1"></i>Budgets</a>
                <button type="button" onclick="window.print()" class="btn btn-primary btn-sm rounded-pill"><i class="fas fa-print me-1"></i>Print</button>
                <button type="button" onclick="downloadPdf()" class="btn btn-danger btn-sm rounded-pill"><i class="fas fa-file-pdf me-1"></i>PDF</button>
            </div>
        </form>
        <?php if (!$budget): ?>
            <div class="alert alert-warning"><i class="fas fa-exclamation-circle me-2"></i>There is no Senate-approved budget for FY <?php echo e(fiscal_year_label($fy)); ?> yet. Actual figures are shown against a budget of zero. <a href="budgets.php">Prepare or approve a budget</a>.</div>
        <?php endif; ?>
        <div class="row g-3 mb-4">
            <?php foreach ([
                ['Receipts realized', $sum['Income']['actual'], $sum['Income']['budget'], 'success'],
                ['Payments spent', $sum['Expense']['actual'], $sum['Expense']['budget'], 'danger'],
            ] as $c): $p = $pct($c[1], $c[2]); ?>
            <div class="col-md-6 col-lg-3"><div class="card stat-card p-3 border-start border-<?php echo $c[3]; ?> border-5 h-100">
                <small class="text-muted fw-bold text-uppercase"><?php echo e($c[0]); ?></small>
                <h5 class="fw-bold mb-0 text-<?php echo $c[3]; ?>">PKR&nbsp;<?php echo money($c[1] / 100, 0); ?></h5>
                <small class="text-muted">of PKR&nbsp;<?php echo money($c[2] / 100, 0); ?> budget<?php echo $p !== null ? ' (' . $p . '%)' : ''; ?></small>
            </div></div>
            <?php endforeach; ?>
            <div class="col-md-6 col-lg-3"><div class="card stat-card p-3 border-start border-warning border-5 h-100"><small class="text-muted fw-bold">WARNING (&ge; <?php echo BUDGET_WARN_PCT; ?>%)</small><h4 class="fw-bold text-warning mb-0"><?php echo $count['warn']; ?></h4><small class="text-muted">payment heads</small></div></div>
            <div class="col-md-6 col-lg-3"><div class="card stat-card p-3 border-start border-danger border-5 h-100"><small class="text-muted fw-bold">EXCEEDED (&ge; <?php echo BUDGET_EXCEED_PCT; ?>%)</small><h4 class="fw-bold text-danger mb-0"><?php echo $count['exceeded'] + $count['nobudget']; ?></h4><small class="text-muted"><?php echo $count['nobudget'] ? $count['nobudget'] . ' spent without a budget' : 'payment heads'; ?></small></div></div>
        </div>
    </div>

    <div id="util" class="bg-white p-4 shadow-sm border rounded">
        <div class="text-center border-bottom pb-3 mb-3">
            <?php echo doc_logo(); ?><h4 class="fw-bold mb-0 doc-title">UNIVERSITY OF SCIENCE &amp; TECHNOLOGY BANNU</h4>
            <p class="text-muted mb-1">Finance Department</p>
            <h5 class="fw-bold mb-1">BUDGET UTILIZATION REPORT - FY <?php echo e(fiscal_year_label($fy)); ?></h5>
            <div class="small"><?php echo $budget ? 'Budget: <strong>' . e($budget['title']) . '</strong> (' . e($budget['budget_type']) . ', approved by Senate)' : 'No approved budget'; ?> &middot; actual figures from posted vouchers up to <strong><?php echo e(fmt_date($as_on)); ?></strong> &middot; PKR</div>
        </div>

        <?php foreach (['Income' => 'RECEIPTS', 'Expense' => 'PAYMENTS'] as $type => $label): $isExp = $type === 'Expense'; ?>
        <h6 class="fw-bold mt-2"><?php echo $label; ?></h6>
        <div class="table-responsive mb-3">
            <table class="table table-bordered table-sm align-middle mb-0 sticky-first">
                <thead class="table-light"><tr><th style="width:8%">Code</th><th>Head</th><th class="num">Budget</th><th class="num"><?php echo $isExp ? 'Actual Spent' : 'Actual Realized'; ?></th>
                    <th class="num"><?php echo $isExp ? 'Remaining' : 'Short / (Excess)'; ?></th><th class="num" style="width:13%"><?php echo $isExp ? '% Used' : '% Realized'; ?></th><?php if ($isExp): ?><th style="width:11%">Status</th><?php endif; ?></tr></thead>
                <tbody>
                <?php if (!$sections[$type]): ?><tr><td colspan="<?php echo $isExp ? 7 : 6; ?>" class="text-center text-muted py-3">No budget or activity.</td></tr><?php endif; ?>
                <?php foreach ($sections[$type] as $r): $grp = (int)$r['is_group'] === 1;
                    $cls = $r['level'] === 'exceeded' ? 'bg-danger' : ($r['level'] === 'warn' ? 'bg-warning' : ($isExp ? 'bg-primary' : 'bg-success')); ?>
                    <tr class="<?php echo $grp ? 'grp' : ''; ?> <?php echo !$grp && $r['level'] === 'exceeded' ? 'table-danger' : (!$grp && $r['level'] === 'warn' ? 'table-warning' : ''); ?>">
                        <td class="font-monospace"><?php echo e($r['code']); ?></td>
                        <td style="padding-left:<?php echo 0.4 + 1.2 * max(0, (int)$r['depth'] - 1); ?>rem"><?php echo e($r['name']); ?></td>
                        <td class="num"><?php echo money($r['budget'] / 100); ?></td>
                        <td class="num"><?php echo money($r['actual'] / 100); ?></td>
                        <?php if ($r['budget'] <= 0): // is head ka koi approved budget nahi: compare karne ko kuch nahi ?>
                        <td class="num text-muted">&ndash;</td>
                        <td class="num text-muted">&ndash;</td>
                        <?php else: ?>
                        <td class="num <?php echo $r['remaining'] < 0 ? 'text-danger' : ''; ?>"><?php echo $r['remaining'] < 0 ? '(' . money(-$r['remaining'] / 100) . ')' : money($r['remaining'] / 100); ?></td>
                        <td class="num"><?php echo $r['pct'] === null ? '&ndash;' : $r['pct'] . '%'; ?><?php echo $grp ? '' : $bar($r['pct'] ?? 0, $cls); ?></td>
                        <?php endif; ?>
                        <?php if ($isExp): ?><td><?php if (!$grp): ?>
                            <?php if ($r['level'] === 'exceeded' && $r['budget'] === 0): ?><span class="badge bg-danger">No budget</span>
                            <?php elseif ($r['level'] === 'exceeded'): ?><span class="badge bg-danger">Exceeded</span>
                            <?php elseif ($r['level'] === 'warn'): ?><span class="badge bg-warning text-dark">Warning</span>
                            <?php else: ?><span class="badge bg-success">OK</span><?php endif; ?>
                        <?php endif; ?></td><?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr class="fw-bold table-light"><td colspan="2" class="text-end">Total <?php echo ucfirst(strtolower($label)); ?></td>
                    <td class="num"><?php echo money($sum[$type]['budget'] / 100); ?></td><td class="num"><?php echo money($sum[$type]['actual'] / 100); ?></td>
                    <?php if ($sum[$type]['budget'] <= 0): ?><td class="num text-muted">&ndash;</td><td class="num text-muted">&ndash;</td>
                    <?php else: ?><td class="num"><?php $rem = $sum[$type]['budget'] - $sum[$type]['actual']; echo $rem < 0 ? '(' . money(-$rem / 100) . ')' : money($rem / 100); ?></td>
                    <td class="num"><?php $p = $pct($sum[$type]['actual'], $sum[$type]['budget']); echo $p === null ? '&ndash;' : $p . '%'; ?></td><?php endif; ?><?php if ($isExp): ?><td></td><?php endif; ?></tr></tfoot>
            </table>
        </div>
        <?php endforeach; ?>
        <p class="small text-muted mb-0">Payment heads: <span class="badge bg-warning text-dark">Warning</span> at <?php echo BUDGET_WARN_PCT; ?>% or more of the budget, <span class="badge bg-danger">Exceeded</span> at <?php echo BUDGET_EXCEED_PCT; ?>% or more; "No budget" = spent on a head with no budget. Receipt heads show the percentage realized only. Cancelled vouchers are not counted.</p>
        <p class="text-center text-muted small mt-3 mb-0">Computer generated on <?php echo e(fmt_datetime(date('Y-m-d H:i:s'))); ?> by <?php echo e($user['full_name']); ?>.</p>
    </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
function downloadPdf() {
    html2pdf().set({ margin: 8, filename: <?php echo json_encode('Budget_Utilization_FY_' . fiscal_year_label($fy) . '.pdf'); ?>, html2canvas: { scale: 2 },
        jsPDF: { format: 'a4', orientation: 'landscape' }, pagebreak: { mode: ['css', 'legacy'] } }).from(document.getElementById('util')).save();
}
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
