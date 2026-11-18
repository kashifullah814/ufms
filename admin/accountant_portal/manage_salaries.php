<?php
/**
 * Staff payroll: monthly salary bill banana (ek staff ka ek mahine mein ek hi bill), Pay, edit/delete (sirf Pending).
 * Pay type ke hisaab se: Regular/Contract = monthly salary; Visiting = hours x rate; Daily Wages = days x rate.
 * Pay karne par payment voucher (PV: Dr Salaries-<type> / Cr Bank / Cr Deductions Payable) usi transaction mein.
 * Paid bill read-only hai (financial record). Month ke hisaab se employment-type summary.
 * Permission: salaries
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
$user = require_permission('salaries');

/**
 * Bill ki raqam validate karta hai. $basis = 'Monthly' | 'Hourly' | 'Daily' (staff/bill ka).
 * $d mein: designation, base, units, rate, allowances, deductions, month.
 */
function read_salary_amounts(&$d, $basis, $prefix = '')
{
    $d = [
        'designation' => trim((string)($_POST[$prefix . 'designation'] ?? '')),
        'allowances'  => parse_amount($_POST[$prefix . 'allowances'] ?? '0'),
        'deductions'  => parse_amount($_POST[$prefix . 'deductions'] ?? '0'),
        'month'       => (string)($_POST[$prefix . 'month_year'] ?? ''),
        'units'       => null,
        'rate'        => null,
    ];
    if ($basis === 'Monthly') {
        $d['base'] = parse_amount($_POST[$prefix . 'base_salary'] ?? '');
        if ($d['base'] === null) return 'Monthly salary must be a valid non-negative amount.';
    } else {
        $unit = $basis === 'Hourly' ? 'hours' : 'days';
        $max = $basis === 'Hourly' ? 744 : 31;
        $units = $_POST[$prefix . 'units'] ?? '';
        $d['rate'] = parse_amount($_POST[$prefix . 'rate'] ?? '', false);
        if (!is_numeric($units) || (float)$units <= 0 || (float)$units > $max || round((float)$units, 2) != (float)$units) return "Please enter the {$unit} worked (0.01 to {$max}).";
        if ($d['rate'] === null) return 'Rate must be greater than zero.';
        $d['units'] = round((float)$units, 2);
        $d['base'] = round($d['units'] * $d['rate'], 2);
    }
    if ($d['designation'] === '' || strlen($d['designation']) > 100) return 'Please enter a valid designation.';
    if ($d['allowances'] === null || $d['deductions'] === null) return 'Allowances and deductions must be valid non-negative numbers.';
    if ($d['deductions'] > $d['base'] + $d['allowances']) return 'Deductions cannot be more than gross pay (basic + allowances).';
    if (!valid_month($d['month'])) return 'Please select a valid salary month.';
    if ($d['month'] > date('Y-m', strtotime('+1 month'))) return 'Salary month cannot be more than one month in the future.';
    return null;
}

$bill_sql = "SELECT ss.*, st.staff_id_number, u.full_name FROM staff_salaries ss JOIN staff st ON st.id = ss.staff_id JOIN users u ON u.id = st.user_id WHERE ss.id = ?";

