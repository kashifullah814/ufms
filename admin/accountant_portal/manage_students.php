<?php
/** Student records: register (credentials email), edit, delete, search/filter, pagination. Permission: fees */
require_once __DIR__ . '/../../includes/bootstrap.php';
$user = require_permission('fees');
$programs = all_programs();
$program_ids = array_map('intval', array_column($programs, 'id'));

/** Add/edit form ko validate karta hai. Error ho to string return. */
function read_student_form(array $program_ids, &$d)
{
    $d = [
        'full_name'  => trim((string)($_POST['full_name'] ?? '')),
        'email'      => trim((string)($_POST['email'] ?? '')),
        'reg_number' => strtoupper(trim((string)($_POST['reg_number'] ?? ''))),
        'program_id' => (int)($_POST['program_id'] ?? 0),
        'semester'   => (int)($_POST['semester'] ?? 0),
        'scholarship'=> parse_amount($_POST['scholarship_discount'] ?? '0'),
    ];
    if ($d['full_name'] === '' || strlen($d['full_name']) > 100) return 'Please enter a valid full name.';
    if (!valid_email($d['email'])) return 'Please enter a valid email address.';
    if (!preg_match('/^[A-Z0-9\-\/]{4,50}$/', $d['reg_number'])) return 'Registration number may only contain letters, numbers, "-" and "/".';
    if (!in_array($d['program_id'], $program_ids, true)) return 'Please select a valid program.';
    if ($d['semester'] < 1 || $d['semester'] > MAX_SEMESTERS) return 'Please select a valid semester.';
    if ($d['scholarship'] === null) return 'Scholarship/discount must be a valid non-negative amount.';
    return null;
}

// ---------- ADD STUDENT ----------
if (isset($_POST['save_student'])) {
    $err = read_student_form($program_ids, $d);
    if (!$err && db_value("SELECT id FROM users WHERE email = ?", [$d['email']])) $err = 'Email already registered!';
    if (!$err && db_value("SELECT id FROM students WHERE reg_number = ?", [$d['reg_number']])) $err = 'Registration Number already exists!';

    if ($err) {
        flash('error', $err);
        redirect_self();
    }

    $temp = generate_temp_password();
    db()->begin_transaction();
    try {
        db_exec("INSERT INTO users (full_name, email, password, role, status, must_change_password) VALUES (?, ?, ?, 'Student', 'Active', 1)",
            [$d['full_name'], $d['email'], password_hash($temp, PASSWORD_BCRYPT)]);
        $uid = db_insert_id();
        db_exec("INSERT INTO students (user_id, reg_number, program_id, semester, scholarship_discount) VALUES (?, ?, ?, ?, ?)",
            [$uid, $d['reg_number'], $d['program_id'], $d['semester'], $d['scholarship']]);
        db()->commit();
    } catch (mysqli_sql_exception $ex) {
        db()->rollback();
        if (!is_duplicate_error($ex)) throw $ex;
        flash('error', 'Email or Registration Number already exists.');
        redirect_self();
    }
    audit('STUDENT_CREATED', "Registered student {$d['full_name']} ({$d['reg_number']})");

    $prog = db_value("SELECT program_name FROM fee_structure WHERE id = ?", [$d['program_id']]);
    if (send_credentials_email($d['email'], $d['full_name'], 'student', $temp, ['Registration No' => $d['reg_number'], 'Program' => $prog], $mailErr)) {
        flash('success', 'Student registered and login credentials emailed successfully!');
    } else {
        flash('warning', "Student registered, but the email could not be sent ({$mailErr}). Please give these login details to the student personally - Email: {$d['email']} | Temporary Password: {$temp}");
    }
    redirect_self();
}

