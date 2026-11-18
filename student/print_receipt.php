<?php
/**
 * Fee receipt / challan (print + PDF).
 * Student sirf apni receipt dekh sakta hai; Accountant (fees permission) aur Admin koi bhi.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
$user = require_login(['Student', 'Accountant', 'Admin'], 'Please login to view fee receipts.');

$data = db_row(
    "SELECT fr.*, s.reg_number, s.semester AS current_semester, s.user_id, u.full_name, fs.program_name
     FROM fees_records fr JOIN students s ON fr.student_id = s.id JOIN users u ON s.user_id = u.id
     LEFT JOIN fee_structure fs ON fs.id = s.program_id
     WHERE fr.id = ?", [(int)($_GET['id'] ?? 0)]);

$allowed = $data && (
    ($user['role'] === 'Student' && (int)$data['user_id'] === (int)$user['id']) ||
    ($user['role'] !== 'Student' && can('fees'))
);
if (!$allowed) {
    flash('error', 'Receipt not found or you are not allowed to view it.');
    redirect(dashboard_url($user['role']));
}
$back = $user['role'] === 'Student' ? url('student/index.php') : url('admin/accountant_portal/collect_fee.php?id=' . (int)$data['student_id']);
$is_receipt = (float)$data['amount_paid'] > 0;
$payments = db_select("SELECT fp.amount, fp.payment_date, v.voucher_no FROM fee_payments fp
                       LEFT JOIN vouchers v ON v.source_type = 'fee_payment' AND v.source_id = fp.id AND v.status = 'Posted'
                       WHERE fp.fee_record_id = ? ORDER BY fp.payment_date, fp.id", [(int)$data['id']])->fetch_all(MYSQLI_ASSOC);

$page_title = ($is_receipt ? 'Fee Receipt ' : 'Fee Challan ') . $data['challan_number'];
$no_animations = true;  // print page
include __DIR__ . '/../includes/header.php';
?>
<div class="container py-4" style="max-width:800px;">
    <div class="d-flex justify-content-between mb-3 no-print">
        <div class="d-flex gap-2 flex-wrap">
            <a href="<?php echo e($back); ?>" class="btn btn-secondary rounded-pill px-4"><i class="fas fa-arrow-left me-1"></i> Back</a>
            <?php $dl = dashboard_link(); if ($dl['url'] !== $back): ?><a href="<?php echo e($dl['url']); ?>" class="btn btn-outline-secondary rounded-pill px-4"><i class="fas fa-home me-1"></i> <?php echo e($dl['label']); ?></a><?php endif; ?>
        </div>
        <div class="d-flex gap-2">
            <button onclick="window.print()" class="btn btn-primary rounded-pill px-4"><i class="fas fa-print me-1"></i> Print</button>
            <button onclick="downloadPdf()" class="btn btn-danger rounded-pill px-4"><i class="fas fa-file-pdf me-1"></i> Download PDF</button>
        </div>
    </div>
    <div id="receipt" class="bg-white p-4 p-md-5 shadow-sm border rounded">
        <div class="text-center border-bottom pb-3 mb-4">
            <?php echo doc_logo(); ?><h3 class="fw-bold mb-0 doc-title">UNIVERSITY OF SCIENCE &amp; TECHNOLOGY BANNU</h3>
            <p class="text-muted mb-1">Finance Department</p>
            <h5 class="fw-bold"><?php echo $is_receipt ? 'FEE PAYMENT RECEIPT' : 'FEE CHALLAN'; ?></h5>
        </div>
        <div class="row mb-4">
            <div class="col-6">
                <p class="mb-1"><strong>Challan No:</strong> <?php echo e($data['challan_number']); ?></p>
                <p class="mb-0"><strong>Status:</strong> <?php echo e($data['paid_status']); ?></p>
            </div>
            <div class="col-6 text-end">
                <p class="mb-1"><strong>Payment Date:</strong> <?php echo e(fmt_date($data['payment_date'], 'Not paid yet')); ?></p>
                <p class="mb-0"><strong>Printed:</strong> <?php echo e(fmt_date(date('Y-m-d'))); ?></p>
            </div>
        </div>
        <table class="table table-bordered">
            <tbody>
                <tr><td class="bg-light fw-bold" style="width:40%;">Student Name</td><td class="text-capitalize"><?php echo e($data['full_name']); ?></td></tr>
                <tr><td class="bg-light fw-bold">Registration No</td><td><?php echo e($data['reg_number']); ?></td></tr>
                <tr><td class="bg-light fw-bold">Program</td><td><?php echo e($data['program_name'] ?: '-'); ?></td></tr>
                <tr><td class="bg-light fw-bold">Semester</td><td><?php echo $data['semester_no'] ? 'Semester ' . (int)$data['semester_no'] : '-'; ?></td></tr>
            </tbody>
        </table>
        <table class="table table-bordered">
            <thead class="table-light"><tr><th>Description</th><th class="text-end">Amount (PKR)</th></tr></thead>
            <tbody>
                <tr><td>Semester Fee</td><td class="text-end"><?php echo money($data['total_fee']); ?></td></tr>
                <tr><td>Late Fee Fine</td><td class="text-end"><?php echo money($data['late_fee_fine']); ?></td></tr>
                <tr class="fw-bold"><td>Total Payable</td><td class="text-end"><?php echo money($data['total_fee'] + $data['late_fee_fine']); ?></td></tr>
                <tr class="table-success fw-bold"><td>Amount Paid</td><td class="text-end"><?php echo money($data['amount_paid']); ?></td></tr>
                <tr class="<?php echo $data['balance'] > 0 ? 'table-danger' : ''; ?> fw-bold fs-5"><td>Remaining Balance</td><td class="text-end"><?php echo money($data['balance']); ?></td></tr>
            </tbody>
        </table>
        <?php if ($payments): ?>
        <table class="table table-bordered table-sm small">
            <thead class="table-light"><tr><th>Payment Date</th><th>Receipt Voucher</th><th class="text-end">Amount (PKR)</th></tr></thead>
            <tbody>
            <?php foreach ($payments as $p): ?>
                <tr><td><?php echo e(fmt_date($p['payment_date'])); ?></td><td class="font-monospace"><?php echo e($p['voucher_no'] ?: '-'); ?></td><td class="text-end"><?php echo money($p['amount']); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <div class="row mt-5 pt-4">
            <div class="col-6"><p class="border-top pt-2 text-center mx-4">Depositor Signature</p></div>
            <div class="col-6"><p class="border-top pt-2 text-center mx-4">Accounts Officer</p></div>
        </div>
        <p class="text-center text-muted small mt-3 mb-0">This is a computer generated document.</p>
    </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
function downloadPdf() {
    html2pdf().set({ margin: 10, filename: <?php echo json_encode(($is_receipt ? 'Fee_Receipt_' : 'Fee_Challan_') . $data['challan_number'] . '.pdf'); ?>, html2canvas: { scale: 2 }, jsPDF: { format: 'a4' } })
        .from(document.getElementById('receipt')).save();
}
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
