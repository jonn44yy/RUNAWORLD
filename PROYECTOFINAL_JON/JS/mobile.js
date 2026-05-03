// mobile.js — runaworld
// la capa movil del juego. runaworld esta pensado para desktop (1200+ px)
// con sidebars a ambos lados, pero en movil se descuadra todo. en lugar de
// hacer media queries gigantes en css y pelearme con cada panel, monto aqui
// una ui movil nueva por encima del dom existente: topbar, drawers laterales,
// botones flotantes y una barra de stats compacta abajo. el dom original
// sigue intacto debajo (solo lo oculto con css), asi si el jugador rota o
// redimensiona se puede volver al desktop sin rehacer nada
//
// indice:
//   1. early return si estamos en desktop (>1024px), no pinta absolutamente nada
//   2. inyeccion del html movil (topbar + drawers + botones flotantes + stats bar)
//   3. clonado del panel de runas desktop al drawer movil
//   4. syncStats() — sync cada 800ms de stats numericas y cantidades de runas
//   5. logica de drawers (abrir/cerrar con boton flotante o overlay)
//   6. navegacion delegada en mostrarSeccion() del desktop (ui.js)
//   7. swipe desde bordes de pantalla para abrir drawers
//
// lenguaje interno para los poco entendidos:
//   drawer       = panel que entra lateralmente (estilo app nativa). hay dos:
//                  uno izq con el menu, otro der con "mis runas". se abren
//                  con sus botones flotantes o con swipe desde el borde
//   overlay      = fondo semi-transparente que se enciende cuando un drawer
//                  esta abierto. sirve de click-fuera-para-cerrar y de velo
//                  visual sobre el juego de detras
//   syncStats    = en vez de meter listeners o refactorizar ui.js para que
//                  notifique a movil cuando algo cambia, tiro de polling
//                  simple: cada 800ms leo los textos del dom desktop y los
//                  copio al dom movil. feo pero funciona y es dos lineas
//   clonar panel = el panel de runas no se mueve, se clona. asi el desktop
//                  conserva su panel original (por si el jugador agranda la
//                  ventana y vuelve a desktop sin recargar) y el movil tiene
//                  su copia en el drawer
//
// hecho a principios de abril cuando probe el juego en el movil y vi que
// era un despropolisto. sigue funcionando, por ahora. !hi

// IIFE: envuelvo todo el archivo en una funcion auto-invocada para no
// ensuciar el scope global con variables como tx0, syncStats, etc. lo unico
// que exporto al window es cerrarDrawers (ver mas abajo)
window.RW_MOBILE_VERSION = '8.0';

