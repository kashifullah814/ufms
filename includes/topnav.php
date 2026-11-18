<?php
/**
 * Top navigation bar (Accountant / Student / Staff pages).
 * Set karein: $nav_title, $nav_icon, optional $nav_back (URL) aur $nav_back_label.
 * $nav_back = 'dashboard' -> user ka apna dashboard (Admin: Admin Dashboard, Accountant: Finance Modules).
 * >= 992px: buttons bar mein; tablet / phone par hamburger dropdown (naam, back, password, logout).
 */
$nav_user = current_user();
if (($nav_back ?? '') === 'dashboard') {
    $nav_dash = dashboard_link();
    $nav_back = $nav_dash['url'];
    $nav_back_label = $nav_dash['label'];
}
?>
<nav class="navbar navbar-dark shadow-sm mb-4 no-print" style="background:#1e3a8a;">
    <div class="container flex-nowrap gap-2">
        <span class="navbar-brand fw-bold text-truncate me-auto"><i class="fas <?php echo e($nav_icon ?? 'fa-university'); ?> me-2"></i><?php echo e($nav_title ?? APP_NAME); ?></span>
        <div class="d-none d-lg-flex align-items-center gap-2 flex-nowrap justify-content-end">
            <?php if (!empty($nav_back)): ?>
                <a href="<?php echo e($nav_back); ?>" class="btn btn-light btn-sm rounded-pill px-3"><i class="fas fa-arrow-left me-1"></i><?php echo e($nav_back_label ?? 'Back'); ?></a>
            <?php endif; ?>
            <?php if ($nav_user): ?>
                <span class="text-white small text-nowrap text-capitalize"><i class="fas fa-user me-1"></i><?php echo e($nav_user['full_name']); ?></span>
                <a href="<?php echo e(url('auth/change_password.php')); ?>" class="btn btn-outline-light btn-sm rounded-pill px-3" title="Change Password" aria-label="Change Password"><i class="fas fa-key"></i></a>
                <a href="<?php echo e(url('auth/logout.php')); ?>" class="btn btn-danger btn-sm rounded-pill px-3" data-confirm-logout><i class="fas fa-sign-out-alt me-1"></i>Logout</a>
            <?php endif; ?>
        </div>
        <div class="dropdown d-lg-none">
            <button class="btn btn-outline-light" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Menu"><i class="fas fa-bars"></i></button>
            <ul class="dropdown-menu dropdown-menu-end shadow">
                <?php if ($nav_user): ?>
                    <li><h6 class="dropdown-header text-truncate text-capitalize" style="max-width:260px;" title="<?php echo e($nav_user['full_name']); ?>"><i class="fas fa-user me-1"></i><?php echo e($nav_user['full_name']); ?></h6></li>
                <?php endif; ?>
                <?php if (!empty($nav_back)): ?>
                    <li><a class="dropdown-item" href="<?php echo e($nav_back); ?>"><i class="fas fa-arrow-left me-2"></i><?php echo e($nav_back_label ?? 'Back'); ?></a></li>
                <?php endif; ?>
                <?php if ($nav_user): ?>
                    <li><a class="dropdown-item" href="<?php echo e(url('auth/change_password.php')); ?>"><i class="fas fa-key me-2"></i>Change Password</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="<?php echo e(url('auth/logout.php')); ?>" data-confirm-logout><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>
