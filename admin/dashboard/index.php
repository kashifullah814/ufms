<?php
/** Admin dashboard: live stats, monthly chart, recent transactions, activity logs, accountant permissions. */
require_once __DIR__ . '/../../includes/bootstrap.php';
$admin = require_login(['Admin']);

// --- Accountant permissions / status update ---
if (isset($_POST['update_user'])) {
    $uid    = (int)($_POST['user_id'] ?? 0);
    $status = in_array($_POST['status'] ?? '', ['Active', 'Inactive'], true) ? $_POST['status'] : 'Active';
    $fees   = isset($_POST['can_manage_fees']) ? 1 : 0;
    $sal    = isset($_POST['can_manage_salaries']) ? 1 : 0;
    $exp    = isset($_POST['can_manage_expenses']) ? 1 : 0;
    $accp   = isset($_POST['can_manage_accounts']) ? 1 : 0;

    $acc = db_row("SELECT full_name FROM users WHERE id = ? AND role = 'Accountant'", [$uid]);
    if ($acc) {
        db_exec("UPDATE users SET status = ?, can_manage_fees = ?, can_manage_salaries = ?, can_manage_expenses = ?, can_manage_accounts = ? WHERE id = ? AND role = 'Accountant'",
            [$status, $fees, $sal, $exp, $accp, $uid]);
        audit('ACCOUNTANT_UPDATED', "Permissions/status updated for {$acc['full_name']} ({$status})");
        flash('success', 'Account configuration updated successfully!');
    } else {
        flash('error', 'Accountant not found.');
    }
    redirect_self();
}

// --- Wrong-portal rule se blocked account unlock (strikes bhi reset) ---
if (isset($_POST['unlock_user_id'])) {
    $target = db_row("SELECT id, full_name, email, role FROM users WHERE id = ? AND lock_reason = ?", [(int)$_POST['unlock_user_id'], LOCK_WRONG_PORTAL]);
    if ($target) {
        unlock_account($target);
        flash('success', "{$target['full_name']} has been unlocked and can log in again.");
    } else {
        flash('error', 'This account is not blocked.');
    }
    redirect_self();
}

// --- Delete accountant ---
if (isset($_POST['delete_id'])) {
    $del = (int)$_POST['delete_id'];
    $acc = db_row("SELECT full_name FROM users WHERE id = ? AND role = 'Accountant'", [$del]);
    if ($acc && $del !== (int)$admin['id']) {
        db_exec("DELETE FROM users WHERE id = ? AND role = 'Accountant'", [$del]);
        audit('ACCOUNTANT_DELETED', "Deleted accountant {$acc['full_name']}");
        flash('success', 'Account deleted successfully!');
    } else {
        flash('error', 'Account could not be deleted.');
    }
    redirect_self();
}

// --- Live statistics ---
$total_income      = (float)db_value("SELECT SUM(amount_paid) FROM fees_records", [], 0);
$total_dues        = (float)db_value("SELECT SUM(balance) FROM fees_records WHERE paid_status <> 'Paid'", [], 0);
$total_expense     = (float)db_value("SELECT SUM(amount) FROM expenses", [], 0);
$total_salary_paid = (float)db_value("SELECT SUM(net_salary) FROM staff_salaries WHERE status = 'Paid'", [], 0);
$total_accountants = (int)db_value("SELECT COUNT(*) FROM users WHERE role = 'Accountant'", [], 0);
$pending_approvals = (int)db_value("SELECT COUNT(*) FROM staff_salaries WHERE status = 'Pending'", [], 0);

