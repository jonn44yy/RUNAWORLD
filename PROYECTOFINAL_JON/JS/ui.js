// ui.js — runaworld
// archivo centralizador del cliente: variables globales del juego, helpers
// de formateo, loop de stats por segundo, toggles de paneles, menus y
// utilidades de suerte/campana. es el "estado" del juego en js; el resto
// de archivos (tirada.js, boosts.js, coleccion.js, etc) leen y escriben
// directamente estas variables en lugar de tener su propio estado
//
// indice:
//   1. variables globales leidas de window.RW_INIT
//   2. formula de suerte (a+b)*c*d y calculador de base
//   3. curva campana (port de calcular_pesos.php a js)
//   4. formateo de numeros (formatNum: 1500000 pasa a "1.5M")
//   5. recalcularStatsDesdeMejoras -- aplica mejoras sin pegarle al server
//   6. loop de pantalla: tick cada segundo que suma coins/points
//   7. actualizarSuerte -- callback tras tirar (server devuelve nueva d)
//   8. panel runas: toggle de cada carta y formato de probabilidad
//   9. cambiar display mode (porcentaje/peso) persistente a la bd
//   10. mostrarSeccion -- cambio de menu + efectos colaterales
//   11. refrescarEstadisticas -- lee las globales y las pinta en el menu stats
//   12. override de mostrarCardEn al cargar (quita el "has conseguido...")
//
// lenguaje interno para los poco entendidos:
//   RW_INIT         = objeto window.RW_INIT inyectado inline en el html por
//                     juego.php. trae todo lo inicial: coins, points, suerte,
//                     probMap, curvas_data, etc. ver cabecera de juego.php
//                     para la lista completa
//   formula suerte  = (a + b) * c * d.
//                       a = 1 (constante, base del jugador)
//                       b = mejoras de suerte de la tienda
//                       c = multiplicador de boosts activos
//                       d = bonus de colecciones completadas
//                     aqui guardo b y d como variables. c lo calcula y
//                     aplica boosts.js en vivo
//   curva campana   = por cada rareza hay 3 puntos: peso_base (suerte=1),
//                     peso_pico (suerte optima) y 0 (suerte_cero, donde
//                     desaparece). la curva sube de base a pico y luego
//                     baja del pico a cero. el resultado: con poca suerte
//                     caen comunes, con mucha suerte caen raras... pero con
//                     diminishing returns (mas alla del pico empieza a bajar)
//   display_mode    = "porcentaje" o "peso". el jugador elige si ver las
//                     probabilidades como "2.5%" o como "Peso: 14". se
//                     guarda en la bd por usuario
//   base vs final   = coins_ps_base = coins por seg sin boosts. coins_ps =
//                     con boosts ya aplicados. mismo para points. sirve para
//                     que boosts.js pueda quitar/poner boosts sin perder el
//                     valor base
//
// corazon del cliente. no se toca a la ligera, si cambias algo aqui se
// nota en todo el juego. !hi


// variables globales leidas de RW_INIT (inyectado por juego.php al final
// del body). uso _i como alias para no repetir window.RW_INIT en cada linea.
// si RW_INIT no existe (no deberia pasar nunca en prod), defaults a {} y
// todos los parseFloat devuelven NaN --> los defaults posteriores entran
const _i = window.RW_INIT || {};
let coins         = parseFloat(_i.coins)        || 0;
let points        = parseFloat(_i.points)       || 0;
let coins_ps      = parseFloat(_i.coins_ps)     || 1;
let points_ps     = parseFloat(_i.points_ps)    || 0;
let coins_ps_max  = parseFloat(_i.coins_ps_max) || coins_ps;
let points_ps_max = parseFloat(_i.points_ps_max)|| points_ps;
let suerte        = parseFloat(_i.suerte)       || 1;
let bulk_runas    = parseInt(_i.bulk_total)     || 1;
let display_mode  = _i.display_mode             || "porcentaje";

// sanity check paranoico: si alguna se cuela en NaN, el formateo de despues
// pinta "NaN coins" en pantalla, feisimo. esto es barato y me ahorra un bug
// dificil de diagnosticar
if (isNaN(coins))     coins     = 0;
if (isNaN(points))    points    = 0;
if (isNaN(coins_ps))  coins_ps  = 1;
if (isNaN(points_ps)) points_ps = 0;
if (isNaN(suerte))    suerte    = 1;

