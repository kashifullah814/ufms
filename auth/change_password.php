<?php
/** Logged-in user ka password change (temporary password wale users ko yahan zabardasti bheja jata hai). */
require_once __DIR__ . '/../includes/bootstrap.php';
$user = require_login(['Admin', 'Accountant', 'Student', 'Staff']);
$forced = (int)$user['must_change_password'] === 1;

if (isset($_POST['change_password_btn'])) {
    $current = (string)($_POST['current_password'] ?? '');
    $new     = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    $hash    = db_value("SELECT password FROM users WHERE id = ?", [(int)$user['id']]);

    if (!password_verify($current, (string)$hash)) {
        flash('error', 'Your current password is incorrect.');
    } elseif ($new !== $confirm) {
        flash('error', 'New Password and Confirm Password do not match.');
    } elseif ($problem = password_problem($new)) {
        flash('error', $problem);
    } elseif (password_verify($new, (string)$hash)) {
        flash('error', 'New password must be different from the current password.');
    } else {
        db_exec("UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?", [password_hash($new, PASSWORD_BCRYPT), (int)$user['id']]);
        session_regenerate_id(true);
        audit('PASSWORD_CHANGED', 'Changed own password');
        flash('success', 'Your password has been changed successfully.');
        redirect(dashboard_url($user['role']));
    }
    redirect(url('auth/change_password.php'));
}

$page_title = 'Change Password';
$body_class = 'd-flex align-items-center justify-content-center min-vh-100';
include __DIR__ . '/../includes/header.php';
?>
<div class="bg-white shadow p-4 p-md-5 rounded-4" style="width:100%;max-width:440px;border-top:5px solid #1e3a8a;">
    <div class="text-center mb-4">
        <i class="fas fa-user-lock fa-3x text-primary mb-2"></i>
        <h4 class="fw-bold">Change Password</h4>
        <p class="text-muted small mb-0"><?php echo e($user['full_name']); ?> (<?php echo e($user['role']); ?>)</p>
    </div>
    <?php echo flash_render(); ?>
    <?php if ($forced): ?>
        <div class="alert alert-warning small py-2"><i class="fas fa-info-circle me-1"></i> You are using a temporary password. Please set your own password to continue.</div>
    <?php endif; ?>
    <form action="" method="POST" autocomplete="off">
        <?php echo csrf_field(); ?>
        <?php foreach (['current_password' => 'CURRENT PASSWORD', 'new_password' => 'NEW PASSWORD', 'confirm_password' => 'CONFIRM NEW PASSWORD'] as $name => $label): ?>
        <div class="mb-3">
            <label class="form-label small fw-bold"><?php echo $label; ?> <span class="req">*</span></label>
            <div class="input-group">
                <input type="password" name="<?php echo $name; ?>" id="<?php echo $name; ?>" class="form-control" required autocomplete="<?php echo $name === 'current_password' ? 'current-password' : 'new-password'; ?>">
                <span class="input-group-text bg-light toggle-password" data-target="<?php echo $name; ?>"><i class="fas fa-eye text-muted"></i></span>
            </div>
        </div>
        <?php endforeach; ?>
        <p class="small text-muted">At least <?php echo MIN_PASSWORD_LENGTH; ?> characters, with at least one letter and one number.</p>
        <button type="submit" name="change_password_btn" class="btn btn-brand w-100 rounded-pill fw-bold py-2 mb-3">UPDATE PASSWORD <i class="fas fa-save ms-2"></i></button>
        <div class="text-center">
            <?php if (!$forced): ?>
                <a href="<?php echo e(dashboard_url($user['role'])); ?>" class="text-decoration-none small text-secondary"><i class="fas fa-arrow-left me-1"></i> Back to Dashboard</a>
            <?php else: ?>
                <a href="<?php echo e(url('auth/logout.php')); ?>" class="text-decoration-none small text-secondary" data-confirm-logout><i class="fas fa-sign-out-alt me-1"></i> Logout</a>
            <?php endif; ?>
        </div>
    </form>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
