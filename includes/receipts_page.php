<?php
/**
 * Grants aur Student Related Charges ka mushtarka page (grants.php / student_charges.php yahan aate hain).
 * Har receipt ka RV (Dr Bank / Cr receipt head) usi transaction mein. Admin receipt cancel kar sakta hai
 * (row rehti hai, voucher Cancelled). Permission: accounts
 * Caller pehle $RECEIPT_KIND = 'Grant' | 'Student Charge' set karta hai.
 */
if (!isset($RECEIPT_KIND) || !in_array($RECEIPT_KIND, ['Grant', 'Student Charge'], true)) {
    http_response_code(404);
    exit;
}
$user = require_permission('accounts');
$is_admin = $user['role'] === 'Admin';
$is_grant = $RECEIPT_KIND === 'Grant';
$today = date('Y-m-d');
$heads = $is_grant ? grant_heads() : student_charge_heads();
$cfg = $is_grant
    ? ['title' => 'Grants (HEC / HED)', 'icon' => 'fa-landmark', 'color' => 'success', 'ref' => 'Letter / Cheque No.', 'ref_required' => true, 'audit' => 'GRANT_RECEIVED']
    : ['title' => 'Student Related Charges', 'icon' => 'fa-hand-holding-usd', 'color' => 'primary', 'ref' => 'Receipt No.', 'ref_required' => false, 'audit' => 'STUDENT_CHARGE_RECEIVED'];

