<?php
/**
 * UFMS shared helpers.
 * Sections: output/URL, database (prepared statements), auth & permissions,
 * CSRF, flash messages, audit log, passwords, email, uploads, finance helpers.
 */

/* ======================= Output & URLs ======================= */

/** HTML-escape any value before printing it (XSS protection). */
function e($value)
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/** Project ka URL path, e.g. "/ufms_project" (ya "" agar project web root par ho). */
function base_path()
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    if (APP_BASE_PATH !== null) {
        return $base = rtrim(APP_BASE_PATH, '/');
    }
    $appDir  = str_replace('\\', '/', (string)realpath(__DIR__ . '/..'));
    $docRoot = str_replace('\\', '/', (string)realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if ($docRoot !== '' && stripos($appDir, $docRoot) === 0) {
        $base = substr($appDir, strlen($docRoot));
    } else {
        $base = '';
    }
    return $base = rtrim($base, '/');
}

function url($path = '')
{
    return base_path() . '/' . ltrim($path, '/');
}

function redirect($location)
{
    remember_old_input();
    header('Location: ' . $location);
    exit();
}

/**
 * Form mein ghalti (error flash) ke baad redirect: user ka likha hua data session mein rakh lo taake footer.php
 * usi form mein wapas bhar de (kuch dobara type na karna pare). Passwords, tokens aur files kabhi nahi rakhe jate.
 */
function remember_old_input()
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || empty($_SESSION['flash']['error']) || empty($_SESSION['user_id'])) {
        return;
    }
    $old = [];
    foreach ($_POST as $k => $v) {
        if ($k === 'csrf_token' || stripos((string)$k, 'password') !== false || stripos((string)$k, 'otp') !== false) continue;
        $old[$k] = $v;
    }
    $_SESSION['old_input'] = ['path' => strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?'), 'data' => $old];
}

/** Sirf usi page par aur sirf ek dafa: pichhle nakam POST ka data (footer.php). Warna null. */
function take_old_input()
{
    $old = $_SESSION['old_input'] ?? null;
    unset($_SESSION['old_input']);
    if (!$old || $old['path'] !== strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?')) {
        return null;
    }
    return $old['data'];
}

/** Current page par wapas redirect (query string ke saath) - Post/Redirect/Get pattern. */
function redirect_self($extraQuery = [])
{
    $path  = strtok($_SERVER['REQUEST_URI'], '?');
    $query = $_GET;
    foreach ($extraQuery as $k => $v) {
        $query[$k] = $v;
    }
    redirect($path . ($query ? '?' . http_build_query($query) : ''));
}

function money($amount, $decimals = 2)
{
    return number_format((float)$amount, $decimals);
}

/** Tareekh hamesha ek hi shakal mein: "2026-10-05" -> "05 Oct 2026" (khaali / ghalat ho to $empty). Har page yahi use kare. */
function fmt_date($date, $empty = '-')
{
    $t = $date ? strtotime((string)$date) : false;
    // non-breaking spaces: tareekh kabhi do lines mein nahi tootti
    return $t ? str_replace(' ', "\u{00A0}", date('d M Y', $t)) : $empty;
}

/** "2026-10-05 14:30:00" -> "05 Oct 2026, 02:30 PM" (tareekh aur waqt apni apni jagah nahi tootte) */
function fmt_datetime($datetime, $empty = '-')
{
    $t = $datetime ? strtotime((string)$datetime) : false;
    return $t ? fmt_date(date('Y-m-d', $t)) . ', ' . str_replace(' ', "\u{00A0}", date('h:i A', $t)) : $empty;
}

/** Activity log ki details dikhate waqt: purane entries ki "2026-10-07 01:00:00" jaisi tareekhen bhi "07 Oct 2026, 01:00 AM" mein */
function log_details($text)
{
    return preg_replace_callback('/\b(\d{4}-\d{2}-\d{2})(?: (\d{2}:\d{2})(?::\d{2})?)?\b/', function ($m) {
        $t = strtotime($m[0]);
        if (!$t) return $m[0];
        return date('d M Y', $t) . (isset($m[2]) ? ', ' . date('h:i A', $t) : '');
    }, (string)$text);
}

