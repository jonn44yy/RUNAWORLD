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
//   1. early return si estamos en desktop (>768px), no pinta absolutamente nada
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
(function () {
    // si el viewport es mayor de 768px es desktop, no inyecto nada. esto hace
    // que este archivo sea practicamente free en desktop: carga y se va
    if (window.innerWidth > 768) return;

    // saco el nombre del usuario del header del desktop para ponerlo en la
    // topbar movil. si no existe dejo string vacio (no fallo)
    const username = document.querySelector('#header .bienvenido strong')?.textContent || '';

    // inyeccion del html movil al final del body. va todo en un solo
    // insertAdjacentHTML porque es mas barato que crear los nodos uno a uno
    // con createElement, y mas legible que encadenar appendChild
    document.body.insertAdjacentHTML('beforeend', `
        <div id="mobile-overlay"></div>
        <div id="mobile-topbar">
            <span class="topbar-title">RunaWorld</span>
            <span class="topbar-user">Bienvenido, <strong>${username}</strong></span>
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
            <div class="drawer-titulo">Mis Runas <span id="m-runas-cnt" style="color:rgba(160,80,255,0.7);"></span></div>
            <div id="mobile-runas-scroll"></div>
        </div>
        <button id="btn-menu-nav" aria-label="Menú">
            <svg viewBox="0 0 24 24" fill="none" stroke="#ffd700" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="3"/>
                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
            </svg>
        </button>
        <button id="btn-menu-runas" aria-label="Mis Runas">
            <svg viewBox="0 0 400 400" fill="none" stroke="#c080ff" stroke-width="14" stroke-linecap="round">
                <circle cx="200" cy="200" r="170"/><circle cx="200" cy="200" r="65"/>
                <line x1="200" y1="125" x2="200" y2="30"/><line x1="200" y1="275" x2="200" y2="370"/>
                <line x1="275" y1="200" x2="370" y2="200"/><line x1="125" y1="200" x2="30" y2="200"/>
                <line x1="245" y1="155" x2="305" y2="95"/><line x1="155" y1="155" x2="95" y2="95"/>
                <line x1="245" y1="245" x2="305" y2="305"/><line x1="155" y1="245" x2="95" y2="305"/>
                <line x1="200" y1="135" x2="200" y2="265"/><line x1="135" y1="200" x2="265" y2="200"/>
                <circle cx="200" cy="200" r="18" fill="#c080ff" stroke="none" opacity="0.9"/>
            </svg>
        </button>
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

    // clonar el panel de "mis runas" del desktop al drawer movil. ojo: CLONAR,
    // no mover. si lo moviera, al agrandar la ventana y volver a desktop, el
    // panel seguiria metido en el drawer movil (ya oculto) y el sidebar
    // desktop se quedaria vacio. el clon lleva un id distinto para no chocar
    // con el original
    const panelRunas = document.getElementById('panel-mis-runas');
    const scroll = document.getElementById('mobile-runas-scroll');
    if (panelRunas && scroll) {
        const clone = panelRunas.cloneNode(true);
        clone.id = 'panel-mis-runas-mobile';
        clone.style.display = 'block';
        scroll.appendChild(clone);
    }

    // sync de stats: leo los valores del dom desktop (coins-display,
    // points-display, etc) y los copio al dom movil. polling cada 800ms.
    // si algun dia me aburro lo cambio por un pub/sub en ui.js pero por
    // ahora 800ms es imperceptible y me ahorra refactorizar todo
    function syncStats() {
        const g = id => document.getElementById(id)?.textContent || '';
        document.getElementById('ms-coins').textContent     = g('coins-display');
        document.getElementById('ms-coins-ps').textContent  = g('coins-ps-display');
        document.getElementById('ms-points').textContent    = g('points-display');
        document.getElementById('ms-points-ps').textContent = g('points-ps-display');
        document.getElementById('ms-suerte').textContent    = g('suerte-display');
        document.getElementById('ms-bulk').textContent      = g('bulk-display');

        // contador de "mis runas" (X/Y desbloqueadas)
        const cnt = document.getElementById('panel-runas-count');
        const m   = document.getElementById('m-runas-cnt');
        if (cnt && m) m.textContent = cnt.textContent;

        // sync de las cantidades "xN" de cada runa desde el panel original al
        // clonado. itero los [data-id] del original y busco el mismo data-id
        // en el clon. si el jugador tira una runa en medio, este sync la
        // refleja en menos de un segundo
        const orig = document.getElementById('panel-mis-runas');
        const mob  = document.getElementById('panel-mis-runas-mobile');
        if (orig && mob) {
            orig.querySelectorAll('[data-id]').forEach(el => {
                const id = el.dataset.id;
                const mobEl = mob.querySelector(`[data-id="${id}"]`);
                if (mobEl) {
                    const cantOrig = el.querySelector('.runa-card-cantidad');
                    const cantMob  = mobEl.querySelector('.runa-card-cantidad');
                    if (cantOrig && cantMob) cantMob.textContent = cantOrig.textContent;
                }
            });
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