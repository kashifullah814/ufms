<?php
/** Admin: accountants ki list - search, status filter, pagination, edit modal, delete. */
require_once __DIR__ . '/../../includes/bootstrap.php';
$admin = require_login(['Admin']);

if (isset($_POST['update_accountant'])) {
    $uid    = (int)($_POST['user_id'] ?? 0);
    $name   = trim((string)($_POST['full_name'] ?? ''));
    $email  = trim((string)($_POST['email'] ?? ''));
    $status = in_array($_POST['status'] ?? '', ['Active', 'Inactive'], true) ? $_POST['status'] : 'Active';
    $fees = isset($_POST['can_manage_fees']) ? 1 : 0;
    $sal  = isset($_POST['can_manage_salaries']) ? 1 : 0;
    $exp  = isset($_POST['can_manage_expenses']) ? 1 : 0;
    $acc  = isset($_POST['can_manage_accounts']) ? 1 : 0;

    if ($name === '' || strlen($name) > 100 || !valid_email($email)) {
        flash('error', 'Please enter a valid name (up to 100 characters) and email.');
    } elseif (db_value("SELECT id FROM users WHERE email = ? AND id <> ?", [$email, $uid])) {
        flash('error', 'This email is already used by another account.');
    } elseif (db_exec("UPDATE users SET full_name = ?, email = ?, status = ?, can_manage_fees = ?, can_manage_salaries = ?, can_manage_expenses = ?, can_manage_accounts = ?
                       WHERE id = ? AND role = 'Accountant'", [$name, $email, $status, $fees, $sal, $exp, $acc, $uid]) >= 0) {
        audit('ACCOUNTANT_UPDATED', "Updated accountant {$name} ({$status})");
        flash('success', 'Accountant updated successfully!');
    }
    redirect_self();
}

if (isset($_POST['delete_id'])) {
    $del = (int)$_POST['delete_id'];
    $acc = db_row("SELECT full_name FROM users WHERE id = ? AND role = 'Accountant'", [$del]);
    if ($acc) {
        db_exec("DELETE FROM users WHERE id = ? AND role = 'Accountant'", [$del]);
        audit('ACCOUNTANT_DELETED', "Deleted accountant {$acc['full_name']}");
        flash('success', 'Accountant deleted successfully!');
    } else {
        flash('error', 'Accountant not found.');
    }
    redirect_self();
}

// Search, filter, pagination
$search = trim((string)($_GET['search'] ?? ''));
$status_filter = in_array($_GET['status'] ?? '', ['Active', 'Inactive'], true) ? $_GET['status'] : '';
$where = "WHERE role = 'Accountant'";
$params = [];
if ($search !== '') {
    $where .= " AND (full_name LIKE ? OR email LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
if ($status_filter !== '') {
    $where .= " AND status = ?";
    $params[] = $status_filter;
}
$limit = 10;
$total_rows = (int)db_value("SELECT COUNT(*) FROM users $where", $params, 0);
$total_pages = max(1, (int)ceil($total_rows / $limit));
$page = min(max(1, (int)($_GET['page'] ?? 1)), $total_pages);
$offset = ($page - 1) * $limit;
$result = db_select("SELECT * FROM users $where ORDER BY id DESC LIMIT $limit OFFSET $offset", $params);
$qs = function ($p) use ($search, $status_filter) { return '?' . http_build_query(['page' => $p, 'search' => $search, 'status' => $status_filter]); };

$page_title = 'Manage Accountants';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/admin_sidebar.php';
?>
<div class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="fw-bold mb-0"><i class="fas fa-users-cog me-2 text-primary"></i>Manage Accountants</h4>
        <a href="add_accountant.php" class="btn btn-brand rounded-pill px-4"><i class="fas fa-plus me-1"></i> Add Accountant</a>
    </div>
    <?php echo flash_render(); ?>

    <div class="card p-3 border-0 shadow-sm rounded-4 mb-4">
        <form method="GET" action="" class="row g-2 align-items-center">
            <div class="col-md-6"><input type="text" name="search" maxlength="100" class="form-control" placeholder="Search by name or email..." value="<?php echo e($search); ?>"></div>
            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="">All Status</option>
                    <option value="Active" <?php echo $status_filter === 'Active' ? 'selected' : ''; ?>>Active</option>
                    <option value="Inactive" <?php echo $status_filter === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search me-1"></i> Search</button>
                <?php if ($search !== '' || $status_filter !== ''): ?><a href="?" class="btn btn-outline-secondary" title="Reset"><i class="fas fa-undo"></i></a><?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card table-card overflow-hidden mb-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 hide-xs-2">
                <thead class="table-light"><tr><th class="ps-4">Accountant</th><th>Phone / Employee ID</th><th>Permissions</th><th>Status</th><th class="text-center">Actions</th></tr></thead>
                <tbody>
                <?php if ($result->num_rows > 0): while ($row = $result->fetch_assoc()): $rid = (int)$row['id']; ?>
                    <tr>
                        <td class="ps-4"><div class="fw-bold text-capitalize"><?php echo e($row['full_name']); ?></div><small class="text-muted"><?php echo e($row['email']); ?></small></td>
                        <td class="small"><?php echo e($row['phone'] ?: '-'); ?><br><span class="text-muted"><?php echo e($row['employee_id'] ?: '-'); ?></span></td>
                        <td>
                            <?php if ($row['can_manage_fees']): ?><span class="badge bg-primary">Fees</span><?php endif; ?>
                            <?php if ($row['can_manage_salaries']): ?><span class="badge bg-success">Salaries</span><?php endif; ?>
                            <?php if ($row['can_manage_expenses']): ?><span class="badge bg-warning text-dark">Expenses</span><?php endif; ?>
                            <?php if ($row['can_manage_accounts']): ?><span class="badge bg-dark">Accounts</span><?php endif; ?>
                            <?php if (!$row['can_manage_fees'] && !$row['can_manage_salaries'] && !$row['can_manage_expenses'] && !$row['can_manage_accounts']): ?><span class="text-muted small">None</span><?php endif; ?>
                        </td>
                        <td><span class="badge rounded-pill <?php echo $row['status'] === 'Active' ? 'bg-success' : 'bg-secondary'; ?>"><?php echo e($row['status']); ?></span></td>
                        <td class="text-center text-nowrap">
                            <button type="button" class="btn btn-sm btn-outline-primary rounded-circle me-1" data-bs-toggle="modal" data-bs-target="#edit<?php echo $rid; ?>" title="Edit"><i class="fas fa-edit"></i></button>
                            <a href="add_accountant.php?id=<?php echo $rid; ?>" class="btn btn-sm btn-outline-secondary rounded-circle me-1" title="Full edit / reset password"><i class="fas fa-user-cog"></i></a>
                            <button type="button" class="btn btn-sm btn-outline-danger rounded-circle" title="Delete"
                                    data-confirm-post data-title="Delete accountant?" data-confirm="Delete"
                                    data-text="<?php echo e('Do you really want to delete "' . $row['full_name'] . '"? This cannot be undone.'); ?>"
                                    data-fields="<?php echo e(json_encode(['delete_id' => $rid])); ?>"><i class="fas fa-trash"></i></button>

                            <div class="modal fade text-start" id="edit<?php echo $rid; ?>" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content rounded-4 border-0 shadow">
                                        <div class="modal-header border-0"><h5 class="modal-title fw-bold">Edit Accountant</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                                        <form action="" method="POST">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="user_id" value="<?php echo $rid; ?>">
                                            <div class="modal-body">
                                                <div class="mb-3"><label class="form-label fw-semibold">Full Name <span class="req">*</span></label><input type="text" name="full_name" maxlength="100" class="form-control" value="<?php echo e($row['full_name']); ?>" required></div>
                                                <div class="mb-3"><label class="form-label fw-semibold">Email <span class="req">*</span></label><input type="email" name="email" maxlength="100" class="form-control" value="<?php echo e($row['email']); ?>" required></div>
                                                <div class="mb-3"><label class="form-label fw-semibold">Status</label>
                                                    <select name="status" class="form-select">
                                                        <option value="Active" <?php echo $row['status'] === 'Active' ? 'selected' : ''; ?>>Active</option>
                                                        <option value="Inactive" <?php echo $row['status'] === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                                                    </select>
                                                </div>
                                                <label class="form-label fw-semibold d-block">Permissions</label>
                                                <div class="d-flex gap-3 flex-wrap">
                                                    <label><input type="checkbox" name="can_manage_fees" value="1" <?php echo $row['can_manage_fees'] ? 'checked' : ''; ?>> Fees</label>
                                                    <label><input type="checkbox" name="can_manage_salaries" value="1" <?php echo $row['can_manage_salaries'] ? 'checked' : ''; ?>> Salaries</label>
                                                    <label><input type="checkbox" name="can_manage_expenses" value="1" <?php echo $row['can_manage_expenses'] ? 'checked' : ''; ?>> Expenses</label>
                                                    <label><input type="checkbox" name="can_manage_accounts" value="1" <?php echo $row['can_manage_accounts'] ? 'checked' : ''; ?>> Accounts</label>
                                                </div>
                                            </div>
                                            <div class="modal-footer border-0">
                                                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" name="update_accountant" class="btn btn-primary rounded-pill px-4">Save Changes</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr><td colspan="5" class="text-center py-5 text-muted">No accountants found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($total_pages > 1): ?>
    <nav><ul class="pagination justify-content-center">
        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo e($qs($page - 1)); ?>">Previous</a></li>
        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <li class="page-item <?php echo $page === $i ? 'active' : ''; ?>"><a class="page-link" href="<?php echo e($qs($i)); ?>"><?php echo $i; ?></a></li>
        <?php endfor; ?>
        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo e($qs($page + 1)); ?>">Next</a></li>
    </ul></nav>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
