<?php
/**
 * Unified login page - Admin, Accountant, Student aur Staff sab yahin se login karte hain.
 * ?portal=admin|accountant|staff|student ho to us portal ka title/rang; role check login_process.php mein.
 * Page ka HTML (Login + Forgot Password sliding panel): includes/auth_panel.php
 */
require_once __DIR__ . '/../includes/bootstrap.php';

// Form hamesha dikhta hai (session ho tab bhi). Kamyab login purani session ko badal deta hai (complete_login);
// form khol kar submit na karein to kuch nahi badalta.
$portal = valid_portal($_GET['portal'] ?? '');
no_cache_headers();
remember_portal($portal);

$auth_mode = 'login';
include __DIR__ . '/../includes/auth_panel.php';
