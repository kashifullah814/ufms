<?php
/**
 * Common <head> + opening <body>.
 * Page se pehle set karein: $page_title (string), optional $use_chart (bool), $body_class (string),
 * $no_animations (bool) - print pages (voucher, receipt, slip) par animations band.
 */
$page_title = $page_title ?? APP_NAME;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($page_title); ?> - <?php echo e(APP_NAME); ?></title>
    <?php if (app_logo_url()): ?><link rel="icon" type="image/png" href="<?php echo e(app_logo_url()); ?>">
    <?php else: /* logo file na ho to bhi favicon (warna browser /favicon.ico maangta hai -> 404) */ ?><link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='14' fill='%231e3a8a'/%3E%3Cpath fill='%23fff' d='M32 10 8 22v5h48v-5L32 10zM14 30v16h6V30h-6zm15 0v16h6V30h-6zm15 0v16h6V30h-6zM8 49v6h48v-6H8z'/%3E%3C/svg%3E"><?php endif; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <?php if (!empty($use_chart)): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <?php endif; ?>
    <style>
        :root { --sidebar-width: 250px; --primary-color: #1e3a8a; }
        body { background-color: #f8f9fa; font-family: 'Segoe UI', Tahoma, sans-serif; }
        .sidebar { width: var(--sidebar-width); height: 100vh; position: fixed; top: 0; left: 0; background: var(--primary-color); color: #fff; z-index: 1000; overflow-y: auto; }
        .sidebar-header { padding: 20px; text-align: center; background: rgba(0,0,0,0.1); }
        .sidebar .nav-link { color: rgba(255,255,255,0.8); padding: 14px 20px; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { background: rgba(255,255,255,0.12); color: #fff; }
        .main-content { margin-left: var(--sidebar-width); padding: 30px; }
        .stat-card { border: none; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); transition: transform .3s; }
        .stat-card:hover { transform: translateY(-4px); }
        /* Stat cards: title aur raqam hamesha ek ek line mein (sab cards ek jaisi height / layout) */
        .stat-text { min-width: 0; }
        .stat-value { font-size: 1.15rem; line-height: 1.3; }
        .stat-value .stat-cur { font-size: .72em; font-weight: 600; }
        .icon-box { width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 22px; }
        .table-card, .form-card { border: none; border-radius: 15px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .btn-brand { background: var(--primary-color); color: #fff; }
        .btn-brand:disabled, .btn-brand.disabled { background: var(--primary-color); color: #fff; opacity: .55; }
        /* Ek hi "primary" rang: Bootstrap ka btn-primary / bg-primary bhi UFMS navy (har page par buttons ek jaise) */
        .btn-primary { --bs-btn-bg: #1e3a8a; --bs-btn-border-color: #1e3a8a; --bs-btn-hover-bg: #1e40af; --bs-btn-hover-border-color: #1e40af;
                       --bs-btn-active-bg: #1e3a8a; --bs-btn-active-border-color: #1e3a8a; --bs-btn-disabled-bg: #1e3a8a; --bs-btn-disabled-border-color: #1e3a8a; }
        .bg-primary { background-color: #1e3a8a !important; }
        .page-item.active .page-link { background-color: #1e3a8a; border-color: #1e3a8a; }
        .btn-brand:hover { background: #1e40af; color: #fff; }
        .bg-soft-primary { background-color: #e0f2fe; color: #0369a1; }
        .req { color: #b02a37; }
        .toggle-password { cursor: pointer; }
        .audit-item { border-left: 3px solid #0dcaf0; background: #f8fafc; }
        /* Accessible colours: har text ka contrast kam az kam 4.5:1 (WCAG AA) - safed aur halke grey (#f8f9fa) dono par.
           Bootstrap ke peele / cyan / halke neele text ki jagah gehre shades; dark backgrounds par asal rang. */
        :root { --bs-link-color: #0b5ed7; --bs-link-color-rgb: 11, 94, 215; --bs-link-hover-color: #0a53be; --bs-link-hover-color-rgb: 10, 83, 190; }
        .text-primary   { color: #0b5ed7 !important; }
        .text-secondary { color: #565e64 !important; }
        .text-success   { color: #146c43 !important; }
        .text-danger    { color: #b02a37 !important; }
        .text-warning   { color: #8a5a00 !important; }
        .text-info      { color: #0a6c7e !important; }
        .sidebar .text-warning, .navbar-dark .text-warning { color: #ffc107 !important; }   /* gehre background par peela theek hai */
        code { color: #a91e63; }
        .btn-outline-primary { --bs-btn-color: #0b5ed7; --bs-btn-border-color: #0b5ed7; --bs-btn-hover-bg: #0b5ed7; --bs-btn-hover-border-color: #0b5ed7; --bs-btn-active-bg: #0a53be; }
        .btn-outline-warning { --bs-btn-color: #8a5a00; --bs-btn-border-color: #b37400; }
        .btn-outline-danger  { --bs-btn-color: #b02a37; --bs-btn-border-color: #b02a37; --bs-btn-hover-bg: #b02a37; --bs-btn-hover-border-color: #b02a37; }
        .btn-outline-success { --bs-btn-color: #146c43; --bs-btn-border-color: #146c43; --bs-btn-hover-bg: #146c43; --bs-btn-hover-border-color: #146c43; }
        .btn-outline-secondary { --bs-btn-color: #565e64; --bs-btn-border-color: #6c757d; }
        .btn-warning.text-white, .bg-warning.text-white:not(.icon-box) { color: #212529 !important; }             /* peele par safed nahi parha jata */
        .btn-info { --bs-btn-color: #fff; --bs-btn-bg: #0a6c7e; --bs-btn-border-color: #0a6c7e; --bs-btn-hover-color: #fff; --bs-btn-hover-bg: #085866;
                    --bs-btn-hover-border-color: #085866; --bs-btn-active-color: #fff; --bs-btn-active-bg: #074a56; --bs-btn-active-border-color: #074a56; }
        .bg-info:not(.text-dark) { background-color: #0a6c7e !important; }                    /* safed text wala cyan -> gehra teal */
        .landing-hero .opacity-75, .overlay-panel .opacity-75 { opacity: 1 !important; }       /* rangeen gradient par halka safed text */
        /* List tables (.table-hover): raqam, tareekh, naam aur headings kabhi do lines mein nahi tootte; lambi text wale
           cells par .cell-wrap. Table chaura ho to scroll card ke andar (.table-responsive). Report / print tables alag hain. */
        .table-hover > :not(caption) > tr > * { white-space: nowrap; }
        .table-hover > :not(caption) > tr > .cell-wrap { white-space: normal; min-width: 14rem; overflow-wrap: anywhere; }
        .btn { white-space: nowrap; }
        /* Form labels: har form par ek hi style (chhote, bold, capital) - add forms aur edit modals dono */
        label.form-label { text-transform: uppercase; font-size: .8125rem; font-weight: 600; letter-spacing: .01em; margin-bottom: .35rem; }
        /* Edit modals table ke cell ke andar hote hain: cell ka nowrap modal ke andar nahi chalna chahiye (labels ek doosre par aa jate the) */
        .modal, .dropdown-menu, .swal2-container { white-space: normal; }
        .modal-header .btn-close, .alert .btn-close { flex-shrink: 0; }
        .modal-title { min-width: 0; overflow-wrap: anywhere; }
        .pagination { flex-wrap: wrap; row-gap: .35rem; }
        /* Entry form + list pages (.split-form / .split-list): form upar poori chaurai mein (fields columns mein) aur list neeche,
           taake list table ko kabhi daen-baen scroll na karna pare (container 1320px se chaura nahi hota). */
        .split-form, .split-list { flex: 0 0 100% !important; max-width: 100% !important; }
        @media (min-width: 768px) {
            .split-form form { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); column-gap: 1rem; align-items: start; }
            .split-form form > .row { grid-column: 1 / -1; }
            .split-form form > .row > [class*="col-"]:not([hidden]) { flex: 1 1 10rem; width: auto; max-width: none; }
            .split-form form > .btn, .split-form form > .alert, .split-form form > .split-full { grid-column: 1 / -1; }
            .split-form form > .btn { width: auto !important; justify-self: end; min-width: 16rem; }
        }
        @media (min-width: 992px) { .split-form form { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        @media (min-width: 1400px) { .split-form form { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
        /* Reports, vouchers, receipts aur slips ka university heading: har document par ek hi size, ek line mein */
        .doc-logo { display: block; height: 64px; width: auto; margin: 0 auto .4rem; }
        .doc-title { color: #1e3a8a; font-size: clamp(1rem, 3.2vw, 1.4rem); letter-spacing: .01em; }
        /* Print (Ctrl+P) har page par: navigation, buttons, filters aur entry forms nahi chhapte - sirf data */
        @media print {
            .no-print, .sidebar, .admin-topbar, .offcanvas, nav.navbar, .btn, .pagination, .modal, .swal2-container, .alert-dismissible,
            form[method="get" i], .form-card { display: none !important; }
            body { background: #fff; }
            .main-content { margin-left: 0 !important; padding: 0 !important; }
            .card, .table-card, .stat-card { box-shadow: none !important; }
            .table-responsive { overflow: visible !important; }
            a { color: inherit !important; text-decoration: none !important; }
        }
    </style>
    <link rel="stylesheet" href="<?php echo e(asset_url('assets/css/animations.css')); ?>">
    <link rel="stylesheet" href="<?php echo e(asset_url('assets/css/dialog.css')); ?>">
    <link rel="stylesheet" href="<?php echo e(asset_url('assets/css/responsive.css')); ?>">
</head>
<body class="<?php echo e(trim(($body_class ?? '') . (empty($no_animations) ? ' ui-enter' : ' no-anim'))); ?>">
