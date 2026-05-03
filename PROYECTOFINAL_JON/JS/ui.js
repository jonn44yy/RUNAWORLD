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
window.RW_UI_VERSION = '8.0';
const _i = window.RW_INIT || {};
let coins         = parseFloat(_i.coins)        || 0;
let points        = parseFloat(_i.points)       || 0;
let coins_ps      = parseFloat(_i.coins_ps)     || 1;
let points_ps     = parseFloat(_i.points_ps)    || 0;
let coins_ps_max  = parseFloat(_i.coins_ps_max) || coins_ps;
let points_ps_max = parseFloat(_i.points_ps_max)|| points_ps;
let bulk_runas    = parseInt(_i.bulk_total)     || 1;

// suerte total = suerte de tienda x bonus de colección.
// tienda: 1.0 -> 1.5 por los 5 niveles de +0.1x
// colección básica completa: x1.5 adicional, por eso 1.5 pasa a 2.25
const RW_COLLECTION_LUCK_BONUS = 1.5;
const _luckInitTotal = parseFloat(_i.luck_multiplier) || 1.0;
let completed_collections = parseInt(_i.completed_collections) || 0;
let luck_collection_multiplier = Math.max(1.0, parseFloat(_i.luck_collection_multiplier) || 1.0);

// compatibilidad: si el PHP todavía no manda luck_collection_multiplier pero
// sí marca basic_collection_complete, activamos el bonus en cliente al cargar.
if (completed_collections > 0 || _i.basic_collection_complete === true || _i.basic_collection_complete === 1 || _i.basic_collection_complete === '1') {
    completed_collections = Math.max(1, completed_collections);
    luck_collection_multiplier = Math.max(luck_collection_multiplier, RW_COLLECTION_LUCK_BONUS);
}

// compatibilidad: si solo llega luck_multiplier=2.25, inferimos tienda=1.5
// y colección=1.5 para que cualquier recalculo posterior no lo baje a 1.5.
if (luck_collection_multiplier <= 1.0 && _luckInitTotal > 1.5) {
    completed_collections = Math.max(1, completed_collections);
    luck_collection_multiplier = RW_COLLECTION_LUCK_BONUS;
}

let luck_shop_multiplier = Math.max(1.0, Math.min(1.5,
    parseFloat(_i.luck_shop_multiplier) || (_luckInitTotal / luck_collection_multiplier) || 1.0
));
let luck_multiplier = Math.max(1.0, luck_shop_multiplier * luck_collection_multiplier, _luckInitTotal);

function recalcularLuckTotal() {
    luck_shop_multiplier = Math.max(1.0, Math.min(1.5, parseFloat(luck_shop_multiplier) || 1.0));
    luck_collection_multiplier = Math.max(1.0, parseFloat(luck_collection_multiplier) || 1.0);
    luck_multiplier = Math.max(1.0, luck_shop_multiplier * luck_collection_multiplier);
    window.luck_shop_multiplier = luck_shop_multiplier;
    window.luck_collection_multiplier = luck_collection_multiplier;
    window.completed_collections = completed_collections;
    window.luck_multiplier = luck_multiplier;
    return luck_multiplier;
}
recalcularLuckTotal();

// detecta la colección básica completa usando los botones ya pintados.
// esto arregla el caso donde la última runa llega por tirada y el PHP/pack
// todavía no ha devuelto el nuevo multiplicador.
function RW_recalcularSuerteColecciones(forzarCompleta) {
    var completa = !!forzarCompleta;

    if (!completa) {
        var btns = Array.prototype.slice.call(document.querySelectorAll('.coleccion-columna-lista [data-collection="basicas"]'));
        if (btns.length > 0) {
            completa = btns.every(function (el) {
                var cant = parseInt(el.dataset.cantidad || '0', 10) || 0;
                return cant > 0 || el.classList.contains('desbloqueada') || !el.classList.contains('bloqueada');
            });
        }
    }

    if (completa) {
        completed_collections = Math.max(1, parseInt(completed_collections, 10) || 0);
        luck_collection_multiplier = Math.max(luck_collection_multiplier, RW_COLLECTION_LUCK_BONUS);
    }

    recalcularLuckTotal();
    actualizarPantalla();
    refrescarProbsAbiertas();
    if (typeof refrescarEstadisticas === 'function') refrescarEstadisticas();
    return luck_multiplier;
}
window.RW_recalcularSuerteColecciones = RW_recalcularSuerteColecciones;
window.RW_marcarColeccionBasicaCompleta = function () {
    return RW_recalcularSuerteColecciones(true);
};

