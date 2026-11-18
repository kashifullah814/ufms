<?php
/**
 * Admin: poora activity log (kis ne kab login kiya, kya add/delete kiya).
 * ?action=A,B sirf in actions ko dikhata hai, ?day=YYYY-MM-DD sirf us din ke (dashboard ka Security card).
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login(['Admin']);

$search = trim((string)($_GET['search'] ?? ''));
$actions = array_values(array_unique(array_filter(explode(',', strtoupper((string)($_GET['action'] ?? ''))), function ($a) {
    return preg_match('/^[A-Z_]{1,50}$/', $a);
})));
$action_param = implode(',', $actions);
$day = valid_date($_GET['day'] ?? '') ? $_GET['day'] : '';
$conds = [];
$params = [];
if ($search !== '') {
    $conds[] = "(user_name LIKE ? OR action LIKE ? OR details LIKE ?)";
    array_push($params, "%{$search}%", "%{$search}%", "%{$search}%");
}
if ($actions) {
    $conds[] = 'action IN (' . implode(',', array_fill(0, count($actions), '?')) . ')';
    $params = array_merge($params, $actions);
}
if ($day !== '') {
    $conds[] = 'created_at >= ? AND created_at < ? + INTERVAL 1 DAY';
    array_push($params, $day, $day);
}
$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';
$limit = 25;
$total = (int)db_value("SELECT COUNT(*) FROM audit_logs $where", $params, 0);
$total_pages = max(1, (int)ceil($total / $limit));
$page = min(max(1, (int)($_GET['page'] ?? 1)), $total_pages);
$offset = ($page - 1) * $limit;
$logs = db_select("SELECT * FROM audit_logs $where ORDER BY id DESC LIMIT $limit OFFSET $offset", $params);

$page_title = 'Activity Logs';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/admin_sidebar.php';
?>
<div class="main-content">
    <h4 class="fw-bold mb-4"><i class="fas fa-history me-2 text-primary"></i>System Activity Logs</h4>
    <div class="card p-3 border-0 shadow-sm rounded-4 mb-4">
        <form method="GET" class="row g-2">
            <?php if ($action_param !== ''): ?><input type="hidden" name="action" value="<?php echo e($action_param); ?>"><?php endif; ?>
            <?php if ($day !== ''): ?><input type="hidden" name="day" value="<?php echo e($day); ?>"><?php endif; ?>
            <div class="col-md-9"><input type="text" name="search" maxlength="100" class="form-control" placeholder="Search user, action or details..." value="<?php echo e($search); ?>"></div>
            <div class="col-md-3 d-flex gap-2"><button class="btn btn-primary w-100"><i class="fas fa-search me-1"></i> Search</button><?php if ($search !== '' || $action_param !== '' || $day !== ''): ?><a href="?" class="btn btn-outline-secondary" title="Clear filters"><i class="fas fa-undo"></i></a><?php endif; ?></div>
        </form>
        <?php if ($actions || $day !== ''): ?>
            <div class="small mt-2"><i class="fas fa-filter me-1 text-muted"></i>Showing only:
                <?php foreach ($actions as $a): ?><span class="badge bg-danger-subtle text-danger border border-danger-subtle me-1"><?php echo e($a); ?></span><?php endforeach; ?>
                <?php if ($day !== ''): ?><span class="badge bg-light text-dark border me-1"><i class="far fa-calendar me-1"></i><?php echo e(fmt_date($day)); ?></span><?php endif; ?>
                <a href="?<?php echo e(http_build_query(['search' => $search !== '' ? $search : null])); ?>" class="ms-1">Show all</a></div>
        <?php endif; ?>
    </div>
    <div class="card table-card overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small sticky-first hide-xs-3 hide-xs-6">
                <thead class="table-light"><tr><th class="ps-4">Date &amp; Time</th><th>User</th><th>Role</th><th>Action</th><th>Details</th><th>IP</th></tr></thead>
                <tbody>
                <?php if ($logs->num_rows > 0): while ($l = $logs->fetch_assoc()): ?>
                    <tr>
                        <td class="ps-4 text-nowrap"><?php echo e(fmt_datetime($l['created_at'])); ?></td>
                        <td class="fw-bold"><?php echo e($l['user_name'] ?: 'System'); ?></td>
                        <td><?php echo e($l['role']); ?></td>
                        <td><span class="badge bg-light text-dark border"><?php echo e($l['action']); ?></span></td>
                        <td class="cell-wrap"><?php echo e(log_details($l['details'])); ?></td>
                        <td class="text-muted cell-wrap" style="min-width:7rem"><?php echo e($l['ip_address']); ?></td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr><td colspan="6" class="text-center py-5 text-muted">No activity found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($total_pages > 1): ?>
    <nav class="mt-3"><ul class="pagination justify-content-center">
        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>"><a class="page-link" href="?<?php echo e(http_build_query(['page' => $i, 'search' => $search !== '' ? $search : null, 'action' => $action_param !== '' ? $action_param : null, 'day' => $day !== '' ? $day : null])); ?>"><?php echo $i; ?></a></li>
        <?php endfor; ?>
    </ul></nav>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
