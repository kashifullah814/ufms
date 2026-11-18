<?php
/**
 * Automatic security rules (Admin ko kuch manually nahi karna parta). Tafseel: docs/security_rules.md
 *  1. ACCESS_DENIED log: require_login()/require_permission() se roka gaya logged-in user (user+page, 1/minute)
 *  2. Auto-lock: 10 minute mein 5 ACCESS_DENIED -> logout + 30 minute lock (Admin kabhi nahi)
 *  3. IP throttling: ek IP se 15 minute mein 20 se zyada ghalat logins -> us IP se 15 minute login band
 *  4. Login pages par warning; Student/Staff pehli dafa Acceptable Use Policy accept karte hain
 *  5. ADMIN_ALLOWED_IPS: Administration/Finance Office portals aur admin/ pages sirf university network se
 *  6. ADMIN_2FA: Admin ka password sahi hone ke baad email par 6-digit OTP
 *  7. Wrong-portal strikes: sahi password + ghalat portal, 24 ghante mein 3 dafa -> account 24 ghante block
 *     (Admin kabhi nahi). Ghalat password wala rule (5 -> 15 minute) is se alag hai.
 *  8. Session timeout (server par): 30 minute kuch na karne par (Admin 15) ya 8 ghante purani session -> dobara login.
 *     1 minute pehle page par popup (Stay logged in -> auth/keep_alive.php).
 * Settings config.php mein; na hon to neeche wale defaults.
 */

$security_defaults = [
    'ADMIN_ALLOWED_IPS'        => '',     // '' = sab jagah se; e.g. '127.0.0.1, 10.0.0.0/8, 192.168.1.0/24'
    'ADMIN_2FA'                => false,  // true = Admin login par email OTP
    'ADMIN_OTP_MINUTES'        => 10,
    'AUTO_LOCK_DENIALS'        => 5,
    'AUTO_LOCK_WINDOW_MINUTES' => 10,
    'AUTO_LOCK_MINUTES'        => 30,
    'IP_MAX_FAILED_LOGINS'     => 20,
    'IP_WINDOW_MINUTES'        => 15,
    'IP_BLOCK_MINUTES'         => 15,
    'WRONG_PORTAL_MAX_STRIKES' => 3,
    'WRONG_PORTAL_WINDOW_HOURS' => 24,
    'WRONG_PORTAL_BLOCK_HOURS' => 24,
    'SESSION_IDLE_MINUTES'     => 30,
    'ADMIN_IDLE_MINUTES'       => 15,
    'SESSION_MAX_HOURS'        => 8,
];
foreach ($security_defaults as $k => $v) {
    if (!defined($k)) define($k, $v);
}
unset($security_defaults, $k, $v);

const LOGIN_WARNING = 'Unauthorized access is prohibited. All activity is logged.';
const NETWORK_ONLY_MESSAGE = 'This portal is available only on the university network.';
const TOO_MANY_ATTEMPTS_MESSAGE = 'Too many attempts. Please try again later.';
/** Yeh portals / roles sirf ADMIN_ALLOWED_IPS se */
const NETWORK_ONLY_PORTALS = ['admin', 'accountant'];
const NETWORK_ONLY_ROLES = ['Admin', 'Accountant'];
/** Acceptable Use Policy sirf in roles ke liye */
const POLICY_ROLES = ['Student', 'Staff'];
/** users.lock_reason jab account wrong-portal strikes se block ho */
const LOCK_WRONG_PORTAL = 'WRONG_PORTAL';

function client_ip()
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

/* ======================= 1 + 2. ACCESS_DENIED aur auto-lock ======================= */

/**
 * Logged-in user ko page se roka gaya (ghalat role / permission nahi) - audit log mein ACCESS_DENIED.
 * Details: "Page: <path> | Reason: <reason>". Ek hi user + page ek minute mein sirf ek dafa log hota hai.
 * Naya event log hone par auto-lock rule check hota hai.
 */
