/* [URE] Dashure — sub-abas do Início, busca e senhas das anotações. */
(function () {
    'use strict';

    var STORE_KEY = 'dashure.tab';

    function store(key, value) {
        try {
            if (value === undefined) { return window.localStorage.getItem(key); }
            window.localStorage.setItem(key, value);
        } catch (e) { /* armazenamento bloqueado: só não lembra a aba */ }
        return null;
    }

    function activate(root, key) {
        var found = false;
        root.querySelectorAll('[data-dashure-tab]').forEach(function (btn) {
            var on = btn.getAttribute('data-dashure-tab') === key;
            found = found || on;
            btn.classList.toggle('is-active', on);
            btn.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        if (!found) { return false; }
        root.querySelectorAll('[data-dashure-pane]').forEach(function (pane) {
            pane.hidden = pane.getAttribute('data-dashure-pane') !== key;
        });
        return true;
    }

    /* O conteúdo da aba do GLPI chega por AJAX: restaura a sub-aba quando aparecer. */
    function init(root) {
        if (root.dataset.ready) { return; }
        root.dataset.ready = '1';
        var saved = store(STORE_KEY);
        if (saved) { activate(root.closest('.dashure') || document, saved); }
    }

    function scan() {
        document.querySelectorAll('[data-dashure-tabs]').forEach(init);
    }
    var pending = false;
    new MutationObserver(function () {
        if (pending) { return; }
        pending = true;
        window.requestAnimationFrame(function () { pending = false; scan(); });
    }).observe(document.documentElement, { childList: true, subtree: true });
    document.addEventListener('DOMContentLoaded', scan);

    function filterNotes(section) {
        var term = (section.querySelector('[data-dashure-search]') || {}).value || '';
        term = term.trim().toLowerCase();
        var activeCat = section.querySelector('[data-dashure-filter] .is-active');
        var category = activeCat ? activeCat.getAttribute('data-category') : '';
        var shown = 0;
        section.querySelectorAll('.dashure-note').forEach(function (note) {
            var ok = (!term || note.getAttribute('data-search').indexOf(term) !== -1)
                && (!category || note.getAttribute('data-category') === category);
            note.hidden = !ok;
            if (ok) { shown++; }
        });
        var empty = section.querySelector('[data-dashure-noresult]');
        if (empty) { empty.hidden = shown !== 0; }
    }

    /* http:// não tem navigator.clipboard: usa o fallback com textarea. */
    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise(function (resolve, reject) {
            var area = document.createElement('textarea');
            area.value = text;
            area.setAttribute('readonly', '');
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();
            var ok = false;
            try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
            area.remove();
            if (ok) { resolve(); } else { reject(new Error('Não foi possível copiar.')); }
        });
    }

    function flash(button, icon) {
        var i = button.querySelector('i');
        if (!i) { return; }
        var old = i.className;
        i.className = icon;
        setTimeout(function () { i.className = old; }, 1400);
    }

    function notify(message) {
        if (window.glpi_toast_error) { window.glpi_toast_error(message); } else { window.alert(message); }
    }

    async function secret(section, noteId, mode) {
        var body = new FormData();
        body.set('action', 'note_secret');
        body.set('id', noteId);
        body.set('mode', mode);
        var response = await fetch(section.getAttribute('data-ajax'), {
            method: 'POST', body: body, credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        var data = await response.json().catch(function () { return {}; });
        if (!response.ok || !data.ok) { throw new Error(data.error || 'Falha ao buscar a anotação.'); }
        return data;
    }

    var hideTimers = new WeakMap();

    document.addEventListener('click', async function (event) {
        var tab = event.target.closest('[data-dashure-tab]');
        if (tab) {
            var key = tab.getAttribute('data-dashure-tab');
            if (activate(tab.closest('.dashure'), key)) { store(STORE_KEY, key); }
            return;
        }

        var cat = event.target.closest('[data-dashure-filter] button');
        if (cat) {
            cat.parentNode.querySelectorAll('button').forEach(function (b) { b.classList.toggle('is-active', b === cat); });
            filterNotes(cat.closest('[data-dashure-notes]'));
            return;
        }

        var toggle = event.target.closest('[data-dashure-toggle-password]');
        if (toggle) {
            var input = toggle.parentNode.querySelector('[data-dashure-password]');
            input.type = input.type === 'password' ? 'text' : 'password';
            return;
        }

        var gen = event.target.closest('[data-dashure-generate]');
        if (gen) {
            var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%&*-_=+?';
            var values = new Uint32Array(16);
            window.crypto.getRandomValues(values);
            var pwd = Array.prototype.map.call(values, function (v) { return chars[v % chars.length]; }).join('');
            var field = gen.parentNode.querySelector('[data-dashure-password]');
            field.value = pwd;
            field.type = 'text';
            return;
        }

        var action = event.target.closest('[data-note-action]');
        if (!action) { return; }
        var note = action.closest('.dashure-note');
        var section = action.closest('[data-dashure-notes]');
        var mode = action.getAttribute('data-note-action');
        var pwdEl = note.querySelector('[data-note-password]');
        var extra = note.querySelector('[data-note-extra]');

        // Ocultar de novo não precisa ir ao servidor.
        if (mode === 'reveal' && note.classList.contains('is-revealed')) {
            hide(note);
            return;
        }
        action.disabled = true;
        try {
            var data = await secret(section, note.getAttribute('data-note-id'), mode);
            if (mode === 'copy_login') {
                await copyText(data.login || '');
                flash(action, 'ti ti-check');
            } else if (mode === 'copy') {
                await copyText(data.password || '');
                flash(action, 'ti ti-check');
            } else {
                if (pwdEl) { pwdEl.textContent = data.password || ''; }
                if (extra && data.notes) {
                    extra.textContent = data.notes;
                    extra.hidden = false;
                }
                note.classList.add('is-revealed');
                note.querySelectorAll('[data-note-action="reveal"] i').forEach(function (i) { i.className = 'ti ti-eye-off'; });
                // Esconde sozinho depois de 30 s.
                clearTimeout(hideTimers.get(note));
                hideTimers.set(note, setTimeout(function () { hide(note); }, 30000));
            }
        } catch (error) {
            notify(error.message);
        } finally {
            action.disabled = false;
        }
    });

    function hide(note) {
        clearTimeout(hideTimers.get(note));
        var pwdEl = note.querySelector('[data-note-password]');
        var extra = note.querySelector('[data-note-extra]');
        if (pwdEl) { pwdEl.textContent = '••••••••'; }
        if (extra) { extra.textContent = ''; extra.hidden = true; }
        note.classList.remove('is-revealed');
        note.querySelectorAll('[data-note-action="reveal"] i').forEach(function (i) { i.className = 'ti ti-eye'; });
    }

    document.addEventListener('input', function (event) {
        if (event.target.matches('[data-dashure-search]')) {
            filterNotes(event.target.closest('[data-dashure-notes]'));
        }
    });
})();
