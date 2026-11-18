<?php
/**
 * Reminders (table: announcements) - fee reminders + general notices. Page: admin/accountant_portal/reminders.php
 *  type 'General': audience 'All' landing page aur har dashboard par; Students / Staff / Accountants sirf apne dashboard par.
 *  type 'Fee'    : sirf un students ko jin ki dues baqi hain - fee_scope 'All' | 'Filter' (program / semester) | 'Student'.
 *                  Message mein {name} {balance} {due_date} {reg_no}; har student apni values dekhta hai.
 *                  Balance 0 hote hi reminder us student ke liye ghayab ho jata hai.
 *  start_date (bhejne ki tareekh) se end_date ("show until", NULL = hamesha) tak active; pinned pehle.
 *  Delivery: table reminder_recipients (har recipient: status None / Sent / Failed, error, sent_at, seen_at).
 *  Sending sirf MANUAL hai (Send Reminder / Retry failed buttons) - koi cron ya automatic email nahi.
 */
const REMINDER_TYPES = ['General', 'Fee'];
const REMINDER_AUDIENCES = ['All', 'Students', 'Staff', 'Accountants'];
const REMINDER_FEE_SCOPES = ['All', 'Filter', 'Student'];
const REMINDER_PLACEHOLDERS = ['{name}', '{balance}', '{due_date}', '{reg_no}'];
const REMINDER_EMAIL_BATCH = 20;   // ek SMTP connection par itni emails
const REMINDER_DUPLICATE_HOURS = 24; // itne ghanton mein dobara fee reminder = pehle confirmation

/**
 * Students jin ki fee baqi hai (Fee Collection wali hi tareef: Paid ke ilawa challans ka balance).
 * $f: program_id, semester, student_id (sab optional). Sirf Active accounts.
 */