// ---------- GENERATE SALARY BILL ----------
if (isset($_POST['save_salary'])) {
    $staff_id = (int)($_POST['staff_id'] ?? 0);
    $staff = db_row("SELECT st.*, u.full_name, u.status FROM staff st JOIN users u ON u.id = st.user_id WHERE st.id = ?", [$staff_id]);
    $err = $staff ? read_salary_amounts($d, $staff['pay_basis']) : 'Please select a staff member.';
    if (!$err && $staff['status'] !== 'Active') $err = 'This staff member is inactive.';
    if (!$err && db_value("SELECT id FROM staff_salaries WHERE staff_id = ? AND month_year = ?", [$staff_id, $d['month']])) {
        $err = "A salary bill for {$staff['full_name']} for " . month_label($d['month']) . " already exists!";
    }
    if ($err) {
        flash('error', $err);
        redirect_self();
    }
    try {
        db_exec("INSERT INTO staff_salaries (staff_id, month_year, designation, employment_type, units, rate, base_salary, allowances, deductions, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?)",
            [$staff_id, $d['month'], $d['designation'], $staff['employment_type'], $d['units'], $d['rate'], $d['base'], $d['allowances'], $d['deductions'], (int)$user['id']]);
    } catch (mysqli_sql_exception $ex) {
        if (!is_duplicate_error($ex)) throw $ex;
        flash('error', 'A salary bill for this staff member and month already exists!');
        redirect_self();
    }
    audit('SALARY_GENERATED', "{$staff['full_name']} - " . month_label($d['month']) . " ({$staff['employment_type']}) PKR " . money($d['base'] + $d['allowances'] - $d['deductions']));
    flash('success', 'Salary bill generated for ' . month_label($d['month']) . '. Press "Pay" when the salary is paid.');
    redirect_self();
}

// ---------- UPDATE (sirf Pending) ----------
if (isset($_POST['update_salary'])) {
    $sid = (int)($_POST['salary_id'] ?? 0);
    $rec = db_row($bill_sql, [$sid]);
    $err = !$rec ? 'Salary record not found.' : ($rec['status'] !== 'Pending' ? 'A paid salary cannot be edited - it is part of the financial record.' : read_salary_amounts($d, $rec['pay_basis'], 'edit_'));
    if (!$err && db_value("SELECT id FROM staff_salaries WHERE staff_id = ? AND month_year = ? AND id <> ?", [(int)$rec['staff_id'], $d['month'], $sid])) {
        $err = 'Another salary bill already exists for this staff member for ' . month_label($d['month']) . '.';
    }
    if ($err) {
        flash('error', $err);
    } else {
        db_exec("UPDATE staff_salaries SET designation = ?, units = ?, rate = ?, base_salary = ?, allowances = ?, deductions = ?, month_year = ? WHERE id = ? AND status = 'Pending'",
            [$d['designation'], $d['units'], $d['rate'], $d['base'], $d['allowances'], $d['deductions'], $d['month'], $sid]);
        audit('SALARY_UPDATED', "{$rec['full_name']} - " . month_label($d['month']) . ' PKR ' . money($d['base'] + $d['allowances'] - $d['deductions']));
        flash('success', 'Salary bill updated successfully!');
    }
    redirect_self();
}

// ---------- PAY (voucher ke saath) ----------
if (isset($_POST['pay_id'])) {
    $sid = (int)$_POST['pay_id'];
    $today = date('Y-m-d');
    db()->begin_transaction();
    $rec = db_row(str_replace('WHERE ss.id = ?', 'WHERE ss.id = ? FOR UPDATE', $bill_sql), [$sid]);
    if (!$rec || $rec['status'] !== 'Pending') {
        db()->rollback();
        flash('error', 'This salary record is not pending.');
        redirect_self();
    }
    try {
        db_exec("UPDATE staff_salaries SET status = 'Paid', paid_date = ? WHERE id = ?", [$today, $sid]);
        $v = post_salary_voucher($rec, $today, $user);
        db()->commit();
    } catch (VoucherException $ex) {
        db()->rollback();
        flash('error', 'Salary not paid: ' . $ex->getMessage());
        redirect_self();
    }
    audit('SALARY_PAID', "{$rec['full_name']} - " . month_label($rec['month_year']) . ' PKR ' . money($rec['net_salary']) . ", {$v['voucher_no']}");
    flash('success', "Salary of {$rec['full_name']} for " . month_label($rec['month_year']) . " paid (voucher {$v['voucher_no']}).");
    redirect_self();
}

