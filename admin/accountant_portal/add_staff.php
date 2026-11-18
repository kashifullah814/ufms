<?php
/**
 * Staff register karna: login account + staff profile (ek transaction mein), credentials email.
 * Employment type: Regular/Contract = monthly salary; Visiting = per hour; Daily Wages = per day.
 * Edit: designation, type aur pay (purane salary bills par asar nahi - un par type/raqam copy hoti hai).
 * Permission: salaries
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
$user = require_permission('salaries');

$f = ['full_name' => '', 'email' => '', 'staff_id_number' => '', 'designation' => '', 'employment_type' => 'Regular', 'base_salary' => '', 'pay_rate' => ''];
$error = '';

/** Type ke hisaab se pay validate: monthly ke liye base salary, hourly/daily ke liye rate. [error, base, rate] */
function read_staff_pay($type, $baseRaw, $rateRaw)
{
    if (!isset(EMPLOYMENT_TYPES[$type])) return ['Please select an employment type.', 0, 0];
    if (EMPLOYMENT_TYPES[$type] === 'Monthly') {
        $base = parse_amount($baseRaw);
        return [$base === null ? 'Monthly salary must be a valid non-negative amount.' : null, $base, 0];
    }
    $rate = parse_amount($rateRaw, false);
    $unit = EMPLOYMENT_TYPES[$type] === 'Hourly' ? 'hour' : 'day';
    return [$rate === null ? "Rate per {$unit} must be greater than zero." : null, 0, $rate];
}

// ---------- EDIT STAFF (designation, type, pay) ----------
if (isset($_POST['update_staff'])) {
    $sid = (int)($_POST['staff_id'] ?? 0);
    $st = db_row("SELECT st.*, u.full_name FROM staff st JOIN users u ON u.id = st.user_id WHERE st.id = ?", [$sid]);
    $designation = trim((string)($_POST['designation'] ?? ''));
    $type = (string)($_POST['employment_type'] ?? '');
    list($err, $base, $rate) = read_staff_pay($type, $_POST['base_salary'] ?? '', $_POST['pay_rate'] ?? '');
    if (!$st) $err = 'Staff member not found.';
    elseif ($designation === '' || strlen($designation) > 100) $err = 'Please enter a valid designation.';
    if ($err) {
        flash('error', $err);
    } else {
        db_exec("UPDATE staff SET designation = ?, employment_type = ?, base_salary = ?, pay_rate = ? WHERE id = ?", [$designation, $type, $base, $rate, $sid]);
        audit('STAFF_UPDATED', "{$st['full_name']} ({$st['staff_id_number']}): {$type}, " . (EMPLOYMENT_TYPES[$type] === 'Monthly' ? 'PKR ' . money($base) . '/month' : 'PKR ' . money($rate) . '/' . (EMPLOYMENT_TYPES[$type] === 'Hourly' ? 'hour' : 'day')));
        flash('success', "Staff member {$st['full_name']} updated. Existing salary bills keep their old type and amounts.");
    }
    redirect_self();
}

if (isset($_POST['save_staff'])) {
    foreach ($f as $k => $v) { $f[$k] = trim((string)($_POST[$k] ?? '')); }
    $f['staff_id_number'] = strtoupper($f['staff_id_number']);
    list($payErr, $base, $rate) = read_staff_pay($f['employment_type'], $f['base_salary'], $f['pay_rate']);

    if ($f['full_name'] === '' || strlen($f['full_name']) > 100) $error = 'Please enter a valid full name.';
    elseif (!valid_email($f['email'])) $error = 'Please enter a valid email address.';
    elseif (!preg_match('/^[A-Z0-9\-\/]{2,50}$/', $f['staff_id_number'])) $error = 'Staff ID may only contain letters, numbers, "-" and "/".';
    elseif ($f['designation'] === '' || strlen($f['designation']) > 100) $error = 'Please enter a valid designation.';
    elseif ($payErr) $error = $payErr;
    elseif (db_value("SELECT id FROM users WHERE email = ?", [$f['email']])) $error = 'Email already registered!';
    elseif (db_value("SELECT id FROM staff WHERE staff_id_number = ?", [$f['staff_id_number']])) $error = 'Staff ID already exists!';

    if ($error === '') {
        $temp = generate_temp_password();
        db()->begin_transaction();
        try {
            db_exec("INSERT INTO users (full_name, email, password, role, status, must_change_password) VALUES (?, ?, ?, 'Staff', 'Active', 1)",
                [$f['full_name'], $f['email'], password_hash($temp, PASSWORD_BCRYPT)]);
            $uid = db_insert_id();
            db_exec("INSERT INTO staff (user_id, staff_id_number, designation, employment_type, base_salary, pay_rate) VALUES (?, ?, ?, ?, ?, ?)",
                [$uid, $f['staff_id_number'], $f['designation'], $f['employment_type'], $base, $rate]);
            db()->commit();
        } catch (mysqli_sql_exception $ex) {
            db()->rollback();
            if (!is_duplicate_error($ex)) throw $ex;
            $error = 'Email or Staff ID already exists.';
        }
    }

    if ($error === '') {
        audit('STAFF_CREATED', "Registered staff {$f['full_name']} ({$f['staff_id_number']}), {$f['employment_type']}");
        if (send_credentials_email($f['email'], $f['full_name'], 'staff', $temp, ['Staff ID' => $f['staff_id_number'], 'Designation' => $f['designation'], 'Employment' => $f['employment_type']], $mailErr)) {
            flash('success', 'Staff registered and login credentials emailed successfully!');
        } else {
            flash('warning', "Staff registered, but the email could not be sent ({$mailErr}). Please give these login details personally - Email: {$f['email']} | Temporary Password: {$temp}");
        }
        redirect_self();
    }
}

