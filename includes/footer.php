<?php /** Common scripts + closing tags */ ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo e(asset_url('assets/js/ui.js')); ?>" defer></script>
<script>
const UFMS_CSRF = <?php echo json_encode(csrf_token()); ?>;

// Hidden POST form bana kar submit karta hai (delete / pay jaise actions GET link se nahi hote)
function postAction(url, fields) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = url || window.location.href;
    fields = Object.assign({}, fields || {}, { csrf_token: UFMS_CSRF });
    Object.keys(fields).forEach(function (k) {
        const input = document.createElement('input');
        input.type = 'hidden'; input.name = k; input.value = fields[k];
        form.appendChild(input);
    });
    document.body.appendChild(form);
    form.submit();
}

// Confirm dialogs: sab UFMS.confirm() se (ek hi theme - assets/js/ui.js + assets/css/dialog.css).
// <button data-confirm-post data-title=".." data-text=".." data-confirm="Yes, delete" data-fields='{"delete_id":5}'
//         data-kind="danger|success|info" (na ho to title se: Delete.. = danger, Pay/Approve/Unlock.. = success, warna info)>
document.addEventListener('click', function (ev) {
    const btn = ev.target.closest('[data-confirm-post]');
    if (!btn) return;
    ev.preventDefault();
    const fields = JSON.parse(btn.getAttribute('data-fields') || '{}');
    const title = btn.getAttribute('data-title') || 'Are you sure?';
    const kind = btn.getAttribute('data-kind') || (/^(delete|remove)/i.test(title) ? 'danger' : (/^(pay|approve|unlock)/i.test(title) ? 'success' : 'info'));
    UFMS.confirm({
        kind: kind, title: title, text: btn.getAttribute('data-text') || '',
        icon: btn.getAttribute('data-fa') || undefined,
        confirmText: btn.getAttribute('data-confirm') || 'Yes, continue'
    }).then(function (ok) { if (ok) postAction(btn.getAttribute('data-action') || '', fields); });
});

// Ek form do dafa submit na ho (double click = do vouchers). Button par chhota spinner; back button par dobara chalu.
document.addEventListener('submit', function (ev) {
    const form = ev.target;
    if (!(form instanceof HTMLFormElement) || (form.method || '').toLowerCase() !== 'post') return;
    if (ev.defaultPrevented) return;   // page ki apni validation ne rok diya
    if (form.dataset.submitted === '1') { ev.preventDefault(); return; }
    form.dataset.submitted = '1';
    setTimeout(function () {   // submit ke BAAD disable (warna button ka name/value POST mein nahi jata)
        form.querySelectorAll('button[type=submit], button:not([type])').forEach(function (b) { b.disabled = true; });
    }, 0);
});
window.addEventListener('pageshow', function () {
    document.querySelectorAll('form[data-submitted]').forEach(function (form) {
        delete form.dataset.submitted;
        form.querySelectorAll('button[type=submit], button:not([type])').forEach(function (b) { if (!b.hasAttribute('data-keep-disabled')) b.disabled = false; });
    });
});

// Logout: <a href=".../auth/logout.php" data-confirm-logout> -> confirm, phir CSRF ke saath POST.
document.addEventListener('click', function (ev) {
    const link = ev.target.closest('[data-confirm-logout]');
    if (!link) return;
    ev.preventDefault();
    const url = link.getAttribute('href');
    UFMS.confirm({
        kind: 'logout', title: <?php echo json_encode('Logout of ' . APP_NAME . '?'); ?>, text: 'You will need to log in again to continue.', confirmText: 'Logout'
    }).then(function (ok) { if (ok) postAction(url, { logout: 1 }); });
});

<?php if (!empty($GLOBALS['ufms_session_timer'])): $st = $GLOBALS['ufms_session_timer']; ?>
// Session timeout: khatam hone se 1 minute pehle popup. "Stay logged in" -> keep-alive (POST + CSRF).
// Waqt khatam -> page reload; server session khatam karke login page par bhejta hai.
(function () {
    var KEEP_ALIVE = <?php echo json_encode(url('auth/keep_alive.php')); ?>, LOGOUT = <?php echo json_encode(url('auth/logout.php')); ?>;
    var warnTimer, endTimer, tick;
    function schedule(seconds, reason) {
        clearTimeout(warnTimer); clearTimeout(endTimer); clearInterval(tick);
        var lead = Math.min(60, Math.floor(seconds / 2));
        warnTimer = setTimeout(function () { warn(lead, reason); }, Math.max(0, seconds - lead) * 1000);
        endTimer = setTimeout(function () { window.location.reload(); }, (seconds + 1) * 1000);
    }
    function warn(left, reason) {
        var max = reason === 'max';
        UFMS.dialog({
            kind: 'warning', title: 'Your session is about to expire', mustChoose: true, focusConfirm: true,
            html: (max ? 'You have been logged in for the maximum time. ' : 'You have been inactive for a while. ')
                + 'You will be logged out in <b id="ufmsSessLeft">' + left + '</b> seconds.',
            confirmText: max ? 'OK' : 'Stay logged in', cancelText: 'Logout',
            didOpen: function () {
                tick = setInterval(function () { left -= 1; var b = document.getElementById('ufmsSessLeft'); if (b) b.textContent = Math.max(left, 0); }, 1000);
            },
            willClose: function () { clearInterval(tick); }
        }).then(function (r) {
            if (r.isConfirmed && !max) {
                var body = new URLSearchParams({ csrf_token: UFMS_CSRF });
                fetch(KEEP_ALIVE, { method: 'POST', body: body, credentials: 'same-origin' })
                    .then(function (res) { return res.json(); })
                    .then(function (d) { if (d.ok) schedule(d.seconds, d.reason); else window.location.reload(); })
                    .catch(function () { window.location.reload(); });
            } else if (r.dismiss === 'cancel') {
                postAction(LOGOUT, { logout: 1 });
            }
        });
    }
    schedule(<?php echo (int)$st['seconds']; ?>, <?php echo json_encode($st['reason']); ?>);
})();
<?php endif; ?>

// Back button: browser cache se purana page wapas aaye (bfcache) to server se dobara lo (logout ke baad login page)
window.addEventListener('pageshow', function (ev) { if (ev.persisted) window.location.reload(); });

// Password show/hide: <span class="toggle-password" data-target="inputId">
document.addEventListener('click', function (ev) {
    const t = ev.target.closest('.toggle-password');
    if (!t) return;
    const input = document.getElementById(t.getAttribute('data-target'));
    const icon = t.querySelector('i');
    if (!input) return;
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    if (icon) { icon.classList.toggle('fa-eye', !show); icon.classList.toggle('fa-eye-slash', show); }
});
</script>
<?php $ufms_old = take_old_input(); if ($ufms_old): ?>
<script>
// Validation error ke baad: user ka likha hua data usi form mein wapas (assets/js/ui.js -> UFMS.restoreForm)
document.addEventListener('DOMContentLoaded', function () { if (window.UFMS && UFMS.restoreForm) UFMS.restoreForm(<?php echo json_encode($ufms_old, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>); });
</script>
<?php endif; ?>
</body>
</html>
