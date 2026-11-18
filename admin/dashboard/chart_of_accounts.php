<?php
/**
 * Admin: Chart of Accounts (account heads ka tree).
 * Group head sirf totals ke liye; vouchers sirf sub-heads par post hote hain.
 * Istemal shuda head delete nahi hota (sirf Inactive); system heads ka type/status lock hai.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
$admin = require_login(['Admin']);

/** Form values validate karta hai; error ho to string return */
function read_account_form(&$d, $existing)
{
    $d = [
        'code'         => trim((string)($_POST['code'] ?? '')),
        'name'         => trim((string)($_POST['name'] ?? '')),
        'type'         => (string)($_POST['type'] ?? ''),
        'parent_id'    => (int)($_POST['parent_id'] ?? 0),
        'is_group'     => isset($_POST['is_group']) ? 1 : 0,
        'is_cash_bank' => isset($_POST['is_cash_bank']) ? 1 : 0,
        'status'       => ($_POST['status'] ?? '') === 'Inactive' ? 'Inactive' : 'Active',
    ];
    if (!preg_match('/^[A-Za-z0-9.\-]{1,20}$/', $d['code'])) return 'Code must be 1-20 characters (letters, digits, dot or dash).';
    if ($d['name'] === '' || strlen($d['name']) > 150) return 'Please enter a head name (maximum 150 characters).';

    if ($d['parent_id'] > 0) {
        $parent = db_row("SELECT * FROM accounts WHERE id = ?", [$d['parent_id']]);
        if (!$parent || (int)$parent['is_group'] !== 1) return 'The parent must be a group head.';
        if ($parent['status'] !== 'Active' && $d['status'] === 'Active') return 'The selected parent head is inactive.';
        $d['type'] = $parent['type']; // child ka type hamesha parent wala
        if ($existing) {
            // Head ko apne hi neeche (ya apne kisi child ke neeche) nahi rakh sakte
            for ($p = $parent; $p; $p = $p['parent_id'] ? db_row("SELECT * FROM accounts WHERE id = ?", [(int)$p['parent_id']]) : null) {
                if ((int)$p['id'] === (int)$existing['id']) return 'A head cannot be placed under itself or one of its sub-heads.';
            }
        }
    } else {
        $d['parent_id'] = null;
        if (!in_array($d['type'], ACCOUNT_TYPES, true)) return 'Please select an account type.';
    }
    if ($d['is_cash_bank'] && ($d['type'] !== 'Asset' || $d['is_group'])) return 'Only an Asset sub-head (not a group) can be marked as Cash/Bank.';

    if ($existing) {
        $id = (int)$existing['id'];
        $used = (int)db_value("SELECT COUNT(*) FROM voucher_lines WHERE account_id = ?", [$id], 0);
        $children = (int)db_value("SELECT COUNT(*) FROM accounts WHERE parent_id = ?", [$id], 0);
        $activeChildren = (int)db_value("SELECT COUNT(*) FROM accounts WHERE parent_id = ? AND status = 'Active'", [$id], 0);
        if ($existing['system_key']) {
            if ($d['type'] !== $existing['type']) return 'The type of a system head cannot be changed.';
            if ($d['is_group'] !== (int)$existing['is_group'] || $d['is_cash_bank'] !== (int)$existing['is_cash_bank']) return 'The group and Cash/Bank settings of a system head cannot be changed.';
            if ($d['status'] !== 'Active') return 'A system head is used by automatic vouchers and cannot be deactivated.';
        }
        if ($d['type'] !== $existing['type'] && ($used || $children)) return 'The type cannot be changed because this head already has vouchers or sub-heads.';
        if ($d['is_group'] && !(int)$existing['is_group'] && $used) return 'This head already has voucher entries, so it cannot become a group head.';
        if (!$d['is_group'] && (int)$existing['is_group'] && $children) return 'This group has sub-heads, so it must stay a group head.';
        if ($d['status'] === 'Inactive' && $activeChildren) return 'Deactivate its sub-heads first.';
    }
    return null;
}

