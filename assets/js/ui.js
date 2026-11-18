/*
 * UFMS - chhota UI script (vanilla JS). includes/footer.php se load hota hai; CSS: assets/css/animations.css
 * 1. Page-load animations ek dafa: body.ui-enter class baad mein hata do (PDF/print capture saaf rahe)
 * 2. Login <-> Forgot Password sliding panel (data-auth-show="forgot" | "login")
 * 3. UFMS.confirm() / UFMS.dialog(): poore app ka ek hi confirm dialog (SweetAlert2 + assets/css/dialog.css)
 * Raqam (finance figures) kabhi animate nahi hoti - hamesha server wali exact value dikhti hai.
 * prefers-reduced-motion, print pages (body.no-anim) aur printing mein kuch nahi chalta.
 */
(function () {
    'use strict';
    var body = document.body;
    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var animate = !reduced && !body.classList.contains('no-anim');

    /* ---- 1. one-time load animations ---- */
    function endEnter() { body.classList.remove('ui-enter'); }
    if (body.classList.contains('ui-enter')) {
        if (animate) setTimeout(endEnter, 900); else endEnter();
        window.addEventListener('beforeprint', endEnter);
    }

    /* ---- 2. Login <-> Forgot Password panel ---- */
    document.addEventListener('click', function (ev) {
        var trigger = ev.target.closest('[data-auth-show]');
        var wrap = document.getElementById('authCard');
        if (!trigger || !wrap) return;
        ev.preventDefault();
        var forgot = trigger.getAttribute('data-auth-show') === 'forgot';
        var login = wrap.querySelector('.login-box'), reset = wrap.querySelector('.forgot-box');
        wrap.classList.add('auth-anim');
        wrap.classList.toggle('show-forgot', forgot);
        // Chhupa hua form keyboard / screen reader se bhi band
        if (login) login.inert = forgot;
        if (reset) reset.inert = !forgot;
        if (document.activeElement && document.activeElement.blur) document.activeElement.blur();
    });

    /* ---- 3. Confirm dialog (ek hi theme): UFMS.confirm({kind, title, text, confirmText}) -> Promise<boolean> ----
     * kind: 'danger' (delete, trash) | 'logout' (logout) | 'success' (pay / approve / unlock, check) | 'info' | 'warning'
     * Esc aur bahar click = Cancel; focus Cancel par; buttons daen taraf, Cancel pehle.
     * options: icon (Font Awesome class), cancelText, html, mustChoose (Esc / bahar click band), focusConfirm, didOpen, willClose */
    var KINDS = {
        danger:  { fa: 'fa-trash-alt',    btn: 'btn-danger' },
        logout:  { fa: 'fa-sign-out-alt', btn: 'btn-danger' },
        success: { fa: 'fa-check',        btn: 'btn-success' },
        info:    { fa: 'fa-info',         btn: 'btn-primary' },
        warning: { fa: 'fa-clock',        btn: 'btn-primary' }
    };
    function dialog(o) {
        o = o || {};
        var kind = KINDS[o.kind] ? o.kind : 'info', k = KINDS[kind];
        if (typeof Swal === 'undefined') {                      // SweetAlert load na ho (internet nahi): browser ka confirm()
            var ok = window.confirm((o.title || 'Are you sure?') + (o.text ? '\n\n' + o.text : ''));
            return Promise.resolve({ isConfirmed: ok, isDismissed: !ok, dismiss: ok ? undefined : 'cancel' });
        }
        var cfg = {
            title: o.title || 'Are you sure?',
            icon: 'info', iconHtml: '<i class="fas ' + (o.icon || k.fa) + '"></i>',
            showCancelButton: true, reverseButtons: true, buttonsStyling: false,
            confirmButtonText: o.confirmText || 'Confirm', cancelButtonText: o.cancelText || 'Cancel',
            focusCancel: !o.focusConfirm, focusConfirm: !!o.focusConfirm,
            allowOutsideClick: !o.mustChoose, allowEscapeKey: !o.mustChoose,
            customClass: {
                container: 'ufms-dlg-backdrop', popup: 'ufms-dlg', icon: 'ufms-dlg-icon ufms-dlg-' + kind,
                confirmButton: 'btn ' + k.btn + ' rounded-pill px-4', cancelButton: 'btn btn-outline-secondary rounded-pill px-4'
            },
            showClass: { popup: 'ufms-dlg-in', backdrop: 'swal2-backdrop-show', icon: '' },
            hideClass: { popup: 'ufms-dlg-out', backdrop: 'swal2-backdrop-hide', icon: '' }
        };
        if (o.html) cfg.html = o.html; else cfg.text = o.text || '';
        if (o.didOpen) cfg.didOpen = o.didOpen;
        if (o.willClose) cfg.willClose = o.willClose;
        return Swal.fire(cfg);
    }
    /*
     * Validation error ke baad form ko dobara bharna (includes/footer.php data deta hai: pichhle POST ke fields).
     * Form ki pehchan: us ka submit button jis ka name POST mein tha; kai forms hon (har row ka edit modal) to
     * woh jis ke hidden fields (id waghera) POST se milte hon. Form modal ke andar ho to modal khol deta hai.
     * Passwords, files aur hidden fields ko kabhi nahi chherta.
     */
    function restoreForm(data) {
        if (!data || typeof data !== 'object') return;
        var best = null, bestScore = -1;
        document.querySelectorAll('form').forEach(function (form) {
            if ((form.method || '').toLowerCase() !== 'post') return;
            var hasSubmit = false, score = 0;
            form.querySelectorAll('button[name], input[type=submit][name]').forEach(function (b) { if (Object.prototype.hasOwnProperty.call(data, b.name)) hasSubmit = true; });
            if (!hasSubmit) return;
            form.querySelectorAll('input[type=hidden][name]').forEach(function (h) {
                if (h.name !== 'csrf_token' && Object.prototype.hasOwnProperty.call(data, h.name)) score += String(data[h.name]) === h.value ? 2 : -2;
            });
            if (score > bestScore) { best = form; bestScore = score; }
        });
        if (!best) return;
        var seen = {};
        Array.prototype.forEach.call(best.elements, function (el) {
            if (!el.name || el.disabled) return;
            var type = (el.type || '').toLowerCase();
            if (type === 'hidden' || type === 'password' || type === 'file' || type === 'submit' || type === 'button') return;
            // name="x" | name="x[]" | name="x[key]"
            var m = el.name.match(/^([^\[]+)(?:\[([^\]]*)\])?$/), val;
            if (!m) return;
            var base = m[1];
            if (m[2] === undefined) val = data[base];
            else if (data[base] && typeof data[base] === 'object') {
                if (m[2] === '') { seen[base] = (seen[base] === undefined ? -1 : seen[base]) + 1; val = Array.isArray(data[base]) ? data[base][seen[base]] : Object.keys(data[base]).map(function (k) { return data[base][k]; })[seen[base]]; }
                else val = data[base][m[2]];
            }
            if (type === 'checkbox' || type === 'radio') {
                if (type === 'radio') { if (val !== undefined) el.checked = String(val) === el.value; }
                else el.checked = val !== undefined && (Array.isArray(val) ? val.map(String).indexOf(el.value) !== -1 : true);
            } else if (val !== undefined && typeof val !== 'object') {
                el.value = String(val);
            } else {
                return;
            }
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
        });
        var modal = best.closest('.modal');
        if (modal && window.bootstrap) bootstrap.Modal.getOrCreateInstance(modal).show();
    }

    window.UFMS = {
        dialog: dialog,
        confirm: function (o) { return dialog(o).then(function (r) { return !!r.isConfirmed; }); },
        restoreForm: restoreForm
    };
})();
