// RegistrArc - assets/js/dashboard.js (scripts de dashboard.php)
//
// Chiffres et graphiques interactifs du tableau de bord, dessinés en SVG sans
// bibliothèque externe :
// - filtres (départ, montants ou engagements) appliqués à tout ce qui suit
// - chiffres clés et jauge d'encaissement
// - courbe de l'encaissé au fil du temps (journal de caisse), avec réticule
// - barres empilées encaissé / reste dû par départ et par club, barres par mode
// - chaque graphique a sa vue tableau (mêmes données, sans survol)
//
// Les noms (clubs, modes) sont insérés avec textContent, jamais en HTML.

(function () {
    const dataEl = document.getElementById('rgVizData');
    const root = document.getElementById('rgViz');

    if (!dataEl || !root) {
        return;
    }

    const data = JSON.parse(dataEl.textContent);
    const SVG_NS = 'http://www.w3.org/2000/svg';
    const state = { session: '*', metric: 'amount', views: {} };
    const MAX_CLUBS = 12;

    // -----------------------------------------------------------------------
    // Formats
    // -----------------------------------------------------------------------
    const nf0 = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 });
    const nf1 = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 1 });
    const nf2 = new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    function euro(v) {
        return (Math.abs(v - Math.round(v)) < 0.005 ? nf0.format(Math.round(v)) : nf2.format(v)) + ' €';
    }

    function value(v) {
        return state.metric === 'amount' ? euro(v) : nf0.format(v);
    }

    function tick(v) {
        const text = Math.abs(v) >= 1000 ? nf1.format(v / 1000) + ' k' : nf0.format(v);

        return state.metric === 'amount' ? text + (Math.abs(v) >= 1000 ? '€' : ' €') : text;
    }

    function signedEuro(v) {
        return (v > 0 ? '+' : v < 0 ? '−' : '') + euro(Math.abs(v));
    }

    function sessionLabel(s) {
        return s === '' ? 'Sans départ' : 'Départ ' + s;
    }

    function modeLabel(code) {
        return (data.modes && data.modes[code]) || code || '?';
    }

    const OPERATION_LABELS = { payment: 'Encaissement', cancel: 'Annulation', refund: 'Remboursement' };

    function parseDate(s) {
        const m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/.exec(s || '');

        return m ? new Date(+m[1], m[2] - 1, +m[3], +m[4], +m[5], +(m[6] || 0)).getTime() : NaN;
    }

    const dateFull = new Intl.DateTimeFormat('fr-FR', { weekday: 'short', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
    const dateDay = new Intl.DateTimeFormat('fr-FR', { day: '2-digit', month: '2-digit' });
    const dateTime = new Intl.DateTimeFormat('fr-FR', { hour: '2-digit', minute: '2-digit' });

    // -----------------------------------------------------------------------
    // Petits outils DOM / SVG
    // -----------------------------------------------------------------------
    function el(tag, attrs, text) {
        const node = document.createElement(tag);

        Object.keys(attrs || {}).forEach(function (k) {
            node.setAttribute(k, attrs[k]);
        });

        if (text !== undefined) {
            node.textContent = text;
        }

        return node;
    }

    function svg(tag, attrs, text) {
        const node = document.createElementNS(SVG_NS, tag);

        Object.keys(attrs || {}).forEach(function (k) {
            node.setAttribute(k, attrs[k]);
        });

        if (text !== undefined) {
            node.textContent = text;
        }

        return node;
    }

    const measureCtx = document.createElement('canvas').getContext('2d');

    function textWidth(text, size) {
        measureCtx.font = (size || 12) + 'px system-ui, -apple-system, "Segoe UI", Roboto, sans-serif';

        return measureCtx.measureText(text).width;
    }

    function fitText(text, max, size) {
        if (textWidth(text, size) <= max) {
            return text;
        }

        let s = text;

        while (s.length > 1 && textWidth(s + '…', size) > max) {
            s = s.slice(0, -1);
        }

        return s.trimEnd() + '…';
    }

    function niceStep(max, count) {
        const raw = max / Math.max(1, count);
        const pow = Math.pow(10, Math.floor(Math.log10(raw)));
        const n = raw / pow;

        return (n <= 1 ? 1 : n <= 2 ? 2 : n <= 2.5 ? 2.5 : n <= 5 ? 5 : 10) * pow;
    }

    function niceTicks(max, count) {
        if (!(max > 0)) {
            return { max: 1, ticks: [0, 1] };
        }

        let step = niceStep(max, count);

        // Des engagements se comptent en entiers
        if (state.metric === 'count') {
            step = Math.max(1, step === 2.5 ? 2 : Math.ceil(step));
        }

        const top = Math.ceil(max / step) * step;
        const ticks = [];

        for (let v = 0; v <= top + step / 2; v += step) {
            ticks.push(Math.round(v * 100) / 100);
        }

        return { max: top, ticks: ticks };
    }

    // Barre horizontale : bout arrondi de 4px côté valeur, carrée côté base
    function barPath(x, y, w, h, rounded) {
        const r = rounded ? Math.min(4, w, h / 2) : 0;

        return 'M' + x + ',' + y
            + 'H' + (x + w - r)
            + (r ? 'Q' + (x + w) + ',' + y + ' ' + (x + w) + ',' + (y + r) : '')
            + 'V' + (y + h - r)
            + (r ? 'Q' + (x + w) + ',' + (y + h) + ' ' + (x + w - r) + ',' + (y + h) : '')
            + 'H' + x + 'Z';
    }

    // -----------------------------------------------------------------------
    // Infobulle (une par carte) : la valeur d'abord, le libellé ensuite
    // -----------------------------------------------------------------------
    function tooltip(card) {
        let tip = card.querySelector('.rg-viz-tip');

        if (!tip) {
            tip = el('div', { class: 'rg-viz-tip', role: 'status' });
            tip.hidden = true;
            card.appendChild(tip);
        }

        return {
            show: function (title, rows, footer, x, y) {
                tip.textContent = '';
                tip.appendChild(el('div', { class: 'rg-viz-tip-title' }, title));

                rows.forEach(function (row) {
                    const line = el('div', { class: 'rg-viz-tip-row' });

                    if (row.color) {
                        const key = el('span', { class: 'rg-viz-tip-key' });
                        key.style.background = row.color;
                        line.appendChild(key);
                    }

                    line.appendChild(el('strong', {}, row.value));
                    line.appendChild(el('span', {}, row.label));
                    tip.appendChild(line);
                });

                if (footer) {
                    tip.appendChild(el('div', { class: 'rg-viz-tip-foot' }, footer));
                }

                tip.hidden = false;

                const cardBox = card.getBoundingClientRect();
                const w = tip.offsetWidth;
                const h = tip.offsetHeight;
                let left = x + 14;
                let top = y - h - 10;

                if (left + w > cardBox.width - 8) {
                    left = x - w - 14;
                }

                if (top < 8) {
                    top = y + 16;
                }

                tip.style.left = Math.max(8, left) + 'px';
                tip.style.top = top + 'px';
            },
            hide: function () {
                tip.hidden = true;
            }
        };
    }

    function pointIn(card, clientX, clientY) {
        const box = card.getBoundingClientRect();

        return { x: clientX - box.left, y: clientY - box.top };
    }

    // -----------------------------------------------------------------------
    // Données filtrées et agrégées
    // -----------------------------------------------------------------------
    const SERIES = {
        amount: [
            { key: 'paid', name: 'Encaissé', color: 'var(--viz-series-1)' },
            { key: 'due', name: 'Reste dû', color: 'var(--viz-series-2)' }
        ],
        count: [
            { key: 'paid', name: 'Payés', color: 'var(--viz-series-1)' },
            { key: 'due', name: 'À payer', color: 'var(--viz-series-2)' }
        ]
    };

    function aggregate() {
        const amount = state.metric === 'amount';
        const out = {
            kpi: { collected: 0, remaining: 0, paid: 0, due: 0, free: 0, refunded: 0 },
            sessions: {},
            clubs: {},
            modes: {}
        };

        data.records.forEach(function (r) {
            const session = r[0];

            if (state.session !== '*' && session !== state.session) {
                return;
            }

            const st = r[2];
            const paid = amount ? r[4] : (st === 'paid' ? 1 : 0);
            const due = amount ? r[5] : (st === 'due' ? 1 : 0);

            out.kpi.collected += r[4];
            out.kpi.remaining += r[5];
            out.kpi[st] = (out.kpi[st] || 0) + 1;

            [[out.sessions, session], [out.clubs, r[1]]].forEach(function (pair) {
                const g = pair[0][pair[1]] || (pair[0][pair[1]] = { paid: 0, due: 0, count: 0 });
                g.paid += paid;
                g.due += due;
                g.count++;
            });

            if (st === 'paid') {
                out.modes[r[3]] = (out.modes[r[3]] || 0) + paid;
            }
        });

        return out;
    }

    // Opérations de caisse du départ choisi, cumulées dans l'ordre du temps
    function timelinePoints() {
        const amount = state.metric === 'amount';
        let total = 0;

        return data.timeline
            .map(function (op, i) {
                return { t: parseDate(op[0]), i: i, type: op[1], amount: op[2], method: op[3], session: op[4] };
            })
            .filter(function (op) {
                return !isNaN(op.t) && (state.session === '*' || op.session === state.session);
            })
            .sort(function (a, b) {
                return a.t - b.t || a.i - b.i;
            })
            .map(function (op) {
                op.delta = amount ? op.amount : (op.type === 'payment' ? 1 : -1);
                total = Math.round((total + op.delta) * 100) / 100;
                op.v = total;

                return op;
            });
    }

    // -----------------------------------------------------------------------
    // Chiffres clés
    // -----------------------------------------------------------------------
    function renderKpi(agg) {
        const card = document.getElementById('rgVizKpi');
        const k = agg.kpi;
        const expected = k.collected + k.remaining;
        const percent = expected > 0 ? Math.round(k.collected * 100 / expected) : 0;

        card.querySelectorAll(':scope > :not(h2)').forEach(function (n) {
            n.remove();
        });

        const figures = el('div', { class: 'rg-figures' });

        [
            [euro(expected), 'attendu', null],
            [euro(k.collected), 'encaissé', 'var(--viz-series-1)'],
            [euro(k.remaining), 'reste dû', 'var(--viz-series-2)'],
            [nf0.format(k.due), 'engagement(s) à payer', null],
            [nf0.format(k.paid), 'payé(s)', null],
            [nf0.format(k.free), 'gratuit(s)', null],
            [nf0.format(k.refunded), 'remboursé(s)', null]
        ].forEach(function (f) {
            const fig = el('div', { class: 'rg-figure' });
            fig.appendChild(el('strong', {}, f[0]));
            const label = el('span', {});

            if (f[2]) {
                const key = el('i', { class: 'rg-viz-key', 'aria-hidden': 'true' });
                key.style.background = f[2];
                label.appendChild(key);
            }

            label.appendChild(document.createTextNode(f[1]));
            fig.appendChild(label);
            figures.appendChild(fig);
        });

        card.appendChild(figures);

        const meter = el('div', {
            class: 'rg-viz-meter',
            role: 'meter',
            'aria-valuemin': '0',
            'aria-valuemax': '100',
            'aria-valuenow': String(percent),
            'aria-label': 'Part du montant attendu déjà encaissée'
        });
        const fill = el('span', {});
        fill.style.width = percent + '%';
        meter.appendChild(fill);
        card.appendChild(meter);
        card.appendChild(el('p', { class: 'rg-help rg-viz-meter-label' },
            percent + ' % du montant attendu est encaissé'
            + (state.session !== '*' ? ' pour le ' + sessionLabel(state.session).toLowerCase() : '') + '.'));
    }

    // -----------------------------------------------------------------------
    // Barres horizontales (empilées si plusieurs séries)
    // rows : [{ label, title, values: [..], onSelect }]
    // -----------------------------------------------------------------------
    function renderBars(card, body, rows, series, opts) {
        const tip = tooltip(card);
        const width = Math.max(260, body.clientWidth);
        const band = 32;
        const thick = 20;
        const axisBand = 26;
        const valueSpace = 76;
        const labelMax = Math.min(220, Math.round(width * 0.38));
        // Colonne des libellés : la largeur du plus long (+ marge), plafonnée
        const labelWidth = Math.min(labelMax, Math.ceil(Math.max.apply(null, rows.map(function (r) {
            return textWidth(r.label, 12);
        }).concat([40]))) + 20);
        const plotX = labelWidth;
        const plotW = width - labelWidth - valueSpace;
        const totals = rows.map(function (r) {
            return r.values.reduce(function (a, b) { return a + b; }, 0);
        });
        const scale = niceTicks(Math.max.apply(null, totals.concat([0])), Math.max(2, Math.floor(plotW / 90)));
        const x = function (v) { return plotX + v / scale.max * plotW; };
        const height = rows.length * band + axisBand;

        const chart = svg('svg', {
            width: width,
            height: height,
            viewBox: '0 0 ' + width + ' ' + height,
            role: 'img',
            'aria-label': opts.ariaLabel
        });

        // Grille et axe : traits fins, en retrait
        scale.ticks.forEach(function (t) {
            chart.appendChild(svg('line', { x1: x(t), x2: x(t), y1: 0, y2: rows.length * band, class: t === 0 ? 'rg-viz-axis' : 'rg-viz-grid' }));
            chart.appendChild(svg('text', { x: x(t), y: rows.length * band + 17, class: 'rg-viz-tick', 'text-anchor': 'middle' }, tick(t)));
        });

        rows.forEach(function (row, i) {
            const y = i * band + (band - thick) / 2;
            const group = svg('g', { class: 'rg-viz-row' + (row.onSelect ? ' is-clickable' : ''), tabindex: '0' });
            group.appendChild(svg('rect', { x: 0, y: i * band, width: width, height: band, class: 'rg-viz-hit' }));
            group.appendChild(svg('text', { x: labelWidth - 10, y: i * band + band / 2 + 4, class: 'rg-viz-cat', 'text-anchor': 'end' }, fitText(row.label, labelWidth - 12, 12)));

            // Segments : 2px de fond entre deux segments, bout arrondi sur le dernier
            let acc = 0;
            const last = row.values.reduce(function (l, v, j) { return v > 0 ? j : l; }, -1);

            row.values.forEach(function (v, j) {
                if (!(v > 0)) {
                    return;
                }

                const x0 = x(acc);
                let w = x(acc + v) - x0;
                acc += v;

                if (j !== last) {
                    w = Math.max(0, w - 2);
                }

                if (w > 0) {
                    const path = svg('path', { d: barPath(x0, y, w, thick, j === last), class: 'rg-viz-bar' });
                    path.style.fill = series[j].color;
                    group.appendChild(path);
                }
            });

            group.appendChild(svg('text', { x: x(totals[i]) + 6, y: i * band + band / 2 + 4, class: 'rg-viz-value' }, value(totals[i])));

            const show = function (px, py) {
                tip.show(
                    row.title || row.label,
                    series.map(function (s, j) {
                        return { value: value(row.values[j]), label: s.name, color: series.length > 1 ? s.color : null };
                    }),
                    series.length > 1 ? 'Total : ' + value(totals[i]) + (row.onSelect ? ' · cliquer pour filtrer' : '') : (row.note || ''),
                    px, py
                );
            };

            group.addEventListener('pointermove', function (ev) {
                const p = pointIn(card, ev.clientX, ev.clientY);
                show(p.x, p.y);
            });
            group.addEventListener('pointerleave', tip.hide);
            group.addEventListener('focus', function () {
                const box = group.getBoundingClientRect();
                const p = pointIn(card, box.left + x(totals[i]), box.top);
                show(p.x, p.y + band / 2);
            });
            group.addEventListener('blur', tip.hide);

            if (row.onSelect) {
                group.addEventListener('click', row.onSelect);
                group.addEventListener('keydown', function (ev) {
                    if (ev.key === 'Enter' || ev.key === ' ') {
                        ev.preventDefault();
                        row.onSelect();
                    }
                });
            }

            chart.appendChild(group);
        });

        body.appendChild(chart);
    }

    function legend(series) {
        const box = el('div', { class: 'rg-viz-legend' });

        series.forEach(function (s) {
            const item = el('span', { class: 'rg-viz-legend-item' });
            const key = el('i', { class: 'rg-viz-key', 'aria-hidden': 'true' });
            key.style.background = s.color;
            item.appendChild(key);
            item.appendChild(document.createTextNode(s.name));
            box.appendChild(item);
        });

        return box;
    }

    function table(headers, rows, numericFrom) {
        const wrap = el('div', { class: 'rg-table-wrap rg-viz-table' });
        const t = el('table', { class: 'rg-table' });
        const head = el('tr');

        headers.forEach(function (h, i) {
            head.appendChild(el('th', i >= numericFrom ? { class: 'right' } : {}, h));
        });

        t.appendChild(el('thead')).appendChild(head);
        const tbody = t.appendChild(el('tbody'));

        rows.forEach(function (r) {
            const tr = el('tr');

            r.forEach(function (cell, i) {
                tr.appendChild(el('td', i >= numericFrom ? { class: 'right num' } : {}, cell));
            });

            tbody.appendChild(tr);
        });

        if (!rows.length) {
            tbody.appendChild(el('tr')).appendChild(el('td', { colspan: String(headers.length), class: 'rg-muted' }, 'Aucune donnée.'));
        }

        wrap.appendChild(t);

        return wrap;
    }

    function empty(body, text) {
        body.appendChild(el('p', { class: 'rg-viz-empty' }, text));
    }

    // -----------------------------------------------------------------------
    // Graphiques
    // -----------------------------------------------------------------------
    function renderSessions(card, body, agg, asTable) {
        const series = SERIES[state.metric];
        const keys = Object.keys(agg.sessions).sort(function (a, b) {
            return a.localeCompare(b, 'fr', { numeric: true });
        });
        const rows = keys.map(function (k) {
            const g = agg.sessions[k];

            return {
                label: sessionLabel(k),
                values: [g.paid, g.due],
                onSelect: state.session === '*' && keys.length > 1 ? function () { setSession(k); } : null
            };
        });

        setSubtitle(card, state.metric === 'amount'
            ? 'Encaissé et reste dû de chaque départ.' + (rows.length > 1 && state.session === '*' ? ' Cliquez sur un départ pour filtrer.' : '')
            : 'Engagements payés et à payer de chaque départ.' + (rows.length > 1 && state.session === '*' ? ' Cliquez sur un départ pour filtrer.' : ''));

        if (asTable) {
            body.appendChild(table(['Départ', series[0].name, series[1].name, 'Total'], rows.map(function (r) {
                return [r.label, value(r.values[0]), value(r.values[1]), value(r.values[0] + r.values[1])];
            }), 1));
            return;
        }

        if (!rows.length) {
            empty(body, 'Aucun engagement.');
            return;
        }

        body.appendChild(legend(series));
        renderBars(card, body, rows, series, { ariaLabel: 'Par départ : ' + rows.map(function (r) { return r.label + ' ' + value(r.values[0]) + ' / ' + value(r.values[1]); }).join(', ') });
    }

    function renderClubs(card, body, agg, asTable) {
        const series = SERIES[state.metric];
        const all = Object.keys(agg.clubs).map(function (k) {
            const g = agg.clubs[k];

            return { label: data.clubs[k], values: [g.paid, g.due], count: g.count };
        }).sort(function (a, b) {
            return (b.values[0] + b.values[1]) - (a.values[0] + a.values[1]) || a.label.localeCompare(b.label, 'fr');
        });

        setSubtitle(card, all.length > MAX_CLUBS && !asTable
            ? 'Les ' + (MAX_CLUBS - 1) + ' premiers clubs ; les ' + (all.length - MAX_CLUBS + 1) + ' autres sont regroupés (détail dans le tableau).'
            : 'Du plus important au plus petit.');

        if (asTable) {
            body.appendChild(table(['Club', 'Engagements', series[0].name, series[1].name], all.map(function (r) {
                return [r.label, nf0.format(r.count), value(r.values[0]), value(r.values[1])];
            }), 1));
            return;
        }

        if (!all.length) {
            empty(body, 'Aucun engagement.');
            return;
        }

        let rows = all;

        if (all.length > MAX_CLUBS) {
            const rest = all.slice(MAX_CLUBS - 1);
            rows = all.slice(0, MAX_CLUBS - 1).concat([{
                label: 'Autres clubs (' + rest.length + ')',
                title: rest.length + ' autres clubs',
                values: [0, 1].map(function (j) {
                    return rest.reduce(function (s, r) { return s + r.values[j]; }, 0);
                })
            }]);
        }

        body.appendChild(legend(series));
        renderBars(card, body, rows, series, { ariaLabel: 'Par club : ' + rows.map(function (r) { return r.label + ' ' + value(r.values[0]) + ' / ' + value(r.values[1]); }).join(', ') });
    }

    function renderModes(card, body, agg, asTable) {
        const rows = Object.keys(agg.modes).map(function (code) {
            return { label: modeLabel(code), values: [agg.modes[code]] };
        }).sort(function (a, b) {
            return b.values[0] - a.values[0] || a.label.localeCompare(b.label, 'fr');
        });
        const series = [{ name: state.metric === 'amount' ? 'Encaissé' : 'Payés', color: 'var(--viz-series-1)' }];

        setSubtitle(card, state.metric === 'amount' ? 'Montant encaissé par mode.' : 'Engagements payés par mode.');

        if (asTable) {
            body.appendChild(table(['Mode', series[0].name], rows.map(function (r) {
                return [r.label, value(r.values[0])];
            }), 1));
            return;
        }

        if (!rows.length) {
            empty(body, 'Aucun paiement.');
            return;
        }

        renderBars(card, body, rows, series, { ariaLabel: 'Par mode : ' + rows.map(function (r) { return r.label + ' ' + value(r.values[0]); }).join(', ') });
    }

    // Courbe en escalier de l'encaissé cumulé, avec la ligne du montant attendu
    function renderTimeline(card, body, agg, asTable) {
        const points = timelinePoints();
        const amount = state.metric === 'amount';
        const target = amount ? agg.kpi.collected + agg.kpi.remaining : agg.kpi.paid + agg.kpi.due;
        const current = amount ? agg.kpi.collected : agg.kpi.paid;
        const last = points.length ? points[points.length - 1].v : 0;
        const seriesName = amount ? 'Encaissé au total' : 'Engagements payés';

        setSubtitle(card, amount
            ? 'Montant encaissé cumulé, d\'après le journal de caisse ; la ligne grise est le montant attendu.'
            : 'Nombre d\'engagements payés, d\'après le journal de caisse ; la ligne grise est le nombre total à payer.');

        if (asTable) {
            body.appendChild(table(['Date', 'Opération', 'Mode', amount ? 'Montant' : 'Engagements', amount ? 'Cumul' : 'Payés'], points.slice().reverse().map(function (p) {
                return [dateFull.format(p.t), OPERATION_LABELS[p.type] || p.type, modeLabel(p.method), amount ? signedEuro(p.delta) : (p.delta > 0 ? '+1' : '−1'), value(p.v)];
            }), 3));
            return;
        }

        if (!points.length) {
            empty(body, 'Aucune opération de caisse pour l\'instant.');
            return;
        }

        const tip = tooltip(card);
        const width = Math.max(280, body.clientWidth);
        const height = 260;
        const yScale = niceTicks(Math.max(last, target, Math.max.apply(null, points.map(function (p) { return p.v; }))), 4);
        const margin = {
            top: 18,
            right: 18,
            bottom: 28,
            left: Math.ceil(Math.max.apply(null, yScale.ticks.map(function (t) { return textWidth(tick(t), 11); }))) + 12
        };
        const plotW = width - margin.left - margin.right;
        const plotH = height - margin.top - margin.bottom;

        let t0 = points[0].t;
        let t1 = points[points.length - 1].t;

        if (t1 - t0 < 30 * 60000) {
            t0 -= 15 * 60000;
            t1 += 15 * 60000;
        }

        const x = function (t) { return margin.left + (t - t0) / (t1 - t0) * plotW; };
        const xTicks = timeTicks(t0, t1, Math.max(2, Math.floor(plotW / 95)));
        const y = function (v) { return margin.top + plotH - Math.max(0, v) / yScale.max * plotH; };

        const chart = svg('svg', {
            width: width,
            height: height,
            viewBox: '0 0 ' + width + ' ' + height,
            role: 'img',
            'aria-label': seriesName + ' : ' + value(last) + ' sur ' + value(target) + ' attendus.'
        });

        yScale.ticks.forEach(function (t) {
            chart.appendChild(svg('line', { x1: margin.left, x2: width - margin.right, y1: y(t), y2: y(t), class: t === 0 ? 'rg-viz-axis' : 'rg-viz-grid' }));
            chart.appendChild(svg('text', { x: margin.left - 8, y: y(t) + 4, class: 'rg-viz-tick', 'text-anchor': 'end' }, tick(t)));
        });

        xTicks.forEach(function (tk) {
            // Aux extrémités, le libellé reste dans le graphique
            const anchor = x(tk.t) - textWidth(tk.label, 11) / 2 < margin.left ? 'start'
                : x(tk.t) + textWidth(tk.label, 11) / 2 > width ? 'end' : 'middle';
            chart.appendChild(svg('text', { x: x(tk.t), y: height - 8, class: 'rg-viz-tick', 'text-anchor': anchor }, tk.label));
        });

        // Montant attendu : trait fin plein, libellé discret
        if (target > 0) {
            chart.appendChild(svg('line', { x1: margin.left, x2: width - margin.right, y1: y(target), y2: y(target), class: 'rg-viz-ref' }));
            chart.appendChild(svg('text', { x: margin.left + 6, y: y(target) - 6, class: 'rg-viz-ref-label' }, (amount ? 'Attendu : ' : 'À payer au total : ') + value(target)));
        }

        // Escalier : la valeur ne change qu'à chaque opération
        let d = 'M' + x(points[0].t) + ',' + y(0);

        points.forEach(function (p, i) {
            if (i > 0) {
                d += 'H' + x(p.t);
            }

            d += 'V' + y(p.v);
        });

        const endX = x(points[points.length - 1].t);
        chart.appendChild(svg('path', { d: d + 'V' + y(0) + 'H' + x(points[0].t) + 'Z', class: 'rg-viz-area' }));
        chart.appendChild(svg('path', { d: d, class: 'rg-viz-line' }));
        chart.appendChild(svg('circle', { cx: endX, cy: y(last), r: 4, class: 'rg-viz-dot' }));

        const endLabel = value(last);
        const endLabelX = endX + 8 + textWidth(endLabel, 12) > width ? endX - 8 : endX + 8;
        chart.appendChild(svg('text', {
            x: endLabelX,
            y: y(last) + (y(last) - margin.top < 14 ? 16 : -8),
            class: 'rg-viz-value',
            'text-anchor': endLabelX < endX ? 'end' : 'start'
        }, endLabel));

        // Réticule : suit le pointeur et se cale sur l'opération la plus proche
        const cross = svg('g', { class: 'rg-viz-cross' });
        const crossLine = svg('line', { y1: margin.top, y2: margin.top + plotH });
        const crossDot = svg('circle', { r: 4, class: 'rg-viz-dot' });
        cross.appendChild(crossLine);
        cross.appendChild(crossDot);
        cross.style.display = 'none';
        chart.appendChild(cross);

        const overlay = svg('rect', {
            x: margin.left,
            y: margin.top,
            width: plotW,
            height: plotH,
            class: 'rg-viz-overlay',
            tabindex: '0',
            'aria-label': 'Parcourir les opérations avec les flèches gauche et droite'
        });
        chart.appendChild(overlay);

        let current_i = points.length - 1;

        function nearest(px) {
            let best = 0;

            points.forEach(function (p, i) {
                if (Math.abs(x(p.t) - px) < Math.abs(x(points[best].t) - px)) {
                    best = i;
                }
            });

            return best;
        }

        function focusPoint(i) {
            current_i = i;
            const p = points[i];
            const px = x(p.t);

            crossLine.setAttribute('x1', px);
            crossLine.setAttribute('x2', px);
            crossDot.setAttribute('cx', px);
            crossDot.setAttribute('cy', y(p.v));
            cross.style.display = '';

            const box = chart.getBoundingClientRect();
            const pos = pointIn(card, box.left + px, box.top + y(p.v));
            const detail = (amount ? signedEuro(p.delta) : (p.delta > 0 ? '+1 engagement' : '−1 engagement'))
                + ' · ' + (OPERATION_LABELS[p.type] || p.type) + ' · ' + modeLabel(p.method);

            tip.show(dateFull.format(p.t), [{ value: value(p.v), label: seriesName, color: 'var(--viz-series-1)' }], detail, pos.x, pos.y);
        }

        function leave() {
            cross.style.display = 'none';
            tip.hide();
        }

        overlay.addEventListener('pointermove', function (ev) {
            const box = chart.getBoundingClientRect();
            focusPoint(nearest(ev.clientX - box.left));
        });
        overlay.addEventListener('pointerleave', leave);
        overlay.addEventListener('focus', function () { focusPoint(current_i); });
        overlay.addEventListener('blur', leave);
        overlay.addEventListener('keydown', function (ev) {
            if (ev.key === 'ArrowLeft' || ev.key === 'ArrowRight') {
                ev.preventDefault();
                focusPoint(Math.min(points.length - 1, Math.max(0, current_i + (ev.key === 'ArrowLeft' ? -1 : 1))));
            }
        });

        body.appendChild(chart);

        // Le journal ne couvre pas tout (paiements antérieurs, tarif changé après coup)
        const gap = Math.round((current - last) * 100) / 100;

        if (Math.abs(gap) >= 0.01) {
            body.appendChild(el('p', { class: 'rg-help rg-viz-note' },
                'Écart de ' + value(Math.abs(gap)) + ' entre la courbe et ' + (amount ? 'l\'encaissé actuel' : 'le nombre de payés actuel')
                + ' : paiements enregistrés avant la mise en place du journal de caisse, ou tarifs modifiés après encaissement.'));
        }
    }

    // Graduations de temps : heures sur une journée, jours au-delà
    function timeTicks(t0, t1, count) {
        const MIN = 60000;
        const HOUR = 60 * MIN;
        const DAY = 24 * HOUR;
        const steps = [15 * MIN, 30 * MIN, HOUR, 2 * HOUR, 3 * HOUR, 6 * HOUR, 12 * HOUR, DAY, 2 * DAY, 7 * DAY, 14 * DAY, 30 * DAY];
        const step = steps.find(function (s) { return (t1 - t0) / s <= count; }) || 30 * DAY;
        const start = new Date(t0);

        if (step < DAY) {
            start.setMinutes(0, 0, 0);
        } else {
            start.setHours(0, 0, 0, 0);
        }

        const ticks = [];
        let previousDay = '';

        for (let t = start.getTime(); t <= t1; t += step) {
            if (t < t0) {
                continue;
            }

            const day = dateDay.format(t);
            const label = step >= DAY ? day : (day !== previousDay ? day + ' ' + dateTime.format(t) : dateTime.format(t));
            previousDay = day;
            ticks.push({ t: t, label: label });
        }

        // Écran étroit : au moins le début et la fin
        if (ticks.length < 2) {
            return [t0, t1].map(function (t) {
                return { t: t, label: dateDay.format(t) + (t1 - t0 < 2 * DAY ? ' ' + dateTime.format(t) : '') };
            });
        }

        return ticks;
    }

    // -----------------------------------------------------------------------
    // Cartes : graphique ou tableau, sous-titre
    // -----------------------------------------------------------------------
    const RENDERERS = { timeline: renderTimeline, sessions: renderSessions, modes: renderModes, clubs: renderClubs };
    const cards = Array.prototype.slice.call(root.querySelectorAll('.rg-viz-card'));

    function setSubtitle(card, text) {
        const sub = card.querySelector('[data-role="subtitle"]');

        if (sub) {
            sub.textContent = text;
        }
    }

    cards.forEach(function (card) {
        const name = card.getAttribute('data-chart');
        const seg = el('div', { class: 'rg-seg rg-seg-small rg-noprint', role: 'group', 'aria-label': 'Affichage' });

        [['chart', 'Graphique'], ['table', 'Tableau']].forEach(function (v) {
            const b = el('button', { type: 'button', 'data-view': v[0], 'aria-pressed': v[0] === 'chart' ? 'true' : 'false' }, v[1]);

            b.addEventListener('click', function () {
                state.views[name] = v[0];
                seg.querySelectorAll('button').forEach(function (other) {
                    other.setAttribute('aria-pressed', other === b ? 'true' : 'false');
                });
                renderCard(card, aggregate());
            });

            seg.appendChild(b);
        });

        card.querySelector('.rg-viz-head').appendChild(seg);
    });

    function renderCard(card, agg) {
        const body = card.querySelector('.rg-viz-body');
        const name = card.getAttribute('data-chart');
        const tip = card.querySelector('.rg-viz-tip');

        if (tip) {
            tip.hidden = true;
        }

        body.textContent = '';
        RENDERERS[name](card, body, agg, state.views[name] === 'table');
    }

    function renderAll() {
        const agg = aggregate();

        renderKpi(agg);
        cards.forEach(function (card) {
            renderCard(card, agg);
        });
    }

    // -----------------------------------------------------------------------
    // Filtres
    // -----------------------------------------------------------------------
    const sessionSelect = document.getElementById('rgVizSession');

    function setSession(s) {
        state.session = s;

        if (sessionSelect) {
            sessionSelect.value = s;
        }

        renderAll();
    }

    if (sessionSelect) {
        sessionSelect.addEventListener('change', function () {
            setSession(sessionSelect.value);
        });
    }

    root.querySelectorAll('[data-metric]').forEach(function (button) {
        button.addEventListener('click', function () {
            state.metric = button.getAttribute('data-metric');
            root.querySelectorAll('[data-metric]').forEach(function (b) {
                b.setAttribute('aria-pressed', b === button ? 'true' : 'false');
            });
            renderAll();
        });
    });

    // Redessine à la bonne largeur quand la fenêtre change
    let lastWidth = root.clientWidth;
    let resizeTimer = null;

    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
            if (root.clientWidth !== lastWidth) {
                lastWidth = root.clientWidth;
                renderAll();
            }
        }, 150);
    });

    renderAll();
})();
