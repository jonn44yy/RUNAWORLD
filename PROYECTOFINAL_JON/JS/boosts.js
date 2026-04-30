// boosts.js — runaworld
// sistema de boosts flotantes, las runas moradas (y de otros colores) que
// aparecen flotando cada X segundos en la pantalla y si las cazas te dan un
// multiplicador temporal: mas coins/seg, mas points/seg, o mas suerte.
//
// indice por orden de aparicion:
//   1. sorteo + mostrar la runa flotante que se puede clickar
//   2. clickar la runa: anade el boost a boostsActivos
//   3. aplicarBoosts: la funcion sagrada, calcula coins_ps, points_ps y suerte
//                     aplicando la formula (a+b)*c*d
//   4. renderizar los boosts activos (iconos abajo)
//   5. tick cada segundo para caducar boosts y actualizar timers
//   6. notificacion del boost cuando lo clickas (icono + color por rareza)
//   7. pausa durante animaciones especiales (eterna/divina/mitica/legendaria)
//   8. pausa cuando el jugador esta en ajustes
//   9. arranque: iniciarSistemaBoosts()
//
// lenguaje interno para los poco entendidos (va por vosotros profesores):
//   boostsActivos  = array global de boosts activos ahora mismo, cada uno con
//                    su multi y fin_ms (cuando caducan)
//   runaFlotante   = la runa morada que flota en pantalla. el jugador la caza
//                    para activar el boost. viene en 5 rarezas: normal, raro,
//                    epico, legendario, divino
//   BOOST_TIPOS    = lista que viene del server, define que boosts existen
//                    (su nombre, multiplicador, duracion, rareza, peso)
//   rareza del boost = distinta de la rareza de las runas del juego. son 5:
//                      normal/raro/epico/legendario/divino
//   _animacionActiva = flag global que dice si una animacion especial esta
//                      corriendo (eterna/divina/mitica/legendaria). si esta
//                      activa, los boosts no aparecen ni se muestran
//   _enAjustes     = flag que dice si el jugador esta en el menu de ajustes.
//                    si esta activo, los timers se congelan (el jugador puede
//                    ir a ajustes con calma sin perder su boost)
//
// empece boosts.js el 15 de marzo cuando se me ocurrio que idle clickers
// chulos como antimatter dimensions tienen cosas que hacer click, no solo
// esperar. hoy 20/04 sigo retocandolo. !hi a quien lea esto


// colores por rareza. los reparto en 3 familias aunque hay 5 rarezas, asi
// el jugador nota una progresion clara:
//   normal   = morado base (#a050ff) — el que ya estaba
//   raro     = morado brillante (#c070ff) — lavanda saturada
//   epico    = rosa-morado (#e090ff) — puente hacia el azul
//   legendario = cian neon (#00eaff) — grita "esto es raro"
//   divino   = azul neon brillante (#80f0ff) — blanco-azulado celestial
const BOOST_COLORES = {
    normal:     "#a050ff",
    raro:       "#c070ff",
    epico:      "#e090ff",
    legendario: "#00eaff",
    divino:     "#80f0ff"
};

// iconos por rareza. uso caracteres rúnicos y símbolos unicode que se ven
// bien con la fuente cinzel. son sencillos pero se distingue bien de un golpe
const BOOST_ICONOS = {
    normal:     "ᛉ",  // runa basica
    raro:       "ᛝ",  // runa doble, suena mas "raro"
    epico:      "ᛟ",  // runa ornamental, con swag
    legendario: "⟡",  // diamante hueco
    divino:     "✦"   // estrella de 4 puntas
};

// helper para elegir rareza del boost. si el server no envia rareza (por si
// algun boost antiguo se quedo sin actualizar), caigo en "normal" por defecto
function obtenerRarezaBoost(boost) {
    return boost.rareza || "normal";
}