const probMap         = _i.probMap      || {};
const BOOST_TIPOS     = _i.boost_tipos  || [];
const BOOST_INTERVALO = _i.boost_intervalo || 30000;
let boostsActivos     = [];
// coins_ps_base y points_ps_base: valor sin boosts. se rellenan cuando se
// activa el primer boost y sirven para quitarlo sin perder el base
let coins_ps_base     = null;
let points_ps_base    = points_ps > 0 ? points_ps : null;


// suerte: formula (a + b) * c * d. ver cabecera de juego.php para detalles
let suerte_shop_add = parseFloat(_i.suerte_shop_add) || 0.0;   // b = mejoras tienda
let suerte_grupo    = parseFloat(_i.suerte_grupo)    || 1.0;   // d = bonus grupo

// calcular suerte base (sin boosts, solo a+b multiplicado por d). boosts.js
// la multiplica por c en aplicarBoosts() para obtener la suerte efectiva.
// separarlas permite "anadir boost" y "quitar boost" sin recalcular desde cero
function _calcSuerteBase() {
    return (1.0 + suerte_shop_add) * suerte_grupo;
}
let suerte_base_val = _calcSuerteBase();


// datos crudos de las curvas campana indexados por rareza. se usan en
// recalcularSuertePanel() para actualizar los % del panel sin pedirle al
// server cada vez que la suerte baila (que puede ser varias veces por minuto
// si hay boosts activandose/caducandose)
const CURVAS_DATA = _i.curvas_data || {};


// multiplicadores de mejoras que sobreviven a las tiradas. los guardo aqui
// para no tener que recalcularlos cada vez. tirada.js los lee directamente
// para reaplicar la formula de points_ps tras cada tirada
let _mejora_coins_ps     = parseFloat(window.RW_INIT?.mejora_coins_ps)     || 1.0;
let _mejora_multi_pts    = parseFloat(window.RW_INIT?.mejora_multi_pts)    || 1.0;
let _mejora_points_add   = parseFloat(window.RW_INIT?.mejora_points_add)   || 0.0;
let _runas_points_ps     = parseFloat(window.RW_INIT?.runas_points_ps)     || 0.0;
let _mejora_suerte_multi = parseFloat(window.RW_INIT?.mejora_suerte_multi) || 1.0;
const probRareza = _i.prob_rareza || {};


// curva campana -- port js de calcular_pesos.php
// para cada rareza hay 3 puntos clave: peso_base (cuando suerte=1),
// peso_pico (cuando suerte=suerte_pico) y 0 (cuando suerte=suerte_cero).
// la curva es piecewise lineal: sube de base a pico, luego baja del pico
// a cero. lo de "campana" es porque con rarezas frecuentes como la comun,
// la curva sube y baja (la comun caeria mucho si tiro la suerte arriba,
// porque deja paso a las raras)
function calcularPesoCampana(suerteVal, curva) {
    const pesoBase   = parseFloat(curva.peso_base);
    const suertePico = parseFloat(curva.suerte_pico);
    const pesoPico   = parseFloat(curva.peso_pico);
    const suerteCero = parseFloat(curva.suerte_cero);

    if (suerteVal <= suertePico) {
        // zona ascendente: de base a pico. caso especial: si pico <= 1, no
        // hay ascenso posible (suerte minima es 1), devuelvo pico directamente
        if (suertePico <= 1.0) return pesoPico;
        let t = (suerteVal - 1.0) / (suertePico - 1.0);
        t = Math.max(0, Math.min(1, t));
        return pesoBase + (pesoPico - pesoBase) * t;
    } else {
        // zona descendente: de pico hasta cero
        let t = (suerteVal - suertePico) / (suerteCero - suertePico);
        t = Math.max(0, Math.min(1, t));
        return pesoPico * (1.0 - t);
    }
}


