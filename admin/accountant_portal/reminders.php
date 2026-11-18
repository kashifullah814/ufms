<?php
/**
 * Reminders: fee reminders (students jin ki dues baqi hain) + general notices. Table: announcements.
 * - Fee Reminder: sab dues wale students / program aur semester ka filter / ek student; message mein
 *   {name} {balance} {due_date} {reg_no}. Dashboard par (aur chahein to email se) jata hai.
 * - General Notice: All / Students / Staff / Accountants (sirf Admin bhej sakta hai).
 * Permission: fees - sirf Admin ya fees permission wala Accountant bhej sakta hai (Accountant sirf fee reminders; Admin sab kuch).
 * Sending sirf MANUAL: Send Reminder / Retry failed buttons. Koi cron ya automatic email nahi.
 * Delivery report (?report=ID): N sent, M failed (addresses + wajah), "Retry failed"; "Seen by X of N" (table reminder_recipients).
 * Duplicate guard: jis student ko pichhle 24 ghante mein fee reminder ja chuka ho, pehle list + confirmation.
 * Fee Collection page se pre-fill: ?dues=all ya ?student=ID
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
$user = require_permission('fees');
$is_admin = $user['role'] === 'Admin';
$today = date('Y-m-d');
$programs = all_programs();
$program_names = [];
foreach ($programs as $p) { $program_names[(int)$p['id']] = $p['program_name']; }

/** Naya reminder: form validate. Error ho to string; warna $d bhar deta hai. */
function read_reminder_form(&$d, $is_admin, array $program_names, $today)
{
    $len = function ($s) { return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s); };
    $d = [
        'type'     => (string)($_POST['type'] ?? ''),
        'title'    => trim((string)($_POST['title'] ?? '')),
        'body'     => trim((string)($_POST['body'] ?? '')),
        'audience' => (string)($_POST['audience'] ?? ''),
        'fee_scope' => null, 'target_program_id' => null, 'target_semester' => null, 'target_student_id' => null,
        'due_date' => trim((string)($_POST['due_date'] ?? '')),
        'end_date' => trim((string)($_POST['end_date'] ?? '')),
        'is_pinned' => isset($_POST['is_pinned']) ? 1 : 0,
        'email'    => isset($_POST['send_email']) ? 1 : 0,
    ];
    if (!in_array($d['type'], REMINDER_TYPES, true)) return 'Please choose the reminder type.';
    if ($d['type'] === 'General' && !$is_admin) return 'Only the Admin can send general notices. You can send fee reminders.';
    if ($d['title'] === '' || $len($d['title']) > 150) return 'Please enter a title (up to 150 characters).';
    if ($d['body'] === '' || $len($d['body']) > 2000) return 'Please enter the message (up to 2000 characters).';
    if ($d['end_date'] !== '' && (!valid_date($d['end_date']) || $d['end_date'] < $today)) return 'The "show until" date must be today or later, or empty.';
    $d['end_date'] = $d['end_date'] === '' ? null : $d['end_date'];

    if ($d['type'] === 'General') {
        if (!in_array($d['audience'], REMINDER_AUDIENCES, true)) return 'Please choose who should see the notice.';
        $d['due_date'] = null;
        return null;
    }
    // Fee reminder
    $d['audience'] = 'Students';
    $scope = (string)($_POST['fee_scope'] ?? '');
    if (!in_array($scope, REMINDER_FEE_SCOPES, true)) return 'Please choose which students get the fee reminder.';
    $d['fee_scope'] = $scope;
    if ($scope === 'Filter') {
        $prog = (int)($_POST['target_program_id'] ?? 0);
        $sem  = (int)($_POST['target_semester'] ?? 0);
        if ($prog && !isset($program_names[$prog])) return 'Please choose a valid program.';
        if ($sem < 0 || $sem > MAX_SEMESTERS) return 'Please choose a valid semester.';
        if (!$prog && !$sem) return 'Choose a program and/or a semester, or select "All students with pending dues".';
        $d['target_program_id'] = $prog ?: null;
        $d['target_semester'] = $sem ?: null;
    } elseif ($scope === 'Student') {
        $sid = (int)($_POST['target_student_id'] ?? 0);
        if (!$sid || !db_value("SELECT COUNT(*) FROM students WHERE id = ?", [$sid], 0)) return 'Please choose a student.';
        $d['target_student_id'] = $sid;
    }
    if (!valid_date($d['due_date'])) return 'Please enter the due date for the fee reminder.';
    if ($d['due_date'] < $today) return 'The due date must be today or later.';
    return null;
}

/** Accountant sirf fee reminders badal / mita sakta hai */
function can_manage_reminder(array $row, $is_admin)
{
    return $is_admin || $row['type'] === 'Fee';
}