// helper: cuanto sube el peso de un boost segun su rareza si la mejora
// de desbloqueo correspondiente esta comprada. ANTES (v1) los boosts
// legendario/divino tenian requiere_mejora_id y solo aparecian si el
// jugador compraba la mejora — ahora aparecen SIEMPRE pero la mejora
// de desbloqueo SUBE su probabilidad. la idea: en mid-game ya ves runas
// raras flotando, comprar la mejora las hace mucho mas frecuentes.
//
// los multiplicadores van por rareza, no por boost individual: si compras
// "Catalizador Legendario" (mejora id=14 en BD v2), TODOS los boosts de
// rareza "legendario" multiplican peso x5. y "Catalizador Divino" (id=15)
// multiplica peso x4 a los divinos. ambos efectos son aditivos
function _multiplicadorPesoBoost(rareza) {
    // window.RW_INIT.mejoras_desbloqueadas trae los ids de mejoras con nivel >= 1.
    // los ids 14 (catalizador legendario) y 15 (catalizador divino) son los
    // que controlan el escalado. si esos ids cambian en BD, cambiar aqui
    const desbloqueadas = (window.RW_INIT && window.RW_INIT.mejoras_desbloqueadas) || [];
    const ID_CAT_LEGENDARIO = 14;
    const ID_CAT_DIVINO     = 15;

    if (rareza === "legendario" && desbloqueadas.includes(ID_CAT_LEGENDARIO)) {
        return 5;   // 1% base -> ~5% efectivo
    }
    if (rareza === "divino" && desbloqueadas.includes(ID_CAT_DIVINO)) {
        return 4;   // 0.5% base -> ~2% efectivo
    }
    return 1;
}


// sortear que boost toca esta vez, ponderado por el peso de cada boost.
// los boosts raros (divino, legendario) tienen peso bajo, los comunes alto.
// mecanica clasica de ruleta: suma de pesos, random, y a ver donde cae.
// v2 (24/04): aplicamos _multiplicadorPesoBoost para escalar legendarios/
// divinos si el jugador compro la mejora de desbloqueo correspondiente.
// los boosts siempre aparecen, lo que cambia es la probabilidad
function sortearBoost() {
    if (!BOOST_TIPOS.length) return null;

    // calculo pesos efectivos primero (multiplicador segun rareza + mejora)
    const pesosEfectivos = BOOST_TIPOS.map(b => {
        const rareza = obtenerRarezaBoost(b);
        return b.peso * _multiplicadorPesoBoost(rareza);
    });
    const pesoTotal = pesosEfectivos.reduce((a, b) => a + b, 0);
    if (pesoTotal <= 0) return BOOST_TIPOS[0];

    let tirada = Math.random() * pesoTotal;
    let acum   = 0;
    for (let i = 0; i < BOOST_TIPOS.length; i++) {
        acum += pesosEfectivos[i];
        if (tirada <= acum) return BOOST_TIPOS[i];
    }
    return BOOST_TIPOS[0];
}


let runaFlotanteTimer  = null;
let runaFlotanteActual = null;
let runaFlotanteAnim   = null;

