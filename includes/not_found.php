<?php
/**
 * UFMS ka "not found" page (HTTP 404), normal layout ke saath. render_not_found() call karein - yeh exit kar deta hai.
 * Use: ghalat address (404.php), ghayab receipt / minutes file.
 */
function render_not_found($title = 'Page not found', $message = 'The page you are looking for does not exist or has been moved.')
{
    http_response_code(404);
    $user = current_user();
    $home = $user && $user['status'] === 'Active' ? dashboard_link() : ['url' => url('index.php'), 'label' => 'Home'];
    $page_title = $title;
    $body_class = 'd-flex align-items-center justify-content-center min-vh-100 px-3';
    $no_animations = true;
    include __DIR__ . '/header.php';
    ?>
    <div class="bg-white shadow-sm p-4 p-md-5 rounded-4 text-center my-4" style="width:100%;max-width:480px;border-top:5px solid #1e3a8a;">
        <i class="fas fa-compass fa-3x text-primary mb-3"></i>
        <h4 class="fw-bold mb-2"><?php echo e($title); ?></h4>
        <p class="text-muted mb-4"><?php echo e($message); ?></p>
        <a href="<?php echo e($home['url']); ?>" class="btn btn-brand rounded-pill px-4"><i class="fas fa-arrow-left me-1"></i>Back to <?php echo e($home['label']); ?></a>
    </div>
    <?php
    include __DIR__ . '/footer.php';
    exit();
}