// --- Monthly chart (last 6 months): fee income vs expenses vs salaries paid ---
$months = [];
for ($i = 5; $i >= 0; $i--) {
    $months[] = date('Y-m', strtotime("first day of -{$i} month"));
}
$from = $months[0] . '-01';
$income_by_month = $expense_by_month = $salary_by_month = array_fill_keys($months, 0.0);
$r = db_select("SELECT DATE_FORMAT(payment_date, '%Y-%m') ym, SUM(amount_paid) t FROM fees_records WHERE payment_date >= ? GROUP BY ym", [$from]);
while ($x = $r->fetch_assoc()) { if (isset($income_by_month[$x['ym']])) $income_by_month[$x['ym']] = (float)$x['t']; }
$r = db_select("SELECT DATE_FORMAT(expense_date, '%Y-%m') ym, SUM(amount) t FROM expenses WHERE expense_date >= ? GROUP BY ym", [$from]);
while ($x = $r->fetch_assoc()) { if (isset($expense_by_month[$x['ym']])) $expense_by_month[$x['ym']] = (float)$x['t']; }
$r = db_select("SELECT month_year ym, SUM(net_salary) t FROM staff_salaries WHERE status = 'Paid' AND month_year >= ? GROUP BY month_year", [$months[0]]);
while ($x = $r->fetch_assoc()) { if (isset($salary_by_month[$x['ym']])) $salary_by_month[$x['ym']] = (float)$x['t']; }
$chart_labels = array_map(function ($m) { return date('M Y', strtotime($m . '-01')); }, $months);
// Chart ke neeche summary strip: inhi 6 mahino ke totals.
// Net = posted vouchers ki SAB receipts (fees, HEC/HED grants, student charges...) minus SAB payments (salaries + non-salaries)
$six_income = array_sum($income_by_month);
$six_expense = array_sum($expense_by_month);
$six_salary = array_sum($salary_by_month);
$six_led = db_row("SELECT COALESCE(SUM(CASE WHEN a.type = 'Income' THEN vl.credit - vl.debit END), 0) AS receipts,
                         COALESCE(SUM(CASE WHEN a.type = 'Expense' THEN vl.debit - vl.credit END), 0) AS payments
                  FROM voucher_lines vl JOIN vouchers v ON v.id = vl.voucher_id JOIN accounts a ON a.id = vl.account_id
                  WHERE v.status = 'Posted' AND v.voucher_date >= ? AND a.type IN ('Income', 'Expense')", [$from]);
$six_receipts = (float)$six_led['receipts'];
$six_payments = (float)$six_led['payments'];
$six_net = $six_receipts - $six_payments;
$six_boxes = [
    ['Fee Income', $six_income, 'success', 'fa-arrow-down'],
    ['Expenses', $six_expense, 'danger', 'fa-receipt'],
    ['Salaries Paid', $six_salary, 'info', 'fa-users'],
    ['Net (all receipts − all payments)', $six_net, $six_net < 0 ? 'danger' : 'success', 'fa-scale-balanced'],
];

// --- Recent feeds ---
$recent_fees = db_select(
    "SELECT fr.challan_number, fr.amount_paid, fr.paid_status, fr.payment_date, u.full_name AS student_name, s.reg_number
     FROM fees_records fr JOIN students s ON fr.student_id = s.id JOIN users u ON s.user_id = u.id
     WHERE fr.amount_paid > 0 ORDER BY fr.payment_date DESC, fr.id DESC LIMIT 5");
$recent_expenses = db_select("SELECT e.title, a.name AS head_name, e.amount, e.expense_date FROM expenses e JOIN accounts a ON a.id = e.account_id ORDER BY e.expense_date DESC, e.id DESC LIMIT 5");
$recent_logs = db_select("SELECT user_name, role, action, details, created_at FROM audit_logs ORDER BY id DESC LIMIT 5");
// Security card: aaj ke auto-locked accounts, roke gaye pages aur ghalat portal logins
$security_today = date('Y-m-d');
$security_counts = ['ACCOUNT_AUTO_LOCKED' => 0, 'ACCESS_DENIED' => 0, 'LOGIN_WRONG_PORTAL' => 0];
$r = db_select("SELECT action, COUNT(*) n FROM audit_logs WHERE action IN ('ACCOUNT_AUTO_LOCKED', 'ACCESS_DENIED', 'LOGIN_WRONG_PORTAL')
                AND created_at >= ? AND created_at < ? + INTERVAL 1 DAY GROUP BY action", [$security_today, $security_today]);
while ($x = $r->fetch_assoc()) { $security_counts[$x['action']] = (int)$x['n']; }
$blocked_users = wrong_portal_blocked_users();
$mgmt_users = db_select("SELECT * FROM users WHERE role = 'Accountant' ORDER BY id DESC LIMIT 10");

$page_title = 'Admin Dashboard';
$use_chart = true;
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/admin_sidebar.php';
?>
<style>
    /* Chart card: chart bachi hui height bharta hai; canvas absolute taake flex height se bara na ho */
    .chart-fill { position: relative; flex: 1 1 auto; min-height: 240px; }
    .chart-fill canvas { position: absolute; inset: 0; }
    .six-box { background: #f8fafc; border-left-width: 4px !important; }
    .clamp-2 { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; overflow-wrap: anywhere; }
</style>
<div class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4 text-white p-3 rounded shadow-sm flex-wrap gap-2" style="background:#1e3a8a;">
        <div>
            <h4 class="mb-0 fw-bold">Welcome, <?php echo e($admin['full_name']); ?>!</h4>
            <small class="opacity-75"><?php echo e(APP_FULL_NAME); ?> - Control Panel</small>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <a href="<?php echo e(url('admin/accountant_portal/manage_salaries.php?status=Pending')); ?>" class="btn btn-warning btn-sm fw-bold rounded-pill position-relative px-3">
                <i class="fas fa-bell me-1"></i> Pending Payrolls
                <?php if ($pending_approvals > 0): ?>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger border border-light"><?php echo $pending_approvals; ?></span>
                <?php endif; ?>
            </a>
            <span class="badge bg-light text-primary px-3 py-2 fs-6">Super Admin</span>
        </div>
    </div>

    <?php echo flash_render(); ?>
    <?php if (!mail_configured()): ?>
        <div class="alert alert-warning py-2 small"><i class="fas fa-envelope me-2"></i><strong>Email is not set up.</strong>
            New students, staff and accountants will not receive their login details by email (they are shown on screen instead), and Forgot Password will not work.
            Set <code>MAIL_USERNAME</code> and a Gmail App Password in <code>MAIL_PASSWORD</code> in <code>includes/config.php</code>.</div>
    <?php endif; ?>

    <div class="row g-4 mb-4">
        <?php
        $cards = [
            ['TOTAL FEE INCOME', money($total_income), 'success', 'fa-wallet'],
            ['PENDING DUES', money($total_dues), 'warning', 'fa-hourglass-half'],
            ['TOTAL EXPENSES', money($total_expense), 'danger', 'fa-chart-line'],
            ['SALARIES PAID', money($total_salary_paid), 'info', 'fa-money-check-alt'],
        ];
        foreach ($cards as $c): ?>
        <div class="col-md-6 col-xl-3">
            <div class="card stat-card p-3 border-start border-<?php echo $c[2]; ?> border-4 bg-white h-100">
                <div class="d-flex align-items-center">
                    <div class="icon-box bg-<?php echo $c[2]; ?> text-white me-3"><i class="fas <?php echo $c[3]; ?>"></i></div>
                    <div class="stat-text">
                        <small class="text-muted fw-bold d-block text-nowrap"><?php echo e($c[0]); ?></small>
                        <div class="stat-value fw-bold text-nowrap text-<?php echo $c[2]; ?>"><span class="stat-cur">PKR</span> <?php echo e($c[1]); ?></div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php $sec_total = array_sum($security_counts); ?>
    <div class="card table-card p-3 mb-4 border-start border-4 <?php echo $sec_total ? 'border-danger' : 'border-success'; ?>">
        <div class="d-flex align-items-center flex-wrap gap-3">
            <div class="d-flex align-items-center me-auto">
                <div class="icon-box <?php echo $sec_total ? 'bg-danger' : 'bg-success'; ?> text-white me-3"><i class="fas fa-user-shield"></i></div>
                <div>
                    <h6 class="fw-bold mb-0">Security <small class="text-muted fw-normal">today</small></h6>
                    <a href="activity_logs.php?<?php echo e(http_build_query(['action' => 'ACCOUNT_AUTO_LOCKED,ACCESS_DENIED,LOGIN_WRONG_PORTAL', 'day' => $security_today])); ?>" class="small text-decoration-none">View in Activity Logs</a>
                </div>
            </div>
            <?php foreach ([['ACCOUNT_AUTO_LOCKED', 'Auto-locked accounts', 'fa-lock'], ['ACCESS_DENIED', 'Access denied', 'fa-ban'], ['LOGIN_WRONG_PORTAL', 'Wrong-portal logins', 'fa-door-closed']] as $s): $n = $security_counts[$s[0]]; ?>
                <a href="activity_logs.php?<?php echo e(http_build_query(['action' => $s[0], 'day' => $security_today])); ?>" class="text-decoration-none text-center px-3 py-1 rounded-3 border <?php echo $n ? 'border-danger-subtle bg-danger-subtle' : 'bg-light'; ?>" style="min-width:120px;">
                    <div class="fs-5 fw-bold <?php echo $n ? 'text-danger' : 'text-secondary'; ?>"><?php echo $n; ?></div>
                    <div class="small text-muted"><i class="fas <?php echo $s[2]; ?> me-1"></i><?php echo e($s[1]); ?></div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card table-card p-3 mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
            <h6 class="fw-bold mb-0"><i class="fas fa-user-lock me-2 text-danger"></i>Blocked accounts
                <small class="text-muted fw-normal">(<?php echo WRONG_PORTAL_MAX_STRIKES; ?> wrong-portal logins in <?php echo WRONG_PORTAL_WINDOW_HOURS; ?> hours)</small></h6>
            <a href="activity_logs.php?action=ACCOUNT_BLOCKED_24H,ACCOUNT_UNLOCKED" class="small text-decoration-none">History</a>
        </div>
        <?php if (!$blocked_users): ?>
            <p class="small text-success mb-0"><i class="fas fa-check-circle me-1"></i>No accounts are blocked.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 small">
                    <thead class="table-light"><tr><th>Name</th><th>Role</th><th>Blocked until</th><th class="text-end">Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($blocked_users as $b): ?>
                        <tr>
                            <td><div class="fw-bold"><?php echo e($b['full_name']); ?></div><span class="text-muted"><?php echo e($b['email']); ?></span></td>
                            <td><?php echo e($b['role']); ?></td>
                            <td class="text-nowrap text-danger"><?php echo e(fmt_datetime($b['locked_until'])); ?></td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-success rounded-pill px-3" data-confirm-post data-kind="success" data-fa="fa-unlock" data-title="Unlock account?" data-confirm="Unlock"
                                        data-text="<?php echo e('Unlock ' . $b['full_name'] . ' now? Their wrong-portal strikes will be reset.'); ?>"
                                        data-fields="<?php echo e(json_encode(['unlock_user_id' => (int)$b['id']])); ?>"><i class="fas fa-unlock me-1"></i>Unlock</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php include __DIR__ . '/../../includes/budget_alerts_panel.php'; ?>

    <div class="row g-4 mb-4">
        <div class="col-xl-8">
            <div class="card table-card p-4 h-100 d-flex flex-column">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold mb-0"><i class="fas fa-chart-bar me-2 text-primary"></i>Monthly Income vs Expenses</h5>
                    <span class="badge bg-soft-primary border">Last 6 months</span>
                </div>
                <!-- Chart bachi hui height le leta hai (card Activity Logs ke barabar hota hai) -->
                <div class="chart-fill"><canvas id="financeChart"></canvas></div>
                <div class="row g-2 mt-3">
                    <?php foreach ($six_boxes as $b): ?>
                    <?php $isNet = $b[3] === 'fa-scale-balanced'; ?>
                    <?php if (!$isNet): ?>
                    <div class="col-12 col-sm-4">
                        <div class="six-box border rounded-3 p-2 h-100 border-<?php echo $b[2]; ?>">
                            <div class="small text-muted text-nowrap"><i class="fas <?php echo $b[3]; ?> me-1 text-<?php echo $b[2]; ?>"></i><?php echo e($b[0]); ?></div>
                            <div class="fw-bold text-nowrap">PKR&nbsp;<?php echo e(money($b[1], 0)); ?></div>
                        </div>
                    </div>
                    <?php else: ?>
                    <!-- Net: posted vouchers ki sab receipts - sab payments (grants aur student charges samet) -->
                    <div class="col-12">
                        <div class="six-box border rounded-3 p-2 border-<?php echo $b[2]; ?> d-flex justify-content-between align-items-center flex-wrap column-gap-3">
                            <div>
                                <div class="small text-muted"><i class="fas <?php echo $b[3]; ?> me-1 text-<?php echo $b[2]; ?>"></i><?php echo e($b[0]); ?></div>
                                <div class="small text-muted">Receipts PKR&nbsp;<?php echo e(money($six_receipts, 0)); ?> &minus; Payments PKR&nbsp;<?php echo e(money($six_payments, 0)); ?></div>
                            </div>
                            <div class="fw-bold fs-5 text-nowrap <?php echo $b[1] < 0 ? 'text-danger' : 'text-success'; ?>">PKR&nbsp;<?php echo e(money($b[1], 0)); ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card table-card p-4 h-100">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold mb-0"><i class="fas fa-history me-2 text-info"></i>Activity Logs</h5>
                    <a href="activity_logs.php" class="small text-decoration-none">View all</a>
                </div>
                <div class="d-flex flex-column gap-2">
                    <?php if ($recent_logs->num_rows > 0): ?>
                        <?php while ($log = $recent_logs->fetch_assoc()): ?>
                        <div class="p-2 rounded audit-item">
                            <div class="d-flex justify-content-between">
                                <strong class="small"><?php echo e($log['user_name'] ?: 'System'); ?></strong>
                                <small class="text-muted" style="font-size:.7rem;"><?php echo e(date('d M, h:i A', strtotime($log['created_at']))); ?></small>
                            </div>
                            <p class="mb-0 small text-secondary clamp-2" title="<?php echo e(log_details($log['details'])); ?>"><?php echo e(str_replace('_', ' ', $log['action'])); ?> - <?php echo e(log_details($log['details'])); ?></p>
                        </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <p class="text-muted small mb-0">No activity recorded yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-xl-6">
            <div class="card table-card p-4 h-100">
                <h5 class="fw-bold mb-3"><i class="fas fa-receipt me-2 text-success"></i>Recent Student Payments</h5>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light"><tr><th>Student</th><th>Reg No</th><th>Paid</th><th>Date</th></tr></thead>
                        <tbody>
                        <?php if ($recent_fees->num_rows > 0): while ($rf = $recent_fees->fetch_assoc()): ?>
                            <tr>
                                <td class="fw-bold text-capitalize"><?php echo e($rf['student_name']); ?></td>
                                <td><span class="badge bg-light text-dark border"><?php echo e($rf['reg_number']); ?></span></td>
                                <td class="fw-bold text-success">PKR&nbsp;<?php echo money($rf['amount_paid']); ?></td>
                                <td class="small text-muted"><?php echo e(fmt_date($rf['payment_date'])); ?></td>
                            </tr>
                        <?php endwhile; else: ?>
                            <tr><td colspan="4" class="text-center text-muted py-3">No payments yet.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-xl-6">
            <div class="card table-card p-4 h-100">
                <h5 class="fw-bold mb-3"><i class="fas fa-file-invoice me-2 text-danger"></i>Recent Expenses</h5>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light"><tr><th>Title</th><th>Expense Head</th><th>Amount</th><th>Date</th></tr></thead>
                        <tbody>
                        <?php if ($recent_expenses->num_rows > 0): while ($re = $recent_expenses->fetch_assoc()): ?>
                            <tr>
                                <td class="fw-bold cell-wrap" style="min-width:7rem"><?php echo e($re['title']); ?></td>
                                <td><span class="badge bg-light text-secondary border"><?php echo e($re['head_name']); ?></span></td>
                                <td class="fw-bold text-danger">PKR&nbsp;<?php echo money($re['amount']); ?></td>
                                <td class="small text-muted"><?php echo e(fmt_date($re['expense_date'])); ?></td>
                            </tr>
                        <?php endwhile; else: ?>
                            <tr><td colspan="4" class="text-center text-muted py-3">No expenses yet.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="fw-bold mb-0">Accountants &amp; Privileges <small class="text-muted fs-6">(<?php echo $total_accountants; ?>)</small></h4>
        <div class="d-flex gap-2">
            <a href="manage_users.php" class="btn btn-outline-primary rounded-pill px-3 btn-sm">View All</a>
            <a href="add_accountant.php" class="btn btn-brand rounded-pill px-4 btn-sm"><i class="fas fa-plus me-1"></i> Add New</a>
        </div>
    </div>
    <div class="card table-card p-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>Accountant</th><th class="text-center">Permissions</th><th class="text-center">Status</th><th class="text-center">Actions</th></tr></thead>
                <tbody>
                <?php if ($mgmt_users->num_rows > 0): while ($row = $mgmt_users->fetch_assoc()): $fid = 'perm' . (int)$row['id']; ?>
                    <tr>
                        <td>
                            <div class="fw-bold text-capitalize"><?php echo e($row['full_name']); ?></div>
                            <small class="text-muted"><?php echo e($row['email']); ?></small>
                        </td>
                        <td class="text-center">
                            <div class="d-flex justify-content-center gap-3">
                                <label class="text-primary"><input type="checkbox" form="<?php echo $fid; ?>" name="can_manage_fees" value="1" <?php echo $row['can_manage_fees'] ? 'checked' : ''; ?>> Fees</label>
                                <label class="text-success"><input type="checkbox" form="<?php echo $fid; ?>" name="can_manage_salaries" value="1" <?php echo $row['can_manage_salaries'] ? 'checked' : ''; ?>> Salaries</label>
                                <label class="text-warning"><input type="checkbox" form="<?php echo $fid; ?>" name="can_manage_expenses" value="1" <?php echo $row['can_manage_expenses'] ? 'checked' : ''; ?>> Expenses</label>
                                <label class="text-dark"><input type="checkbox" form="<?php echo $fid; ?>" name="can_manage_accounts" value="1" <?php echo $row['can_manage_accounts'] ? 'checked' : ''; ?>> Accounts</label>
                            </div>
                        </td>
                        <td class="text-center" style="width:150px;">
                            <select name="status" form="<?php echo $fid; ?>" class="form-select form-select-sm rounded-pill fw-bold text-center" style="min-width:7.5rem">
                                <option value="Active" <?php echo $row['status'] === 'Active' ? 'selected' : ''; ?>>Active</option>
                                <option value="Inactive" <?php echo $row['status'] === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </td>
                        <td class="text-center text-nowrap">
                            <form id="<?php echo $fid; ?>" action="" method="POST" class="d-inline">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="user_id" value="<?php echo (int)$row['id']; ?>">
                                <button type="submit" name="update_user" class="btn btn-sm btn-outline-primary border-0 rounded-circle" title="Save"><i class="fas fa-save fs-5"></i></button>
                            </form>
                            <button type="button" class="btn btn-sm btn-outline-danger border-0 rounded-circle" title="Delete"
                                    data-confirm-post data-title="Delete accountant?" data-confirm="Delete"
                                    data-text="<?php echo e('Remove the account of ' . $row['full_name'] . '? This cannot be undone.'); ?>"
                                    data-fields="<?php echo e(json_encode(['delete_id' => (int)$row['id']])); ?>">
                                <i class="fas fa-trash-alt fs-5"></i>
                            </button>
                        </td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">No accountants yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
new Chart(document.getElementById('financeChart'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode($chart_labels); ?>,
        datasets: [
            { label: 'Fee Income', data: <?php echo json_encode(array_values($income_by_month)); ?>, backgroundColor: 'rgba(25,135,84,.75)', borderRadius: 6 },
            { label: 'Expenses', data: <?php echo json_encode(array_values($expense_by_month)); ?>, backgroundColor: 'rgba(220,53,69,.75)', borderRadius: 6 },
            { label: 'Salaries Paid', data: <?php echo json_encode(array_values($salary_by_month)); ?>, backgroundColor: 'rgba(13,202,240,.75)', borderRadius: 6 }
        ]
    },
    options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
});
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
