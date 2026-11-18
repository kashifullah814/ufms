<?php
/**
 * Dashboard panel: current FY ke payment heads jo budget ka 80% ya 100% cross kar chuke.
 * Admin ko pending budget faisle bhi dikhte hain. Admin dashboard aur accountant portal isko include karte hain
 * (sirf un users ke liye jin ke paas accounts permission ho).
 */
if (!function_exists('budget_alerts') || !can('accounts')) {
    return;
}
$ba = budget_alerts();
$ba_util_url = url('admin/accountant_portal/utilization.php?fy=' . (int)$ba['fy']);
$ba_pending = (current_user()['role'] ?? '') === 'Admin'
    ? db_select("SELECT id, title, status FROM budgets WHERE status IN ('Submitted', 'FPC Approved', 'Syndicate Approved') ORDER BY id")->fetch_all(MYSQLI_ASSOC) : [];
?>
<div class="card table-card p-4 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h5 class="fw-bold mb-0"><i class="fas fa-bell me-2 text-warning"></i>Budget Alerts <small class="text-muted fs-6">FY <?php echo e(fiscal_year_label($ba['fy'])); ?></small></h5>
        <a href="<?php echo e($ba_util_url); ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3">Utilization report</a>
    </div>
    <?php foreach ($ba_pending as $p): ?>
        <div class="alert alert-info py-2 small mb-2"><i class="fas fa-gavel me-1"></i>
            <a href="<?php echo e(url('admin/accountant_portal/budget_detail.php?id=' . (int)$p['id'])); ?>" class="fw-bold"><?php echo e($p['title']); ?></a>
            is waiting for the <strong><?php echo e(BUDGET_STAGES[$p['status']]['stage']); ?></strong> decision.</div>
    <?php endforeach; ?>
    <?php if (!$ba['budget']): ?>
        <p class="small text-muted mb-0"><i class="fas fa-info-circle me-1"></i>No Senate-approved budget for this fiscal year yet, so spending cannot be compared. <a href="<?php echo e(url('admin/accountant_portal/budgets.php')); ?>">Open Budgets</a>.
        <?php $unbudgeted = count($ba['alerts']); if ($unbudgeted): ?><br><?php echo $unbudgeted; ?> payment head(s) already have spending.<?php endif; ?></p>
    <?php elseif (!$ba['alerts']): ?>
        <p class="small text-success mb-0"><i class="fas fa-check-circle me-1"></i>All payment heads are below <?php echo BUDGET_WARN_PCT; ?>% of their budget.</p>
    <?php else: ?>
        <div class="d-flex flex-column gap-2">
        <?php foreach (array_slice($ba['alerts'], 0, 6) as $a): $ex = $a['level'] === 'exceeded'; $w = $a['pct'] === null ? 100 : min(100, $a['pct']); ?>
            <div>
                <div class="d-flex justify-content-between small">
                    <span><span class="badge <?php echo $ex ? 'bg-danger' : 'bg-warning text-dark'; ?> me-1"><?php echo $ex ? ($a['budget'] > 0 ? 'Exceeded' : 'No budget') : 'Warning'; ?></span><?php echo e($a['code'] . ' ' . $a['name']); ?></span>
                    <span class="fw-semibold"><?php echo money($a['actual'] / 100, 0); ?> / <?php echo money($a['budget'] / 100, 0); ?><?php echo $a['pct'] !== null ? ' (' . $a['pct'] . '%)' : ''; ?></span>
                </div>
                <div class="progress" style="height:6px"><div class="progress-bar <?php echo $ex ? 'bg-danger' : 'bg-warning'; ?>" style="width:<?php echo $w; ?>%"></div></div>
            </div>
        <?php endforeach; ?>
        <?php if (count($ba['alerts']) > 6): ?><a href="<?php echo e($ba_util_url); ?>" class="small">+ <?php echo count($ba['alerts']) - 6; ?> more</a><?php endif; ?>
        </div>
    <?php endif; ?>
</div>