// mostrar la runa flotante en una posicion random de la pantalla. anima en
// dos fases: 0-5s aparece y crece hasta pico de brillo, 5-10s se desvanece.
// si el jugador la clica antes, se activa el boost. si no, desaparece.
// si hay animacion especial corriendo o estoy en ajustes, no la muestro
function mostrarRunaFlotante() {
    // 20/04: si hay animacion de eterna/divina/mitica/legendaria corriendo,
    // apunto la intencion para que salga cuando termine. asi no se me
    // pisan la animacion de la runa especial con la del boost
    if (typeof _animacionActiva !== "undefined" && _animacionActiva) {
        _boostPendiente = true;
        return;
    }
    // si el jugador esta en ajustes, tampoco. al salir de ajustes se reanuda
    if (_enAjustes) {
        _boostPendiente = true;
        return;
    }

    const boost = sortearBoost();
    if (!boost) return;
    runaFlotanteActual = boost;

    const el     = document.getElementById("runa-flotante");
    const svgEl  = document.getElementById("runa-flotante-svg");
    const lblEl  = document.getElementById("runa-flotante-label");
    if (!el) return;

    // elegir color segun la rareza del boost. aplico el color al svg de la
    // runa flotante (para el drop-shadow) y al texto del label (que ya no se
    // usa pero lo dejo por si acaso)
    const rareza = obtenerRarezaBoost(boost);
    const color  = BOOST_COLORES[rareza] || BOOST_COLORES.normal;
    if (svgEl) svgEl.style.color = color;
    if (lblEl) {
        // al pedir quitar el texto de las notifs, tambien le quito el label de
        // la runa flotante. me quedo solo con el mandala dorado + el glow
        lblEl.textContent = "";
    }

    // posicion aleatoria dentro de la pantalla, con margen de 120px para que
    // no salga pegada al borde y sea dificil de cazar
    const margen = 120;
    const x = margen + Math.random() * (window.innerWidth  - margen * 2);
    const y = margen + Math.random() * (window.innerHeight - margen * 2);
    el.style.left    = x + "px";
    el.style.top     = y + "px";
    el.style.display = "block";

    // animacion de 11s en tres fases:
    //   0-3s  = aparecer y crecer hasta su tamano pico
    //   3-6s  = quieta en tamano pico, lo que el jugador aprovecha para cazarla
    //   6-11s = decrecer y desvanecerse
    let t0 = null;
    const DURACION_MS   = 11000;
    const T_CRECER_MS   = 3000;
    const T_PICO_MS     = 6000;   // final de la fase pico (3s crecer + 3s pico)

    function animarFlotante(ts) {
        if (!t0) t0 = ts;
        const elapsed = ts - t0;
        let escala, opacidad, brillo;
        if (elapsed < T_CRECER_MS) {
            // fase crecer
            const t = elapsed / T_CRECER_MS;
            escala   = 0.4 + t * 0.8;
            opacidad = 0.3 + t * 0.7;
            brillo   = t * 20;
        } else if (elapsed < T_PICO_MS) {
            // fase pico: 3s quieta en el maximo para que el jugador la cace
            escala   = 1.2;
            opacidad = 1.0;
            brillo   = 20;
        } else {
            // fase decrecer
            const t = (elapsed - T_PICO_MS) / (DURACION_MS - T_PICO_MS);
            escala   = 1.2 - t * 1.2;
            opacidad = 1.0 - t;
            brillo   = 20 * (1 - t);
        }
        el.style.transform = `translate(-50%, -50%) scale(${escala})`;
        el.style.opacity   = opacidad;
        if (svgEl) svgEl.style.filter = `drop-shadow(0 0 ${brillo}px ${color})`;

        if (elapsed >= DURACION_MS) {
            el.style.display = "none";
            runaFlotanteActual = null;
            return;
        }
        runaFlotanteAnim = requestAnimationFrame(animarFlotante);
    }

    if (runaFlotanteAnim) cancelAnimationFrame(runaFlotanteAnim);
    runaFlotanteAnim = requestAnimationFrame(animarFlotante);
    clearTimeout(runaFlotanteTimer);
    runaFlotanteTimer = setTimeout(() => {
        el.style.display = "none";
        runaFlotanteActual = null;
    }, DURACION_MS + 500);
}


// el jugador clicko la runa flotante. activar el boost, sumarlo a
// boostsActivos, y mostrar la notificacion de icono abajo
function clickarRunaFlotante() {
    if (!runaFlotanteActual) return;
    const boost = runaFlotanteActual;

    // parar la animacion de la runa flotante
    if (runaFlotanteAnim) cancelAnimationFrame(runaFlotanteAnim);
    clearTimeout(runaFlotanteTimer);
    document.getElementById("runa-flotante").style.display = "none";
    runaFlotanteActual = null;

    // calcular cuanto dura el boost
    const ahora       = Date.now();
    const duracion_ms = boost.duracion_seg * 1000;
    const fin_ms      = ahora + duracion_ms;
    const multi       = parseFloat(boost.multiplicador);

    // si ya hay un boost del mismo tipo, sumo tiempo en vez de reemplazar
    // (mecanica que me gusta de idle games: stackear duracion con repeticiones)
    const idx = boostsActivos.findIndex(b => b.tipo === boost.tipo);
    if (idx !== -1) {
        boostsActivos[idx].fin_ms += duracion_ms;
    } else {
        boostsActivos.push({
            id:     boost.id,
            nombre: boost.nombre,
            tipo:   boost.tipo,
            rareza: obtenerRarezaBoost(boost),
            multi,
            fin_ms
        });
    }

    aplicarBoosts();
    renderizarBoosts();
    mostrarNotifBoost(boost);
}


// 27/04 v3: obtenerSuerteReal() eliminada, sistema de suerte retirado.


