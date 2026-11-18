<?php
/** OTP verify: hash se compare, expiry check, zyada se zyada 5 koshishein. */
require_once __DIR__ . '/../includes/bootstrap.php';
no_cache_headers();

if (empty($_SESSION['reset_email'])) {
    redirect(url('auth/forgot_password.php'));
}
$email = $_SESSION['reset_email'];

if (isset($_POST['verify_otp_btn'])) {
    $otp  = preg_replace('/\D/', '', (string)($_POST['otp'] ?? ''));
    $user = db_row("SELECT id, full_name, role, status, reset_otp, otp_expiry, otp_attempts FROM users WHERE email = ?", [$email]);

    if (!$user || $user['status'] !== 'Active' || !$user['reset_otp'] || !$user['otp_expiry'] || strtotime($user['otp_expiry']) < time()) {
        flash('error', 'This OTP is invalid or has expired. Please request a new one.');
        redirect(url('auth/verify_otp.php'));
    }
    if ((int)$user['otp_attempts'] >= OTP_MAX_ATTEMPTS) {
        db_exec("UPDATE users SET reset_otp = NULL, otp_expiry = NULL, otp_attempts = 0 WHERE id = ?", [(int)$user['id']]);
        flash('error', 'Too many wrong attempts. Please request a new OTP.');
        redirect(url('auth/forgot_password.php'));
    }
    if (strlen($otp) !== 6 || !password_verify($otp, $user['reset_otp'])) {
        db_exec("UPDATE users SET otp_attempts = otp_attempts + 1 WHERE id = ?", [(int)$user['id']]);
        $left = OTP_MAX_ATTEMPTS - (int)$user['otp_attempts'] - 1;
        flash('error', "Wrong OTP code. {$left} attempt(s) left.");
        redirect(url('auth/verify_otp.php'));
    }

    // OTP sahi - ek dafa hi use ho sakta hai
    db_exec("UPDATE users SET reset_otp = NULL, otp_expiry = NULL, otp_attempts = 0 WHERE id = ?", [(int)$user['id']]);
    session_regenerate_id(true);
    $_SESSION['otp_verified_user'] = (int)$user['id'];
    $_SESSION['otp_verified_at']   = time();
    redirect(url('auth/reset_password.php'));
}

$page_title = 'Verify OTP';
$body_class = 'd-flex align-items-center justify-content-center min-vh-100';
include __DIR__ . '/../includes/header.php';
?>
<div class="bg-white shadow p-4 p-md-5 rounded-4" style="width:100%;max-width:420px;border-top:5px solid #1e3a8a;">
    <div class="text-center mb-4">
        <i class="fas fa-shield-alt fa-3x text-primary mb-2"></i>
        <h4 class="fw-bold">Enter OTP Code</h4>
        <p class="text-muted small">Enter the 6-digit code sent to <strong><?php echo e($email); ?></strong></p>
    </div>
    <?php echo flash_render(); ?>
    <form action="" method="POST" autocomplete="off">
        <?php echo csrf_field(); ?>
        <div class="mb-4">
            <label class="form-label small fw-bold">6-DIGIT OTP CODE <span class="req">*</span></label>
            <input type="text" name="otp" maxlength="6" inputmode="numeric" pattern="\d{6}" class="form-control text-center fs-4 fw-bold" placeholder="123456" required autocomplete="one-time-code">
        </div>
        <button type="submit" name="verify_otp_btn" class="btn btn-brand w-100 rounded-pill fw-bold py-2 mb-3">VERIFY OTP <i class="fas fa-check-circle ms-2"></i></button>
        <div class="text-center d-flex justify-content-between">
            <a href="<?php echo e(login_url()); ?>" class="text-decoration-none small text-secondary"><i class="fas fa-arrow-left me-1"></i> Login</a>
            <a href="forgot_password.php" class="text-decoration-none small text-secondary">Resend OTP Code</a>
        </div>
    </form>
    <p class="text-center small text-danger mt-3 mb-0"><i class="fas fa-exclamation-triangle me-1"></i><?php echo e(LOGIN_WARNING); ?></p>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
