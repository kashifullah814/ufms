<?php
/**
 * Budgets: fiscal year ke hisaab se list; naya (Original) budget ya active budget ka Revised draft banana.
 * Lines, submit aur approval stages budget_detail.php par. Permission: accounts
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
$user = require_permission('accounts');
$fy_now = fiscal_year_of(date('Y-m-d'));
$fy_options = [$fy_now - 1, $fy_now, $fy_now + 1];

// ---------- NEW ORIGINAL BUDGET ----------
if (isset($_POST['create_budget'])) {
    $fy = (int)($_POST['fiscal_year'] ?? 0);
    $title = trim((string)($_POST['title'] ?? ''));
    $err = null;
    if (!in_array($fy, $fy_options, true)) $err = 'Please select a valid fiscal year.';
    elseif ($title === '' || strlen($title) > 150) $err = 'Please enter a title (maximum 150 characters).';
    elseif ($other = db_row("SELECT id, status FROM budgets WHERE fiscal_year = ? AND budget_type = 'Original'", [$fy])) {
        $err = 'FY ' . fiscal_year_label($fy) . ' already has an original budget (' . budget_status_label($other['status']) . '). Open it, or create a Revised budget once it is approved.';
    }
    if ($err) {
        flash('error', $err);
        redirect_self();
    }
    db_exec("INSERT INTO budgets (fiscal_year, budget_type, title, created_by) VALUES (?, 'Original', ?, ?)", [$fy, $title, (int)$user['id']]);
    $bid = db_insert_id();
    audit('BUDGET_CREATED', "{$title} (FY " . fiscal_year_label($fy) . ', Original)');
    flash('success', 'Draft budget created. Enter the amount for each head and save, then submit it to F&PC.');
    redirect(url('admin/accountant_portal/budget_detail.php?id=' . $bid));
}

// ---------- REVISED BUDGET (active ki copy) ----------
if (isset($_POST['revise_id'])) {
    $base = db_row("SELECT * FROM budgets WHERE id = ? AND is_active = 1", [(int)$_POST['revise_id']]);
    $busy = $base ? db_row("SELECT id, title, status FROM budgets WHERE fiscal_year = ? AND budget_type = 'Revised' AND status IN ('" . implode("','", BUDGET_IN_PROGRESS) . "')", [(int)$base['fiscal_year']]) : null;
    if (!$base) {
        flash('error', 'Only the active (Senate-approved) budget can be revised.');
        redirect_self();
    }
    if ($busy) {
        flash('error', "A revision for this fiscal year is already in progress (\"{$busy['title']}\", " . budget_status_label($busy['status']) . ').');
        redirect(url('admin/accountant_portal/budget_detail.php?id=' . (int)$busy['id']));
    }
    $n = 1 + (int)db_value("SELECT COUNT(*) FROM budgets WHERE fiscal_year = ? AND budget_type = 'Revised'", [(int)$base['fiscal_year']], 0);
    $title = substr('Revised Budget ' . ($n > 1 ? "#{$n} " : '') . 'FY ' . fiscal_year_label($base['fiscal_year']), 0, 150);
    db()->begin_transaction();
    db_exec("INSERT INTO budgets (fiscal_year, budget_type, revision_of, title, created_by) VALUES (?, 'Revised', ?, ?, ?)",
        [(int)$base['fiscal_year'], (int)$base['id'], $title, (int)$user['id']]);
    $bid = db_insert_id();
    db_exec("INSERT INTO budget_lines (budget_id, account_id, amount, remarks) SELECT ?, account_id, amount, remarks FROM budget_lines WHERE budget_id = ?", [$bid, (int)$base['id']]);
    db()->commit();
    audit('BUDGET_CREATED', "{$title} (revision of #{$base['id']})");
    flash('success', 'Revised budget created as a copy of the approved budget. Change the amounts, save, then submit it to F&PC.');
    redirect(url('admin/accountant_portal/budget_detail.php?id=' . $bid));
}

$budgets = db_select("SELECT b.*, u.full_name AS created_by_name,
                             COALESCE(SUM(CASE WHEN a.type = 'Income' THEN bl.amount END), 0) AS receipts,
                             COALESCE(SUM(CASE WHEN a.type = 'Expense' THEN bl.amount END), 0) AS payments
                      FROM budgets b LEFT JOIN users u ON u.id = b.created_by
                      LEFT JOIN budget_lines bl ON bl.budget_id = b.id LEFT JOIN accounts a ON a.id = bl.account_id
                      GROUP BY b.id ORDER BY b.fiscal_year DESC, b.id DESC")->fetch_all(MYSQLI_ASSOC);
$by_fy = [];
foreach ($budgets as $b) {
    $by_fy[(int)$b['fiscal_year']][] = $b;
}

$page_title = 'Budgets';
$nav_title = 'Budgets';
$nav_icon = 'fa-clipboard-list';
$nav_back = 'dashboard';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/topnav.php';
?>
<div class="container pb-5">
    <?php echo flash_render(); ?>
    <div class="row g-4">
        <div class="col-lg-4 split-form">
            <div class="card form-card mb-4">
                <div class="card-header text-white" style="background:#1e3a8a;"><h6 class="mb-0 py-1"><i class="fas fa-plus-circle me-2"></i>New Budget</h6></div>
                <div class="card-body p-4">
                    <form action="" method="POST">
                        <?php echo csrf_field(); ?>
                        <div class="mb-3"><label class="form-label fw-semibold small">FISCAL YEAR (JULY - JUNE) <span class="req">*</span></label>
                            <select name="fiscal_year" class="form-select" id="fySel" required>
                                <?php foreach ($fy_options as $fy): ?><option value="<?php echo $fy; ?>" <?php echo $fy === $fy_now ? 'selected' : ''; ?>><?php echo e('FY ' . fiscal_year_label($fy)); ?></option><?php endforeach; ?>
                            </select></div>
                        <div class="mb-3"><label class="form-label fw-semibold small">TITLE <span class="req">*</span></label><input type="text" name="title" id="titleIn" class="form-control" maxlength="150" value="<?php echo e('Budget Estimates FY ' . fiscal_year_label($fy_now)); ?>" required></div>
                        <button type="submit" name="create_budget" class="btn btn-brand w-100 rounded-pill">Create Draft</button>
                    </form>
                </div>
            </div>
            <div class="card border-0 shadow-sm rounded-4 p-3 small">
                <h6 class="fw-bold"><i class="fas fa-route me-2 text-primary"></i>Approval workflow</h6>
                <ol class="mb-2 ps-3">
                    <li>Accounts prepares the <strong>Draft</strong> and submits it to <strong>F&amp;PC</strong>.</li>
                    <li>Admin records the <strong>F&amp;PC</strong> decision, then <strong>Syndicate</strong>, then <strong>Senate</strong> (meeting date, reference no., minutes PDF).</li>
                    <li>Only the <strong>Senate-approved</strong> budget is active and used in the Utilization report.</li>
                </ol>
                <p class="mb-0 text-muted">Any forum can return the budget for revision. Mid-year, create a <strong>Revised</strong> budget from the active one; it replaces the old one once the Senate approves it.</p>
            </div>
        </div>
        <div class="col-lg-8 split-list">
            <?php if (!$by_fy): ?>
                <div class="card table-card p-5 text-center text-muted">No budgets yet. Create the first draft on the left.</div>
            <?php endif; ?>
            <?php foreach ($by_fy as $fy => $list): ?>
            <div class="card table-card p-3 mb-4">
                <h6 class="fw-bold mb-3"><i class="fas fa-calendar-alt me-2 text-primary"></i>FY <?php echo e(fiscal_year_label($fy)); ?> <small class="text-muted fw-normal">(<?php echo e(fmt_date(fiscal_year_range($fy)[0])); ?> - <?php echo e(fmt_date(fiscal_year_range($fy)[1])); ?>)</small></h6>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 small">
                        <thead class="table-light"><tr><th>Budget</th><th>Status</th><th class="text-end">Receipts</th><th class="text-end">Payments</th><th class="text-end">Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($list as $b): ?>
                            <tr class="<?php echo $b['status'] === 'Superseded' ? 'text-muted' : ''; ?>">
                                <td><a href="budget_detail.php?id=<?php echo (int)$b['id']; ?>" class="fw-bold text-decoration-none"><?php echo e($b['title']); ?></a>
                                    <br><span class="badge bg-light text-dark border"><?php echo e($b['budget_type']); ?></span>
                                    <?php if ((int)$b['is_active']): ?><span class="badge bg-success"><i class="fas fa-check me-1"></i>Active</span><?php endif; ?>
                                    <small class="text-muted">by <?php echo e($b['created_by_name'] ?: '-'); ?></small></td>
                                <td><span class="badge <?php echo budget_status_badge($b['status']); ?>"><?php echo e(budget_status_label($b['status'])); ?></span></td>
                                <td class="text-end text-success"><?php echo money($b['receipts']); ?></td>
                                <td class="text-end text-danger"><?php echo money($b['payments']); ?></td>
                                <td class="text-end text-nowrap">
                                    <a href="budget_detail.php?id=<?php echo (int)$b['id']; ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3">Open</a>
                                    <?php if ((int)$b['is_active']): ?>
                                        <button type="button" class="btn btn-sm btn-outline-warning rounded-pill px-3" data-confirm-post data-kind="info" data-fa="fa-code-branch" data-title="Create a revised budget?" data-confirm="Create revision"
                                                data-text="A Revised draft will be created as a copy of this approved budget. It must go through F&amp;PC, Syndicate and Senate again."
                                                data-fields="<?php echo e(json_encode(['revise_id' => (int)$b['id']])); ?>">Revise</button>
                                        <a href="utilization.php?fy=<?php echo (int)$b['fiscal_year']; ?>" class="btn btn-sm btn-outline-success rounded-pill px-3">Utilization</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<script>
// FY badalne par default title bhi badle (agar user ne khud na likha ho)
(function () {
    const fy = document.getElementById('fySel'), t = document.getElementById('titleIn');
    const label = function (y) { return y + '-' + String((+y + 1) % 100).padStart(2, '0'); };
    fy.addEventListener('change', function () { if (/^Budget Estimates FY \d{4}-\d{2}$/.test(t.value)) t.value = 'Budget Estimates FY ' + label(fy.value); });
})();
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
