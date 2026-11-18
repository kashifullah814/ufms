<?php
/**
 * Admin: naya accountant banana ya maujooda ko edit karna (?id=).
 * Naye accountant ko random temporary password milta hai jo email hota hai (students/staff ki tarah);
 * email na ja sake to login details screen par dikhti hain. Pehle login par password badalna lazmi.
 * Edit mein password field sirf reset ke liye hai.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
$admin = require_login(['Admin']);

$id = (int)($_GET['id'] ?? 0);
$is_edit = false;
$f = ['full_name' => '', 'email' => '', 'phone' => '', 'employee_id' => '', 'can_manage_fees' => 0, 'can_manage_salaries' => 0, 'can_manage_expenses' => 0, 'can_manage_accounts' => 0];

if ($id > 0) {
    $row = db_row("SELECT * FROM users WHERE id = ? AND role = 'Accountant'", [$id]);
    if (!$row) {
        flash('error', 'Accountant not found.');
        redirect(url('admin/dashboard/manage_users.php'));
    }
    $is_edit = true;
    foreach ($f as $k => $v) { $f[$k] = $row[$k] ?? $v; }
}

$error = '';
if (isset($_POST['save_accountant'])) {
    $f['full_name']   = trim((string)($_POST['full_name'] ?? ''));
    $f['email']       = trim((string)($_POST['email'] ?? ''));
    $f['phone']       = trim((string)($_POST['phone'] ?? ''));
    $f['employee_id'] = trim((string)($_POST['employee_id'] ?? ''));
    $f['can_manage_fees']     = isset($_POST['manage_fees']) ? 1 : 0;
    $f['can_manage_salaries'] = isset($_POST['manage_salaries']) ? 1 : 0;
    $f['can_manage_expenses'] = isset($_POST['manage_expenses']) ? 1 : 0;
    $f['can_manage_accounts'] = isset($_POST['manage_accounts']) ? 1 : 0;
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['confirm_password'] ?? '');

    if ($f['full_name'] === '' || strlen($f['full_name']) > 100) {
        $error = 'Please enter a valid full name.';
    } elseif (!valid_email($f['email'])) {
        $error = 'Please enter a valid email address.';
    } elseif ($f['phone'] !== '' && !preg_match('/^\+?[0-9\- ]{7,20}$/', $f['phone'])) {
        $error = 'Please enter a valid phone number (digits only, e.g. 03001234567).';
    } elseif (strlen($f['employee_id']) > 50) {
        $error = 'Employee ID is too long.';
    } elseif (db_value("SELECT id FROM users WHERE email = ? AND id <> ?", [$f['email'], $id])) {
        $error = 'Email address already exists in the system!';
    } elseif ($is_edit && $password !== '') {
        if ($password !== $confirm) {
            $error = 'Password and Confirm Password do not match!';
        } elseif ($p = password_problem($password)) {
            $error = $p;
        }
    }

    if ($error === '') {
        $phone = $f['phone'] !== '' ? $f['phone'] : null;
        $emp   = $f['employee_id'] !== '' ? $f['employee_id'] : null;
        if ($is_edit) {
            db_exec("UPDATE users SET full_name = ?, email = ?, phone = ?, employee_id = ?, can_manage_fees = ?, can_manage_salaries = ?, can_manage_expenses = ?, can_manage_accounts = ?
                     WHERE id = ? AND role = 'Accountant'",
                [$f['full_name'], $f['email'], $phone, $emp, $f['can_manage_fees'], $f['can_manage_salaries'], $f['can_manage_expenses'], $f['can_manage_accounts'], $id]);
            if ($password !== '') {
                db_exec("UPDATE users SET password = ?, must_change_password = 1 WHERE id = ?", [password_hash($password, PASSWORD_BCRYPT), $id]);
            }
            audit('ACCOUNTANT_UPDATED', "Updated accountant {$f['full_name']}");
            flash('success', 'Accountant updated successfully!');
            redirect(url('admin/dashboard/manage_users.php'));
        } else {
            $temp = generate_temp_password();
            db_exec("INSERT INTO users (full_name, email, phone, employee_id, password, role, status, can_manage_fees, can_manage_salaries, can_manage_expenses, can_manage_accounts, must_change_password)
                     VALUES (?, ?, ?, ?, ?, 'Accountant', 'Active', ?, ?, ?, ?, 1)",
                [$f['full_name'], $f['email'], $phone, $emp, password_hash($temp, PASSWORD_BCRYPT), $f['can_manage_fees'], $f['can_manage_salaries'], $f['can_manage_expenses'], $f['can_manage_accounts']]);
            audit('ACCOUNTANT_CREATED', "Created accountant {$f['full_name']} ({$f['email']})");
            $perms = array_keys(array_filter(['Fees' => $f['can_manage_fees'], 'Salaries' => $f['can_manage_salaries'], 'Expenses' => $f['can_manage_expenses'], 'Accounts' => $f['can_manage_accounts']]));
            $extra = ['Permissions' => $perms ? implode(', ', $perms) : 'None yet'];
            if ($emp !== null) $extra = ['Employee ID' => $emp] + $extra;
            if (send_credentials_email($f['email'], $f['full_name'], 'accountant', $temp, $extra, $mailErr)) {
                flash('success', 'Accountant created and login credentials emailed successfully! The accountant must set a new password at first login.');
            } else {
                flash('warning', "Accountant created, but the email could not be sent ({$mailErr}). Please give these login details to the accountant personally - Email: {$f['email']} | Temporary Password: {$temp}");
            }
            redirect(url('admin/dashboard/add_accountant.php'));
        }
    }
}

$page_title = $is_edit ? 'Edit Accountant' : 'Add Accountant';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/admin_sidebar.php';
?>
<div class="main-content">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card form-card">
                <div class="card-header text-white py-3" style="background:#1e3a8a;border-radius:15px 15px 0 0;">
                    <h5 class="mb-0"><i class="fas <?php echo $is_edit ? 'fa-user-edit' : 'fa-user-plus'; ?> me-2"></i><?php echo $is_edit ? 'Edit Accountant' : 'Create Accountant Account'; ?></h5>
                </div>
                <div class="card-body p-4">
                    <?php echo flash_render(); ?>
                    <?php if ($error): /* flash jaisa hi look; form ka data apni jagah rehta hai */ ?><div class="alert alert-danger alert-dismissible fade show py-2 shadow-sm" role="alert"><i class="fas fa-exclamation-triangle me-2"></i><?php echo e($error); ?><button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button></div><?php endif; ?>

                    <form action="" method="POST" autocomplete="off">
                        <?php echo csrf_field(); ?>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">FULL NAME <span class="req">*</span></label>
                                <input type="text" name="full_name" class="form-control" maxlength="100" value="<?php echo e($f['full_name']); ?>" required autocomplete="off">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">EMAIL ADDRESS <span class="req">*</span></label>
                                <input type="email" name="email" class="form-control" maxlength="100" value="<?php echo e($f['email']); ?>" required autocomplete="off">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">PHONE NUMBER</label>
                                <input type="text" name="phone" class="form-control" maxlength="20" placeholder="03001234567" value="<?php echo e($f['phone']); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">EMPLOYEE ID</label>
                                <input type="text" name="employee_id" class="form-control" maxlength="50" placeholder="EMP-001" value="<?php echo e($f['employee_id']); ?>">
                            </div>
                            <?php if ($is_edit): ?>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">NEW PASSWORD</label>
                                <div class="input-group">
                                    <input type="password" name="password" id="accPass" class="form-control" autocomplete="new-password">
                                    <span class="input-group-text toggle-password" data-target="accPass"><i class="fas fa-eye text-muted"></i></span>
                                </div>
                                <div class="form-text">Leave blank to keep the current password.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">CONFIRM NEW PASSWORD</label>
                                <div class="input-group">
                                    <input type="password" name="confirm_password" id="accPass2" class="form-control" autocomplete="new-password">
                                    <span class="input-group-text toggle-password" data-target="accPass2"><i class="fas fa-eye text-muted"></i></span>
                                </div>
                            </div>
                            <div class="col-12">
                                <small class="text-muted">Password: at least <?php echo MIN_PASSWORD_LENGTH; ?> characters with letters and numbers. The accountant must change it at next login.</small>
                            </div>
                            <?php else: ?>
                            <div class="col-12">
                                <div class="alert alert-info border-0 py-2 small mb-0"><i class="fas fa-info-circle me-1"></i> A random temporary password is emailed to the accountant, who must change it at first login. If the email cannot be sent, the login details are shown here so you can hand them over personally.</div>
                            </div>
                            <?php endif; ?>
                            <div class="col-12">
                                <label class="form-label fw-semibold small d-block">PERMISSIONS</label>
                                <div class="d-flex gap-4 flex-wrap p-3 bg-light rounded">
                                    <label><input type="checkbox" name="manage_fees" value="1" <?php echo $f['can_manage_fees'] ? 'checked' : ''; ?>> Fees &amp; Students</label>
                                    <label><input type="checkbox" name="manage_salaries" value="1" <?php echo $f['can_manage_salaries'] ? 'checked' : ''; ?>> Salaries &amp; Staff</label>
                                    <label><input type="checkbox" name="manage_expenses" value="1" <?php echo $f['can_manage_expenses'] ? 'checked' : ''; ?>> Expenses</label>
                                    <label><input type="checkbox" name="manage_accounts" value="1" <?php echo $f['can_manage_accounts'] ? 'checked' : ''; ?>> Accounts (vouchers, reports, budgets)</label>
                                </div>
                            </div>
                        </div>
                        <div class="mt-4 d-flex justify-content-between">
                            <a href="manage_users.php" class="btn btn-light rounded-pill px-4">Cancel</a>
                            <button type="submit" name="save_accountant" class="btn btn-brand rounded-pill px-5"><i class="fas fa-save me-1"></i><?php echo $is_edit ? 'Update Accountant' : 'Create Account'; ?></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