/** "2026-06" -> "June 2026" */
function month_label($ym)
{
    $d = DateTime::createFromFormat('!Y-m', (string)$ym);
    return $d ? $d->format('F Y') : (string)$ym;
}

function no_cache_headers()
{
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

/* ======================= Database ======================= */

function db()
{
    global $conn;
    return $conn;
}

/** Prepared statement run karta hai. Parameters ki types khud detect hoti hain. */
function db_stmt($sql, array $params = [])
{
    $stmt = db()->prepare($sql);
    if ($params) {
        $types = '';
        $values = [];
        foreach ($params as $p) {
            if (is_int($p) || is_bool($p)) {
                $types .= 'i';
                $values[] = (int)$p;
            } elseif (is_float($p)) {
                $types .= 'd';
                $values[] = $p;
            } else {
                $types .= 's';
                $values[] = $p === null ? null : (string)$p;
            }
        }
        $stmt->bind_param($types, ...$values);
    }
    $stmt->execute();
    return $stmt;
}

/** SELECT -> mysqli_result (mysqli_fetch_assoc / mysqli_num_rows ke saath use karein) */
function db_select($sql, array $params = [])
{
    return db_stmt($sql, $params)->get_result();
}

/** Pehli row (assoc array) ya null */
function db_row($sql, array $params = [])
{
    $row = db_select($sql, $params)->fetch_assoc();
    return $row ?: null;
}

/** Pehle column ki value ya $default */
function db_value($sql, array $params = [], $default = null)
{
    $row = db_select($sql, $params)->fetch_row();
    return ($row && $row[0] !== null) ? $row[0] : $default;
}

/** INSERT/UPDATE/DELETE -> affected rows */
function db_exec($sql, array $params = [])
{
    return db_stmt($sql, $params)->affected_rows;
}

function db_insert_id()
{
    return (int)db()->insert_id;
}

/** Duplicate-key (UNIQUE) error ko pehchanne ke liye */
function is_duplicate_error($e)
{
    return $e instanceof mysqli_sql_exception && (int)$e->getCode() === 1062;
}

/** Foreign key ki wajah se delete/update ruk jaye */
function is_fk_error($e)
{
    return $e instanceof mysqli_sql_exception && in_array((int)$e->getCode(), [1451, 1452], true);
}

/* ======================= Auth & Permissions ======================= */

/** Logged-in user ki fresh details database se (har request par - taake deactivate/permission change foran lagu ho). */
function current_user($refresh = false)
{
    static $user = false;
    if ($user !== false && !$refresh) {
        return $user;
    }
    if (empty($_SESSION['user_id'])) {
        return $user = null;
    }
    $user = db_row(
        "SELECT id, full_name, email, role, status, can_manage_fees, can_manage_salaries,
                can_manage_expenses, can_manage_accounts, must_change_password, locked_until, accepted_policy_at
         FROM users WHERE id = ?",
        [(int)$_SESSION['user_id']]
    );
    return $user;
}

function dashboard_url($role)
{
    switch ($role) {
        case 'Admin':      return url('admin/dashboard/index.php');
        case 'Accountant': return url('admin/accountant_portal/index.php');
        case 'Student':    return url('student/index.php');
        case 'Staff':      return url('staff/index.php');
        default:           return login_url();
    }
}

/**
 * Local CSS / JS file ka URL ?v=<filemtime> ke saath - file badalte hi URL badal jata hai, is liye browser kabhi
 * purani (cached) file use nahi karta. header.php aur footer.php har local file isi se load karte hain.
 */
function asset_url($path)
{
    return url($path) . '?v=' . (int)@filemtime(__DIR__ . '/../' . ltrim($path, '/'));
}

/** University logo ka URL (assets/img/ustb_logo.png); file na ho to null - phir generic icon dikhta hai */
function app_logo_url()
{
    static $url = false;
    if ($url === false) {
        $file = __DIR__ . '/../assets/img/ustb_logo.png';
        $url = is_file($file) ? url('assets/img/ustb_logo.png') . '?v=' . (int)filemtime($file) : null;
    }
    return $url;
}

/** "Dashboard" link (top nav aur print pages): Admin -> Admin Dashboard, Accountant -> Finance Modules, waghera */
function dashboard_link()
{
    $role = current_user()['role'] ?? '';
    return ['url' => dashboard_url($role), 'label' => $role === 'Admin' ? 'Admin Dashboard' : 'Dashboard'];
}

/** University logo documents ke heading ke upar (voucher, receipt, salary slip, reports). File na ho to kuch nahi. */
function doc_logo()
{
    return app_logo_url() ? '<img src="' . e(app_logo_url()) . '" alt="UST Bannu logo" class="doc-logo">' : '';
}

/* ======================= Login portals ======================= */

/**
 * Ek hi login system; portal sirf login page ka look badalta hai aur password ke baad role check karta hai.
 * 'name' error message mein aata hai ("cannot log in to the Student portal").
 */
const LOGIN_PORTALS = [
    'admin'      => ['title' => 'Administration', 'name' => 'Administration', 'role' => 'Admin', 'icon' => 'fa-shield-alt', 'color' => '#1e3a8a',
                     'desc' => 'Users, approvals, budgets and university-wide reports.'],
    'accountant' => ['title' => 'Finance Office', 'name' => 'Finance Office', 'role' => 'Accountant', 'icon' => 'fa-briefcase', 'color' => '#047857',
                     'desc' => 'Fee collection, payroll, expenses and vouchers.'],
    'staff'      => ['title' => 'Staff Portal', 'name' => 'Staff', 'role' => 'Staff', 'icon' => 'fa-user-tie', 'color' => '#6d28d9',
                     'desc' => 'Your profile, salary history and salary slips.'],
    'student'    => ['title' => 'Student Portal', 'name' => 'Student', 'role' => 'Student', 'icon' => 'fa-graduation-cap', 'color' => '#c2410c',
                     'desc' => 'Your fee challans, payments and receipts.'],
];
/** Aakhri portal cookie mein yaad rehta hai taake logout / session expire par wahi login page khule */
const PORTAL_COOKIE = 'ufms_portal';

/** Sahi portal key ya '' */
function valid_portal($portal)
{
    $portal = is_string($portal) ? $portal : '';
    return isset(LOGIN_PORTALS[$portal]) ? $portal : '';
}

function portal_for_role($role)
{
    foreach (LOGIN_PORTALS as $key => $p) {
        if ($p['role'] === $role) return $key;
    }
    return '';
}

function remember_portal($portal)
{
    if (valid_portal($portal) === '' || headers_sent()) return;
    setcookie(PORTAL_COOKIE, $portal, [
        'expires'  => time() + 180 * 86400,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[PORTAL_COOKIE] = $portal;
}

/**
 * Bina login protected page khola: us page ka apna portal (sirf Admin -> Administration,
 * Admin+Accountant -> Finance Office, Student / Staff). Kai roles wale page (e.g. change password) -> null (yaad rakha portal).
 */
function login_portal_for_roles(array $roles)
{
    if (count($roles) === 1) {
        return portal_for_role($roles[0]) ?: null;
    }
    if (!array_diff($roles, ['Admin', 'Accountant'])) {
        return 'accountant';
    }
    return null;
}

/** Login page ka URL; $portal na diya ho to cookie wala (yaad rakha) portal */
function login_url($portal = null)
{
    $portal = valid_portal($portal === null ? ($_COOKIE[PORTAL_COOKIE] ?? '') : $portal);
    return url('auth/login.php' . ($portal !== '' ? '?portal=' . $portal : ''));
}

function end_session()
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/**
 * Page ko sirf diye gaye roles ke liye khula rakhta hai.
 * Inactive/deleted user ko foran logout karta hai, aur temporary password wale ko
 * pehle password change karwata hai.
 */
function require_login(array $roles, $deniedMessage = null)
{
    no_cache_headers();
    $user = current_user();

    if (!$user || $user['status'] !== 'Active') {
        if ($user) {
            end_session();
            session_start();
            flash('error', 'Your account is inactive. Please contact the administrator.');
        } elseif (session_expired_portal()) {
            // Abhi timeout hui: message pehle hi flash mein; usi user ke portal ka login
            redirect(login_url(session_expired_portal()));
        } else {
            flash('error', $deniedMessage ?: 'Please login to continue.');
        }
        redirect(login_url(login_portal_for_roles($roles)));
    }

    // Account lock (ghalat passwords ya auto-lock) chalti session ko bhi khatam karta hai
    if ($mins = account_locked_minutes($user)) {
        end_session();
        session_start();
        flash('error', "Your account is locked. Please try again after {$mins} minute(s) or contact the administrator.");
        redirect(login_url());
    }

    $script = basename($_SERVER['SCRIPT_NAME']);
    if ((int)$user['must_change_password'] === 1 && $script !== 'change_password.php') {
        flash('warning', 'For security, please set a new password before continuing.');
        redirect(url('auth/change_password.php'));
    }

    if (!in_array($user['role'], $roles, true)) {
        log_access_denied($user, 'role ' . $user['role'] . ' not allowed');
        flash('error', $deniedMessage ?: 'You do not have access to that page.');
        redirect(dashboard_url($user['role']));
    }

    // Student/Staff: jab tak Acceptable Use Policy accept na ho (accepted_policy_at NULL) har protected page se policy page par -
    // bilkul must_change_password ki tarah. Sirf accept_policy.php aur change_password.php khule hain (logout require_login use nahi karta).
    // Order: pehle password (upar), phir policy, phir dashboard. Role check ke baad, taake ghalat page ACCESS_DENIED mein aaye.
    if (policy_required($user) && !in_array($script, ['change_password.php', 'accept_policy.php'], true)) {
        flash('warning', 'Please read and accept the Acceptable Use Policy before using the portal.');
        redirect(url('auth/accept_policy.php'));
    }

    $_SESSION['user_name'] = $user['full_name'];
    touch_session_activity();
    $GLOBALS['ufms_session_timer'] = session_time_left($user);  // footer.php ka "session expire hone wali hai" popup
    return $user;
}

/** Admin ke paas har permission hai; Accountant ke paas sirf jo Admin ne di ho. */
function can($permission)
{
    $user = current_user();
    if (!$user) {
        return false;
    }
    if ($user['role'] === 'Admin') {
        return true;
    }
    $col = 'can_manage_' . $permission;
    return $user['role'] === 'Accountant' && !empty($user[$col]);
}

/** $permission: 'fees' | 'salaries' | 'expenses' | 'accounts' */
function require_permission($permission)
{
    $user = require_login(['Admin', 'Accountant']);
    if (!can($permission)) {
        log_access_denied($user, "no '{$permission}' permission");
        flash('error', 'You do not have permission to manage ' . $permission . '. Please contact the administrator.');
        redirect(url('admin/accountant_portal/index.php'));
    }
    return $user;
}

/* ======================= CSRF ======================= */

function csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field()
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_verify()
{
    $sent = $_POST['csrf_token'] ?? '';
    if (!is_string($sent) || $sent === '' || !hash_equals(csrf_token(), $sent)) {
        // Session pehle hi khatam (expire) ho chuki ho to logout ka purana form: seedha login page
        if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'logout.php' && empty($_SESSION['user_id'])) {
            redirect(login_url(session_expired_portal()));
        }
        // Isi request mein timeout hui: form "expired" page ke bajaye login page (message flash mein)
        if (session_expired_portal()) {
            redirect(login_url(session_expired_portal()));
        }
        http_response_code(419);
        $hint = empty($_POST) ? ' (The uploaded file may be larger than the server allows.)' : '';
        exit('<div style="font-family:Arial;max-width:520px;margin:80px auto;padding:25px;border:1px solid #ffe69c;background:#fff3cd;border-radius:10px">'
            . '<h3 style="margin-top:0">Request expired</h3><p>Your session expired or the form was not valid' . e($hint)
            . '. Please go back, refresh the page and try again.</p><a href="javascript:history.back()">&larr; Go back</a></div>');
    }
}

/* ======================= Flash messages ======================= */

function flash($type, $message)
{
    $_SESSION['flash'][$type][] = $message;
}

function flash_render()
{
    if (empty($_SESSION['flash'])) {
        return '';
    }
    $map = [
        'success' => ['success', 'fa-check-circle'],
        'error'   => ['danger', 'fa-exclamation-triangle'],
        'warning' => ['warning', 'fa-exclamation-circle'],
        'info'    => ['info', 'fa-info-circle'],
    ];
    $html = '';
    foreach ($_SESSION['flash'] as $type => $messages) {
        list($cls, $icon) = $map[$type] ?? ['secondary', 'fa-info-circle'];
        foreach ($messages as $m) {
            $html .= '<div class="alert alert-' . $cls . ' alert-dismissible fade show py-2 shadow-sm" role="alert">'
                   . '<i class="fas ' . $icon . ' me-2"></i>' . e($m)
                   . '<button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button></div>';
        }
    }
    unset($_SESSION['flash']);
    return $html;
}

/* ======================= Audit log ======================= */

/** Kis ne kab kya kiya - Admin dashboard par "System Activity Logs" mein dikhta hai. */
function audit($action, $details = '', $user = null)
{
    $user = $user ?: current_user();
    try {
        db_exec(
            "INSERT INTO audit_logs (user_id, user_name, role, action, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)",
            [
                $user ? (int)$user['id'] : null,
                $user ? $user['full_name'] : null,
                $user ? $user['role'] : null,
                $action,
                function_exists('mb_substr') ? mb_substr((string)$details, 0, 255) : substr((string)$details, 0, 255),
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]
        );
    } catch (Throwable $t) {
        error_log('UFMS audit log failed: ' . $t->getMessage());
    }
}

/* ======================= Passwords ======================= */

/** Returns error message, ya null agar password theek hai. */
function password_problem($password)
{
    if (strlen($password) < MIN_PASSWORD_LENGTH) {
        return 'Password must be at least ' . MIN_PASSWORD_LENGTH . ' characters long.';
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        return 'Password must contain at least one letter and one number.';
    }
    return null;
}

/** Naye student/staff ke liye random temporary password (pehle login par change karna lazmi). */
function generate_temp_password($length = 10)
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    do {
        $pass = '';
        for ($i = 0; $i < $length; $i++) {
            $pass .= $chars[random_int(0, strlen($chars) - 1)];
        }
    } while (password_problem($pass) !== null);
    return $pass;
}

function valid_email($email)
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false && strlen($email) <= 100;
}

