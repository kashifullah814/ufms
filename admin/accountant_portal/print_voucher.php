<?php
/**
 * Voucher print / PDF (RV, PV, JV) - standard format, amount in words, signatures.
 * Admin manual JV ko cancel kar sakta hai (reason ke saath). Automatic vouchers apne
 * source page (fees, salaries, expenses) se hi cancel hote hain.
 * Permission: accounts
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
$user = require_permission('accounts');
$is_admin = $user['role'] === 'Admin';
$vid = (int)($_GET['id'] ?? 0);

$v = db_row("SELECT v.*, cu.full_name AS created_by_name, xu.full_name AS cancelled_by_name
             FROM vouchers v LEFT JOIN users cu ON cu.id = v.created_by LEFT JOIN users xu ON xu.id = v.cancelled_by
             WHERE v.id = ?", [$vid]);
if (!$v) {
    flash('error', 'Voucher not found.');
    redirect(url('admin/accountant_portal/vouchers.php'));
}
$can_cancel = $is_admin && $v['source_type'] === 'manual' && $v['status'] === 'Posted';

// ---------- CANCEL MANUAL JV ----------
if (isset($_POST['cancel_voucher'])) {
    $reason = trim((string)($_POST['cancel_reason'] ?? ''));
    if (!$can_cancel) {
        flash('error', 'This voucher cannot be cancelled here.');
    } elseif (strlen($reason) < 5 || strlen($reason) > 255) {
        flash('error', 'Please give a reason for cancelling (5-255 characters).');
    } elseif (cancel_voucher($vid, $reason)) {
        audit('VOUCHER_CANCELLED', "{$v['voucher_no']}: {$reason}");
        flash('success', "Voucher {$v['voucher_no']} cancelled. It stays on record but no longer counts in reports.");
    }
    redirect_self();
}

$lines = db_select("SELECT vl.*, a.code, a.name FROM voucher_lines vl JOIN accounts a ON a.id = vl.account_id
                    WHERE vl.voucher_id = ? ORDER BY vl.debit = 0, vl.id", [$vid])->fetch_all(MYSQLI_ASSOC);
$total_dr = array_sum(array_map('to_paisa', array_column($lines, 'debit')));
$total_cr = array_sum(array_map('to_paisa', array_column($lines, 'credit')));
$cancelled = $v['status'] === 'Cancelled';

$page_title = $v['voucher_no'];
$no_animations = true;  // print page
include __DIR__ . '/../../includes/header.php';
?>
<style>
    #voucher { position: relative; overflow: hidden; }
    .cancel-stamp { position: absolute; top: 38%; left: 50%; transform: translate(-50%, -50%) rotate(-25deg); font-size: clamp(2rem, 11vw, 5rem); font-weight: 800; white-space: nowrap;
                    color: rgba(220, 53, 69, .18); border: 8px solid rgba(220, 53, 69, .18); padding: 0 30px; pointer-events: none; letter-spacing: 6px; }
    #voucher .table td, #voucher .table th { padding: .45rem .6rem; }
</style>
<div class="container py-4" style="max-width:900px;">
    <div class="d-flex justify-content-between mb-3 no-print flex-wrap gap-2">
        <div class="d-flex gap-2 flex-wrap">
            <a href="vouchers.php" class="btn btn-secondary rounded-pill px-4"><i class="fas fa-arrow-left me-1"></i> Back</a>
            <?php $dl = dashboard_link(); if ($dl['url'] !== url('admin/accountant_portal/vouchers.php')): ?><a href="<?php echo e($dl['url']); ?>" class="btn btn-outline-secondary rounded-pill px-4"><i class="fas fa-home me-1"></i> <?php echo e($dl['label']); ?></a><?php endif; ?>
        </div>
        <div class="d-flex gap-2">
            <?php if ($can_cancel): ?>
                <button class="btn btn-outline-danger rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#cancelModal"><i class="fas fa-ban me-1"></i> Cancel Voucher</button>
            <?php endif; ?>
            <button onclick="window.print()" class="btn btn-primary rounded-pill px-4"><i class="fas fa-print me-1"></i> Print</button>
            <button onclick="downloadPdf()" class="btn btn-danger rounded-pill px-4"><i class="fas fa-file-pdf me-1"></i> Download PDF</button>
        </div>
    </div>
    <div class="no-print"><?php echo flash_render(); ?></div>

    <div id="voucher" class="bg-white p-4 p-md-5 shadow-sm border rounded">
        <?php if ($cancelled): ?><div class="cancel-stamp" data-watermark>CANCELLED</div><?php endif; ?>
        <div class="text-center border-bottom pb-3 mb-4">
            <?php echo doc_logo(); ?><h3 class="fw-bold mb-0 doc-title">UNIVERSITY OF SCIENCE &amp; TECHNOLOGY BANNU</h3>
            <p class="text-muted mb-1">Finance Department</p>
            <h5 class="fw-bold text-uppercase mb-0"><?php echo e(VOUCHER_TYPES[$v['voucher_type']]); ?></h5>
        </div>
        <div class="row mb-3">
            <div class="col-6">
                <p class="mb-1"><strong>Voucher No:</strong> <span class="font-monospace"><?php echo e($v['voucher_no']); ?></span></p>
                <p class="mb-1"><strong>Fiscal Year:</strong> <?php echo e(fiscal_year_label($v['fiscal_year'])); ?></p>
                <p class="mb-0"><strong>Source:</strong> <?php echo e(VOUCHER_SOURCES[$v['source_type']] ?? $v['source_type']); ?></p>
            </div>
            <div class="col-6 text-end">
                <p class="mb-1"><strong>Voucher Date:</strong> <?php echo e(fmt_date($v['voucher_date'])); ?></p>
                <p class="mb-1"><strong>Status:</strong> <span class="<?php echo $cancelled ? 'text-danger fw-bold' : 'text-success fw-bold'; ?>"><?php echo e($v['status']); ?></span></p>
                <p class="mb-0"><strong>Printed:</strong> <?php echo e(fmt_date(date('Y-m-d'))); ?></p>
            </div>
        </div>
        <p class="mb-3"><strong>Narration:</strong> <?php echo e($v['narration']); ?></p>

        <div class="table-responsive screen-scroll-only">
        <table class="table table-bordered align-middle">
            <thead class="table-light"><tr><th style="width:5%">#</th><th style="width:12%">Code</th><th>Account Head / Particulars</th><th class="text-end" style="width:17%">Debit (PKR)</th><th class="text-end" style="width:17%">Credit (PKR)</th></tr></thead>
            <tbody>
            <?php foreach ($lines as $i => $l): ?>
                <tr>
                    <td><?php echo $i + 1; ?></td>
                    <td class="font-monospace"><?php echo e($l['code']); ?></td>
                    <td><div class="fw-semibold"><?php echo e($l['name']); ?></div><?php if ($l['line_narration']): ?><small class="text-muted"><?php echo e($l['line_narration']); ?></small><?php endif; ?></td>
                    <td class="text-end"><?php echo (float)$l['debit'] > 0 ? money($l['debit']) : ''; ?></td>
                    <td class="text-end"><?php echo (float)$l['credit'] > 0 ? money($l['credit']) : ''; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="fw-bold table-light"><td colspan="3" class="text-end">Total</td><td class="text-end"><?php echo money($total_dr / 100); ?></td><td class="text-end"><?php echo money($total_cr / 100); ?></td></tr>
            </tfoot>
        </table>
        </div>
        <p class="mb-4"><strong>Amount in words:</strong> <?php echo e(amount_in_words($v['total_amount'])); ?></p>

        <?php if ($cancelled): ?>
            <div class="alert alert-danger py-2 small">
                <strong>Cancelled</strong> on <?php echo e(fmt_datetime($v['cancelled_at'])); ?>
                <?php if ($v['cancelled_by_name']): ?>by <?php echo e($v['cancelled_by_name']); ?><?php endif; ?>.
                Reason: <?php echo e($v['cancel_reason']); ?>
            </div>
        <?php endif; ?>

        <div class="row mt-5 pt-4 text-center small">
            <div class="col-4"><p class="border-top pt-2 mx-2 mb-0">Prepared By</p><span class="text-muted"><?php echo e($v['created_by_name'] ?: '-'); ?></span></div>
            <div class="col-4"><p class="border-top pt-2 mx-2 mb-0">Checked By</p><span class="text-muted">Accounts Officer</span></div>
            <div class="col-4"><p class="border-top pt-2 mx-2 mb-0">Approved By</p><span class="text-muted">Treasurer</span></div>
        </div>
        <p class="text-center text-muted small mt-4 mb-0">This is a computer generated voucher.</p>
    </div>
</div>

<?php if ($can_cancel): ?>
<div class="modal fade no-print" id="cancelModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content rounded-4 border-0 shadow">
        <div class="modal-header border-0"><h5 class="modal-title fw-bold text-danger"><i class="fas fa-ban me-2"></i>Cancel <?php echo e($v['voucher_no']); ?>?</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <form action="" method="POST">
            <?php echo csrf_field(); ?>
            <div class="modal-body">
                <p class="small text-muted">The voucher stays on record, stamped CANCELLED, but will no longer count in the Trial Balance or reports. This cannot be undone; post a new JV if needed.</p>
                <label class="form-label fw-semibold">Reason <span class="req">*</span></label>
                <textarea name="cancel_reason" class="form-control" rows="2" minlength="5" maxlength="255" required></textarea>
            </div>
            <div class="modal-footer border-0"><button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Close</button><button type="submit" name="cancel_voucher" class="btn btn-danger rounded-pill px-4">Cancel Voucher</button></div>
        </form>
    </div></div>
</div>
<?php endif; ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
function downloadPdf() {
    html2pdf().set({ margin: 10, filename: <?php echo json_encode($v['voucher_no'] . '.pdf'); ?>, html2canvas: { scale: 2 }, jsPDF: { format: 'a4' } })
        .from(document.getElementById('voucher')).save();
}
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
