/* ─────────────────────────────────────────────────────────────────────────────
   modal.js – Zentrale Dialog-Komponente für den Einsatzplan
   Ersetzt die Browser-Dialoge alert() und confirm() durch ein eigenes,
   zum Theme passendes Modal.

   API (alle Funktionen geben ein Promise zurück):
     appModal.alert({ title, message, type })                    → Promise<void>
     appModal.confirm({ title, message, type, okText, cancelText }) → Promise<bool>
     appModal.password({ title, message, type, okText })         → Promise<string|null>

   type: 'info' (blau) | 'warning' (gelb) | 'danger' (rot) | 'success' (grün)

   Automatische Anbindung per HTML-Attribut (keine eigene JS-Zeile nötig):
     <form data-confirm="Wirklich löschen?" data-confirm-title="Löschen"
           data-confirm-type="danger"> …
     <button data-confirm="…"> … (auch für Submit-Buttons mit name/value)
     <form data-confirm-password="Text"> … → fragt zusätzlich das Admin-Passwort
           ab und hängt es als verstecktes Feld  name="admin_password"  an.
   ───────────────────────────────────────────────────────────────────────── */
(function () {
    'use strict';

    // ── Styles einmalig injizieren ───────────────────────────────────────────
    const css = `
    .apm-overlay {
        position: fixed; inset: 0; z-index: 99999;
        background: rgba(15, 23, 42, 0.55);
        backdrop-filter: blur(2px);
        display: flex; align-items: center; justify-content: center;
        opacity: 0; transition: opacity .18s ease;
        padding: 20px;
    }
    .apm-overlay.apm-show { opacity: 1; }
    .apm-box {
        background: var(--surface, #fff);
        color: var(--ink, #1e293b);
        border-radius: var(--radius, 12px);
        box-shadow: 0 20px 50px rgba(0,0,0,.30);
        width: 100%; max-width: 440px;
        transform: translateY(14px) scale(.97);
        transition: transform .18s ease;
        overflow: hidden;
        font-family: inherit;
    }
    .apm-overlay.apm-show .apm-box { transform: translateY(0) scale(1); }
    .apm-head {
        display: flex; align-items: center; gap: 12px;
        padding: 18px 22px 0 22px;
    }
    .apm-icon {
        flex: 0 0 auto; width: 40px; height: 40px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-size: 20px;
    }
    .apm-info    .apm-icon { background: #e0f2fe; }
    .apm-warning .apm-icon { background: #fef9c3; }
    .apm-danger  .apm-icon { background: #fee2e2; }
    .apm-success .apm-icon { background: #dcfce7; }
    .apm-title { font-size: 1.05rem; font-weight: 700; margin: 0; }
    .apm-body { padding: 12px 22px 6px 22px; font-size: .95rem; line-height: 1.55; color: var(--ink-soft, #475569); }
    .apm-body b, .apm-body strong { color: var(--ink, #1e293b); }
    .apm-input-wrap { padding: 0 22px 4px 22px; }
    .apm-input-wrap label { display:block; font-size: .8rem; font-weight: 700; margin-bottom: 5px; color: var(--ink-soft, #475569); }
    .apm-input {
        width: 100%; box-sizing: border-box;
        padding: 9px 12px; font-size: .95rem;
        border: 1px solid var(--line, #cbd5e1); border-radius: 8px;
        outline: none;
    }
    .apm-input:focus { border-color: var(--brand, #2563eb); box-shadow: 0 0 0 3px rgba(37,99,235,.15); }
    .apm-error { color: #dc2626; font-size: .8rem; margin-top: 5px; min-height: 1em; }
    .apm-foot {
        display: flex; justify-content: flex-end; gap: 10px;
        padding: 16px 22px 20px 22px;
    }
    .apm-btn {
        border: none; border-radius: 8px; cursor: pointer;
        padding: 9px 18px; font-size: .9rem; font-weight: 700;
        transition: filter .12s ease;
    }
    .apm-btn:hover { filter: brightness(.94); }
    .apm-btn-cancel { background: var(--surface-alt, #e2e8f0); color: var(--ink, #1e293b); }
    .apm-btn-ok.apm-info,   .apm-btn-ok.apm-success { background: var(--brand, #2563eb); color: #fff; }
    .apm-btn-ok.apm-warning { background: #d97706; color: #fff; }
    .apm-btn-ok.apm-danger  { background: #dc2626; color: #fff; }
    @media (max-width: 480px) {
        .apm-foot { flex-direction: column-reverse; }
        .apm-btn { width: 100%; }
    }`;
    const styleEl = document.createElement('style');
    styleEl.textContent = css;
    document.head.appendChild(styleEl);

    const ICONS = { info: 'ℹ️', warning: '⚠️', danger: '🗑️', success: '✅' };
    const DEFAULT_TITLES = { info: 'Hinweis', warning: 'Achtung', danger: 'Wirklich löschen?', success: 'Erledigt' };

    /**
     * Basis-Dialog. mode: 'alert' | 'confirm' | 'password'
     */
    function openModal(opts, mode) {
        return new Promise(function (resolve) {
            const type = opts.type || (mode === 'alert' ? 'info' : 'warning');

            const overlay = document.createElement('div');
            overlay.className = 'apm-overlay apm-' + type;

            const passwordField = (mode === 'password')
                ? '<div class="apm-input-wrap">' +
                  '  <label>Zur Bestätigung bitte Ihr Admin-Passwort eingeben:</label>' +
                  '  <input type="password" class="apm-input" autocomplete="current-password">' +
                  '  <div class="apm-error"></div>' +
                  '</div>'
                : '';

            overlay.innerHTML =
                '<div class="apm-box" role="dialog" aria-modal="true">' +
                '  <div class="apm-head">' +
                '    <div class="apm-icon">' + (opts.icon || ICONS[type] || 'ℹ️') + '</div>' +
                '    <h3 class="apm-title"></h3>' +
                '  </div>' +
                '  <div class="apm-body"></div>' + passwordField +
                '  <div class="apm-foot">' +
                (mode !== 'alert' ? '<button type="button" class="apm-btn apm-btn-cancel"></button>' : '') +
                '    <button type="button" class="apm-btn apm-btn-ok apm-' + type + '"></button>' +
                '  </div>' +
                '</div>';

            overlay.querySelector('.apm-title').textContent = opts.title || DEFAULT_TITLES[type];
            // message darf einfaches HTML enthalten (kommt nur aus eigenem Code, nie aus Nutzereingaben)
            overlay.querySelector('.apm-body').innerHTML = opts.message || '';

            const okBtn = overlay.querySelector('.apm-btn-ok');
            okBtn.textContent = opts.okText || (mode === 'alert' ? 'OK' : (type === 'danger' ? 'Ja, löschen' : 'Ja, fortfahren'));
            const cancelBtn = overlay.querySelector('.apm-btn-cancel');
            if (cancelBtn) cancelBtn.textContent = opts.cancelText || 'Abbrechen';

            const input = overlay.querySelector('.apm-input');
            const errEl = overlay.querySelector('.apm-error');

            function close(result) {
                overlay.classList.remove('apm-show');
                setTimeout(function () { overlay.remove(); }, 180);
                document.removeEventListener('keydown', onKey);
                resolve(result);
            }
            function ok() {
                if (mode === 'password') {
                    const val = input.value;
                    if (!val) {
                        errEl.textContent = 'Bitte Passwort eingeben.';
                        input.focus();
                        return;
                    }
                    close(val);
                } else {
                    close(mode === 'alert' ? undefined : true);
                }
            }
            function cancel() { close(mode === 'alert' ? undefined : (mode === 'password' ? null : false)); }
            function onKey(e) {
                if (e.key === 'Escape') cancel();
                if (e.key === 'Enter' && (mode !== 'password' || document.activeElement === input)) { e.preventDefault(); ok(); }
            }

            okBtn.addEventListener('click', ok);
            if (cancelBtn) cancelBtn.addEventListener('click', cancel);
            overlay.addEventListener('mousedown', function (e) { if (e.target === overlay) cancel(); });
            document.addEventListener('keydown', onKey);

            document.body.appendChild(overlay);
            requestAnimationFrame(function () { overlay.classList.add('apm-show'); });
            setTimeout(function () { (input || okBtn).focus(); }, 60);
        });
    }

    window.appModal = {
        alert:    function (opts) { return openModal(typeof opts === 'string' ? { message: opts } : opts, 'alert'); },
        confirm:  function (opts) { return openModal(typeof opts === 'string' ? { message: opts } : opts, 'confirm'); },
        password: function (opts) { return openModal(typeof opts === 'string' ? { message: opts } : opts, 'password'); }
    };

    // ── Automatische Anbindung über data-Attribute ──────────────────────────
    // Merker, damit ein bestätigtes Formular beim programmatischen Submit
    // nicht erneut abgefangen wird.
    const approved = new WeakSet();

    function findAttrTarget(start, names) {
        let el = start;
        while (el && el !== document.body) {
            for (const n of names) {
                if (el.hasAttribute && el.hasAttribute(n)) return { el: el, attr: n };
            }
            el = el.parentElement;
        }
        return null;
    }

    function handleConfirmFlow(form, holder, attr, submitter) {
        const message = holder.getAttribute(attr) || 'Fortfahren?';
        const opts = {
            message: message,
            title: holder.getAttribute('data-confirm-title') || undefined,
            type:  holder.getAttribute('data-confirm-type') || 'warning',
            okText: holder.getAttribute('data-confirm-ok') || undefined
        };

        const submitApproved = function (password) {
            if (password !== undefined && password !== null && attr === 'data-confirm-password') {
                let hidden = form.querySelector('input[name="admin_password"]');
                if (!hidden) {
                    hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'admin_password';
                    form.appendChild(hidden);
                }
                hidden.value = password;
            }
            approved.add(form);
            if (submitter && form.requestSubmit) {
                form.requestSubmit(submitter);
            } else if (form.requestSubmit) {
                form.requestSubmit();
            } else {
                form.submit();
            }
        };

        if (attr === 'data-confirm-password') {
            appModal.password(opts).then(function (pw) { if (pw !== null) submitApproved(pw); });
        } else {
            appModal.confirm(opts).then(function (yes) { if (yes) submitApproved(); });
        }
    }

    document.addEventListener('submit', function (e) {
        const form = e.target;
        if (!(form instanceof HTMLFormElement)) return;
        if (approved.has(form)) { approved.delete(form); return; }

        const found = findAttrTarget(e.submitter || form, ['data-confirm-password', 'data-confirm'])
                   || (form.hasAttribute('data-confirm-password') ? { el: form, attr: 'data-confirm-password' }
                   :  (form.hasAttribute('data-confirm') ? { el: form, attr: 'data-confirm' } : null));
        if (!found) return;

        e.preventDefault();
        handleConfirmFlow(form, found.el, found.attr, e.submitter || null);
    }, true);

    // Links (<a>) mit data-confirm
    document.addEventListener('click', function (e) {
        const a = e.target.closest && e.target.closest('a[data-confirm]');
        if (!a) return;
        e.preventDefault();
        appModal.confirm({
            message: a.getAttribute('data-confirm'),
            title: a.getAttribute('data-confirm-title') || undefined,
            type: a.getAttribute('data-confirm-type') || 'warning'
        }).then(function (yes) { if (yes) window.location.href = a.href; });
    });
})();