// ---------- Send (create) ----------
if (isset($_POST['send_reminder'])) {
    $err = read_reminder_form($d, $is_admin, $program_names, $today);
    $recipients = [];
    if (!$err) {
        $recipients = reminder_recipients($d);
        if ($d['type'] === 'Fee' && !$recipients) {
            $err = 'No student with pending dues matches this selection, so there is nobody to remind.';
        }
    }
    if ($err) {
        flash('error', $err);
        redirect_self();
    }
    // Duplicate guard: pichhle 24 ghanton mein jin ko fee reminder ja chuka hai - pehle list dikha kar confirmation
    $confirm = null;
    if ($d['type'] === 'Fee' && ($_POST['confirm_duplicates'] ?? '') !== '1') {
        $dups = reminder_recent_duplicates($recipients);
        if ($dups) {
            $confirm = ['dups' => $dups, 'total' => count($recipients), 'fields' => []];
            foreach (['type', 'title', 'body', 'audience', 'fee_scope', 'target_program_id', 'target_semester', 'target_student_id', 'due_date', 'end_date', 'is_pinned', 'send_email'] as $k) {
                if (isset($_POST[$k])) $confirm['fields'][$k] = (string)$_POST[$k];
            }
        }
    }
    if (!$confirm) {
        $db = db();
        $db->begin_transaction();
        try {
            db_exec("INSERT INTO announcements (type, title, body, audience, fee_scope, target_program_id, target_semester, target_student_id,
                                                start_date, end_date, due_date, is_pinned, email_requested, recipients_count, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$d['type'], $d['title'], $d['body'], $d['audience'], $d['fee_scope'], $d['target_program_id'], $d['target_semester'], $d['target_student_id'],
                 $today, $d['end_date'], $d['due_date'], $d['is_pinned'], $d['email'], count($recipients), (int)$user['id']]);
            $id = db_insert_id();
            reminder_save_recipients($id, $recipients);
            $db->commit();
        } catch (Throwable $ex) {
            $db->rollback();
            throw $ex;
        }

        $who = $d['type'] === 'Fee' ? 'student' : 'recipient';
        $n = count($recipients);
        $sent = $failed = 0;
        if ($d['email']) {
            // Email transaction ke bahar (der lagti hai); har recipient ka status reminder_recipients mein
            $res = reminder_deliver($id, $d, $recipients);
            $sent = $res['sent'];
            $failed = $res['failed'];
        }
        $label = reminder_audience_label($d + ['program_name' => $program_names[(int)$d['target_program_id']] ?? null,
            'student_name' => $d['target_student_id'] ? db_value("SELECT u.full_name FROM students s JOIN users u ON u.id = s.user_id WHERE s.id = ?", [$d['target_student_id']]) : null]);
        audit('REMINDER_SENT', "\"{$d['title']}\" ({$d['type']}; {$label}): {$n} {$who}" . ($n === 1 ? '' : 's')
            . ($d['email'] ? ", emails {$sent} sent / {$failed} failed" : ', dashboard only'));
        $msg = "Reminder created for {$n} {$who}" . ($n === 1 ? '' : 's') . ' (shown on their dashboard).';
        if ($d['email']) $msg .= " Email: {$sent} sent, {$failed} failed.";
        if ($d['email'] && !mail_configured()) $msg .= ' Email is not set up (MAIL_USERNAME / MAIL_PASSWORD in includes/config.php).';
        flash($failed > 0 ? 'warning' : 'success', $msg);
        redirect(url('admin/accountant_portal/reminders.php?report=' . $id));
    }
}