// recalcular los % del panel tras un cambio de suerte (p.ej. un boost se
// activo o caduco). esto evita un round-trip al server cada vez que la
// suerte cambia, y para un boost que afecta varias veces por minuto la
// diferencia de latencia es notable
function recalcularSuertePanel() {
    if (!CURVAS_DATA || Object.keys(CURVAS_DATA).length === 0) return;
    let pesoTotal = 0;
    const pesosPorRareza = {};
    for (const rareza in CURVAS_DATA) {
        const peso = calcularPesoCampana(suerte, CURVAS_DATA[rareza]);
        pesosPorRareza[rareza] = peso;
        pesoTotal += peso;
    }
    if (pesoTotal <= 0) return;
    // para cada carta meto los datos nuevos en dataset. formatProb los lee
    // cuando el jugador despliega la carta, asi no recalculo por cada abrir
    document.querySelectorAll(".runa-card-btn").forEach(card => {
        const rareza = card.dataset.rareza;
        if (!rareza || !pesosPorRareza.hasOwnProperty(rareza)) return;
        const nuevoPct = (pesosPorRareza[rareza] / pesoTotal) * 100;
        card.dataset.suerte = nuevoPct.toFixed(6);
        card.dataset.pesoEfectivo = pesosPorRareza[rareza].toFixed(4);
    });
    // si hay cartas abiertas ya, refresco sus numeros tambien para no
    // dejarlas con los % de la suerte anterior
    if (typeof refrescarProbsAbiertas === "function") refrescarProbsAbiertas();
}


// formateo de numeros: 1500 --> "1.5k", 2500000 --> "2.5M", etc. el replace
// final quita ".0" y ".00" para que "5000000" quede "5M" en lugar de "5.00M"
function formatNum(n) {
    if (n >= 1e12) return (n/1e12).toFixed(2).replace(/\.?0+$/,"") + "T";
    if (n >= 1e9)  return (n/1e9) .toFixed(2).replace(/\.?0+$/,"") + "B";
    if (n >= 1e6)  return (n/1e6) .toFixed(2).replace(/\.?0+$/,"") + "M";
    if (n >= 1e3)  return (n/1e3) .toFixed(2).replace(/\.?0+$/,"") + "k";
    return Math.floor(n).toString();
}

// al cargar formateo los costes pintados desde php. vienen con el numero
// crudo en data-valor, ejemplo: <span class="coste-num" data-valor="5000">
document.querySelectorAll(".coste-num").forEach(el => {
    el.textContent = formatNum(parseFloat(el.dataset.valor));
});


// recalcular stats desde mejoras (sin tocar el server). se llama desde
// tienda.js tras comprar una mejora. el server ya ha guardado el cambio,
// pero para que el ui refleje el nuevo valor YA (sin esperar recarga),
// aqui rehago la misma matematica que juego.php pero en js
function recalcularStatsDesdeMejoras(mejoras) {
    let coins_add    = 0.0;
    let multi_coins  = 1.0;
    let points_add   = 0.0;
    let multi_points = 1.0;
    let suerte_add   = 0.0;
    let bulk_add     = 0;

    // recorro todas las mejoras y acumulo segun el tipo. el switch es el
    // mismo que el de juego.php ~linea 175, mantener ambos sincronizados
    // si se anaden tipos nuevos
    mejoras.forEach(m => {
        const v = parseFloat(m.valor);
        const n = parseInt(m.cantidad) || parseInt(m.nivel) || 1;
        switch (m.tipo) {
            case "coins_seg":        coins_add    += v * n; break;
            // multi: cada nivel anade +v al multiplicador (v=1.0 --> nivel1=x2, nivel2=x3...)
            case "coins_seg_multi":  multi_coins  *= (1 + v * n); break;
            case "points_seg":       points_add   += v * n; break;
            case "points_seg_multi": multi_points *= (1 + v * n); break;
            case "suerte":           suerte_add   += v * n; break;
            case "bulk":             bulk_add     += n; break;
        }
    });

    // coins_ps = (1 + suma_additive) * multi_combinado. la base de 1 es el
    // coin/seg que tiene el jugador sin mejoras
    const coinsBase       = 1.0 + coins_add;
    const nuevaCoinsPs    = coinsBase * multi_coins;
    const nuevaSuerteBase = 1.0 + suerte_add;

    coins_ps_base = nuevaCoinsPs;
    coins_ps      = nuevaCoinsPs;

    // points tiene otra logica: base = runas del jugador (viene del server
    // y se guarda en points_ps_base). encima sumo el additive y multiplico
    // por el multi combinado. si points_ps_base es null es que el jugador
    // aun no tiene runas que den puntos, dejo points_ps como estaba
    if (points_ps_base !== null) {
        const nuevaPointsPs = (points_ps_base + points_add) * multi_points;
        points_ps = nuevaPointsPs;
    }

    // bulk: runas por tirada. base 1, cada mejora suma niveles
    bulk_runas = 1 + bulk_add;
    const bulkEl = document.getElementById("bulk-display");
    if (bulkEl) bulkEl.textContent = bulk_runas + " runa" + (bulk_runas > 1 ? "s" : "");

    suerte_base_val = nuevaSuerteBase;

    // reaplicar boosts sobre los nuevos valores base. sin esto, tras comprar
    // una mejora se perderian los boosts activos hasta que caduquen
    if (typeof aplicarBoosts === "function") {
        aplicarBoosts();
    }
}