// 27/04 v3: variables suerte y display_mode eliminadas (sistema retirado)

// sanity check paranoico: si alguna se cuela en NaN, el formateo de despues
// pinta "NaN coins" en pantalla, feisimo. esto es barato y me ahorra un bug
// dificil de diagnosticar
if (isNaN(coins))     coins     = 0;
if (isNaN(points))    points    = 0;
if (isNaN(coins_ps))  coins_ps  = 1;
if (isNaN(points_ps)) points_ps = 0;

const probMap         = _i.probMap      || {};
const BOOST_TIPOS     = (_i.boost_tipos || []).filter(function (bt) {
    var rareza = String(bt.rareza || "").toLowerCase();
    var desbloqueadas = Array.isArray(_i.mejoras_desbloqueadas) ? _i.mejoras_desbloqueadas.map(Number) : [];
    if (rareza === "legendario") return desbloqueadas.indexOf(14) !== -1;
    if (rareza === "divino") return desbloqueadas.indexOf(15) !== -1;
    return true;
});
const BOOST_INTERVALO = _i.boost_intervalo || 30000;
let boostsActivos     = [];
// coins_ps_base y points_ps_base: valor sin boosts. se rellenan cuando se
// activa el primer boost y sirven para quitarlo sin perder el base
let coins_ps_base     = null;
let points_ps_base    = points_ps > 0 ? points_ps : null;


// 27/04 v3: bloque entero de suerte (formula a+b*c*d, _calcSuerteBase,
// suerte_shop_add, suerte_grupo, suerte_base_val) eliminado.
// las probabilidades son fijas, no dependen de suerte.


// multiplicadores de mejoras que sobreviven a las tiradas. los guardo aqui
// para no tener que recalcularlos cada vez. tirada.js los lee directamente
// para reaplicar la formula de points_ps tras cada tirada
let _mejora_coins_ps     = parseFloat(window.RW_INIT?.mejora_coins_ps)     || 1.0;
let _mejora_multi_pts    = parseFloat(window.RW_INIT?.mejora_multi_pts)    || 1.0;
let _mejora_points_add   = parseFloat(window.RW_INIT?.mejora_points_add)   || 0.0;
let _runas_points_ps     = parseFloat(window.RW_INIT?.runas_points_ps)     || 0.0;
const probRareza = _i.prob_rareza || {};