// ---------- Retry failed emails (manual) ----------
if (isset($_POST['retry_failed'])) {
    $rid = (int)$_POST['retry_failed'];
    $row = db_row("SELECT * FROM announcements WHERE id = ?", [$rid]);
    if (!$row) {
        flash('error', 'Reminder not found.');
        redirect(url('admin/accountant_portal/reminders.php'));
    }
    if (!can_manage_reminder($row, $is_admin)) {
        flash('error', 'Only the Admin can resend general notices.');
        redirect(url('admin/accountant_portal/reminders.php?report=' . $rid));
    }
    // Abhi ka email address (ho sakta hai ghalat address theek kiya gaya ho); sirf Active accounts
    $failed_rows = db_select("SELECT rr.user_id, u.full_name, u.email FROM reminder_recipients rr JOIN users u ON u.id = rr.user_id
                              WHERE rr.reminder_id = ? AND rr.status = 'Failed' AND u.status = 'Active' ORDER BY u.full_name, u.id", [$rid])->fetch_all(MYSQLI_ASSOC);
    $dues_by_user = [];
    if ($row['type'] === 'Fee') {
        foreach (students_with_dues() as $s) { $dues_by_user[(int)$s['user_id']] = $s; }
    }
    $again = [];
    $paid = 0;
    foreach ($failed_rows as $f) {
        $uid = (int)$f['user_id'];
        if ($row['type'] === 'Fee') {
            if (!isset($dues_by_user[$uid])) {   // is dauran fee de di: ab reminder ki zaroorat nahi
                db_exec("UPDATE reminder_recipients SET status = 'None', error = 'Balance paid before retry' WHERE reminder_id = ? AND user_id = ?", [$rid, $uid]);
                $paid++;
                continue;
            }
            $again[] = ['user_id' => $uid, 'full_name' => $f['full_name'], 'email' => $f['email'], 'vars' => reminder_vars($dues_by_user[$uid], $row['due_date'])];
        } else {
            $again[] = ['user_id' => $uid, 'full_name' => $f['full_name'], 'email' => $f['email'], 'vars' => []];
        }
    }
    if (!$again) {
        reminder_sync_counters($rid);
        flash('info', $paid ? "Nothing to retry: {$paid} student(s) have paid in the meantime." : 'There are no failed emails to retry.');
    } else {
        $res = reminder_deliver($rid, $row, $again);
        audit('REMINDER_RETRY', "\"{$row['title']}\" ({$row['type']}): emails {$res['sent']} sent / {$res['failed']} failed" . ($paid ? ", {$paid} skipped (paid)" : ''));
        flash($res['failed'] > 0 ? 'warning' : 'success', "Retry finished: {$res['sent']} sent, {$res['failed']} failed." . ($paid ? " {$paid} skipped (already paid)." : '')
            . (!mail_configured() ? ' Email is not set up (MAIL_USERNAME / MAIL_PASSWORD in includes/config.php).' : ''));
    }
    redirect(url('admin/accountant_portal/reminders.php?report=' . $rid));
}

// ---------- Edit ----------
if (isset($_POST['update_reminder'])) {
    $row = db_row("SELECT * FROM announcements WHERE id = ?", [(int)($_POST['reminder_id'] ?? 0)]);
    $len = function ($s) { return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s); };
    $title = trim((string)($_POST['title'] ?? ''));
    $body = trim((string)($_POST['body'] ?? ''));
    $end = trim((string)($_POST['end_date'] ?? ''));
    $due = trim((string)($_POST['due_date'] ?? ''));
    $aud = (string)($_POST['audience'] ?? '');
    if (!$row) $err = 'Reminder not found.';
    elseif (!can_manage_reminder($row, $is_admin)) $err = 'Only the Admin can change general notices.';
    elseif ($title === '' || $len($title) > 150) $err = 'Please enter a title (up to 150 characters).';
    elseif ($body === '' || $len($body) > 2000) $err = 'Please enter the message (up to 2000 characters).';
    elseif ($end !== '' && (!valid_date($end) || $end < $row['start_date'])) $err = 'The "show until" date must be on or after the date it was sent, or empty.';
    elseif ($row['type'] === 'Fee' && !valid_date($due)) $err = 'Please enter the due date for the fee reminder.';
    elseif ($row['type'] === 'General' && !in_array($aud, REMINDER_AUDIENCES, true)) $err = 'Please choose who should see the notice.';
    else $err = null;
    if ($err) {
        flash('error', $err);
    } else {
        db_exec("UPDATE announcements SET title = ?, body = ?, end_date = ?, due_date = ?, audience = ?, is_pinned = ? WHERE id = ?",
            [$title, $body, $end === '' ? null : $end, $row['type'] === 'Fee' ? $due : null, $row['type'] === 'Fee' ? 'Students' : $aud,
             isset($_POST['is_pinned']) ? 1 : 0, (int)$row['id']]);
        audit('REMINDER_UPDATED', "\"{$title}\" ({$row['type']})");
        flash('success', 'Reminder updated.');
    }
    redirect(url('admin/accountant_portal/reminders.php'));
}

// ---------- Delete ----------
if (isset($_POST['delete_id'])) {
    $row = db_row("SELECT id, type, title FROM announcements WHERE id = ?", [(int)$_POST['delete_id']]);
    if (!$row) {
        flash('error', 'Reminder not found.');
    } elseif (!can_manage_reminder($row, $is_admin)) {
        flash('error', 'Only the Admin can delete general notices.');
    } else {
        db_exec("DELETE FROM announcements WHERE id = ?", [(int)$row['id']]);
        audit('REMINDER_DELETED', "\"{$row['title']}\" ({$row['type']})");
        flash('success', 'Reminder deleted.');
    }
    redirect(url('admin/accountant_portal/reminders.php'));
}