// pintar los numeros en los displays del header. se llama en cada tick
// del setInterval y tambien desde tirada.js tras cada tirada (para que el
// feedback sea inmediato y no haya que esperar al siguiente segundo)
function actualizarPantalla() {
    document.getElementById("coins-display").textContent     = formatNum(coins);
    document.getElementById("points-display").textContent    = formatNum(points);
    document.getElementById("coins-ps-display").textContent  = "+" + formatNum(coins_ps)   + "/seg";
    document.getElementById("points-ps-display").textContent = "+" + formatNum(points_ps)  + "/seg";
}

// tick cada segundo: sumo coins_ps y points_ps a las variables y repinto.
// el guardado a la bd lo hace tirada.js cada 30s, no en cada tick (si
// mandara un fetch por segundo al server se me caeria la infra por unos
// centimos por segundo)
setInterval(() => {
    coins  += coins_ps;
    points += points_ps;
    actualizarPantalla();
}, 1000);


// arranque del idle timer. linea suelta porque solo se llama una vez al
// cargar, el resto de calls vienen desde los handlers de input del usuario
resetIdleTimer();
// iniciarAnimacionJuego() y iniciarSistemaBoosts() se llaman desde otros
// archivos (intro.html y boosts.js al final), no hace falta lanzarlos aqui


// actualizar suerte tras tirar. lo llama tirada.js con la respuesta del
// server. nuevaSuerteGrupo es la "d" de la formula, los otros factores
// (b, c) no cambian con una tirada. tambien acepta nuevoBulk por si se
// ha desbloqueado una mejora durante la tirada (via un bonus de grupo)
function actualizarSuerte(nuevaSuerteGrupo, nuevoBulk, bonusConseguidos) {
    if (nuevaSuerteGrupo !== undefined && nuevaSuerteGrupo !== null) {
        const nueva = parseFloat(nuevaSuerteGrupo);
        if (!isNaN(nueva) && nueva > 0) {
            suerte_grupo    = nueva;
            suerte_base_val = _calcSuerteBase();
            if (typeof aplicarBoosts === "function") aplicarBoosts();
        }
    }
    if (nuevoBulk !== undefined) {
        bulk_runas = nuevoBulk;
        const el = document.getElementById("bulk-display");
        if (el) el.textContent = bulk_runas + " runa" + (bulk_runas > 1 ? "s" : "");
    }
}


// formato de probabilidad segun display_mode. el umbral 0.01% existe
// porque con valores muy pequenos (runas raras, poca suerte), dos decimales
// se ven como "0.00%" que no dice nada. por debajo paso a 4 decimales
function formatProb(val, peso) {
    if (display_mode === "peso") return "Peso: " + Math.round(peso);
    if (val >= 1)    return val.toFixed(2) + "%";
    if (val >= 0.01) return val.toFixed(3) + "%";
    return val.toFixed(4) + "%";
}

// abrir/cerrar el desplegable de probabilidad de una carta de runa. al
// abrir una cierro las demas (solo hay UNA abierta a la vez) para no
// saturar visualmente el panel derecho. el maxHeight calculado con
// scrollHeight hace que la transicion de css funcione con altura dinamica
function toggleRunaProb(card) {
    const prob = card.querySelector(".runa-card-prob");
    if (!prob) return;
    const abierto = card.classList.contains("expandida");

    // cierro todas (incluida esta si estaba abierta)
    document.querySelectorAll(".runa-card-btn").forEach(c => {
        c.classList.remove("expandida");
        c.querySelector(".runa-card-prob").style.maxHeight = "0";
        c.querySelector(".runa-card-flecha").textContent = "▾";
    });

    if (!abierto) {
        // relleno los valores justo antes de abrir, usando el display_mode
        // actual. asi si el jugador cambia de modo y reabre una carta ve
        // el valor nuevo sin tener que recargar
        const pb    = parseFloat(card.dataset.base   || 0);
        const ps    = parseFloat(card.dataset.suerte || 0);
        const peso  = parseFloat(card.dataset.peso   || 0);
        card.querySelector(".prob-base-val").textContent   = formatProb(pb, peso);
        card.querySelector(".prob-suerte-val").textContent = formatProb(ps, peso);

        card.classList.add("expandida");
        prob.style.maxHeight = prob.scrollHeight + "px";
        card.querySelector(".runa-card-flecha").textContent = "▴";
    }
}

