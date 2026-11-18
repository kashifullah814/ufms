<?php
/**
 * Forgot password: email par 6-digit OTP.
 * OTP database mein hash ho kar save hota hai, 15 minute valid, aur 1 minute se pehle dobara nahi bhejta.
 * Page ka HTML login page wala hi sliding panel hai (includes/auth_panel.php), Forgot Password side khuli hui.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
no_cache_headers();
remember_portal(valid_portal($_GET['portal'] ?? ''));  // "Back to Login" usi portal par

if (isset($_POST['send_otp_btn'])) {
    $email = trim((string)($_POST['email'] ?? ''));

    if (!valid_email($email)) {
        flash('error', 'Please enter a valid email address.');
        redirect(url('auth/forgot_password.php'));
    }
    if (!mail_configured()) {
        flash('error', 'Password reset by email is not available right now. Please contact the administrator.');
        redirect(url('auth/forgot_password.php'));
    }

    $user = db_row("SELECT id, full_name, email, role, status, otp_expiry FROM users WHERE email = ?", [$email]);

    if ($user && $user['status'] === 'Active') {
        $lastSent = $user['otp_expiry'] ? strtotime($user['otp_expiry']) - OTP_VALID_MINUTES * 60 : 0;
        if ($lastSent > time() - 60) {
            flash('warning', 'An OTP was sent less than a minute ago. Please check your inbox (and spam folder).');
            $_SESSION['reset_email'] = $email;
            redirect(url('auth/verify_otp.php'));
        }

        list($otp, $otpHash) = otp_new();
        $expiry = date('Y-m-d H:i:s', time() + OTP_VALID_MINUTES * 60);
        db_exec("UPDATE users SET reset_otp = ?, otp_expiry = ?, otp_attempts = 0 WHERE id = ?",
            [$otpHash, $expiry, (int)$user['id']]);

        $inner = "<p>Hello <strong>" . e($user['full_name']) . "</strong>,</p>"
               . "<p>Your OTP code to reset your " . e(APP_NAME) . " portal password is:</p>"
               . "<div style='background:#f8f9fa;border-left:4px solid #1e3a8a;padding:15px;margin:20px 0;text-align:center;'>"
               . "<span style='font-size:28px;font-weight:bold;letter-spacing:5px;color:#1e3a8a;'>{$otp}</span></div>"
               . "<p style='color:#6c757d;font-size:13px;'>This code is valid for " . OTP_VALID_MINUTES . " minutes. If you did not request it, ignore this email.</p>";

        if (!send_mail($user['email'], $user['full_name'], APP_NAME . ' - Password Reset OTP', email_template('Password Reset Code', $inner))) {
            db_exec("UPDATE users SET reset_otp = NULL, otp_expiry = NULL WHERE id = ?", [(int)$user['id']]);
            flash('error', 'The OTP email could not be sent right now. Please try again in a few minutes.');
            redirect(url('auth/forgot_password.php'));
        }
        audit('OTP_SENT', 'Password reset OTP requested', $user);
    }

    // Email registered ho ya na ho, ek hi message (taake koi emails guess na kar sake)
    $_SESSION['reset_email'] = $email;
    flash('info', 'If this email is registered, a 6-digit OTP has been sent to it.');
    redirect(url('auth/verify_otp.php'));
}

// Portal: URL wala, warna yaad rakha hua (cookie) - rang aur "Back to Login" usi ke
$portal = valid_portal($_GET['portal'] ?? ($_COOKIE[PORTAL_COOKIE] ?? ''));
$auth_mode = 'forgot';
include __DIR__ . '/../includes/auth_panel.php';
