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
            applyZFilter(section, section.dataset.zactive || '');
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

    /* "Host: Interface ens18: Bits received" -> "Host · ens18" quando o resto é igual em todas as séries. */
    function shortName(name, series) {
        var parts = name.split(': ');
        var host = parts.shift();
        var rest = parts.join(': ');
        var others = series.map(function (s) { return s.name.split(': ').slice(1).join(': '); });
        var same = others.every(function (o) { return o === rest; });
        var label = same ? host : host + ' · ' + rest.replace(/^Interface\s+/i, '').replace(/:\s*Bits (received|sent)$/i, '')
            .replace(/:\s*Disk utilization$/i, '');
        return label.length > 34 ? label.slice(0, 33) + '…' : label;
    }

    async function drawChart(fig, ajax, range, echartsSrc) {
        var msg = fig.querySelector('[data-chart-msg]');
        var canvas = fig.querySelector('[data-chart-canvas]');
        try {
            var body = new FormData();
            var graphId = fig.getAttribute('data-zabbix-graph');
            body.set('action', graphId ? 'zabbix_graph' : 'zabbix_chart');
            body.set('id', graphId || fig.getAttribute('data-zabbix-chart'));
            body.set('range', range);
            var combineKey = 'dashure.zcombine.' + fig.getAttribute('data-zabbix-chart');
            if (!graphId) { body.set('combine', store(combineKey) === '1' ? '1' : '0'); }
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
            // Escala: automática (acompanha o pico) ou comparada ao total configurado no gráfico.
            var total = Number(data.total) || 0;
            var scaleKey = 'dashure.zscale.' + (fig.getAttribute('data-zabbix-chart') || 'g' + fig.getAttribute('data-zabbix-graph'));
            var cap = fig.querySelector('figcaption');
            var tools = cap ? cap.querySelector('.dashure-ztools') : null;
            if (cap && !tools) {
                tools = document.createElement('span');
                tools.className = 'dashure-ztools';
                cap.appendChild(tools);
            }
            // Separado (uma linha por item) ou juntos (soma; em % a média).
            var combineToggle = fig.querySelector('[data-zcombine]');
            if (data.combinable && tools && !combineToggle) {
                combineToggle = document.createElement('span');
                combineToggle.className = 'dashure-zscale';
                combineToggle.setAttribute('data-zcombine', '');
                combineToggle.innerHTML = '<button type="button" data-zcombine-mode="0">Separado</button><button type="button" data-zcombine-mode="1"></button>';
                combineToggle.querySelector('[data-zcombine-mode="1"]').textContent = units === '%' ? 'Média' : 'Somado';
                tools.insertBefore(combineToggle, tools.firstChild);
            }
            if (combineToggle) {
                combineToggle.querySelectorAll('[data-zcombine-mode]').forEach(function (b) {
                    b.classList.toggle('is-active', b.getAttribute('data-zcombine-mode') === (data.combined ? '1' : '0'));
                });
                fig.dataset.combineKey = combineKey;
            }
            var toggle = fig.querySelector('[data-zscale]');
            if (total > 0 && cap && !toggle) {
                toggle = document.createElement('span');
                toggle.className = 'dashure-zscale';
                toggle.setAttribute('data-zscale', '');
                toggle.innerHTML = '<button type="button" data-zscale-mode="auto">Automática</button><button type="button" data-zscale-mode="total"></button>';
                toggle.querySelector('[data-zscale-mode="total"]').textContent = 'Total (' + formatValue(total, units) + ')';
                tools.appendChild(toggle);
            } else if (total <= 0 && toggle) {
                toggle.remove();
                toggle = null;
            }
            var scaleMode = total > 0 && store(scaleKey) === 'total' ? 'total' : 'auto';
            if (toggle) {
                toggle.querySelectorAll('[data-zscale-mode]').forEach(function (b) {
                    b.classList.toggle('is-active', b.getAttribute('data-zscale-mode') === scaleMode);
                });
                fig.dataset.scaleKey = scaleKey;
            }
            chart.setOption({
                color: PALETTE,
                animation: false,
                grid: { left: 8, right: 16, top: 12, bottom: 40, containLabel: true },
                tooltip: {
                    trigger: 'axis', confine: true,
                    valueFormatter: function (v) { return formatValue(v, units); }
                },
                legend: {
                    type: 'scroll', bottom: 0, left: 0, right: 0, itemGap: 14, itemWidth: 14, itemHeight: 8,
                    textStyle: { color: text, fontSize: 11 },
                    pageTextStyle: { color: text }, pageIconSize: 10,
                    // Nome completo no tooltip; na legenda só o essencial.
                    formatter: function (name) { return shortName(name, data.series); },
                    tooltip: { show: true }
                },
                xAxis: {
                    type: 'time', min: data.from, max: data.to,
                    // Cabeçalho do tooltip em dd/mm/aaaa hh:mm (padrão BR).
                    axisPointer: {
                        label: {
                            formatter: function (p) {
                                var d = new Date(p.value);
                                var z = function (n) { return (n < 10 ? '0' : '') + n; };
                                return z(d.getDate()) + '/' + z(d.getMonth() + 1) + '/' + d.getFullYear()
                                    + ' ' + z(d.getHours()) + ':' + z(d.getMinutes());
                            }
                        }
                    },
                    axisLabel: {
                        color: text, fontSize: 10, hideOverlap: true,
                        formatter: function (value) {
                            var d = new Date(value);
                            var p = function (n) { return (n < 10 ? '0' : '') + n; };
                            return (data.to - data.from) > 2 * 86400000
                                ? p(d.getDate()) + '/' + p(d.getMonth() + 1)
                                : p(d.getHours()) + ':' + p(d.getMinutes());
                        }
                    },
                    axisLine: { lineStyle: { color: grid } }, splitLine: { show: false }
                },
                yAxis: {
                    type: 'value', min: 0, max: scaleMode === 'total' ? total : null,
                    axisLabel: { color: text, fontSize: 10, formatter: function (v) { return formatValue(v, units); } },
                    splitLine: { lineStyle: { color: grid, type: 'dashed' } }
                },
                series: data.series.map(function (s, index) {
                    return {
                        markLine: scaleMode === 'total' && index === 0 ? {
                            silent: true, symbol: 'none',
                            lineStyle: { color: '#dc2626', type: 'dashed', width: 1.2 },
                            label: { formatter: 'Total ' + formatValue(total, units), color: '#dc2626', fontSize: 10, position: 'insideEndTop' },
                            data: [{ yAxis: total }]
                        } : undefined,
                        itemStyle: s.color ? { color: s.color } : undefined,
                        name: s.name, type: 'line', data: s.data, showSymbol: false, smooth: false, connectNulls: false,
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

    /* Contadores do Zabbix: "sev:N" filtra os problemas; "host:estado" mostra os cartões dos hosts. */
    function applyZFilter(section, filter) {
        section.dataset.zactive = filter;
        var summary = section.querySelector('[data-zabbix-summary]');
        if (!summary) { return; }
        summary.querySelectorAll('[data-zfilter]').forEach(function (b) {
            var on = b.getAttribute('data-zfilter') === filter;
            b.classList.toggle('is-selected', on);
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        var problems = summary.querySelector('[data-zview="problems"]');
        var hosts = summary.querySelector('[data-zview="hosts"]');
        if (!problems || !hosts) { return; }
        var parts = filter.split(':');
        var showHosts = parts[0] === 'host';
        problems.hidden = showHosts;
        hosts.hidden = !showHosts;
        var shown = 0;
        if (showHosts) {
            var titles = { all: 'Todos os hosts', ok: 'Hosts sem problemas', problem: 'Hosts com problemas', down: 'Hosts indisponíveis' };
            hosts.querySelectorAll('[data-host-state]').forEach(function (card) {
                var ok = parts[1] === 'all' || card.getAttribute('data-host-state') === parts[1];
                card.hidden = !ok;
                if (ok) { shown++; }
            });
            hosts.querySelector('[data-zview-title]').textContent = (titles[parts[1]] || 'Hosts') + ' (' + shown + ')';
            hosts.querySelector('[data-zview-empty]').hidden = shown !== 0;
        } else {
            var list = problems.querySelectorAll('.dashure-problems > li');
            list.forEach(function (li) {
                var ok = parts[0] !== 'sev' || li.getAttribute('data-severity') === parts[1];
                li.hidden = !ok;
                if (ok) { shown++; }
            });
            var empty = problems.querySelector('[data-zview-empty]');
            if (empty) { empty.hidden = shown !== 0 || !list.length; }
            var title = problems.querySelector('[data-zview-title]');
            var btn = filter ? summary.querySelector('[data-zfilter="' + filter + '"] span') : null;
            title.textContent = btn ? 'Problemas — ' + btn.textContent + ' (' + shown + ')' : 'Problemas ativos (' + list.length + ')';
        }
    }

    /* Janela com todos os gráficos do host (os mesmos configurados no Zabbix). */
    async function openHostGraphs(button) {
        var section = button.closest('[data-dashure-zabbix]');
        var ajax = section.getAttribute('data-ajax');
        var src = section.getAttribute('data-echarts');
        var layer = document.createElement('div');
        layer.className = 'dashure-modal-layer';
        layer.innerHTML = '<div class="dashure-modal-backdrop" data-zmodal-close></div>'
            + '<div class="dashure-modal" role="dialog" aria-modal="true" aria-labelledby="dashure-zmodal-title">'
            + '<header class="dashure-modal-head"><div><h3 id="dashure-zmodal-title"><i class="ti ti-chart-histogram"></i> </h3>'
            + '<p class="dashure-muted">Gráficos do Zabbix para este host</p></div>'
            + '<div class="dashure-range" role="group" aria-label="Período"></div>'
            + '<button type="button" class="dashure-icon-btn" data-zmodal-close aria-label="Fechar"><i class="ti ti-x"></i></button></header>'
            + '<div class="dashure-modal-body"><p class="dashure-muted"><i class="ti ti-loader-2 dashure-spin"></i> Carregando gráficos…</p></div></div>';
        layer.querySelector('h3').appendChild(document.createTextNode(button.getAttribute('data-zhost-name') || 'Host'));
        var rangeBox = layer.querySelector('.dashure-range');
        section.querySelectorAll('[data-zabbix-charts] [data-zabbix-range]').forEach(function (b) {
            var copy = document.createElement('button');
            copy.type = 'button';
            copy.textContent = b.textContent;
            copy.setAttribute('data-zmodal-range', b.getAttribute('data-zabbix-range'));
            rangeBox.appendChild(copy);
        });
        if (!rangeBox.children.length) {
            [['1h', '1 hora'], ['6h', '6 horas'], ['12h', '12 horas'], ['1d', '1 dia'], ['7d', '7 dias'], ['30d', '30 dias']].forEach(function (r) {
                var c = document.createElement('button');
                c.type = 'button'; c.textContent = r[1]; c.setAttribute('data-zmodal-range', r[0]);
                rangeBox.appendChild(c);
            });
        }
        document.body.appendChild(layer);
        document.body.classList.add('dashure-modal-open');
        var body = layer.querySelector('.dashure-modal-body');

        function close() {
            document.removeEventListener('keydown', onKey, true);
            if (window.echarts) {
                layer.querySelectorAll('[data-chart-canvas]').forEach(function (el) {
                    var c = window.echarts.getInstanceByDom(el);
                    if (c) { c.dispose(); }
                });
            }
            layer.remove();
            document.body.classList.remove('dashure-modal-open');
            button.focus();
        }
        function onKey(e) { if (e.key === 'Escape') { e.preventDefault(); close(); } }
        document.addEventListener('keydown', onKey, true);
        layer.addEventListener('click', function (e) {
            if (e.target.closest('[data-zmodal-close]')) { close(); return; }
            var r = e.target.closest('[data-zmodal-range]');
            if (r) { store(RANGE_KEY, r.getAttribute('data-zmodal-range')); draw(); }
        });

        function draw() {
            var range = store(RANGE_KEY) || '1d';
            rangeBox.querySelectorAll('[data-zmodal-range]').forEach(function (b) {
                b.classList.toggle('is-active', b.getAttribute('data-zmodal-range') === range);
            });
            layer.querySelectorAll('[data-zabbix-graph]').forEach(function (fig) {
                var m = fig.querySelector('[data-chart-msg]');
                m.textContent = 'Carregando…';
                m.hidden = false;
                drawChart(fig, ajax, range, src);
            });
        }

        try {
            var form = new FormData();
            form.set('action', 'zabbix_host_graphs');
            form.set('id', button.getAttribute('data-zhost-graphs'));
            var response = await fetch(ajax, { method: 'POST', body: form, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            var data = await response.json().catch(function () { return {}; });
            if (!response.ok || !data.ok) { throw new Error(data.error || 'Falha ao listar os gráficos.'); }
            if (!data.graphs || !data.graphs.length) {
                body.innerHTML = '<p class="dashure-muted">Este host não tem gráficos configurados no Zabbix.</p>';
                return;
            }
            var grid = document.createElement('div');
            grid.className = 'dashure-zchart-grid';
            data.graphs.forEach(function (g) {
                var fig = document.createElement('figure');
                fig.className = 'dashure-zchart';
                fig.setAttribute('data-zabbix-graph', g.id);
                var cap = document.createElement('figcaption');
                cap.textContent = g.name;
                fig.appendChild(cap);
                fig.insertAdjacentHTML('beforeend', '<div class="dashure-zchart-canvas" data-chart-canvas></div><p class="dashure-muted dashure-zchart-msg" data-chart-msg>Carregando…</p>');
                grid.appendChild(fig);
            });
            body.innerHTML = '';
            body.appendChild(grid);
            draw();
        } catch (error) {
            body.innerHTML = '';
            var p = document.createElement('p');
            p.className = 'dashure-muted';
            p.textContent = error.message;
            body.appendChild(p);
        }
    }

    /* Preventiva: os contadores mostram só as escolas correspondentes. */
    function applyPrevFilter(section, filter) {
        section.dataset.pactive = filter;
        section.querySelectorAll('[data-prev-filter]').forEach(function (b) {
            var on = b.getAttribute('data-prev-filter') === filter;
            b.classList.toggle('is-selected', on);
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        var showMissing = filter === '' || filter === 'all' || filter === 'missing';
        var showResponded = filter !== 'missing';
        var onlyProblems = filter === 'problems';
        var onlyNoProblems = filter === 'noproblems';
        var cols = { missing: showMissing, responded: showResponded };
        Object.keys(cols).forEach(function (key) {
            var col = section.querySelector('[data-prev-col="' + key + '"]');
            if (col) { col.hidden = !cols[key]; }
        });
        var grid = section.querySelector('.dashure-prev-grid');
        if (grid) { grid.classList.toggle('is-single', !(showMissing && showResponded)); }
        section.querySelectorAll('[data-prev-col="responded"] li[data-prev-problems]').forEach(function (li) {
            var has = li.getAttribute('data-prev-problems') === '1';
            li.classList.toggle('is-out', (onlyProblems && !has) || (onlyNoProblems && has));
        });
        var title = section.querySelector('[data-prev-col="responded"] [data-prev-title]');
        if (title) {
            var items = section.querySelectorAll('[data-prev-col="responded"] li[data-prev-problems]:not(.is-out)').length;
            title.textContent = (onlyProblems ? 'Responderam com problemas' : (onlyNoProblems ? 'Responderam sem problemas' : 'Já responderam')) + ' (' + items + ')';
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

        var zc = event.target.closest('[data-zcombine-mode]');
        if (zc) {
            var cfig = zc.closest('[data-zabbix-chart]');
            store(cfig.dataset.combineKey, zc.getAttribute('data-zcombine-mode'));
            var csec = zc.closest('[data-dashure-zabbix]');
            drawChart(cfig, csec.getAttribute('data-ajax'), store(RANGE_KEY) || '1d', csec.getAttribute('data-echarts'));
            return;
        }

        var zs = event.target.closest('[data-zscale-mode]');
        if (zs) {
            var zfig = zs.closest('[data-zabbix-chart], [data-zabbix-graph]');
            store(zfig.dataset.scaleKey, zs.getAttribute('data-zscale-mode'));
            var zsection = zs.closest('[data-dashure-zabbix]');
            drawChart(zfig, zsection.getAttribute('data-ajax'), store(RANGE_KEY) || '1d', zsection.getAttribute('data-echarts'));
            return;
        }

        var pf = event.target.closest('[data-prev-filter]');
        if (pf) {
            var psec = pf.closest('[data-dashure-prev]');
            var pvalue = pf.getAttribute('data-prev-filter');
            applyPrevFilter(psec, psec.dataset.pactive === pvalue ? '' : pvalue);
            return;
        }

        var pc = event.target.closest('[data-prev-copy]');
        if (pc) {
            var names = Array.prototype.map.call(
                pc.closest('[data-dashure-prev]').querySelectorAll('[data-prev-missing]'),
                function (li) { return li.getAttribute('data-prev-missing'); }
            );
            try {
                await copyText(names.join('\n'));
                var old = pc.innerHTML;
                pc.innerHTML = '<i class="ti ti-check"></i> ' + names.length + ' escola(s) copiada(s)';
                setTimeout(function () { pc.innerHTML = old; }, 1800);
            } catch (e) { notify('Não foi possível copiar a lista.'); }
            return;
        }

        var zg = event.target.closest('[data-zhost-graphs]');
        if (zg) {
            openHostGraphs(zg);
            return;
        }

        var zf = event.target.closest('[data-zfilter]');
        if (zf) {
            var zsec = zf.closest('[data-dashure-zabbix]');
            var value = zf.getAttribute('data-zfilter');
            applyZFilter(zsec, zsec.dataset.zactive === value ? '' : value);
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
        if (event.target.matches('[data-prev-search]')) {
            var term = event.target.value.trim().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
            event.target.closest('[data-dashure-prev]').querySelectorAll('[data-prev-name]').forEach(function (li) {
                var name = li.getAttribute('data-prev-name').normalize('NFD').replace(/[\u0300-\u036f]/g, '');
                li.hidden = term !== '' && name.indexOf(term) === -1;
            });
        }
    });
})();