function students_with_dues(array $f = [])
{
    $where = ["u.status = 'Active'"];
    $params = [];
    if (!empty($f['program_id'])) { $where[] = 's.program_id = ?'; $params[] = (int)$f['program_id']; }
    if (!empty($f['semester']))   { $where[] = 's.semester = ?';   $params[] = (int)$f['semester']; }
    if (!empty($f['student_id'])) { $where[] = 's.id = ?';         $params[] = (int)$f['student_id']; }
    return db_select(
        "SELECT s.id AS student_id, s.user_id, s.reg_number, s.program_id, s.semester, u.full_name, u.email, t.pending AS balance
         FROM students s
         JOIN users u ON u.id = s.user_id
         JOIN (SELECT student_id, SUM(balance) AS pending FROM fees_records WHERE paid_status <> 'Paid'
               GROUP BY student_id HAVING SUM(balance) > 0) t ON t.student_id = s.id
         WHERE " . implode(' AND ', $where) . " ORDER BY u.full_name, s.id", $params)->fetch_all(MYSQLI_ASSOC);
}

/** Fee reminder ke scope se students_with_dues() ke filters */
function reminder_fee_filters(array $r)
{
    if ($r['fee_scope'] === 'Student') return ['student_id' => (int)$r['target_student_id']];
    if ($r['fee_scope'] === 'Filter')  return ['program_id' => (int)$r['target_program_id'], 'semester' => (int)$r['target_semester']];
    return [];
}

/** {name} {balance} {due_date} {reg_no} ki values ek student ke liye */
function reminder_vars(array $student, $dueDate)
{
    $bal = (float)$student['balance'];
    return [
        '{name}'     => $student['full_name'],
        '{balance}'  => money($bal, abs($bal - round($bal)) < 0.005 ? 0 : 2),
        '{due_date}' => $dueDate ? fmt_date($dueDate) : '',
        '{reg_no}'   => $student['reg_number'],
    ];
}

/** Placeholders badal do (output par e() phir bhi lazmi hai) */
function reminder_render($text, array $vars)
{
    return strtr((string)$text, $vars);
}

/**
 * Reminder kis kis ko jata hai (bhejte waqt): [['user_id','full_name','email','vars'], ...]
 * Fee: dues wale students (scope ke mutabiq). General: us audience ke Active users.
 */
function reminder_recipients(array $r)
{
    $out = [];
    if ($r['type'] === 'Fee') {
        foreach (students_with_dues(reminder_fee_filters($r)) as $s) {
            $out[] = ['user_id' => (int)$s['user_id'], 'full_name' => $s['full_name'], 'email' => $s['email'], 'vars' => reminder_vars($s, $r['due_date'])];
        }
        return $out;
    }
    $roles = ['All' => ['Student', 'Staff', 'Accountant'], 'Students' => ['Student'], 'Staff' => ['Staff'], 'Accountants' => ['Accountant']][$r['audience']] ?? [];
    if (!$roles) return [];
    $marks = implode(',', array_fill(0, count($roles), '?'));
    $res = db_select("SELECT id, full_name, email FROM users WHERE status = 'Active' AND role IN ($marks) ORDER BY full_name, id", $roles);
    while ($u = $res->fetch_assoc()) {
        $out[] = ['user_id' => (int)$u['id'], 'full_name' => $u['full_name'], 'email' => $u['email'], 'vars' => []];
    }
    return $out;
}

/** Har recipient ko ek email (email_template), batches mein. Returns ['sent' => n, 'failed' => m, 'results' => [user_id => ['ok', 'error']]] */
function send_reminder_emails(array $r, array $recipients)
{
    $messages = [];
    foreach ($recipients as $rc) {
        $title = reminder_render($r['title'], $rc['vars']);
        $inner = "<p>Hello <strong>" . e($rc['full_name']) . "</strong>,</p><p>" . nl2br(e(reminder_render($r['body'], $rc['vars']))) . "</p>";
        if ($r['type'] === 'Fee') {
            $inner .= "<div style='background:#f8f9fa;border-left:4px solid #1e3a8a;padding:12px 15px;margin:18px 0;'>"
                    . "Pending balance: <strong>PKR " . e($rc['vars']['{balance}']) . "</strong><br>Pay by: <strong>" . e($rc['vars']['{due_date}']) . "</strong></div>";
        }
        $inner .= "<p style='text-align:center;margin-top:22px;'><a href='" . e(rtrim(APP_URL, '/') . '/') . "' "
                . "style='background:#1e3a8a;color:#fff;padding:10px 22px;text-decoration:none;border-radius:20px;font-weight:bold;'>Open " . e(APP_NAME) . " Portal</a></p>";
        $messages[(int)$rc['user_id']] = ['email' => $rc['email'], 'name' => $rc['full_name'], 'subject' => APP_NAME . ' - ' . $title, 'html' => email_template($title, $inner)];
    }
    return send_mail_bulk($messages, REMINDER_EMAIL_BATCH);
}

/** Recipients ka snapshot save (status 'None'); email baad mein reminder_deliver() se */
function reminder_save_recipients($reminderId, array $recipients)
{
    foreach ($recipients as $rc) {
        db_exec("INSERT IGNORE INTO reminder_recipients (reminder_id, user_id, email) VALUES (?, ?, ?)", [(int)$reminderId, (int)$rc['user_id'], $rc['email']]);
    }
}

/**
 * In recipients ko email bhejo aur har ek ka status likho (Sent / Failed + error). announcements ke counters bhi taza.
 * Pehli dafa aur "Retry failed" dono yahi use karte hain. Returns ['sent' => n, 'failed' => m].
 */
function reminder_deliver($reminderId, array $r, array $recipients)
{
    $res = send_reminder_emails($r, $recipients);
    foreach ($recipients as $rc) {
        $uid = (int)$rc['user_id'];
        $one = $res['results'][$uid] ?? ['ok' => false, 'error' => 'Not sent'];
        if ($one['ok']) {
            db_exec("UPDATE reminder_recipients SET status = 'Sent', error = NULL, sent_at = NOW(), email = ? WHERE reminder_id = ? AND user_id = ?", [$rc['email'], (int)$reminderId, $uid]);
        } else {
            $why = function_exists('mb_substr') ? mb_substr((string)$one['error'], 0, 250) : substr((string)$one['error'], 0, 250);
            db_exec("UPDATE reminder_recipients SET status = 'Failed', error = ?, email = ? WHERE reminder_id = ? AND user_id = ?", [$why, $rc['email'], (int)$reminderId, $uid]);
        }
    }
    reminder_sync_counters($reminderId);
    return ['sent' => (int)$res['sent'], 'failed' => (int)$res['failed']];
}

/** announcements.emails_sent / emails_failed = reminder_recipients ki ginti */
function reminder_sync_counters($reminderId)
{
    db_exec("UPDATE announcements a SET
                a.emails_sent   = (SELECT COUNT(*) FROM reminder_recipients rr WHERE rr.reminder_id = a.id AND rr.status = 'Sent'),
                a.emails_failed = (SELECT COUNT(*) FROM reminder_recipients rr WHERE rr.reminder_id = a.id AND rr.status = 'Failed')
             WHERE a.id = ?", [(int)$reminderId]);
}

/**
 * Duplicate guard: in recipients mein se kis ko pichhle REMINDER_DUPLICATE_HOURS ghanton mein fee reminder ja chuka hai.
 * Returns [['user_id', 'full_name', 'email', 'last_at', 'last_title'], ...]
 */
function reminder_recent_duplicates(array $recipients)
{
    $ids = array_map(function ($rc) { return (int)$rc['user_id']; }, $recipients);
    if (!$ids) return [];
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $since = date('Y-m-d H:i:s', time() - REMINDER_DUPLICATE_HOURS * 3600);
    $last = [];
    $res = db_select("SELECT rr.user_id, rr.created_at, a.title FROM reminder_recipients rr JOIN announcements a ON a.id = rr.reminder_id
                      WHERE a.type = 'Fee' AND rr.created_at >= ? AND rr.user_id IN ($marks) ORDER BY rr.created_at, rr.id", array_merge([$since], $ids));
    while ($x = $res->fetch_assoc()) { $last[(int)$x['user_id']] = $x; }   // aakhri wala reh jata hai
    $out = [];
    foreach ($recipients as $rc) {
        $uid = (int)$rc['user_id'];
        if (isset($last[$uid])) {
            $out[] = ['user_id' => $uid, 'full_name' => $rc['full_name'], 'email' => $rc['email'], 'last_at' => $last[$uid]['created_at'], 'last_title' => $last[$uid]['title']];
        }
    }
    return $out;
}

/** User ne yeh reminders apne portal par dekh liye: seen_at sirf pehli dafa set hota hai */
function mark_reminders_seen($userId, array $reminderIds)
{
    $ids = array_values(array_filter(array_map('intval', $reminderIds)));
    if (!$userId || !$ids) return;
    $marks = implode(',', array_fill(0, count($ids), '?'));
    db_exec("UPDATE reminder_recipients SET seen_at = NOW() WHERE user_id = ? AND seen_at IS NULL AND reminder_id IN ($marks)", array_merge([(int)$userId], $ids));
}

/** History table ke liye: yeh reminder kis ke liye hai (join wale columns: program_name, student_name, student_reg) */
function reminder_audience_label(array $r)
{
    if ($r['type'] !== 'Fee') {
        return $r['audience'] === 'All' ? 'Everyone' : $r['audience'];
    }
    if ($r['fee_scope'] === 'Student') {
        return ($r['student_name'] ?? 'One student') . (!empty($r['student_reg']) ? ' (' . $r['student_reg'] . ')' : '');
    }
    if ($r['fee_scope'] === 'Filter') {
        $parts = [];
        if (!empty($r['target_program_id'])) $parts[] = $r['program_name'] ?? 'Program';
        if (!empty($r['target_semester']))   $parts[] = 'Semester ' . (int)$r['target_semester'];
        return 'Students with dues: ' . ($parts ? implode(', ', $parts) : 'all');
    }
    return 'All students with dues';
}

/** 'Scheduled' (abhi shuru nahi), 'Active' ya 'Expired' */
function reminder_status(array $a)
{
    $today = date('Y-m-d');
    if ($a['start_date'] > $today) return 'Scheduled';
    if ($a['end_date'] !== null && $a['end_date'] < $today) return 'Expired';
    return 'Active';
}

/**
 * Dashboard / landing page ke reminders (text mein placeholders badle hue; output par e() karein).
 * $user null = landing page: sirf General + audience 'All'.
 * Student ko apne fee reminders bhi (sirf jab us ka balance > 0 ho) - doosre student ke kabhi nahi.
 */
function dashboard_reminders($user = null, $limit = 6)
{
    $role = $user['role'] ?? '';
    $audiences = ['Student' => ['All', 'Students'], 'Staff' => ['All', 'Staff'], 'Accountant' => ['All', 'Accountants'], 'Admin' => ['All', 'Accountants']][$role] ?? ['All'];
    $today = date('Y-m-d');
    $marks = implode(',', array_fill(0, count($audiences), '?'));
    $sql = "SELECT id, type, title, body, start_date, due_date, is_pinned FROM announcements
            WHERE start_date <= ? AND (end_date IS NULL OR end_date >= ?) AND ((type = 'General' AND audience IN ($marks))";
    $params = array_merge([$today, $today], $audiences);

    $student = null;
    if ($role === 'Student') {
        $student = students_with_dues(['student_id' => (int)db_value("SELECT id FROM students WHERE user_id = ?", [(int)$user['id']], 0)])[0] ?? null;
    }
    if ($student) {
        $sql .= " OR (type = 'Fee' AND (fee_scope = 'All'
                     OR (fee_scope = 'Filter' AND (target_program_id IS NULL OR target_program_id = ?) AND (target_semester IS NULL OR target_semester = ?))
                     OR (fee_scope = 'Student' AND target_student_id = ?)))";
        array_push($params, (int)$student['program_id'], (int)$student['semester'], (int)$student['student_id']);
    }
    $rows = db_select($sql . ") ORDER BY is_pinned DESC, start_date DESC, id DESC LIMIT " . (int)$limit, $params)->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as $i => $r) {
        if ($r['type'] === 'Fee') {
            $vars = reminder_vars($student, $r['due_date']);
            $rows[$i]['title'] = reminder_render($r['title'], $vars);
            $rows[$i]['body'] = reminder_render($r['body'], $vars);
            $rows[$i]['balance'] = $vars['{balance}'];
        }
    }
    return $rows;
}
