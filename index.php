<?php
/**
 * Entry point: hamesha portal selection (landing) page - logged in ho ya na ho, bilkul ek jaisa.
 * Har card usi ek login page ko ?portal= ke saath kholta hai (auth/login.php) - hamesha email + password.
 */
require_once __DIR__ . '/includes/bootstrap.php';
no_cache_headers();

$page_title = 'Choose Portal';
$body_class = 'landing';
include __DIR__ . '/includes/header.php';
?>
<style>
    body.landing { min-height: 100vh; background: #f8f9fa; }
    /* Blue header poori width par; curve text ke NEECHE padding wale hisse mein hai, is liye text hamesha blue par rehta hai */
    .landing-hero { color: #fff; background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 60%, #2563eb 100%);
                    padding: 3rem 1rem 8rem; border-radius: 0 0 50% 50% / 0 0 4rem 4rem; }
    .landing-cards { margin-top: -5.5rem; }
    @media (max-width: 575.98px) { .landing-hero { padding: 2.25rem 1rem 7rem; border-bottom-left-radius: 50% 2.5rem; border-bottom-right-radius: 50% 2.5rem; } }
    .landing-hero .logo { width: 84px; height: 84px; border-radius: 50%; background: rgba(255,255,255,.15); display: inline-flex; align-items: center; justify-content: center; overflow: hidden; }
    .landing-hero .logo.has-img { background: #fff; padding: 6px; }
    .landing-hero .logo img { width: 100%; height: 100%; object-fit: contain; border-radius: 50%; }
    .landing-footer a { color: inherit; }
    .portal-card { border: 0; border-radius: 18px; border-top: 5px solid var(--pc); box-shadow: 0 8px 24px rgba(15,23,42,.10); transition: transform .25s, box-shadow .25s; }
    .portal-card:hover { transform: translateY(-6px); box-shadow: 0 14px 32px rgba(15,23,42,.16); }
    .portal-card .p-icon { width: 70px; height: 70px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; background: var(--pc); color: #fff; }
    .portal-card .btn-portal { background: var(--pc); color: #fff; }
    .portal-card .btn-portal:hover, .portal-card .btn-portal:focus { background: var(--pc); color: #fff; filter: brightness(1.12); }
</style>
<header class="landing-hero text-center">
    <div class="container">
        <?php if (app_logo_url()): ?>
            <div class="logo has-img mb-3"><img src="<?php echo e(app_logo_url()); ?>" alt="UST Bannu logo"></div>
        <?php else: ?>
            <div class="logo mb-3"><i class="fas fa-university fa-2x"></i></div>
        <?php endif; ?>
        <h1 class="h3 fw-bold mb-1">University of Science &amp; Technology Bannu</h1>
        <p class="mb-1 fs-5"><?php echo e(APP_FULL_NAME); ?> (<?php echo e(APP_NAME); ?>)</p>
        <p class="small mb-0 opacity-75">Choose your portal to log in</p>
    </div>
</header>
<div class="container landing-cards pb-5">
    <?php echo flash_render(); ?>

    <div class="row g-4 justify-content-center">
        <?php foreach (LOGIN_PORTALS as $key => $p): ?>
            <div class="col-12 col-sm-6 col-lg-3">
                <div class="card portal-card h-100 text-center p-4 d-flex flex-column" style="--pc: <?php echo e($p['color']); ?>;">
                    <div class="mb-3"><span class="p-icon"><i class="fas <?php echo e($p['icon']); ?> fa-2x"></i></span></div>
                    <h5 class="fw-bold mb-2"><?php echo e($p['title']); ?></h5>
                    <p class="small mb-4 flex-grow-1"><?php echo e($p['desc']); ?></p>
                    <a href="<?php echo e(url('auth/login.php?portal=' . $key)); ?>" class="btn btn-portal rounded-pill fw-bold mt-auto">
                        <i class="fas fa-sign-in-alt me-1"></i>Login
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php // Sab ke liye ('All') general notices
    $rem_user = null; ?>
    <div class="mt-4"><?php include __DIR__ . '/includes/reminders_panel.php'; ?></div>

    <footer class="landing-footer text-center text-muted small mt-5">
        <div class="fw-semibold text-secondary mb-1"><i class="fas fa-university me-1"></i>Finance Office</div>
        <div class="d-flex justify-content-center flex-wrap column-gap-4 row-gap-1 mb-2">
            <?php if (FINANCE_OFFICE_ADDRESS !== ''): ?><span><i class="fas fa-map-marker-alt me-1"></i><?php echo e(FINANCE_OFFICE_ADDRESS); ?></span><?php endif; ?>
            <?php if (FINANCE_OFFICE_PHONE !== ''): ?><span><i class="fas fa-phone me-1"></i><a href="tel:<?php echo e(preg_replace('/[^0-9+]/', '', FINANCE_OFFICE_PHONE)); ?>" class="text-decoration-none"><?php echo e(FINANCE_OFFICE_PHONE); ?></a></span><?php endif; ?>
            <?php if (FINANCE_OFFICE_EMAIL !== ''): ?><span><i class="fas fa-envelope me-1"></i><a href="mailto:<?php echo e(FINANCE_OFFICE_EMAIL); ?>" class="text-decoration-none"><?php echo e(FINANCE_OFFICE_EMAIL); ?></a></span><?php endif; ?>
            <?php if (FINANCE_OFFICE_HOURS !== ''): ?><span><i class="far fa-clock me-1"></i><?php echo e(FINANCE_OFFICE_HOURS); ?></span><?php endif; ?>
        </div>
        <p class="mb-0"><?php echo e(APP_NAME); ?> v<?php echo e(APP_VERSION); ?> &copy; <?php echo date('Y'); ?> <?php echo e(APP_ORG); ?></p>
    </footer>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
