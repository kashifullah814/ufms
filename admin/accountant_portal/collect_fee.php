<?php
/**
 * Semester fee collection.
 * - Naya challan: total fee server par fee structure se banti hai (haath se nahi likhi jati).
 * - Partial payment: baqi raqam usi challan par add hoti hai (duplicate record nahi banta).
 * - Har payment ki alag row (fee_payments) aur uska receipt voucher (RV: Dr Bank / Cr fee heads)
 *   usi transaction mein banta hai.
 * Permission: fees
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
$user = require_permission('fees');

$s_id = (int)($_GET['id'] ?? 0);
$student = db_row(
    "SELECT s.*, u.full_name, u.email, u.status, fs.program_name
     FROM students s JOIN users u ON s.user_id = u.id LEFT JOIN fee_structure fs ON fs.id = s.program_id
     WHERE s.id = ?", [$s_id]);
if (!$student) {
    flash('error', 'Student not found. Please select a student from the list.');
    redirect(url('admin/accountant_portal/manage_fees.php'));
}

$fee_id = (int)($_GET['fee_id'] ?? 0);
$sem = (int)($_GET['sem'] ?? $student['semester']);
if ($sem < 1 || $sem > MAX_SEMESTERS) $sem = (int)$student['semester'];
$today = date('Y-m-d');

// ---------- NEW CHALLAN ----------
if (isset($_POST['save_fee'])) {
    $sem     = (int)($_POST['semester_no'] ?? 0);
    $challan = strtoupper(trim((string)($_POST['challan_no'] ?? '')));
    $fine    = parse_amount($_POST['late_fee_fine'] ?? '0');
    $paid    = parse_amount($_POST['amount_paid'] ?? '');
    $p_date  = (string)($_POST['p_date'] ?? '');
    $fee     = semester_fee_breakdown($student, $sem);
    $err = null;

    if ($sem < 1 || $sem > MAX_SEMESTERS) $err = 'Please select a valid semester.';
    elseif (!preg_match('/^[A-Z0-9\-\/]{3,30}$/', $challan)) $err = 'Challan number may only contain letters, numbers, "-" and "/" (3-30 characters).';
    elseif ($fine === null) $err = 'Late fee fine must be a valid non-negative amount.';
    elseif ($paid === null) $err = 'Amount paid must be a valid non-negative amount.';
    elseif ($paid > $fee['total'] + $fine) $err = 'Amount paid cannot be more than the total payable (PKR ' . money($fee['total'] + $fine) . ').';
    elseif ($paid > 0 && (!valid_date($p_date) || $p_date > $today)) $err = 'Please enter a valid payment date (not in the future).';
    elseif ($fee['total'] + $fine <= 0) $err = 'Total payable is zero. Ask the Admin to set the fee structure for this program first.';
    elseif (db_value("SELECT id FROM fees_records WHERE student_id = ? AND semester_no = ?", [$s_id, $sem])) $err = "A challan for Semester {$sem} already exists. Receive the remaining payment on that challan instead.";

    if ($err) {
        flash('error', $err);
        redirect_self(['sem' => $sem]);
    }
    $split = fee_challan_split($fee);
    db()->begin_transaction();
    try {
        db_exec("INSERT INTO fees_records (student_id, challan_number, semester_no, total_fee, tuition_amt, admission_amt, exam_amt, other_amt,
                                           late_fee_fine, amount_paid, paid_status, payment_date, collected_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$s_id, $challan, $sem, $fee['total'], $split['tuition'], $split['admission'], $split['exam'], $split['other'],
             $fine, $paid, fee_status($fee['total'], $fine, $paid), $paid > 0 ? $p_date : null, (int)$user['id']]);
        $v = null;
        if ($paid > 0) {
            $rec = db_row("SELECT * FROM fees_records WHERE id = ?", [db_insert_id()]);
            $v = record_fee_payment($rec, $paid, $p_date, $student, $user);
        }
        db()->commit();
    } catch (mysqli_sql_exception $ex) {
        db()->rollback();
        if (!is_duplicate_error($ex)) throw $ex;
        flash('error', 'This challan number is already used, or this semester already has a challan.');
        redirect_self(['sem' => $sem]);
    } catch (VoucherException $ex) {
        db()->rollback();
        flash('error', 'Fee not saved: ' . $ex->getMessage());
        redirect_self(['sem' => $sem]);
    }
    $balance = round($fee['total'] + $fine - $paid, 2);
    audit('FEE_COLLECTED', "Challan {$challan}: PKR " . money($paid) . " from {$student['full_name']} ({$student['reg_number']}), Semester {$sem}" . ($v ? ", {$v['voucher_no']}" : ''));
    flash('success', "Semester {$sem} fee recorded (Challan {$challan}). Paid: PKR " . money($paid) . " | Balance: PKR " . money($balance) . ($v ? " | Voucher {$v['voucher_no']}" : ''));
    redirect(url('admin/accountant_portal/collect_fee.php?id=' . $s_id));
}

// ---------- PAYMENT ON EXISTING CHALLAN ----------
if (isset($_POST['add_payment'])) {
    $fid    = (int)($_POST['fee_id'] ?? 0);
    $amount = parse_amount($_POST['amount'] ?? '', false);
    $extra  = parse_amount($_POST['extra_fine'] ?? '0');
    $p_date = (string)($_POST['p_date'] ?? '');

    db()->begin_transaction();
    $rec = db_row("SELECT * FROM fees_records WHERE id = ? AND student_id = ? FOR UPDATE", [$fid, $s_id]);
    $err = null;
    if (!$rec) $err = 'Fee record not found.';
    elseif ($amount === null) $err = 'Please enter a valid amount greater than zero.';
    elseif ($extra === null) $err = 'Late fine must be a valid non-negative amount.';
    elseif ($amount > (float)$rec['balance'] + $extra) $err = 'Amount cannot be more than the remaining balance (PKR ' . money((float)$rec['balance'] + $extra) . ').';
    elseif (!valid_date($p_date) || $p_date > $today) $err = 'Please enter a valid payment date (not in the future).';

    if ($err) {
        db()->rollback();
        flash('error', $err);
        redirect_self();
    }
    $new_paid = round((float)$rec['amount_paid'] + $amount, 2);
    $new_fine = round((float)$rec['late_fee_fine'] + $extra, 2);
    try {
        // Purane challan ki pichli raqam ka voucher pehle (uski asal date par), phir yeh payment
        ensure_legacy_fee_payment($rec, $student, $user);
        db_exec("UPDATE fees_records SET amount_paid = ?, late_fee_fine = ?, paid_status = ?, payment_date = ?, collected_by = ? WHERE id = ?",
            [$new_paid, $new_fine, fee_status($rec['total_fee'], $new_fine, $new_paid), $p_date, (int)$user['id'], $fid]);
        $v = record_fee_payment(array_merge($rec, ['late_fee_fine' => $new_fine]), $amount, $p_date, $student, $user);
        db()->commit();
    } catch (VoucherException $ex) {
        db()->rollback();
        flash('error', 'Payment not saved: ' . $ex->getMessage());
        redirect_self();
    }

    $balance = round((float)$rec['total_fee'] + $new_fine - $new_paid, 2);
    audit('FEE_COLLECTED', "Challan {$rec['challan_number']}: PKR " . money($amount) . " received from {$student['full_name']} ({$student['reg_number']}), {$v['voucher_no']}");
    flash('success', "Payment of PKR " . money($amount) . " added to challan {$rec['challan_number']} (voucher {$v['voucher_no']}). Remaining balance: PKR " . money($balance));
    redirect(url('admin/accountant_portal/collect_fee.php?id=' . $s_id));
}

// ---------- PAGE DATA ----------
$history = db_select("SELECT * FROM fees_records WHERE student_id = ? ORDER BY COALESCE(semester_no, 0) DESC, id DESC", [$s_id]);
$payments = [];
$pr = db_select("SELECT fp.*, v.id AS voucher_id, v.voucher_no FROM fee_payments fp JOIN fees_records fr ON fr.id = fp.fee_record_id
                 LEFT JOIN vouchers v ON v.source_type = 'fee_payment' AND v.source_id = fp.id AND v.status = 'Posted'
                 WHERE fr.student_id = ? ORDER BY fp.payment_date, fp.id", [$s_id]);
while ($p = $pr->fetch_assoc()) {
    $payments[(int)$p['fee_record_id']][] = $p;
}
$show_vouchers = can('accounts');
$summary = db_row("SELECT COALESCE(SUM(total_fee + late_fee_fine),0) payable, COALESCE(SUM(amount_paid),0) paid, COALESCE(SUM(balance),0) balance FROM fees_records WHERE student_id = ?", [$s_id]);

$pay_rec = null;
if ($fee_id) {
    $pay_rec = db_row("SELECT * FROM fees_records WHERE id = ? AND student_id = ?", [$fee_id, $s_id]);
    if (!$pay_rec || (float)$pay_rec['balance'] <= 0) {
        $pay_rec = null;
    }
}
$existing_for_sem = db_row("SELECT * FROM fees_records WHERE student_id = ? AND semester_no = ?", [$s_id, $sem]);
$fee = semester_fee_breakdown($student, $sem);

$page_title = 'Collect Fee';
$nav_title = 'Collect Fee';
$nav_icon = 'fa-university';
$nav_back = 'manage_fees.php';
$nav_back_label = 'Fee Collection';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/topnav.php';
?>
<div class="container pb-5">
    <?php echo flash_render(); ?>

    <div class="card form-card mb-4">
        <div class="card-body p-4">
            <div class="row g-3">
                <div class="col-md-3"><small class="text-muted d-block">STUDENT</small><strong class="text-capitalize"><?php echo e($student['full_name']); ?></strong></div>
                <div class="col-md-3"><small class="text-muted d-block">REG NUMBER</small><strong><?php echo e($student['reg_number']); ?></strong></div>
                <div class="col-md-3"><small class="text-muted d-block">PROGRAM</small><span class="badge bg-info"><?php echo e($student['program_name'] ?: 'Not assigned'); ?></span></div>
                <div class="col-md-3"><small class="text-muted d-block">CURRENT SEMESTER</small><strong>Semester <?php echo (int)$student['semester']; ?></strong></div>
            </div>
            <hr>
            <div class="row text-center g-2">
                <div class="col-12 col-sm-4"><small class="text-muted d-block">TOTAL PAYABLE</small><strong class="text-nowrap">PKR&nbsp;<?php echo money($summary['payable']); ?></strong></div>
                <div class="col-12 col-sm-4"><small class="text-muted d-block">TOTAL PAID</small><strong class="text-success text-nowrap">PKR&nbsp;<?php echo money($summary['paid']); ?></strong></div>
                <div class="col-12 col-sm-4"><small class="text-muted d-block">REMAINING BALANCE</small><strong class="text-danger text-nowrap">PKR&nbsp;<?php echo money($summary['balance']); ?></strong></div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-6">
            <?php if ($pay_rec): ?>
            <div class="card form-card">
                <div class="card-header text-white" style="background:#1e3a8a;"><h6 class="mb-0 py-1"><i class="fas fa-plus-circle me-2"></i>Receive Payment - Challan <?php echo e($pay_rec['challan_number']); ?></h6></div>
                <div class="card-body p-4">
                    <p class="mb-3 small">Total: <strong>PKR&nbsp;<?php echo money($pay_rec['total_fee']); ?></strong> | Fine: <strong>PKR&nbsp;<?php echo money($pay_rec['late_fee_fine']); ?></strong> | Paid: <strong class="text-success">PKR&nbsp;<?php echo money($pay_rec['amount_paid']); ?></strong> | Balance: <strong class="text-danger">PKR&nbsp;<?php echo money($pay_rec['balance']); ?></strong></p>
                    <form action="" method="POST">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="fee_id" value="<?php echo (int)$pay_rec['id']; ?>">
                        <div class="row g-3">
                            <div class="col-md-6"><label class="form-label fw-bold">Amount Received <span class="req">*</span></label><input type="number" step="0.01" min="0.01" name="amount" class="form-control border-primary" value="<?php echo e($pay_rec['balance']); ?>" required></div>
                            <div class="col-md-6"><label class="form-label fw-bold">Add Late Fine</label><input type="number" step="0.01" min="0" name="extra_fine" class="form-control" value="0"></div>
                            <div class="col-md-6"><label class="form-label fw-bold">Payment Date <span class="req">*</span></label><input type="date" name="p_date" class="form-control" value="<?php echo $today; ?>" max="<?php echo $today; ?>" required></div>
                        </div>
                        <div class="mt-4 d-flex justify-content-between">
                            <a href="collect_fee.php?id=<?php echo $s_id; ?>" class="btn btn-light rounded-pill px-4">Cancel</a>
                            <button type="submit" name="add_payment" class="btn btn-brand rounded-pill px-4">Save Payment</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php else: ?>
            <div class="card form-card">
                <div class="card-header text-white" style="background:#1e3a8a;"><h6 class="mb-0 py-1"><i class="fas fa-file-invoice me-2"></i>New Semester Challan</h6></div>
                <div class="card-body p-4">
                    <form action="" method="GET" class="mb-3">
                        <input type="hidden" name="id" value="<?php echo $s_id; ?>">
                        <label class="form-label fw-bold">Select Semester</label>
                        <select name="sem" class="form-select border-primary" onchange="this.form.submit()">
                            <?php for ($i = 1; $i <= MAX_SEMESTERS; $i++): ?><option value="<?php echo $i; ?>" <?php echo $i === $sem ? 'selected' : ''; ?>>Semester <?php echo $i; ?></option><?php endfor; ?>
                        </select>
                    </form>

                    <?php if ($existing_for_sem): ?>
                        <div class="alert alert-info mb-0">
                            Semester <?php echo $sem; ?> already has challan <strong><?php echo e($existing_for_sem['challan_number']); ?></strong> (<?php echo e($existing_for_sem['paid_status']); ?>, balance PKR&nbsp;<?php echo money($existing_for_sem['balance']); ?>).
                            <?php if ((float)$existing_for_sem['balance'] > 0): ?>
                                <a href="collect_fee.php?id=<?php echo $s_id; ?>&fee_id=<?php echo (int)$existing_for_sem['id']; ?>" class="btn btn-sm btn-success rounded-pill ms-1">Receive payment</a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <?php if (!$fee['has_structure']): ?><div class="alert alert-warning small">This student's program has no fee structure. Ask the Admin to set it.</div><?php endif; ?>
                        <table class="table table-sm mb-3">
                            <tr><td>Tuition Fee</td><td class="text-end"><?php echo money($fee['tuition']); ?></td></tr>
                            <?php if ($fee['admission'] > 0): ?><tr><td>Admission Fee (1st semester)</td><td class="text-end"><?php echo money($fee['admission']); ?></td></tr><?php endif; ?>
                            <tr><td>Exam Fee</td><td class="text-end"><?php echo money($fee['exam']); ?></td></tr>
                            <tr><td>Other Charges</td><td class="text-end"><?php echo money($fee['other']); ?></td></tr>
                            <?php if ($fee['discount'] > 0): ?><tr class="text-success"><td>Scholarship / Discount</td><td class="text-end">- <?php echo money($fee['discount']); ?></td></tr><?php endif; ?>
                            <tr class="fw-bold table-light"><td>Semester Total (PKR)</td><td class="text-end" id="semTotal" data-total="<?php echo e($fee['total']); ?>"><?php echo money($fee['total']); ?></td></tr>
                        </table>
                        <form action="" method="POST">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="semester_no" value="<?php echo $sem; ?>">
                            <div class="row g-3">
                                <div class="col-md-6"><label class="form-label fw-bold">Challan / Receipt # <span class="req">*</span></label><input type="text" name="challan_no" maxlength="30" class="form-control" placeholder="CN-123456" required></div>
                                <div class="col-md-6"><label class="form-label fw-bold">Payment Date</label><input type="date" name="p_date" class="form-control" value="<?php echo $today; ?>" max="<?php echo $today; ?>"></div>
                                <div class="col-md-4"><label class="form-label fw-bold">Late Fine</label><input type="number" step="0.01" min="0" id="fine" name="late_fee_fine" class="form-control" value="0" oninput="calcRem()"></div>
                                <div class="col-md-4"><label class="form-label fw-bold text-primary">Amount Paid <span class="req">*</span></label><input type="number" step="0.01" min="0" id="paid" name="amount_paid" class="form-control border-primary" placeholder="0.00" oninput="calcRem()" required></div>
                                <div class="col-md-4"><label class="form-label fw-bold text-danger">Balance</label><input type="text" id="rem" class="form-control bg-light" readonly></div>
                            </div>
                            <div class="mt-4 text-end"><button type="submit" name="save_fee" class="btn btn-brand px-5 rounded-pill">Save Record</button></div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="col-lg-6">
            <div class="card table-card p-3">
                <h6 class="fw-bold mb-3"><i class="fas fa-history me-2 text-primary"></i>Fee History</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0 small">
                        <thead class="table-light"><tr><th>Challan</th><th>Sem</th><th>Payable</th><th>Paid</th><th>Balance</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                        <?php if ($history->num_rows > 0): while ($h = $history->fetch_assoc()): ?>
                            <tr>
                                <td class="fw-bold"><?php echo e($h['challan_number']); ?></td>
                                <td><?php echo $h['semester_no'] ? (int)$h['semester_no'] : '-'; ?></td>
                                <td><?php echo money($h['total_fee'] + $h['late_fee_fine']); ?></td>
                                <td class="text-success"><?php echo money($h['amount_paid']); ?></td>
                                <td class="<?php echo $h['balance'] > 0 ? 'text-danger' : ''; ?>"><?php echo money($h['balance']); ?></td>
                                <td><span class="badge <?php echo $h['paid_status'] === 'Paid' ? 'bg-success' : ($h['paid_status'] === 'Partial' ? 'bg-warning text-dark' : 'bg-danger'); ?>"><?php echo e($h['paid_status']); ?></span></td>
                                <td class="text-nowrap">
                                    <?php if ($h['balance'] > 0): ?><a href="collect_fee.php?id=<?php echo $s_id; ?>&fee_id=<?php echo (int)$h['id']; ?>" class="btn btn-sm btn-outline-success py-0" title="Receive payment"><i class="fas fa-plus"></i></a><?php endif; ?>
                                    <a href="<?php echo e(url('student/print_receipt.php?id=' . (int)$h['id'])); ?>" target="_blank" class="btn btn-sm btn-outline-secondary py-0" title="Print"><i class="fas fa-print"></i></a>
                                </td>
                            </tr>
                            <?php foreach ($payments[(int)$h['id']] ?? [] as $p): ?>
                            <tr class="text-muted" style="font-size:.8rem;">
                                <td colspan="3" class="ps-4"><i class="fas fa-level-up-alt fa-rotate-90 me-2"></i>Paid <?php echo e(fmt_date($p['payment_date'])); ?></td>
                                <td class="text-success">+<?php echo money($p['amount']); ?></td>
                                <td colspan="3">
                                    <?php if ($p['voucher_no'] && $show_vouchers): ?><a href="print_voucher.php?id=<?php echo (int)$p['voucher_id']; ?>" target="_blank" class="font-monospace"><?php echo e($p['voucher_no']); ?></a>
                                    <?php elseif ($p['voucher_no']): ?><span class="font-monospace"><?php echo e($p['voucher_no']); ?></span><?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endwhile; else: ?>
                            <tr><td colspan="7" class="text-center text-muted py-3">No fee records yet.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
function calcRem() {
    const totalEl = document.getElementById('semTotal');
    if (!totalEl) return;
    const total = parseFloat(totalEl.dataset.total) || 0;
    const fine = parseFloat(document.getElementById('fine').value) || 0;
    const paid = parseFloat(document.getElementById('paid').value) || 0;
    document.getElementById('rem').value = (total + fine - paid).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
calcRem();
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
