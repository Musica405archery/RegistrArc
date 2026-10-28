// RegistrArc - assets/js/dashboard.js (scripts de dashboard.php)
//
// Tableau de bord en trois pages, dessiné en SVG sans bibliothèque externe :
// - Greffe : inscrits, départs, catégories, clubs, points à vérifier
// - Trésorier : attendu, encaissé, reste dû, modes, tarifs, chèques, caisse
// - Terrain : à encaisser, activité du jour, opérateurs, postes, origines
// Le filtre Départ s'applique aux trois pages ; chaque graphique a sa vue
// tableau (mêmes données, sans survol). Données : lib/registrarc_dashboard.inc.php
//
// Les noms (clubs, catégories, opérateurs) sont insérés avec textContent, jamais en HTML.

(function () {
    const dataEl = document.getElementById('rgVizData');
    const root = document.getElementById('rgViz');
    const pageEl = document.getElementById('rgVizPage');

    if (!dataEl || !root || !pageEl) {
        return;
    }

    const data = JSON.parse(dataEl.textContent);
    const SVG_NS = 'http://www.w3.org/2000/svg';
    const PAGE_KEY = 'RegistrArcDashboardPage';
    const MAX_ROWS = 12;
    const state = { page: 'greffe', session: '*', views: {} };

    try {
        const saved = localStorage.getItem(PAGE_KEY);

        if (saved === 'greffe' || saved === 'tresorier' || saved === 'terrain') {
            state.page = saved;
        }
    } catch (e) {
        // stockage indisponible : page Greffe par défaut
    }

    // -----------------------------------------------------------------------
    // Formats
    // -----------------------------------------------------------------------
    // Séparateur de milliers : espace insécable classique (l'espace fine du format
    // français ne s'affiche pas avec toutes les polices des graphiques)
    function numberFormat(options) {
        const f = new Intl.NumberFormat('fr-FR', options);

        return { format: function (v) { return f.format(v).replace(/\u202f/g, '\u00a0'); } };
    }

    const nf0 = numberFormat({ maximumFractionDigits: 0 });
    const nf1 = numberFormat({ maximumFractionDigits: 1 });
    const nf2 = numberFormat({ minimumFractionDigits: 2, maximumFractionDigits: 2 });

    function euro(v) {
        return (Math.abs(v - Math.round(v)) < 0.005 ? nf0.format(Math.round(v)) : nf2.format(v)) + ' €';
    }

    function signedEuro(v) {
        return (v > 0 ? '+' : v < 0 ? '−' : '') + euro(Math.abs(v));
    }

    function compact(v) {
        return Math.abs(v) >= 1000 ? nf1.format(v / 1000) + ' k' : nf0.format(v);
    }

    // Une mesure : comment écrire une valeur, une graduation, et si elle est entière
    const FMT = {
        euro: { v: euro, tick: function (t) { return compact(t) + (Math.abs(t) >= 1000 ? '€' : ' €'); }, integer: false },
        count: { v: function (n) { return nf0.format(n); }, tick: compact, integer: true }
    };

    function plural(n, one, many) {
        return nf0.format(n) + ' ' + (n > 1 ? many : one);
    }

    function percent(part, total) {
        return total > 0 ? Math.round(part * 100 / total) : 0;
    }

    function sessionLabel(s) {
        return s === '' ? 'Sans départ' : 'Départ ' + s;
    }

    function modeLabel(code) {
        return (data.modes && data.modes[code]) || code || 'Non précisé';
    }

    const OPERATION_LABELS = { payment: 'Encaissement', cancel: 'Annulation', refund: 'Remboursement' };
    const SOURCE_LABELS = { liste: 'Liste des engagements', kiosque: 'Mode Kiosk', import: 'Import de paiements', helloasso: 'HelloAsso' };

    function parseDate(s) {
        const m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/.exec(s || '');

        return m ? new Date(+m[1], m[2] - 1, +m[3], +m[4], +m[5], +(m[6] || 0)).getTime() : NaN;
    }

    const dateFull = new Intl.DateTimeFormat('fr-FR', { weekday: 'short', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
    const dateLong = new Intl.DateTimeFormat('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' });
    const dateDay = new Intl.DateTimeFormat('fr-FR', { day: '2-digit', month: '2-digit' });
    const dateTime = new Intl.DateTimeFormat('fr-FR', { hour: '2-digit', minute: '2-digit' });

    function dayKey(t) {
        const d = new Date(t);

        return d.getFullYear() + '-' + d.getMonth() + '-' + d.getDate();
    }

    function since(t, now) {
        const minutes = Math.round((now - t) / 60000);

        if (minutes < 1) {
            return 'à l\'instant';
        }

        if (minutes < 60) {
            return 'il y a ' + minutes + ' min';
        }

        if (minutes < 24 * 60) {
            return 'il y a ' + Math.floor(minutes / 60) + ' h ' + String(minutes % 60).padStart(2, '0');
        }

        return dateFull.format(t);
    }

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

    function niceTicks(max, count, integer) {
        if (!(max > 0)) {
            return { max: 1, ticks: [0, 1] };
        }

        let step = niceStep(max, count);

        // Des engagements ou des opérations se comptent en entiers
        if (integer) {
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

    // Colonne : bout arrondi de 4px en haut, carrée sur l'axe
    function columnPath(x, y, w, h) {
        const r = Math.min(4, w / 2, h);

        return 'M' + x + ',' + (y + h)
            + 'V' + (y + r)
            + 'Q' + x + ',' + y + ' ' + (x + r) + ',' + y
            + 'H' + (x + w - r)
            + 'Q' + (x + w) + ',' + y + ' ' + (x + w) + ',' + (y + r)
            + 'V' + (y + h) + 'Z';
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
    // Données : opérations de caisse dans l'ordre du temps, puis calculs
    // pour le départ choisi
    // -----------------------------------------------------------------------
    const NOW = parseDate(data.now) || Date.now();

    const OPS = data.journal
        .map(function (op, i) {
            return {
                t: parseDate(op[0]), i: i, type: op[1], amount: op[2], method: op[3],
                session: op[4], operator: op[5], device: op[6], source: op[7]
            };
        })
        .filter(function (op) {
            return !isNaN(op.t);
        })
        .sort(function (a, b) {
            return a.t - b.t || a.i - b.i;
        });

    // Nombre d'engagements de chaque archer, tous départs confondus
    const ENGAGEMENTS_PER_ARCHER = {};

    data.records.forEach(function (r) {
        ENGAGEMENTS_PER_ARCHER[r.a] = (ENGAGEMENTS_PER_ARCHER[r.a] || 0) + 1;
    });

    function add(map, key, fields) {
        const g = map[key] || (map[key] = {});

        Object.keys(fields).forEach(function (k) {
            g[k] = (g[k] || 0) + fields[k];
        });

        return g;
    }

    function compute() {
        const inSession = function (s) { return state.session === '*' || s === state.session; };
        const R = data.records.filter(function (r) { return inSession(r.s); });
        const J = OPS.filter(function (op) { return inSession(op.session); });
        const A = {
            R: R,
            J: J,
            counts: { paid: 0, due: 0, free: 0, refunded: 0 },
            collected: 0,
            remaining: 0,
            archers: {},          // archer => tranche d'âge
            payingArchers: {},
            clubArchers: {},      // club => { archer: true }
            sessions: {},
            categories: {},
            clubs: {},
            modes: {},
            tarifs: {},
            unassigned: 0,
            upgraded: 0,
            late: 0,
            special: 0,
            receipts: 0,
            notes: 0,
            chequesPending: { count: 0, amount: 0 }
        };

        R.forEach(function (r) {
            const paid = r.st === 'paid' ? 1 : 0;
            const due = r.st === 'due' ? 1 : 0;
            const other = paid || due ? 0 : 1;

            A.counts[r.st]++;
            A.collected += r.p;
            A.remaining += r.d;
            A.archers[r.a] = r.age;
            (A.clubArchers[r.c] || (A.clubArchers[r.c] = {}))[r.a] = true;

            if (paid) {
                A.payingArchers[r.a] = true;
                add(A.modes, r.m, { amount: r.p, count: 1 });

                if (r.m === 'CHQ' && !r.dep) {
                    A.chequesPending.count++;
                    A.chequesPending.amount += r.p;
                }
            }

            add(A.sessions, r.s, { paid: paid, due: due, other: other, collected: r.p, remaining: r.d });
            add(A.categories, r.cat, { count: 1 });
            add(A.clubs, r.c, { count: 1, due: due, remaining: r.d });

            if (paid || due) {
                add(A.tarifs, r.t, { collected: r.p, remaining: r.d, count: 1 });
            }

            A.unassigned += r.u;
            A.upgraded += r.up;
            A.late += r.l;
            A.special += r.t !== 'Standard' ? 1 : 0;
            A.receipts += r.r;
            A.notes += r.n;
        });

        A.expected = A.collected + A.remaining;
        A.refunded = J.reduce(function (s, op) { return s + (op.type === 'refund' ? -op.amount : 0); }, 0);

        return A;
    }

    // Classement décroissant, avec regroupement de la fin en « Autres »
    function topRows(rows, label) {
        rows.sort(function (a, b) {
            return b.values.reduce(sum, 0) - a.values.reduce(sum, 0) || a.label.localeCompare(b.label, 'fr');
        });

        if (rows.length <= MAX_ROWS) {
            return rows;
        }

        const rest = rows.slice(MAX_ROWS - 1);

        return rows.slice(0, MAX_ROWS - 1).concat([{
            label: label + ' (' + rest.length + ')',
            title: rest.length + ' ' + label.toLowerCase(),
            values: rows[0].values.map(function (v, j) {
                return rest.reduce(function (s, r) { return s + r.values[j]; }, 0);
            })
        }]);
    }

    function sum(a, b) {
        return a + b;
    }

    // -----------------------------------------------------------------------
    // Tuiles de chiffres clés
    // tiles : [{ label, value, sub, warn, meter }]
    // -----------------------------------------------------------------------
    function renderTiles(container, tiles) {
        const grid = el('div', { class: 'rg-viz-tiles' });

        tiles.forEach(function (t) {
            const tile = el('div', { class: 'rg-viz-tile' + (t.warn ? ' is-warn' : '') });
            tile.appendChild(el('div', { class: 'rg-viz-tile-label' }, t.label));

            const value = el('div', { class: 'rg-viz-tile-value' });

            if (t.warn) {
                value.appendChild(el('span', { class: 'rg-viz-warn-icon', 'aria-label': 'À vérifier', title: 'À vérifier' }, '⚠'));
            }

            value.appendChild(document.createTextNode(t.value));
            tile.appendChild(value);

            if (t.meter !== undefined) {
                const meter = el('div', { class: 'rg-viz-meter', role: 'meter', 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-valuenow': String(t.meter), 'aria-label': t.label });
                const fill = el('span', {});
                fill.style.width = Math.min(100, t.meter) + '%';
                meter.appendChild(fill);
                tile.appendChild(meter);
            }

            if (t.sub) {
                tile.appendChild(el('div', { class: 'rg-viz-tile-sub' }, t.sub));
            }

            grid.appendChild(tile);
        });

        container.appendChild(grid);
    }

    // -----------------------------------------------------------------------
    // Barres horizontales (empilées si plusieurs séries)
    // rows : [{ label, title, values: [..], extra: [{ value, label }], onSelect }]
    // -----------------------------------------------------------------------
    function renderBars(card, body, rows, series, opts) {
        const fmt = opts.format;
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
            return r.values.reduce(sum, 0);
        });
        const scale = niceTicks(Math.max.apply(null, totals.concat([0])), Math.max(2, Math.floor(plotW / 90)), fmt.integer);
        const x = function (v) { return plotX + v / scale.max * plotW; };
        const height = rows.length * band + axisBand;

        if (series.length > 1) {
            body.appendChild(legend(series));
        }

        const chart = svg('svg', {
            width: width,
            height: height,
            viewBox: '0 0 ' + width + ' ' + height,
            role: 'img',
            'aria-label': card.querySelector('h2').textContent + ' : ' + rows.map(function (r, i) { return r.label + ' ' + fmt.v(totals[i]); }).join(', ')
        });

        // Grille et axe : traits fins, en retrait
        scale.ticks.forEach(function (t) {
            chart.appendChild(svg('line', { x1: x(t), x2: x(t), y1: 0, y2: rows.length * band, class: t === 0 ? 'rg-viz-axis' : 'rg-viz-grid' }));
            chart.appendChild(svg('text', { x: x(t), y: rows.length * band + 17, class: 'rg-viz-tick', 'text-anchor': 'middle' }, fmt.tick(t)));
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

            group.appendChild(svg('text', { x: x(totals[i]) + 6, y: i * band + band / 2 + 4, class: 'rg-viz-value' }, fmt.v(totals[i])));

            const show = function (px, py) {
                const lines = series.map(function (s, j) {
                    return { value: fmt.v(row.values[j]), label: s.name, color: series.length > 1 ? s.color : null };
                }).concat(row.extra || []);

                tip.show(
                    row.title || row.label,
                    lines,
                    (series.length > 1 ? 'Total : ' + fmt.v(totals[i]) : '') + (row.onSelect ? (series.length > 1 ? ' · ' : '') + 'cliquer pour filtrer' : ''),
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

    // -----------------------------------------------------------------------
    // Colonnes (une série) : cols : [{ label, title, value, extra }]
    // -----------------------------------------------------------------------
    function renderColumns(card, body, cols, opts) {
        const fmt = opts.format;
        const tip = tooltip(card);
        const width = Math.max(260, body.clientWidth);
        const height = 230;
        const scale = niceTicks(Math.max.apply(null, cols.map(function (c) { return c.value; }).concat([0])), 4, fmt.integer);
        const margin = {
            top: 20,
            right: 8,
            bottom: 26,
            left: Math.ceil(Math.max.apply(null, scale.ticks.map(function (t) { return textWidth(fmt.tick(t), 11); }))) + 12
        };
        const plotW = width - margin.left - margin.right;
        const plotH = height - margin.top - margin.bottom;
        const band = plotW / Math.max(1, cols.length);
        const thick = Math.max(4, Math.min(24, band - 6));
        const y = function (v) { return margin.top + plotH - v / scale.max * plotH; };
        const labelEvery = Math.max(1, Math.ceil((Math.max.apply(null, cols.map(function (c) { return textWidth(c.label, 11); })) + 8) / band));
        const maxIndex = cols.reduce(function (best, c, i) { return c.value > cols[best].value ? i : best; }, 0);

        const chart = svg('svg', {
            width: width,
            height: height,
            viewBox: '0 0 ' + width + ' ' + height,
            role: 'img',
            'aria-label': card.querySelector('h2').textContent + ' : ' + cols.map(function (c) { return c.label + ' ' + fmt.v(c.value); }).join(', ')
        });

        scale.ticks.forEach(function (t) {
            chart.appendChild(svg('line', { x1: margin.left, x2: width - margin.right, y1: y(t), y2: y(t), class: t === 0 ? 'rg-viz-axis' : 'rg-viz-grid' }));
            chart.appendChild(svg('text', { x: margin.left - 8, y: y(t) + 4, class: 'rg-viz-tick', 'text-anchor': 'end' }, fmt.tick(t)));
        });

        cols.forEach(function (c, i) {
            const cx = margin.left + band * i + band / 2;
            const group = svg('g', { class: 'rg-viz-row', tabindex: '0' });
            group.appendChild(svg('rect', { x: margin.left + band * i, y: margin.top, width: band, height: plotH, class: 'rg-viz-hit' }));

            if (c.value > 0) {
                const path = svg('path', { d: columnPath(cx - thick / 2, y(c.value), thick, y(0) - y(c.value)), class: 'rg-viz-bar' });
                path.style.fill = 'var(--viz-series-1)';
                group.appendChild(path);
            }

            // Seule la plus haute colonne porte sa valeur ; les autres sont dans l'infobulle
            if (i === maxIndex && c.value > 0) {
                group.appendChild(svg('text', { x: cx, y: y(c.value) - 6, class: 'rg-viz-value', 'text-anchor': 'middle' }, fmt.v(c.value)));
            }

            if (i % labelEvery === 0) {
                group.appendChild(svg('text', { x: cx, y: height - 8, class: 'rg-viz-tick', 'text-anchor': 'middle' }, c.label));
            }

            const show = function (px, py) {
                tip.show(c.title || c.label, [{ value: fmt.v(c.value), label: opts.name }].concat(c.extra || []), '', px, py);
            };

            group.addEventListener('pointermove', function (ev) {
                const p = pointIn(card, ev.clientX, ev.clientY);
                show(p.x, p.y);
            });
            group.addEventListener('pointerleave', tip.hide);
            group.addEventListener('focus', function () {
                const box = chart.getBoundingClientRect();
                const p = pointIn(card, box.left + cx, box.top + y(c.value));
                show(p.x, p.y);
            });
            group.addEventListener('blur', tip.hide);

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

    // Graphique en barres ou son tableau, selon le bouton de la carte
    function barsOrTable(card, body, asTable, rows, series, opts) {
        if (asTable) {
            const extraHeaders = rows.length && rows[0].extra ? rows[0].extra.map(function (e) { return e.label; }) : [];

            body.appendChild(table([opts.header].concat(series.map(function (s) { return s.name; }), series.length > 1 ? ['Total'] : [], extraHeaders), rows.map(function (r) {
                return [r.title || r.label]
                    .concat(r.values.map(opts.format.v))
                    .concat(series.length > 1 ? [opts.format.v(r.values.reduce(sum, 0))] : [])
                    .concat((r.extra || []).map(function (e) { return e.value; }));
            }), 1));
            return;
        }

        if (!rows.length) {
            empty(body, opts.empty || 'Aucune donnée.');
            return;
        }

        renderBars(card, body, rows, series, opts);
    }

    const COLOR = { paid: 'var(--viz-series-1)', due: 'var(--viz-series-2)', other: 'var(--viz-other)' };

    // -----------------------------------------------------------------------
    // Page Greffe : qui est inscrit, où, et ce qu'il faut vérifier
    // -----------------------------------------------------------------------
    function greffeTiles(A) {
        const archers = Object.keys(A.archers);
        const jeunes = archers.filter(function (a) { return A.archers[a] === 'jeunes'; }).length;
        const multi = archers.filter(function (a) { return ENGAGEMENTS_PER_ARCHER[a] > 1; }).length;
        const clubs = Object.keys(A.clubArchers).length;
        const tarifs = Object.keys(A.tarifs).filter(function (t) { return t !== 'Standard'; }).length;

        return [
            { label: 'Archers', value: nf0.format(archers.length), sub: plural(jeunes, 'jeune', 'jeunes') + ' · ' + plural(archers.length - jeunes, 'adulte', 'adultes') },
            { label: 'Engagements', value: nf0.format(A.R.length), sub: plural(A.counts.free, 'gratuit', 'gratuits') + ' · ' + plural(A.counts.refunded, 'remboursé', 'remboursés') },
            { label: 'Clubs', value: nf0.format(clubs), sub: clubs ? nf1.format(archers.length / clubs) + ' archers par club en moyenne' : '' },
            { label: 'Archers sur plusieurs départs', value: nf0.format(multi), sub: percent(multi, archers.length) + ' % des archers' },
            { label: 'Sans cible', value: nf0.format(A.unassigned), warn: A.unassigned > 0, sub: A.unassigned ? 'à placer dans I@nseo' : 'tous les archers sont placés' },
            { label: 'Surclassés', value: nf0.format(A.upgraded), warn: A.upgraded > 0, sub: A.upgraded ? 'à vérifier (catégorie d\'âge différente)' : 'aucun' },
            { label: 'Inscriptions tardives', value: nf0.format(A.late), sub: A.late ? 'majorées si un supplément est réglé' : 'aucune' },
            { label: 'Tarifs spéciaux', value: nf0.format(A.special), sub: plural(tarifs, 'règle utilisée', 'règles utilisées') }
        ];
    }

    const GREFFE_CARDS = [
        {
            id: 'g-sessions',
            title: 'Engagements par départ',
            subtitle: function () { return 'Payés, à payer, et gratuits ou remboursés.' + (state.session === '*' && data.sessions.length > 1 ? ' Cliquez sur un départ pour filtrer.' : ''); },
            render: function (card, body, A, asTable) {
                const keys = Object.keys(A.sessions).sort(function (a, b) { return a.localeCompare(b, 'fr', { numeric: true }); });
                const rows = keys.map(function (k) {
                    const g = A.sessions[k];

                    return { label: sessionLabel(k), values: [g.paid || 0, g.due || 0, g.other || 0], onSelect: state.session === '*' && keys.length > 1 ? function () { setSession(k); } : null };
                });

                barsOrTable(card, body, asTable, rows, [
                    { name: 'Payés', color: COLOR.paid },
                    { name: 'À payer', color: COLOR.due },
                    { name: 'Gratuits ou remboursés', color: COLOR.other }
                ], { format: FMT.count, header: 'Départ', empty: 'Aucun engagement.' });
            }
        },
        {
            id: 'g-categories',
            title: 'Engagements par catégorie',
            subtitle: function () { return 'Les catégories les plus nombreuses en premier.'; },
            render: function (card, body, A, asTable) {
                const rows = Object.keys(A.categories).map(function (c) {
                    return { label: c || 'Sans catégorie', values: [A.categories[c].count] };
                });

                barsOrTable(card, body, asTable, asTable ? rows.sort(function (a, b) { return b.values[0] - a.values[0]; }) : topRows(rows, 'Autres catégories'),
                    [{ name: 'Engagements', color: COLOR.paid }], { format: FMT.count, header: 'Catégorie', empty: 'Aucun engagement.' });
            }
        },
        {
            id: 'g-clubs',
            title: 'Clubs les plus représentés',
            subtitle: function () { return 'Nombre d\'archers par club.'; },
            render: function (card, body, A, asTable) {
                const rows = Object.keys(A.clubArchers).map(function (c) {
                    const archers = Object.keys(A.clubArchers[c]).length;

                    return { label: data.clubs[c], values: [archers], extra: [{ value: nf0.format(A.clubs[c].count), label: 'engagements' }] };
                });

                barsOrTable(card, body, asTable, asTable ? rows.sort(function (a, b) { return b.values[0] - a.values[0]; }) : topRows(rows, 'Autres clubs'),
                    [{ name: 'Archers', color: COLOR.paid }], { format: FMT.count, header: 'Club', empty: 'Aucun engagement.' });
            }
        },
        {
            id: 'g-multi',
            title: 'Départs par archer',
            subtitle: function () { return 'Combien d\'archers tirent un, deux, trois départs ou plus.'; },
            render: function (card, body, A, asTable) {
                const buckets = [0, 0, 0];
                const total = Object.keys(A.archers).length;

                Object.keys(A.archers).forEach(function (a) {
                    buckets[Math.min(3, ENGAGEMENTS_PER_ARCHER[a]) - 1]++;
                });

                const rows = ['1 départ', '2 départs', '3 départs ou plus'].map(function (label, i) {
                    return { label: label, values: [buckets[i]], extra: [{ value: percent(buckets[i], total) + ' %', label: 'des archers' }] };
                });

                barsOrTable(card, body, asTable, total ? rows : [], [{ name: 'Archers', color: COLOR.paid }], { format: FMT.count, header: 'Départs', empty: 'Aucun archer.' });
            }
        }
    ];

    // -----------------------------------------------------------------------
    // Page Trésorier : l'argent du concours
    // -----------------------------------------------------------------------
    function tresorierTiles(A) {
        const paying = Object.keys(A.payingArchers).length;
        const deposited = data.deposits.reduce(function (s, d) { return s + d.total; }, 0);
        const gaps = data.closures.filter(function (c) { return c.diff !== null; });
        const gap = Math.round(gaps.reduce(function (s, c) { return s + c.diff; }, 0) * 100) / 100;
        const pct = percent(A.collected, A.expected);

        return [
            { label: 'Attendu', value: euro(A.expected), sub: 'encaissé + reste dû' },
            { label: 'Encaissé', value: euro(A.collected), meter: pct, sub: pct + ' % de l\'attendu' },
            { label: 'Reste dû', value: euro(A.remaining), sub: plural(A.counts.due, 'engagement à payer', 'engagements à payer') },
            { label: 'Remboursé', value: euro(A.refunded), sub: plural(A.counts.refunded, 'engagement', 'engagements') },
            { label: 'Montant moyen par archer', value: paying ? euro(A.collected / paying) : '—', sub: plural(paying, 'archer a payé', 'archers ont payé') },
            { label: 'Chèques à déposer', value: nf0.format(A.chequesPending.count), warn: A.chequesPending.count > 0, sub: A.chequesPending.count ? euro(A.chequesPending.amount) + ' à porter à la banque' : 'tout est déposé' },
            { label: 'Déposé en banque', value: euro(deposited), sub: plural(data.deposits.length, 'remise', 'remises') + ' · tous départs' },
            { label: 'Écart de caisse', value: gaps.length ? signedEuro(gap) : '—', warn: Math.abs(gap) >= 0.01, sub: gaps.length ? plural(gaps.length, 'clôture comptée', 'clôtures comptées') : 'aucune clôture comptée' }
        ];
    }

    const TRESORIER_CARDS = [
        {
            id: 't-timeline',
            wide: true,
            title: 'Encaissé au fil du temps',
            subtitle: function () { return 'Montant encaissé cumulé, d\'après le journal de caisse ; la ligne grise est le montant attendu.'; },
            render: renderTimeline
        },
        {
            id: 't-modes',
            title: 'Encaissé par mode de paiement',
            subtitle: function () { return 'Montant et nombre d\'engagements payés.'; },
            render: function (card, body, A, asTable) {
                const rows = Object.keys(A.modes).map(function (m) {
                    return { label: modeLabel(m), values: [A.modes[m].amount], extra: [{ value: nf0.format(A.modes[m].count), label: 'engagements' }] };
                }).sort(function (a, b) { return b.values[0] - a.values[0]; });

                barsOrTable(card, body, asTable, rows, [{ name: 'Encaissé', color: COLOR.paid }], { format: FMT.euro, header: 'Mode', empty: 'Aucun paiement.' });
            }
        },
        {
            id: 't-sessions',
            title: 'Encaissé et reste dû par départ',
            subtitle: function () { return state.session === '*' && data.sessions.length > 1 ? 'Cliquez sur un départ pour filtrer.' : 'Montants du départ.'; },
            render: function (card, body, A, asTable) {
                const keys = Object.keys(A.sessions).sort(function (a, b) { return a.localeCompare(b, 'fr', { numeric: true }); });
                const rows = keys.map(function (k) {
                    return { label: sessionLabel(k), values: [A.sessions[k].collected || 0, A.sessions[k].remaining || 0], onSelect: state.session === '*' && keys.length > 1 ? function () { setSession(k); } : null };
                });

                barsOrTable(card, body, asTable, rows, [{ name: 'Encaissé', color: COLOR.paid }, { name: 'Reste dû', color: COLOR.due }], { format: FMT.euro, header: 'Départ', empty: 'Aucun engagement.' });
            }
        },
        {
            id: 't-due-clubs',
            title: 'Reste dû par club',
            subtitle: function () { return 'Pour les relances : les plus gros montants en premier.'; },
            render: function (card, body, A, asTable) {
                const rows = Object.keys(A.clubs).filter(function (c) { return A.clubs[c].remaining > 0; }).map(function (c) {
                    return { label: data.clubs[c], values: [A.clubs[c].remaining], extra: [{ value: nf0.format(A.clubs[c].due), label: 'engagements à payer' }] };
                });

                barsOrTable(card, body, asTable, asTable ? rows.sort(function (a, b) { return b.values[0] - a.values[0]; }) : topRows(rows, 'Autres clubs'),
                    [{ name: 'Reste dû', color: COLOR.due }], { format: FMT.euro, header: 'Club', empty: 'Rien à recouvrer : tout est réglé.' });
            }
        },
        {
            id: 't-tarifs',
            title: 'Recette par tarif',
            subtitle: function () { return 'Encaissé et reste dû pour chaque tarif (standard ou règle spéciale).'; },
            render: function (card, body, A, asTable) {
                const rows = Object.keys(A.tarifs).map(function (t) {
                    return { label: t, values: [A.tarifs[t].collected, A.tarifs[t].remaining], extra: [{ value: nf0.format(A.tarifs[t].count), label: 'engagements' }] };
                });

                barsOrTable(card, body, asTable, asTable ? rows : topRows(rows, 'Autres tarifs'),
                    [{ name: 'Encaissé', color: COLOR.paid }, { name: 'Reste dû', color: COLOR.due }], { format: FMT.euro, header: 'Tarif', empty: 'Aucun engagement payant.' });
            }
        },
        {
            id: 't-bank',
            title: 'Remises de chèques et clôtures de caisse',
            tableOnly: true,
            subtitle: function () { return 'Tous départs confondus.'; },
            render: function (card, body) {
                const grid = el('div', { class: 'rg-viz-split' });
                const deposits = el('div', {});
                deposits.appendChild(el('h3', {}, 'Remises déposées'));
                deposits.appendChild(table(['Remise', 'Date', 'Chèques', 'Montant'], data.deposits.map(function (d) {
                    return [d.id, dateFull.format(parseDate(d.at)), nf0.format(d.count), euro(d.total)];
                }), 2));

                const closures = el('div', {});
                closures.appendChild(el('h3', {}, 'Clôtures de caisse'));
                closures.appendChild(table(['Clôture', 'Jusqu\'au', 'Espèces attendues', 'Comptées', 'Écart'], data.closures.map(function (c) {
                    return [c.id, dateFull.format(parseDate(c.to)), euro(c.expected), c.counted === null ? '—' : euro(c.counted), c.diff === null ? '—' : signedEuro(c.diff)];
                }), 2));

                grid.appendChild(deposits);
                grid.appendChild(closures);
                body.appendChild(grid);
            }
        }
    ];

    // Courbe en escalier de l'encaissé cumulé, avec la ligne du montant attendu
    function renderTimeline(card, body, A, asTable) {
        let total = 0;
        const points = A.J.map(function (op) {
            total = Math.round((total + op.amount) * 100) / 100;

            return { t: op.t, type: op.type, delta: op.amount, method: op.method, operator: op.operator, v: total };
        });
        const target = A.expected;
        const last = points.length ? points[points.length - 1].v : 0;
        const fmt = FMT.euro;

        if (asTable) {
            body.appendChild(table(['Date', 'Opération', 'Mode', 'Opérateur', 'Montant', 'Cumul'], points.slice().reverse().map(function (p) {
                return [dateFull.format(p.t), OPERATION_LABELS[p.type] || p.type, modeLabel(p.method), p.operator || '—', signedEuro(p.delta), euro(p.v)];
            }), 4));
            return;
        }

        if (!points.length) {
            empty(body, 'Aucune opération de caisse pour l\'instant.');
            return;
        }

        const tip = tooltip(card);
        const width = Math.max(280, body.clientWidth);
        const height = 260;
        const yScale = niceTicks(Math.max(last, target, Math.max.apply(null, points.map(function (p) { return p.v; }))), 4, false);
        const margin = {
            top: 18,
            right: 18,
            bottom: 28,
            left: Math.ceil(Math.max.apply(null, yScale.ticks.map(function (t) { return textWidth(fmt.tick(t), 11); }))) + 12
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
            'aria-label': 'Encaissé au total : ' + euro(last) + ' sur ' + euro(target) + ' attendus.'
        });

        yScale.ticks.forEach(function (t) {
            chart.appendChild(svg('line', { x1: margin.left, x2: width - margin.right, y1: y(t), y2: y(t), class: t === 0 ? 'rg-viz-axis' : 'rg-viz-grid' }));
            chart.appendChild(svg('text', { x: margin.left - 8, y: y(t) + 4, class: 'rg-viz-tick', 'text-anchor': 'end' }, fmt.tick(t)));
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
            chart.appendChild(svg('text', { x: margin.left + 6, y: y(target) - 6, class: 'rg-viz-ref-label' }, 'Attendu : ' + euro(target)));
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

        const endLabel = euro(last);
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

        let currentIndex = points.length - 1;

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
            currentIndex = i;
            const p = points[i];
            const px = x(p.t);

            crossLine.setAttribute('x1', px);
            crossLine.setAttribute('x2', px);
            crossDot.setAttribute('cx', px);
            crossDot.setAttribute('cy', y(p.v));
            cross.style.display = '';

            const box = chart.getBoundingClientRect();
            const pos = pointIn(card, box.left + px, box.top + y(p.v));
            const detail = signedEuro(p.delta) + ' · ' + (OPERATION_LABELS[p.type] || p.type) + ' · ' + modeLabel(p.method) + (p.operator ? ' · ' + p.operator : '');

            tip.show(dateFull.format(p.t), [{ value: euro(p.v), label: 'Encaissé au total', color: 'var(--viz-series-1)' }], detail, pos.x, pos.y);
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
        overlay.addEventListener('focus', function () { focusPoint(currentIndex); });
        overlay.addEventListener('blur', leave);
        overlay.addEventListener('keydown', function (ev) {
            if (ev.key === 'ArrowLeft' || ev.key === 'ArrowRight') {
                ev.preventDefault();
                focusPoint(Math.min(points.length - 1, Math.max(0, currentIndex + (ev.key === 'ArrowLeft' ? -1 : 1))));
            }
        });

        body.appendChild(chart);

        // Le journal ne couvre pas tout (paiements antérieurs, tarif changé après coup)
        const gap = Math.round((A.collected - last) * 100) / 100;

        if (Math.abs(gap) >= 0.01) {
            body.appendChild(el('p', { class: 'rg-help rg-viz-note' },
                'Écart de ' + euro(Math.abs(gap)) + ' entre la courbe et l\'encaissé actuel : paiements enregistrés avant la mise en place '
                + 'du journal de caisse, ou tarifs modifiés après encaissement.'));
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

        // Par jours du calendrier au-delà d'une journée : un pas fixe de 24 h se
        // décalerait au changement d'heure (dernier dimanche de mars et d'octobre)
        const next = function (t) {
            if (step < DAY) {
                return t + step;
            }

            const d = new Date(t);
            d.setDate(d.getDate() + Math.round(step / DAY));

            return d.getTime();
        };

        for (let t = start.getTime(); t <= t1; t = next(t)) {
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
    // Page Terrain : ce qui se passe au greffe, en ce moment
    // -----------------------------------------------------------------------
    // Jour suivi : aujourd'hui s'il y a eu des opérations, sinon le dernier jour actif
    function activityDay(A) {
        const today = dayKey(NOW);

        if (A.J.some(function (op) { return dayKey(op.t) === today; })) {
            return { key: today, t: NOW, isToday: true };
        }

        if (!A.J.length) {
            return null;
        }

        const lastT = A.J[A.J.length - 1].t;

        return { key: dayKey(lastT), t: lastT, isToday: false };
    }

    function terrainTiles(A) {
        const today = dayKey(NOW);
        const todayOps = A.J.filter(function (op) { return dayKey(op.t) === today; });
        const todayPayments = todayOps.filter(function (op) { return op.type === 'payment'; });
        const operators = {};

        todayOps.forEach(function (op) {
            operators[op.operator || '?'] = true;
        });

        const lastOp = A.J.length ? A.J[A.J.length - 1] : null;

        return [
            { label: 'À encaisser', value: nf0.format(A.counts.due), sub: euro(A.remaining) + ' restant dû' },
            { label: 'Encaissé aujourd\'hui', value: euro(todayOps.reduce(function (s, op) { return s + op.amount; }, 0)), sub: plural(todayPayments.length, 'encaissement', 'encaissements') },
            {
                label: 'Dernière opération',
                value: lastOp ? since(lastOp.t, NOW) : '—',
                sub: lastOp ? (OPERATION_LABELS[lastOp.type] || lastOp.type) + ' · ' + (lastOp.operator || 'opérateur non renseigné') + (lastOp.device ? ' · ' + lastOp.device : '') : 'aucune opération'
            },
            { label: 'Opérateurs aujourd\'hui', value: nf0.format(Object.keys(operators).length), sub: 'personnes ayant encaissé' },
            { label: 'Reçus demandés', value: nf0.format(A.receipts), sub: 'à remettre aux archers' },
            { label: 'Sans cible', value: nf0.format(A.unassigned), warn: A.unassigned > 0, sub: A.unassigned ? 'à placer dans I@nseo' : 'tous placés' }
        ];
    }

    function countBy(ops, keyOf, labelOf) {
        const groups = {};

        ops.forEach(function (op) {
            const g = add(groups, keyOf(op), { count: 0, amount: 0 });
            g.count += op.type === 'payment' ? 1 : 0;
            g.amount += op.amount;
            g.all = (g.all || 0) + 1;
        });

        return Object.keys(groups).map(function (k) {
            return {
                label: labelOf(k),
                values: [groups[k].count],
                extra: [{ value: euro(groups[k].amount), label: 'encaissé net' }, { value: nf0.format(groups[k].all), label: 'opérations' }]
            };
        }).sort(function (a, b) { return b.values[0] - a.values[0]; });
    }

    const TERRAIN_CARDS = [
        {
            id: 'f-hours',
            wide: true,
            title: 'Activité heure par heure',
            subtitle: function (A) {
                const day = activityDay(A);

                return !day ? 'Nombre d\'encaissements par heure.'
                    : 'Encaissements par heure, ' + (day.isToday ? 'aujourd\'hui' : 'le ' + dateLong.format(day.t) + ' (dernier jour avec des opérations)') + '.';
            },
            render: function (card, body, A, asTable) {
                const day = activityDay(A);
                const hours = {};

                (day ? A.J.filter(function (op) { return dayKey(op.t) === day.key; }) : []).forEach(function (op) {
                    const h = new Date(op.t).getHours();
                    const g = hours[h] || (hours[h] = { count: 0, amount: 0 });
                    g.count += op.type === 'payment' ? 1 : 0;
                    g.amount += op.amount;
                });

                const list = Object.keys(hours).map(Number);
                const cols = [];

                if (list.length) {
                    for (let h = Math.min.apply(null, list); h <= Math.max.apply(null, list); h++) {
                        const g = hours[h] || { count: 0, amount: 0 };
                        cols.push({ label: h + ' h', title: h + ' h – ' + (h + 1) + ' h', value: g.count, extra: [{ value: euro(g.amount), label: 'encaissé net' }] });
                    }
                }

                if (asTable) {
                    body.appendChild(table(['Heure', 'Encaissements', 'Encaissé net'], cols.map(function (c) {
                        return [c.title, nf0.format(c.value), c.extra[0].value];
                    }), 1));
                    return;
                }

                if (!cols.length) {
                    empty(body, 'Aucune opération de caisse pour l\'instant.');
                    return;
                }

                renderColumns(card, body, cols, { format: FMT.count, name: 'encaissements' });
            }
        },
        {
            id: 'f-due',
            title: 'Reste à encaisser par départ',
            subtitle: function () { return 'Nombre d\'engagements à payer.' + (state.session === '*' && data.sessions.length > 1 ? ' Cliquez sur un départ pour filtrer.' : ''); },
            render: function (card, body, A, asTable) {
                const keys = Object.keys(A.sessions).sort(function (a, b) { return a.localeCompare(b, 'fr', { numeric: true }); });
                const rows = keys.filter(function (k) { return A.sessions[k].due > 0; }).map(function (k) {
                    return { label: sessionLabel(k), values: [A.sessions[k].due], extra: [{ value: euro(A.sessions[k].remaining), label: 'restant dû' }], onSelect: state.session === '*' && keys.length > 1 ? function () { setSession(k); } : null };
                });

                barsOrTable(card, body, asTable, rows, [{ name: 'À payer', color: COLOR.due }], { format: FMT.count, header: 'Départ', empty: 'Tout est encaissé.' });
            }
        },
        {
            id: 'f-operators',
            title: 'Encaissements par opérateur',
            subtitle: function () { return 'Sur tout le concours, d\'après le prénom saisi dans le champ Opérateur.'; },
            render: function (card, body, A, asTable) {
                barsOrTable(card, body, asTable, countBy(A.J, function (op) { return op.operator; }, function (k) { return k || 'Non renseigné'; }),
                    [{ name: 'Encaissements', color: COLOR.paid }], { format: FMT.count, header: 'Opérateur', empty: 'Aucune opération.' });
            }
        },
        {
            id: 'f-devices',
            title: 'Encaissements par poste',
            subtitle: function () { return 'Ordinateur, téléphone ou tablette.'; },
            render: function (card, body, A, asTable) {
                barsOrTable(card, body, asTable, countBy(A.J, function (op) { return op.device; }, function (k) { return k || 'Inconnu'; }),
                    [{ name: 'Encaissements', color: COLOR.paid }], { format: FMT.count, header: 'Poste', empty: 'Aucune opération.' });
            }
        },
        {
            id: 'f-sources',
            title: 'Encaissements par origine',
            subtitle: function () { return 'Depuis la liste, le mode Kiosk, un import ou HelloAsso.'; },
            render: function (card, body, A, asTable) {
                barsOrTable(card, body, asTable, countBy(A.J, function (op) { return op.source; }, function (k) { return SOURCE_LABELS[k] || k || 'Inconnue'; }),
                    [{ name: 'Encaissements', color: COLOR.paid }], { format: FMT.count, header: 'Origine', empty: 'Aucune opération.' });
            }
        }
    ];

    // -----------------------------------------------------------------------
    // Pages : tuiles puis cartes ; chaque carte peut passer en tableau
    // -----------------------------------------------------------------------
    const PAGES = {
        greffe: { tiles: greffeTiles, cards: GREFFE_CARDS },
        tresorier: { tiles: tresorierTiles, cards: TRESORIER_CARDS },
        terrain: { tiles: terrainTiles, cards: TERRAIN_CARDS }
    };

    function buildCard(def, grid, A) {
        const card = el('div', { class: 'rg-card rg-viz-card' + (def.wide || def.tableOnly ? ' is-wide' : '') });
        const head = el('div', { class: 'rg-viz-head' });
        const titles = el('div', {});
        titles.appendChild(el('h2', {}, def.title));
        titles.appendChild(el('p', { class: 'rg-help' }, def.subtitle(A)));
        head.appendChild(titles);

        const body = el('div', { class: 'rg-viz-body' });

        if (!def.tableOnly) {
            const seg = el('div', { class: 'rg-seg rg-seg-small rg-noprint', role: 'group', 'aria-label': 'Affichage' });

            [['chart', 'Graphique'], ['table', 'Tableau']].forEach(function (v) {
                const b = el('button', { type: 'button', 'aria-pressed': (state.views[def.id] || 'chart') === v[0] ? 'true' : 'false' }, v[1]);

                b.addEventListener('click', function () {
                    state.views[def.id] = v[0];
                    seg.querySelectorAll('button').forEach(function (other) {
                        other.setAttribute('aria-pressed', other === b ? 'true' : 'false');
                    });
                    renderCardBody(def, card, body, compute());
                });

                seg.appendChild(b);
            });

            head.appendChild(seg);
        }

        card.appendChild(head);
        card.appendChild(body);
        grid.appendChild(card);

        return { def: def, card: card, body: body };
    }

    function renderCardBody(def, card, body, A) {
        const tip = card.querySelector('.rg-viz-tip');

        if (tip) {
            tip.hidden = true;
        }

        body.textContent = '';
        def.render(card, body, A, state.views[def.id] === 'table');
    }

    function renderPage() {
        const page = PAGES[state.page];
        const A = compute();

        pageEl.textContent = '';
        renderTiles(pageEl, page.tiles(A));

        const grid = el('div', { class: 'rg-viz-cards' });
        pageEl.appendChild(grid);

        // Les cartes sont d'abord placées, puis dessinées à leur largeur réelle
        page.cards.map(function (def) {
            return buildCard(def, grid, A);
        }).forEach(function (c) {
            renderCardBody(c.def, c.card, c.body, A);
        });
    }

    // -----------------------------------------------------------------------
    // Navigation et filtres
    // -----------------------------------------------------------------------
    const tabs = Array.prototype.slice.call(root.querySelectorAll('[data-page]'));

    function setPage(page) {
        state.page = page;

        tabs.forEach(function (t) {
            t.setAttribute('aria-selected', t.getAttribute('data-page') === page ? 'true' : 'false');
        });

        try {
            localStorage.setItem(PAGE_KEY, page);
        } catch (e) {
            // stockage indisponible : sans conséquence
        }

        renderPage();
    }

    tabs.forEach(function (t) {
        t.addEventListener('click', function () {
            setPage(t.getAttribute('data-page'));
        });
    });

    const sessionSelect = document.getElementById('rgVizSession');

    function setSession(s) {
        state.session = s;

        if (sessionSelect) {
            sessionSelect.value = s;
        }

        renderPage();
    }

    if (sessionSelect) {
        sessionSelect.addEventListener('change', function () {
            setSession(sessionSelect.value);
        });
    }

    // Redessine à la bonne largeur quand la fenêtre change
    let lastWidth = root.clientWidth;
    let resizeTimer = null;

    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
            if (root.clientWidth !== lastWidth) {
                lastWidth = root.clientWidth;
                renderPage();
            }
        }, 150);
    });

    setPage(state.page);
})();