// 27/04 v3: funciones calcularPesoCampana y recalcularSuertePanel eliminadas.
// las probabilidades por rareza son fijas (vienen de rarezas.denominador en BD)
// y se pintan una sola vez al cargar, no se recalculan en vivo


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
//
// 27/04 v3: casos de suerte eliminados, las mejoras de suerte ya no existen.
// 28/04 v3.1: points_seg cambiada de geometrica a lineal para evitar la
// explosion de numeros (nivel 5 daba 50.000 pts/seg, nivel 10 daba 5.000M).
// formulas que quedan:
//   coins_seg:           triangular: (n*(n+1)/2) * v
//   coins_seg_multi[_eterno]:   *= 2^n
//   points_seg:          lineal: v * n  (28/04: antes era v * 10^(n-1))
//   points_seg_multi[_eterno]:  *= 2^n
//   bulk / bulk_normal:  + n (cada nivel suma 1 runa)
//   bulk_extra:          + v si n>=1
function recalcularStatsDesdeMejoras(mejoras) {
    let coins_add    = 0.0;
    let multi_coins  = 1.0;
    let points_add   = 0.0;
    let multi_points = 1.0;
    let bulk_add     = 0;

    mejoras.forEach(m => {
        const v = parseFloat(m.valor);
        const n = parseInt(m.nivel) || parseInt(m.cantidad) || 0;
        if (n <= 0) return;
        switch (m.tipo) {
            // coins lineal triangular: nivel 1=+1, nivel 2=+3 total, nivel 3=+6 total...
            case "coins_seg":
                coins_add += (n * (n + 1) / 2) * v;
                break;

            // multiplicadores x2 por nivel (eterno y normal mismo comportamiento)
            case "coins_seg_multi":
            case "coins_seg_multi_eterno":
                multi_coins *= Math.pow(Math.max(1, v), n);
                break;

            // points lineal: cada nivel suma `valor` puntos/seg
            // 28/04: cambiado de geometrica (10^n-1) a lineal para que el
            // generador no explote a millardos en nivel 10
            case "points_seg":
                points_add += v * n;
                break;

            case "points_seg_multi":
            case "points_seg_multi_eterno":
                multi_points *= Math.pow(Math.max(1, v), n);
                break;
            case "bulk":
            case "bulk_normal":
                bulk_add += Math.round(v * n);
                break;
            case "bulk_extra":
                if (n >= 1) bulk_add += Math.round(v);
                break;

            // los desbloquear_boost_* no afectan stats, solo desbloquean
            // tipos de boost en boosts.js (que mira RW_INIT.mejoras_desbloqueadas)
        }
    });

    // coins_ps = (1 + suma_additive) * multi_combinado. la base de 1 es el
    // coin/seg que tiene el jugador sin mejoras
    const coinsBase       = 1.0 + coins_add;
    const nuevaCoinsPs    = coinsBase * multi_coins;

    coins_ps_base = nuevaCoinsPs;
    coins_ps      = nuevaCoinsPs;

    // points tiene otra logica: base = runas del jugador (viene del server
    // y se guarda en points_ps_base). encima sumo el additive y multiplico
    // por el multi combinado. si points_ps_base es null es que el jugador
    // aun no tiene runas que den puntos, dejo points_ps como estaba
    _mejora_points_add = points_add;
    _mejora_multi_pts  = multi_points;
    const runasBaseLimpia = parseFloat(_runas_points_ps) || 0;
    const nuevaPointsPs   = (runasBaseLimpia + points_add) * multi_points;
    points_ps_base = nuevaPointsPs;
    points_ps      = nuevaPointsPs;

    bulk_runas = 1 + bulk_add;
    const bulkEl = document.getElementById("bulk-display");
    if (bulkEl) bulkEl.textContent = bulk_runas + " runa" + (bulk_runas > 1 ? "s" : "");

    // 27/04 v3: bloque de actualizacion de suerte eliminado.
    // reaplicar boosts (ya solo de coins/points) sobre los nuevos valores
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

    // algunos HTML antiguos usan luck-display y otros suerte-display.
    // actualizo ambos para que desktop/móvil no se queden desincronizados.
    var luckTxt = "x" + (parseFloat(luck_multiplier) || 1).toFixed(2);
    var luckEl = document.getElementById("luck-display");
    if (luckEl) luckEl.textContent = luckTxt;
    var suerteEl = document.getElementById("suerte-display");
    if (suerteEl) suerteEl.textContent = luckTxt;

    var bulkEl = document.getElementById("bulk-display");
    if (bulkEl) bulkEl.textContent = bulk_runas + " runa" + (bulk_runas > 1 ? "s" : "");
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


// 27/04 v3: actualizarSuerte() eliminada, ya no aplica.
// si tirada.js o algun otro archivo la llama, hacemos un stub vacio para
// no romper la cadena. solo respeta el bulk si llega
function actualizarSuerte(_nuevaSuerteGrupo, nuevoBulk, _bonusConseguidos) {
    if (nuevoBulk !== undefined) {
        bulk_runas = nuevoBulk;
        const el = document.getElementById("bulk-display");
        if (el) el.textContent = bulk_runas + " runa" + (bulk_runas > 1 ? "s" : "");
    }
}


// 27/04 v3: formato de probabilidad como fraccion (1/100k, 1/25, 1/1...).
// recibe un porcentaje (ej: 0.001 para 0.001%) y devuelve la fraccion legible.
// la convencion estandar de gachas: si la prob es muy alta (>=99%) mostramos
// "1/1" como referencia visual, no es literal pero es lo que la gente espera ver.
function formatProb(pct) {
    if (pct === undefined || pct === null || isNaN(pct)) return "—";
    if (pct <= 0)   return "—";
    if (pct >= 99)  return "1/1";
    const denom = 100 / pct;
    if (denom >= 1000000) return "1/" + Math.round(denom / 1000000) + "M";
    if (denom >= 1000)    return "1/" + Math.round(denom / 1000) + "k";
    return "1/" + Math.round(denom);
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
        const p = parseFloat(card.dataset.prob || 0);
        const baseEl = card.querySelector(".prob-base-val");
        const luckEl = card.querySelector(".prob-luck-val");
        if (baseEl) baseEl.textContent = formatProb(p);
        if (luckEl) luckEl.textContent = formatProb(Math.min(100, p * (parseFloat(luck_multiplier) || 1)));

        card.classList.add("expandida");
        prob.style.maxHeight = prob.scrollHeight + "px";
        card.querySelector(".runa-card-flecha").textContent = "▴";
    }
}