// ---------- UPDATE STUDENT ----------
if (isset($_POST['update_student'])) {
    $sid = (int)($_POST['student_id'] ?? 0);
    $existing = db_row("SELECT id, user_id FROM students WHERE id = ?", [$sid]);
    $err = $existing ? read_student_form($program_ids, $d) : 'Student not found.';
    $status = in_array($_POST['status'] ?? '', ['Active', 'Inactive'], true) ? $_POST['status'] : 'Active';

    if (!$err && db_value("SELECT id FROM users WHERE email = ? AND id <> ?", [$d['email'], (int)$existing['user_id']])) $err = 'This email is used by another account.';
    if (!$err && db_value("SELECT id FROM students WHERE reg_number = ? AND id <> ?", [$d['reg_number'], $sid])) $err = 'This registration number belongs to another student.';

    if ($err) {
        flash('error', $err);
    } else {
        db()->begin_transaction();
        try {
            db_exec("UPDATE users SET full_name = ?, email = ?, status = ? WHERE id = ? AND role = 'Student'", [$d['full_name'], $d['email'], $status, (int)$existing['user_id']]);
            db_exec("UPDATE students SET reg_number = ?, program_id = ?, semester = ?, scholarship_discount = ? WHERE id = ?",
                [$d['reg_number'], $d['program_id'], $d['semester'], $d['scholarship'], $sid]);
            db()->commit();
            audit('STUDENT_UPDATED', "Updated student {$d['full_name']} ({$d['reg_number']})");
            flash('success', 'Student record updated successfully!');
        } catch (mysqli_sql_exception $ex) {
            db()->rollback();
            if (!is_duplicate_error($ex)) throw $ex;
            flash('error', 'Email or Registration Number already exists.');
        }
    }
    redirect_self();
}

// ---------- DELETE STUDENT ----------
if (isset($_POST['del_id'])) {
    $sid = (int)$_POST['del_id'];
    $st = db_row("SELECT s.user_id, s.reg_number, u.full_name FROM students s JOIN users u ON u.id = s.user_id WHERE s.id = ?", [$sid]);
    $fee_count = (int)db_value("SELECT COUNT(*) FROM fees_records WHERE student_id = ?", [$sid], 0)
               + (int)db_value("SELECT COUNT(*) FROM other_receipts WHERE student_id = ?", [$sid], 0);
    if (!$st) {
        flash('error', 'Student not found.');
    } elseif ($fee_count > 0) {
        flash('error', "Cannot delete {$st['full_name']}: the student has {$fee_count} fee / charge record(s). Financial history must be kept - set the student's status to Inactive instead.");
    } else {
        db_exec("DELETE FROM users WHERE id = ? AND role = 'Student'", [(int)$st['user_id']]); // students row cascade se delete
        audit('STUDENT_DELETED', "Deleted student {$st['full_name']} ({$st['reg_number']})");
        flash('success', 'Student and login account deleted successfully!');
    }
    redirect_self();
}

