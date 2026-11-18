<?php
/** Admin sidebar menu */
$current = basename($_SERVER['PHP_SELF']);
$links = [
    ['admin/dashboard/index.php', 'index.php', 'fa-home', 'Dashboard'],
    ['admin/dashboard/add_accountant.php', 'add_accountant.php', 'fa-user-plus', 'Add Accountant'],
    ['admin/dashboard/manage_users.php', 'manage_users.php', 'fa-users-cog', 'Manage Accountants'],
    ['admin/dashboard/fee_structure.php', 'fee_structure.php', 'fa-file-invoice-dollar', 'Fee Structure'],
    ['admin/dashboard/chart_of_accounts.php', 'chart_of_accounts.php', 'fa-sitemap', 'Chart of Accounts'],
    ['admin/accountant_portal/vouchers.php', '', 'fa-receipt', 'Voucher Generator'],
    ['admin/accountant_portal/trial_balance.php', '', 'fa-balance-scale', 'Trial Balance'],
    ['admin/accountant_portal/budgets.php', '', 'fa-clipboard-list', 'Budgets'],
    ['admin/accountant_portal/utilization.php', '', 'fa-chart-pie', 'Utilization'],
    ['admin/accountant_portal/reminders.php', '', 'fa-bell', 'Reminders'],
    ['admin/dashboard/activity_logs.php', 'activity_logs.php', 'fa-history', 'Activity Logs'],
    ['admin/accountant_portal/index.php', '', 'fa-th-large', 'Finance Modules'],
    ['auth/change_password.php', '', 'fa-key', 'Change Password'],
];
/** Menu ke links (desktop sidebar aur mobile offcanvas dono yahi use karte hain) */
$render_admin_links = function () use ($links, $current) {
    foreach ($links as $l) {
        $active = $l[1] !== '' && strpos($_SERVER['PHP_SELF'], 'admin/dashboard/') !== false && $current === $l[1];
        echo '<a href="' . e(url($l[0])) . '" class="nav-link' . ($active ? ' active' : '') . '"><i class="fas ' . e($l[2]) . ' me-2"></i> ' . e($l[3]) . '</a>';
    }
    echo '<a href="' . e(url('auth/logout.php')) . '" class="nav-link text-warning" data-confirm-logout><i class="fas fa-sign-out-alt me-2"></i> Logout</a>';
};
?>
<!-- < 992px: top bar + hamburger (offcanvas menu). Desktop: neeche wala fixed sidebar. -->
<nav class="navbar navbar-dark sticky-top d-lg-none no-print admin-topbar shadow-sm">
    <div class="container-fluid flex-nowrap gap-2">
        <button class="btn btn-outline-light" type="button" data-bs-toggle="offcanvas" data-bs-target="#adminMenu" aria-controls="adminMenu" aria-label="Open menu"><i class="fas fa-bars"></i></button>
        <span class="navbar-brand fw-bold mb-0 me-auto text-truncate"><i class="fas fa-university me-2"></i><?php echo e(APP_NAME); ?> ADMIN</span>
        <a href="<?php echo e(url('auth/logout.php')); ?>" class="btn btn-outline-light" data-confirm-logout aria-label="Logout" title="Logout"><i class="fas fa-sign-out-alt"></i></a>
    </div>
</nav>
<div class="offcanvas offcanvas-start admin-offcanvas no-print" tabindex="-1" id="adminMenu" aria-labelledby="adminMenuLabel">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title" id="adminMenuLabel"><i class="fas fa-university me-2"></i><?php echo e(APP_NAME); ?> ADMIN</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close menu"></button>
    </div>
    <div class="offcanvas-body p-0"><div class="nav flex-column"><?php $render_admin_links(); ?></div></div>
</div>
<div class="sidebar d-none d-lg-block no-print">
    <div class="sidebar-header">
        <h5 class="mb-0"><i class="fas fa-university me-2"></i><?php echo e(APP_NAME); ?> ADMIN</h5>
    </div>
    <div class="nav flex-column mt-3"><?php $render_admin_links(); ?></div>
</div>
