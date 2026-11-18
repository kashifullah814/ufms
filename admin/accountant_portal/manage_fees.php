<?php
/**
 * Fee Collection Center: har student ka paid / pending hisaab, status filter, search. Permission: fees
 * Har student ke saath "Last reminded: <date>" (aakhri fee reminder, table reminder_recipients).
 * Dues wale student ke saath "Send reminder" aur upar "Remind all students with dues" (reminders.php pre-filled kholta hai).
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
$user = require_permission('fees');

$reg_no = trim((string)($_GET['reg_no'] ?? ''));
$status_filter = in_array($_GET['status'] ?? '', ['paid', 'unpaid', 'nofee'], true) ? $_GET['status'] : 'all';

$where = [];
$params = [];
if ($reg_no !== '') {
    $where[] = "(s.reg_number LIKE ? OR u.full_name LIKE ?)";
    array_push($params, "%{$reg_no}%", "%{$reg_no}%");
}
if ($status_filter === 'paid')   $where[] = "COALESCE(t.pending, 0) = 0 AND COALESCE(t.records, 0) > 0";
if ($status_filter === 'unpaid') $where[] = "COALESCE(t.pending, 0) > 0";
if ($status_filter === 'nofee')  $where[] = "COALESCE(t.records, 0) = 0";
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// List: 20 students per page (filters ke saath)
$from_sql = "
     FROM students s
     JOIN users u ON s.user_id = u.id
     LEFT JOIN fee_structure fs ON fs.id = s.program_id
     LEFT JOIN (
        SELECT student_id, SUM(amount_paid) AS paid, SUM(CASE WHEN paid_status <> 'Paid' THEN balance ELSE 0 END) AS pending,
               COUNT(*) AS records, MAX(payment_date) AS last_payment_date
        FROM fees_records GROUP BY student_id
     ) t ON t.student_id = s.id
     LEFT JOIN (
        SELECT rr.user_id, MAX(rr.created_at) AS last_reminded
        FROM reminder_recipients rr JOIN announcements a ON a.id = rr.reminder_id WHERE a.type = 'Fee' GROUP BY rr.user_id
     ) lr ON lr.user_id = s.user_id
     $where_sql";
$per_page = 20;
$total_rows = (int)db_value("SELECT COUNT(*) $from_sql", $params, 0);
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$page = min(max(1, (int)($_GET['page'] ?? 1)), $total_pages);
$students = db_select(
    "SELECT s.id, s.reg_number, s.semester, u.full_name, u.email, u.status, fs.program_name,
            COALESCE(t.paid, 0) AS paid_amount, COALESCE(t.pending, 0) AS pending_amount,
            COALESCE(t.records, 0) AS records, t.last_payment_date, lr.last_reminded
     $from_sql
     ORDER BY pending_amount DESC, s.id DESC LIMIT $per_page OFFSET " . (($page - 1) * $per_page), $params);
$page_url = function ($p) use ($reg_no, $status_filter) {
    return '?' . http_build_query(array_filter(['reg_no' => $reg_no, 'status' => $status_filter === 'all' ? '' : $status_filter, 'page' => $p > 1 ? $p : null], function ($v) { return $v !== '' && $v !== null; }));
};

$totals = db_row("SELECT COALESCE(SUM(amount_paid),0) paid, COALESCE(SUM(CASE WHEN paid_status <> 'Paid' THEN balance ELSE 0 END),0) pending FROM fees_records");

$page_title = 'Fee Collection';
$nav_title = 'Fee Collection';
$nav_icon = 'fa-file-invoice-dollar';
$nav_back = 'dashboard';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/topnav.php';
?>
<div class="container pb-5">
    <?php echo flash_render(); ?>
    <div class="row g-3 mb-4">
        <div class="col-md-6"><div class="card stat-card p-3 border-start border-success border-5"><small class="text-muted fw-bold">TOTAL COLLECTED</small><h4 class="fw-bold text-success mb-0">PKR&nbsp;<?php echo money($totals['paid']); ?></h4></div></div>
        <div class="col-md-6"><div class="card stat-card p-3 border-start border-danger border-5"><small class="text-muted fw-bold">TOTAL PENDING (BALANCE)</small><h4 class="fw-bold text-danger mb-0">PKR&nbsp;<?php echo money($totals['pending']); ?></h4></div></div>
    </div>

    <div class="card p-3 border-0 shadow-sm rounded-4 mb-4">
        <form action="" method="GET" class="row g-2 align-items-end">
            <div class="col-md-5"><label class="form-label small fw-bold">SEARCH</label><input type="text" name="reg_no" maxlength="50" class="form-control" placeholder="Registration number or name" value="<?php echo e($reg_no); ?>"></div>
            <div class="col-md-4"><label class="form-label small fw-bold">STATUS</label>
                <select name="status" class="form-select">
                    <option value="all">All Students</option>
                    <option value="unpaid" <?php echo $status_filter === 'unpaid' ? 'selected' : ''; ?>>Has pending dues</option>
                    <option value="paid" <?php echo $status_filter === 'paid' ? 'selected' : ''; ?>>Fully paid</option>
                    <option value="nofee" <?php echo $status_filter === 'nofee' ? 'selected' : ''; ?>>No fee record yet</option>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2"><button class="btn btn-primary w-100"><i class="fas fa-search me-1"></i> Search</button><?php if ($reg_no !== '' || $status_filter !== 'all'): ?><a href="?" class="btn btn-outline-secondary"><i class="fas fa-undo"></i></a><?php endif; ?></div>
        </form>
    </div>

    <div class="d-flex justify-content-end flex-wrap gap-2 mb-3">
        <a href="reminders.php" class="btn btn-outline-secondary rounded-pill px-3"><i class="fas fa-history me-1"></i>Reminder history</a>
        <?php if ($totals['pending'] > 0): ?>
            <a href="reminders.php?dues=all" class="btn btn-danger rounded-pill px-3"><i class="fas fa-bell me-1"></i>Remind all students with dues</a>
        <?php endif; ?>
    </div>

    <div class="card table-card overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 sticky-first hide-xs-3">
                <thead class="table-light"><tr><th class="ps-4">Student</th><th>Reg No</th><th>Program</th><th>Paid</th><th>Pending</th><th class="text-center">Action</th></tr></thead>
                <tbody>
                <?php if ($students->num_rows > 0): while ($row = $students->fetch_assoc()): ?>
                    <tr>
                        <td class="ps-4"><div class="fw-bold text-capitalize"><?php echo e($row['full_name']); ?></div><small class="text-muted">Semester <?php echo (int)$row['semester']; ?><?php echo $row['status'] !== 'Active' ? ' - Inactive' : ''; ?></small></td>
                        <td><span class="badge bg-soft-primary border"><?php echo e($row['reg_number']); ?></span></td>
                        <td class="cell-wrap" style="min-width:9rem"><?php echo e($row['program_name'] ?: '-'); ?></td>
                        <td class="text-success fw-bold">PKR&nbsp;<?php echo money($row['paid_amount']); ?>
                            <?php if ($row['last_payment_date']): ?><div class="small fw-normal text-muted">Last payment: <?php echo e(fmt_date($row['last_payment_date'])); ?></div><?php endif; ?></td>
                        <td class="<?php echo $row['pending_amount'] > 0 ? 'text-danger fw-bold' : 'text-muted'; ?>">PKR&nbsp;<?php echo money($row['pending_amount']); ?>
                            <?php if ($row['last_reminded']): ?><div class="small fw-normal text-muted text-nowrap">Last reminded: <?php echo e(fmt_date($row['last_reminded'])); ?></div>
                            <?php elseif ($row['pending_amount'] > 0): ?><div class="small fw-normal text-muted text-nowrap">Not reminded yet</div><?php endif; ?></td>
                        <td class="text-center text-nowrap"><a href="collect_fee.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-success rounded-pill px-3"><i class="fas fa-hand-holding-usd me-1"></i>Collect / View</a>
                            <?php if ($row['pending_amount'] > 0): ?><a href="reminders.php?student=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-outline-danger rounded-pill px-3 ms-1" title="Send a fee reminder to this student"><i class="fas fa-bell me-1"></i>Remind</a><?php endif; ?></td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr><td colspan="6" class="text-center py-5 text-muted">No students found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($total_pages > 1): ?>
    <nav class="mt-4"><ul class="pagination justify-content-center">
        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo e($page_url($page - 1)); ?>">Previous</a></li>
        <?php for ($i = 1; $i <= $total_pages; $i++): ?><li class="page-item <?php echo $page === $i ? 'active' : ''; ?>"><a class="page-link" href="<?php echo e($page_url($i)); ?>"><?php echo $i; ?></a></li><?php endfor; ?>
        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo e($page_url($page + 1)); ?>">Next</a></li>
    </ul></nav>
    <p class="text-center text-muted small mb-0"><?php echo $total_rows; ?> student<?php echo $total_rows === 1 ? '' : 's'; ?> &middot; page <?php echo $page; ?> of <?php echo $total_pages; ?></p>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
