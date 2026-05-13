// mobile.js — RuneWorld mobile v11.1
// Capa móvil ligera.
// Principio: usar la lógica web existente, no inventar otra.
// - La navegación móvil llama a los botones reales.
// - La barra inferior solo refleja los displays reales de ui.js.
// - El drawer derecho clona paneles solo al abrirse.
// - No clona paneles en cada click de tirar runa.

(function () {
    'use strict';

    window.RW_MOBILE_VERSION = '11.1-light-dom-proxy-stats-sync';

    const MOBILE_MAX = 1024;

    const IDS = {
        topbar: 'mobile-topbar',
        navBtn: 'btn-menu-nav',
        runasBtn: 'btn-menu-runas',
        overlay: 'mobile-overlay',
        navDrawer: 'mobile-nav-drawer',
        runasDrawer: 'mobile-runas-drawer',
        statsBar: 'mobile-stats-bar'
    };

    let statsTimer = null;
    let statObserver = null;
    let collectionObserver = null;

    const statCache = {
        coins: '',
        points: '',
        coinsPs: '',
        pointsPs: '',
        bulk: '',
        luck: '',
        ts: 0
    };

    function isMobile() {
        return window.innerWidth <= MOBILE_MAX || window.matchMedia('(max-width: 1024px)').matches;
    }

    function byId(id) {
        return document.getElementById(id);
    }

    function make(tag, attrs, html) {
        const el = document.createElement(tag);
        if (attrs) {
            Object.keys(attrs).forEach((key) => {
                if (key === 'class') el.className = attrs[key];
                else if (key === 'text') el.textContent = attrs[key];
                else el.setAttribute(key, attrs[key]);
            });
        }
        if (html != null) el.innerHTML = html;
        return el;
    }

    function normalize(txt) {
        return String(txt || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .trim();
    }

    function getGlobalValue(name) {
        try {
            return Function('return (typeof ' + name + ' !== "undefined") ? ' + name + ' : undefined;')();
        } catch (e) {
            return undefined;
        }
    }

    function fmtNumber(value) {
        const n = Number(value);
        if (!Number.isFinite(n)) return '';
        if (typeof window.formatNum === 'function') return window.formatNum(n);

        const abs = Math.abs(n);
        const sign = n < 0 ? '-' : '';
        function short(div, suffix) {
            return sign + (abs / div).toFixed(2).replace(/\.?0+$/, '') + suffix;
        }

        if (abs >= 1e12) return short(1e12, 'T');
        if (abs >= 1e9) return short(1e9, 'B');
        if (abs >= 1e6) return short(1e6, 'M');
        if (abs >= 1e3) return short(1e3, 'K');
        return sign + Math.floor(abs).toString();
    }

    function fmtRate(value) {
        const n = Number(value);
        if (!Number.isFinite(n)) return '';
        return '+' + fmtNumber(n) + '/seg';
    }

    function deepFind(obj, keys) {
        if (!obj || typeof obj !== 'object') return undefined;

        for (const key of keys) {
            if (Object.prototype.hasOwnProperty.call(obj, key)) return obj[key];
        }

        const stack = [obj];
        const seen = new Set();

        while (stack.length) {
            const cur = stack.pop();
            if (!cur || typeof cur !== 'object' || seen.has(cur)) continue;
            seen.add(cur);

            for (const key of keys) {
                if (Object.prototype.hasOwnProperty.call(cur, key)) return cur[key];
            }

            Object.keys(cur).forEach((k) => {
                const v = cur[k];
                if (v && typeof v === 'object') stack.push(v);
            });
        }

        return undefined;
    }

    function ingestStatsPayload(payload) {
        const d = payload && payload.detail ? payload.detail : payload;
        if (!d || typeof d !== 'object') return;

        const coinsVal = deepFind(d, ['coins', 'monedas']);
        const pointsVal = deepFind(d, ['points', 'puntos']);
        const coinsPsVal = deepFind(d, ['coins_ps', 'coins_por_seg', 'coinsPerSecond', 'monedas_por_seg']);
        const pointsPsVal = deepFind(d, ['points_ps', 'points_por_seg', 'pointsPerSecond', 'puntos_por_seg']);
        const bulkVal = deepFind(d, ['bulk', 'bulk_total', 'bulk_runas']);
        const luckVal = deepFind(d, ['luck_multiplier', 'suerte', 'suerte_total']);

        if (coinsVal !== undefined) statCache.coins = fmtNumber(coinsVal);
        if (pointsVal !== undefined) statCache.points = fmtNumber(pointsVal);
        if (coinsPsVal !== undefined) statCache.coinsPs = fmtRate(coinsPsVal);
        if (pointsPsVal !== undefined) statCache.pointsPs = fmtRate(pointsPsVal);
        if (bulkVal !== undefined) statCache.bulk = String(parseInt(bulkVal, 10) || 1);
        if (luckVal !== undefined) statCache.luck = String(luckVal).charAt(0) === 'x'
            ? String(luckVal)
            : 'x' + (parseFloat(luckVal) || 1).toFixed(2);

        statCache.ts = Date.now();
    }

    function cacheValue(key) {
        return (Date.now() - statCache.ts) < 4000 ? (statCache[key] || '') : '';
    }

    function textOf(id) {
        const el = byId(id);
        return el ? String(el.textContent || el.value || '').replace(/\s+/g, ' ').trim() : '';
    }

    function setText(id, value) {
        const el = byId(id);
        if (el) el.textContent = value;
    }

    function visibleUsername() {
        const candidates = [
            '#header .bienvenido strong',
            '.bienvenido strong',
            '#nombre-usuario',
            '#usuario-nombre',
            '#user-name',
            '.nombre-usuario',
            '.usuario-nombre',
            '.user-name',
            '[data-usuario]',
            '[data-username]'
        ];

        for (const sel of candidates) {
            const el = document.querySelector(sel);
            if (!el) continue;
            const txt = String(el.textContent || el.value || '').trim();
            if (txt) return txt;
        }

        return 'JUGADOR';
    }

    function svgMenu() {
        return '<svg class="mobile-topbar-icon-svg" viewBox="0 0 24 24" aria-hidden="true">' +
            '<path d="M4 7h16M4 12h16M4 17h16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>' +
        '</svg>';
    }

    function svgRunas() {
        return '<svg class="mobile-topbar-icon-svg" viewBox="0 0 24 24" aria-hidden="true">' +
            '<path d="M12 3l7 4v10l-7 4-7-4V7l7-4z" fill="none" stroke="currentColor" stroke-width="1.9"/>' +
            '<path d="M12 7v10M8.5 9.2l7 5.6M15.5 9.2l-7 5.6" fill="none" stroke="currentColor" stroke-width="1.45" stroke-linecap="round"/>' +
        '</svg>';
    }

    function createTopbar() {
        if (byId(IDS.topbar)) return;

        const topbar = make('div', { id: IDS.topbar });

        const navBtn = make('button', {
            id: IDS.navBtn,
            class: 'mobile-topbar-btn',
            type: 'button',
            'aria-label': 'Abrir menú'
        }, svgMenu());

        const main = make('div', { class: 'topbar-main' });
        main.innerHTML =
            '<div class="topbar-title">RuneWorld</div>' +
            '<div class="topbar-user">USUARIO <strong></strong></div>';
        main.querySelector('strong').textContent = visibleUsername();

        const runasBtn = make('button', {
            id: IDS.runasBtn,
            class: 'mobile-topbar-btn',
            type: 'button',
            'aria-label': 'Abrir panel derecho'
        }, svgRunas());

        topbar.appendChild(navBtn);
        topbar.appendChild(main);
        topbar.appendChild(runasBtn);
        document.body.appendChild(topbar);
    }

    function createOverlay() {
        if (byId(IDS.overlay)) return;
        document.body.appendChild(make('div', { id: IDS.overlay }));
    }

    function originalNavButtons() {
        return Array.from(document.querySelectorAll(
            '#sidebar .nav-btn, #sidebar button, #sidebar a, #header .nav-btn, [data-sec], [data-section], [data-seccion]'
        )).filter((el) => {
            if (!el || el.closest('#mobile-nav-drawer')) return false;
            if (el.closest('#mobile-topbar')) return false;
            const txt = normalize(el.textContent);
            if (!txt || txt.length > 60) return false;
            return true;
        });
    }

    function prefixIds(root, prefix) {
        if (!root || !root.querySelectorAll) return;

        if (root.id) root.id = prefix + root.id;

        root.querySelectorAll('[id]').forEach((el) => {
            el.id = prefix + el.id;
        });

        root.querySelectorAll('label[for]').forEach((el) => {
            el.setAttribute('for', prefix + el.getAttribute('for'));
        });
    }

    function sectionFromOriginal(el) {
        const ds = el.dataset || {};
        let target = ds.sec || ds.section || ds.seccion || ds.target || ds.tab || el.getAttribute('href') || '';
        target = String(target || '').replace(/^#/, '');
        if (!target) return '';
        if (!target.startsWith('seccion-')) target = 'seccion-' + target;
        return target;
    }

    function fallbackShowSection(sectionId) {
        if (!sectionId) return;

        if (typeof window.mostrarSeccion === 'function') {
            window.mostrarSeccion(sectionId.replace(/^seccion-/, ''));
            return;
        }

        const sec = byId(sectionId);
        if (!sec) return;

        document.querySelectorAll('.seccion').forEach((s) => s.classList.remove('activa'));
        sec.classList.add('activa');
    }

    function createNavDrawer() {
        if (byId(IDS.navDrawer)) return;

        const drawer = make('aside', { id: IDS.navDrawer, 'aria-hidden': 'true' });
        drawer.appendChild(make('div', { class: 'drawer-titulo', text: 'Navegación' }));

        const holder = make('div', { class: 'mobile-nav-items' });
        drawer.appendChild(holder);
        document.body.appendChild(drawer);

        rebuildNavDrawer();
    }

    function rebuildNavDrawer() {
        const holder = document.querySelector('#mobile-nav-drawer .mobile-nav-items');
        if (!holder) return;

        holder.innerHTML = '';

        const originals = originalNavButtons();
        const used = new Set();

        originals.forEach((original) => {
            const label = String(original.textContent || '').replace(/\s+/g, ' ').trim();
            const key = normalize(label);
            if (!key || used.has(key)) return;
            used.add(key);

            const clone = make('button', {
                type: 'button',
                class: 'mob-nav' + (original.classList.contains('danger') || key.includes('borrar') ? ' danger' : ''),
                'data-mobile-target': sectionFromOriginal(original)
            });

            clone.innerHTML = original.innerHTML || label;
            prefixIds(clone, 'mob-nav-');

            clone.addEventListener('click', () => {
                if (typeof original.click === 'function') original.click();
                else fallbackShowSection(sectionFromOriginal(original));

                closeDrawers();
                window.setTimeout(() => {
                    updateActiveNav();
                    updateRightDrawerMode();
                    applyCollectionMobileCleanup();
                    syncStatsBar();
                }, 40);
            });

            holder.appendChild(clone);
        });

        if (!holder.children.length) {
            [
                ['Tirar runa', 'seccion-tirada'],
                ['Tienda', 'seccion-tienda'],
                ['Colección', 'seccion-coleccion'],
                ['Estadísticas', 'seccion-estadisticas'],
                ['Ajustes', 'seccion-ajustes'],
                ['Engranajes', 'seccion-engranajes']
            ].forEach(([label, id]) => {
                if (!byId(id)) return;

                const btn = make('button', {
                    type: 'button',
                    class: 'mob-nav',
                    'data-mobile-target': id,
                    text: label
                });

                btn.addEventListener('click', () => {
                    fallbackShowSection(id);
                    closeDrawers();
                    updateActiveNav();
                    updateRightDrawerMode();
                    applyCollectionMobileCleanup();
                });

                holder.appendChild(btn);
            });
        }

        updateActiveNav();
    }

    function updateActiveNav() {
        const active = document.querySelector('.seccion.activa');
        const activeId = active ? active.id : '';

        document.querySelectorAll('#mobile-nav-drawer .mob-nav').forEach((btn) => {
            btn.classList.toggle('active', btn.getAttribute('data-mobile-target') === activeId);
        });
    }

    function createRightDrawer() {
        if (byId(IDS.runasDrawer)) return;

        const drawer = make('aside', { id: IDS.runasDrawer, 'aria-hidden': 'true' });
        drawer.innerHTML =
            '<div id="mobile-runas-scroll">' +
                '<div id="panel-col-stats-mobile"></div>' +
                '<div id="panel-mis-runas-mobile"></div>' +
            '</div>';

        document.body.appendChild(drawer);
        updateRightDrawerMode();
    }

    function isCollectionActive() {
        const sec = byId('seccion-coleccion');
        return !!(sec && sec.classList.contains('activa'));
    }

    function updateRightDrawerMode() {
        const drawer = byId(IDS.runasDrawer);
        if (!drawer) return;

        const collection = isCollectionActive();
        drawer.classList.toggle('rw-drawer-mode-stats', collection);
        drawer.classList.toggle('rw-drawer-mode-runas', !collection);

        const title = drawer.querySelector('.drawer-titulo');
        if (title) title.textContent = collection ? 'Stats de runa' : 'Mis runas';
    }

    function clonePanel(sourceSelector, targetId) {
        const source = document.querySelector(sourceSelector);
        const target = byId(targetId);
        if (!source || !target) return;

        const html = source.innerHTML || '';
        if (target.getAttribute('data-last-html') === html) return;
        target.setAttribute('data-last-html', html);

        target.innerHTML = '';

        Array.from(source.childNodes).forEach((node) => {
            const clone = node.cloneNode(true);
            if (clone.nodeType === 1) prefixIds(clone, 'mob-');
            target.appendChild(clone);
        });
    }

    function syncRightDrawerNow() {
        updateRightDrawerMode();

        if (isCollectionActive()) {
            clonePanel('#panel-col-stats', 'panel-col-stats-mobile');
        } else {
            clonePanel('#panel-mis-runas', 'panel-mis-runas-mobile');
            removeDuplicateCollectionTitle(byId('panel-mis-runas-mobile'));
        }
    }

    function openDrawer(which) {
        const nav = byId(IDS.navDrawer);
        const runas = byId(IDS.runasDrawer);
        const overlay = byId(IDS.overlay);

        if (which === 'nav') {
            if (runas) {
                runas.classList.remove('open');
                runas.setAttribute('aria-hidden', 'true');
            }

            if (nav) {
                nav.classList.add('open');
                nav.setAttribute('aria-hidden', 'false');
            }
        }

        if (which === 'runas') {
            if (nav) {
                nav.classList.remove('open');
                nav.setAttribute('aria-hidden', 'true');
            }

            if (runas) {
                syncRightDrawerNow();
                runas.classList.add('open');
                runas.setAttribute('aria-hidden', 'false');
            }
        }

        if (overlay) overlay.classList.add('visible');
    }

    function closeDrawers() {
        const nav = byId(IDS.navDrawer);
        const runas = byId(IDS.runasDrawer);
        const overlay = byId(IDS.overlay);

        if (document.activeElement && document.activeElement.closest &&
            (document.activeElement.closest('#mobile-nav-drawer') || document.activeElement.closest('#mobile-runas-drawer'))) {
            const fallback = byId(IDS.navBtn) || byId(IDS.runasBtn) || document.body;
            if (fallback && fallback.focus) fallback.focus({ preventScroll: true });
        }

        if (nav) {
            nav.classList.remove('open');
            nav.setAttribute('aria-hidden', 'true');
        }

        if (runas) {
            runas.classList.remove('open');
            runas.setAttribute('aria-hidden', 'true');
        }

        if (overlay) overlay.classList.remove('visible');
    }

    function statIconFor(displayId, fallback) {
        const el = byId(displayId);
        if (!el) return fallback;

        const box = el.closest('.stat-block') ||
            el.closest('.stat-card, .stat-box, .panel-stat, .stats-card, .stat') ||
            el.parentElement;

        if (!box) return fallback;

        const icon = box.querySelector('svg.stat-icon, .stat-icon svg, img.stat-icon, .stat-icon, svg, img, .icon, .ico');
        return icon ? icon.outerHTML : fallback;
    }

    function statItem(key, label, valueId, rateId, iconHtml) {
        const el = make('div', { class: 'mob-stat mob-stat-' + key });
        el.innerHTML =
            '<div class="mob-icon">' + iconHtml + '</div>' +
            '<div class="mob-val" id="' + valueId + '">0</div>' +
            '<div class="mob-rate" id="' + rateId + '">' + label + '</div>';
        return el;
    }

    function sep() {
        return make('div', { class: 'mob-sep' });
    }

    function createStatsBar() {
        if (byId(IDS.statsBar)) return;

        const bar = make('div', { id: IDS.statsBar });

        bar.appendChild(statItem('coins', 'coins/s', 'ms-coins', 'ms-coins-rate', statIconFor('coins-display', '◈')));
        bar.appendChild(sep());
        bar.appendChild(statItem('points', 'pts/s', 'ms-points', 'ms-points-rate', statIconFor('points-display', '✦')));
        bar.appendChild(sep());
        bar.appendChild(statItem('bulk', 'por tirada', 'ms-bulk', 'ms-bulk-rate', statIconFor('bulk-display', '×')));
        bar.appendChild(sep());
        bar.appendChild(statItem('suerte', 'suerte total', 'ms-suerte', 'ms-suerte-rate', statIconFor('luck-display', '☽')));

        document.body.appendChild(bar);
        syncStatsBar();
    }

    function syncStatsBar() {
        const coins = cacheValue('coins') || textOf('coins-display') || fmtNumber(getGlobalValue('coins')) || '0';
        const points = cacheValue('points') || textOf('points-display') || fmtNumber(getGlobalValue('points')) || '0';

        const bulkGlobal = getGlobalValue('bulk_runas');
        const bulkText = cacheValue('bulk') || textOf('bulk-display') || (bulkGlobal !== undefined ? String(parseInt(bulkGlobal, 10) || 1) : '1');
        const bulkNum = String(bulkText || '').match(/\d+/);
        const bulk = bulkNum ? bulkNum[0] : '1';

        const luckGlobal = getGlobalValue('luck_multiplier');
        const luck = cacheValue('luck') || textOf('luck-display') || textOf('suerte-display') ||
            (luckGlobal !== undefined ? 'x' + (parseFloat(luckGlobal) || 1).toFixed(2) : 'x1.00');

        const coinsPsGlobal = getGlobalValue('coins_ps');
        const pointsPsGlobal = getGlobalValue('points_ps');

        const coinsPs = cacheValue('coinsPs') ||
            (coinsPsGlobal !== undefined ? fmtRate(coinsPsGlobal) : '') ||
            textOf('coins-ps-display') ||
            'coins/s';

        const pointsPs = cacheValue('pointsPs') ||
            (pointsPsGlobal !== undefined ? fmtRate(pointsPsGlobal) : '') ||
            textOf('points-ps-display') ||
            'pts/s';

        setText('ms-coins', coins);
        setText('ms-points', points);
        setText('ms-bulk', bulk);
        setText('ms-suerte', luck);
        setText('ms-coins-rate', coinsPs);
        setText('ms-points-rate', pointsPs);
        setText('ms-bulk-rate', 'por tirada');
        setText('ms-suerte-rate', 'suerte total');
    }

    function patchStatsPainters() {
        ['actualizarPantalla', 'refrescarEstadisticas', 'recalcularStatsDesdeMejoras', 'aplicarBoosts'].forEach((name) => {
            if (typeof window[name] !== 'function') return;
            if (window[name].__rwMobileStatsPatched) return;

            const original = window[name];

            window[name] = function () {
                const result = original.apply(this, arguments);
                syncStatsBar();
                window.setTimeout(syncStatsBar, 60);
                window.setTimeout(syncStatsBar, 180);
                return result;
            };

            window[name].__rwMobileStatsPatched = true;
            window[name].__rwOriginal = original;
        });
    }

    function observeStatsDisplays() {
        if (statObserver || !('MutationObserver' in window)) return;

        const ids = [
            'coins-display',
            'points-display',
            'coins-ps-display',
            'points-ps-display',
            'bulk-display',
            'luck-display',
            'suerte-display'
        ];

        const nodes = ids.map(byId).filter(Boolean);
        if (!nodes.length) return;

        statObserver = new MutationObserver(() => {
            window.requestAnimationFrame(syncStatsBar);
        });

        nodes.forEach((node) => {
            statObserver.observe(node, {
                childList: true,
                characterData: true,
                subtree: true
            });
        });
    }

    function removeDuplicateCollectionTitle(root) {
        if (!root || !root.querySelectorAll) return;

        root.querySelectorAll('.col-contador, .col-contador-label, h1, h2, h3, h4, .panel-titulo, .coleccion-title, .coleccion-titulo, .seccion-titulo, .titulo-seccion').forEach((el) => {
            if (!el || !el.isConnected) return;
            if (el.closest('#mobile-nav-drawer')) return;

            const txt = normalize(el.textContent);
            if (txt === 'coleccion' || txt === 'collection') {
                const counter = el.closest('.col-contador');
                if (counter) counter.remove();
                else el.remove();
            }
        });
    }

    function activeVariantIsCorrupt(root) {
        if (!root) return false;

        const active = root.querySelector(
            '.coleccion-variante-pill.active, ' +
            '.coleccion-variante-pill.activa, ' +
            '.coleccion-variante-pill[aria-current="true"], ' +
            '[data-variante].active, [data-variante].activa, ' +
            '[data-variant].active, [data-variant].activa'
        );

        if (!active) return false;

        const raw = [
            active.textContent,
            active.className,
            active.getAttribute('data-variante'),
            active.getAttribute('data-variant')
        ].join(' ');

        const txt = normalize(raw);
        const locked = active.disabled ||
            active.getAttribute('aria-disabled') === 'true' ||
            txt.includes('bloquead') ||
            txt.includes('locked') ||
            Boolean(active.querySelector('.locked, .bloqueado, .bloqueada, [data-locked]'));

        return txt.includes('corrupt') && !locked;
    }

    function applyCollectionMobileCleanup() {
        const sec = byId('seccion-coleccion');
        if (!sec) return;

        removeDuplicateCollectionTitle(sec);

        if (!isMobile()) return;

        const showCorrupt = activeVariantIsCorrupt(sec);

        sec.classList.toggle('rw-variant-corrupta', showCorrupt);
        sec.classList.toggle('rw-variant-normal', !showCorrupt);

        // El bloque desktop no debe verse en móvil. En móvil solo usamos el bonus mobile.
        sec.querySelectorAll('.coleccion-bonus-desktop').forEach((el) => {
            el.style.setProperty('display', 'none', 'important');
            el.setAttribute('data-rw-mobile-hidden', '1');
        });

        sec.querySelectorAll('.coleccion-bonus-mobile .coleccion-bonus-suerte-v74, .coleccion-bonus-mobile [data-bonus-coleccion]').forEach((el) => {
            const tipo = normalize(el.getAttribute('data-bonus-coleccion') || el.dataset.bonusColeccion || el.textContent);
            const isCorrupt = tipo.includes('corrupt');
            const hide = isCorrupt ? !showCorrupt : showCorrupt;

            if (hide) {
                el.style.setProperty('display', 'none', 'important');
                el.setAttribute('data-rw-bonus-hidden', '1');
            } else {
                el.style.removeProperty('display');
                el.removeAttribute('data-rw-bonus-hidden');
            }
        });
    }

    function observeCollectionLight() {
        if (collectionObserver || !('MutationObserver' in window)) return;

        const sec = byId('seccion-coleccion');
        if (!sec) return;

        collectionObserver = new MutationObserver(() => {
            window.requestAnimationFrame(applyCollectionMobileCleanup);
        });

        collectionObserver.observe(sec, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['class', 'hidden', 'aria-disabled', 'aria-current', 'data-variante', 'data-variant', 'data-bonus-coleccion']
        });
    }

    function bindEvents() {
        const navBtn = byId(IDS.navBtn);
        const runasBtn = byId(IDS.runasBtn);
        const overlay = byId(IDS.overlay);

        if (navBtn && !navBtn.__rwMobileBound) {
            navBtn.__rwMobileBound = true;
            navBtn.addEventListener('click', () => openDrawer('nav'));
        }

        if (runasBtn && !runasBtn.__rwMobileBound) {
            runasBtn.__rwMobileBound = true;
            runasBtn.addEventListener('click', () => openDrawer('runas'));
        }

        if (overlay && !overlay.__rwMobileBound) {
            overlay.__rwMobileBound = true;
            overlay.addEventListener('click', closeDrawers);
        }

        document.addEventListener('keydown', (ev) => {
            if (ev.key === 'Escape') closeDrawers();
        });

        document.addEventListener('click', (ev) => {
            const collectionControl = ev.target && ev.target.closest
                ? ev.target.closest('.coleccion-lista-pill, .coleccion-variante-pill, [data-variante], [data-variant]')
                : null;

            if (collectionControl) {
                window.setTimeout(applyCollectionMobileCleanup, 30);
                window.setTimeout(applyCollectionMobileCleanup, 160);
            }
        }, true);

        ['runas:sync', 'runas:update', 'runas:recorte', 'rw:stats-updated', 'rw:stats-actualizadas'].forEach((eventName) => {
            window.addEventListener(eventName, (ev) => {
                ingestStatsPayload(ev);
                syncStatsBar();

                const drawer = byId(IDS.runasDrawer);
                if (drawer && drawer.classList.contains('open')) {
                    window.setTimeout(syncRightDrawerNow, 80);
                }

                window.setTimeout(syncStatsBar, 60);
                window.setTimeout(syncStatsBar, 180);
                window.setTimeout(applyCollectionMobileCleanup, 120);
            }, true);
        });
    }

    function patchFetchForStats() {
        if (window.fetch && !window.fetch.__rwMobileStatsPatched) {
            const originalFetch = window.fetch;

            window.fetch = function () {
                return originalFetch.apply(this, arguments).then((response) => {
                    try {
                        const url = String(arguments[0] && (arguments[0].url || arguments[0]) || '');
                        if (/tirar_runa|runa-sync|guardar|tienda|comprar/i.test(url)) {
                            response.clone().json().then((data) => {
                                ingestStatsPayload(data);
                                syncStatsBar();
                                window.setTimeout(syncStatsBar, 80);
                            }).catch(() => {});
                        }
                    } catch (e) {}
                    return response;
                });
            };

            window.fetch.__rwMobileStatsPatched = true;
        }
    }

    function boot() {
        if (!isMobile()) return;

        createOverlay();
        createTopbar();
        createNavDrawer();
        createRightDrawer();
        createStatsBar();

        bindEvents();
        patchFetchForStats();
        patchStatsPainters();
        observeStatsDisplays();
        observeCollectionLight();

        updateActiveNav();
        updateRightDrawerMode();
        applyCollectionMobileCleanup();
        syncStatsBar();

        if (!statsTimer) {
            statsTimer = window.setInterval(() => {
                if (isMobile()) syncStatsBar();
            }, 500);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
    } else {
        boot();
    }

    window.addEventListener('resize', () => {
        if (isMobile()) {
            boot();
            syncStatsBar();
            applyCollectionMobileCleanup();
        }
    });

    window.RW_MOBILE_SYNC_NOW = function () {
        syncStatsBar();
        syncRightDrawerNow();
        applyCollectionMobileCleanup();
        updateActiveNav();
        updateRightDrawerMode();
    };
})();