$staff_rows = db_select("SELECT st.*, u.full_name, u.email, u.status FROM staff st JOIN users u ON u.id = st.user_id ORDER BY st.id DESC");

$page_title = 'Register Staff';
$nav_title = 'Register Staff';
$nav_icon = 'fa-user-tie';
$nav_back = 'dashboard';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/topnav.php';
?>
<div class="container pb-5">
    <?php echo flash_render(); ?>
    <div class="row g-4">
        <div class="col-lg-5 split-form">
            <div class="card form-card">
                <div class="card-header text-white" style="background:#1e3a8a;"><h6 class="mb-0 py-1"><i class="fas fa-user-plus me-2"></i>Register New Staff Member</h6></div>
                <div class="card-body p-4">
                    <?php if ($error): /* flash jaisa hi look; form ka data apni jagah rehta hai */ ?><div class="alert alert-danger alert-dismissible fade show py-2 shadow-sm" role="alert"><i class="fas fa-exclamation-triangle me-2"></i><?php echo e($error); ?><button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button></div><?php endif; ?>
                    <form action="" method="POST" autocomplete="off">
                        <?php echo csrf_field(); ?>
                        <div class="mb-3"><label class="form-label fw-semibold small">FULL NAME <span class="req">*</span></label><input type="text" name="full_name" maxlength="100" class="form-control" value="<?php echo e($f['full_name']); ?>" required></div>
                        <div class="mb-3"><label class="form-label fw-semibold small">EMAIL ADDRESS <span class="req">*</span></label><input type="email" name="email" maxlength="100" class="form-control" value="<?php echo e($f['email']); ?>" required></div>
                        <div class="row g-2">
                            <div class="col-md-6 mb-3"><label class="form-label fw-semibold small">STAFF ID <span class="req">*</span></label><input type="text" name="staff_id_number" maxlength="50" class="form-control" placeholder="ST-3466" value="<?php echo e($f['staff_id_number']); ?>" required></div>
                            <div class="col-md-6 mb-3"><label class="form-label fw-semibold small">DESIGNATION <span class="req">*</span></label><input type="text" name="designation" maxlength="100" class="form-control" placeholder="Lecturer" value="<?php echo e($f['designation']); ?>" required></div>
                        </div>
                        <div class="mb-3"><label class="form-label fw-semibold small">EMPLOYMENT TYPE <span class="req">*</span></label>
                            <select name="employment_type" class="form-select js-emp-type" required>
                                <?php foreach (EMPLOYMENT_TYPES as $t => $basis): ?><option value="<?php echo e($t); ?>" data-basis="<?php echo $basis; ?>" <?php echo $f['employment_type'] === $t ? 'selected' : ''; ?>><?php echo e($t . ' (' . ($basis === 'Monthly' ? 'fixed monthly' : ($basis === 'Hourly' ? 'hours x rate' : 'days x rate')) . ')'); ?></option><?php endforeach; ?>
                            </select></div>
                        <div class="mb-3 js-pay-monthly"><label class="form-label fw-semibold small">MONTHLY SALARY (PKR) <span class="req">*</span></label><input type="number" step="0.01" min="0" name="base_salary" class="form-control" value="<?php echo e($f['base_salary']); ?>"></div>
                        <div class="mb-3 js-pay-rate" hidden><label class="form-label fw-semibold small"><span class="js-rate-label">RATE</span> (PKR) <span class="req">*</span></label><input type="number" step="0.01" min="0.01" name="pay_rate" class="form-control" value="<?php echo e($f['pay_rate']); ?>"></div>
                        <div class="alert alert-info border-0 py-2 small"><i class="fas fa-info-circle me-1"></i> A random temporary password is emailed; the staff member must change it at first login.</div>
                        <button type="submit" name="save_staff" class="btn btn-brand w-100 rounded-pill"><i class="fas fa-paper-plane me-1"></i> Save &amp; Send Email</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-7 split-list">
            <div class="card table-card p-3">
                <h6 class="fw-bold mb-3"><i class="fas fa-users me-2 text-primary"></i>Registered Staff</h6>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 small sticky-first hide-xs-2 hide-xs-4">
                        <thead class="table-light"><tr><th>Name</th><th>Staff ID</th><th>Designation</th><th>Type</th><th class="text-end">Pay</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                        <?php if ($staff_rows->num_rows > 0): while ($s = $staff_rows->fetch_assoc()): ?>
                            <tr>
                                <td><div class="fw-bold text-capitalize"><?php echo e($s['full_name']); ?></div><small class="text-muted"><?php echo e($s['email']); ?></small></td>
                                <td><?php echo e($s['staff_id_number']); ?></td>
                                <td><?php echo e($s['designation']); ?></td>
                                <td><span class="badge bg-light text-dark border"><?php echo e($s['employment_type']); ?></span></td>
                                <td class="text-end text-nowrap"><?php echo $s['pay_basis'] === 'Monthly' ? money($s['base_salary']) . ' <small class="text-muted">/month</small>' : money($s['pay_rate']) . ' <small class="text-muted">/' . ($s['pay_basis'] === 'Hourly' ? 'hour' : 'day') . '</small>'; ?></td>
                                <td><span class="badge <?php echo $s['status'] === 'Active' ? 'bg-success' : 'bg-secondary'; ?>"><?php echo e($s['status']); ?></span></td>
                                <td><button type="button" class="btn btn-sm btn-outline-primary rounded-circle" title="Edit" data-bs-toggle="modal" data-bs-target="#editStaff<?php echo (int)$s['id']; ?>"><i class="fas fa-edit"></i></button>
                                    <div class="modal fade text-start" id="editStaff<?php echo (int)$s['id']; ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog modal-dialog-centered"><div class="modal-content rounded-4 border-0 shadow">
                                            <div class="modal-header border-0"><h5 class="modal-title fw-bold">Edit Staff - <?php echo e($s['full_name']); ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                                            <form action="" method="POST">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="staff_id" value="<?php echo (int)$s['id']; ?>">
                                                <div class="modal-body">
                                                    <div class="mb-3"><label class="form-label fw-semibold">Designation <span class="req">*</span></label><input type="text" name="designation" class="form-control" maxlength="100" value="<?php echo e($s['designation']); ?>" required></div>
                                                    <div class="mb-3"><label class="form-label fw-semibold">Employment Type <span class="req">*</span></label>
                                                        <select name="employment_type" class="form-select js-emp-type" required>
                                                            <?php foreach (EMPLOYMENT_TYPES as $t => $basis): ?><option value="<?php echo e($t); ?>" data-basis="<?php echo $basis; ?>" <?php echo $s['employment_type'] === $t ? 'selected' : ''; ?>><?php echo e($t); ?></option><?php endforeach; ?>
                                                        </select></div>
                                                    <div class="mb-3 js-pay-monthly"><label class="form-label fw-semibold">Monthly Salary (PKR) <span class="req">*</span></label><input type="number" step="0.01" min="0" name="base_salary" class="form-control" value="<?php echo e($s['base_salary']); ?>"></div>
                                                    <div class="mb-3 js-pay-rate" hidden><label class="form-label fw-semibold"><span class="js-rate-label">Rate</span> (PKR) <span class="req">*</span></label><input type="number" step="0.01" min="0" name="pay_rate" class="form-control" value="<?php echo e($s['pay_rate']); ?>"></div>
                                                    <p class="small text-muted mb-0">Changes apply to new salary bills only.</p>
                                                </div>
                                                <div class="modal-footer border-0"><button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button><button type="submit" name="update_staff" class="btn btn-primary rounded-pill px-4">Save Changes</button></div>
                                            </form>
                                        </div></div>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; else: ?>
                            <tr><td colspan="7" class="text-center text-muted py-4">No staff registered yet.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
// Monthly type par salary field, hourly/daily par rate field
function syncPayFields(sel) {
    const form = sel.closest('form');
    const basis = sel.selectedOptions[0].getAttribute('data-basis');
    form.querySelector('.js-pay-monthly').hidden = basis !== 'Monthly';
    form.querySelector('.js-pay-rate').hidden = basis === 'Monthly';
    // chhupa hua field required nahi (warna form submit nahi hota); dikhne wala lazmi
    form.querySelector('.js-pay-monthly input').required = basis === 'Monthly';
    form.querySelector('.js-pay-rate input').required = basis !== 'Monthly';
    form.querySelector('.js-rate-label').textContent = basis === 'Hourly' ? 'Rate per hour' : 'Rate per day';
}
document.querySelectorAll('.js-emp-type').forEach(function (sel) { syncPayFields(sel); sel.addEventListener('change', function () { syncPayFields(sel); }); });
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
