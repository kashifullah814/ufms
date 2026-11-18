<?php
/**
 * Trial Balance: date range ke liye har head ka opening, period debit/credit aur closing balance.
 * Group heads subtotal dikhate hain; totals hamesha match karte hain (warna red warning).
 * Print + PDF. Permission: accounts
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
$user = require_permission('accounts');

$today = date('Y-m-d');
$fy_now = fiscal_year_of($today);
$from = valid_date($_GET['from'] ?? '') ? $_GET['from'] : fiscal_year_range($fy_now)[0];
$to   = valid_date($_GET['to'] ?? '') ? $_GET['to'] : $today;
$show_all = !empty($_GET['all']);
$error = '';
if ($from > $to) {
    $error = '"From" date must be on or before the "To" date.';
    list($from, $to) = [$to, $from];
}

$tb = trial_balance($from, $to);
$t = $tb['totals'];
$balanced = $t['open_dr'] === $t['open_cr'] && $t['dr'] === $t['cr'] && $t['close_dr'] === $t['close_cr'];

// Zero heads chhupane ke liye: group sirf tab dikhe jab uske neeche kuch dikh raha ho
$visible = [];
$has_activity = function ($r) { return $r['open'] !== 0 || $r['dr'] !== 0 || $r['cr'] !== 0; };
$byId = [];
foreach ($tb['rows'] as $r) {
    if ($r['id']) $byId[(int)$r['id']] = $r;
}
foreach ($tb['rows'] as $r) {
    if ((int)$r['is_group'] === 0 && ($show_all || $has_activity($r))) {
        $visible[$r['id'] ? 'a' . $r['id'] : 'virtual'] = true;
        for ($p = (int)$r['parent_id']; $p && isset($byId[$p]); $p = (int)$byId[$p]['parent_id']) {
            $visible['a' . $p] = true;
        }
    }
}
if ($show_all) {
    foreach ($tb['rows'] as $r) {
        if ($r['id']) $visible['a' . $r['id']] = true;
    }
}

$presets = [
    'This FY (' . fiscal_year_label($fy_now) . ')' => [fiscal_year_range($fy_now)[0], $today],
    'Last FY (' . fiscal_year_label($fy_now - 1) . ')' => fiscal_year_range($fy_now - 1),
    'This Month' => [date('Y-m-01'), $today],
];
$cell = function ($paisa) { return $paisa ? money($paisa / 100) : ''; };

$page_title = 'Trial Balance';
$nav_title = 'Trial Balance';
$nav_icon = 'fa-balance-scale';
$nav_back = 'dashboard';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/topnav.php';
?>
<style>
    #tb table { font-size: .85rem; }
    #tb td.num, #tb th.num { text-align: right; white-space: nowrap; }
    #tb tr.grp td { background: #f1f5f9; font-weight: 600; }
    @media print { .container { max-width: 100% !important; } #tb { box-shadow: none !important; border: 0 !important; padding: 0 !important; } #tb table { font-size: 9pt; } }
</style>
<div class="container pb-5">
    <div class="no-print">
        <?php echo flash_render(); ?>
        <?php if ($error): ?><div class="alert alert-warning py-2"><?php echo e($error); ?> The dates were swapped.</div><?php endif; ?>
        <div class="card p-3 border-0 shadow-sm rounded-4 mb-4">
            <form method="GET" action="" class="row g-2 align-items-end">
                <div class="col-6 col-md-3 col-xl-2"><label class="form-label small fw-semibold mb-1">From</label><input type="date" name="from" class="form-control form-control-sm" value="<?php echo e($from); ?>"></div>
                <div class="col-6 col-md-3 col-xl-2"><label class="form-label small fw-semibold mb-1">To</label><input type="date" name="to" class="form-control form-control-sm" value="<?php echo e($to); ?>"></div>
                <div class="col-8 col-md-4 col-xl-2"><label class="small"><input type="checkbox" name="all" value="1" <?php echo $show_all ? 'checked' : ''; ?>> Show zero-balance heads</label></div>
                <div class="col-4 col-md-2 col-xl-1"><button class="btn btn-primary btn-sm w-100"><i class="fas fa-sync me-1"></i>Show</button></div>
                <div class="col-12 col-xl-5 d-flex gap-2 flex-wrap justify-content-xl-end">
                    <?php foreach ($presets as $label => $range): ?>
                        <a class="btn btn-outline-secondary btn-sm" href="?<?php echo e(http_build_query(['from' => $range[0], 'to' => $range[1], 'all' => $show_all ? 1 : null])); ?>"><?php echo e($label); ?></a>
                    <?php endforeach; ?>
                    <button type="button" onclick="window.print()" class="btn btn-primary btn-sm rounded-pill"><i class="fas fa-print me-1"></i>Print</button>
                    <button type="button" onclick="downloadPdf()" class="btn btn-danger btn-sm rounded-pill"><i class="fas fa-file-pdf me-1"></i>PDF</button>
                </div>
            </form>
        </div>
    </div>

    <div id="tb" class="bg-white p-4 shadow-sm border rounded">
        <div class="text-center border-bottom pb-3 mb-3">
            <?php echo doc_logo(); ?><h4 class="fw-bold mb-0 doc-title">UNIVERSITY OF SCIENCE &amp; TECHNOLOGY BANNU</h4>
            <p class="text-muted mb-1">Finance Department</p>
            <h5 class="fw-bold mb-1">TRIAL BALANCE</h5>
            <div class="small">For the period <strong><?php echo e(fmt_date($from)); ?></strong> to <strong><?php echo e(fmt_date($to)); ?></strong> (amounts in PKR)</div>
        </div>
        <div class="text-center mb-3">
            <?php if ($balanced): ?>
                <span class="badge bg-success px-3 py-2"><i class="fas fa-check-circle me-1"></i>Balanced: total debit = total credit</span>
            <?php else: ?>
                <span class="badge bg-danger px-3 py-2"><i class="fas fa-exclamation-triangle me-1"></i>NOT balanced - please report this to the administrator</span>
            <?php endif; ?>
        </div>
        <div class="table-responsive">
            <table class="table table-bordered table-sm align-middle mb-2 sticky-first">
                <thead class="table-light">
                    <tr><th rowspan="2" style="width:8%">Code</th><th rowspan="2">Account Head</th><th colspan="2" class="text-center">Opening Balance</th><th colspan="2" class="text-center">During Period</th><th colspan="2" class="text-center">Closing Balance</th></tr>
                    <tr><th class="num">Debit</th><th class="num">Credit</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Debit</th><th class="num">Credit</th></tr>
                </thead>
                <tbody>
                <?php $shown = 0; foreach ($tb['rows'] as $r):
                    $key = $r['id'] ? 'a' . $r['id'] : 'virtual';
                    if (empty($visible[$key])) continue;
                    $shown++;
                    $group = (int)$r['is_group'] === 1;
                    $pad = 0.4 + 1.2 * (int)$r['depth']; ?>
                    <?php if ($group): ?>
                        <tr class="grp">
                            <td class="font-monospace"><?php echo e($r['code']); ?></td>
                            <td style="padding-left:<?php echo $pad; ?>rem"><?php echo e($r['name']); ?></td>
                            <td class="num"><?php echo $cell($r['g_open_dr']); ?></td><td class="num"><?php echo $cell($r['g_open_cr']); ?></td>
                            <td class="num"><?php echo $cell($r['g_dr']); ?></td><td class="num"><?php echo $cell($r['g_cr']); ?></td>
                            <td class="num"><?php echo $cell($r['g_close_dr']); ?></td><td class="num"><?php echo $cell($r['g_close_cr']); ?></td>
                        </tr>
                    <?php else: ?>
                        <tr class="<?php echo $r['virtual'] ? 'fst-italic' : ''; ?>">
                            <td class="font-monospace"><?php echo e($r['code']); ?></td>
                            <td style="padding-left:<?php echo $pad; ?>rem"><?php echo e($r['name']); ?><?php echo ($r['status'] ?? 'Active') === 'Inactive' ? ' <small class="text-muted">(inactive)</small>' : ''; ?></td>
                            <td class="num"><?php echo $cell(max($r['open'], 0)); ?></td><td class="num"><?php echo $cell(max(-$r['open'], 0)); ?></td>
                            <td class="num"><?php echo $cell($r['dr']); ?></td><td class="num"><?php echo $cell($r['cr']); ?></td>
                            <td class="num"><?php echo $cell(max($r['close'], 0)); ?></td><td class="num"><?php echo $cell(max(-$r['close'], 0)); ?></td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php if (!$shown): ?><tr><td colspan="8" class="text-center text-muted py-4">No posted vouchers up to this date.</td></tr><?php endif; ?>
                </tbody>
                <tfoot>
                    <tr class="fw-bold table-dark">
                        <td colspan="2" class="text-end">TOTAL</td>
                        <td class="num"><?php echo money($t['open_dr'] / 100); ?></td><td class="num"><?php echo money($t['open_cr'] / 100); ?></td>
                        <td class="num"><?php echo money($t['dr'] / 100); ?></td><td class="num"><?php echo money($t['cr'] / 100); ?></td>
                        <td class="num"><?php echo money($t['close_dr'] / 100); ?></td><td class="num"><?php echo money($t['close_cr'] / 100); ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <p class="small text-muted mb-0">Only posted vouchers are included (cancelled vouchers are ignored). Income and expense heads start from zero at the beginning of each fiscal year (July-June); the surplus or deficit of earlier years is shown as "Accumulated Fund" under Equity.</p>
        <div class="row mt-5 pt-3 text-center small">
            <div class="col-4"><p class="border-top pt-2 mx-3 mb-0">Prepared By</p><span class="text-muted"><?php echo e($user['full_name']); ?></span></div>
            <div class="col-4"><p class="border-top pt-2 mx-3 mb-0">Accounts Officer</p></div>
            <div class="col-4"><p class="border-top pt-2 mx-3 mb-0">Treasurer</p></div>
        </div>
        <p class="text-center text-muted small mt-3 mb-0">Computer generated on <?php echo e(fmt_datetime(date('Y-m-d H:i:s'))); ?>.</p>
    </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
function downloadPdf() {
    html2pdf().set({ margin: 8, filename: <?php echo json_encode('Trial_Balance_' . $from . '_to_' . $to . '.pdf'); ?>, html2canvas: { scale: 2 },
        jsPDF: { format: 'a4', orientation: 'landscape' }, pagebreak: { mode: ['css', 'legacy'] } })
        .from(document.getElementById('tb')).save();
}
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
