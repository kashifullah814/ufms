<?php
/**
 * Logout: sirf POST + CSRF (bootstrap.php check karta hai), taake koi doosri website chhupe link se logout na kar sake.
 * Session poori tarah khatam, phir "Logged out" page (naam aur portal ek-dafa wale session flash mein, URL mein nahi).
 * Seedha GET: logout nahi, bas dashboard (ya login page) par.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $user = current_user();
    redirect($user && $user['status'] === 'Active' ? dashboard_url($user['role']) : login_url());
}

$user = current_user();
if ($user) {
    // Policy page ka "Decline and log out": alag action taake Activity Logs mein nazar aaye
    if (!empty($_POST['decline_policy']) && policy_required($user)) {
        audit('POLICY_DECLINED', 'Declined the Acceptable Use Policy and was logged out');
    } else {
        audit('LOGOUT', 'Logged out');
    }
}
$portal = $user ? portal_for_role($user['role']) : valid_portal($_COOKIE[PORTAL_COOKIE] ?? '');
end_session();
session_start();
session_regenerate_id(true);
$_SESSION['signed_out'] = ['name' => $user ? $user['full_name'] : '', 'portal' => $portal];
no_cache_headers();
redirect(url('auth/signed_out.php'));
