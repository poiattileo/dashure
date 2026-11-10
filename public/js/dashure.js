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
        if (key === 'zabbix') {
            var zbx = root.querySelector('[data-dashure-zabbix]');
            if (zbx) { loadZabbix(zbx); }
        }
        return true;
    }

    /* O conteúdo da aba do GLPI chega por AJAX: restaura a sub-aba quando aparecer. */
    function init(root) {
        if (root.dataset.ready) { return; }
        root.dataset.ready = '1';
        var saved = store(STORE_KEY);
        if (saved) { activate(root.closest('.dashure') || document, saved); }
        var host = root.closest('.dashure') || document;
        var zbx = host.querySelector('[data-dashure-zabbix]');
        var zpane = zbx && zbx.closest('[data-dashure-pane]');
        if (zbx && zpane && !zpane.hidden) { loadZabbix(zbx); }
    }

    /* Painel Zabbix: carrega ao abrir a sub-aba e atualiza enquanto estiver visível. */
    async function loadZabbix(section) {
        if (section.dataset.loading) { return; }
        section.dataset.loading = '1';
        try {
            var body = new FormData();
            body.set('action', 'zabbix_panel');
            var response = await fetch(section.getAttribute('data-ajax'), {
                method: 'POST', body: body, credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            var data = await response.json().catch(function () { return {}; });
            if (!response.ok || !data.ok) { throw new Error(data.error || 'Falha ao consultar o Zabbix.'); }
            (section.querySelector('[data-zabbix-summary]') || section).innerHTML = data.html;
        } catch (error) {
            var p = document.createElement('p');
            p.className = 'dashure-muted dashure-zabbix-error';
            p.textContent = error.message;
            var old = section.querySelector('.dashure-zabbix-error');
            if (old) { old.remove(); }
            var target = section.querySelector('[data-zabbix-summary]') || section;
            if (target.querySelector('.dashure-card')) { target.prepend(p); } else { target.innerHTML = ''; target.appendChild(p); }
        } finally {
            delete section.dataset.loading;
        }
        loadCharts(section);
        if (!section.dataset.timer) {
            var seconds = Math.max(30, parseInt(section.getAttribute('data-refresh'), 10) || 60);
            section.dataset.timer = String(window.setInterval(function () {
                if (!document.body.contains(section)) {
                    window.clearInterval(parseInt(section.dataset.timer, 10));
                    return;
                }
                var pane = section.closest('[data-dashure-pane]');
                if (!document.hidden && pane && !pane.hidden) { loadZabbix(section); }
            }, seconds * 1000));
        }
    }

    /* ---------- Gráficos do Zabbix (ECharts, que já vem com o GLPI) ---------- */
    var RANGE_KEY = 'dashure.zrange';
    var echartsPromise = null;

    function ensureEcharts(src) {
        if (window.echarts) { return Promise.resolve(window.echarts); }
        if (!echartsPromise) {
            echartsPromise = new Promise(function (resolve, reject) {
                var s = document.createElement('script');
                s.src = src;
                s.onload = function () { resolve(window.echarts); };
                s.onerror = function () { echartsPromise = null; reject(new Error('Não foi possível carregar a biblioteca de gráficos.')); };
                document.head.appendChild(s);
            });
        }
        return echartsPromise;
    }

    function formatValue(value, units) {
        var v = Number(value);
        if (!isFinite(v)) { return ''; }
        var abs = Math.abs(v);
        if (units === 'B' || units === 'Bps' || units === 'bps') {
            var base = units === 'bps' ? 1000 : 1024;
            var names = units === 'bps' ? ['bps', 'Kbps', 'Mbps', 'Gbps', 'Tbps']
                : (units === 'Bps' ? ['B/s', 'KB/s', 'MB/s', 'GB/s', 'TB/s'] : ['B', 'KB', 'MB', 'GB', 'TB']);
            var i = 0;
            while (abs >= base && i < names.length - 1) { v /= base; abs /= base; i++; }
            return (abs >= 100 ? v.toFixed(0) : abs >= 10 ? v.toFixed(1) : v.toFixed(2)) + ' ' + names[i];
        }
        var txt = abs >= 100 ? v.toFixed(0) : abs >= 10 ? v.toFixed(1) : v.toFixed(2);
        return units ? txt + ' ' + units : txt;
    }

    var PALETTE = ['#e45959', '#f2b134', '#26b5a3', '#5b6ee1', '#9c5bd6', '#3fa7f5', '#7cc75b', '#f07b3f', '#d6539c', '#8d99ae', '#00a6a6', '#c9a227'];

    function cssVar(name, fallback) {
        var v = getComputedStyle(document.body).getPropertyValue(name).trim();
        return v || fallback;
    }

    async function drawChart(fig, ajax, range, echartsSrc) {
        var msg = fig.querySelector('[data-chart-msg]');
        var canvas = fig.querySelector('[data-chart-canvas]');
        try {
            var body = new FormData();
            body.set('action', 'zabbix_chart');
            body.set('id', fig.getAttribute('data-zabbix-chart'));
            body.set('range', range);
            var response = await fetch(ajax, {
                method: 'POST', body: body, credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            var data = await response.json().catch(function () { return {}; });
            if (!response.ok || !data.ok) { throw new Error(data.error || 'Falha ao buscar o gráfico.'); }
            if (!data.series || !data.series.length) {
                msg.textContent = 'Sem dados para esse período (confira o padrão do gráfico).';
                msg.hidden = false;
                canvas.hidden = true;
                return;
            }
            var echarts = await ensureEcharts(echartsSrc);
            canvas.hidden = false;
            msg.hidden = !data.truncated;
            if (data.truncated) { msg.textContent = 'Mostrando as 12 séries de maior média.'; }
            var chart = echarts.getInstanceByDom(canvas) || echarts.init(canvas);
            var text = cssVar('--tblr-body-color', '#334155');
            var grid = cssVar('--tblr-border-color', '#e2e8f0');
            var units = data.units || '';
            var stacked = units === 'B';
            chart.setOption({
                color: PALETTE,
                animation: false,
                grid: { left: 8, right: 16, top: 12, bottom: 8, containLabel: true },
                tooltip: {
                    trigger: 'axis', confine: true,
                    valueFormatter: function (v) { return formatValue(v, units); }
                },
                legend: { type: 'scroll', bottom: 0, textStyle: { color: text, fontSize: 11 }, itemWidth: 14, itemHeight: 8 },
                xAxis: {
                    type: 'time', min: data.from, max: data.to,
                    axisLabel: { color: text, fontSize: 10, hideOverlap: true },
                    axisLine: { lineStyle: { color: grid } }, splitLine: { show: false }
                },
                yAxis: {
                    type: 'value', min: 0,
                    axisLabel: { color: text, fontSize: 10, formatter: function (v) { return formatValue(v, units); } },
                    splitLine: { lineStyle: { color: grid, type: 'dashed' } }
                },
                series: data.series.map(function (s) {
                    return {
                        name: s.name, type: 'line', data: s.data, showSymbol: false, smooth: false,
                        stack: stacked ? 'total' : undefined, lineStyle: { width: 1.4 },
                        areaStyle: { opacity: stacked ? 0.55 : 0.12 }, sampling: 'lttb'
                    };
                })
            }, true);
            canvas.style.paddingBottom = '0';
            chart.resize();
        } catch (error) {
            msg.textContent = error.message;
            msg.hidden = false;
        }
    }

    function loadCharts(section, force) {
        var box = section.querySelector('[data-zabbix-charts]');
        if (!box) { return; }
        var stored = store(RANGE_KEY) || '1d';
        var btn = box.querySelector('[data-zabbix-range="' + stored + '"]') || box.querySelector('[data-zabbix-range]');
        var range = btn ? btn.getAttribute('data-zabbix-range') : '1d';
        box.querySelectorAll('[data-zabbix-range]').forEach(function (b) { b.classList.toggle('is-active', b === btn); });
        var ajax = section.getAttribute('data-ajax');
        var src = section.getAttribute('data-echarts');
        box.querySelectorAll('[data-zabbix-chart]').forEach(function (fig) {
            if (force) {
                var m = fig.querySelector('[data-chart-msg]');
                m.textContent = 'Carregando…';
                m.hidden = false;
            }
            drawChart(fig, ajax, range, src);
        });
    }

    window.addEventListener('resize', function () {
        if (!window.echarts) { return; }
        document.querySelectorAll('[data-chart-canvas]').forEach(function (el) {
            var c = window.echarts.getInstanceByDom(el);
            if (c) { c.resize(); }
        });
    });

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

        var rangeBtn = event.target.closest('[data-zabbix-range]');
        if (rangeBtn) {
            store(RANGE_KEY, rangeBtn.getAttribute('data-zabbix-range'));
            loadCharts(rangeBtn.closest('[data-dashure-zabbix]'), true);
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