// refrescar los valores pintados de las cartas abiertas (por si la prob
// cambia dinamicamente en algun caso futuro). por ahora las probs son fijas
// pero dejamos la funcion para no romper llamadas externas
function refrescarProbsAbiertas() {
    document.querySelectorAll(".runa-card-btn.expandida").forEach(card => {
        const p = parseFloat(card.dataset.prob || 0);
        const baseEl = card.querySelector(".prob-base-val");
        const luckEl = card.querySelector(".prob-luck-val");
        if (baseEl) baseEl.textContent = formatProb(p);
        if (luckEl) luckEl.textContent = formatProb(Math.min(100, p * (parseFloat(luck_multiplier) || 1)));
    });
}


// 27/04 v3: cambiarDisplayMode() eliminada. ya no hay modo "porcentaje vs peso",
// las probabilidades siempre se muestran como fraccion (1/100k, 1/1...).


// helpers de probabilidad para el panel de stats en coleccion (lado derecho
// cuando seleccionas una runa). probMap ahora tiene solo {prob: X.XX}
function getProbStr(runaId) {
    const p = probMap[runaId];
    if (!p) return "—";
    return formatProb(p.prob);
}

// alias por compatibilidad: ya no hay diferencia entre "base" y "con suerte",
// ambas dan la misma probabilidad fija
function getProbBaseStr(runaId) {
    return getProbStr(runaId);
}

// Probabilidad real con la suerte total actual.
// probMap[runaId].prob viene en porcentaje; luck_multiplier ya incluye tienda × colecciones.
function getProbLuckStr(runaId) {
    const p = probMap[runaId];
    if (!p) return "—";
    const luck = Math.max(1, parseFloat(luck_multiplier) || 1);
    return formatProb(Math.min(100, (parseFloat(p.prob) || 0) * luck));
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
    document.body.classList.remove("rw-sec-tirada", "rw-sec-tienda", "rw-sec-coleccion", "rw-sec-estadisticas", "rw-sec-ajustes");
    document.body.classList.add("rw-sec-" + id);
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
        };
    }

    setTimeout(() => {
        const todas = document.querySelectorAll('.runa-card-btn');

        let hayCorruptaVisible = false;

        todas.forEach(card => {
            const cantidad = parseInt(card.dataset.cantidad || "0");

            const esCorrupta =
                card.textContent.toLowerCase().includes("corrupta");

            if (esCorrupta && cantidad <= 0) {
                card.style.display = "none";
            }

            if (esCorrupta && cantidad > 0) {
                hayCorruptaVisible = true;
            }
        });

        const titulos = document.querySelectorAll('.grupo-nombre');

        titulos.forEach(titulo => {
            if (titulo.textContent.toLowerCase().includes("corruptas")) {
                titulo.style.display = hayCorruptaVisible ? "" : "none";
            }
        });

    }, 100);
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