/* ======================= Email ======================= */

function mail_configured()
{
    return MAIL_ENABLED && MAIL_USERNAME !== '' && MAIL_PASSWORD !== '';
}

/**
 * Email bhejta hai. Kamyabi par true; nakami par false aur $error mein wajah.
 * Gmail password sirf config.php mein hai - code mein kahin nahi.
 */
function send_mail($toEmail, $toName, $subject, $htmlBody, &$error = null)
{
    if (!mail_configured()) {
        $error = 'email is not set up (MAIL_USERNAME / MAIL_PASSWORD are empty in includes/config.php)';
        error_log('UFMS mail not sent to ' . $toEmail . ': ' . $error);
        return false;
    }
    require_once __DIR__ . '/PHPMailer/Exception.php';
    require_once __DIR__ . '/PHPMailer/PHPMailer.php';
    require_once __DIR__ . '/PHPMailer/SMTP.php';

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = MAIL_PORT;
        $mail->CharSet    = 'UTF-8';
        $mail->Timeout    = 15; // internet na ho to page 5 minute tak na atke
        if (!MAIL_VERIFY_SSL) {
            $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
        }
        $mail->setFrom(MAIL_USERNAME, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = trim(html_entity_decode(strip_tags(str_replace(['<br>', '</p>', '</div>'], "\n", $htmlBody))));
        $mail->send();
        return true;
    } catch (\PHPMailer\PHPMailer\Exception $ex) {
        $error = $mail->ErrorInfo ?: $ex->getMessage();
        if (stripos($error, 'authenticate') !== false) {
            $error .= ' - check MAIL_USERNAME and the Gmail App Password in includes/config.php (2-Step Verification must be on)';
        }
        error_log('UFMS mail failed to ' . $toEmail . ': ' . $error);
        return false;
    }
}