function log_access_denied(array $user, $reason)
{
    $page = (string)strtok((string)($_SERVER['REQUEST_URI'] ?? $_SERVER['SCRIPT_NAME'] ?? ''), '?');
    $page = function_exists('mb_substr') ? mb_substr($page, 0, 150) : substr($page, 0, 150);
    $prefix = 'Page: ' . $page . ' | ';
    $recent = db_value("SELECT COUNT(*) FROM audit_logs WHERE user_id = ? AND action = 'ACCESS_DENIED'
                        AND created_at >= NOW() - INTERVAL 1 MINUTE AND LEFT(details, CHAR_LENGTH(?)) = ?",
        [(int)$user['id'], $prefix, $prefix], 0);
    if ((int)$recent === 0) {
        audit('ACCESS_DENIED', $prefix . 'Reason: ' . $reason, $user);
        auto_lock_if_needed($user);
    }
}

/** 10 minute mein 5 ACCESS_DENIED -> account 30 minute lock, logout, login page. Admin kabhi lock nahi hota. */
function auto_lock_if_needed(array $user)
{
    if ($user['role'] === 'Admin') {
        return;
    }
    $count = (int)db_value("SELECT COUNT(*) FROM audit_logs WHERE user_id = ? AND action = 'ACCESS_DENIED'
                            AND created_at >= NOW() - INTERVAL " . (int)AUTO_LOCK_WINDOW_MINUTES . " MINUTE", [(int)$user['id']], 0);
    if ($count < AUTO_LOCK_DENIALS) {
        return;
    }
    db_exec("UPDATE users SET locked_until = ?, lock_reason = NULL, failed_logins = 0 WHERE id = ?",
        [date('Y-m-d H:i:s', time() + AUTO_LOCK_MINUTES * 60), (int)$user['id']]);
    audit('ACCOUNT_AUTO_LOCKED', "Locked for " . AUTO_LOCK_MINUTES . " minutes after {$count} refused page requests in "
        . AUTO_LOCK_WINDOW_MINUTES . " minutes", $user);
    end_session();
    session_start();
    flash('error', 'Your account has been locked for ' . AUTO_LOCK_MINUTES . ' minutes because of repeated attempts to open pages you are not allowed to use. '
        . 'Contact the administrator if this is a mistake.');
    redirect(login_url());
}

/** Account abhi locked hai? (ghalat passwords ya auto-lock). Minutes baqi, warna 0 */
function account_locked_minutes(array $user)
{
    if (empty($user['locked_until'])) return 0;
    $left = strtotime($user['locked_until']) - time();
    return $left > 0 ? (int)ceil($left / 60) : 0;
}

/* ======================= 7. Wrong-portal strikes ======================= */

/** Ek strike record karo; pichle 24 ghante ke strikes (is samet) wapas */
function add_portal_strike(array $user, $portal)
{
    db_exec("DELETE FROM portal_strikes WHERE created_at < NOW() - INTERVAL 2 DAY");
    db_exec("INSERT INTO portal_strikes (user_id, portal, ip_address) VALUES (?, ?, ?)", [(int)$user['id'], (string)$portal, client_ip()]);
    return (int)db_value("SELECT COUNT(*) FROM portal_strikes WHERE user_id = ?
                          AND created_at >= NOW() - INTERVAL " . (int)WRONG_PORTAL_WINDOW_HOURS . " HOUR", [(int)$user['id']], 0);
}

function wrong_portal_block_message($until)
{
    return 'Your account is blocked until ' . fmt_datetime($until)
        . ' due to repeated unauthorized portal access. Contact the Finance Office.';
}

/** Teesra strike: account 24 ghante block, strikes reset, audit, email. Block khatam hone ka waqt wapas. */
function block_account_wrong_portal(array $user, $strikes)
{
    $until = date('Y-m-d H:i:s', time() + WRONG_PORTAL_BLOCK_HOURS * 3600);
    db_exec("UPDATE users SET locked_until = ?, lock_reason = ?, failed_logins = 0 WHERE id = ?", [$until, LOCK_WRONG_PORTAL, (int)$user['id']]);
    db_exec("DELETE FROM portal_strikes WHERE user_id = ?", [(int)$user['id']]);
    audit('ACCOUNT_BLOCKED_24H', "Blocked until " . date('d M Y, h:i A', strtotime($until)) . " after {$strikes} wrong-portal logins in " . WRONG_PORTAL_WINDOW_HOURS . " hours", $user);
    if (mail_configured()) {
        $inner = "<p>Hello <strong>" . e($user['full_name']) . "</strong>,</p>"
               . "<p>Your " . e(APP_NAME) . " account has been <strong>blocked until " . e(fmt_datetime($until)) . "</strong> "
               . "because someone logged in with your password " . (int)$strikes . " times to a portal that is not yours.</p>"
               . "<p>If this was not you, your password may be known to someone else. Please contact the Finance Office; "
               . "after the block ends, change your password.</p>";
        if (!send_mail($user['email'], $user['full_name'], APP_NAME . ' - Account blocked', email_template('Account Blocked', $inner), $mailErr)) {
            error_log('UFMS block email failed: ' . $mailErr);
        }
    }
    return $until;
}

/** Admin ne unlock kiya: lock hatao, strikes aur ghalat password ginti reset */
function unlock_account(array $target)
{
    db_exec("UPDATE users SET locked_until = NULL, lock_reason = NULL, failed_logins = 0 WHERE id = ?", [(int)$target['id']]);
    db_exec("DELETE FROM portal_strikes WHERE user_id = ?", [(int)$target['id']]);
    audit('ACCOUNT_UNLOCKED', "Unlocked {$target['full_name']} ({$target['role']}, {$target['email']}); strikes reset");
}

/** Dashboard: abhi wrong-portal rule se blocked accounts */
function wrong_portal_blocked_users()
{
    return db_select("SELECT id, full_name, email, role, locked_until FROM users WHERE lock_reason = ? AND locked_until > ? ORDER BY locked_until",
        [LOCK_WRONG_PORTAL, date('Y-m-d H:i:s')])->fetch_all(MYSQLI_ASSOC);
}

/* ======================= 8. Session timeout ======================= */

/** Is role ki idle limit (seconds) */
function session_idle_seconds($role)
{
    return max(1, (int)round(($role === 'Admin' ? ADMIN_IDLE_MINUTES : SESSION_IDLE_MINUTES) * 60));
}

function session_max_seconds()
{
    return max(1, (int)round(SESSION_MAX_HOURS * 3600));
}

/**
 * Har request par (bootstrap.php): logged-in session idle limit ya 8 ghante se purani ho to khatam.
 * Audit SESSION_EXPIRED, flash message; require_login()/csrf_verify() phir usi portal ke login par bhejte hain.
 * Purani session jis mein abhi waqt save nahi (yeh feature aane se pehle ki) - abhi se ginti shuru.
 */
function check_session_timeout()
{
    if (PHP_SAPI === 'cli' || empty($_SESSION['user_id'])) return;
    $now = time();
    if (empty($_SESSION['login_at']) || empty($_SESSION['last_activity'])) {
        $_SESSION['login_at'] = $_SESSION['login_at'] ?? $now;
        $_SESSION['last_activity'] = $_SESSION['last_activity'] ?? $now;
        return;
    }
    $user = current_user();
    if (!$user) return;
    $idle = $now - (int)$_SESSION['last_activity'];
    $age  = $now - (int)$_SESSION['login_at'];
    if ($idle > session_idle_seconds($user['role'])) {
        $detail = 'Idle for ' . (int)floor($idle / 60) . ' minute(s) (limit ' . (int)round(session_idle_seconds($user['role']) / 60) . ')';
        $msg = 'Your session expired due to inactivity. Please log in again.';
    } elseif ($age > session_max_seconds()) {
        $detail = 'Session older than ' . SESSION_MAX_HOURS . ' hours';
        $msg = 'Your session reached the ' . SESSION_MAX_HOURS . '-hour limit. Please log in again.';
    } else {
        return;
    }
    audit('SESSION_EXPIRED', $detail, $user);
    end_session();
    session_start();
    session_expired_portal(portal_for_role($user['role']));
    flash('error', $msg);
    current_user(true);
}

/** Isi request mein session expire hui? Us user ka portal (set karne ke liye argument do) */
function session_expired_portal($set = null)
{
    static $portal = null;
    if ($set !== null) $portal = $set;
    return $portal;
}

/** Page dekhna = activity (require_login aur keep-alive) */
function touch_session_activity()
{
    $_SESSION['last_activity'] = time();
    if (empty($_SESSION['login_at'])) $_SESSION['login_at'] = time();
}

/** Kitne seconds mein session khatam hogi aur kyun ('idle' ya 'max') - page ka popup timer */
function session_time_left(array $user)
{
    $idleLeft = session_idle_seconds($user['role']) - (time() - (int)($_SESSION['last_activity'] ?? time()));
    $maxLeft  = session_max_seconds() - (time() - (int)($_SESSION['login_at'] ?? time()));
    return $maxLeft < $idleLeft ? ['seconds' => max(0, $maxLeft), 'reason' => 'max'] : ['seconds' => max(0, $idleLeft), 'reason' => 'idle'];
}

/* ======================= 3. IP login throttling ======================= */

/** Is IP se login abhi band hai? */
function ip_login_blocked()
{
    return (int)db_value("SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND blocked_until > NOW()", [client_ip()], 0) > 0;
}

/** Ghalat login (ya ghalat 2FA code) record karo; 15 minute mein 20 se zyada ho to IP 15 minute ke liye band */
function record_failed_login($email)
{
    $ip = client_ip();
    db_exec("DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 1 DAY");
    db_exec("INSERT INTO login_attempts (ip_address, email) VALUES (?, ?)",
        [$ip, function_exists('mb_substr') ? mb_substr((string)$email, 0, 100) : substr((string)$email, 0, 100)]);
    $id = db_insert_id();
    $fails = (int)db_value("SELECT COUNT(*) FROM login_attempts WHERE ip_address = ?
                            AND attempted_at >= NOW() - INTERVAL " . (int)IP_WINDOW_MINUTES . " MINUTE", [$ip], 0);
    if ($fails > IP_MAX_FAILED_LOGINS) {
        db_exec("UPDATE login_attempts SET blocked_until = NOW() + INTERVAL " . (int)IP_BLOCK_MINUTES . " MINUTE WHERE id = ?", [$id]);
        audit('LOGIN_IP_BLOCKED', "IP {$ip} blocked for " . IP_BLOCK_MINUTES . " minutes after {$fails} failed logins in " . IP_WINDOW_MINUTES . " minutes", null);
    }
}

/* ======================= Security headers ======================= */

/**
 * Har PHP page ke saath: frame mein sirf apni site, MIME sniffing band, referrer mehdood, PHP version chhupa,
 * aur Content-Security-Policy (scripts / styles sirf apni site + jsDelivr + cdnjs se; pages mein inline <script>/<style> hain).
 */
function send_security_headers()
{
    if (PHP_SAPI === 'cli' || headers_sent()) return;
    header_remove('X-Powered-By');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; "
         . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; "
         . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; "
         . "font-src 'self' data: https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; "
         . "img-src 'self' data: blob:; connect-src 'self'; frame-src 'self' blob: data:; object-src 'none'; "
         . "base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
}

/* ======================= 4. Acceptable Use Policy ======================= */

/** Student/Staff jinhon ne abhi policy accept nahi ki */
function policy_required(array $user)
{
    return in_array($user['role'], POLICY_ROLES, true) && empty($user['accepted_policy_at']);
}

/* ======================= 5. Campus-only network ======================= */

/** $ip list mein hai? Entries: exact IP ya CIDR range (IPv4/IPv6), comma ya space se alag */
function ip_in_list($ip, $list)
{
    $bin = @inet_pton((string)$ip);
    if ($bin === false) return false;
    foreach (preg_split('/[\s,]+/', (string)$list, -1, PREG_SPLIT_NO_EMPTY) as $entry) {
        $parts = explode('/', $entry, 2);
        $net = @inet_pton($parts[0]);
        if ($net === false || strlen($net) !== strlen($bin)) continue;
        $bits = isset($parts[1]) ? (int)$parts[1] : strlen($bin) * 8;
        if ((isset($parts[1]) && !ctype_digit($parts[1])) || $bits > strlen($bin) * 8) continue;
        $bytes = intdiv($bits, 8);
        $rem = $bits % 8;
        if (substr($bin, 0, $bytes) !== substr($net, 0, $bytes)) continue;
        if ($rem === 0) return true;
        $mask = (0xFF << (8 - $rem)) & 0xFF;
        if ((ord($bin[$bytes]) & $mask) === (ord($net[$bytes]) & $mask)) return true;
    }
    return false;
}

/** Administration / Finance Office is IP se chal sakte hain? (ADMIN_ALLOWED_IPS khali = haan) */
function admin_network_allowed()
{
    return trim((string)ADMIN_ALLOWED_IPS) === '' || ip_in_list(client_ip(), ADMIN_ALLOWED_IPS);
}

/** admin/ folder ke pages university network ke bahar se band (bootstrap.php se call hota hai) */
function enforce_admin_network()
{
    if (PHP_SAPI === 'cli' || admin_network_allowed()) return;
    $script = realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $adminDir = realpath(__DIR__ . '/../admin');
    if ($script && $adminDir && strpos($script, $adminDir . DIRECTORY_SEPARATOR) === 0) {
        http_response_code(403);
        no_cache_headers();
        $page_title = 'University network only';
        $body_class = 'd-flex align-items-center justify-content-center min-vh-100 px-3';
        include __DIR__ . '/header.php';
        echo '<div class="bg-white shadow p-4 p-md-5 rounded-4 text-center" style="max-width:440px;border-top:5px solid #dc3545;">'
           . '<i class="fas fa-network-wired fa-3x text-danger mb-3"></i><h5 class="fw-bold">' . e(NETWORK_ONLY_MESSAGE) . '</h5>'
           . '<p class="text-muted small mb-4">Connect to the UST Bannu campus network and try again. Student and Staff portals work from anywhere.</p>'
           . '<a href="' . e(url('index.php')) . '" class="btn btn-brand rounded-pill px-4">Choose another portal</a></div>';
        include __DIR__ . '/footer.php';
        exit();
    }
}

/* ======================= 6. Admin two-factor login ======================= */

/** Naya 6-digit OTP: [code, hash] (password reset aur Admin 2FA dono yahi use karte hain) */
function otp_new()
{
    $code = (string)random_int(100000, 999999);
    return [$code, password_hash($code, PASSWORD_BCRYPT)];
}

function admin_2fa_required(array $user)
{
    return ADMIN_2FA && $user['role'] === 'Admin';
}

/** Password sahi: OTP email karo aur session mein (hash) rakho. Session login OTP ke baad shuru hota hai. */
function start_admin_2fa(array $user, $portal, &$error = null)
{
    list($code, $hash) = otp_new();
    $inner = "<p>Hello <strong>" . e($user['full_name']) . "</strong>,</p>"
           . "<p>Your " . e(APP_NAME) . " Administration login code is:</p>"
           . "<div style='background:#f8f9fa;border-left:4px solid #1e3a8a;padding:15px;margin:20px 0;text-align:center;'>"
           . "<span style='font-size:28px;font-weight:bold;letter-spacing:5px;color:#1e3a8a;'>{$code}</span></div>"
           . "<p style='color:#6c757d;font-size:13px;'>This code is valid for " . ADMIN_OTP_MINUTES . " minutes. "
           . "If you did not just log in, someone knows your password: change it immediately.</p>";
    if (!send_mail($user['email'], $user['full_name'], APP_NAME . ' - Admin Login Code', email_template('Admin Login Code', $inner), $error)) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['pending_2fa'] = [
        'user_id'  => (int)$user['id'],
        'hash'     => $hash,
        'expires'  => time() + ADMIN_OTP_MINUTES * 60,
        'attempts' => 0,
        'portal'   => valid_portal($portal),
    ];
    audit('ADMIN_2FA_SENT', 'Login code emailed', $user);
    return true;
}

/** Kamyab login (password, aur Admin 2FA ho to OTP ke baad): session shuru, audit, redirect */
function complete_login(array $user, $portal, $via2fa = false)
{
    db_exec("UPDATE users SET failed_logins = 0, locked_until = NULL, lock_reason = NULL WHERE id = ?", [(int)$user['id']]);
    session_regenerate_id(true);
    unset($_SESSION['old_login_email'], $_SESSION['pending_2fa']);
    $_SESSION['user_id']   = (int)$user['id'];
    $_SESSION['user_name'] = $user['full_name'];
    $_SESSION['role']      = $user['role'];
    $_SESSION['login_at']  = $_SESSION['last_activity'] = time();
    remember_portal(portal_for_role($user['role']));
    $portal = valid_portal($portal);
    audit('LOGIN', $user['role'] . ' logged in' . ($portal !== '' ? ' (' . LOGIN_PORTALS[$portal]['name'] . ' portal)' : '')
        . ($via2fa ? ' with 2FA code' : ''), $user);

    if ((int)$user['must_change_password'] === 1) {
        flash('warning', 'For security, please set a new password before continuing.');
        redirect(url('auth/change_password.php'));
    }
    redirect(dashboard_url($user['role']));
}
