<?php
/**
 * Admin 2FA: password ke baad email wala 6-digit code. Hash session mein; 10 minute, 5 koshishein.
 * Sahi code par hi login session shuru hota hai (complete_login).
 */
require_once __DIR__ . '/../includes/bootstrap.php';
no_cache_headers();

$pending = $_SESSION['pending_2fa'] ?? null;
$back = url('auth/login.php' . (!empty($pending['portal']) ? '?portal=' . $pending['portal'] : ''));

if (!$pending || empty($pending['user_id'])) {
    redirect(login_url());
}
if (time() > (int)$pending['expires']) {
    unset($_SESSION['pending_2fa']);
    flash('error', 'Your login code has expired. Please log in again.');
    redirect($back);
}
if (ip_login_blocked()) {
    unset($_SESSION['pending_2fa']);
    flash('error', TOO_MANY_ATTEMPTS_MESSAGE);
    redirect($back);
}

$user = db_row("SELECT * FROM users WHERE id = ?", [(int)$pending['user_id']]);
if (!$user || $user['status'] !== 'Active' || $user['role'] !== 'Admin' || account_locked_minutes($user)) {
    unset($_SESSION['pending_2fa']);
    flash('error', 'You cannot log in with this account right now. Please contact the administrator.');
    redirect($back);
}

if (isset($_POST['verify_2fa_btn'])) {
    $otp = preg_replace('/\D/', '', (string)($_POST['otp'] ?? ''));
    if (strlen($otp) === 6 && password_verify($otp, $pending['hash'])) {
        complete_login($user, $pending['portal'], true);
    }
    record_failed_login($user['email']);
    $attempts = (int)$pending['attempts'] + 1;
    if ($attempts >= OTP_MAX_ATTEMPTS) {
        unset($_SESSION['pending_2fa']);
        audit('ADMIN_2FA_FAILED', "Wrong login code entered {$attempts} times", $user);
        flash('error', 'Too many wrong codes. Please log in again to get a new code.');
        redirect($back);
    }
    $_SESSION['pending_2fa']['attempts'] = $attempts;
    flash('error', 'Wrong code. ' . (OTP_MAX_ATTEMPTS - $attempts) . ' attempt(s) left.');
    redirect(url('auth/verify_2fa.php'));
}

// Email ka kuch hissa chhupa kar dikhao: ka***@gmail.com
$at = strpos($user['email'], '@');
$masked = $at === false ? $user['email'] : substr($user['email'], 0, min(2, $at)) . str_repeat('*', max(3, $at - 2)) . substr($user['email'], $at);
$mins_left = max(1, (int)ceil(((int)$pending['expires'] - time()) / 60));

$page_title = 'Admin Login Code';
$body_class = 'd-flex align-items-center justify-content-center min-vh-100 px-3';
include __DIR__ . '/../includes/header.php';
?>
<div class="bg-white shadow p-4 p-md-5 rounded-4 my-4" style="width:100%;max-width:420px;border-top:5px solid #1e3a8a;">
    <div class="text-center mb-4">
        <span class="d-inline-flex align-items-center justify-content-center rounded-circle mb-2 text-white" style="width:72px;height:72px;background:#1e3a8a;"><i class="fas fa-user-shield fa-2x"></i></span>
        <h4 class="fw-bold mb-0">Two-Factor Login</h4>
        <p class="text-muted small mb-0">Enter the 6-digit code sent to <strong><?php echo e($masked); ?></strong>. It expires in about <?php echo $mins_left; ?> minute(s).</p>
    </div>
    <?php echo flash_render(); ?>
    <form action="" method="POST" autocomplete="off">
        <?php echo csrf_field(); ?>
        <div class="mb-4">
            <label class="form-label small fw-bold">6-DIGIT LOGIN CODE <span class="req">*</span></label>
            <input type="text" name="otp" maxlength="6" inputmode="numeric" pattern="\d{6}" class="form-control text-center fs-4 fw-bold" placeholder="123456" required autofocus autocomplete="one-time-code">
        </div>
        <button type="submit" name="verify_2fa_btn" class="btn btn-brand w-100 rounded-pill fw-bold py-2 mb-3">VERIFY &amp; LOGIN <i class="fas fa-check-circle ms-2"></i></button>
        <div class="text-center"><a href="<?php echo e($back); ?>" class="text-decoration-none small text-secondary"><i class="fas fa-arrow-left me-1"></i> Back to login (get a new code)</a></div>
    </form>
    <p class="text-center small text-danger mt-4 mb-0"><i class="fas fa-exclamation-triangle me-1"></i><?php echo e(LOGIN_WARNING); ?></p>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