/** Standard email layout */
/**
 * Kai emails ek saath (reminders): har batch ($batch emails) ek hi SMTP connection par, har recipient ko alag email.
 * $messages: [['email' => .., 'name' => .., 'subject' => .., 'html' => ..], ...].
 * Returns ['sent' => n, 'failed' => m, 'results' => [message key => ['ok' => bool, 'error' => string]]].
 */
function send_mail_bulk(array $messages, $batch = 20)
{
    $res = ['sent' => 0, 'failed' => 0, 'results' => []];
    if (!$messages) {
        return $res;
    }
    if (!mail_configured()) {
        $res['failed'] = count($messages);
        foreach ($messages as $k => $m) { $res['results'][$k] = ['ok' => false, 'error' => 'Email is not set up (includes/config.php)']; }
        return $res;
    }
    require_once __DIR__ . '/PHPMailer/Exception.php';
    require_once __DIR__ . '/PHPMailer/PHPMailer.php';
    require_once __DIR__ . '/PHPMailer/SMTP.php';
    @set_time_limit(0);
    foreach (array_chunk($messages, max(1, (int)$batch), true) as $chunk) {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = MAIL_PORT;
        $mail->CharSet    = 'UTF-8';
        $mail->Timeout    = 15;
        $mail->SMTPKeepAlive = true;   // poora batch ek connection par
        if (!MAIL_VERIFY_SSL) {
            $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
        }
        foreach ($chunk as $k => $m) {
            try {
                $mail->clearAllRecipients();
                $mail->setFrom(MAIL_USERNAME, MAIL_FROM_NAME);
                $mail->addAddress($m['email'], $m['name']);
                $mail->isHTML(true);
                $mail->Subject = $m['subject'];
                $mail->Body    = $m['html'];
                $mail->AltBody = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br />', '</p>', '</div>'], "\n", $m['html']))));
                $mail->send();
                $res['sent']++;
                $res['results'][$k] = ['ok' => true, 'error' => ''];
            } catch (\PHPMailer\PHPMailer\Exception $ex) {
                $res['failed']++;
                $why = $mail->ErrorInfo ?: $ex->getMessage();
                $res['results'][$k] = ['ok' => false, 'error' => $why];
                error_log('UFMS bulk mail failed to ' . $m['email'] . ': ' . $why);
                try { if ($mail->getSMTPInstance()->connected()) { $mail->getSMTPInstance()->reset(); } } catch (\Throwable $t) { /* agla email naya connection khol lega */ }
            }
        }
        $mail->smtpClose();
    }
    return $res;
}

