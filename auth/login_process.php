<?php
/**
 * Login: IP throttling, password check, account lock (bar bar ghalat password), portal/role check,
 * university-network check, inactive check, Admin 2FA, session ID renew aur role ke hisaab se redirect.
 * Portal (optional) sirf password sahi hone ke BAAD check hota hai. Rules: includes/security.php
 */
require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(login_url());
}

$portal = valid_portal($_POST['portal'] ?? '');
$back   = url('auth/login.php' . ($portal !== '' ? '?portal=' . $portal : ''));

$email    = trim((string)($_POST['email'] ?? ''));
$password = (string)($_POST['password'] ?? '');
$generic  = 'Invalid email or password.';

// Is IP se bahut zyada ghalat logins: kuch check kiye baghair mana (generic message)
if (ip_login_blocked()) {
    flash('error', TOO_MANY_ATTEMPTS_MESSAGE);
    redirect($back);
}

if ($email === '' || $password === '') {
    flash('error', 'Please enter your email and password.');
    redirect($back);
}

$user = db_row("SELECT * FROM users WHERE email = ?", [$email]);

// Account temporarily locked?
if ($user && $user['locked_until'] && strtotime($user['locked_until']) > time()) {
    if ($user['lock_reason'] === LOCK_WRONG_PORTAL) {
        flash('error', wrong_portal_block_message($user['locked_until']));
        redirect($back);
    }
    $mins = (int)ceil((strtotime($user['locked_until']) - time()) / 60);
    flash('error', "Too many failed attempts. Please try again after {$mins} minute(s) or reset your password.");
    redirect($back);
}

// Administration / Finance Office portal university network se bahar band (ADMIN_ALLOWED_IPS)
if (in_array($portal, NETWORK_ONLY_PORTALS, true) && !admin_network_allowed()) {
    audit('NETWORK_DENIED', 'Login to the ' . LOGIN_PORTALS[$portal]['name'] . ' portal from outside the university network (' . client_ip() . ')', $user ?: null);
    flash('error', NETWORK_ONLY_MESSAGE);
    redirect($back);
}

if (!$user || !password_verify($password, $user['password'])) {
    record_failed_login($email);
    if ($user) {
        $fails = (int)$user['failed_logins'] + 1;
        if ($fails >= MAX_LOGIN_ATTEMPTS) {
            db_exec("UPDATE users SET failed_logins = 0, locked_until = ?, lock_reason = NULL WHERE id = ?",
                [date('Y-m-d H:i:s', time() + LOCKOUT_MINUTES * 60), (int)$user['id']]);
            audit('LOGIN_LOCKED', 'Account locked after repeated failed logins', $user);
        } else {
            db_exec("UPDATE users SET failed_logins = ? WHERE id = ?", [$fails, (int)$user['id']]);
        }
        audit('LOGIN_FAILED', 'Wrong password for ' . $user['email'], $user);
    }
    flash('error', $generic);
    redirect($back);
}

// Password sahi hai; ab dekho account isi portal ka hai (Admin portal = sirf Admin, Finance Office = sirf Accountant).
// Strikes: 24 ghante mein 3 dafa -> account 24 ghante block (Admin kabhi nahi)
if ($portal !== '' && $user['role'] !== LOGIN_PORTALS[$portal]['role']) {
    $pname = LOGIN_PORTALS[$portal]['name'];
    $strikes = $user['role'] === 'Admin' ? 0 : add_portal_strike($user, $portal);
    audit('LOGIN_WRONG_PORTAL', "{$user['role']} account {$user['email']} tried to log in to the {$pname} portal"
        . ($strikes ? " (strike {$strikes} of " . WRONG_PORTAL_MAX_STRIKES . ')' : ''), $user);
    if ($strikes >= WRONG_PORTAL_MAX_STRIKES) {
        $until = block_account_wrong_portal($user, $strikes);
        flash('error', wrong_portal_block_message($until));
        redirect($back);
    }
    $msg = "This account cannot log in to the {$pname} portal. Please use the correct portal.";
    if ($strikes && $strikes === WRONG_PORTAL_MAX_STRIKES - 1) {
        $msg .= ' Warning: one more attempt and your account will be blocked for ' . WRONG_PORTAL_BLOCK_HOURS . ' hours.';
    }
    flash('error', $msg);
    redirect($back);
}

// Generic login se bhi Admin/Accountant university network ke bahar se nahi
if (in_array($user['role'], NETWORK_ONLY_ROLES, true) && !admin_network_allowed()) {
    audit('NETWORK_DENIED', "{$user['role']} login from outside the university network (" . client_ip() . ')', $user);
    flash('error', NETWORK_ONLY_MESSAGE);
    redirect($back);
}

if ($user['status'] !== 'Active') {
    flash('error', 'Your account is inactive. Please contact the administrator.');
    redirect($back);
}

if (password_needs_rehash($user['password'], PASSWORD_BCRYPT)) {
    db_exec("UPDATE users SET password = ? WHERE id = ?", [password_hash($password, PASSWORD_BCRYPT), (int)$user['id']]);
}

// Admin 2FA: session OTP sahi hone ke baad shuru hota hai (verify_2fa.php)
if (admin_2fa_required($user)) {
    if (!start_admin_2fa($user, $portal, $mailErr)) {
        audit('ADMIN_2FA_FAILED', 'Login code could not be emailed: ' . $mailErr, $user);
        flash('error', 'Your login code could not be emailed, so you cannot log in right now. '
            . 'Check the internet connection and email settings, or set ADMIN_2FA to false in includes/config.php.');
        redirect($back);
    }
    flash('info', 'A 6-digit login code has been sent to your email.');
    redirect(url('auth/verify_2fa.php'));
}

complete_login($user, $portal);