// LA FUNCION MAS IMPORTANTE DEL ARCHIVO
// recalcula coins_ps y points_ps aplicando los multiplicadores de los boosts
// activos. la parte de suerte fue retirada el 27/04 v3.
function aplicarBoosts() {
    const ahora = Date.now();

    // filtrar boosts caducados. los de usos_tirada (se consumen al tirar) no
    // caducan por tiempo, viven hasta que se gastan
    boostsActivos = boostsActivos.filter(b => {
        if (b.usos > 0) return true;
        return b.fin_ms > ahora;
    });

    // inicializar bases de produccion solo si son null Y tienen valor valido.
    // esto es para la primera vez que entro, luego ya estan cacheadas
    if (coins_ps_base  === null && typeof coins_ps  !== "undefined" && coins_ps  > 0)  coins_ps_base  = coins_ps;
    if (points_ps_base === null && typeof points_ps !== "undefined" && points_ps >= 0) points_ps_base = points_ps;

    // acumular multiplicadores de los boosts activos por tipo
    let mc = 1;  // coins boost (multiplicador de coins/seg)
    let mp = 1;  // points boost (multiplicador de points/seg)

    boostsActivos.forEach(b => {
        const multi = parseFloat(b.multi) || 1;
        if (b.tipo === "coins_seg")  mc *= multi;
        if (b.tipo === "points_seg") mp *= multi;
        // 27/04 v3: boosts de suerte eliminados (ya no existen en boost_tipos)
    });

    if (coins_ps_base  !== null) coins_ps  = coins_ps_base  * mc;
    if (points_ps_base !== null) points_ps = points_ps_base * mp;

    if (typeof actualizarPantalla === "function") actualizarPantalla();
}


// renderizar los boosts activos abajo de la pantalla. antes era un contenedor
// con texto, ahora son iconos luminosos con el color de su rareza
function renderizarBoosts() {
    const contenedor = document.getElementById("boosts-activos");
    if (contenedor) contenedor.innerHTML = "";
    // las notificaciones de boost salen por mostrarNotifBoost(), aqui solo
    // limpio el contenedor viejo por si queda algo de la version anterior
}


// tick cada segundo: caducar boosts + actualizar timers visibles.
// si hay animacion especial o estoy en ajustes, en vez de dejar que el tiempo
// corra, sumo 1000ms a los fin_ms de cada boost activo (lo que equivale a
// "pausar" su cronometro). mismo truco con _spawnRestanteMs (cuando toca la
// proxima runa flotante): si no descuento, es como si no hubiera pasado tiempo

// contador regresivo para la proxima runa flotante. arranca en 5000ms para
// que al cargar la pagina tenga el jugador 5s de gracia antes del primer spawn
let _spawnRestanteMs = 5000;

setInterval(() => {
    // hay animacion especial o esta en ajustes? pausamos TODO el sistema
    if (_animacionActiva || _enAjustes) {
        // congelar timer de boosts activos: les sumo 1s para compensar
        boostsActivos.forEach(b => { b.fin_ms += 1000; });
        // el timer del spawn de la proxima runa tambien se queda quieto
        // (no descuento de _spawnRestanteMs)
        return;
    }

    const ahora    = Date.now();
    const antesLen = boostsActivos.length;
    boostsActivos  = boostsActivos.filter(b => b.fin_ms > ahora);

    // actualizar los timers visibles de las notificaciones de boost activo.
    // recalculo los segundos que quedan desde fin_ms, mas fiable que llevar
    // un contador aparte que puede desincronizarse con la pausa
    document.querySelectorAll("[data-boost-notif]").forEach(el => {
        const finMs = parseInt(el.dataset.finMs);
        const segsRestantes = Math.ceil((finMs - ahora) / 1000);
        const timerEl = el.querySelector(".boost-notif-timer-mini");
        if (timerEl) timerEl.textContent = (segsRestantes > 0 ? segsRestantes : 0) + "s";
        // si ya caduco, fade out y remove
        if (segsRestantes <= 0 && !el.dataset.finalizando) {
            el.dataset.finalizando = "1";
            el.style.opacity = "0";
            setTimeout(() => {
                el.remove();
                if (window.innerWidth > 768) _reposicionarNotifs();
            }, 400);
        }
    });

    // descontar tiempo del timer de spawn de la proxima runa flotante
    _spawnRestanteMs -= 1000;
    if (_spawnRestanteMs <= 0) {
        mostrarRunaFlotante();
        _spawnRestanteMs = BOOST_INTERVALO;
    }

    // si caducaron algunos boosts, recalcular stats
    if (boostsActivos.length !== antesLen) {
        aplicarBoosts();
        renderizarBoosts();
    }
}, 1000);