// ---------- ADD / UPDATE ----------
if (isset($_POST['save_account'])) {
    $aid = (int)($_POST['account_id'] ?? 0);
    $existing = $aid ? db_row("SELECT * FROM accounts WHERE id = ?", [$aid]) : null;
    $err = ($aid && !$existing) ? 'Account head not found.' : read_account_form($d, $existing);
    if ($err) {
        flash('error', $err);
        redirect_self();
    }
    try {
        if ($existing) {
            db_exec("UPDATE accounts SET code = ?, name = ?, type = ?, parent_id = ?, is_group = ?, is_cash_bank = ?, status = ? WHERE id = ?",
                [$d['code'], $d['name'], $d['type'], $d['parent_id'], $d['is_group'], $d['is_cash_bank'], $d['status'], $aid]);
            audit('ACCOUNT_UPDATED', "{$d['code']} {$d['name']} ({$d['type']}, {$d['status']})");
            flash('success', "Account head {$d['code']} updated.");
        } else {
            db_exec("INSERT INTO accounts (code, name, type, parent_id, is_group, is_cash_bank, status) VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$d['code'], $d['name'], $d['type'], $d['parent_id'], $d['is_group'], $d['is_cash_bank'], $d['status']]);
            audit('ACCOUNT_ADDED', "{$d['code']} {$d['name']} ({$d['type']})");
            flash('success', "Account head {$d['code']} {$d['name']} added.");
        }
    } catch (mysqli_sql_exception $ex) {
        if (!is_duplicate_error($ex)) throw $ex;
        flash('error', "Code {$d['code']} is already used by another head.");
    }
    redirect_self();
}

// ---------- DELETE (sirf na-istemal shuda, non-system) ----------
if (isset($_POST['delete_id'])) {
    $acc = db_row("SELECT * FROM accounts WHERE id = ?", [(int)$_POST['delete_id']]);
    if (!$acc) {
        flash('error', 'Account head not found.');
    } elseif ($acc['system_key']) {
        flash('error', 'System heads cannot be deleted.');
    } else {
        try {
            db_exec("DELETE FROM accounts WHERE id = ?", [(int)$acc['id']]);
            audit('ACCOUNT_DELETED', "{$acc['code']} {$acc['name']}");
            flash('success', "Account head {$acc['code']} {$acc['name']} deleted.");
        } catch (mysqli_sql_exception $ex) {
            if (!is_fk_error($ex)) throw $ex;
            flash('error', "\"{$acc['name']}\" has sub-heads or voucher entries, so it cannot be deleted. Mark it Inactive instead.");
        }
    }
    redirect_self();
}

// ---------- PAGE DATA ----------
$tree = account_tree();
// Posted vouchers se har head ka balance (debit - credit); group heads mein children ka total
$net = [];
$res = db_select("SELECT vl.account_id, SUM(vl.debit) - SUM(vl.credit) AS net, COUNT(*) AS n
                  FROM voucher_lines vl JOIN vouchers v ON v.id = vl.voucher_id AND v.status = 'Posted'
                  GROUP BY vl.account_id");
while ($r = $res->fetch_assoc()) {
    $net[(int)$r['account_id']] = to_paisa($r['net']);
}
$used_ids = array_flip(array_map('intval', array_column(db_select("SELECT DISTINCT account_id FROM voucher_lines")->fetch_all(MYSQLI_ASSOC), 'account_id')));
$byId = [];
foreach ($tree as $a) {
    $byId[(int)$a['id']] = $a;
}
$rollup = [];
foreach ($tree as $a) {
    $amount = $net[(int)$a['id']] ?? 0;
    for ($p = (int)$a['id']; $p; $p = (int)$byId[$p]['parent_id']) {
        $rollup[$p] = ($rollup[$p] ?? 0) + $amount;
    }
}
$has_children = array_flip(array_filter(array_map(function ($a) { return (int)$a['parent_id']; }, $tree)));
$groups = array_filter($tree, function ($a) { return (int)$a['is_group'] === 1; });
$type_badge = ['Asset' => 'bg-primary', 'Liability' => 'bg-warning text-dark', 'Equity' => 'bg-secondary', 'Income' => 'bg-success', 'Expense' => 'bg-danger'];

$page_title = 'Chart of Accounts';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/admin_sidebar.php';
?>
<div class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h4 class="fw-bold mb-0"><i class="fas fa-sitemap me-2 text-primary"></i>Chart of Accounts</h4>
        <button class="btn btn-brand rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#accountModal" data-account=""><i class="fas fa-plus me-1"></i> Add Head</button>
    </div>
    <?php echo flash_render(); ?>

    <div class="card table-card overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 sticky-first hide-xs-4">
                <thead class="table-light"><tr><th class="ps-4">Code</th><th>Account Head</th><th>Type</th><th>Flags</th><th class="text-end">Balance (PKR)</th><th>Status</th><th class="text-center">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($tree as $a): $aid = (int)$a['id']; $is_group = (int)$a['is_group'] === 1;
                    $bal = $is_group ? ($rollup[$aid] ?? 0) : ($net[$aid] ?? 0);
                    $deletable = !$a['system_key'] && !isset($used_ids[$aid]) && !isset($has_children[$aid]); ?>
                    <tr class="<?php echo $is_group ? 'table-light' : ''; ?> <?php echo $a['status'] === 'Inactive' ? 'text-muted' : ''; ?>">
                        <td class="ps-4 font-monospace <?php echo $is_group ? 'fw-bold' : ''; ?>"><?php echo e($a['code']); ?></td>
                        <td style="padding-left: <?php echo 0.5 + 1.5 * (int)$a['depth']; ?>rem;" class="<?php echo $is_group ? 'fw-bold' : ''; ?>">
                            <i class="fas <?php echo $is_group ? 'fa-folder-open text-warning' : 'fa-file-alt text-secondary'; ?> me-2"></i><?php echo e($a['name']); ?>
                        </td>
                        <td><span class="badge <?php echo $type_badge[$a['type']]; ?>"><?php echo e($a['type']); ?></span></td>
                        <td class="small">
                            <?php if ($is_group): ?><span class="badge bg-light text-dark border">Group</span><?php endif; ?>
                            <?php if ((int)$a['is_cash_bank']): ?><span class="badge bg-info text-dark">Cash/Bank</span><?php endif; ?>
                            <?php if ($a['system_key']): ?><span class="badge bg-light text-secondary border" title="Used by automatic vouchers"><i class="fas fa-lock me-1"></i>System</span><?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap <?php echo $is_group ? 'fw-bold' : ''; ?>">
                            <?php if ($bal === 0): ?><span class="text-muted">-</span>
                            <?php else: ?><?php echo money(abs($bal) / 100); ?> <small class="text-muted"><?php echo $bal > 0 ? 'Dr' : 'Cr'; ?></small><?php endif; ?>
                        </td>
                        <td><span class="badge rounded-pill <?php echo $a['status'] === 'Active' ? 'bg-success' : 'bg-secondary'; ?>"><?php echo e($a['status']); ?></span></td>
                        <td class="text-center text-nowrap">
                            <button type="button" class="btn btn-sm btn-outline-primary rounded-circle me-1" title="Edit" data-bs-toggle="modal" data-bs-target="#accountModal"
                                    data-account="<?php echo e(json_encode(['id' => $aid, 'code' => $a['code'], 'name' => $a['name'], 'type' => $a['type'], 'parent_id' => (int)$a['parent_id'],
                                        'is_group' => (int)$a['is_group'], 'is_cash_bank' => (int)$a['is_cash_bank'], 'status' => $a['status'], 'system' => (bool)$a['system_key']])); ?>"><i class="fas fa-edit"></i></button>
                            <?php if ($deletable): ?>
                                <button type="button" class="btn btn-sm btn-outline-danger rounded-circle" title="Delete" data-confirm-post data-title="Delete account head?" data-confirm="Delete"
                                        data-text="<?php echo e('Delete "' . $a['code'] . ' ' . $a['name'] . '"?'); ?>"
                                        data-fields="<?php echo e(json_encode(['delete_id' => $aid])); ?>"><i class="fas fa-trash"></i></button>
                            <?php else: ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary rounded-circle" disabled title="<?php echo $a['system_key'] ? 'System head' : 'Has vouchers or sub-heads - mark Inactive instead'; ?>"><i class="fas fa-trash"></i></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <p class="small text-muted mt-2">Balance = posted vouchers only (Dr = debit balance, Cr = credit balance). Group heads show the total of their sub-heads. Heads that already have vouchers cannot be deleted; mark them Inactive instead.</p>