// ---------- Page data ----------
$dues_students = students_with_dues();
$dues_total = array_sum(array_column($dues_students, 'balance'));

// Pre-fill (Fee Collection page ke buttons): ?dues=all ya ?student=ID
$pre_student = (int)($_GET['student'] ?? 0);
$pre = ['type' => $is_admin ? 'General' : 'Fee', 'scope' => 'All', 'student' => 0, 'title' => '', 'body' => ''];
$fee_title = 'Fee payment reminder';
$fee_body = "Dear {name},\nPKR {balance} is pending against your registration number {reg_no}. Please pay by {due_date} to avoid a late fine.";
if ($pre_student && in_array($pre_student, array_map('intval', array_column($dues_students, 'student_id')), true)) {
    $pre = ['type' => 'Fee', 'scope' => 'Student', 'student' => $pre_student, 'title' => $fee_title, 'body' => $fee_body];
} elseif (($_GET['dues'] ?? '') === 'all' || $pre_student) {
    $pre = ['type' => 'Fee', 'scope' => 'All', 'student' => 0, 'title' => $fee_title, 'body' => $fee_body];
}
$default_due = date('Y-m-d', strtotime('+7 days'));

// Delivery report (bhejne ke foran baad, ya History ke "Report" button se)
$report = null;
if (!empty($_GET['report'])) {
    $report = db_row("SELECT * FROM announcements WHERE id = ?", [(int)$_GET['report']]);
    if ($report) {
        $report['list'] = db_select("SELECT rr.status, rr.error, rr.email, rr.sent_at, rr.seen_at, u.full_name FROM reminder_recipients rr
                                     JOIN users u ON u.id = rr.user_id WHERE rr.reminder_id = ? ORDER BY FIELD(rr.status, 'Failed', 'Sent', 'None'), u.full_name, u.id",
                                    [(int)$report['id']])->fetch_all(MYSQLI_ASSOC);
        $report['n'] = ['Sent' => 0, 'Failed' => 0, 'None' => 0, 'seen' => 0];
        foreach ($report['list'] as $x) { $report['n'][$x['status']]++; if ($x['seen_at']) $report['n']['seen']++; }
    }
}
// History: har reminder ke recipients ki ginti (total / seen)
$rr_stats = [];
$res = db_select("SELECT reminder_id, COUNT(*) AS total, SUM(seen_at IS NOT NULL) AS seen FROM reminder_recipients GROUP BY reminder_id");
while ($x = $res->fetch_assoc()) { $rr_stats[(int)$x['reminder_id']] = ['total' => (int)$x['total'], 'seen' => (int)$x['seen']]; }

