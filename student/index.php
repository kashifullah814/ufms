<?php
/** Student portal: profile, fee summary, challan history (detail modal), receipts. */
require_once __DIR__ . '/../includes/bootstrap.php';
$user = require_login(['Student'], 'Please login with your Student Account to view this page.');

$student = db_row(
    "SELECT s.*, u.full_name, u.email, fs.program_name
     FROM students s JOIN users u ON u.id = s.user_id LEFT JOIN fee_structure fs ON fs.id = s.program_id
     WHERE s.user_id = ?", [(int)$user['id']]);

$fees = [];
$charges = [];
$summary = ['payable' => 0, 'paid' => 0, 'balance' => 0];
if ($student) {
    $fees = db_select("SELECT * FROM fees_records WHERE student_id = ? ORDER BY COALESCE(semester_no, 0) DESC, id DESC", [(int)$student['id']])->fetch_all(MYSQLI_ASSOC);
    // Hostel, transport, DMC waghera (cancelled receipts nahi dikhte)
    $charges = db_select("SELECT r.amount, r.receipt_date, r.reference_no, r.description, a.name AS head_name, v.voucher_no
                          FROM other_receipts r JOIN accounts a ON a.id = r.account_id
                          JOIN vouchers v ON v.source_type = 'receipt' AND v.source_id = r.id AND v.status = 'Posted'
                          WHERE r.student_id = ? ORDER BY r.receipt_date DESC, r.id DESC", [(int)$student['id']])->fetch_all(MYSQLI_ASSOC);
    foreach ($fees as $f) {
        $summary['payable'] += $f['total_fee'] + $f['late_fee_fine'];
        $summary['paid']    += $f['amount_paid'];
        $summary['balance'] += $f['balance'];
    }
}

$page_title = 'Student Portal';
$nav_title = APP_NAME . ' Student Portal';
$nav_icon = 'fa-university';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/topnav.php';
?>
<div class="container pb-5">
    <?php echo flash_render(); ?>
    <?php $rem_user = $user; include __DIR__ . '/../includes/reminders_panel.php'; ?>
    <?php if (!$student): ?>
        <div class="alert alert-warning">Your student profile is not set up yet. Please contact the Finance Department.</div>
    <?php else: ?>
    <div class="row g-4">
        <div class="col-xxl-4">
            <div class="card border-0 shadow p-4 text-white rounded-4" style="background:linear-gradient(135deg,#1e3a8a,#1e40af);">
                <div class="text-center mb-4">
                    <div class="bg-white text-primary rounded-circle d-inline-block p-3 mb-3 shadow-sm"><i class="fas fa-user-graduate fa-3x"></i></div>
                    <h4 class="mb-0 fw-bold text-capitalize" style="font-size:1.2rem;overflow-wrap:anywhere;"><?php echo e($student['full_name']); ?></h4>
                    <p class="small opacity-75"><?php echo e($student['email']); ?></p>
                </div>
                <div class="mb-3"><small class="opacity-75 d-block">REGISTRATION NUMBER</small><span class="fw-bold"><?php echo e($student['reg_number']); ?></span></div>
                <div class="mb-3"><small class="opacity-75 d-block">PROGRAM</small><span class="fw-bold"><?php echo e($student['program_name'] ?: 'Not assigned'); ?></span></div>
                <div><small class="opacity-75 d-block">CURRENT SEMESTER</small><span class="badge bg-light text-primary fs-6">Semester <?php echo (int)$student['semester']; ?></span></div>
            </div>
            <div class="card border-0 shadow-sm mt-4 p-3 rounded-4">
                <p class="text-muted small mb-0"><i class="fas fa-info-circle me-1 text-primary"></i> In case of any discrepancy in your fee record, please visit the Finance Department with your original bank deposit slip.</p>
            </div>
        </div>
        <div class="col-xxl-8">
            <div class="row g-3 mb-4">
                <div class="col-md-4"><div class="card stat-card p-3 border-start border-primary border-5"><small class="text-muted fw-bold">TOTAL PAYABLE</small><h5 class="fw-bold mb-0">PKR&nbsp;<?php echo money($summary['payable']); ?></h5></div></div>
                <div class="col-md-4"><div class="card stat-card p-3 border-start border-success border-5"><small class="text-muted fw-bold">TOTAL PAID</small><h5 class="fw-bold text-success mb-0">PKR&nbsp;<?php echo money($summary['paid']); ?></h5></div></div>
                <div class="col-md-4"><div class="card stat-card p-3 border-start border-danger border-5"><small class="text-muted fw-bold">REMAINING BALANCE</small><h5 class="fw-bold text-danger mb-0">PKR&nbsp;<?php echo money($summary['balance']); ?></h5></div></div>
            </div>
            <div class="card table-card p-4">
                <h5 class="fw-bold mb-4"><i class="fas fa-history me-2 text-primary"></i>Fee Payment History</h5>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light"><tr><th>Challan No</th><th>Semester</th><th>Payable</th><th>Paid</th><th>Balance</th><th>Status</th><th class="text-center">Action</th></tr></thead>
                        <tbody>
                        <?php if ($fees): foreach ($fees as $row): $rid = (int)$row['id']; ?>
                            <tr>
                                <td><a href="#" class="fw-bold text-decoration-none" data-bs-toggle="modal" data-bs-target="#challan<?php echo $rid; ?>"><?php echo e($row['challan_number']); ?></a></td>
                                <td><?php echo $row['semester_no'] ? 'Semester ' . (int)$row['semester_no'] : '-'; ?></td>
                                <td>PKR&nbsp;<?php echo money($row['total_fee'] + $row['late_fee_fine']); ?></td>
                                <td class="text-success">PKR&nbsp;<?php echo money($row['amount_paid']); ?></td>
                                <td class="<?php echo $row['balance'] > 0 ? 'text-danger fw-bold' : ''; ?>">PKR&nbsp;<?php echo money($row['balance']); ?></td>
                                <td><span class="badge rounded-pill px-3 py-2 <?php echo $row['paid_status'] === 'Paid' ? 'bg-success' : ($row['paid_status'] === 'Partial' ? 'bg-warning text-dark' : 'bg-danger'); ?>"><?php echo e($row['paid_status']); ?></span></td>
                                <td class="text-center"><a href="print_receipt.php?id=<?php echo $rid; ?>" target="_blank" class="btn btn-sm btn-light border rounded-pill px-3"><i class="fas fa-print me-1"></i> <?php echo $row['amount_paid'] > 0 ? 'Receipt' : 'Challan'; ?></a></td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="7" class="text-center py-5 text-muted"><i class="fas fa-folder-open fa-2x d-block mb-2 opacity-25"></i>No fee records found.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php if ($charges): ?>
            <div class="card table-card p-4 mt-4">
                <h5 class="fw-bold mb-3"><i class="fas fa-hand-holding-usd me-2 text-primary"></i>Other Payments</h5>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0 small">
                        <thead class="table-light"><tr><th>Date</th><th>Charge</th><th>Receipt No.</th><th>Voucher</th><th class="text-end">Amount (PKR)</th></tr></thead>
                        <tbody>
                        <?php foreach ($charges as $c): ?>
                            <tr><td><?php echo e(fmt_date($c['receipt_date'])); ?></td><td><?php echo e($c['head_name']); ?><?php if ($c['description']): ?><br><small class="text-muted"><?php echo e($c['description']); ?></small><?php endif; ?></td>
                                <td><?php echo e($c['reference_no'] ?: '-'); ?></td><td class="font-monospace"><?php echo e($c['voucher_no']); ?></td><td class="text-end fw-bold text-success"><?php echo money($c['amount']); ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php foreach ($fees as $row): $rid = (int)$row['id']; ?>
    <div class="modal fade" id="challan<?php echo $rid; ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered"><div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header border-0"><h5 class="modal-title fw-bold">Challan <?php echo e($row['challan_number']); ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <table class="table table-sm mb-0">
                    <tr><td>Semester</td><td class="text-end"><?php echo $row['semester_no'] ? (int)$row['semester_no'] : '-'; ?></td></tr>
                    <tr><td>Semester Fee</td><td class="text-end">PKR&nbsp;<?php echo money($row['total_fee']); ?></td></tr>
                    <tr><td>Late Fee Fine</td><td class="text-end">PKR&nbsp;<?php echo money($row['late_fee_fine']); ?></td></tr>
                    <tr class="fw-bold"><td>Total Payable</td><td class="text-end">PKR&nbsp;<?php echo money($row['total_fee'] + $row['late_fee_fine']); ?></td></tr>
                    <tr class="text-success"><td>Amount Paid</td><td class="text-end">PKR&nbsp;<?php echo money($row['amount_paid']); ?></td></tr>
                    <tr class="fw-bold text-danger"><td>Remaining Balance</td><td class="text-end">PKR&nbsp;<?php echo money($row['balance']); ?></td></tr>
                    <tr><td>Status</td><td class="text-end"><?php echo e($row['paid_status']); ?></td></tr>
                    <tr><td>Last Payment Date</td><td class="text-end"><?php echo e(fmt_date($row['payment_date'])); ?></td></tr>
                </table>
            </div>
            <div class="modal-footer border-0"><a href="print_receipt.php?id=<?php echo $rid; ?>" target="_blank" class="btn btn-primary rounded-pill px-4"><i class="fas fa-print me-1"></i> Print</a></div>
        </div></div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