// ---------- RECORD RECEIPT ----------
if (isset($_POST['save_receipt'])) {
    $d = [
        'account_id' => (int)($_POST['account_id'] ?? 0),
        'student_id' => $is_grant ? 0 : (int)($_POST['student_id'] ?? 0),
        'amount'     => trim((string)($_POST['amount'] ?? '')),
        'date'       => (string)($_POST['receipt_date'] ?? ''),
        'ref'        => trim((string)($_POST['reference_no'] ?? '')),
        'desc'       => trim((string)($_POST['description'] ?? '')),
    ];
    $err = null;
    if (!isset($heads[$d['account_id']])) $err = 'Please select a valid head.';
    elseif (!is_numeric($d['amount']) || round((float)$d['amount'], 2) <= 0 || (float)$d['amount'] > 999999999999.99) $err = 'Amount must be greater than zero.';
    elseif (!valid_date($d['date']) || $d['date'] > $today) $err = 'Please enter a valid date (not in the future).';
    elseif ($cfg['ref_required'] && $d['ref'] === '') $err = "Please enter the {$cfg['ref']}.";
    elseif (strlen($d['ref']) > 100 || strlen($d['desc']) > 255) $err = 'Reference (100) or description (255 characters) is too long.';
    elseif ($d['student_id'] && !db_value("SELECT id FROM students WHERE id = ?", [$d['student_id']])) $err = 'Please select a valid student.';
    if ($err) {
        flash('error', $err);
        redirect_self();
    }
    db()->begin_transaction();
    try {
        db_exec("INSERT INTO other_receipts (receipt_kind, account_id, student_id, amount, receipt_date, reference_no, description, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [$RECEIPT_KIND, $d['account_id'], $d['student_id'] ?: null, round((float)$d['amount'], 2), $d['date'], $d['ref'] !== '' ? $d['ref'] : null, $d['desc'] !== '' ? $d['desc'] : null, (int)$user['id']]);
        $r = db_row("SELECT r.*, a.name AS head_name, u.full_name, s.reg_number FROM other_receipts r JOIN accounts a ON a.id = r.account_id
                     LEFT JOIN students s ON s.id = r.student_id LEFT JOIN users u ON u.id = s.user_id WHERE r.id = ?", [db_insert_id()]);
        $v = post_other_receipt_voucher($r, $user);
        db()->commit();
    } catch (VoucherException $ex) {
        db()->rollback();
        flash('error', 'Receipt not saved: ' . $ex->getMessage());
        redirect_self();
    }
    audit($cfg['audit'], "{$r['head_name']} PKR " . money($r['amount']) . ($r['full_name'] ? " - {$r['full_name']}" : '') . ", {$v['voucher_no']}");
    flash('success', "{$r['head_name']} of PKR " . money($r['amount']) . " recorded. Receipt voucher {$v['voucher_no']}.");
    redirect_self();
}

// ---------- CANCEL (sirf Admin) ----------
if (isset($_POST['cancel_receipt'])) {
    $rid = (int)($_POST['receipt_id'] ?? 0);
    $reason = trim((string)($_POST['cancel_reason'] ?? ''));
    $r = db_row("SELECT r.*, a.name AS head_name FROM other_receipts r JOIN accounts a ON a.id = r.account_id WHERE r.id = ? AND r.receipt_kind = ?", [$rid, $RECEIPT_KIND]);
    $v = $r ? voucher_for_source('receipt', $rid) : null;
    if (!$is_admin) {
        flash('error', 'Only the Admin can cancel a receipt.');
    } elseif (!$r || !$v) {
        flash('error', 'Receipt not found or already cancelled.');
    } elseif (strlen($reason) < 5 || strlen($reason) > 255) {
        flash('error', 'Please give a reason for cancelling (5-255 characters).');
    } elseif (cancel_voucher($v['id'], $reason, $user)) {
        audit('RECEIPT_CANCELLED', "{$r['head_name']} PKR " . money($r['amount']) . ", {$v['voucher_no']}: {$reason}");
        flash('success', "Receipt cancelled. Voucher {$v['voucher_no']} is now Cancelled (kept on record).");
    }
    redirect_self();
}

// ---------- LIST ----------
$f_head = (int)($_GET['head'] ?? 0);
$f_from = valid_date($_GET['from'] ?? '') ? $_GET['from'] : '';
$f_to   = valid_date($_GET['to'] ?? '') ? $_GET['to'] : '';
$where = 'WHERE r.receipt_kind = ?';
$params = [$RECEIPT_KIND];
if ($f_head)       { $where .= ' AND r.account_id = ?'; $params[] = $f_head; }
if ($f_from !== '') { $where .= ' AND r.receipt_date >= ?'; $params[] = $f_from; }
if ($f_to !== '')   { $where .= ' AND r.receipt_date <= ?'; $params[] = $f_to; }
$rows = db_select("SELECT r.*, a.code AS head_code, a.name AS head_name, u.full_name, s.reg_number, v.id AS voucher_id, v.voucher_no, v.status AS voucher_status, v.cancel_reason
                   FROM other_receipts r JOIN accounts a ON a.id = r.account_id
                   LEFT JOIN students s ON s.id = r.student_id LEFT JOIN users u ON u.id = s.user_id
                   LEFT JOIN vouchers v ON v.id = (SELECT MAX(v2.id) FROM vouchers v2 WHERE v2.source_type = 'receipt' AND v2.source_id = r.id)
                   $where ORDER BY r.receipt_date DESC, r.id DESC", $params)->fetch_all(MYSQLI_ASSOC);
$total = 0;
foreach ($rows as $row) {
    if ($row['voucher_status'] === 'Posted') $total += to_paisa($row['amount']);
}
$students = $is_grant ? [] : db_select("SELECT s.id, s.reg_number, u.full_name FROM students s JOIN users u ON u.id = s.user_id WHERE u.status = 'Active' ORDER BY u.full_name")->fetch_all(MYSQLI_ASSOC);
$list_heads = db_select("SELECT DISTINCT a.id, a.code, a.name FROM accounts a JOIN other_receipts r ON r.account_id = a.id WHERE r.receipt_kind = ? ORDER BY a.code", [$RECEIPT_KIND])->fetch_all(MYSQLI_ASSOC);

$page_title = $cfg['title'];
$nav_title = $cfg['title'];
$nav_icon = $cfg['icon'];
$nav_back = 'dashboard';
include __DIR__ . '/header.php';
include __DIR__ . '/topnav.php';
?>
<div class="container pb-5">
    <?php echo flash_render(); ?>
    <div class="row g-4">
        <div class="col-lg-4 split-form">
            <div class="card form-card">
                <div class="card-header text-white" style="background:#1e3a8a;"><h6 class="mb-0 py-1"><i class="fas <?php echo $cfg['icon']; ?> me-2"></i><?php echo $is_grant ? 'Record Grant Received' : 'Record Student Charge'; ?></h6></div>
                <div class="card-body p-4">
                    <?php if (!$heads): ?>
                        <div class="alert alert-warning small mb-0">No active heads available. Ask the Admin to add them in the Chart of Accounts.</div>
                    <?php else: ?>
                    <form action="" method="POST">
                        <?php echo csrf_field(); ?>
                        <div class="mb-3"><label class="form-label fw-semibold small"><?php echo $is_grant ? 'GRANT SOURCE' : 'CHARGE HEAD'; ?> <span class="req">*</span></label>
                            <select name="account_id" class="form-select" required><option value="" disabled selected>Select...</option>
                                <?php foreach ($heads as $h): ?><option value="<?php echo (int)$h['id']; ?>"><?php echo e($h['code'] . ' - ' . $h['name']); ?></option><?php endforeach; ?>
                            </select></div>
                        <?php if (!$is_grant): ?>
                        <div class="mb-3"><label class="form-label fw-semibold small">STUDENT</label>
                            <select name="student_id" class="form-select"><option value="0">- Not a registered student -</option>
                                <?php foreach ($students as $s): ?><option value="<?php echo (int)$s['id']; ?>"><?php echo e($s['full_name'] . ' (' . $s['reg_number'] . ')'); ?></option><?php endforeach; ?>
                            </select>
                            <div class="form-text">Leave empty for outside payers (e.g. degree verification requested by an employer).</div></div>
                        <?php endif; ?>
                        <div class="row g-2">
                            <div class="col-6 mb-3"><label class="form-label fw-semibold small">AMOUNT (PKR) <span class="req">*</span></label><input type="number" step="0.01" min="0.01" name="amount" class="form-control" required></div>
                            <div class="col-6 mb-3"><label class="form-label fw-semibold small">DATE <span class="req">*</span></label><input type="date" name="receipt_date" class="form-control" value="<?php echo $today; ?>" max="<?php echo $today; ?>" required></div>
                        </div>
                        <div class="mb-3"><label class="form-label fw-semibold small"><?php echo e(strtoupper($cfg['ref'])); ?><?php echo $cfg['ref_required'] ? ' <span class="req">*</span>' : ''; ?></label><input type="text" name="reference_no" class="form-control" maxlength="100" <?php echo $cfg['ref_required'] ? 'required' : ''; ?>></div>
                        <div class="mb-3"><label class="form-label fw-semibold small">DESCRIPTION</label><input type="text" name="description" class="form-control" maxlength="255" placeholder="<?php echo $is_grant ? 'e.g. Recurring grant, 1st quarter' : 'e.g. Hostel charges, Fall semester'; ?>"></div>
                        <button type="submit" name="save_receipt" class="btn btn-brand w-100 rounded-pill">Save &amp; Create Receipt Voucher</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-8 split-list">
            <div class="card table-card p-3">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <h6 class="fw-bold mb-0"><i class="fas fa-list me-2 text-<?php echo $cfg['color']; ?>"></i>Receipts</h6>
                    <form method="GET" class="d-flex gap-2 flex-wrap">
                        <select name="head" class="form-select form-select-sm" style="width:auto"><option value="">All heads</option>
                            <?php foreach ($list_heads as $h): ?><option value="<?php echo (int)$h['id']; ?>" <?php echo (int)$h['id'] === $f_head ? 'selected' : ''; ?>><?php echo e($h['name']); ?></option><?php endforeach; ?>
                        </select>
                        <input type="date" name="from" class="form-control form-control-sm" style="width:auto" value="<?php echo e($f_from); ?>" title="From">
                        <input type="date" name="to" class="form-control form-control-sm" style="width:auto" value="<?php echo e($f_to); ?>" title="To">
                        <button class="btn btn-sm btn-outline-primary"><i class="fas fa-filter"></i></button>
                        <?php if ($f_head || $f_from !== '' || $f_to !== ''): ?><a href="?" class="btn btn-sm btn-outline-secondary"><i class="fas fa-undo"></i></a><?php endif; ?>
                    </form>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 small">
                        <thead class="table-light"><tr><th>Date</th><th>Head</th><th><?php echo $is_grant ? 'Reference' : 'Student / Receipt'; ?></th><th class="text-end">Amount</th><th>Voucher</th><?php if ($is_admin): ?><th></th><?php endif; ?></tr></thead>
                        <tbody>
                        <?php if ($rows): foreach ($rows as $row): $cancelled = $row['voucher_status'] !== 'Posted'; ?>
                            <tr class="<?php echo $cancelled ? 'text-muted' : ''; ?>">
                                <td class="text-nowrap"><?php echo e(fmt_date($row['receipt_date'])); ?></td>
                                <td class="cell-wrap"><?php echo e($row['head_name']); ?><?php if ($row['description']): ?><br><small class="text-muted"><?php echo e($row['description']); ?></small><?php endif; ?></td>
                                <td><?php if ($row['full_name']): ?><span class="text-capitalize"><?php echo e($row['full_name']); ?></span> <small class="text-muted">(<?php echo e($row['reg_number']); ?>)</small><br><?php endif; ?><small><?php echo e($row['reference_no'] ?: '-'); ?></small></td>
                                <td class="text-end fw-bold <?php echo $cancelled ? 'text-decoration-line-through' : 'text-success'; ?>"><?php echo money($row['amount']); ?></td>
                                <td class="text-nowrap"><?php if ($row['voucher_no']): ?><a href="print_voucher.php?id=<?php echo (int)$row['voucher_id']; ?>" target="_blank" class="font-monospace"><?php echo e($row['voucher_no']); ?></a><?php endif; ?>
                                    <?php if ($cancelled): ?><br><span class="badge bg-secondary" title="<?php echo e($row['cancel_reason']); ?>">Cancelled</span><?php endif; ?></td>
                                <?php if ($is_admin): ?><td class="text-end"><?php if (!$cancelled): ?><button type="button" class="btn btn-sm btn-outline-danger rounded-circle" title="Cancel receipt" data-bs-toggle="modal" data-bs-target="#cancelModal"
                                        data-receipt="<?php echo (int)$row['id']; ?>" data-label="<?php echo e($row['head_name'] . ' - PKR ' . money($row['amount']) . ' (' . $row['voucher_no'] . ')'); ?>"><i class="fas fa-ban"></i></button><?php endif; ?></td><?php endif; ?>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="<?php echo $is_admin ? 6 : 5; ?>" class="text-center text-muted py-4">No receipts recorded.</td></tr>
                        <?php endif; ?>
                        </tbody>
                        <tfoot><tr class="table-light fw-bold"><td colspan="3" class="text-end">Total received (excluding cancelled):</td><td class="text-end text-success">PKR&nbsp;<?php echo money($total / 100); ?></td><td colspan="<?php echo $is_admin ? 2 : 1; ?>"></td></tr></tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php if ($is_admin): ?>
<div class="modal fade" id="cancelModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content rounded-4 border-0 shadow">
        <div class="modal-header border-0"><h5 class="modal-title fw-bold text-danger"><i class="fas fa-ban me-2"></i>Cancel receipt?</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <form action="" method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="receipt_id" id="cancelReceiptId">
            <div class="modal-body">
                <p class="small mb-2" id="cancelLabel"></p>
                <p class="small text-muted">The receipt stays on record and its voucher is marked CANCELLED, so it no longer counts in reports.</p>
                <label class="form-label fw-semibold">Reason <span class="req">*</span></label>
                <textarea name="cancel_reason" class="form-control" rows="2" minlength="5" maxlength="255" required></textarea>
            </div>
            <div class="modal-footer border-0"><button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Close</button><button type="submit" name="cancel_receipt" class="btn btn-danger rounded-pill px-4">Cancel Receipt</button></div>
        </form>
    </div></div>
</div>
<script>
document.getElementById('cancelModal').addEventListener('show.bs.modal', function (ev) {
    document.getElementById('cancelReceiptId').value = ev.relatedTarget.getAttribute('data-receipt');
    document.getElementById('cancelLabel').textContent = ev.relatedTarget.getAttribute('data-label');
});
</script>
<?php endif; ?>
<?php include __DIR__ . '/footer.php'; ?>