// ---------- SEARCH, FILTER, PAGINATION ----------
$search_term = trim((string)($_GET['search_input'] ?? ''));
$prog_filter = (int)($_GET['program'] ?? 0);
$sem_filter  = (int)($_GET['semester'] ?? 0);
$where = [];
$params = [];
if ($search_term !== '') {
    $where[] = "(s.reg_number LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)";
    array_push($params, "%{$search_term}%", "%{$search_term}%", "%{$search_term}%");
}
if ($prog_filter > 0) { $where[] = "s.program_id = ?"; $params[] = $prog_filter; }
if ($sem_filter > 0)  { $where[] = "s.semester = ?";   $params[] = $sem_filter; }
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$limit = 10;
$total_rows = (int)db_value("SELECT COUNT(*) FROM students s JOIN users u ON s.user_id = u.id $where_sql", $params, 0);
$total_pages = max(1, (int)ceil($total_rows / $limit));
$page = min(max(1, (int)($_GET['page'] ?? 1)), $total_pages);
$offset = ($page - 1) * $limit;
$result = db_select(
    "SELECT s.*, u.full_name, u.email, u.status, fs.program_name
     FROM students s JOIN users u ON s.user_id = u.id LEFT JOIN fee_structure fs ON fs.id = s.program_id
     $where_sql ORDER BY s.id DESC LIMIT $limit OFFSET $offset", $params);
$qs = function ($p) use ($search_term, $prog_filter, $sem_filter) {
    return '?' . http_build_query(['page' => $p, 'search_input' => $search_term, 'program' => $prog_filter, 'semester' => $sem_filter]);
};

$page_title = 'Manage Students';
$nav_title = 'Manage Students';
$nav_icon = 'fa-users';
$nav_back = 'dashboard';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/topnav.php';

/** Program dropdown options */
function program_options(array $programs, $selected)
{
    $html = '';
    foreach ($programs as $p) {
        $html .= '<option value="' . (int)$p['id'] . '"' . ((int)$selected === (int)$p['id'] ? ' selected' : '') . '>' . e($p['program_name']) . '</option>';
    }
    return $html;
}
?>
<div class="container pb-5">
    <?php echo flash_render(); ?>
    <?php if (!$programs): ?>
        <div class="alert alert-warning">No programs exist yet. The Admin must add a program in <strong>Fee Structure</strong> before students can be registered.</div>
    <?php endif; ?>

    <div class="card p-3 border-0 shadow-sm rounded-4 mb-4">
        <form action="" method="GET" class="row g-2 align-items-center">
            <div class="col-12 col-lg-3"><input type="text" name="search_input" maxlength="100" class="form-control" placeholder="Name, reg no. or email" value="<?php echo e($search_term); ?>"></div>
            <div class="col-sm-6 col-lg-3"><select name="program" class="form-select"><option value="0">All Programs</option><?php echo program_options($programs, $prog_filter); ?></select></div>
            <div class="col-sm-6 col-lg-3">
                <select name="semester" class="form-select">
                    <option value="0">All Semesters</option>
                    <?php for ($s = 1; $s <= MAX_SEMESTERS; $s++): ?><option value="<?php echo $s; ?>" <?php echo $sem_filter === $s ? 'selected' : ''; ?>>Semester <?php echo $s; ?></option><?php endfor; ?>
                </select>
            </div>
            <div class="col-12 col-lg-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-fill"><i class="fas fa-filter me-1"></i> Filter</button>
                <?php if ($search_term !== '' || $prog_filter || $sem_filter): ?><a href="?" class="btn btn-outline-secondary" title="Reset"><i class="fas fa-undo"></i></a><?php endif; ?>
                <button type="button" class="btn btn-brand px-3 text-nowrap" data-bs-toggle="modal" data-bs-target="#addStudentModal" <?php echo $programs ? '' : 'disabled'; ?>><i class="fas fa-plus me-1"></i> New</button>
            </div>
        </form>
    </div>

    <div class="card table-card overflow-hidden mb-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 sticky-first hide-xs-3 hide-xs-4">
                <thead class="table-light"><tr><th class="ps-4 py-3">Student Info</th><th>Reg Number</th><th>Program</th><th>Semester</th><th>Status</th><th class="text-center">Actions</th></tr></thead>
                <tbody>
                <?php if ($result->num_rows > 0): while ($row = $result->fetch_assoc()): $rid = (int)$row['id']; ?>
                    <tr>
                        <td class="ps-4"><div class="fw-bold text-capitalize"><?php echo e($row['full_name']); ?></div><small class="text-muted"><?php echo e($row['email']); ?></small></td>
                        <td><span class="badge bg-soft-primary px-3 py-1 border"><?php echo e($row['reg_number']); ?></span></td>
                        <td><?php echo e($row['program_name'] ?: 'Not assigned'); ?></td>
                        <td>Semester <?php echo (int)$row['semester']; ?></td>
                        <td><span class="badge rounded-pill <?php echo $row['status'] === 'Active' ? 'bg-success' : 'bg-secondary'; ?>"><?php echo e($row['status']); ?></span></td>
                        <td class="text-center text-nowrap">
                            <a href="collect_fee.php?id=<?php echo $rid; ?>" class="btn btn-sm btn-outline-success rounded-circle me-1" title="Fee / Collect"><i class="fas fa-money-bill"></i></a>
                            <button type="button" class="btn btn-sm btn-outline-primary rounded-circle me-1" data-bs-toggle="modal" data-bs-target="#editStudent<?php echo $rid; ?>" title="Edit"><i class="fas fa-edit"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-danger rounded-circle" title="Delete"
                                    data-confirm-post data-title="Delete Student Record?" data-confirm="Delete"
                                    data-text="<?php echo e('Deleting "' . $row['full_name'] . '" will remove their login and student data. This cannot be undone!'); ?>"
                                    data-fields="<?php echo e(json_encode(['del_id' => $rid])); ?>"><i class="fas fa-trash"></i></button>

                            <div class="modal fade text-start" id="editStudent<?php echo $rid; ?>" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-dialog-centered"><div class="modal-content rounded-4 border-0 shadow">
                                    <div class="modal-header border-0"><h5 class="modal-title fw-bold">Edit Student Record</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                                    <form action="" method="POST">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="student_id" value="<?php echo $rid; ?>">
                                        <div class="modal-body">
                                            <div class="mb-3"><label class="form-label fw-semibold">Full Name <span class="req">*</span></label><input type="text" name="full_name" maxlength="100" class="form-control" value="<?php echo e($row['full_name']); ?>" required></div>
                                            <div class="mb-3"><label class="form-label fw-semibold">Email Address <span class="req">*</span></label><input type="email" name="email" maxlength="100" class="form-control" value="<?php echo e($row['email']); ?>" required></div>
                                            <div class="mb-3"><label class="form-label fw-semibold">Registration Number <span class="req">*</span></label><input type="text" name="reg_number" maxlength="50" class="form-control" value="<?php echo e($row['reg_number']); ?>" required></div>
                                            <div class="row g-2">
                                                <div class="col-md-6 mb-3"><label class="form-label fw-semibold">Program <span class="req">*</span></label><select name="program_id" class="form-select" required><?php echo program_options($programs, $row['program_id']); ?></select></div>
                                                <div class="col-md-6 mb-3"><label class="form-label fw-semibold">Semester <span class="req">*</span></label>
                                                    <select name="semester" class="form-select" required><?php for ($s = 1; $s <= MAX_SEMESTERS; $s++): ?><option value="<?php echo $s; ?>" <?php echo (int)$row['semester'] === $s ? 'selected' : ''; ?>>Semester <?php echo $s; ?></option><?php endfor; ?></select>
                                                </div>
                                                <div class="col-md-6 mb-3"><label class="form-label fw-semibold">Scholarship (PKR)</label><input type="number" step="0.01" min="0" name="scholarship_discount" class="form-control" value="<?php echo e($row['scholarship_discount']); ?>"><div class="form-text">Discount per semester</div></div>
                                                <div class="col-md-6 mb-3"><label class="form-label fw-semibold">Account Status</label>
                                                    <select name="status" class="form-select"><option value="Active" <?php echo $row['status'] === 'Active' ? 'selected' : ''; ?>>Active</option><option value="Inactive" <?php echo $row['status'] === 'Inactive' ? 'selected' : ''; ?>>Inactive</option></select>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="modal-footer border-0"><button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button><button type="submit" name="update_student" class="btn btn-primary rounded-pill px-4">Save Changes</button></div>
                                    </form>
                                </div></div>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr><td colspan="6" class="text-center py-5 text-muted">No student records found matching your filter criteria.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($total_pages > 1): ?>
    <nav><ul class="pagination justify-content-center">
        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo e($qs($page - 1)); ?>">Previous</a></li>
        <?php for ($i = 1; $i <= $total_pages; $i++): ?><li class="page-item <?php echo $page === $i ? 'active' : ''; ?>"><a class="page-link" href="<?php echo e($qs($i)); ?>"><?php echo $i; ?></a></li><?php endfor; ?>
        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo e($qs($page + 1)); ?>">Next</a></li>
    </ul></nav>
    <?php endif; ?>
</div>

<div class="modal fade" id="addStudentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content rounded-4 border-0 shadow">
        <div class="modal-header border-0"><h5 class="modal-title fw-bold"><i class="fas fa-user-plus me-2 text-primary"></i>Register New Student</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <form action="" method="POST" autocomplete="off">
            <?php echo csrf_field(); ?>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label fw-semibold small">FULL NAME <span class="req">*</span></label><input type="text" name="full_name" maxlength="100" class="form-control" placeholder="e.g. Ali Khan" required></div>
                    <div class="col-md-6"><label class="form-label fw-semibold small">EMAIL ADDRESS <span class="req">*</span></label><input type="email" name="email" maxlength="100" class="form-control" placeholder="ali@student.com" required></div>
                    <div class="col-md-6"><label class="form-label fw-semibold small">REGISTRATION NUMBER <span class="req">*</span></label><input type="text" name="reg_number" maxlength="50" class="form-control" placeholder="2024-USTB-000123" required></div>
                    <div class="col-md-6"><label class="form-label fw-semibold small">PROGRAM <span class="req">*</span></label><select name="program_id" class="form-select" required><option value="">Select Program</option><?php echo program_options($programs, 0); ?></select></div>
                    <div class="col-md-6"><label class="form-label fw-semibold small">CURRENT SEMESTER <span class="req">*</span></label><select name="semester" class="form-select" required><?php for ($i = 1; $i <= MAX_SEMESTERS; $i++): ?><option value="<?php echo $i; ?>">Semester <?php echo $i; ?></option><?php endfor; ?></select></div>
                    <div class="col-md-6"><label class="form-label fw-semibold small">SCHOLARSHIP (PKR)</label><input type="number" step="0.01" min="0" name="scholarship_discount" class="form-control" value="0"><div class="form-text">Discount per semester</div></div>
                </div>
                <div class="alert alert-info border-0 rounded-3 mt-3 mb-0 py-2 small"><i class="fas fa-info-circle me-1"></i> A random temporary password is generated and emailed. The student must set a new password at first login.</div>
            </div>
            <div class="modal-footer border-0"><button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button><button type="submit" name="save_student" class="btn btn-primary rounded-pill px-4"><i class="fas fa-paper-plane me-1"></i> Save &amp; Send Email</button></div>
        </form>
    </div></div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