function email_template($title, $innerHtml, $color = '#1e3a8a')
{
    return "<div style='font-family:Arial,sans-serif;padding:20px;background:#f4f6f9;'>"
         . "<div style='max-width:560px;margin:0 auto;background:#fff;border-radius:8px;padding:25px;border:1px solid #e0e0e0;'>"
         . "<h2 style='color:{$color};margin-top:0;'>" . e($title) . "</h2>{$innerHtml}"
         . "<p style='color:#6c757d;font-size:12px;margin-top:25px;'>" . e(APP_ORG) . "</p></div></div>";
}

/** Naye account ke login credentials email karta hai. */
function send_credentials_email($email, $name, $accountLabel, $tempPassword, array $extraRows = [], &$error = null)
{
    $rows = '';
    foreach ($extraRows as $label => $value) {
        $rows .= "<p style='margin:0 0 8px 0;'><strong>" . e($label) . ":</strong> " . e($value) . "</p>";
    }
    $inner = "<p>Dear <strong>" . e($name) . "</strong>,</p>"
           . "<p>Your " . e($accountLabel) . " account has been created on the " . e(APP_FULL_NAME) . ".</p>"
           . "<div style='background:#f8f9fa;border-left:4px solid #1e3a8a;padding:15px;margin:20px 0;'>{$rows}"
           . "<p style='margin:0 0 8px 0;'><strong>Login Email:</strong> " . e($email) . "</p>"
           . "<p style='margin:0;'><strong>Temporary Password:</strong> <span style='color:#d9534f;font-weight:bold;'>" . e($tempPassword) . "</span></p></div>"
           . "<p>You will be asked to set your own password at first login. Do not share this email with anyone.</p>"
           . "<p style='text-align:center;margin-top:25px;'><a href='" . e(rtrim(APP_URL, '/') . '/auth/login.php') . "' "
           . "style='background:#1e3a8a;color:#fff;padding:10px 22px;text-decoration:none;border-radius:20px;font-weight:bold;'>Login to Portal</a></p>";
    return send_mail($email, $name, 'Your ' . APP_NAME . ' Portal Account', email_template('Welcome to ' . APP_NAME . ' Portal', $inner), $error);
}

