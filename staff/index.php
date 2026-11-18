<?php
/** Staff portal: profile, salary summary (year filter), salary history aur slips. */
require_once __DIR__ . '/../includes/bootstrap.php';
$user = require_login(['Staff'], 'Please login with your Staff Account to view this page.');

$staff = db_row("SELECT st.*, u.full_name, u.email FROM staff st JOIN users u ON u.id = st.user_id WHERE st.user_id = ?", [(int)$user['id']]);

$years = [];
$year = (int)($_GET['year'] ?? date('Y'));
$history = [];
$sum = ['net' => 0, 'allow' => 0, 'deduct' => 0, 'pending' => 0];
if ($staff) {
    $yr = db_select("SELECT DISTINCT LEFT(month_year, 4) y FROM staff_salaries WHERE staff_id = ? ORDER BY y DESC", [(int)$staff['id']]);
    while ($y = $yr->fetch_assoc()) { $years[] = (int)$y['y']; }
    if (!in_array((int)date('Y'), $years, true)) array_unshift($years, (int)date('Y'));
    if (!in_array($year, $years, true)) $year = $years[0];

    $history = db_select("SELECT * FROM staff_salaries WHERE staff_id = ? AND month_year LIKE ? ORDER BY month_year DESC", [(int)$staff['id'], $year . '-%'])->fetch_all(MYSQLI_ASSOC);
    foreach ($history as $h) {
        if ($h['status'] === 'Paid') {
            $sum['net'] += $h['net_salary'];
            $sum['allow'] += $h['allowances'];
            $sum['deduct'] += $h['deductions'];
        } else {
            $sum['pending'] += $h['net_salary'];
        }
    }
}

$page_title = 'Staff Portal';
$nav_title = APP_NAME . ' Staff Portal';
$nav_icon = 'fa-user-tie';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/topnav.php';
?>
<div class="container pb-5">
    <?php echo flash_render(); ?>
    <?php $rem_user = $user; include __DIR__ . '/../includes/reminders_panel.php'; ?>
    <?php if (!$staff): ?>
        <div class="alert alert-warning">Your staff profile is not set up yet. Please contact the Finance Department.</div>
    <?php else: ?>
    <div class="row g-4">
        <div class="col-xxl-4">
            <div class="card border-0 shadow p-4 text-white rounded-4" style="background:linear-gradient(135deg,#134e4a,#115e59);">
                <div class="text-center mb-4">
                    <div class="bg-white text-success rounded-circle d-inline-block p-3 mb-3 shadow-sm"><i class="fas fa-user-tie fa-3x"></i></div>
                    <h4 class="mb-0 fw-bold text-capitalize" style="font-size:1.2rem;overflow-wrap:anywhere;"><?php echo e($staff['full_name']); ?></h4>
                    <p class="small opacity-75"><?php echo e($staff['email']); ?></p>
                </div>
                <div class="mb-3"><small class="opacity-75 d-block">STAFF ID</small><span class="fw-bold"><?php echo e($staff['staff_id_number']); ?></span></div>
                <div class="mb-3"><small class="opacity-75 d-block">DESIGNATION</small><span class="fw-bold"><?php echo e($staff['designation']); ?></span></div>
                <div class="mb-3"><small class="opacity-75 d-block">EMPLOYMENT</small><span class="fw-bold"><?php echo e($staff['employment_type']); ?></span></div>
                <div><small class="opacity-75 d-block"><?php echo $staff['pay_basis'] === 'Monthly' ? 'MONTHLY SALARY' : ($staff['pay_basis'] === 'Hourly' ? 'RATE PER HOUR' : 'RATE PER DAY'); ?></small><span class="badge bg-light text-success fs-6">PKR&nbsp;<?php echo money($staff['pay_basis'] === 'Monthly' ? $staff['base_salary'] : $staff['pay_rate']); ?></span></div>
            </div>
        </div>
        <div class="col-xxl-8">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0">Salary Summary - <?php echo $year; ?></h5>
                <form method="GET"><select name="year" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php foreach ($years as $y): ?><option value="<?php echo $y; ?>" <?php echo $y === $year ? 'selected' : ''; ?>><?php echo $y; ?></option><?php endforeach; ?>
                </select></form>
            </div>
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3"><div class="card stat-card p-3 border-start border-success border-5"><small class="text-muted fw-bold">NET PAID</small><h6 class="fw-bold text-success mb-0">PKR&nbsp;<?php echo money($sum['net']); ?></h6></div></div>
                <div class="col-6 col-md-3"><div class="card stat-card p-3 border-start border-info border-5"><small class="text-muted fw-bold">ALLOWANCES</small><h6 class="fw-bold mb-0">PKR&nbsp;<?php echo money($sum['allow']); ?></h6></div></div>
                <div class="col-6 col-md-3"><div class="card stat-card p-3 border-start border-danger border-5"><small class="text-muted fw-bold">DEDUCTIONS</small><h6 class="fw-bold mb-0">PKR&nbsp;<?php echo money($sum['deduct']); ?></h6></div></div>
                <div class="col-6 col-md-3"><div class="card stat-card p-3 border-start border-warning border-5"><small class="text-muted fw-bold">PENDING</small><h6 class="fw-bold mb-0">PKR&nbsp;<?php echo money($sum['pending']); ?></h6></div></div>
            </div>
            <div class="card table-card p-4">
                <h5 class="fw-bold mb-4"><i class="fas fa-history me-2 text-success"></i>Salary History</h5>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light"><tr><th>Month</th><th class="text-end">Base</th><th class="text-end">Allowances</th><th class="text-end">Deductions</th><th class="text-end">Net</th><th>Status</th><th class="text-center">Slip</th></tr></thead>
                        <tbody>
                        <?php if ($history): foreach ($history as $row): ?>
                            <tr>
                                <td class="fw-bold text-primary"><?php echo e(month_label($row['month_year'])); ?></td>
                                <td class="text-end"><?php echo money($row['base_salary']); ?></td>
                                <td class="text-end text-success">+<?php echo money($row['allowances']); ?></td>
                                <td class="text-end text-danger">-<?php echo money($row['deductions']); ?></td>
                                <td class="text-end fw-bold"><?php echo money($row['net_salary']); ?></td>
                                <td><span class="badge rounded-pill <?php echo $row['status'] === 'Paid' ? 'bg-success' : 'bg-warning text-dark'; ?>"><?php echo e($row['status']); ?></span></td>
                                <td class="text-center">
                                    <?php if ($row['status'] === 'Paid'): ?>
                                        <a href="<?php echo e(url('admin/accountant_portal/print_salary_slip.php?id=' . (int)$row['id'])); ?>" target="_blank" class="btn btn-sm btn-light border rounded-pill px-3"><i class="fas fa-print me-1"></i> Slip</a>
                                    <?php else: ?><span class="text-muted small">After payment</span><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="7" class="text-center py-5 text-muted">No salary records for <?php echo $year; ?>.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
