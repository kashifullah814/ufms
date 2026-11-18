<?php
/**
 * Active reminders ki list. Set karein: $rem_user (current_user() ya null = landing page).
 * Landing page aur Student / Staff / Accountant dashboards isko include karte hain. Koi na ho to kuch nahi dikhta.
 */
$rem_rows = dashboard_reminders($rem_user ?? null);
if (!$rem_rows) {
    return;
}
// "Seen by X of N": logged-in user ne yeh reminders ab dekh liye (sirf pehli dafa likha jata hai)
if (!empty($rem_user['id'])) {
    mark_reminders_seen((int)$rem_user['id'], array_column($rem_rows, 'id'));
}
?>
<div class="card table-card p-4 mb-4 reminders-panel">
    <h5 class="fw-bold mb-3"><i class="fas fa-bell me-2 text-primary"></i>Reminders</h5>
    <div class="d-flex flex-column gap-3">
        <?php foreach ($rem_rows as $rem): $fee = $rem['type'] === 'Fee'; ?>
            <div class="border-start border-4 <?php echo $fee ? 'border-danger' : ($rem['is_pinned'] ? 'border-warning' : 'border-primary'); ?> ps-3">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-1">
                    <h6 class="fw-bold mb-1"><?php if ($rem['is_pinned']): ?><i class="fas fa-thumbtack text-warning me-1" title="Pinned"></i><?php endif; ?><?php echo e($rem['title']); ?></h6>
                    <small class="text-muted text-nowrap"><i class="far fa-calendar me-1"></i><?php echo e(fmt_date($rem['start_date'])); ?></small>
                </div>
                <p class="small mb-0 text-secondary"><?php echo nl2br(e($rem['body'])); ?></p>
                <?php if ($fee): ?>
                    <div class="small mt-1"><span class="badge bg-danger me-1">Fee reminder</span>Balance <strong>PKR&nbsp;<?php echo e($rem['balance']); ?></strong> &middot; pay by <strong><?php echo e(fmt_date($rem['due_date'])); ?></strong></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>
