// RegistrArc - assets/js/aide.js (scripts de aide.php)
//
// Met en forme le README avec marked, puis reproduit ce que fait GitHub :
// - identifiant de chaque titre, pour les liens internes (#la-caisse…)
// - encadrés > [!TIP], > [!IMPORTANT], > [!WARNING]…
// - liens vers des fichiers non installés : ouverts sur le dépôt GitHub
// - badges externes retirés (inutiles ici, et absents sans internet)

(function () {
    const source = document.getElementById('rgDocSource');
    const doc = document.getElementById('rgDoc');

    if (!source || !doc || !window.marked) {
        return;
    }

    doc.innerHTML = window.marked.parse(JSON.parse(source.textContent), { gfm: true });

    // Badges et images hébergés ailleurs
    doc.querySelectorAll('img').forEach(function (img) {
        if (/^https?:/i.test(img.getAttribute('src') || '')) {
            const parent = img.parentElement;
            img.remove();

            if (parent && parent.tagName === 'P' && parent.textContent.trim() === '' && !parent.querySelector('img')) {
                parent.remove();
            }
        }
    });

    // Tableaux : ceux du Markdown ont des en-têtes, les autres servent à la mise en page
    doc.querySelectorAll('table').forEach(function (table) {
        if (table.querySelector('th')) {
            const wrap = document.createElement('div');
            wrap.className = 'rg-doc-table-wrap';
            table.parentNode.insertBefore(wrap, table);
            wrap.appendChild(table);
        } else {
            table.classList.add('rg-doc-layout');
        }
    });

    // Identifiants des titres, calculés comme GitHub :
    // minuscules, ponctuation retirée, chaque espace remplacée par un tiret
    const used = {};

    doc.querySelectorAll('h1, h2, h3, h4, h5, h6').forEach(function (heading) {
        let slug = heading.textContent.trim().toLowerCase()
            .replace(/[^\p{L}\p{N}\s_-]/gu, '')
            .replace(/\s/g, '-');

        if (used[slug] !== undefined) {
            used[slug]++;
            slug += '-' + used[slug];
        } else {
            used[slug] = 0;
        }

        heading.id = slug;
    });

    // Encadrés GitHub
    const calloutLabels = {
        note: 'Remarque',
        tip: 'Astuce',
        important: 'Important',
        warning: 'Attention',
        caution: 'Prudence'
    };

    doc.querySelectorAll('blockquote').forEach(function (quote) {
        const first = quote.querySelector('p');
        const match = first ? first.innerHTML.match(/^\s*\[!(NOTE|TIP|IMPORTANT|WARNING|CAUTION)\]\s*(<br>\s*)?/i) : null;

        if (!match) {
            return;
        }

        const type = match[1].toLowerCase();
        first.innerHTML = first.innerHTML.slice(match[0].length);

        if (first.innerHTML.trim() === '') {
            first.remove();
        }

        const title = document.createElement('div');
        title.className = 'rg-callout-title';
        title.textContent = calloutLabels[type];

        quote.classList.add('rg-callout', 'rg-callout-' + type);
        quote.insertBefore(title, quote.firstChild);
    });

    // Liens : internes (#…), externes, ou vers un fichier du dépôt non installé
    const repo = doc.dataset.repo || '';

    doc.querySelectorAll('a[href]').forEach(function (link) {
        const href = link.getAttribute('href');

        if (href.charAt(0) === '#') {
            return;
        }

        if (!/^[a-z][a-z0-9+.-]*:/i.test(href) && repo !== '') {
            link.setAttribute('href', repo + href.replace(/^\.\//, ''));
        }

        link.target = '_blank';
        link.rel = 'noopener';
    });

    // Aller à une partie : ouvre les sections repliées qui la contiennent
    function reveal(id) {
        const target = id ? document.getElementById(id) : null;

        if (!target || !doc.contains(target)) {
            return false;
        }

        for (let el = target; el && el !== doc; el = el.parentElement) {
            if (el.tagName === 'DETAILS') {
                el.open = true;
            }
        }

        target.scrollIntoView({ behavior: 'smooth', block: 'start' });

        return true;
    }

    // Met à jour l'adresse (#partie) sans recharger ni faire défiler
    function setHash(hash) {
        try {
            history.replaceState(null, '', location.pathname + location.search + hash);
        } catch (e) {
            // adresse non modifiable (aperçu local) : sans conséquence
        }
    }

    function decodeHash(hash) {
        try {
            return decodeURIComponent(hash.replace(/^#/, ''));
        } catch (e) {
            return hash.replace(/^#/, '');
        }
    }

    doc.addEventListener('click', function (event) {
        const link = event.target.closest('a[href^="#"]');

        if (link && reveal(decodeHash(link.getAttribute('href')))) {
            event.preventDefault();
            setHash(link.getAttribute('href'));
        }
    });

    buildToc();

    if (location.hash) {
        reveal(decodeHash(location.hash));
    }

    // Sommaire : parties (h2), sous-parties (h3) et, sous la partie en cours
    // de lecture, ses sections repliables ; suit la lecture pendant le défilement
    function buildToc() {
        const aside = document.getElementById('rgDocToc');
        const nav = aside ? aside.querySelector('nav') : null;

        if (!nav) {
            return;
        }

        const targets = [];
        const list = document.createElement('ul');
        let group = null;
        let sub = null;

        doc.querySelectorAll('h2, h3, details > summary').forEach(function (node) {
            const text = node.textContent.trim();

            if (text === '') {
                return;
            }

            if (node.tagName === 'SUMMARY') {
                if (!node.id) {
                    let slug = 'section-' + text.toLowerCase().replace(/[^\p{L}\p{N}\s_-]/gu, '').replace(/\s+/g, '-');

                    if (used[slug] !== undefined) {
                        used[slug]++;
                        slug += '-' + used[slug];
                    } else {
                        used[slug] = 0;
                    }

                    node.id = slug;
                }
            } else if (!node.id) {
                return;
            }

            const item = document.createElement('li');
            const link = document.createElement('a');
            link.href = '#' + node.id;
            link.textContent = text;
            item.appendChild(link);

            if (node.tagName === 'H2') {
                group = item;
                group.className = 'rg-toc-group';
                sub = null;
                list.appendChild(item);
                link.className = 'rg-toc-h2';
            } else {
                if (!group) {
                    group = document.createElement('li');
                    group.className = 'rg-toc-group';
                    list.appendChild(group);
                }

                if (!sub) {
                    sub = document.createElement('ul');
                    group.appendChild(sub);
                }

                item.className = node.tagName === 'H3' ? 'rg-toc-h3' : 'rg-toc-section';
                sub.appendChild(item);
            }

            targets.push({ node: node, link: link, group: group });
        });

        if (!targets.length) {
            return;
        }

        nav.appendChild(list);
        aside.hidden = false;

        const toggle = aside.querySelector('.rg-toc-toggle');

        toggle.addEventListener('click', function () {
            const open = toggle.getAttribute('aria-expanded') !== 'true';
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            aside.classList.toggle('is-open', open);
        });

        // Clic dans le sommaire : ouvre la section, y va, referme le sommaire sur téléphone
        aside.addEventListener('click', function (event) {
            const link = event.target.closest('a[href^="#"]');

            if (!link) {
                return;
            }

            event.preventDefault();

            if (link.classList.contains('rg-toc-top')) {
                doc.scrollIntoView({ behavior: 'smooth', block: 'start' });
                setHash('');
            } else if (reveal(decodeHash(link.getAttribute('href')))) {
                setHash(link.getAttribute('href'));

                // L'entrée cliquée reste active, même si la page bute en bas,
                // jusqu'à ce que la personne fasse défiler elle-même
                pinned = targets.find(function (t) { return t.link === link; }) || null;
                schedule();
            }

            toggle.setAttribute('aria-expanded', 'false');
            aside.classList.remove('is-open');
        });

        // Partie en cours : le dernier titre arrivé en haut de l'écran ;
        // en bas de page, le dernier titre visible (il ne peut plus remonter)
        let current = null;
        let pending = false;
        let pinned = null;

        function update() {
            pending = false;

            if (pinned) {
                setCurrent(pinned);
                return;
            }

            let found = targets[0];
            const page = document.scrollingElement || document.documentElement;
            const atBottom = page.scrollTop > 0 && page.scrollTop + window.innerHeight >= page.scrollHeight - 2;

            targets.forEach(function (t) {
                // Une section dans une partie repliée n'a pas de position utile
                if (t.node.offsetParent === null) {
                    return;
                }

                const top = t.node.getBoundingClientRect().top;

                if (top < 48 || (atBottom && top < window.innerHeight / 2)) {
                    found = t;
                }
            });

            setCurrent(found);
        }

        function setCurrent(found) {
            if (found === current) {
                return;
            }

            if (current) {
                current.link.classList.remove('is-current');
                current.group.classList.remove('is-current');
            }

            current = found;
            current.link.classList.add('is-current');
            current.group.classList.add('is-current');

            // Garde l'entrée visible dans le sommaire, sans faire défiler la page
            const panel = aside.querySelector('.rg-toc-panel');
            const linkTop = current.link.offsetTop;

            if (linkTop < panel.scrollTop + 40 || linkTop > panel.scrollTop + panel.clientHeight - 60) {
                panel.scrollTop = Math.max(0, linkTop - panel.clientHeight / 3);
            }
        }

        function schedule() {
            if (!pending) {
                pending = true;
                window.requestAnimationFrame(update);
            }
        }

        // En capture : suit aussi un défilement dans un conteneur de la page I@nseo
        document.addEventListener('scroll', schedule, true);
        ['wheel', 'touchstart', 'keydown'].forEach(function (type) {
            window.addEventListener(type, function () {
                if (pinned) {
                    pinned = null;
                    schedule();
                }
            }, { passive: true, capture: true });
        });
        window.addEventListener('resize', schedule);
        doc.addEventListener('toggle', schedule, true);
        update();
    }

    // Boutons : tout déplier, tout replier, imprimer (tout déplié)
    function setAll(open) {
        doc.querySelectorAll('details').forEach(function (details) {
            details.open = open;
        });
    }

    document.querySelectorAll('[data-doc-action]').forEach(function (button) {
        button.addEventListener('click', function () {
            const action = button.getAttribute('data-doc-action');

            if (action === 'print') {
                setAll(true);
                window.print();
            } else {
                setAll(action === 'open');
            }
        });
    });

    window.addEventListener('beforeprint', function () {
        setAll(true);
    });
})();
