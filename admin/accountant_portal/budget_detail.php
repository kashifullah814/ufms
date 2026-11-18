<?php
/**
 * Budget detail: har head ki raqam (Draft/Returned mein edit), Submit to F&PC,
 * Admin ke stage faisle (F&PC -> Syndicate -> Senate; Approve ya Return) meeting date,
 * reference no. aur minutes PDF ke saath, history, delete (sirf kabhi submit na hua Draft), print/PDF.
 * Permission: accounts (stage faisle sirf Admin)
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
$user = require_permission('accounts');
$is_admin = $user['role'] === 'Admin';
$bid = (int)($_GET['id'] ?? 0);
$today = date('Y-m-d');

$budget = db_row("SELECT * FROM budgets WHERE id = ?", [$bid]);
if (!$budget) {
    flash('error', 'Budget not found.');
    redirect(url('admin/accountant_portal/budgets.php'));
}
$fy_label = fiscal_year_label($budget['fiscal_year']);
$lines = budget_line_amounts($bid);
$history_count = (int)db_value("SELECT COUNT(*) FROM budget_approvals WHERE budget_id = ?", [$bid], 0);

// ---------- SAVE LINES (Draft / Returned) ----------
if (isset($_POST['save_lines'])) {
    $title = trim((string)($_POST['title'] ?? ''));
    $remarks = trim((string)($_POST['remarks'] ?? ''));
    $allowed = [];
    foreach (budget_sheet_rows(array_keys($lines)) as $r) {
        if (!(int)$r['is_group']) $allowed[(int)$r['id']] = $r;
    }
    $new = [];
    $err = null;
    if (!budget_editable($budget)) $err = 'This budget can no longer be edited (' . budget_status_label($budget['status']) . ').';
    elseif ($title === '' || strlen($title) > 150) $err = 'Please enter a title (maximum 150 characters).';
    elseif (strlen($remarks) > 500) $err = 'Remarks are too long (maximum 500 characters).';
    else {
        foreach ((array)($_POST['amount'] ?? []) as $aid => $val) {
            $val = trim((string)$val);
            if ($val === '' || (is_numeric($val) && (float)$val == 0)) continue;
            if (!isset($allowed[(int)$aid])) { $err = 'Invalid account head in the budget.'; break; }
            if (!is_numeric($val) || (float)$val < 0 || (float)$val > 999999999999.99) { $err = "Invalid amount for \"{$allowed[(int)$aid]['name']}\"."; break; }
            $new[(int)$aid] = round((float)$val, 2);
        }
    }
    if ($err) {
        flash('error', $err);
        redirect_self();
    }
    db()->begin_transaction();
    db_exec("UPDATE budgets SET title = ?, remarks = ? WHERE id = ?", [$title, $remarks !== '' ? $remarks : null, $bid]);
    db_exec("DELETE FROM budget_lines WHERE budget_id = ?", [$bid]);
    foreach ($new as $aid => $amt) {
        db_exec("INSERT INTO budget_lines (budget_id, account_id, amount) VALUES (?, ?, ?)", [$bid, $aid, $amt]);
    }
    db()->commit();
    audit('BUDGET_UPDATED', "{$title}: " . count($new) . ' head(s)');
    flash('success', 'Budget saved (' . count($new) . ' head(s) with an amount).');
    redirect_self();
}

// ---------- SUBMIT TO F&PC ----------
if (isset($_POST['submit_budget'])) {
    $remarks = trim((string)($_POST['submit_remarks'] ?? ''));
    db()->begin_transaction();
    $b = db_row("SELECT * FROM budgets WHERE id = ? FOR UPDATE", [$bid]);
    $err = null;
    if (!budget_editable($b)) $err = 'Only a Draft or Returned budget can be submitted.';
    elseif (!db_value("SELECT COUNT(*) FROM budget_lines WHERE budget_id = ?", [$bid])) $err = 'Enter and save at least one amount before submitting.';
    elseif (strlen($remarks) > 500) $err = 'Remarks are too long.';
    if ($err) {
        db()->rollback();
        flash('error', $err);
        redirect_self();
    }
    db_exec("UPDATE budgets SET status = 'Submitted' WHERE id = ?", [$bid]);
    db_exec("INSERT INTO budget_approvals (budget_id, stage, action, remarks, recorded_by) VALUES (?, 'F&PC', 'Submitted', ?, ?)", [$bid, $remarks !== '' ? $remarks : null, (int)$user['id']]);
    db()->commit();
    audit('BUDGET_SUBMITTED', "{$b['title']} submitted to F&PC");
    flash('success', 'Budget submitted to F&PC. The Admin will record the F&PC decision.');
    redirect_self();
}

// ---------- STAGE DECISION (sirf Admin) ----------
if (isset($_POST['record_decision'])) {
    $decision = (string)($_POST['decision'] ?? '');
    $meeting = (string)($_POST['meeting_date'] ?? '');
    $ref = trim((string)($_POST['reference_no'] ?? ''));
    $remarks = trim((string)($_POST['decision_remarks'] ?? ''));
    $stage = BUDGET_STAGES[$budget['status']] ?? null;
    $err = null;
    if (!$is_admin) $err = 'Only the Admin can record F&PC, Syndicate and Senate decisions.';
    elseif (!$stage) $err = 'This budget is not waiting for a decision.';
    elseif (!in_array($decision, ['Approved', 'Returned'], true)) $err = 'Please choose Approve or Return.';
    elseif (!valid_date($meeting) || $meeting > $today) $err = 'Please enter a valid meeting date (not in the future).';
    elseif ($decision === 'Approved' && $ref === '') $err = 'Please enter the meeting reference / decision number.';
    elseif ($decision === 'Returned' && $remarks === '') $err = 'Please write why the budget is being returned.';
    elseif (strlen($ref) > 100 || strlen($remarks) > 500) $err = 'Reference (100) or remarks (500 characters) is too long.';
    $file = null;
    if (!$err) {
        $file = store_upload('minutes', budget_minutes_dir(), ['pdf' => ['application/pdf']], $upErr, 'minutes');
        if ($file === false) $err = $upErr;
    }
    if ($err) {
        flash('error', $err);
        redirect_self();
    }
    db()->begin_transaction();
    $b = db_row("SELECT * FROM budgets WHERE id = ? FOR UPDATE", [$bid]);
    if ($b['status'] !== $budget['status']) {
        db()->rollback();
        if ($file) @unlink(budget_minutes_dir() . $file);
        flash('error', 'The budget status changed meanwhile. Please check and try again.');
        redirect_self();
    }
    $next = $decision === 'Approved' ? $stage['next'] : 'Returned';
    db_exec("INSERT INTO budget_approvals (budget_id, stage, action, meeting_date, reference_no, minutes_file, remarks, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
        [$bid, $stage['stage'], $decision, $meeting, $ref !== '' ? $ref : null, $file, $remarks !== '' ? $remarks : null, (int)$user['id']]);
    $superseded = null;
    if ($next === 'Senate Approved') {
        // Pehle purana active band (unique index ki wajah se), phir yeh active
        $superseded = db_row("SELECT id, title FROM budgets WHERE fiscal_year = ? AND is_active = 1 AND id <> ? FOR UPDATE", [(int)$b['fiscal_year'], $bid]);
        if ($superseded) {
            db_exec("UPDATE budgets SET is_active = 0, status = 'Superseded' WHERE id = ?", [(int)$superseded['id']]);
        }
        db_exec("UPDATE budgets SET status = 'Senate Approved', is_active = 1 WHERE id = ?", [$bid]);
    } else {
        db_exec("UPDATE budgets SET status = ? WHERE id = ?", [$next, $bid]);
    }
    db()->commit();
    audit('BUDGET_' . ($decision === 'Approved' ? 'APPROVED' : 'RETURNED'), "{$b['title']}: {$stage['stage']} " . strtolower($decision) . ($ref !== '' ? " (Ref {$ref})" : ''));
    $msg = $decision === 'Returned' ? "Budget returned by {$stage['stage']} for revision. Accounts can now edit and resubmit it."
         : ($next === 'Senate Approved' ? 'Budget approved by the Senate. It is now the ACTIVE budget for FY ' . $fy_label . '.' . ($superseded ? " \"{$superseded['title']}\" is superseded." : '')
         : "{$stage['stage']} approval recorded. Next: " . BUDGET_STAGES[$next]['stage'] . '.');
    flash('success', $msg);
    redirect_self();
}

// ---------- DELETE (Draft jo kabhi submit nahi hua) ----------
if (isset($_POST['delete_budget'])) {
    if ($budget['status'] !== 'Draft' || $history_count > 0) {
        flash('error', 'Only a Draft that was never submitted can be deleted.');
        redirect_self();
    }
    db_exec("DELETE FROM budgets WHERE id = ? AND status = 'Draft'", [$bid]);
    audit('BUDGET_DELETED', $budget['title']);
    flash('success', "Draft \"{$budget['title']}\" deleted.");
    redirect(url('admin/accountant_portal/budgets.php'));
}

// ---------- PAGE DATA ----------
$editable = budget_editable($budget);
$base_lines = $budget['revision_of'] ? budget_line_amounts((int)$budget['revision_of']) : null;
$base = $budget['revision_of'] ? db_row("SELECT id, title FROM budgets WHERE id = ?", [(int)$budget['revision_of']]) : null;
$rows = budget_sheet_rows(array_merge(array_keys($lines), $base_lines ? array_keys($base_lines) : []));
$byId = [];
foreach ($rows as $i => $r) {
    $rows[$i]['amt'] = (int)$r['is_group'] ? 0 : ($lines[(int)$r['id']] ?? 0);
    $rows[$i]['base'] = (int)$r['is_group'] ? 0 : ($base_lines[(int)$r['id']] ?? 0);
    $byId[(int)$r['id']] = $i;
}
foreach ($rows as $r) {
    if ((int)$r['is_group']) continue;
    for ($p = (int)$r['parent_id']; $p && isset($byId[$p]); $p = (int)$rows[$byId[$p]]['parent_id']) {
        $rows[$byId[$p]]['amt'] += $r['amt'];
        $rows[$byId[$p]]['base'] += $r['base'];
    }
}
$totals = ['Income' => 0, 'Expense' => 0];
foreach ($rows as $r) {
    if (!(int)$r['is_group']) $totals[$r['type']] += $r['amt'];
}
$history = db_select("SELECT ba.*, u.full_name FROM budget_approvals ba LEFT JOIN users u ON u.id = ba.recorded_by WHERE ba.budget_id = ? ORDER BY ba.id", [$bid])->fetch_all(MYSQLI_ASSOC);
$stage = BUDGET_STAGES[$budget['status']] ?? null;
$steps = ['Draft' => 'Draft', 'Submitted' => 'F&PC', 'FPC Approved' => 'Syndicate', 'Syndicate Approved' => 'Senate', 'Senate Approved' => 'Final'];
$step_keys = array_keys($steps);
$cur_step = array_search($budget['status'], $step_keys, true);

$page_title = $budget['title'];
$nav_title = 'Budget - FY ' . $fy_label;
$nav_icon = 'fa-clipboard-list';
$nav_back = 'budgets.php';
$nav_back_label = 'Budgets';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/topnav.php';
$cell = function ($p) { return $p ? money($p / 100) : '-'; };
?>
<style>
    #budgetDoc td.num, #budgetDoc th.num { text-align: right; white-space: nowrap; }
    #budgetDoc tr.grp td { background: #f1f5f9; font-weight: 600; }
    #budgetDoc input.amt { max-width: 170px; min-width: 150px; margin-left: auto; text-align: right; }
    .steps .step { flex: 1; text-align: center; font-size: .8rem; padding: .4rem; border-bottom: 4px solid #dee2e6; color: #565e64; }
    .steps .step.done { border-color: #198754; color: #146c43; font-weight: 600; }
    .steps .step.now { border-color: #0d6efd; color: #0b5ed7; font-weight: 700; }
    @media print { #budgetDoc { box-shadow: none !important; border: 0 !important; } }
</style>
<div class="container pb-5">
    <div class="no-print"><?php echo flash_render(); ?></div>

    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3 no-print">
        <div>
            <h5 class="fw-bold mb-1"><?php echo e($budget['title']); ?></h5>
            <span class="badge bg-light text-dark border"><?php echo e($budget['budget_type']); ?></span>
            <span class="badge <?php echo budget_status_badge($budget['status']); ?>"><?php echo e(budget_status_label($budget['status'])); ?></span>
            <?php if ((int)$budget['is_active']): ?><span class="badge bg-success"><i class="fas fa-check me-1"></i>Active budget</span><?php endif; ?>
            <?php if ($base): ?><small class="text-muted ms-1">Revision of <a href="budget_detail.php?id=<?php echo (int)$base['id']; ?>"><?php echo e($base['title']); ?></a></small><?php endif; ?>
        </div>
        <div class="d-flex gap-2">
            <?php if ((int)$budget['is_active']): ?><a href="utilization.php?fy=<?php echo (int)$budget['fiscal_year']; ?>" class="btn btn-outline-success btn-sm rounded-pill px-3"><i class="fas fa-chart-pie me-1"></i>Utilization</a><?php endif; ?>
            <button type="button" onclick="window.print()" class="btn btn-primary btn-sm rounded-pill px-3"><i class="fas fa-print me-1"></i>Print</button>
            <button type="button" onclick="downloadPdf()" class="btn btn-danger btn-sm rounded-pill px-3"><i class="fas fa-file-pdf me-1"></i>PDF</button>
            <?php if ($budget['status'] === 'Draft' && !$history_count): ?>
                <button type="button" class="btn btn-outline-danger btn-sm rounded-pill px-3" data-confirm-post data-title="Delete this draft?" data-confirm="Delete"
                        data-text="<?php echo e('Delete "' . $budget['title'] . '" and all its amounts?'); ?>" data-fields="<?php echo e(json_encode(['delete_budget' => 1])); ?>"><i class="fas fa-trash me-1"></i>Delete</button>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!in_array($budget['status'], ['Returned', 'Superseded'], true)): ?>
    <div class="d-flex steps mb-4 no-print">
        <?php foreach ($steps as $k => $label): $i = array_search($k, $step_keys, true); ?>
            <div class="step <?php echo $cur_step !== false && $i < $cur_step ? 'done' : ($i === $cur_step ? 'now' : ''); ?>">
                <?php echo $i < $cur_step || $budget['status'] === 'Senate Approved' ? '<i class="fas fa-check-circle me-1"></i>' : ''; ?><?php echo e($k === 'Draft' ? 'Draft' : ($k === 'Senate Approved' ? 'Approved (Final)' : $label)); ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php elseif ($budget['status'] === 'Returned'): ?>
        <div class="alert alert-warning no-print"><i class="fas fa-undo me-2"></i>This budget was <strong>returned for revision</strong>. Edit the amounts, save, and submit it again (it goes back to F&amp;PC).</div>
    <?php else: ?>
        <div class="alert alert-secondary no-print">This budget was superseded by a revised budget and is kept for record only.</div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-8">
            <form action="" method="POST" id="linesForm">
                <?php echo csrf_field(); ?>
                <div id="budgetDoc" class="bg-white p-4 shadow-sm border rounded">
                    <div class="text-center border-bottom pb-3 mb-3">
                        <?php echo doc_logo(); ?><h5 class="fw-bold mb-0 doc-title">UNIVERSITY OF SCIENCE &amp; TECHNOLOGY BANNU</h5>
                        <p class="text-muted mb-1 small">Finance Department</p>
                        <?php if ($editable): ?>
                            <input type="text" name="title" class="form-control form-control-sm text-center fw-bold mx-auto no-print" style="max-width:420px" maxlength="150" value="<?php echo e($budget['title']); ?>" required>
                        <?php endif; ?>
                        <h6 class="fw-bold mb-0 <?php echo $editable ? 'd-none d-print-block' : ''; ?>"><?php echo e(strtoupper($budget['title'])); ?></h6>
                        <div class="small">Fiscal Year <?php echo e($fy_label); ?> (1 July <?php echo (int)$budget['fiscal_year']; ?> - 30 June <?php echo (int)$budget['fiscal_year'] + 1; ?>) &middot; <?php echo e(budget_status_label($budget['status'])); ?> &middot; amounts in PKR</div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm align-middle mb-2 small sticky-first">
                            <thead class="table-light"><tr><th style="width:9%">Code</th><th>Head</th><?php if ($base_lines !== null): ?><th class="num">Approved (current)</th><?php endif; ?><th class="num"><?php echo $base_lines !== null ? 'Revised' : 'Budget'; ?></th><?php if ($base_lines !== null): ?><th class="num">Change</th><?php endif; ?></tr></thead>
                            <tbody>
                            <?php foreach ($rows as $r): $grp = (int)$r['is_group'] === 1; $pad = 0.4 + 1.2 * (int)$r['depth']; ?>
                                <tr class="<?php echo $grp ? 'grp' : ''; ?>">
                                    <td class="font-monospace"><?php echo e($r['code']); ?></td>
                                    <td style="padding-left:<?php echo $pad; ?>rem"><?php echo e($r['name']); ?><?php echo !$grp && $r['status'] !== 'Active' ? ' <small class="text-muted">(inactive)</small>' : ''; ?></td>
                                    <?php if ($base_lines !== null): ?><td class="num"><?php echo $cell($r['base']); ?></td><?php endif; ?>
                                    <td class="num">
                                        <?php if ($editable && !$grp): ?>
                                            <input type="number" step="0.01" min="0" name="amount[<?php echo (int)$r['id']; ?>]" class="form-control form-control-sm amt no-print" value="<?php echo $r['amt'] ? e(number_format($r['amt'] / 100, 2, '.', '')) : ''; ?>" placeholder="0.00">
                                            <span class="d-none d-print-inline"><?php echo $cell($r['amt']); ?></span>
                                        <?php else: ?><?php echo $cell($r['amt']); ?><?php endif; ?>
                                    </td>
                                    <?php if ($base_lines !== null): $diff = $r['amt'] - $r['base']; ?><td class="num <?php echo $diff > 0 ? 'text-success' : ($diff < 0 ? 'text-danger' : ''); ?>"><?php echo $diff ? ($diff > 0 ? '+' : '-') . money(abs($diff) / 100) : '-'; ?></td><?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="fw-bold table-light"><td colspan="2" class="text-end">Total Receipts</td><?php if ($base_lines !== null): ?><td></td><?php endif; ?><td class="num text-success"><?php echo money($totals['Income'] / 100); ?></td><?php if ($base_lines !== null): ?><td></td><?php endif; ?></tr>
                                <tr class="fw-bold table-light"><td colspan="2" class="text-end">Total Payments</td><?php if ($base_lines !== null): ?><td></td><?php endif; ?><td class="num text-danger"><?php echo money($totals['Expense'] / 100); ?></td><?php if ($base_lines !== null): ?><td></td><?php endif; ?></tr>
                                <tr class="fw-bold"><td colspan="2" class="text-end"><?php echo $totals['Income'] >= $totals['Expense'] ? 'Surplus' : 'Deficit'; ?></td><?php if ($base_lines !== null): ?><td></td><?php endif; ?><td class="num"><?php echo money(abs($totals['Income'] - $totals['Expense']) / 100); ?></td><?php if ($base_lines !== null): ?><td></td><?php endif; ?></tr>
                            </tfoot>
                        </table>
                    </div>
                    <?php if ($editable): ?>
                        <label class="form-label small fw-semibold no-print">Remarks</label>
                        <textarea name="remarks" class="form-control form-control-sm no-print" rows="2" maxlength="500"><?php echo e($budget['remarks']); ?></textarea>
                    <?php elseif ($budget['remarks']): ?>
                        <p class="small mb-0"><strong>Remarks:</strong> <?php echo e($budget['remarks']); ?></p>
                    <?php endif; ?>
                    <?php if ($history): ?>
                        <div class="d-none d-print-block mt-3 small">
                            <strong>Approval record:</strong>
                            <?php foreach ($history as $h): if ($h['action'] === 'Submitted') continue; ?>
                                <div><?php echo e($h['stage'] . ' - ' . $h['action'] . ' in meeting of ' . fmt_date($h['meeting_date']) . ($h['reference_no'] ? ', Ref ' . $h['reference_no'] : '')); ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if ($editable): ?>
                    <div class="text-end mt-3 no-print"><button type="submit" name="save_lines" class="btn btn-brand rounded-pill px-5"><i class="fas fa-save me-1"></i>Save Budget</button></div>
                <?php endif; ?>
            </form>
        </div>

        <div class="col-lg-4 no-print">
            <?php if ($editable): ?>
            <div class="card form-card mb-4">
                <div class="card-header text-white" style="background:#1e3a8a;"><h6 class="mb-0 py-1"><i class="fas fa-paper-plane me-2"></i>Submit to F&amp;PC</h6></div>
                <div class="card-body p-3">
                    <p class="small text-muted">Save your changes first. After submitting, the budget cannot be edited unless a forum returns it.</p>
                    <form action="" method="POST">
                        <?php echo csrf_field(); ?>
                        <textarea name="submit_remarks" class="form-control form-control-sm mb-2" rows="2" maxlength="500" placeholder="Remarks (optional)"></textarea>
                        <button type="submit" name="submit_budget" class="btn btn-brand w-100 rounded-pill" <?php echo $lines ? '' : 'disabled'; ?>>Submit to F&amp;PC</button>
                        <?php if (!$lines): ?><div class="form-text">Enter and save at least one amount first.</div><?php endif; ?>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($stage): ?>
            <div class="card form-card mb-4">
                <div class="card-header text-white" style="background:#1e3a8a;"><h6 class="mb-0 py-1"><i class="fas fa-gavel me-2"></i><?php echo e($stage['stage']); ?> decision</h6></div>
                <div class="card-body p-3">
                    <?php if ($is_admin): ?>
                    <form action="" method="POST" enctype="multipart/form-data">
                        <?php echo csrf_field(); ?>
                        <div class="mb-2 d-flex gap-3">
                            <label><input type="radio" name="decision" value="Approved" checked> Approve</label>
                            <label class="text-danger"><input type="radio" name="decision" value="Returned"> Return for revision</label>
                        </div>
                        <div class="mb-2"><label class="form-label small fw-semibold">Meeting date <span class="req">*</span></label><input type="date" name="meeting_date" class="form-control form-control-sm" max="<?php echo $today; ?>" required></div>
                        <div class="mb-2"><label class="form-label small fw-semibold">Reference no. <span class="req">*</span></label><input type="text" name="reference_no" class="form-control form-control-sm" maxlength="100" data-required-if="approved" placeholder="e.g. <?php echo e($stage['stage']); ?>-<?php echo date('Y'); ?>/12"><div class="form-text">Needed when the budget is approved.</div></div>
                        <div class="mb-2"><label class="form-label small fw-semibold">Minutes (PDF, max <?php echo MAX_UPLOAD_BYTES / 1024 / 1024; ?> MB)</label><input type="file" name="minutes" class="form-control form-control-sm" accept=".pdf,application/pdf"></div>
                        <div class="mb-3"><label class="form-label small fw-semibold">Remarks <small class="text-muted fw-normal">(required when returning)</small></label><textarea name="decision_remarks" class="form-control form-control-sm" rows="2" maxlength="500"></textarea></div>
                        <button type="submit" name="record_decision" class="btn btn-brand w-100 rounded-pill">Record <?php echo e($stage['stage']); ?> Decision</button>
                    </form>
                    <?php else: ?>
                        <p class="small mb-0">Waiting for the <strong><?php echo e($stage['stage']); ?></strong> decision. The Admin records it here.</p>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="card table-card p-3">
                <h6 class="fw-bold mb-3"><i class="fas fa-history me-2 text-primary"></i>Approval history</h6>
                <?php if (!$history): ?><p class="small text-muted mb-0">Not submitted yet.</p><?php endif; ?>
                <?php foreach ($history as $h):
                    $icon = $h['action'] === 'Approved' ? 'fa-check-circle text-success' : ($h['action'] === 'Returned' ? 'fa-undo text-warning' : 'fa-paper-plane text-info'); ?>
                    <div class="border-start border-3 ps-2 mb-3 small">
                        <div class="fw-bold"><i class="fas <?php echo $icon; ?> me-1"></i><?php echo e($h['action'] === 'Submitted' ? 'Submitted to F&PC' : $h['stage'] . ' - ' . $h['action']); ?></div>
                        <?php if ($h['meeting_date']): ?><div>Meeting: <?php echo e(fmt_date($h['meeting_date'])); ?><?php echo $h['reference_no'] ? ' &middot; Ref ' . e($h['reference_no']) : ''; ?></div><?php endif; ?>
                        <?php if ($h['remarks']): ?><div class="text-muted"><?php echo e($h['remarks']); ?></div><?php endif; ?>
                        <?php if ($h['minutes_file']): ?><a href="view_minutes.php?id=<?php echo (int)$h['id']; ?>" target="_blank"><i class="fas fa-file-pdf me-1 text-danger"></i>Minutes</a><?php endif; ?>
                        <div class="text-muted" style="font-size:.75rem"><?php echo e(($h['full_name'] ?: 'Unknown') . ', ' . date('d M Y h:i A', strtotime($h['recorded_at']))); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
function downloadPdf() {
    const doc = document.getElementById('budgetDoc').cloneNode(true);
    // PDF mein input ki jagah raqam (print wala text) dikhao
    doc.querySelectorAll('.no-print').forEach(function (el) { el.remove(); });
    doc.querySelectorAll('.d-none.d-print-block, .d-none.d-print-inline').forEach(function (el) { el.classList.remove('d-none'); });
    html2pdf().set({ margin: 10, filename: <?php echo json_encode(preg_replace('/[^A-Za-z0-9_-]+/', '_', $budget['title']) . '.pdf'); ?>, html2canvas: { scale: 2 }, jsPDF: { format: 'a4' } }).from(doc).save();
}
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
