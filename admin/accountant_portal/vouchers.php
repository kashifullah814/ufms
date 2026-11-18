<?php
/**
 * Voucher Generator: RV / PV / JV ki list (filters + pagination).
 * Admin manual Journal Voucher bana sakta hai - hamesha balanced (post_voucher() check karta hai).
 * Permission: accounts
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
$user = require_permission('accounts');
$is_admin = $user['role'] === 'Admin';
$today = date('Y-m-d');

// ---------- MANUAL JOURNAL VOUCHER (sirf Admin) ----------
if (isset($_POST['save_jv'])) {
    if (!$is_admin) {
        flash('error', 'Only the Admin can create manual journal vouchers.');
        redirect_self();
    }
    $date = (string)($_POST['voucher_date'] ?? '');
    $narration = (string)($_POST['narration'] ?? '');
    $lines = [];
    foreach ((array)($_POST['line_account'] ?? []) as $i => $acc) {
        $dr = trim((string)($_POST['line_debit'][$i] ?? ''));
        $cr = trim((string)($_POST['line_credit'][$i] ?? ''));
        if ((int)$acc === 0 && $dr === '' && $cr === '') {
            continue; // khaali row
        }
        $lines[] = ['account_id' => (int)$acc, 'debit' => is_numeric($dr) ? (float)$dr : 0, 'credit' => is_numeric($cr) ? (float)$cr : 0,
                    'narration' => (string)($_POST['line_narration'][$i] ?? '')];
    }
    if (valid_date($date) && $date > $today) {
        flash('error', 'The voucher date cannot be in the future.');
        redirect_self();
    }
    db()->begin_transaction();
    try {
        $v = post_voucher('JV', $date, $narration, $lines, 'manual');
        db()->commit();
    } catch (VoucherException $ex) {
        db()->rollback();
        flash('error', $ex->getMessage());
        redirect_self();
    }
    audit('VOUCHER_POSTED', "Manual {$v['voucher_no']}: " . $narration);
    flash('success', "Journal voucher {$v['voucher_no']} posted.");
    redirect(url('admin/accountant_portal/print_voucher.php?id=' . $v['id']));
}

// ---------- FILTERS ----------
$f_type   = isset(VOUCHER_TYPES[$_GET['type'] ?? '']) ? $_GET['type'] : '';
$f_status = in_array($_GET['status'] ?? '', ['Posted', 'Cancelled'], true) ? $_GET['status'] : '';
$f_source = isset(VOUCHER_SOURCES[$_GET['source'] ?? '']) ? $_GET['source'] : '';
$f_fy     = ctype_digit((string)($_GET['fy'] ?? '')) ? (int)$_GET['fy'] : 0;
$f_from   = valid_date($_GET['from'] ?? '') ? $_GET['from'] : '';
$f_to     = valid_date($_GET['to'] ?? '') ? $_GET['to'] : '';
$search   = trim((string)($_GET['q'] ?? ''));
// From > To: tareekhen badal do aur bata do (Trial Balance / Expenses jaisa)
$date_note = '';
if ($f_from !== '' && $f_to !== '' && $f_from > $f_to) {
    list($f_from, $f_to) = [$f_to, $f_from];
    $date_note = '"From" date must be on or before the "To" date. The dates were swapped.';
}

$where = 'WHERE 1 = 1';
$params = [];
if ($f_type !== '')   { $where .= ' AND v.voucher_type = ?'; $params[] = $f_type; }
if ($f_status !== '') { $where .= ' AND v.status = ?'; $params[] = $f_status; }
if ($f_source !== '') { $where .= ' AND v.source_type = ?'; $params[] = $f_source; }
if ($f_fy > 0)        { $where .= ' AND v.fiscal_year = ?'; $params[] = $f_fy; }
if ($f_from !== '')   { $where .= ' AND v.voucher_date >= ?'; $params[] = $f_from; }
if ($f_to !== '')     { $where .= ' AND v.voucher_date <= ?'; $params[] = $f_to; }
if ($search !== '')   { $where .= ' AND (v.voucher_no LIKE ? OR v.narration LIKE ?)'; $params[] = "%{$search}%"; $params[] = "%{$search}%"; }

$limit = 20;
$stats = db_row("SELECT COUNT(*) n, COALESCE(SUM(CASE WHEN v.status = 'Posted' THEN v.total_amount END), 0) posted_total FROM vouchers v $where", $params);
$total_rows = (int)$stats['n'];
$total_pages = max(1, (int)ceil($total_rows / $limit));
$page = min(max(1, (int)($_GET['page'] ?? 1)), $total_pages);
$offset = ($page - 1) * $limit;
$result = db_select("SELECT v.*, u.full_name AS created_by_name FROM vouchers v LEFT JOIN users u ON u.id = v.created_by
                     $where ORDER BY v.voucher_date DESC, v.id DESC LIMIT $limit OFFSET $offset", $params);

$filters = ['type' => $f_type, 'status' => $f_status, 'source' => $f_source, 'fy' => $f_fy ?: '', 'from' => $f_from, 'to' => $f_to, 'q' => $search];
$qs = function ($p) use ($filters) { return '?' . http_build_query(array_merge($filters, ['page' => $p])); };
$has_filter = implode('', $filters) !== '';

$fiscal_years = array_map('intval', array_column(db_select("SELECT DISTINCT fiscal_year FROM vouchers ORDER BY fiscal_year DESC")->fetch_all(MYSQLI_ASSOC), 'fiscal_year'));
$current_fy = fiscal_year_of($today);
if (!in_array($current_fy, $fiscal_years, true)) {
    array_unshift($fiscal_years, $current_fy);
}
$accounts = $is_admin ? postable_accounts() : [];

$page_title = 'Voucher Generator';
$nav_title = 'Voucher Generator';
$nav_icon = 'fa-receipt';
$nav_back = 'dashboard';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/topnav.php';
?>
<div class="container pb-5">
    <?php echo flash_render(); ?>
    <?php if ($date_note !== ''): ?><div class="alert alert-warning py-2"><?php echo e($date_note); ?></div><?php endif; ?>
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h5 class="fw-bold mb-0"><i class="fas fa-receipt me-2 text-primary"></i>Voucher Generator
            <small class="text-muted fw-normal">(<?php echo number_format($total_rows); ?> found &middot; posted total PKR&nbsp;<?php echo money($stats['posted_total']); ?>)</small></h5>
        <?php if ($is_admin): ?>
            <button class="btn btn-brand rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#jvModal"><i class="fas fa-plus me-1"></i> New Journal Voucher</button>
        <?php endif; ?>
    </div>

    <div class="card p-3 border-0 shadow-sm rounded-4 mb-4">
        <form method="GET" action="" class="row g-2 align-items-end">
            <div class="col-12 col-lg-4"><label class="form-label small fw-semibold mb-1">Search</label><input type="text" name="q" maxlength="100" class="form-control form-control-sm" placeholder="Voucher no. or narration" value="<?php echo e($search); ?>"></div>
            <div class="col-6 col-md-3 col-lg-2"><label class="form-label small fw-semibold mb-1">Type</label>
                <select name="type" class="form-select form-select-sm"><option value="">All</option>
                    <?php foreach (VOUCHER_TYPES as $k => $label): ?><option value="<?php echo $k; ?>" <?php echo $f_type === $k ? 'selected' : ''; ?>><?php echo $k; ?></option><?php endforeach; ?>
                </select></div>
            <div class="col-6 col-md-3 col-lg-2"><label class="form-label small fw-semibold mb-1">Source</label>
                <select name="source" class="form-select form-select-sm"><option value="">All</option>
                    <?php foreach (VOUCHER_SOURCES as $k => $label): ?><option value="<?php echo $k; ?>" <?php echo $f_source === $k ? 'selected' : ''; ?>><?php echo e($label); ?></option><?php endforeach; ?>
                </select></div>
            <div class="col-6 col-md-3 col-lg-2"><label class="form-label small fw-semibold mb-1">Status</label>
                <select name="status" class="form-select form-select-sm"><option value="">All</option>
                    <?php foreach (['Posted', 'Cancelled'] as $s): ?><option value="<?php echo $s; ?>" <?php echo $f_status === $s ? 'selected' : ''; ?>><?php echo $s; ?></option><?php endforeach; ?>
                </select></div>
            <div class="col-6 col-md-3 col-lg-2"><label class="form-label small fw-semibold mb-1">Fiscal Year</label>
                <select name="fy" class="form-select form-select-sm"><option value="">All</option>
                    <?php foreach ($fiscal_years as $fy): ?><option value="<?php echo $fy; ?>" <?php echo $f_fy === $fy ? 'selected' : ''; ?>><?php echo e(fiscal_year_label($fy)); ?></option><?php endforeach; ?>
                </select></div>
            <div class="col-6 col-md-3 col-lg-2"><label class="form-label small fw-semibold mb-1">From</label><input type="date" name="from" class="form-control form-control-sm" value="<?php echo e($f_from); ?>"></div>
            <div class="col-6 col-md-3 col-lg-2"><label class="form-label small fw-semibold mb-1">To</label><input type="date" name="to" class="form-control form-control-sm" value="<?php echo e($f_to); ?>"></div>
            <div class="col-12 col-md-6 col-lg-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fas fa-filter me-1"></i> Filter</button>
                <?php if ($has_filter): ?><a href="?" class="btn btn-outline-secondary btn-sm" title="Reset"><i class="fas fa-undo"></i></a><?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card table-card overflow-hidden mb-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 sticky-first hide-xs-4 hide-xs-5">
                <thead class="table-light"><tr><th class="ps-4">Voucher No</th><th>Date</th><th>Type</th><th>Narration</th><th>Source</th><th class="text-end">Amount (PKR)</th><th>Status</th><th class="text-center">Actions</th></tr></thead>
                <tbody>
                <?php if ($result->num_rows > 0): while ($row = $result->fetch_assoc()): $cancelled = $row['status'] === 'Cancelled'; ?>
                    <tr class="<?php echo $cancelled ? 'text-muted' : ''; ?>">
                        <td class="ps-4 fw-bold font-monospace text-nowrap <?php echo $cancelled ? 'text-decoration-line-through' : ''; ?>"><?php echo e($row['voucher_no']); ?></td>
                        <td class="text-nowrap"><?php echo e(fmt_date($row['voucher_date'])); ?></td>
                        <td><span class="badge <?php echo voucher_badge_class($row['voucher_type']); ?>"><?php echo e($row['voucher_type']); ?></span></td>
                        <td class="small cell-wrap"><?php echo e($row['narration']); ?><?php if ($row['created_by_name']): ?><br><span class="text-muted">by <?php echo e($row['created_by_name']); ?></span><?php endif; ?></td>
                        <td class="small"><?php echo e(VOUCHER_SOURCES[$row['source_type']] ?? $row['source_type']); ?></td>
                        <td class="text-end fw-bold text-nowrap"><?php echo money($row['total_amount']); ?></td>
                        <td><span class="badge rounded-pill <?php echo $cancelled ? 'bg-secondary' : 'bg-success'; ?>"><?php echo e($row['status']); ?></span></td>
                        <td class="text-center"><a href="print_voucher.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3"><i class="fas fa-print me-1"></i>View</a></td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr><td colspan="8" class="text-center py-5 text-muted">No vouchers found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($total_pages > 1): ?>
    <nav><ul class="pagination justify-content-center">
        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo e($qs($page - 1)); ?>">Previous</a></li>
        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <li class="page-item <?php echo $page === $i ? 'active' : ''; ?>"><a class="page-link" href="<?php echo e($qs($i)); ?>"><?php echo $i; ?></a></li>
        <?php endfor; ?>
        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo e($qs($page + 1)); ?>">Next</a></li>
    </ul></nav>
    <?php endif; ?>
</div>

<?php if ($is_admin): ?>
<div class="modal fade" id="jvModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content rounded-4 border-0 shadow">
        <div class="modal-header border-0"><h5 class="modal-title fw-bold"><i class="fas fa-book me-2 text-primary"></i>New Journal Voucher (JV)</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <form action="" method="POST" id="jvForm">
            <?php echo csrf_field(); ?>
            <div class="modal-body">
                <div class="row g-3 mb-3">
                    <div class="col-md-4 col-lg-3"><label class="form-label fw-semibold">Date <span class="req">*</span></label><input type="date" name="voucher_date" class="form-control" value="<?php echo $today; ?>" max="<?php echo $today; ?>" required></div>
                    <div class="col-md-8 col-lg-9"><label class="form-label fw-semibold">Narration <span class="req">*</span></label><input type="text" name="narration" class="form-control" maxlength="255" placeholder="e.g. Correction of opening balance" required></div>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle" id="jvLines" style="min-width:620px">
                        <thead class="table-light"><tr><th style="width:38%">Account Head</th><th>Line Narration</th><th style="width:15%" class="text-end">Debit</th><th style="width:15%" class="text-end">Credit</th><th style="width:40px"></th></tr></thead>
                        <tbody></tbody>
                        <tfoot>
                            <tr class="fw-bold"><td><button type="button" class="btn btn-sm btn-outline-primary rounded-pill" id="jvAdd"><i class="fas fa-plus me-1"></i>Add line</button></td>
                                <td class="text-end">Total</td><td class="text-end" id="jvDr">0.00</td><td class="text-end" id="jvCr">0.00</td><td></td></tr>
                        </tfoot>
                    </table>
                </div>
                <div id="jvStatus" class="alert alert-warning py-2 small mb-0">Enter at least one debit and one credit line.</div>
            </div>
            <div class="modal-footer border-0"><button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button><button type="submit" name="save_jv" id="jvSubmit" class="btn btn-brand rounded-pill px-4" disabled>Post Voucher</button></div>
        </form>
    </div></div>
</div>
<template id="jvRowTpl">
    <tr>
        <td><select name="line_account[]" class="form-select form-select-sm">
            <option value="0">- Select head -</option>
            <?php foreach ($accounts as $type => $list): ?>
                <optgroup label="<?php echo e($type); ?>">
                    <?php foreach ($list as $a): ?><option value="<?php echo (int)$a['id']; ?>"><?php echo e($a['code'] . ' - ' . $a['name']); ?></option><?php endforeach; ?>
                </optgroup>
            <?php endforeach; ?>
        </select></td>
        <td><input type="text" name="line_narration[]" class="form-control form-control-sm" maxlength="255"></td>
        <td><input type="number" name="line_debit[]" class="form-control form-control-sm text-end jv-dr" step="0.01" min="0"></td>
        <td><input type="number" name="line_credit[]" class="form-control form-control-sm text-end jv-cr" step="0.01" min="0"></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger jv-del" title="Remove line"><i class="fas fa-times"></i></button></td>
    </tr>
</template>
<script>
(function () {
    const body = document.querySelector('#jvLines tbody');
    const tpl = document.getElementById('jvRowTpl');
    const toPaisa = function (v) { const n = parseFloat(v); return isNaN(n) || n < 0 ? 0 : Math.round(n * 100); };
    const fmt = function (p) { return (p / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
    function addRow() { body.appendChild(tpl.content.cloneNode(true)); }

    // Totals aur balance check - balanced na ho to Post button band
    function recalc() {
        let dr = 0, cr = 0, drLines = 0, crLines = 0, problem = '';
        body.querySelectorAll('tr').forEach(function (tr, i) {
            const acc = tr.querySelector('select').value !== '0';
            const d = toPaisa(tr.querySelector('.jv-dr').value), c = toPaisa(tr.querySelector('.jv-cr').value);
            if (!acc && !d && !c) return;
            if (!acc) problem = problem || 'Line ' + (i + 1) + ': select an account head.';
            if ((d > 0) === (c > 0)) problem = problem || 'Line ' + (i + 1) + ': enter either a debit or a credit.';
            dr += d; cr += c; if (d) drLines++; if (c) crLines++;
        });
        document.getElementById('jvDr').textContent = fmt(dr);
        document.getElementById('jvCr').textContent = fmt(cr);
        if (!problem && (!drLines || !crLines)) problem = 'Enter at least one debit and one credit line.';
        if (!problem && dr !== cr) problem = 'Not balanced: difference ' + fmt(Math.abs(dr - cr)) + '.';
        const box = document.getElementById('jvStatus');
        box.className = 'alert py-2 small mb-0 ' + (problem ? 'alert-warning' : 'alert-success');
        box.textContent = problem || 'Balanced: debit = credit = ' + fmt(dr) + '.';
        document.getElementById('jvSubmit').disabled = !!problem;
    }

    body.addEventListener('input', function (ev) {
        // Ek line mein debit likhne par credit khaali (aur ulta)
        const tr = ev.target.closest('tr');
        if (ev.target.classList.contains('jv-dr') && ev.target.value) tr.querySelector('.jv-cr').value = '';
        if (ev.target.classList.contains('jv-cr') && ev.target.value) tr.querySelector('.jv-dr').value = '';
        recalc();
    });
    body.addEventListener('change', recalc);
    body.addEventListener('click', function (ev) {
        const del = ev.target.closest('.jv-del');
        if (!del) return;
        if (body.querySelectorAll('tr').length > 2) del.closest('tr').remove(); else del.closest('tr').querySelectorAll('input').forEach(function (i) { i.value = ''; });
        recalc();
    });
    document.getElementById('jvAdd').addEventListener('click', function () { addRow(); recalc(); });
    addRow(); addRow();
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
