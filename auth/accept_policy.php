<?php
/**
 * Acceptable Use Policy: Student/Staff pehli login par accept karte hain (users.accepted_policy_at).
 * Jab tak accept na karein, require_login() har protected page se yahan bhejta hai (sirf yeh page, change_password.php aur logout khule hain).
 * Pehli login ka order: temporary password badlo -> policy accept -> dashboard.
 * Accept: checkbox lazmi (button tab tak disabled; server bhi POST reject karta hai). Decline: logout.php (POST + CSRF) -> POLICY_DECLINED.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
$user = require_login(POLICY_ROLES);

if (!policy_required($user)) {
    redirect(dashboard_url($user['role']));
}

if (isset($_POST['accept_policy_btn'])) {
    if (($_POST['agree'] ?? '') !== '1') {
        flash('error', 'Please tick the box to accept the policy.');
        redirect_self();
    }
    db_exec("UPDATE users SET accepted_policy_at = NOW() WHERE id = ? AND accepted_policy_at IS NULL", [(int)$user['id']]);
    audit('POLICY_ACCEPTED', 'Accepted the Acceptable Use Policy');
    flash('success', 'Thank you. You can now use the portal.');
    redirect(dashboard_url($user['role']));
}

$page_title = 'Acceptable Use Policy';
$body_class = 'd-flex align-items-center justify-content-center min-vh-100 px-3';
include __DIR__ . '/../includes/header.php';
?>
<div class="bg-white shadow p-4 p-md-5 rounded-4 my-4" style="width:100%;max-width:640px;border-top:5px solid #1e3a8a;">
    <div class="text-center mb-4">
        <i class="fas fa-file-signature fa-3x text-primary mb-2"></i>
        <h4 class="fw-bold mb-0">Acceptable Use Policy</h4>
        <p class="text-muted small mb-0"><?php echo e(APP_NAME . ' - ' . APP_ORG); ?></p>
    </div>
    <?php echo flash_render(); ?>
    <p class="small">Hello <strong><?php echo e($user['full_name']); ?></strong>. Before using the <?php echo e($user['role']); ?> portal for the first time, please read and accept these rules.</p>
    <ol class="small border rounded-3 bg-light py-3 pe-3" style="max-height:320px;overflow-y:auto;">
        <li class="mb-2">This portal is for official use by students and staff of the University of Science &amp; Technology Bannu only.</li>
        <li class="mb-2">Keep your password secret. Do not share your account or let anyone else use it. You are responsible for all activity under your account.</li>
        <li class="mb-2">Use only the pages and information meant for you. Do not try to open other people's records, receipts or salary slips, or pages of other portals.</li>
        <li class="mb-2">Do not try to bypass security, guess passwords, or disrupt the system. Repeated attempts to open pages you are not allowed to use lock your account automatically.</li>
        <li class="mb-2">Unauthorized access is prohibited. All activity is logged (time, account and IP address) and may be reviewed by the university.</li>
        <li class="mb-2">Report a lost password, a wrong fee or salary record, or any suspicious activity to the Finance Department.</li>
        <li>Misuse may lead to your account being disabled and to disciplinary action under university rules.</li>
    </ol>
    <form method="POST" action="">
        <?php echo csrf_field(); ?>
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="agree" value="1" id="agreePolicy" required>
            <label class="form-check-label small fw-semibold" for="agreePolicy">I have read and accept the Acceptable Use Policy.</label>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <button type="submit" name="accept_policy_btn" id="acceptPolicyBtn" class="btn btn-brand rounded-pill px-4 fw-bold" disabled><i class="fas fa-check me-1"></i>Accept and continue</button>
            <!-- Alag form (form attribute): logout POST + CSRF, server POLICY_DECLINED log karta hai -->
            <button type="submit" form="declinePolicyForm" class="btn btn-outline-secondary rounded-pill px-4">Decline and log out</button>
        </div>
    </form>
    <form method="POST" action="<?php echo e(url('auth/logout.php')); ?>" id="declinePolicyForm">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="decline_policy" value="1">
    </form>
</div>
<script>
// "Accept and continue" tab tak disabled jab tak checkbox tick na ho (server bhi check karta hai)
(function () {
    var box = document.getElementById('agreePolicy'), btn = document.getElementById('acceptPolicyBtn');
    function sync() { btn.disabled = !box.checked; }
    box.addEventListener('change', sync);
    window.addEventListener('pageshow', sync);
    sync();
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
