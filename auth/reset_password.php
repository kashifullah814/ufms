<?php
/** OTP verify hone ke baad naya password (10 minute ke andar). */
require_once __DIR__ . '/../includes/bootstrap.php';
no_cache_headers();

$uid = (int)($_SESSION['otp_verified_user'] ?? 0);
if (!$uid || (time() - (int)($_SESSION['otp_verified_at'] ?? 0)) > 600) {
    unset($_SESSION['otp_verified_user'], $_SESSION['otp_verified_at']);
    flash('error', 'Your reset session has expired. Please request a new OTP.');
    redirect(url('auth/forgot_password.php'));
}

if (isset($_POST['change_password_btn'])) {
    $new = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    if ($new !== $confirm) {
        flash('error', 'New Password and Confirm Password do not match.');
    } elseif ($problem = password_problem($new)) {
        flash('error', $problem);
    } else {
        // Password reset ghalat-password/auto lock hata deta hai, lekin wrong-portal ka 24 ghante wala block nahi
        db_exec("UPDATE users SET password = ?, must_change_password = 0, failed_logins = 0,
                        locked_until = IF(lock_reason = '" . LOCK_WRONG_PORTAL . "', locked_until, NULL),
                        reset_otp = NULL, otp_expiry = NULL, otp_attempts = 0 WHERE id = ?",
            [password_hash($new, PASSWORD_BCRYPT), $uid]);
        $u = db_row("SELECT id, full_name, role FROM users WHERE id = ?", [$uid]);
        audit('PASSWORD_RESET', 'Password reset via OTP', $u);
        unset($_SESSION['otp_verified_user'], $_SESSION['otp_verified_at'], $_SESSION['reset_email']);
        flash('success', 'Your password has been updated. Please login.');
        redirect(login_url());
    }
    redirect(url('auth/reset_password.php'));
}

$page_title = 'Reset Password';
$body_class = 'd-flex align-items-center justify-content-center min-vh-100';
include __DIR__ . '/../includes/header.php';
?>
<div class="bg-white shadow p-4 p-md-5 rounded-4" style="width:100%;max-width:420px;border-top:5px solid #1e3a8a;">
    <div class="text-center mb-4">
        <i class="fas fa-lock-open fa-3x text-primary mb-2"></i>
        <h4 class="fw-bold">Set New Password</h4>
        <p class="text-muted small">At least <?php echo MIN_PASSWORD_LENGTH; ?> characters, with letters and numbers</p>
    </div>
    <?php echo flash_render(); ?>
    <form action="" method="POST" autocomplete="off">
        <?php echo csrf_field(); ?>
        <div class="mb-3">
            <label class="form-label small fw-bold">NEW PASSWORD <span class="req">*</span></label>
            <div class="input-group">
                <input type="password" name="new_password" id="newPass" class="form-control" minlength="<?php echo MIN_PASSWORD_LENGTH; ?>" required autocomplete="new-password">
                <span class="input-group-text bg-light toggle-password" data-target="newPass"><i class="fas fa-eye text-muted"></i></span>
            </div>
        </div>
        <div class="mb-4">
            <label class="form-label small fw-bold">CONFIRM NEW PASSWORD <span class="req">*</span></label>
            <div class="input-group">
                <input type="password" name="confirm_password" id="confirmPass" class="form-control" minlength="<?php echo MIN_PASSWORD_LENGTH; ?>" required autocomplete="new-password">
                <span class="input-group-text bg-light toggle-password" data-target="confirmPass"><i class="fas fa-eye text-muted"></i></span>
            </div>
        </div>
        <button type="submit" name="change_password_btn" class="btn btn-brand w-100 rounded-pill fw-bold py-2">UPDATE PASSWORD <i class="fas fa-save ms-2"></i></button>
    </form>
    <p class="text-center small text-danger mt-3 mb-0"><i class="fas fa-exclamation-triangle me-1"></i><?php echo e(LOGIN_WARNING); ?></p>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