// 28/04 v3.1: setters para que tienda.js, tirada.js etc puedan actualizar
// las variables INTERNAS de ui.js (no solo window.coins, que era el bug que
// hacia que tras comprar una mejora el sidebar siguiera mostrando el saldo
// viejo). estos setters tocan tanto la variable de scope (coins, points)
// como window por compatibilidad con codigo viejo
function setLuck(v) {
    const n = parseFloat(v);
    if (!isNaN(n)) {
        // si la colección ya está marcada como completa, no permitimos que
        // una respuesta vieja del pack baje la suerte de 2.25 a 1.50.
        luck_multiplier = Math.max(1.0, n, luck_shop_multiplier * luck_collection_multiplier);
        window.luck_multiplier = luck_multiplier;
        actualizarPantalla();
        refrescarProbsAbiertas();
    }
}
function setLuckDetalle(detalle) {
    if (!detalle || typeof detalle !== "object") return;
    if (detalle.tienda !== undefined) {
        luck_shop_multiplier = Math.max(1.0, Math.min(1.5, parseFloat(detalle.tienda) || luck_shop_multiplier));
    }
    if (detalle.colecciones !== undefined) {
        luck_collection_multiplier = Math.max(1.0, parseFloat(detalle.colecciones) || luck_collection_multiplier);
    }
    if (detalle.colecciones_completadas !== undefined) {
        completed_collections = parseInt(detalle.colecciones_completadas) || completed_collections;
        if (completed_collections > 0) luck_collection_multiplier = Math.max(luck_collection_multiplier, RW_COLLECTION_LUCK_BONUS);
    }
    if (detalle.basic_collection_complete === true || detalle.basic_collection_complete === 1 || detalle.basic_collection_complete === '1') {
        completed_collections = Math.max(1, completed_collections);
        luck_collection_multiplier = Math.max(luck_collection_multiplier, RW_COLLECTION_LUCK_BONUS);
    }

    const total = (detalle.total !== undefined) ? parseFloat(detalle.total) : recalcularLuckTotal();
    if (!isNaN(total)) setLuck(total);
}

function setPointsPs(v) {
    const n = parseFloat(v);
    if (!isNaN(n)) {
        points_ps_base = n;
        points_ps      = n;
        window.points_ps = n;
        actualizarPantalla();
    }
}
function setCoinsPs(v) {
    const n = parseFloat(v);
    if (!isNaN(n)) {
        coins_ps_base = n;
        coins_ps      = n;
        window.coins_ps = n;
        actualizarPantalla();
    }
}

function setCoins(v) {
    const n = parseFloat(v);
    if (!isNaN(n)) {
        coins        = n;
        window.coins = n;
        actualizarPantalla();
    }
}
function setPoints(v) {
    const n = parseFloat(v);
    if (!isNaN(n)) {
        points        = n;
        window.points = n;
        actualizarPantalla();
    }
}
window.setCoins    = setCoins;
window.setPoints   = setPoints;
window.setLuck     = setLuck;
window.setLuckDetalle = setLuckDetalle;
window.setPointsPs = setPointsPs;
window.setCoinsPs  = setCoinsPs;
window.recalcularStatsDesdeMejoras = recalcularStatsDesdeMejoras;