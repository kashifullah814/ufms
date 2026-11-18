<?php
/**
 * University expenses: add (receipt ke saath), edit, delete, head + date range filter, total.
 * Expense Report: filtered list ka Print / PDF (university header, filters, signatures).
 * Har expense ka payment voucher (PV: Dr expense head / Cr Bank) usi transaction mein banta hai.
 * Edit (amount/head/date/title) par purana voucher Cancel aur naya post; delete par voucher Cancel.
 * Receipt hatana/badalna sirf attachment badalta hai, voucher nahi.
 * Permission: expenses
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
$user = require_permission('expenses');
$today = date('Y-m-d');
$heads = accounts_under('GRP_NON_SALARIES');

/** Form validate. Error ho to string. */
function read_expense_form(&$d)
{
    global $today, $heads;
    $d = [
        'title'    => trim((string)($_POST['title'] ?? '')),
        'account_id' => (int)($_POST['account_id'] ?? 0),
        'amount'   => parse_amount($_POST['amount'] ?? '', false),
        'date'     => (string)($_POST['expense_date'] ?? ''),
        'desc'     => trim((string)($_POST['description'] ?? '')),
    ];
    if ($d['title'] === '' || strlen($d['title']) > 255) return 'Please enter a valid title.';
    if (!isset($heads[$d['account_id']])) return 'Please select a valid expense head.';
    if ($d['amount'] === null) return 'Amount must be a valid number greater than zero.';
    if (!valid_date($d['date']) || $d['date'] > $today) return 'Please enter a valid expense date (not in the future).';
    if (strlen($d['desc']) > 2000) return 'Description is too long (maximum 1,000 characters).';
    return null;
}

