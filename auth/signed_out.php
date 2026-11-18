<?php
/**
 * Logout ke baad "Logged out" page. Naam/portal sirf ek dafa (session flash) - refresh ya seedha kholne par landing page.
 * Login ki zaroorat nahi; no-cache. 5 second baad landing page par.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
no_cache_headers();

$user = current_user();
if ($user && $user['status'] === 'Active') {
    redirect(dashboard_url($user['role']));
}
$info = $_SESSION['signed_out'] ?? null;
unset($_SESSION['signed_out']);
if (!is_array($info)) {
    redirect(url('index.php'));
}
$portal = valid_portal($info['portal'] ?? '');
$color = $portal !== '' ? LOGIN_PORTALS[$portal]['color'] : '#1e3a8a';
$name = trim((string)($info['name'] ?? ''));
$home = url('index.php');
$seconds = 5;

$page_title = 'Logged out';
$body_class = 'd-flex align-items-center justify-content-center min-vh-100 px-3';
include __DIR__ . '/../includes/header.php';
?>
<style>
    .portal-btn { background: <?php echo e($color); ?>; color: #fff; }
    .portal-btn:hover, .portal-btn:focus { background: <?php echo e($color); ?>; color: #fff; filter: brightness(1.12); }
</style>
<noscript><meta http-equiv="refresh" content="<?php echo $seconds; ?>;url=<?php echo e($home); ?>"></noscript>
<div class="bg-white shadow p-4 p-md-5 rounded-4 my-4 text-center" style="width:100%;max-width:440px;border-top:5px solid <?php echo e($color); ?>;">
    <span class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3 text-white" style="width:80px;height:80px;background:<?php echo e($color); ?>;"><i class="fas fa-check fa-2x"></i></span>
    <h4 class="fw-bold mb-1">You have been logged out</h4>
    <p class="text-muted mb-3">Thank you for using <?php echo e(APP_NAME); ?><?php echo $name !== '' ? ', <strong>' . e($name) . '</strong>' : ''; ?>.</p>
    <div class="alert alert-light border small text-start mb-4"><i class="fas fa-shield-alt me-2 text-secondary"></i>For your security, close the browser if this is a shared computer.</div>
    <div class="d-flex gap-2 justify-content-center flex-wrap mb-3">
        <a href="<?php echo e(login_url($portal)); ?>" class="btn portal-btn rounded-pill px-4 fw-bold"><i class="fas fa-sign-in-alt me-1"></i>Login again</a>
        <a href="<?php echo e($home); ?>" class="btn btn-outline-secondary rounded-pill px-4"><i class="fas fa-home me-1"></i>Home</a>
    </div>
    <p class="small text-muted mb-0" id="soCountdown">Going to the home page in <strong id="soSeconds"><?php echo $seconds; ?></strong> seconds&hellip;</p>
</div>
<script>
(function () {
    var left = <?php echo $seconds; ?>, el = document.getElementById('soSeconds');
    var timer = setInterval(function () {
        left -= 1;
        if (el) el.textContent = Math.max(left, 0);
        if (left <= 0) { clearInterval(timer); window.location.replace(<?php echo json_encode($home); ?>); }
    }, 1000);
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