// ---------- DELETE (sirf Pending) ----------
if (isset($_POST['delete_id'])) {
    $sid = (int)$_POST['delete_id'];
    $rec = db_row($bill_sql, [$sid]);
    if (!$rec) {
        flash('error', 'Salary record not found.');
    } elseif ($rec['status'] === 'Paid') {
        flash('error', 'A paid salary cannot be deleted - it is part of the financial record.');
    } else {
        db_exec("DELETE FROM staff_salaries WHERE id = ? AND status = 'Pending'", [$sid]);
        audit('SALARY_DELETED', "{$rec['full_name']} - " . month_label($rec['month_year']));
        flash('success', 'Salary record deleted successfully!');
    }
    redirect_self();
}

// ---------- DATA ----------
$status_filter = in_array($_GET['status'] ?? '', ['Paid', 'Pending'], true) ? $_GET['status'] : '';
$staff_list = db_select("SELECT st.id, st.staff_id_number, st.designation, st.employment_type, st.pay_basis, st.base_salary, st.pay_rate, u.full_name
                         FROM staff st JOIN users u ON u.id = st.user_id WHERE u.status = 'Active' ORDER BY u.full_name");
$sql = "SELECT ss.*, st.staff_id_number, u.full_name, v.id AS voucher_id, v.voucher_no
        FROM staff_salaries ss JOIN staff st ON st.id = ss.staff_id JOIN users u ON u.id = st.user_id
        LEFT JOIN vouchers v ON v.source_type = 'salary' AND v.source_id = ss.id AND v.status = 'Posted'";
$salaries = $status_filter ? db_select("$sql WHERE ss.status = ? ORDER BY ss.month_year DESC, ss.id DESC", [$status_filter]) : db_select("$sql ORDER BY ss.month_year DESC, ss.id DESC");
$totals = db_row("SELECT COALESCE(SUM(CASE WHEN status='Paid' THEN net_salary END),0) paid, COALESCE(SUM(CASE WHEN status='Pending' THEN net_salary END),0) pending FROM staff_salaries");
$show_vouchers = can('accounts');

// Salaries - Established: employment type ke hisaab se subtotal (chuna hua mahina)
$months = array_column(db_select("SELECT DISTINCT month_year FROM staff_salaries ORDER BY month_year DESC")->fetch_all(MYSQLI_ASSOC), 'month_year');
$sum_month = valid_month($_GET['month'] ?? '') ? $_GET['month'] : ($months[0] ?? date('Y-m'));
$by_type = [];
foreach (array_keys(EMPLOYMENT_TYPES) as $t) {
    $by_type[$t] = ['bills' => 0, 'gross' => 0.0, 'deductions' => 0.0, 'net' => 0.0, 'paid' => 0.0, 'pending' => 0.0];
}
$r = db_select("SELECT employment_type, COUNT(*) bills, SUM(base_salary + allowances) gross, SUM(deductions) deductions, SUM(net_salary) net,
                       SUM(CASE WHEN status = 'Paid' THEN net_salary ELSE 0 END) paid, SUM(CASE WHEN status = 'Pending' THEN net_salary ELSE 0 END) pending
                FROM staff_salaries WHERE month_year = ? GROUP BY employment_type", [$sum_month]);
while ($x = $r->fetch_assoc()) {
    $by_type[$x['employment_type']] = ['bills' => (int)$x['bills'], 'gross' => (float)$x['gross'], 'deductions' => (float)$x['deductions'],
                                       'net' => (float)$x['net'], 'paid' => (float)$x['paid'], 'pending' => (float)$x['pending']];
}
$type_total = ['bills' => 0, 'gross' => 0.0, 'deductions' => 0.0, 'net' => 0.0, 'paid' => 0.0, 'pending' => 0.0];
foreach ($by_type as $row) {
    foreach ($type_total as $k => $v) $type_total[$k] += $row[$k];
}

$page_title = 'Staff Payroll';
$nav_title = 'Staff Payroll';
$nav_icon = 'fa-money-check-alt';
$nav_back = 'dashboard';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/topnav.php';
$basis_label = ['Monthly' => 'monthly', 'Hourly' => 'per hour', 'Daily' => 'per day'];
?>
<div class="container pb-5">
    <?php echo flash_render(); ?>
    <div class="row g-3 mb-4">
        <div class="col-md-6"><div class="card stat-card p-3 border-start border-success border-5"><small class="text-muted fw-bold">TOTAL PAID</small><h4 class="fw-bold text-success mb-0">PKR&nbsp;<?php echo money($totals['paid']); ?></h4></div></div>
        <div class="col-md-6"><div class="card stat-card p-3 border-start border-warning border-5"><small class="text-muted fw-bold">PENDING PAYROLL</small><h4 class="fw-bold text-warning mb-0">PKR&nbsp;<?php echo money($totals['pending']); ?></h4></div></div>
    </div>

    <div class="card table-card p-3 mb-4">
        <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
            <h6 class="fw-bold mb-0"><i class="fas fa-layer-group me-2 text-info"></i>Salaries - Established: summary by employment type</h6>
            <form method="GET" class="d-flex gap-2 align-items-center">
                <?php if ($status_filter): ?><input type="hidden" name="status" value="<?php echo e($status_filter); ?>"><?php endif; ?>
                <label class="small fw-semibold">Month</label>
                <input type="month" name="month" class="form-control form-control-sm" value="<?php echo e($sum_month); ?>" onchange="this.form.submit()">
            </form>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0 small sticky-first">
                <thead class="table-light"><tr><th>Employment Type</th><th>Pay Basis</th><th class="text-end">Bills</th><th class="text-end">Gross</th><th class="text-end">Deductions</th><th class="text-end">Net</th><th class="text-end">Paid</th><th class="text-end">Pending</th></tr></thead>
                <tbody>
                <?php foreach ($by_type as $t => $row): ?>
                    <tr class="<?php echo $row['bills'] ? '' : 'text-muted'; ?>">
                        <td class="fw-semibold"><?php echo e($t); ?></td><td><?php echo e(EMPLOYMENT_TYPES[$t] === 'Monthly' ? 'Fixed monthly' : (EMPLOYMENT_TYPES[$t] === 'Hourly' ? 'Hours x rate' : 'Days x rate')); ?></td>
                        <td class="text-end"><?php echo $row['bills']; ?></td><td class="text-end"><?php echo money($row['gross']); ?></td><td class="text-end"><?php echo money($row['deductions']); ?></td>
                        <td class="text-end fw-bold"><?php echo money($row['net']); ?></td><td class="text-end text-success"><?php echo money($row['paid']); ?></td><td class="text-end text-warning"><?php echo money($row['pending']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr class="table-light fw-bold"><td colspan="2">Total - <?php echo e(month_label($sum_month)); ?></td><td class="text-end"><?php echo $type_total['bills']; ?></td><td class="text-end"><?php echo money($type_total['gross']); ?></td>
                    <td class="text-end"><?php echo money($type_total['deductions']); ?></td><td class="text-end"><?php echo money($type_total['net']); ?></td><td class="text-end text-success"><?php echo money($type_total['paid']); ?></td><td class="text-end text-warning"><?php echo money($type_total['pending']); ?></td></tr></tfoot>
            </table>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-4 split-form">
            <div class="card form-card">
                <div class="card-header text-white" style="background:#1e3a8a;"><h6 class="mb-0 py-1"><i class="fas fa-file-invoice-dollar me-2"></i>Generate Salary Bill</h6></div>
                <div class="card-body p-4">
                    <?php if ($staff_list->num_rows === 0): ?>
                        <div class="alert alert-warning small mb-0">No active staff found. <a href="add_staff.php">Register a staff member</a> first.</div>
                    <?php else: ?>
                    <form action="" method="POST" id="billForm">
                        <?php echo csrf_field(); ?>
                        <div class="mb-3"><label class="form-label fw-semibold small">STAFF MEMBER <span class="req">*</span></label>
                            <select name="staff_id" id="staffSelect" class="form-select" required>
                                <option value="">Select staff...</option>
                                <?php while ($s = $staff_list->fetch_assoc()): ?>
                                    <option value="<?php echo (int)$s['id']; ?>" data-designation="<?php echo e($s['designation']); ?>" data-base="<?php echo e($s['base_salary']); ?>"
                                            data-rate="<?php echo e($s['pay_rate']); ?>" data-basis="<?php echo e($s['pay_basis']); ?>" data-type="<?php echo e($s['employment_type']); ?>">
                                        <?php echo e($s['full_name'] . ' (' . $s['staff_id_number'] . ')'); ?></option>
                                <?php endwhile; ?>
                            </select>
                            <div class="form-text" id="typeHint"></div>
                        </div>
                        <div class="mb-3"><label class="form-label fw-semibold small">DESIGNATION <span class="req">*</span></label><input type="text" name="designation" maxlength="100" id="designation" class="form-control" required></div>
                        <div class="row g-2">
                            <div class="col-12 mb-3 js-monthly"><label class="form-label fw-semibold small">MONTHLY SALARY <span class="req">*</span></label><input type="number" step="0.01" min="0" name="base_salary" id="baseSalary" class="form-control" required></div>
                            <div class="col-6 mb-3 js-units" hidden><label class="form-label fw-semibold small" id="unitsLabel">HOURS <span class="req">*</span></label><input type="number" step="0.01" min="0.01" name="units" id="units" class="form-control"></div>
                            <div class="col-6 mb-3 js-units" hidden><label class="form-label fw-semibold small" id="rateLabel">RATE <span class="req">*</span></label><input type="number" step="0.01" min="0.01" name="rate" id="rate" class="form-control"></div>
                            <div class="col-12 mb-3 js-units small text-muted" hidden>Basic pay = <strong id="calcBase">0.00</strong></div>
                            <div class="col-6 mb-3"><label class="form-label fw-semibold small">ALLOWANCES</label><input type="number" step="0.01" min="0" name="allowances" class="form-control" value="0"></div>
                            <div class="col-6 mb-3"><label class="form-label fw-semibold small">DEDUCTIONS</label><input type="number" step="0.01" min="0" name="deductions" class="form-control" value="0"></div>
                            <div class="col-12 mb-3"><label class="form-label fw-semibold small">MONTH <span class="req">*</span></label><input type="month" name="month_year" class="form-control" value="<?php echo date('Y-m'); ?>" required></div>
                        </div>
                        <button type="submit" name="save_salary" class="btn btn-brand w-100 rounded-pill">Generate Bill</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-8 split-list">
            <div class="card table-card p-3">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <h6 class="fw-bold mb-0"><i class="fas fa-list me-2 text-info"></i>Payroll Records</h6>
                    <div class="btn-group btn-group-sm">
                        <a href="?" class="btn <?php echo $status_filter === '' ? 'btn-primary' : 'btn-outline-primary'; ?>">All</a>
                        <a href="?status=Pending" class="btn <?php echo $status_filter === 'Pending' ? 'btn-warning' : 'btn-outline-warning'; ?>">Pending</a>
                        <a href="?status=Paid" class="btn <?php echo $status_filter === 'Paid' ? 'btn-success' : 'btn-outline-success'; ?>">Paid</a>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 small sticky-first">
                        <thead class="table-light"><tr><th>Staff</th><th>Month</th><th class="text-end">Basic</th><th class="text-end">Allow.</th><th class="text-end">Deduct.</th><th class="text-end">Net</th><th>Status</th><th class="text-center">Actions</th></tr></thead>
                        <tbody>
                        <?php if ($salaries->num_rows > 0): while ($row = $salaries->fetch_assoc()): $rid = (int)$row['id']; $pending = $row['status'] === 'Pending'; ?>
                            <tr>
                                <td><div class="fw-bold text-capitalize"><?php echo e($row['full_name']); ?></div><small class="text-muted"><?php echo e($row['staff_id_number'] . ' - ' . $row['designation']); ?></small>
                                    <br><span class="badge bg-light text-dark border"><?php echo e($row['employment_type']); ?></span></td>
                                <td class="text-primary fw-bold text-nowrap"><?php echo e(month_label($row['month_year'])); ?></td>
                                <td class="text-end"><?php echo money($row['base_salary']); ?><?php if ($row['units'] !== null): ?><br><small class="text-muted text-nowrap"><?php echo e(salary_units_label($row)); ?></small><?php endif; ?></td>
                                <td class="text-end <?php echo $row['allowances'] > 0 ? 'text-success' : 'text-muted'; ?>"><?php echo $row['allowances'] > 0 ? '+' : ''; ?><?php echo money($row['allowances']); ?></td>
                                <td class="text-end <?php echo $row['deductions'] > 0 ? 'text-danger' : 'text-muted'; ?>"><?php echo $row['deductions'] > 0 ? '-' : ''; ?><?php echo money($row['deductions']); ?></td>
                                <td class="text-end fw-bold"><?php echo money($row['net_salary']); ?></td>
                                <td><span class="badge <?php echo $pending ? 'bg-warning text-dark' : 'bg-success'; ?>"><?php echo e($row['status']); ?></span>
                                    <?php if ($row['voucher_no']): ?><br><?php if ($show_vouchers): ?><a href="print_voucher.php?id=<?php echo (int)$row['voucher_id']; ?>" target="_blank" class="font-monospace small"><?php echo e($row['voucher_no']); ?></a><?php else: ?><span class="font-monospace small text-muted"><?php echo e($row['voucher_no']); ?></span><?php endif; ?><?php endif; ?></td>
                                <td class="text-center text-nowrap">
                                    <?php if ($pending): ?>
                                        <button type="button" class="btn btn-sm btn-primary rounded-pill px-2" data-confirm-post data-kind="success" data-title="Pay salary?" data-confirm="Pay salary"
                                                data-text="<?php echo e('Pay PKR ' . money($row['net_salary']) . ' to ' . $row['full_name'] . ' for ' . month_label($row['month_year']) . '? A payment voucher will be created and the bill will be locked.'); ?>"
                                                data-fields="<?php echo e(json_encode(['pay_id' => $rid])); ?>">Pay</button>
                                        <button type="button" class="btn btn-sm btn-outline-primary rounded-circle" data-bs-toggle="modal" data-bs-target="#editSal<?php echo $rid; ?>" title="Edit"><i class="fas fa-edit"></i></button>
                                        <button type="button" class="btn btn-sm btn-outline-danger rounded-circle" title="Delete" data-confirm-post data-title="Delete salary bill?" data-confirm="Delete"
                                                data-text="<?php echo e('Delete the ' . month_label($row['month_year']) . ' bill of ' . $row['full_name'] . '?'); ?>"
                                                data-fields="<?php echo e(json_encode(['delete_id' => $rid])); ?>"><i class="fas fa-trash"></i></button>
                                    <?php else: ?>
                                        <a href="print_salary_slip.php?id=<?php echo $rid; ?>" target="_blank" class="btn btn-sm btn-outline-secondary rounded-circle" title="Print Slip"><i class="fas fa-print"></i></a>
                                        <span class="text-muted ms-1" title="Paid bills are locked"><i class="fas fa-lock"></i></span>
                                    <?php endif; ?>

                                    <?php if ($pending): ?>
                                    <div class="modal fade text-start" id="editSal<?php echo $rid; ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog modal-dialog-centered"><div class="modal-content rounded-4 border-0 shadow">
                                            <div class="modal-header border-0"><h5 class="modal-title fw-bold">Edit Salary - <?php echo e($row['full_name']); ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                                            <form action="" method="POST">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="salary_id" value="<?php echo $rid; ?>">
                                                <div class="modal-body">
                                                    <p class="small mb-2"><span class="badge bg-light text-dark border"><?php echo e($row['employment_type']); ?></span> <span class="text-muted">(<?php echo e($basis_label[$row['pay_basis']]); ?>)</span></p>
                                                    <div class="mb-3"><label class="form-label fw-semibold">Designation <span class="req">*</span></label><input type="text" name="edit_designation" maxlength="100" class="form-control" value="<?php echo e($row['designation']); ?>" required></div>
                                                    <div class="row g-2">
                                                        <?php if ($row['pay_basis'] === 'Monthly'): ?>
                                                            <div class="col-12 mb-3"><label class="form-label fw-semibold">Monthly Salary <span class="req">*</span></label><input type="number" step="0.01" min="0" name="edit_base_salary" class="form-control" value="<?php echo e($row['base_salary']); ?>" required></div>
                                                        <?php else: ?>
                                                            <div class="col-6 mb-3"><label class="form-label fw-semibold"><?php echo $row['pay_basis'] === 'Hourly' ? 'Hours' : 'Days'; ?> <span class="req">*</span></label><input type="number" step="0.01" min="0.01" name="edit_units" class="form-control" value="<?php echo e($row['units']); ?>" required></div>
                                                            <div class="col-6 mb-3"><label class="form-label fw-semibold">Rate <span class="req">*</span></label><input type="number" step="0.01" min="0.01" name="edit_rate" class="form-control" value="<?php echo e($row['rate']); ?>" required></div>
                                                        <?php endif; ?>
                                                        <div class="col-6 mb-3"><label class="form-label fw-semibold">Allowances</label><input type="number" step="0.01" min="0" name="edit_allowances" class="form-control" value="<?php echo e($row['allowances']); ?>"></div>
                                                        <div class="col-6 mb-3"><label class="form-label fw-semibold">Deductions</label><input type="number" step="0.01" min="0" name="edit_deductions" class="form-control" value="<?php echo e($row['deductions']); ?>"></div>
                                                        <div class="col-12 mb-1"><label class="form-label fw-semibold">Month <span class="req">*</span></label><input type="month" name="edit_month_year" class="form-control" value="<?php echo e($row['month_year']); ?>" required></div>
                                                    </div>
                                                </div>
                                                <div class="modal-footer border-0"><button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button><button type="submit" name="update_salary" class="btn btn-primary rounded-pill px-4">Save Changes</button></div>
                                            </form>
                                        </div></div>
                                    </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; else: ?>
                            <tr><td colspan="8" class="text-center text-muted py-4">No salary records found.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
// Staff select karne par designation aur pay khud bhar jati hai; hourly/daily staff ke liye hours/days x rate
(function () {
    const sel = document.getElementById('staffSelect');
    if (!sel) return;
    const $ = function (id) { return document.getElementById(id); };
    function recalc() {
        const u = parseFloat($('units').value) || 0, r = parseFloat($('rate').value) || 0;
        $('calcBase').textContent = (Math.round(u * r * 100) / 100).toLocaleString('en-US', { minimumFractionDigits: 2 });
    }
    sel.addEventListener('change', function () {
        const opt = sel.options[sel.selectedIndex];
        const basis = opt.dataset.basis || 'Monthly';
        $('designation').value = opt.dataset.designation || '';
        document.querySelectorAll('.js-monthly').forEach(function (el) { el.hidden = basis !== 'Monthly'; });
        document.querySelectorAll('.js-units').forEach(function (el) { el.hidden = basis === 'Monthly'; });
        $('baseSalary').required = basis === 'Monthly';
        $('units').required = $('rate').required = basis !== 'Monthly';
        if (basis === 'Monthly') { $('baseSalary').value = opt.dataset.base || ''; }
        else { $('rate').value = opt.dataset.rate || ''; $('units').value = ''; }
        $('unitsLabel').firstChild.textContent = basis === 'Hourly' ? 'HOURS WORKED ' : 'DAYS WORKED ';
        $('rateLabel').firstChild.textContent = basis === 'Hourly' ? 'RATE / HOUR ' : 'RATE / DAY ';
        $('typeHint').textContent = opt.value ? opt.dataset.type + ' - ' + (basis === 'Monthly' ? 'fixed monthly salary' : (basis === 'Hourly' ? 'paid per hour' : 'paid per day')) : '';
        recalc();
    });
    $('units').addEventListener('input', recalc);
    $('rate').addEventListener('input', recalc);
})();
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