if (isset($_POST['save_expense'])) {
    $err = read_expense_form($d);
    $receipt = null;
    if (!$err) {
        $receipt = store_receipt_upload('receipt', $uploadErr);
        if ($receipt === false) $err = $uploadErr;
    }
    if ($err) {
        flash('error', $err);
        redirect_self();
    }
    db()->begin_transaction();
    try {
        db_exec("INSERT INTO expenses (title, account_id, amount, expense_date, description, receipt, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$d['title'], $d['account_id'], $d['amount'], $d['date'], $d['desc'], $receipt, (int)$user['id']]);
        $v = post_expense_voucher(db_row("SELECT * FROM expenses WHERE id = ?", [db_insert_id()]), $user);
        db()->commit();
    } catch (VoucherException $ex) {
        db()->rollback();
        if ($receipt) delete_receipt_file($receipt);
        flash('error', 'Expense not saved: ' . $ex->getMessage());
        redirect_self();
    }
    audit('EXPENSE_ADDED', "{$d['title']} ({$heads[$d['account_id']]['name']}) PKR " . money($d['amount']) . ", {$v['voucher_no']}");
    flash('success', "Expense recorded successfully! Payment voucher {$v['voucher_no']}.");
    redirect_self();
}

if (isset($_POST['update_expense'])) {
    $id = (int)($_POST['expense_id'] ?? 0);
    $old = db_row("SELECT * FROM expenses WHERE id = ?", [$id]);
    $err = $old ? read_expense_form($d) : 'Expense not found.';
    $receipt = null;
    if (!$err) {
        $receipt = store_receipt_upload('receipt', $uploadErr);
        if ($receipt === false) $err = $uploadErr;
    }
    if ($err) {
        flash('error', $err);
        redirect_self();
    }
    // Naya receipt upload ho to wohi lagta hai; warna "Remove" tick par receipt hat jata hai
    $removeReceipt = !$receipt && $old['receipt'] && !empty($_POST['remove_receipt']);
    $newReceipt = $receipt ?: ($removeReceipt ? null : $old['receipt']);
    // Voucher sirf tab badalta hai jab accounting wali cheez badle (description/receipt se nahi)
    $changed = (int)$old['account_id'] !== $d['account_id'] || to_paisa($old['amount']) !== to_paisa($d['amount'])
            || $old['expense_date'] !== $d['date'] || $old['title'] !== $d['title'];
    $old_v = voucher_for_source('expense', $id);
    $v = null;
    db()->begin_transaction();
    try {
        db_exec("UPDATE expenses SET title = ?, account_id = ?, amount = ?, expense_date = ?, description = ?, receipt = ? WHERE id = ?",
            [$d['title'], $d['account_id'], $d['amount'], $d['date'], $d['desc'], $newReceipt, $id]);
        if ($changed || !$old_v) {
            if ($old_v) cancel_voucher($old_v['id'], 'Expense edited - replaced by a new voucher', $user);
            $v = post_expense_voucher(db_row("SELECT * FROM expenses WHERE id = ?", [$id]), $user);
        }
        db()->commit();
    } catch (VoucherException $ex) {
        db()->rollback();
        if ($receipt) delete_receipt_file($receipt);
        flash('error', 'Expense not updated: ' . $ex->getMessage());
        redirect_self();
    }
    if (($receipt || $removeReceipt) && $old['receipt']) delete_receipt_file($old['receipt']);
    if ($removeReceipt) audit('EXPENSE_RECEIPT_REMOVED', "Expense #{$id} {$d['title']}: receipt removed");
    audit('EXPENSE_UPDATED', "{$d['title']} ({$heads[$d['account_id']]['name']}) PKR " . money($d['amount'])
        . ($v ? ($old_v ? ", {$old_v['voucher_no']} cancelled, {$v['voucher_no']} posted" : ", {$v['voucher_no']} posted") : ''));
    flash('success', 'Expense updated successfully!' . ($removeReceipt ? ' Receipt removed.' : '') . ($v ? ($old_v ? " Voucher {$old_v['voucher_no']} was cancelled and {$v['voucher_no']} posted." : " Voucher {$v['voucher_no']} posted.") : ''));
    redirect_self();
}

if (isset($_POST['delete_id'])) {
    $id = (int)$_POST['delete_id'];
    $old = db_row("SELECT * FROM expenses WHERE id = ?", [$id]);
    if ($old) {
        $old_v = voucher_for_source('expense', $id);
        db()->begin_transaction();
        if ($old_v) cancel_voucher($old_v['id'], 'Expense deleted: ' . $old['title'], $user);
        db_exec("DELETE FROM expenses WHERE id = ?", [$id]);
        db()->commit();
        delete_receipt_file($old['receipt']);
        audit('EXPENSE_DELETED', "{$old['title']} PKR " . money($old['amount']) . ($old_v ? ", {$old_v['voucher_no']} cancelled" : ''));
        flash('success', 'Expense deleted successfully!' . ($old_v ? " Voucher {$old_v['voucher_no']} was cancelled (it stays on record)." : ''));
    } else {
        flash('error', 'Expense not found.');
    }
    redirect_self();
}

$all_heads = db_select("SELECT DISTINCT a.id, a.code, a.name FROM accounts a JOIN expenses e ON e.account_id = a.id ORDER BY a.code")->fetch_all(MYSQLI_ASSOC);
$head_filter = (int)($_GET['head'] ?? 0);
if (!in_array($head_filter, array_map('intval', array_column($all_heads, 'id')), true)) $head_filter = 0;
$date_from = valid_date($_GET['from'] ?? '') ? $_GET['from'] : '';
$date_to   = valid_date($_GET['to'] ?? '') ? $_GET['to'] : '';
$filter_warning = '';
if ($date_from !== '' && $date_to !== '' && $date_from > $date_to) {
    $filter_warning = '"From" date must be on or before the "To" date. The dates were swapped.';
    list($date_from, $date_to) = [$date_to, $date_from];
}
$conds = [];
$params = [];
if ($head_filter) { $conds[] = 'e.account_id = ?'; $params[] = $head_filter; }
if ($date_from !== '') { $conds[] = 'e.expense_date >= ?'; $params[] = $date_from; }
if ($date_to !== '') { $conds[] = 'e.expense_date <= ?'; $params[] = $date_to; }
$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';
$expenses = db_select("SELECT e.*, a.code AS head_code, a.name AS head_name, v.id AS voucher_id, v.voucher_no
                       FROM expenses e JOIN accounts a ON a.id = e.account_id
                       LEFT JOIN vouchers v ON v.source_type = 'expense' AND v.source_id = e.id AND v.status = 'Posted'
                       $where ORDER BY e.expense_date DESC, e.id DESC", $params);
$total_expense_sum = (float)db_value("SELECT SUM(e.amount) FROM expenses e $where", $params, 0);
$head_filter_name = '';
$head_filter_label = 'All heads';
foreach ($all_heads as $h) { if ((int)$h['id'] === $head_filter) { $head_filter_name = $h['name']; $head_filter_label = $h['code'] . ' - ' . $h['name']; } }
$fmt_date = function ($d) { return fmt_date($d); };
if ($date_from !== '' && $date_to !== '') $period_label = $fmt_date($date_from) . ' to ' . $fmt_date($date_to);
elseif ($date_from !== '') $period_label = 'From ' . $fmt_date($date_from);
elseif ($date_to !== '') $period_label = 'Up to ' . $fmt_date($date_to);
else $period_label = 'All dates';
$filtered = $head_filter || $date_from !== '' || $date_to !== '';
$pdf_name = 'Expense_Report' . ($date_from !== '' ? '_' . $date_from : '') . ($date_to !== '' ? '_to_' . $date_to : '') . '.pdf';
$show_vouchers = can('accounts');

$page_title = 'Expenses';
$nav_title = 'Expenses';
$nav_icon = 'fa-wallet';
$nav_back = 'dashboard';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/topnav.php';

/** Expense head dropdown (Non-Salaries ke neeche ke heads). Purana (inactive) head bhi edit mein dikhe. */
function head_options($selected, array $extra = [])
{
    global $heads;
    $list = $heads;
    if ($extra && !isset($list[(int)$extra['id']])) $list[(int)$extra['id']] = $extra;
    $h = '';
    foreach ($list as $a) {
        $h .= '<option value="' . (int)$a['id'] . '"' . ((int)$a['id'] === (int)$selected ? ' selected' : '') . '>' . e($a['code'] . ' - ' . $a['name']) . '</option>';
    }
    return $h;
}
?>
<style>
    .print-only { display: none; }
    @media print {
        .print-only { display: block !important; }
        .container { max-width: 100% !important; }
        #expReportCol { flex: 0 0 100%; max-width: 100%; }
        #expReport { box-shadow: none !important; padding: 0 !important; }
        #expReport table { font-size: 9pt; }
    }
    /* html2pdf print CSS nahi padhta: PDF banate waqt yahi classes body.pdf-mode se lagti hain */
    body.pdf-mode #expReport .print-only { display: block !important; }
    body.pdf-mode #expReport .no-print { display: none !important; }
</style>
<div class="container pb-5">
    <div class="no-print">
        <?php echo flash_render(); ?>
        <?php if ($filter_warning): ?><div class="alert alert-warning py-2"><?php echo e($filter_warning); ?></div><?php endif; ?>
    </div>
    <div class="row g-4">
        <div class="col-lg-4 no-print split-form">
            <div class="card form-card">
                <div class="card-header text-white" style="background:#1e3a8a;"><h6 class="mb-0 py-1"><i class="fas fa-plus-circle me-2"></i>Record New Expense</h6></div>
                <div class="card-body p-4">
                    <form action="" method="POST" enctype="multipart/form-data">
                        <?php echo csrf_field(); ?>
                        <div class="mb-3"><label class="form-label fw-semibold small">TITLE <span class="req">*</span></label><input type="text" name="title" class="form-control" maxlength="255" placeholder="e.g. Electricity bill" required></div>
                        <div class="mb-3"><label class="form-label fw-semibold small">EXPENSE HEAD <span class="req">*</span></label><select name="account_id" class="form-select" required><option value="" disabled selected>Select head</option><?php echo head_options(0); ?></select></div>
                        <div class="mb-3"><label class="form-label fw-semibold small">AMOUNT (PKR) <span class="req">*</span></label><input type="number" step="0.01" min="0.01" name="amount" class="form-control" required></div>
                        <div class="mb-3"><label class="form-label fw-semibold small">DATE <span class="req">*</span></label><input type="date" name="expense_date" class="form-control" value="<?php echo $today; ?>" max="<?php echo $today; ?>" required></div>
                        <div class="mb-3"><label class="form-label fw-semibold small">DESCRIPTION</label><textarea name="description" class="form-control" rows="2" maxlength="2000"></textarea></div>
                        <div class="mb-3"><label class="form-label fw-semibold small">RECEIPT (JPG, PNG, PDF - max <?php echo MAX_UPLOAD_BYTES / 1024 / 1024; ?> MB)</label><input type="file" name="receipt" class="form-control" accept=".jpg,.jpeg,.png,.pdf"></div>
                        <button type="submit" name="save_expense" class="btn btn-brand w-100 rounded-pill">Save Expense</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-8 split-list" id="expReportCol">
            <div class="card table-card p-3" id="expReport">
                <div class="print-only text-center border-bottom pb-3 mb-3">
                    <?php echo doc_logo(); ?><h4 class="fw-bold mb-0 doc-title">UNIVERSITY OF SCIENCE &amp; TECHNOLOGY BANNU</h4>
                    <p class="text-muted mb-1">Finance Department</p>
                    <h5 class="fw-bold mb-1">EXPENSE REPORT</h5>
                    <div class="small">Expense Head: <strong><?php echo e($head_filter_label); ?></strong> &nbsp;|&nbsp; Period: <strong><?php echo e($period_label); ?></strong> &nbsp;|&nbsp; Printed on <?php echo e(fmt_datetime(date('Y-m-d H:i:s'))); ?> (amounts in PKR)</div>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2 no-print">
                    <h6 class="fw-bold mb-0"><i class="fas fa-list me-2 text-danger"></i>Expense Records</h6>
                    <div class="d-flex gap-2">
                        <button type="button" onclick="window.print()" class="btn btn-primary btn-sm rounded-pill"><i class="fas fa-print me-1"></i>Print</button>
                        <button type="button" onclick="downloadPdf()" class="btn btn-danger btn-sm rounded-pill"><i class="fas fa-file-pdf me-1"></i>PDF</button>
                    </div>
                    <form method="GET" class="d-flex gap-2 flex-wrap align-items-center w-100">
                        <select name="head" class="form-select form-select-sm w-auto" onchange="this.form.submit()"><option value="">All Heads</option>
                            <?php foreach ($all_heads as $h): ?><option value="<?php echo (int)$h['id']; ?>" <?php echo (int)$h['id'] === $head_filter ? 'selected' : ''; ?>><?php echo e($h['code'] . ' - ' . $h['name']); ?></option><?php endforeach; ?>
                        </select>
                        <label class="small text-muted mb-0" for="expFrom">From</label><input type="date" name="from" id="expFrom" class="form-control form-control-sm w-auto" value="<?php echo e($date_from); ?>">
                        <label class="small text-muted mb-0" for="expTo">To</label><input type="date" name="to" id="expTo" class="form-control form-control-sm w-auto" value="<?php echo e($date_to); ?>">
                        <button class="btn btn-primary btn-sm"><i class="fas fa-filter me-1"></i>Filter</button>
                        <?php if ($filtered): ?><a href="manage_expenses.php" class="btn btn-outline-secondary btn-sm">Clear</a><?php endif; ?>
                    </form>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 small sticky-first">
                        <thead class="table-light"><tr><th>Date</th><th>Title</th><th>Expense Head</th><th>Voucher No</th><th class="text-end">Amount</th><th class="no-print">Receipt</th><th class="text-center no-print">Actions</th></tr></thead>
                        <tbody>
                        <?php if ($expenses->num_rows > 0): while ($row = $expenses->fetch_assoc()): $rid = (int)$row['id']; ?>
                            <tr>
                                <td class="text-nowrap"><?php echo e(fmt_date($row['expense_date'])); ?></td>
                                <td class="cell-wrap"><div class="fw-bold"><?php echo e($row['title']); ?></div><small class="text-muted no-print"><?php echo e($row['description']); ?></small></td>
                                <td><span class="badge bg-light text-dark border"><?php echo e($row['head_code'] . ' ' . $row['head_name']); ?></span></td>
                                <td class="text-nowrap font-monospace"><?php if ($row['voucher_no'] && $show_vouchers): ?><a href="print_voucher.php?id=<?php echo (int)$row['voucher_id']; ?>" target="_blank"><?php echo e($row['voucher_no']); ?></a><?php elseif ($row['voucher_no']): ?><?php echo e($row['voucher_no']); ?><?php else: ?><span class="text-muted">-</span><?php endif; ?></td>
                                <td class="text-end fw-bold text-danger text-nowrap"><?php echo money($row['amount']); ?></td>
                                <td class="no-print"><?php if ($row['receipt']): ?><a href="view_receipt.php?id=<?php echo $rid; ?>" target="_blank" class="btn btn-sm btn-outline-primary rounded-pill py-0"><i class="fas fa-paperclip me-1"></i>View</a><?php else: ?><span class="text-muted">-</span><?php endif; ?></td>
                                <td class="text-center text-nowrap no-print">
                                    <button type="button" class="btn btn-sm btn-outline-primary rounded-circle me-1" data-bs-toggle="modal" data-bs-target="#editExp<?php echo $rid; ?>"><i class="fas fa-edit"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-danger rounded-circle" data-confirm-post data-title="Delete expense?" data-confirm="Delete"
                                            data-text="<?php echo e('Delete "' . $row['title'] . '" (PKR ' . money($row['amount']) . ')?' . ($row['voucher_no'] ? ' Its payment voucher ' . $row['voucher_no'] . ' will be cancelled.' : '')); ?>"
                                            data-fields="<?php echo e(json_encode(['delete_id' => $rid])); ?>"><i class="fas fa-trash"></i></button>

                                    <div class="modal fade text-start" id="editExp<?php echo $rid; ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog modal-dialog-centered"><div class="modal-content rounded-4 border-0 shadow">
                                            <div class="modal-header border-0"><h5 class="modal-title fw-bold">Edit Expense</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                                            <form action="" method="POST" enctype="multipart/form-data">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="expense_id" value="<?php echo $rid; ?>">
                                                <div class="modal-body">
                                                    <div class="mb-3"><label class="form-label fw-semibold">Title <span class="req">*</span></label><input type="text" name="title" maxlength="255" class="form-control" value="<?php echo e($row['title']); ?>" required></div>
                                                    <div class="mb-3"><label class="form-label fw-semibold">Expense Head <span class="req">*</span></label><select name="account_id" class="form-select" required><?php echo head_options($row['account_id'], ['id' => $row['account_id'], 'code' => $row['head_code'], 'name' => $row['head_name']]); ?></select></div>
                                                    <div class="row g-2">
                                                        <div class="col-6 mb-3"><label class="form-label fw-semibold">Amount <span class="req">*</span></label><input type="number" step="0.01" min="0.01" name="amount" class="form-control" value="<?php echo e($row['amount']); ?>" required></div>
                                                        <div class="col-6 mb-3"><label class="form-label fw-semibold">Date <span class="req">*</span></label><input type="date" name="expense_date" class="form-control" value="<?php echo e($row['expense_date']); ?>" max="<?php echo $today; ?>" required></div>
                                                    </div>
                                                    <div class="mb-3"><label class="form-label fw-semibold">Description</label><textarea name="description" maxlength="1000" class="form-control" rows="2"><?php echo e($row['description']); ?></textarea></div>
                                                    <div class="mb-1"><label class="form-label fw-semibold">Replace Receipt (optional)</label><input type="file" name="receipt" class="form-control" accept=".jpg,.jpeg,.png,.pdf"></div>
                                                    <?php if ($row['receipt']): ?>
                                                    <div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="remove_receipt" value="1" id="rmRcpt<?php echo $rid; ?>"><label class="form-check-label small" for="rmRcpt<?php echo $rid; ?>">Remove current receipt <span class="text-muted">(ignored if a new receipt is uploaded)</span></label></div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="modal-footer border-0"><button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button><button type="submit" name="update_expense" class="btn btn-primary rounded-pill px-4">Save Changes</button></div>
                                            </form>
                                        </div></div>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; else: ?>
                            <tr><td colspan="7" class="text-center text-muted py-4"><?php echo $filtered ? 'No expenses match these filters.' : 'No expenses recorded.'; ?></td></tr>
                        <?php endif; ?>
                        </tbody>
                        <tfoot><tr class="table-light fw-bold"><td colspan="4" class="text-end">Total<?php echo $head_filter_name !== '' ? ' (' . e($head_filter_name) . ')' : ''; ?>:</td><td class="text-end text-danger text-nowrap">PKR&nbsp;<?php echo money($total_expense_sum); ?></td><td colspan="2" class="no-print"></td></tr></tfoot>
                    </table>
                </div>
                <div class="print-only">
                    <div class="row mt-5 pt-3 text-center small">
                        <div class="col-6"><p class="border-top pt-2 mx-4 mb-0">Prepared By</p><span class="text-muted"><?php echo e($user['full_name']); ?></span></div>
                        <div class="col-6"><p class="border-top pt-2 mx-4 mb-0">Accounts Officer</p></div>
                    </div>
                    <p class="text-center text-muted small mt-3 mb-0">Computer generated on <?php echo e(fmt_datetime(date('Y-m-d H:i:s'))); ?>.</p>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
function downloadPdf() {
    document.body.classList.add('pdf-mode');
    html2pdf().set({ margin: 8, filename: <?php echo json_encode($pdf_name); ?>, html2canvas: { scale: 2 },
        jsPDF: { format: 'a4', orientation: 'portrait' }, pagebreak: { mode: ['css', 'legacy'] } })
        .from(document.getElementById('expReport')).save()
        .then(function () { document.body.classList.remove('pdf-mode'); }, function () { document.body.classList.remove('pdf-mode'); });
}
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