/* ======================= Uploads ======================= */

function receipt_dir()
{
    return defined('RECEIPT_DIR') ? RECEIPT_DIR : __DIR__ . '/../uploads/expenses/';
}

/**
 * Upload validate karke $dir mein random naam se save karta hai (content bhi check hota hai, sirf extension nahi).
 * $allowed: ['pdf' => ['application/pdf'], ...]. Returns: naya file name, null (koi file nahi di), ya false ($error mein wajah).
 */
function store_upload($field, $dir, array $allowed, &$error = null, $label = 'File')
{
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $file = $_FILES[$field];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE)
            ? "{$label} file is too large." : "{$label} upload failed. Please try again.";
        return false;
    }
    if ($file['size'] > MAX_UPLOAD_BYTES) {
        $error = "{$label} file must be smaller than " . (MAX_UPLOAD_BYTES / 1024 / 1024) . ' MB.';
        return false;
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!isset($allowed[$ext])) {
        $error = 'Only ' . strtoupper(implode(', ', array_keys($allowed))) . " files are allowed for the {$label}.";
        return false;
    }
    if (class_exists('finfo')) {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!in_array($mime, $allowed[$ext], true)) {
            $error = 'The uploaded file content does not match its extension.';
            return false;
        }
    }
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        $error = 'Upload folder could not be created.';
        return false;
    }
    $name = date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . $name)) {
        $error = "Failed to save the {$label} file.";
        return false;
    }
    return $name;
}