// refrescar los valores pintados de las cartas abiertas. se usa cuando
// cambia display_mode o cuando la suerte baila por un boost mientras hay
// cartas abiertas (si no, se quedan con los numeros viejos hasta reabrirlas)
function refrescarProbsAbiertas() {
    document.querySelectorAll(".runa-card-btn.expandida").forEach(card => {
        const pb   = parseFloat(card.dataset.base   || 0);
        const ps   = parseFloat(card.dataset.suerte || 0);
        const peso = parseFloat(card.dataset.peso   || 0);
        card.querySelector(".prob-base-val").textContent   = formatProb(pb, peso);
        card.querySelector(".prob-suerte-val").textContent = formatProb(ps, peso);
    });
}


// cambio de display mode (porcentaje / peso) persistente a la bd. manda
// el modo nuevo al server via ajustes_action.php, y si devuelve ok lo
// aplica localmente: actualiza los botones activos + refresca cartas
// abiertas con el nuevo formato
function cambiarDisplayMode(modo) {
    fetch("PHP/ajustes_action.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ accion: "display_mode", valor: modo })
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            display_mode = modo;
            document.getElementById("btn-modo-pct").classList.toggle("active",  modo === "porcentaje");
            document.getElementById("btn-modo-peso").classList.toggle("active", modo === "peso");
            refrescarProbsAbiertas();
            // mensaje de confirmacion visible 2s, luego se limpia solo
            const msg = document.getElementById("msg-display-mode");
            msg.textContent = "Guardado.";
            setTimeout(() => msg.textContent = "", 2000);
        }
    });
}


// helpers de probabilidad para el panel de stats en coleccion (lado derecho
// cuando seleccionas una runa). getProbStr da la probabilidad con la
// suerte actual. getProbBaseStr da la probabilidad base (con suerte=1).
// son practicamente iguales, solo cambia que campo leen del probMap
function getProbStr(runaId) {
    const p = probMap[runaId];
    if (!p) return "—";
    if (display_mode === "peso") return "Peso: " + p.peso;
    const v = p.suerte;
    if (v >= 1)    return v.toFixed(2) + "%";
    if (v >= 0.01) return v.toFixed(3) + "%";
    return v.toFixed(4) + "%";
}

function getProbBaseStr(runaId) {
    const p = probMap[runaId];
    if (!p) return "—";
    if (display_mode === "peso") return "Peso base: " + p.peso;
    const v = p.base;
    if (v >= 1)    return v.toFixed(2) + "%";
    if (v >= 0.01) return v.toFixed(3) + "%";
    return v.toFixed(4) + "%";
}


