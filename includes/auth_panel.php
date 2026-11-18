<?php
/**
 * Login + Forgot Password ka classic sliding card (auth/login.php aur auth/forgot_password.php dono yahi dikhate hain).
 * Set karein: $auth_mode = 'login' | 'forgot', $portal ('' ya admin|accountant|staff|student).
 * Structure (CSS: assets/css/animations.css):
 *   .auth-card > .form-box.login-box + .form-box.forgot-box + .overlay-box > .overlay > .panel-left / .panel-right
 *   .show-forgot (card par): dono form daen slide, overlay baen. Har text sirf EK dafa hai.
 *   Overlay sirf information panel hai (koi button nahi): slide "Forgot Password?" / "Back to Login" links se hota hai.
 * UFMS mein self-registration nahi hai - is liye doosri taraf sirf Forgot Password (OTP) hai.
 * Mobile (< 768px): overlay nahi, ek waqt mein ek form (fade). Toggle: assets/js/ui.js (data-auth-show).
 */
$auth_forgot = ($auth_mode ?? 'login') === 'forgot';
$p = $portal !== '' ? LOGIN_PORTALS[$portal] : null;
// Administration / Finance Office university network ke bahar se: form ke bajaye message
$network_blocked = in_array($portal, NETWORK_ONLY_PORTALS, true) && !admin_network_allowed();
$color = $p ? $p['color'] : '#1e3a8a';
// Overlay ka gradient: portal ka rang -> usi ka gehra shade (38% #0b1220 ke saath mila kar)
$rgb = sscanf($color, '#%02x%02x%02x');
$dark = sprintf('#%02x%02x%02x', (int)round($rgb[0] * .62 + 11 * .38), (int)round($rgb[1] * .62 + 18 * .38), (int)round($rgb[2] * .62 + 32 * .38));
$gradient = "linear-gradient(135deg, {$color} 0%, {$dark} 100%)";
$portal_qs = $p ? '?portal=' . $portal : '';
$auth_flash = flash_render();

