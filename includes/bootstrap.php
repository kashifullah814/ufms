<?php
/**
 * Har page sab se pehle yeh file include karta hai.
 * Session, database, helper functions aur CSRF check yahan se load hote hain.
 */
require_once __DIR__ . '/config.php';
// Naye settings jo purani config.php mein na hon (phone / email / hours khali hon to landing page par nahi dikhte)
foreach ([
    'APP_VERSION'            => '1.0',
    'FINANCE_OFFICE_ADDRESS' => 'Finance Office, University of Science & Technology Bannu, Khyber Pakhtunkhwa',
    'FINANCE_OFFICE_PHONE'   => '',
    'FINANCE_OFFICE_EMAIL'   => '',
    'FINANCE_OFFICE_HOURS'   => '',
] as $cfg_key => $cfg_default) {
    if (!defined($cfg_key)) define($cfg_key, $cfg_default);
}
unset($cfg_key, $cfg_default);
date_default_timezone_set(APP_TIMEZONE);

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/accounting.php';
require_once __DIR__ . '/postings.php';
require_once __DIR__ . '/budgets.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/reminders.php';
require_once __DIR__ . '/not_found.php';

// Security headers har page par (clickjacking, MIME sniffing, referrer, CSP)
send_security_headers();

// Koi unexpected error ho to user ko andar ki details (SQL, paths) na dikhen
set_exception_handler(function ($e) {
    error_log('UFMS error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo '<div style="font-family:Arial;max-width:520px;margin:80px auto;padding:25px;border:1px solid #f5c2c2;background:#fce8e8;color:#a80000;border-radius:10px">'
       . '<h3 style="margin-top:0">Something went wrong</h3><p>The request could not be completed. Please go back and try again. '
       . 'If the problem continues, contact the administrator.</p><a href="javascript:history.back()">&larr; Go back</a></div>';
});

// Idle / 8 ghante wali session khatam (har request par, browser "continue where you left off" se bhi nahi bachti)
check_session_timeout();

// ADMIN_ALLOWED_IPS set ho to admin/ pages sirf university network se
enforce_admin_network();

// Har POST request par CSRF token lazmi hai
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_verify();
}
