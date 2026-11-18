<?php
/** Accountant dashboard: stats + sirf woh modules jin ki permission hai. */
require_once __DIR__ . '/../../includes/bootstrap.php';
$user = require_login(['Accountant', 'Admin']);

$student_count = (int)db_value("SELECT COUNT(*) FROM students", [], 0);
$fee_collected = (float)db_value("SELECT SUM(amount_paid) FROM fees_records", [], 0);
$total_dues    = (float)db_value("SELECT SUM(balance) FROM fees_records WHERE paid_status <> 'Paid'", [], 0);
$total_expense = (float)db_value("SELECT SUM(amount) FROM expenses", [], 0);
$pending_sal   = (int)db_value("SELECT COUNT(*) FROM staff_salaries WHERE status = 'Pending'", [], 0);

$modules = [
    ['fees', 'Student Records', 'fa-user-graduate', 'primary', 'Register students, update programs, semesters and scholarships.', 'manage_students.php', 'Open Records'],
    ['fees', 'Fee Collection', 'fa-file-invoice-dollar', 'success', 'Search students, collect semester fees (full or partial) and track balances.', 'manage_fees.php', 'Collect Fees'],
    ['fees', 'Reminders', 'fa-bell', 'danger', 'Send fee reminders to students with pending dues (dashboard and email); see what was sent.', 'reminders.php', 'Send Reminder'],
    ['expenses', 'University Expenses', 'fa-wallet', 'danger', 'Record utility bills, maintenance and purchases with receipts.', 'manage_expenses.php', 'Manage Expenses'],
    ['salaries', 'Staff Payroll', 'fa-money-check-alt', 'info', 'Generate monthly salary bills, mark them paid and print slips.' . ($pending_sal ? " ({$pending_sal} pending)" : ''), 'manage_salaries.php', 'Manage Salaries'],
    ['accounts', 'Voucher Generator', 'fa-receipt', 'dark', 'View, print and export receipt, payment and journal vouchers (RV / PV / JV).', 'vouchers.php', 'Open Voucher Generator'],
    ['accounts', 'Trial Balance', 'fa-balance-scale', 'primary', 'Debit and credit balance of every account head for any date range; print or PDF.', 'trial_balance.php', 'Open Trial Balance'],
    ['accounts', 'Grants (HEC / HED)', 'fa-landmark', 'success', 'Record HEC and HED grants received; each creates a receipt voucher.', 'grants.php', 'Record Grants'],
    ['accounts', 'Student Charges', 'fa-hand-holding-usd', 'primary', 'Hostel, transport, repeat paper, summer, DMC, degree, verification and migration fees.', 'student_charges.php', 'Record Charges'],
    ['accounts', 'Budgets', 'fa-clipboard-list', 'warning', 'Prepare budgets per head and track F&PC, Syndicate and Senate approval.', 'budgets.php', 'Open Budgets'],
    ['accounts', 'Budget Utilization', 'fa-chart-pie', 'danger', 'Budget vs actual per head with 80% warning and 100% exceeded alerts.', 'utilization.php', 'Open Report'],
    ['salaries', 'Register Staff', 'fa-user-tie', 'secondary', 'Create staff accounts; login details are emailed to the staff member.', 'add_staff.php', 'Add Staff'],
];

$page_title = 'Accountant Portal';
$nav_title = APP_NAME . ' - ' . ($user['role'] === 'Admin' ? 'Finance Modules' : 'Accountant Portal');
$nav_icon = 'fa-chart-line';
if ($user['role'] === 'Admin') { $nav_back = 'dashboard'; }
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/topnav.php';
?>
<style>
    .module-locked { background: #f1f3f5; box-shadow: none; border: 1px dashed #adb5bd; }
    .module-locked:hover { transform: none; }
    .module-locked h5, .module-locked p { color: #495057 !important; }
    .module-locked-note { border: 1px solid #6c757d; color: #343a40; background: #fff; padding: .375rem .75rem; }
</style>
<div class="container pb-5">
    <?php echo flash_render(); ?>
    <div class="row g-4 mb-5">
        <?php foreach ([
            ['Total Students', number_format($student_count), 'primary', 'fa-user-graduate'],
            ['Fee Collected', "PKR\u{00A0}" . money($fee_collected, 0), 'success', 'fa-cash-register'],
            ['Pending Dues', "PKR\u{00A0}" . money($total_dues, 0), 'danger', 'fa-exclamation-circle'],
            ['Total Expenses', "PKR\u{00A0}" . money($total_expense, 0), 'warning', 'fa-wallet'],
        ] as $c): ?>
        <div class="col-md-6 col-xl-3">
            <div class="card stat-card p-3 border-start border-<?php echo $c[2]; ?> border-5 h-100">
                <div class="d-flex align-items-center justify-content-between">
                    <div><h6 class="text-muted small text-uppercase fw-bold mb-1"><?php echo e($c[0]); ?></h6><h4 class="fw-bold mb-0 text-<?php echo $c[2]; ?>"><?php echo e($c[1]); ?></h4></div>
                    <div class="text-<?php echo $c[2]; ?> fs-2"><i class="fas <?php echo $c[3]; ?>"></i></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php $rem_user = $user; include __DIR__ . '/../../includes/reminders_panel.php'; ?>
    <?php include __DIR__ . '/../../includes/budget_alerts_panel.php'; ?>

    <h5 class="fw-bold text-secondary mb-4"><i class="fas fa-th-large me-2"></i>Management Modules</h5>
    <div class="row g-4">
        <?php foreach ($modules as $m): $allowed = can($m[0]); ?>
        <div class="col-md-6 col-lg-4">
            <?php /* Locked module: halka grey card (opacity nahi, taake text parha ja sake - WCAG AA) */ ?>
            <div class="card stat-card p-4 text-center h-100 <?php echo $allowed ? '' : 'module-locked'; ?>">
                <div class="bg-light text-<?php echo $allowed ? $m[3] : 'secondary'; ?> icon-box mx-auto mb-3"><i class="fas <?php echo $m[2]; ?>"></i></div>
                <h5 class="fw-bold"><?php echo e($m[1]); ?></h5>
                <p class="small text-muted mb-4"><?php echo e($m[4]); ?></p>
                <?php if ($allowed): ?>
                    <a href="<?php echo e($m[5]); ?>" class="btn btn-brand rounded-pill w-100 mt-auto"><?php echo e($m[6]); ?></a>
                <?php else: ?>
                    <div class="module-locked-note rounded-pill w-100 mt-auto"><i class="fas fa-lock me-1"></i> No permission</div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