// cambiar de seccion desde el menu. oculta todas, muestra la pedida y marca
// el boton activo. ademas gestiona varias cosas colaterales por seccion:
//   - coleccion --> arranca animaciones de los botones (neon + mini canvas)
//   - sale de coleccion --> las para para ahorrar cpu
//   - estadisticas --> refresca los valores en vivo con las globales actuales
//   - ajustes --> pausa los timers de boosts (para que no pierdas uno activo
//     mientras el jugador trastea con ajustes)
//   - en movil --> scroll del centro al top para que no quede a medio camino
function mostrarSeccion(id, btn) {
    document.querySelectorAll(".seccion").forEach(s => s.classList.remove("activa"));
    document.querySelectorAll(".nav-btn").forEach(b => b.classList.remove("active"));
    document.getElementById("seccion-" + id).classList.add("activa");
    if (btn) btn.classList.add("active");

    const esCole  = id === "coleccion";
    const esMobil = window.innerWidth <= 768;

    // solo en desktop: alternar entre el panel "mis runas" y el panel de
    // stats de coleccion. en movil los dos viven en drawers separados
    // (ver mobile.js) y no hace falta ocultar nada aqui
    if (!esMobil) {
        const pr = document.getElementById("panel-mis-runas");
        const ps = document.getElementById("panel-col-stats");
        if (pr) pr.style.display = esCole ? "none" : "";
        if (ps) ps.style.display = esCole ? ""     : "none";
    }

    // animaciones de coleccion: arranco si entramos, paro si salimos. el
    // setTimeout 60ms es para dar tiempo a que el dom pinte las cartas
    // antes de que los canvas empiecen a dibujar sobre ellas
    if (esCole) { setTimeout(() => { iniciarNeonBotones(); iniciarMiniCanvas(); }, 60); }
    else pararAnimColeccion();

    // si entra al menu de estadisticas, refresco los valores con las variables
    // actuales de js (coins/points/suerte etc). no hay nada que hacer con el
    // server, es pura lectura en vivo. lo quise asi: entra sale y se
    // actualiza, nada de estar mandando datos al server cada segundo
    if (id === "estadisticas" && typeof refrescarEstadisticas === "function") {
        refrescarEstadisticas();
    }

    // 20/04: pausar los boosts cuando esta en ajustes (timer congelado).
    // asi el jugador puede abrir ajustes con calma sin perder su boost activo
    if (typeof setEnAjustes === "function") {
        setEnAjustes(id === "ajustes");
    }

    // en movil: resetear el scroll del area central. si venias con scroll
    // hacia abajo en una seccion, al cambiar a otra empezabas tambien abajo,
    // muy raro visualmente
    if (esMobil) { const c = document.getElementById("centro"); if (c) c.scrollTop = 0; }
}


// refrescar valores dinamicos del menu estadisticas leyendo las globales
// que js ya mantiene al dia. llamar cada vez que entras al menu
function refrescarEstadisticas() {
    const setTxt = (id, val) => {
        const el = document.getElementById(id);
        if (el) el.textContent = val;
    };
    setTxt("stats-coins-actual", formatNum(coins));
    setTxt("stats-coins-ps",     "+" + formatNum(coins_ps) + "/s");
    setTxt("stats-points-actual", formatNum(points));
    setTxt("stats-points-ps",    "+" + formatNum(points_ps) + "/s");
    setTxt("stats-suerte",       "x" + (suerte || 1).toFixed(2));
    setTxt("stats-bulk",         (bulk_runas || 1) + " runa" + (bulk_runas > 1 ? "s" : ""));
}


// colRaf y colRunaActual viven en coleccion.js, aqui solo se mencionan
// porque varias funciones de arriba los usan sin declararlos


// override al cargar: quitar el mensaje "has conseguido tal runa".
// mostrarCardEn vive en animaciones.js y pintaba una cartita con nombre,
// rareza y multi de CADA runa que salia en una tirada. con bulk alto (5-10
// runas por tirada) eso es spam constante, asi que lo quito. las animaciones
// de rarezas especiales (eterna, divina, mitica) no pasan por mostrarCardEn
// para sus displays principales, usan sus propios canvas/overlays, asi que
// siguen intactas. legendaria si lo usa, pero pasa por toggles.js antes y
// acaba en _mostrarResultadoOriginal si el toggle esta ON.
//
// guardo la referencia original en window._mostrarCardEn_original por si
// en el futuro quiero volver atras sin recargar todo el proyecto
window.addEventListener("load", () => {
    if (typeof mostrarCardEn === "function") {
        window._mostrarCardEn_original = mostrarCardEn;
        window.mostrarCardEn = function(elementId, runa) {
            // no hacer nada: decision de producto, no bug
        };
    }
});


// ideas futuras / TODO:
//   - mover las globales a un objeto Game.* para no contaminar window.
//     implicaria tocar todos los .js que las leen, por eso no lo he hecho
//   - el override de mostrarCardEn es feo (monkey patch). mejor seria un
//     flag dentro de animaciones.js, pero funciona y no molesta a nadie
//   - cachear querySelectorAll repetidos en actualizarPantalla por si se
//     nota en movil low-end. ahora no se nota, pero con mucho bulk quizas
//   - refactorizar recalcularStatsDesdeMejoras para que comparta codigo con
//     juego.php (ahora es copy-paste manual, si cambio uno hay que acordarse
//     del otro). podria ir en un json endpoint que devuelva el objeto ya
//     calculado, pero es complicarlo por complicarlo