// mostrar notificacion de boost activo. abajo (desktop) o en el drawer (movil).
// formato: "suerte x5 24s" — palabra del tipo + multiplicador + timer.
// el color del borde/texto identifica la rareza.
// OJO: NO lleva setInterval propio, el tick global de arriba actualiza el
// timer leyendo data-fin-ms. asi pausa correctamente con el resto del sistema
function mostrarNotifBoost(boost) {
    // fallback por si llega un boost medio roto (caso raro pero paso una vez)
    if (typeof boost === "string") {
        boost = { nombre: boost, tipo: "coins_seg", multiplicador: "?", duracion_seg: 60, rareza: "normal" };
    }

    const rareza   = obtenerRarezaBoost(boost);
    const color    = BOOST_COLORES[rareza] || BOOST_COLORES.normal;
    const duracion = parseInt(boost.duracion_seg) || 60;
    const multi    = parseFloat(boost.multiplicador) || 1;

    // etiqueta del tipo de boost. en vez de emoji pongo la palabra, que es
    // mas claro de un vistazo: puntos o moneda
    const tipoLabel = {
        "points_seg": "puntos",
        "coins_seg":  "moneda"
    }[boost.tipo] || boost.tipo;

    // si habia una notif del mismo tipo, la quito para no duplicar
    document.querySelectorAll(`[data-tipo="${boost.tipo}"][data-boost-notif]`).forEach(el => el.remove());

    // calcular fin_ms buscando el boost en boostsActivos (ya lo puso clickarRunaFlotante)
    const boostActivo = boostsActivos.find(b => b.tipo === boost.tipo);
    const finMs       = boostActivo ? boostActivo.fin_ms : Date.now() + duracion * 1000;

    const notif = document.createElement("div");
    notif.dataset.tipo       = boost.tipo;
    notif.dataset.rareza     = rareza;
    notif.dataset.boostNotif = "1";   // marker para el tick global
    notif.dataset.finMs      = finMs;

    // estilos inline (autonomos, no dependen de CSS externo)
    Object.assign(notif.style, {
        position: "fixed",
        bottom: "0",
        left: "50%",
        transform: "translateX(-50%)",
        background: "rgba(8, 9, 13, 0.92)",
        border: `1px solid ${color}`,
        color: color,
        fontFamily: "'Oswald', sans-serif",
        fontSize: "0.82rem",
        letterSpacing: "2px",
        textTransform: "uppercase",
        padding: "8px 16px",
        borderRadius: "20px",
        zIndex: "9800",
        pointerEvents: "none",
        transition: "opacity 0.4s, bottom 0.3s, visibility 0s",
        whiteSpace: "nowrap",
        textAlign: "center",
        boxShadow: `0 0 12px ${color}44, 0 0 24px ${color}22`,
        display: "flex",
        alignItems: "center",
        gap: "10px"
    });

    // contenido: tipo + multiplicador + timer
    // el span del timer tiene la clase para que el tick global lo encuentre
    notif.innerHTML = `
        <span>${tipoLabel}</span>
        <span style="opacity:0.9;">x${multi}</span>
        <span class="boost-notif-timer-mini" style="font-size:0.75rem; opacity:0.85;">${duracion}s</span>
    `;

    // en movil va dentro del drawer, en desktop en el body
    const esMobil = window.innerWidth <= 768;
    if (esMobil) {
        Object.assign(notif.style, {
            position: "relative",
            bottom: "auto",
            left: "auto",
            transform: "none",
            margin: "4px 8px"
        });
        const contenedor = document.getElementById("mobile-runas-drawer") || document.body;
        contenedor.appendChild(notif);
    } else {
        document.body.appendChild(notif);
        _reposicionarNotifs();
    }

    // si en el momento de crear la notif ya hay una animacion corriendo,
    // la oculto directamente (porque iniciarAnimacion ya habia pasado)
    if (_animacionActiva) {
        notif.style.visibility = "hidden";
    }
}


// llamar esto desde tirada.js cuando el server devuelve nuevos ps
function actualizarBasesBoost(nuevoCoinsPs, nuevoPointsPs) {
    coins_ps_base  = nuevoCoinsPs  ?? coins_ps_base;
    points_ps_base = nuevoPointsPs ?? points_ps_base;
    // suerte_base_val la recompone aplicarBoosts() desde sus fuentes
    aplicarBoosts();
}


// flags globales. las dos controlan si los boosts estan activos visualmente.
// _animacionActiva lo activa animaciones.js cuando arranca una especial
// _enAjustes lo activa ui.js al entrar al menu de ajustes
let _animacionActiva = false;
let _boostPendiente  = false;
let _enAjustes       = false;