$rows = db_select("SELECT a.*, u.full_name AS author, fs.program_name, su.full_name AS student_name, st.reg_number AS student_reg
                   FROM announcements a
                   LEFT JOIN users u ON u.id = a.created_by
                   LEFT JOIN fee_structure fs ON fs.id = a.target_program_id
                   LEFT JOIN students st ON st.id = a.target_student_id
                   LEFT JOIN users su ON su.id = st.user_id
                   ORDER BY a.id DESC")->fetch_all(MYSQLI_ASSOC);
$status_badge = ['Active' => 'bg-success', 'Scheduled' => 'bg-info text-dark', 'Expired' => 'bg-secondary'];

$page_title = 'Reminders';
$nav_title = 'Reminders';
$nav_icon = 'fa-bell';
$nav_back = 'dashboard';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/topnav.php';
?>
<div class="container pb-5">
    <?php echo flash_render(); ?>
    <div class="row g-4">
        <?php if (!empty($confirm)): ?>
        <!-- Duplicate guard: confirmation -->
        <div class="col-12" id="dupConfirm">
            <div class="card table-card p-4 border border-warning">
                <h6 class="fw-bold mb-2"><i class="fas fa-exclamation-triangle me-2 text-warning"></i>Already reminded in the last <?php echo (int)REMINDER_DUPLICATE_HOURS; ?> hours</h6>
                <p class="small mb-3"><?php echo count($confirm['dups']); ?> of the <?php echo (int)$confirm['total']; ?> student(s) below already received a fee reminder in the last <?php echo (int)REMINDER_DUPLICATE_HOURS; ?> hours. Nothing has been sent yet. Do you want to send this reminder to all <?php echo (int)$confirm['total']; ?> anyway?</p>
                <div class="table-responsive mb-3">
                    <table class="table table-sm align-middle mb-0 small">
                        <thead class="table-light"><tr><th>Student</th><th>Email</th><th>Last reminded</th><th>Reminder</th></tr></thead>
                        <tbody>
                        <?php foreach ($confirm['dups'] as $x): ?>
                            <tr><td class="fw-bold"><?php echo e($x['full_name']); ?></td><td><?php echo e($x['email']); ?></td><td class="text-nowrap"><?php echo e(fmt_datetime($x['last_at'])); ?></td><td><?php echo e($x['last_title']); ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <form action="reminders.php" method="POST" class="d-flex flex-wrap gap-2">
                    <?php echo csrf_field(); ?>
                    <?php foreach ($confirm['fields'] as $k => $v): ?><input type="hidden" name="<?php echo e($k); ?>" value="<?php echo e($v); ?>"><?php endforeach; ?>
                    <input type="hidden" name="confirm_duplicates" value="1">
                    <button type="submit" name="send_reminder" class="btn btn-warning rounded-pill px-4"><i class="fas fa-paper-plane me-1"></i>Send again anyway</button>
                    <a href="reminders.php" class="btn btn-light rounded-pill px-4">Cancel</a>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($report): $rn = $report['n']; $rtotal = count($report['list']); ?>
        <!-- Delivery report -->
        <div class="col-12" id="deliveryReport">
            <div class="card table-card p-4">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                    <div><h6 class="fw-bold mb-1"><i class="fas fa-clipboard-check me-2 text-primary"></i>Delivery report</h6>
                        <div class="small text-muted"><?php echo e($report['title']); ?> &middot; <?php echo e(fmt_date($report['start_date'])); ?></div></div>
                    <a href="reminders.php" class="btn btn-sm btn-light rounded-pill px-3">Close</a>
                </div>
                <?php if (!$rtotal): ?>
                    <p class="small text-muted mb-0">No per-recipient record exists for this reminder (it was sent before delivery tracking was added).</p>
                <?php else: ?>
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <span class="badge bg-secondary fs-6 fw-normal"><?php echo $rtotal; ?> recipient<?php echo $rtotal === 1 ? '' : 's'; ?></span>
                    <?php if ($report['email_requested']): ?>
                        <span class="badge bg-success fs-6 fw-normal"><?php echo $rn['Sent']; ?> sent</span>
                        <span class="badge <?php echo $rn['Failed'] ? 'bg-danger' : 'bg-secondary'; ?> fs-6 fw-normal"><?php echo $rn['Failed']; ?> failed</span>
                    <?php else: ?>
                        <span class="badge bg-light text-dark border fs-6 fw-normal">Dashboard only (no email)</span>
                    <?php endif; ?>
                    <span class="badge bg-info text-dark fs-6 fw-normal">Seen by <?php echo $rn['seen']; ?> of <?php echo $rtotal; ?></span>
                </div>
                <?php if ($rn['Failed']): ?>
                    <div class="table-responsive mb-3">
                        <table class="table table-sm align-middle mb-0 small">
                            <thead class="table-light"><tr><th class="text-nowrap">Failed recipient</th><th class="text-nowrap">Email address</th><th>Reason</th></tr></thead>
                            <tbody>
                            <?php foreach ($report['list'] as $x): if ($x['status'] !== 'Failed') continue; ?>
                                <tr><td class="fw-bold"><?php echo e($x['full_name']); ?></td><td><?php echo e($x['email']); ?></td><td class="text-danger"><?php echo e($x['error'] ?: 'Unknown error'); ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if (can_manage_reminder($report, $is_admin)): ?>
                    <button type="button" class="btn btn-danger rounded-pill px-4" data-confirm-post data-kind="info" data-fa="fa-redo" data-title="Retry failed emails?" data-confirm="Retry failed"
                            data-text="<?php echo e('Send the email again to the ' . $rn['Failed'] . ' failed recipient(s)? Recipients who already got it are not emailed again.'); ?>"
                            data-fields="<?php echo e(json_encode(['retry_failed' => (int)$report['id']])); ?>"><i class="fas fa-redo me-1"></i>Retry failed (<?php echo $rn['Failed']; ?>)</button>
                    <?php endif; ?>
                <?php elseif ($report['email_requested']): ?>
                    <p class="small text-success mb-0"><i class="fas fa-check-circle me-1"></i>Every email was accepted by the mail server.</p>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="col-12">
            <div class="card form-card">
                <div class="card-header text-white" style="background:#1e3a8a;"><h6 class="mb-0 py-1"><i class="fas fa-paper-plane me-2"></i>Send Reminder</h6></div>
                <div class="card-body p-4">
                    <form action="" method="POST" id="reminderForm">
                        <?php echo csrf_field(); ?>
                        <div class="row g-4">
                        <div class="col-lg-5">
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">TYPE <span class="req">*</span></label>
                            <select name="type" id="remType" class="form-select" required>
                                <?php if ($is_admin): ?><option value="General" <?php echo $pre['type'] === 'General' ? 'selected' : ''; ?>>General Notice</option><?php endif; ?>
                                <option value="Fee" <?php echo $pre['type'] === 'Fee' ? 'selected' : ''; ?>>Fee Reminder</option>
                            </select>
                        </div>

                        <!-- General notice -->
                        <div class="mb-3" data-rem-type="General">
                            <label class="form-label fw-semibold small">WHO SEES IT <span class="req">*</span></label>
                            <select name="audience" class="form-select" required>
                                <option value="All">Everyone</option>
                                <option value="Students">Students only</option>
                                <option value="Staff">Staff only</option>
                                <option value="Accountants">Accountants only</option>
                            </select>
                            <div class="form-text">"Everyone" is shown on the home page and on every dashboard.</div>
                        </div>

                        <!-- Fee reminder -->
                        <div data-rem-type="Fee">
                            <div class="alert alert-light border small py-2"><i class="fas fa-users me-1 text-danger"></i><strong><?php echo count($dues_students); ?></strong> student(s) have pending dues right now (PKR&nbsp;<?php echo money($dues_total, 0); ?> in total). Only students with a balance receive a fee reminder; it disappears for a student once the balance is paid.</div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold small">SEND TO <span class="req">*</span></label>
                                <select name="fee_scope" id="remScope" class="form-select" required>
                                    <option value="All" <?php echo $pre['scope'] === 'All' ? 'selected' : ''; ?>>All students with pending dues</option>
                                    <option value="Filter">By program and/or semester</option>
                                    <option value="Student" <?php echo $pre['scope'] === 'Student' ? 'selected' : ''; ?>>One student</option>
                                </select>
                            </div>
                            <div class="row g-2 mb-3" data-rem-scope="Filter">
                                <div class="col-12"><label class="form-label fw-semibold small">PROGRAM</label>
                                    <select name="target_program_id" class="form-select"><option value="0">Any program</option>
                                        <?php foreach ($programs as $p): ?><option value="<?php echo (int)$p['id']; ?>"><?php echo e($p['program_name']); ?></option><?php endforeach; ?>
                                    </select></div>
                                <div class="col-12"><label class="form-label fw-semibold small">SEMESTER</label>
                                    <select name="target_semester" class="form-select"><option value="0">Any semester</option>
                                        <?php for ($i = 1; $i <= MAX_SEMESTERS; $i++): ?><option value="<?php echo $i; ?>">Semester <?php echo $i; ?></option><?php endfor; ?>
                                    </select></div>
                            </div>
                            <div class="mb-3" data-rem-scope="Student">
                                <label class="form-label fw-semibold small">STUDENT <span class="req">*</span></label>
                                <select name="target_student_id" class="form-select"><option value="">Select a student with dues...</option>
                                    <?php foreach ($dues_students as $s): ?>
                                        <option value="<?php echo (int)$s['student_id']; ?>" <?php echo (int)$s['student_id'] === $pre['student'] ? 'selected' : ''; ?>><?php echo e($s['full_name'] . ' (' . $s['reg_number'] . ')'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold small">DUE DATE <span class="req">*</span></label>
                                <input type="date" name="due_date" class="form-control" value="<?php echo e($default_due); ?>" min="<?php echo e($today); ?>">
                            </div>
                        </div>
                        </div>
                        <div class="col-lg-7">

                        <div class="mb-3"><label class="form-label fw-semibold small">TITLE <span class="req">*</span></label><input type="text" name="title" id="remTitle" class="form-control" maxlength="150" value="<?php echo e($pre['title']); ?>" required></div>
                        <div class="mb-2"><label class="form-label fw-semibold small">MESSAGE <span class="req">*</span></label><textarea name="body" id="remBody" class="form-control" rows="5" maxlength="2000" required><?php echo e($pre['body']); ?></textarea></div>
                        <p class="small text-muted" data-rem-type="Fee">Placeholders: <code>{name}</code> <code>{balance}</code> <code>{due_date}</code> <code>{reg_no}</code> &ndash; each student sees their own values, e.g. "Dear Ali, PKR 22,000 is pending. Please pay by 15 Oct 2026."
                            <a href="#" id="remUseTemplate" class="ms-1">Use the standard text</a></p>
                        <div class="mb-3"><label class="form-label fw-semibold small">SHOW UNTIL</label><input type="date" name="end_date" class="form-control" min="<?php echo e($today); ?>"><div class="form-text">Leave empty to show it until it is deleted<span data-rem-type="Fee"> (or until the student has paid)</span>.</div></div>
                        <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="is_pinned" value="1" id="remPin"><label class="form-check-label small" for="remPin">Pin to the top</label></div>
                        <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="send_email" value="1" id="remEmail" <?php echo $pre['type'] === 'Fee' ? 'checked' : ''; ?>><label class="form-check-label small" for="remEmail">Also send by email (one email per recipient)</label>
                            <?php if (!mail_configured()): ?><div class="form-text text-danger">Email is not set up, so only the dashboard reminder will be created.</div><?php endif; ?></div>
                        <button type="submit" name="send_reminder" class="btn btn-primary rounded-pill px-5"><i class="fas fa-paper-plane me-1"></i>Send Reminder</button>
                        </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card table-card p-3">
                <h6 class="fw-bold mb-3"><i class="fas fa-history me-2 text-primary"></i>History</h6>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 small sticky-first">
                        <thead class="table-light"><tr><th style="min-width:210px">Title</th><th>Type</th><th>Audience</th><th class="text-end">Recipients</th><th class="text-end">Emails sent</th><th>Seen</th><th>Date</th><th>By</th><th class="text-center">Actions</th></tr></thead>
                        <tbody>
                        <?php if (!$rows): ?>
                            <tr><td colspan="9" class="text-center text-muted py-4">No reminders sent yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($rows as $row): $rid = (int)$row['id']; $st = reminder_status($row); $fee = $row['type'] === 'Fee'; $mine = can_manage_reminder($row, $is_admin); ?>
                            <tr>
                                <td class="cell-wrap"><div class="fw-bold"><?php if ($row['is_pinned']): ?><i class="fas fa-thumbtack text-warning me-1" title="Pinned"></i><?php endif; ?><?php echo e($row['title']); ?></div>
                                    <div class="text-muted"><?php echo nl2br(e($row['body'])); ?></div>
                                    <?php if ($fee): ?><div class="text-danger">Due <?php echo e(fmt_date($row['due_date'])); ?></div><?php endif; ?></td>
                                <td><span class="badge <?php echo $fee ? 'bg-danger' : 'bg-primary'; ?>"><?php echo $fee ? 'Fee Reminder' : 'General Notice'; ?></span></td>
                                <td class="cell-wrap" style="min-width:7rem"><?php echo e(reminder_audience_label($row)); ?></td>
                                <td class="text-end fw-bold"><?php echo $row['recipients_count'] === null ? '&ndash;' : (int)$row['recipients_count']; ?></td>
                                <td class="text-end text-nowrap"><?php if (!$row['email_requested']): ?><span class="text-muted">&ndash;</span><?php else: ?><?php echo (int)$row['emails_sent']; ?><?php if ((int)$row['emails_failed'] > 0): ?> <span class="text-danger">(<?php echo (int)$row['emails_failed']; ?> failed)</span><?php endif; ?><?php endif; ?></td>
                                <td class="text-nowrap"><?php if (isset($rr_stats[$rid])): ?>Seen by <?php echo $rr_stats[$rid]['seen']; ?> of <?php echo $rr_stats[$rid]['total']; ?><?php else: ?><span class="text-muted">&ndash;</span><?php endif; ?></td>
                                <td class="text-nowrap"><?php echo e(fmt_date($row['start_date'])); ?><br><span class="badge <?php echo $status_badge[$st]; ?>"><?php echo e($st); ?></span><?php if ($row['end_date']): ?><div class="text-muted">until <?php echo e(fmt_date($row['end_date'])); ?></div><?php endif; ?></td>
                                <td><?php echo e($row['author'] ?: '-'); ?></td>
                                <td class="text-center text-nowrap">
                                    <?php if (isset($rr_stats[$rid])): ?><a href="reminders.php?report=<?php echo $rid; ?>#deliveryReport" class="btn btn-sm btn-outline-secondary rounded-circle me-1" title="Delivery report"><i class="fas fa-clipboard-check"></i></a><?php endif; ?>
                                    <?php if ($mine): ?>
                                    <button type="button" class="btn btn-sm btn-outline-primary rounded-circle me-1" data-bs-toggle="modal" data-bs-target="#editRem<?php echo $rid; ?>" title="Edit"><i class="fas fa-edit"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-danger rounded-circle" title="Delete" data-confirm-post data-title="Delete reminder?" data-confirm="Delete"
                                            data-text="<?php echo e('Delete "' . $row['title'] . '"? It will disappear from every dashboard.'); ?>"
                                            data-fields="<?php echo e(json_encode(['delete_id' => $rid])); ?>"><i class="fas fa-trash"></i></button>

                                    <div class="modal fade text-start" id="editRem<?php echo $rid; ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog modal-dialog-centered"><div class="modal-content rounded-4 border-0 shadow">
                                            <div class="modal-header border-0"><h5 class="modal-title fw-bold">Edit <?php echo $fee ? 'Fee Reminder' : 'General Notice'; ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                                            <form action="" method="POST">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="reminder_id" value="<?php echo $rid; ?>">
                                                <div class="modal-body">
                                                    <p class="small text-muted mb-3"><i class="fas fa-users me-1"></i><?php echo e(reminder_audience_label($row)); ?><?php echo $fee ? '' : ' (change below)'; ?>. Editing does not send emails again.</p>
                                                    <div class="mb-3"><label class="form-label fw-semibold">Title <span class="req">*</span></label><input type="text" name="title" class="form-control" maxlength="150" value="<?php echo e($row['title']); ?>" required></div>
                                                    <div class="mb-3"><label class="form-label fw-semibold">Message <span class="req">*</span></label><textarea name="body" class="form-control" rows="4" maxlength="2000" required><?php echo e($row['body']); ?></textarea></div>
                                                    <?php if ($fee): ?>
                                                        <div class="mb-3"><label class="form-label fw-semibold">Due date <span class="req">*</span></label><input type="date" name="due_date" class="form-control" value="<?php echo e((string)$row['due_date']); ?>" required></div>
                                                    <?php else: ?>
                                                        <div class="mb-3"><label class="form-label fw-semibold">Who sees it <span class="req">*</span></label><select name="audience" class="form-select" required>
                                                            <?php foreach (['All' => 'Everyone', 'Students' => 'Students only', 'Staff' => 'Staff only', 'Accountants' => 'Accountants only'] as $k => $lbl): ?><option value="<?php echo e($k); ?>" <?php echo $row['audience'] === $k ? 'selected' : ''; ?>><?php echo e($lbl); ?></option><?php endforeach; ?></select></div>
                                                    <?php endif; ?>
                                                    <div class="mb-3"><label class="form-label fw-semibold">Show until</label><input type="date" name="end_date" class="form-control" value="<?php echo e((string)$row['end_date']); ?>"></div>
                                                    <div class="form-check"><input class="form-check-input" type="checkbox" name="is_pinned" value="1" id="pinRem<?php echo $rid; ?>" <?php echo $row['is_pinned'] ? 'checked' : ''; ?>><label class="form-check-label" for="pinRem<?php echo $rid; ?>">Pin to the top</label></div>
                                                </div>
                                                <div class="modal-footer border-0"><button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button><button type="submit" name="update_reminder" class="btn btn-primary rounded-pill px-4">Save Changes</button></div>
                                            </form>
                                        </div></div>
                                    </div>
                                    <?php else: ?><span class="text-muted" title="Only the Admin can change general notices"><i class="fas fa-lock"></i></span><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
// Type / scope ke hisaab se form ke hisse dikhana; chhupe hue fields required nahi rehte
(function () {
    var type = document.getElementById('remType'), scope = document.getElementById('remScope'), form = document.getElementById('reminderForm');
    var TEMPLATE = <?php echo json_encode(['title' => $fee_title, 'body' => $fee_body]); ?>;
    function sync() {
        var t = type.value, s = scope.value;
        form.querySelectorAll('[data-rem-type]').forEach(function (el) { el.hidden = el.getAttribute('data-rem-type') !== t; });
        form.querySelectorAll('[data-rem-scope]').forEach(function (el) { el.hidden = t !== 'Fee' || el.getAttribute('data-rem-scope') !== s; });
        form.querySelector('[name=due_date]').required = t === 'Fee';
        form.querySelector('[name=target_student_id]').required = t === 'Fee' && s === 'Student';
    }
    // "Also send by email": Fee Reminder ke liye pehle se ticked (user hata sakta hai); General ke liye nahi
    var email = document.getElementById('remEmail'), emailTouched = false;
    email.addEventListener('change', function () { emailTouched = true; });
    type.addEventListener('change', function () {
        sync();
        if (!emailTouched) email.checked = type.value === 'Fee';
        var title = document.getElementById('remTitle'), body = document.getElementById('remBody');
        if (type.value === 'Fee' && title.value === '' && body.value === '') { title.value = TEMPLATE.title; body.value = TEMPLATE.body; }
    });
    scope.addEventListener('change', sync);
    document.getElementById('remUseTemplate').addEventListener('click', function (ev) {
        ev.preventDefault(); document.getElementById('remTitle').value = TEMPLATE.title; document.getElementById('remBody').value = TEMPLATE.body;
    });
    sync();
})();
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