(function () {
    // si el viewport es mayor de 1024px es desktop, no inyecto nada. esto hace
    // que este archivo sea practicamente free en desktop: carga y se va
    if (window.innerWidth > 1024) return;

    // saco el nombre del usuario del header del desktop para ponerlo en la
    // topbar movil. si no existe dejo string vacio (no fallo)
    const username = document.querySelector('#header .bienvenido strong')?.textContent || '';

    // inyeccion del html movil al final del body. va todo en un solo
    // insertAdjacentHTML porque es mas barato que crear los nodos uno a uno
    // con createElement, y mas legible que encadenar appendChild
    document.body.insertAdjacentHTML('beforeend', `
        <div id="mobile-overlay"></div>
        <div id="mobile-topbar">
            <button id="btn-menu-nav" class="mobile-topbar-btn" aria-label="Menú" type="button">
                <svg viewBox="0 0 24 24" fill="none" stroke="#ffd700" stroke-width="1.7" stroke-linecap="round">
                    <path d="M4 7h16"/><path d="M4 12h16"/><path d="M4 17h16"/>
                </svg>
            </button>
            <div class="topbar-main">
                <span class="topbar-title">RunaWorld</span>
                <span class="topbar-user">Bienvenido, <strong>${username}</strong></span>
            </div>
            <button id="btn-menu-runas" class="mobile-topbar-btn mobile-topbar-btn-runas" aria-label="Estadísticas de runa" type="button">
                <svg viewBox="0 0 400 400" fill="none" stroke="#c080ff" stroke-width="18" stroke-linecap="round">
                    <circle cx="200" cy="200" r="150"/><circle cx="200" cy="200" r="48"/>
                    <line x1="200" y1="55" x2="200" y2="18"/><line x1="200" y1="345" x2="200" y2="382"/>
                    <line x1="55" y1="200" x2="18" y2="200"/><line x1="345" y1="200" x2="382" y2="200"/>
                    <circle cx="200" cy="200" r="16" fill="#c080ff" stroke="none"/>
                </svg>
            </button>
        </div>
        <div id="mobile-nav-drawer">
            <div class="drawer-titulo">Menú</div>
            <button class="mob-nav active" data-sec="tirada"><span>⬡</span> Tirar Runa</button>
            <button class="mob-nav" data-sec="tienda"><span>◈</span> Tienda</button>
            <button class="mob-nav" data-sec="coleccion"><span>◎</span> Colección</button>
            <button class="mob-nav" data-sec="estadisticas"><span>✦</span> Estadisticas</button>
            <button class="mob-nav" data-sec="ajustes"><span>⚙</span> Ajustes</button>
            <div class="mob-spacer"></div>
            <div class="mob-line"></div>
            <button class="mob-nav danger" onclick="window.location='PHP/logout.php'"><span>→</span> Cerrar Sesión</button>
        </div>
        <div id="mobile-runas-drawer">
            <div class="drawer-titulo">Runas y estadísticas</div>
            <div id="mobile-runas-scroll"></div>
        </div>
        <div id="mobile-stats-bar">
            <div class="mob-stat">
                <svg width="16" height="16" viewBox="0 0 40 40" fill="none"><circle cx="20" cy="20" r="17" stroke="#ffd700" stroke-width="1.5" opacity="0.5"/><text x="20" y="25" text-anchor="middle" fill="#ffd700" font-size="11" font-family="Oswald">C</text></svg>
                <span class="mob-val" id="ms-coins">0</span>
                <span class="mob-rate" id="ms-coins-ps">+0/s</span>
            </div>
            <div class="mob-sep"></div>
            <div class="mob-stat">
                <svg width="16" height="16" viewBox="0 0 40 40" fill="none"><polygon points="20,3 24,15 37,15 26,23 30,35 20,27 10,35 14,23 3,15 16,15" stroke="#dde4f0" stroke-width="1.5" fill="none" opacity="0.6"/></svg>
                <span class="mob-val silver" id="ms-points">0</span>
                <span class="mob-rate" id="ms-points-ps">+0/s</span>
            </div>
            <div class="mob-sep"></div>
            <div class="mob-stat">
                <svg width="16" height="16" viewBox="0 0 40 40" fill="none"><polygon points="20,4 22,15 33,15 24,22 27,33 20,26 13,33 16,22 7,15 18,15" stroke="#ffd700" stroke-width="1.2" fill="none" opacity="0.7"/></svg>
                <span class="mob-val" id="ms-suerte">x1.00</span>
                <span class="mob-rate">suerte</span>
            </div>
            <div class="mob-sep"></div>
            <div class="mob-stat">
                <svg width="16" height="16" viewBox="0 0 40 40" fill="none"><rect x="6" y="14" width="8" height="16" stroke="#dde4f0" stroke-width="1.2" opacity="0.5" rx="1"/><rect x="16" y="10" width="8" height="20" stroke="#dde4f0" stroke-width="1.2" opacity="0.7" rx="1"/><rect x="26" y="6" width="8" height="24" stroke="#ffd700" stroke-width="1.2" opacity="0.9" rx="1"/></svg>
                <span class="mob-val silver" id="ms-bulk">1</span>
                <span class="mob-rate">por tirada</span>
            </div>
        </div>
    `);

    // clonar al drawer móvil tanto las estadísticas de la runa seleccionada
    // como la lista completa de Mis Runas. Así el móvil mantiene la misma
    // separación que PC: Runas Básicas / Runas Corruptas.
    const panelStatsColeccion = document.getElementById('panel-col-stats');
    const panelMisRunas = document.getElementById('panel-mis-runas');
    const scroll = document.getElementById('mobile-runas-scroll');
    if (scroll) {
        if (panelStatsColeccion) {
            const cloneStats = panelStatsColeccion.cloneNode(true);
            cloneStats.id = 'panel-col-stats-mobile';
            cloneStats.style.display = 'block';
            scroll.appendChild(cloneStats);
        }
        if (panelMisRunas) {
            const cloneRunas = panelMisRunas.cloneNode(true);
            cloneRunas.id = 'panel-mis-runas-mobile';
            cloneRunas.style.display = 'block';
            cloneRunas.querySelectorAll('[id]').forEach(function(el){ el.removeAttribute('id'); });
            scroll.appendChild(cloneRunas);
        }
    }

    window.rwSyncMobileCollectionStats = function () {
        const origStats = document.getElementById("panel-col-stats");
        const mobStats  = document.getElementById("panel-col-stats-mobile");
        const mobRunas  = document.getElementById("panel-mis-runas-mobile");
        const enColeccion = !!document.querySelector("#seccion-coleccion.activa");

        // En Colección, el botón morado enseña SOLO las estadísticas
        // de la runa seleccionada. El inventario móvil queda para
        // Tirar Runa, Ajustes y Estadísticas.
        if (mobRunas) mobRunas.style.display = enColeccion ? "none" : "block";

        if (!origStats || !mobStats) return;
        mobStats.style.display = enColeccion ? "block" : "none";
        if (!enColeccion) return;

        mobStats.innerHTML = origStats.innerHTML;
    };

    // sync de stats: leo los valores del dom desktop (coins-display,
    // points-display, etc) y los copio al dom movil. polling cada 800ms.
    // si algun dia me aburro lo cambio por un pub/sub en ui.js pero por
    // ahora 800ms es imperceptible y me ahorra refactorizar todo
    function syncStats() {
        const g = id => document.getElementById(id)?.textContent || '';
        document.getElementById('ms-coins').textContent     = g('coins-display');
        const suerteTxt = g("luck-display") || g("suerte-display") || (
            typeof window.luck_multiplier !== "undefined"
                ? "x" + (parseFloat(window.luck_multiplier) || 1).toFixed(2)
                : "x1.00"
        );
        const bulkTxt = g("bulk-display") || (typeof window.bulk_runas !== "undefined" ? String(window.bulk_runas) : "1");
        document.getElementById("ms-suerte").textContent = suerteTxt;
        document.getElementById("ms-bulk").textContent   = bulkTxt;
        document.getElementById('ms-points-ps').textContent = g('points-ps-display');

        if (typeof window.rwSyncMobileCollectionStats === 'function') {
            window.rwSyncMobileCollectionStats();
        }
    }
    // polling estable cada 800ms + un sync extra a los 300ms para cubrir el
    // caso de que el panel movil tarde en renderizarse en el primer frame
    setInterval(syncStats, 800);
    setTimeout(syncStats, 300);

    // drawers: abrir/cerrar con el overlay que oscurece el fondo
    function abrirDrawer(id) {
        // siempre cierro todo antes de abrir. asi si hay otro drawer abierto
        // se cambia limpio en vez de apilarse
        cerrarDrawers(true);
        document.getElementById(id)?.classList.add('open');
        document.getElementById('mobile-overlay').classList.add('visible');
    }

    // cerrarDrawers se expone en window porque lo llaman desde los handlers
    // inline de los botones (atencion: parametro silent no se usa ahora mismo,
    // lo deje por si algun dia quiero cerrar sin animacion)
    window.cerrarDrawers = function(silent) {
        document.getElementById('mobile-nav-drawer')?.classList.remove('open');
        document.getElementById('mobile-runas-drawer')?.classList.remove('open');
        document.getElementById('mobile-overlay')?.classList.remove('visible');
    };

    // boton del engranaje (nav) y boton de la runa morada (runas). cada uno
    // hace toggle: si esta abierto lo cierra, si esta cerrado lo abre
    document.getElementById('btn-menu-nav').addEventListener('click', () => {
        const d = document.getElementById('mobile-nav-drawer');
        if (d.classList.contains('open')) cerrarDrawers(); else abrirDrawer('mobile-nav-drawer');
    });
    document.getElementById('btn-menu-runas').addEventListener('click', () => {
        const d = document.getElementById('mobile-runas-drawer');
        if (d.classList.contains('open')) cerrarDrawers(); else abrirDrawer('mobile-runas-drawer');
    });
    // click en el overlay = cerrar drawer. estandar en todas las apps moviles
    document.getElementById('mobile-overlay').addEventListener('click', cerrarDrawers);

    // navegacion entre secciones: reutilizo mostrarSeccion() de ui.js que es
    // la misma que usa el desktop. asi no duplico la logica de mostrar/ocultar
    // secciones, solo cambio el boton "active" en el drawer movil y cierro
    document.querySelectorAll('#mobile-nav-drawer .mob-nav[data-sec]').forEach(btn => {
        btn.addEventListener('click', () => {
            mostrarSeccion(btn.dataset.sec, null);
            document.querySelectorAll('#mobile-nav-drawer .mob-nav').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            cerrarDrawers();
        });
    });

    // swipe desde los bordes para abrir drawers (gesto clasico en apps)
    //   borde izq  -> abre nav drawer
    //   borde der  -> abre runas drawer
    // detecto touchstart en los primeros/ultimos 25px para no disparar con
    // scrolls normales por el medio. el swipe tiene que ser de al menos 50px
    // tambien, asi no se abre por error con un tap largo
    let tx0 = 0;
    document.addEventListener('touchstart', e => { tx0 = e.touches[0].clientX; }, { passive: true });
    document.addEventListener('touchend', e => {
        const dx = e.changedTouches[0].clientX - tx0;
        if (Math.abs(dx) < 50) return;
        // swipe hacia la derecha desde borde izq -> abrir menu
        if (dx > 50  && tx0 < 25) abrirDrawer('mobile-nav-drawer');
        // swipe hacia la izq desde borde der -> abrir runas
        if (dx < -50 && tx0 > window.innerWidth - 25) abrirDrawer('mobile-runas-drawer');
    }, { passive: true });

})();


// ideas futuras / TODO:
//   - swipe vertical desde abajo para abrir la stats bar ampliada con mas info
//   - haptic feedback (navigator.vibrate) al abrir drawer y al tirar runa
//   - modo landscape: ahora mismo funciona pero los drawers quedan chiquitos,
//     podria aprovechar mejor el espacio horizontal
//   - cambiar el polling de 800ms por un custom event desde ui.js cuando
//     algo cambie. cuando tenga tiempo
//   - detectar rotacion / resize a desktop y volver al layout original sin
//     necesidad de recargar la pagina