$page_title = $auth_forgot ? 'Forgot Password' : ($p ? $p['title'] . ' Login' : 'Login');
$body_class = 'd-flex align-items-center justify-content-center min-vh-100 px-3';
include __DIR__ . '/header.php';
?>
<div class="auth-shell my-4" style="--pc: <?php echo e($color); ?>; --portal-gradient: <?php echo e($gradient); ?>;">
    <div class="auth-card<?php echo $auth_forgot ? ' show-forgot' : ''; ?>" id="authCard">

        <!-- Login -->
        <section class="form-box login-box"<?php echo $auth_forgot ? ' inert' : ''; ?>>
            <div class="text-center mb-4">
                <?php if (app_logo_url()): ?><img src="<?php echo e(app_logo_url()); ?>" alt="UST Bannu logo" class="d-md-none mb-2" style="height:56px;width:auto;"><br class="d-md-none"><?php endif; ?>
                <?php if ($p): ?>
                    <span class="auth-icon mb-2"><i class="fas <?php echo e($p['icon']); ?> fa-2x"></i></span>
                    <h4 class="fw-bold mb-0"><?php echo e($p['title']); ?> Login</h4>
                    <p class="text-muted small mb-0"><?php echo e(APP_NAME . ' - ' . APP_FULL_NAME); ?></p>
                <?php else: ?>
                    <i class="fas fa-university fa-3x text-primary mb-2"></i>
                    <h4 class="fw-bold mb-0"><?php echo e(APP_NAME); ?> PORTAL</h4>
                    <p class="text-muted small mb-0"><?php echo e(APP_FULL_NAME); ?></p>
                <?php endif; ?>
            </div>

            <?php echo $auth_forgot ? '' : $auth_flash; ?>

            <?php if ($network_blocked): ?>
            <div class="alert alert-danger text-center small"><i class="fas fa-network-wired fa-2x d-block mb-2"></i><strong><?php echo e(NETWORK_ONLY_MESSAGE); ?></strong><br>Student and Staff portals work from anywhere.</div>
            <?php else: ?>
            <!-- Browser saved email/password khud na bhare: autocomplete off + readonly jab tak user field par click/focus na kare -->
            <form action="<?php echo e(url('auth/login_process.php')); ?>" method="POST" autocomplete="off">
                <?php echo csrf_field(); ?>
                <?php if ($p): ?><input type="hidden" name="portal" value="<?php echo e($portal); ?>"><?php endif; ?>
                <div class="mb-3">
                    <label class="form-label small fw-bold">EMAIL ADDRESS <span class="req">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text bg-light"><i class="fas fa-envelope text-muted"></i></span>
                        <input type="email" name="email" maxlength="100" id="loginEmail" class="form-control" value="" placeholder="name@example.com" required autocomplete="off" readonly data-unlock-on-focus>
                    </div>
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-bold">PASSWORD <span class="req">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text bg-light"><i class="fas fa-lock text-muted"></i></span>
                        <input type="password" name="password" id="loginPassword" class="form-control" value="" placeholder="Enter your password" required autocomplete="new-password" readonly data-unlock-on-focus>
                        <span class="input-group-text bg-light toggle-password" data-target="loginPassword"><i class="fas fa-eye text-muted"></i></span>
                    </div>
                </div>
                <div class="d-flex justify-content-between align-items-start gap-2 mb-4">
                    <small class="text-muted"><i class="fas fa-info-circle me-1"></i>Do not save your password on shared computers.</small>
                    <a href="<?php echo e(url('auth/forgot_password.php' . $portal_qs)); ?>" data-auth-show="forgot" class="text-decoration-none small fw-semibold text-nowrap">Forgot Password?</a>
                </div>
                <button type="submit" name="login_btn" class="btn auth-btn w-100 rounded-pill fw-bold py-2 shadow-sm">
                    LOGIN <i class="fas fa-sign-in-alt ms-2"></i>
                </button>
            </form>
            <?php endif; ?>
            <p class="text-center small text-danger mt-3 mb-0 d-md-none"><i class="fas fa-lock me-1"></i><?php echo e(LOGIN_WARNING); ?></p>
            <div class="text-center mt-3">
                <a href="<?php echo e(url('index.php')); ?>" class="text-decoration-none small text-secondary"><i class="fas fa-th-large me-1"></i><?php echo $p ? 'Choose another portal' : 'Choose your portal'; ?></a>
            </div>
        </section>

        <!-- Forgot Password (OTP) -->
        <section class="form-box forgot-box"<?php echo $auth_forgot ? '' : ' inert'; ?>>
            <div class="text-center mb-4">
                <span class="auth-icon mb-2"><i class="fas fa-key fa-2x"></i></span>
                <h4 class="fw-bold mb-0">Forgot Password</h4>
                <p class="text-muted small mb-0">Enter your registered email to receive an OTP</p>
            </div>

            <?php echo $auth_forgot ? $auth_flash : ''; ?>

            <form action="<?php echo e(url('auth/forgot_password.php' . $portal_qs)); ?>" method="POST" autocomplete="off">
                <?php echo csrf_field(); ?>
                <div class="mb-4">
                    <label class="form-label small fw-bold">EMAIL ADDRESS <span class="req">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text bg-light"><i class="fas fa-envelope text-muted"></i></span>
                        <input type="email" name="email" maxlength="100" id="forgotEmail" class="form-control" placeholder="name@example.com" required autocomplete="off">
                    </div>
                </div>
                <button type="submit" name="send_otp_btn" class="btn auth-btn w-100 rounded-pill fw-bold py-2 mb-3">SEND OTP CODE <i class="fas fa-paper-plane ms-2"></i></button>
                <div class="text-center"><a href="<?php echo e(login_url($portal)); ?>" data-auth-show="login" class="text-decoration-none small text-secondary"><i class="fas fa-arrow-left me-1"></i> Back to Login</a></div>
            </form>
            <p class="text-center small text-danger mt-3 mb-0 d-md-none"><i class="fas fa-lock me-1"></i><?php echo e(LOGIN_WARNING); ?></p>
        </section>

        <!-- Sliding info panel (sirf desktop): koi button nahi - slide sirf form ke links se hota hai -->
        <div class="overlay-box" aria-hidden="true">
            <div class="overlay">
                <!-- Forgot state -->
                <div class="overlay-panel panel-left">
                    <i class="fas fa-key fa-3x mb-3"></i>
                    <h4 class="fw-bold">Reset your password</h4>
                    <p class="small mb-0">We will email you a 6-digit code.<br>It is valid for <?php echo (int)OTP_VALID_MINUTES; ?> minutes.</p>
                    <p class="overlay-note small mb-0"><i class="fas fa-lock me-1"></i><?php echo e(LOGIN_WARNING); ?></p>
                </div>
                <!-- Login state -->
                <div class="overlay-panel panel-right">
                    <?php if (app_logo_url()): ?>
                        <span class="overlay-logo has-img mb-3"><img src="<?php echo e(app_logo_url()); ?>" alt=""></span>
                    <?php else: ?>
                        <span class="overlay-logo mb-3"><i class="fas fa-university fa-2x"></i></span>
                    <?php endif; ?>
                    <h4 class="fw-bold">Welcome to <?php echo e(APP_NAME); ?></h4>
                    <p class="fw-semibold mb-2"><?php echo e($p ? $p['title'] : APP_FULL_NAME); ?></p>
                    <p class="small mb-0"><?php echo e($p ? $p['desc'] : 'Log in with your university account.'); ?></p>
                    <p class="overlay-note small mb-0"><i class="fas fa-lock me-1"></i><?php echo e(LOGIN_WARNING); ?></p>
                </div>
            </div>
        </div>
    </div>
    <p class="text-center text-muted small mt-3 mb-0">&copy; <?php echo date('Y'); ?> <?php echo e(APP_ORG); ?></p>
</div>
<script>
// readonly hata do jab user field par aaye (click, tab, touch); submit se pehle bhi taake "required" check chale
(function () {
    var fields = document.querySelectorAll('[data-unlock-on-focus]');
    function unlock(el) { el.removeAttribute('readonly'); }
    fields.forEach(function (el) {
        ['focus', 'mousedown', 'touchstart'].forEach(function (evt) { el.addEventListener(evt, function () { unlock(el); }); });
    });
    var form = fields.length ? fields[0].form : null;
    if (form) form.addEventListener('submit', function () { fields.forEach(unlock); }, true);
    var btn = form ? form.querySelector('button[type=submit]') : null;
    if (btn) btn.addEventListener('click', function () { fields.forEach(unlock); });
    // Browser page khulte hi saved email/password bhar de to hata do - jab tak user ne field ko chhua nahi (readonly hai)
    function clearUntouched() { fields.forEach(function (el) { if (el.readOnly && el.value !== '') el.value = ''; }); }
    [0, 100, 300, 700, 1500, 3000].forEach(function (ms) { setTimeout(clearUntouched, ms); });
    window.addEventListener('load', clearUntouched);
    // Back button / bfcache se wapas aaye to bhi khaali
    window.addEventListener('pageshow', function () { fields.forEach(function (el) { el.value = ''; }); });
})();
</script>
<?php include __DIR__ . '/footer.php'; ?>
