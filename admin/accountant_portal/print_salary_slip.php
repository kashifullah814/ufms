<?php
/**
 * Salary slip (print / PDF).
 * Accountant (salaries permission) aur Admin koi bhi slip dekh sakte hain; Staff sirf apni.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
$user = require_login(['Admin', 'Accountant', 'Staff'], 'Please login to view salary slips.');

$slip = db_row(
    "SELECT ss.*, st.staff_id_number, st.user_id, u.full_name, u.email
     FROM staff_salaries ss JOIN staff st ON st.id = ss.staff_id JOIN users u ON u.id = st.user_id
     WHERE ss.id = ?", [(int)($_GET['id'] ?? 0)]);

$allowed = $slip && (
    ($user['role'] === 'Staff' && (int)$slip['user_id'] === (int)$user['id']) ||
    ($user['role'] !== 'Staff' && can('salaries'))
);
if (!$allowed) {
    flash('error', 'Salary slip not found or you are not allowed to view it.');
    redirect(dashboard_url($user['role']));
}
if ($slip['status'] !== 'Paid') {
    flash('warning', 'A salary slip is available only after the salary has been paid.');
    redirect($user['role'] === 'Staff' ? url('staff/index.php') : url('admin/accountant_portal/manage_salaries.php'));
}
$back = $user['role'] === 'Staff' ? url('staff/index.php') : url('admin/accountant_portal/manage_salaries.php');
$gross = $slip['base_salary'] + $slip['allowances'];
$voucher = voucher_for_source('salary', (int)$slip['id']);

$page_title = 'Salary Slip - ' . month_label($slip['month_year']);
$no_animations = true;  // print page
include __DIR__ . '/../../includes/header.php';
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
    <div id="slip" class="bg-white p-4 p-md-5 shadow-sm border rounded">
        <div class="text-center border-bottom pb-3 mb-4">
            <?php echo doc_logo(); ?><h3 class="fw-bold mb-0 doc-title">UNIVERSITY OF SCIENCE &amp; TECHNOLOGY BANNU</h3>
            <p class="text-muted mb-1">Finance Department</p>
            <h5 class="fw-bold">SALARY SLIP - <?php echo e(strtoupper(month_label($slip['month_year']))); ?></h5>
        </div>
        <div class="row mb-4">
            <div class="col-6">
                <p class="mb-1"><strong>Name:</strong> <?php echo e($slip['full_name']); ?></p>
                <p class="mb-1"><strong>Staff ID:</strong> <?php echo e($slip['staff_id_number']); ?></p>
                <p class="mb-1"><strong>Designation:</strong> <?php echo e($slip['designation']); ?></p>
                <p class="mb-0"><strong>Employment:</strong> <?php echo e($slip['employment_type']); ?></p>
            </div>
            <div class="col-6 text-end">
                <p class="mb-1"><strong>Slip No:</strong> SAL-<?php echo str_pad((string)(int)$slip['id'], 5, '0', STR_PAD_LEFT); ?></p>
                <p class="mb-1"><strong>Salary Month:</strong> <?php echo e(month_label($slip['month_year'])); ?></p>
                <p class="mb-<?php echo $voucher ? '1' : '0'; ?>"><strong>Paid On:</strong> <?php echo e(fmt_date($slip['paid_date'])); ?></p>
                <?php if ($voucher): ?><p class="mb-0"><strong>Voucher:</strong> <?php echo e($voucher['voucher_no']); ?></p><?php endif; ?>
            </div>
        </div>
        <table class="table table-bordered">
            <thead class="table-light"><tr><th>Description</th><th class="text-end">Amount (PKR)</th></tr></thead>
            <tbody>
                <tr><td><?php echo $slip['pay_basis'] === 'Monthly' ? 'Basic Salary' : 'Basic Pay (' . e(salary_units_label($slip)) . ')'; ?></td><td class="text-end"><?php echo money($slip['base_salary']); ?></td></tr>
                <tr><td>Allowances</td><td class="text-end text-success">+ <?php echo money($slip['allowances']); ?></td></tr>
                <tr class="fw-bold"><td>Gross Salary</td><td class="text-end"><?php echo money($gross); ?></td></tr>
                <tr><td>Deductions</td><td class="text-end text-danger">- <?php echo money($slip['deductions']); ?></td></tr>
                <tr class="table-primary fw-bold fs-5"><td>NET SALARY PAID</td><td class="text-end"><?php echo money($slip['net_salary']); ?></td></tr>
            </tbody>
        </table>
        <div class="row mt-5 pt-4">
            <div class="col-6"><p class="border-top pt-2 text-center mx-4">Employee Signature</p></div>
            <div class="col-6"><p class="border-top pt-2 text-center mx-4">Accounts Officer</p></div>
        </div>
        <p class="text-center text-muted small mt-3 mb-0">This is a computer generated slip. Generated on <?php echo e(fmt_datetime(date('Y-m-d H:i:s'))); ?>.</p>
    </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
function downloadPdf() {
    html2pdf().set({ margin: 10, filename: <?php echo json_encode('Salary_Slip_' . $slip['staff_id_number'] . '_' . $slip['month_year'] . '.pdf'); ?>, html2canvas: { scale: 2 }, jsPDF: { format: 'a4' } })
        .from(document.getElementById('slip')).save();
}
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