// animaciones.js llama a esto al empezar una animacion especial.
// si hay runa flotante, la borro. si hay notifs activas, las oculto
// temporalmente (no las borro, solo las escondo, luego vuelven al terminar)
function iniciarAnimacion() {
    _animacionActiva = true;

    // si hay runa flotante, la quito y recordar para mostrarla luego
    const el = document.getElementById("runa-flotante");
    if (el && el.style.display !== "none") {
        el.style.display = "none";
        _boostPendiente  = true;
        if (runaFlotanteAnim) cancelAnimationFrame(runaFlotanteAnim);
        clearTimeout(runaFlotanteTimer);
        runaFlotanteActual = null;
    }

    // ocultar todas las notificaciones de boost activas usando el marker
    // data-boost-notif. no las borro porque su timer sigue "congelado" en
    // el tick global. al terminar la animacion se vuelven a ver
    document.querySelectorAll("[data-boost-notif]").forEach(n => {
        n.style.visibility = "hidden";
    });
}

// animaciones.js llama a esto al terminar una animacion especial
function terminarAnimacion() {
    _animacionActiva = false;

    // volver a mostrar las notifs que estaban escondidas
    document.querySelectorAll("[data-boost-notif]").forEach(n => {
        n.style.visibility = "visible";
    });

    // si se habia quedado un boost pendiente por mostrar, sacarlo ahora
    if (_boostPendiente) {
        _boostPendiente = false;
        setTimeout(mostrarRunaFlotante, 500);
    }
}

// ui.js llama a esto al entrar/salir del menu de ajustes.
// cuando esta activo, el timer de los boosts se congela y no aparecen
// nuevas runas flotantes. al salir, todo sigue donde estaba
function setEnAjustes(estaEnAjustes) {
    _enAjustes = !!estaEnAjustes;

    if (_enAjustes) {
        // si entra a ajustes y hay runa flotante en pantalla, la escondo y
        // apunto la intencion de mostrarla al salir
        const el = document.getElementById("runa-flotante");
        if (el && el.style.display !== "none") {
            el.style.display = "none";
            _boostPendiente  = true;
            if (runaFlotanteAnim) cancelAnimationFrame(runaFlotanteAnim);
            clearTimeout(runaFlotanteTimer);
            runaFlotanteActual = null;
        }
    } else {
        // al salir de ajustes, si habia un boost pendiente, lo muestro
        if (_boostPendiente && !_animacionActiva) {
            _boostPendiente = false;
            setTimeout(mostrarRunaFlotante, 500);
        }
    }
}


// reposicionar las notifs de boost activas apiladas en vertical (solo desktop)
function _reposicionarNotifs() {
    const fixedNotifs = [];
    document.querySelectorAll("[data-tipo]").forEach(n => {
        if (n.style.position === "fixed") fixedNotifs.push(n);
    });
    let offset = 12;
    fixedNotifs.forEach(n => {
        n.style.bottom = offset + "px";
        offset += (n.offsetHeight || 36) + 6;
    });
}


// arrancar el sistema de boosts. antes usaba setInterval pero ese no se puede
// pausar, asi que ahora el tick global de arriba es el que hace de reloj
// usando _spawnRestanteMs como contador regresivo. mucho mas flexible
function iniciarSistemaBoosts() {
    // al arrancar, el contador ya esta en 5000ms (ver declaracion arriba).
    // no hace falta hacer nada mas aqui, el tick global lo maneja todo.
    // dejo la funcion para que iniciar/terminar tenga simetria por si el dia
    // de manana hay que parar el sistema entero (boton en debug, algo asi)
}


// funcion de debug para forzar una runa de rareza concreta, la uso para
// probar las animaciones sin tener que esperar a que salga una eterna por rng
// TODO: quitarla antes de entregar? o dejarla solo en debug mode
window.forzarRuna = function(rareza) {
    const runaFalsa = {
        nombre: rareza.charAt(0).toUpperCase() + rareza.slice(1),
        rareza,
        multiplicador: 99
    };
    mostrarResultado(runaFalsa);
};


// arrancar todo al cargar la pagina
iniciarSistemaBoosts();

// sincronizar el display de suerte al cargar. hubo un bug donde al recargar
// aparecia x1.00 aunque la suerte era mucho mayor, esto lo arregla
if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => aplicarBoosts());
} else {
    aplicarBoosts();
}


// ideas futuras / TODO de este archivo:
//   - animacion de "click perfecto" al cazar la runa (tipo flash corto)
//   - que la runa flotante tenga un sonidito al aparecer (desactivable)
//   - runas que se mueven lentamente por la pantalla en vez de quedarse quietas
//   - boosts combinables: si tienes 3 del mismo tipo activos, un mega boost
//   - que los boosts afecten tambien al bulk (mas runas por tirada temporalmente)
//   - notificacion de "runa flotante aparecida" mas sutil para que no pase
//     desapercibida al jugador cuando esta en la tienda o similar