/** Expense receipt (JPG, PNG, PDF) - store_upload() ke zariye */
function store_receipt_upload($field, &$error = null)
{
    $allowed = ['jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'pdf' => ['application/pdf']];
    return store_upload($field, receipt_dir(), $allowed, $error, 'Receipt');
}

function delete_receipt_file($name)
{
    $name = basename((string)$name);
    if ($name !== '' && is_file(receipt_dir() . $name)) {
        @unlink(receipt_dir() . $name);
    }
}

/* ======================= Finance helpers ======================= */

/**
 * Kisi student ki kisi semester ki fee (program ke fee structure se).
 * Admission fee sirf semester 1 mein; scholarship/discount minus hota hai.
 */
function semester_fee_breakdown(array $student, $semester)
{
    $fs = null;
    if (!empty($student['program_id'])) {
        $fs = db_row("SELECT * FROM fee_structure WHERE id = ?", [(int)$student['program_id']]);
    }
    $b = [
        'tuition'   => $fs ? (float)$fs['tuition_fee'] : 0.0,
        'admission' => ($fs && (int)$semester === 1) ? (float)$fs['admission_fee'] : 0.0,
        'exam'      => $fs ? (float)$fs['exam_fee'] : 0.0,
        'other'     => $fs ? (float)$fs['other_charges'] : 0.0,
        'discount'  => (float)($student['scholarship_discount'] ?? 0),
        'has_structure' => (bool)$fs,
    ];
    $gross = $b['tuition'] + $b['admission'] + $b['exam'] + $b['other'];
    $b['discount'] = min($b['discount'], $gross);
    $b['total'] = round($gross - $b['discount'], 2);
    return $b;
}

function fee_status($total, $fine, $paid)
{
    $due = round((float)$total + (float)$fine, 2);
    if (round((float)$paid, 2) >= $due) {
        return 'Paid';
    }
    return $paid > 0 ? 'Partial' : 'Unpaid';
}

/** Form se aaye amount ko validate karta hai (null = invalid) */
function parse_amount($value, $allowZero = true)
{
    if (!is_numeric($value)) {
        return null;
    }
    $n = round((float)$value, 2);
    if ($n < 0 || (!$allowZero && $n == 0) || $n > 99999999.99) {
        return null;
    }
    return $n;
}

function valid_date($value)
{
    $d = DateTime::createFromFormat('!Y-m-d', (string)$value);
    return $d && $d->format('Y-m-d') === $value;
}

function valid_month($value)
{
    $d = DateTime::createFromFormat('!Y-m', (string)$value);
    return $d && $d->format('Y-m') === $value;
}

/** Programs (fee structures) dropdown ke liye */
function all_programs()
{
    return db_select("SELECT id, program_name FROM fee_structure ORDER BY program_name")->fetch_all(MYSQLI_ASSOC);
}