</div>

<div class="modal fade" id="accountModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content rounded-4 border-0 shadow">
        <div class="modal-header border-0"><h5 class="modal-title fw-bold" id="accountModalTitle">Add Account Head</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <form action="" method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="account_id" id="f_id" value="">
            <div class="modal-body">
                <div id="f_system_note" class="alert alert-info small py-2" hidden><i class="fas fa-lock me-1"></i>System head: used by automatic vouchers. You can rename it or change its code, but not its type or status.</div>
                <div class="row g-3">
                    <div class="col-4"><label class="form-label fw-semibold">Code <span class="req">*</span></label><input type="text" name="code" id="f_code" class="form-control font-monospace" maxlength="20" pattern="[A-Za-z0-9.\-]{1,20}" required></div>
                    <div class="col-8"><label class="form-label fw-semibold">Head Name <span class="req">*</span></label><input type="text" name="name" id="f_name" class="form-control" maxlength="150" required></div>
                    <div class="col-12"><label class="form-label fw-semibold">Parent Head</label>
                        <select name="parent_id" id="f_parent" class="form-select">
                            <option value="0" data-type="">- None (top level) -</option>
                            <?php foreach ($groups as $g): ?>
                                <option value="<?php echo (int)$g['id']; ?>" data-type="<?php echo e($g['type']); ?>"><?php echo str_repeat('&nbsp;&nbsp;&nbsp;', (int)$g['depth']) . e($g['code'] . ' - ' . $g['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6"><label class="form-label fw-semibold">Type <span class="req">*</span></label>
                        <select name="type" id="f_type" class="form-select" required>
                            <?php foreach (ACCOUNT_TYPES as $t): ?><option value="<?php echo $t; ?>"><?php echo $t; ?></option><?php endforeach; ?>
                        </select>
                        <div class="form-text" id="f_type_hint" hidden>Taken from the parent head.</div>
                    </div>
                    <div class="col-6"><label class="form-label fw-semibold">Status</label>
                        <select name="status" id="f_status" class="form-select"><option value="Active">Active</option><option value="Inactive">Inactive</option></select>
                    </div>
                    <div class="col-12 d-flex gap-4 flex-wrap">
                        <label><input type="checkbox" name="is_group" id="f_group" value="1"> Group head (totals only)</label>
                        <label><input type="checkbox" name="is_cash_bank" id="f_cash" value="1"> Cash / Bank account</label>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0"><button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button><button type="submit" name="save_account" class="btn btn-brand rounded-pill px-4">Save</button></div>
        </form>
    </div></div>
</div>
<script>
(function () {
    const $ = function (id) { return document.getElementById(id); };
    // Parent select ho to type wahi hota hai (server bhi yehi karta hai)
    function syncType() {
        const opt = $('f_parent').selectedOptions[0];
        const t = opt ? opt.getAttribute('data-type') : '';
        if (t) { $('f_type').value = t; }
        $('f_type').disabled = !!t || $('f_type').dataset.locked === '1';
        $('f_type_hint').hidden = !t;
    }
    $('f_parent').addEventListener('change', syncType);
    // Disabled select submit nahi hota - bhejne se pehle enable karein
    $('f_type').form.addEventListener('submit', function () { $('f_type').disabled = false; });
    $('accountModal').addEventListener('show.bs.modal', function (ev) {
        const raw = ev.relatedTarget ? ev.relatedTarget.getAttribute('data-account') : '';
        const a = raw ? JSON.parse(raw) : null;
        $('accountModalTitle').textContent = a ? 'Edit Account Head' : 'Add Account Head';
        $('f_id').value = a ? a.id : '';
        $('f_code').value = a ? a.code : '';
        $('f_name').value = a ? a.name : '';
        $('f_parent').value = a ? String(a.parent_id) : '0';
        $('f_type').value = a ? a.type : 'Expense';
        $('f_status').value = a ? a.status : 'Active';
        $('f_group').checked = a ? a.is_group === 1 : false;
        $('f_cash').checked = a ? a.is_cash_bank === 1 : false;
        const sys = !!(a && a.system);
        $('f_system_note').hidden = !sys;
        $('f_type').dataset.locked = sys ? '1' : '0';
        // System head: disabled checkbox submit nahi hota, is liye onclick se lock karte hain
        [$('f_group'), $('f_cash')].forEach(function (c) { c.onclick = sys ? function () { return false; } : null; });
        $('f_status').querySelector('option[value="Inactive"]').disabled = sys;
        // Head apne hi neeche nahi ja sakta
        Array.prototype.forEach.call($('f_parent').options, function (o) { o.disabled = a !== null && o.value === String(a.id); });
        syncType();
    });
})();
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
