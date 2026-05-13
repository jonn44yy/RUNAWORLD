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

// ── RW V115 — fix visual panel runas/estadisticas ─────────────────────
// 1) Evita que el dropdown de probabilidades se corte por overflow/altura.
// 2) Permite crear el desglose de estadisticas aunque PHP no lo haya pintado.
(function RW_inyectarFixesVisualesV115() {
    if (document.getElementById('rw-fixes-v115')) return;
    var st = document.createElement('style');
    st.id = 'rw-fixes-v115';
    st.textContent = `
        #panel-mis-runas,
        #panel-mis-runas .grupo-runas,
        #panel-mis-runas .runa-card,
        #panel-mis-runas .runa-card-btn,
        #panel-mis-runas .runa-card-btn.expandida {
            overflow: visible !important;
        }

        #panel-mis-runas .runa-card-btn.expandida {
            height: auto !important;
            min-height: 52px !important;
            z-index: 20 !important;
        }

        #panel-mis-runas .runa-card-btn.expandida .runa-card-prob {
            display: block !important;
            visibility: visible !important;
            opacity: 1 !important;
            overflow: visible !important;
            max-height: 140px !important;
            padding-top: 8px !important;
            padding-bottom: 8px !important;
        }

        #stats-desglose-vivo .stats-fila {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }
    `;
    document.head.appendChild(st);
})();

const _i = window.RW_INIT || {};
let coins                   = parseFloat(_i.coins)                   || 0;
let points                  = parseFloat(_i.points)                  || 0;
let coins_ps                = parseFloat(_i.coins_ps)                || 1;
let points_ps               = parseFloat(_i.points_ps)               || 0;
let coins_ps_max            = parseFloat(_i.coins_ps_max)            || coins_ps;
let points_ps_max           = parseFloat(_i.points_ps_max)           || points_ps;
let collection_bulk_bonus = parseInt(_i.collection_bulk_bonus, 10) || 0;
let bulk_runas = (parseInt(_i.bulk_total, 10) || 1) + collection_bulk_bonus;
window.bulk_runas = bulk_runas;

if (
    collection_bulk_bonus <= 0 &&
    _i.collection_states &&
    _i.collection_states.basica_corrupta &&
    (
        _i.collection_states.basica_corrupta.completa === true ||
        _i.collection_states.basica_corrupta.completa === 1 ||
        _i.collection_states.basica_corrupta.completa === '1'
    )
) {
    collection_bulk_bonus = 2;
    bulk_runas += 2;
}

window.bulk_runas = bulk_runas;

// suerte total = suerte de tienda x bonus de colección.
// tienda: 1.0 -> 1.5 por los 5 niveles de +0.1x
// colección básica completa: x1.5 adicional, por eso 1.5 pasa a 2.25
const RW_COLLECTION_LUCK_BONUS = 1.5;
const _luckInitTotal = parseFloat(_i.luck_multiplier) || 1.0;
let completed_collections = parseInt(_i.completed_collections) || 0;
let luck_collection_multiplier = Math.max(1.0, parseFloat(_i.luck_collection_multiplier) || 1.0);
let rw_basic_collection_complete = (_i.basic_collection_complete === true || _i.basic_collection_complete === 1 || _i.basic_collection_complete === '1');

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

function RW_actualizarBonusColeccionVisual() {
    var estados = (window.RW_INIT && window.RW_INIT.collection_states) ? window.RW_INIT.collection_states : {};
    var basicaNormalCompleta = rw_basic_collection_complete === true || !!(estados.basica_normal && estados.basica_normal.completa);
    var basicaCorruptaCompleta = !!(estados.basica_corrupta && estados.basica_corrupta.completa);

    document.querySelectorAll('.coleccion-bonus-suerte-v74').forEach(function (box) {
        var tipo = box.dataset.bonusColeccion || 'basica_normal';
        var activo = tipo === 'basica_corrupta' ? basicaCorruptaCompleta : basicaNormalCompleta;
        box.classList.toggle('activo', activo);
        box.classList.toggle('bloqueado', !activo);

        var label = box.querySelector('.coleccion-bonus-label');
        var sub = box.querySelector('.coleccion-bonus-sub');
        if (tipo === 'basica_corrupta') {
            if (label) label.textContent = activo ? 'Basica corrupta reclamada' : 'Basica corrupta bloqueada';
            if (sub) sub.textContent = activo ? 'x2 suerte y +2 bulk activos' : '';
        } else {
            if (label) label.textContent = activo ? 'Basica normal reclamada' : 'Basica normal bloqueada';
            if (sub) sub.textContent = activo ? 'x1.5 suerte activo' : '';
        }
    });
}window.RW_actualizarBonusColeccionVisual = RW_actualizarBonusColeccionVisual;
RW_actualizarBonusColeccionVisual();

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
        rw_basic_collection_complete = true;
        if (window.RW_INIT) {
            window.RW_INIT.basic_collection_complete = true;
            window.RW_INIT.collection_states = window.RW_INIT.collection_states || {};
            window.RW_INIT.collection_states.basica_normal = window.RW_INIT.collection_states.basica_normal || {};
            window.RW_INIT.collection_states.basica_normal.completa = true;
        }
        completed_collections = Math.max(1, parseInt(completed_collections, 10) || 0);
        luck_collection_multiplier = Math.max(luck_collection_multiplier, RW_COLLECTION_LUCK_BONUS);
    }

    recalcularLuckTotal();
    RW_actualizarBonusColeccionVisual();
    actualizarPantalla();
    refrescarProbsAbiertas();
    if (typeof refrescarEstadisticas === 'function') refrescarEstadisticas();
    if (typeof window.RW_actualizarVisibilidadRunasLaterales === 'function') {
        window.RW_actualizarVisibilidadRunasLaterales();
    }
    return luck_multiplier;
}
window.RW_recalcularSuerteColecciones = RW_recalcularSuerteColecciones;
window.RW_marcarColeccionBasicaCompleta = function () {
    var v = RW_recalcularSuerteColecciones(true);
    if (typeof window.RW_actualizarVisibilidadRunasLaterales === 'function') {
        window.RW_actualizarVisibilidadRunasLaterales();
    }
    return v;
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
    n = Number(n) || 0;
    const abs = Math.abs(n);
    const sign = n < 0 ? "-" : "";

    function fmt(value, suffix) {
        return sign + value.toFixed(2).replace(/\.?0+$/, "") + suffix;
    }

    if (abs >= 1e18) return fmt(abs / 1e18, "Qi");
    if (abs >= 1e15) return fmt(abs / 1e15, "Qa");
    if (abs >= 1e12) return fmt(abs / 1e12, "T");
    if (abs >= 1e9)  return fmt(abs / 1e9,  "B");
    if (abs >= 1e6)  return fmt(abs / 1e6,  "M");
    if (abs >= 1e3)  return fmt(abs / 1e3,  "K");

    return sign + Math.floor(abs).toString();
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
    if (window.RW_DEBUG_ECONOMIA) {
        console.log("[RW economia][recalcularStatsDesdeMejoras:antes]", {
            points_ps: points_ps,
            points_ps_base: points_ps_base,
            runas_points_ps: _runas_points_ps,
            mejora_points_add: _mejora_points_add,
            mejora_multi_pts: _mejora_multi_pts,
            mejoras: mejoras
        });
    }
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

    bulk_runas = 1 + bulk_add + collection_bulk_bonus;
    window.bulk_runas = bulk_runas;
    const bulkEl = document.getElementById("bulk-display");
    if (bulkEl) bulkEl.textContent = bulk_runas + " runa" + (bulk_runas > 1 ? "s" : "");

    // 27/04 v3: bloque de actualizacion de suerte eliminado.
    // reaplicar boosts (ya solo de coins/points) sobre los nuevos valores
    if (typeof aplicarBoosts === "function") {
        aplicarBoosts();
    }
    if (window.RW_DEBUG_ECONOMIA) {
        console.log("[RW economia][recalcularStatsDesdeMejoras:despues]", {
            runasBaseLimpia: runasBaseLimpia,
            points_add: points_add,
            multi_points: multi_points,
            points_ps_base: points_ps_base,
            points_ps: points_ps
        });
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

    var bulkReal = parseInt(bulk_runas || window.bulk_runas || 1, 10) || 1;
    window.bulk_runas = bulkReal;

    var bulkEl = document.getElementById("bulk-display");
    if (bulkEl) {
        bulkEl.textContent = bulkReal + " runa" + (bulkReal > 1 ? "s" : "");
    }
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
        bulk_runas = parseInt(nuevoBulk, 10) || bulk_runas;
        window.bulk_runas = bulk_runas;
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
        var pCierre = c.querySelector(".runa-card-prob");
        if (pCierre) pCierre.style.maxHeight = "0";
        var fCierre = c.querySelector(".runa-card-flecha");
        if (fCierre) fCierre.textContent = "▾";
    });

    if (!abierto) {
        const p = parseFloat(card.dataset.prob || 0);
        const baseEl = card.querySelector(".prob-base-val");
        const luckEl = card.querySelector(".prob-luck-val");
        if (baseEl) baseEl.textContent = formatProb(p);
        if (luckEl) luckEl.textContent = formatProb(Math.min(100, p * (parseFloat(luck_multiplier) || 1)));

        card.classList.add("expandida");
        prob.style.display = "block";
        prob.style.overflow = "visible";
        prob.style.maxHeight = Math.max(120, prob.scrollHeight + 24) + "px";
        var flecha = card.querySelector(".runa-card-flecha");
        if (flecha) flecha.textContent = "▴";
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

function RW_esCorruptaLateral(card) {
    if (!card) return false;
    return card.dataset.runaVariante === 'corrupta' ||
        (card.textContent || '').toLowerCase().indexOf('corrupt') !== -1;
}

function RW_cantidadRunaLateral(card) {
    if (!card) return 0;
    var raw = card.dataset.cantidad || '0';
    var n = parseInt(String(raw).replace(/[^0-9-]/g, ''), 10) || 0;
    var cantEl = card.querySelector('.runa-card-cantidad');
    if (cantEl) {
        var txt = String(cantEl.textContent || '').replace(/[^0-9-]/g, '');
        n = Math.max(n, parseInt(txt, 10) || 0);
    }
    return n;
}

function RW_basicasCompletasLaterales() {
    if (rw_basic_collection_complete) {
        return true;
    }
    var basicas = Array.prototype.slice.call(document.querySelectorAll('.runa-card-btn[data-runa-grupo="basicas"]'))
        .filter(function (card) { return !RW_esCorruptaLateral(card); });
    return basicas.length > 0 && basicas.every(function (card) {
        return RW_cantidadRunaLateral(card) > 0;
    });
}

function RW_estadoGrupoRunasLaterales(grupo) {
    var key = 'rw_panel_runas_' + grupo + '_abierto';
    var guardado = localStorage.getItem(key);
    return guardado === null ? true : guardado !== '0';
}

function RW_aplicarDropdownGrupoRunasLaterales(grupoEl, abierto) {
    var lista = grupoEl.querySelector('.grupo-runas-lista');
    var flecha = grupoEl.querySelector('.grupo-runas-flecha');
    if (lista) lista.style.display = abierto ? '' : 'none';
    if (flecha) flecha.textContent = abierto ? '▼' : '▶';
}

window.RW_toggleGrupoRunasLaterales = function (grupo) {
    var key = 'rw_panel_runas_' + grupo + '_abierto';
    var nuevo = !RW_estadoGrupoRunasLaterales(grupo);
    localStorage.setItem(key, nuevo ? '1' : '0');
    window.RW_actualizarVisibilidadRunasLaterales();
};

window.RW_actualizarVisibilidadRunasLaterales = function () {
    var corruptasDisponibles = RW_basicasCompletasLaterales();
    if (corruptasDisponibles) {
        rw_basic_collection_complete = true;
        completed_collections = Math.max(1, parseInt(completed_collections, 10) || 0);
    }

    document.querySelectorAll('.grupo-runas[data-runa-grupo]').forEach(function (grupoEl) {
        var grupo = grupoEl.dataset.runaGrupo;
        var abierto = RW_estadoGrupoRunasLaterales(grupo);
        if (grupo === 'corruptas') {
            grupoEl.style.display = corruptasDisponibles ? '' : 'none';
        } else {
            grupoEl.style.display = '';
        }
        RW_aplicarDropdownGrupoRunasLaterales(grupoEl, abierto);
    });

    var corruptasVisibles = 0;
    document.querySelectorAll('.runa-card-btn[data-runa-grupo="corruptas"]').forEach(function (card) {
        var tiene = RW_cantidadRunaLateral(card) > 0;
        card.style.display = corruptasDisponibles && tiene ? '' : 'none';
        if (corruptasDisponibles && tiene) corruptasVisibles++;
    });

    document.querySelectorAll('[data-corruptas-msg]').forEach(function (msg) {
        msg.style.display = corruptasDisponibles && corruptasVisibles === 0 ? '' : 'none';
    });

    var visibles = Array.prototype.slice.call(document.querySelectorAll('.runa-card-btn')).filter(function (card) {
        var grupoEl = card.closest('.grupo-runas[data-runa-grupo]');
        return (!grupoEl || grupoEl.style.display !== 'none') && card.style.display !== 'none';
    });
    visibles.forEach(function (card) {
        if (card.classList.contains('runa-bloqueada')) {
            var nombre = card.querySelector('.runa-card-nombre');
            if (nombre) nombre.textContent = 'Runa desconocida';
        }
    });
};


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
    document.body.classList.remove("rw-sec-tirada", "rw-sec-tienda", "rw-sec-coleccion", "rw-sec-estadisticas", "rw-sec-engranajes", "rw-sec-ajustes");
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
    if (esCole) {
        if (typeof window.RW_actualizarVisibilidadRunasLaterales === 'function') window.RW_actualizarVisibilidadRunasLaterales();
        if (typeof window.RW_actualizarBonusColeccionVisual === 'function') window.RW_actualizarBonusColeccionVisual();
        if (typeof window.RW_recalcularSuerteColecciones === 'function') window.RW_recalcularSuerteColecciones();
        if (typeof window.RW_aplicarVistaColeccionV81 === 'function') setTimeout(window.RW_aplicarVistaColeccionV81, 0);
        setTimeout(() => { iniciarNeonBotones(); iniciarMiniCanvas(); }, 60);
    }
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

    // RW V114 — tambien refresca los historicos si ya existen en memoria.
    if (window.RW_STATS_HISTORICAS) {
        RW_pintarEstadisticasHistoricas();
    }
    if (typeof RW_pintarDesgloseStatsVivoDesdePanel === 'function') {
        RW_pintarDesgloseStatsVivoDesdePanel();
    }
}

// ── RW V114 — estadisticas historicas en vivo ─────────────────────────
// Antes estas cifras venian pintadas por PHP y solo cambiaban al recargar.
// Ahora se inicializan desde el DOM/RW_INIT y se incrementan con cada runa
// recibida por el evento rw:stats-actualizadas que dispara tirada.js.
function RW_parseEnteroStats(v) {
    if (v === undefined || v === null) return 0;
    var txt = String(v).replace(/<[^>]*>/g, '').replace(/[^0-9.,-]/g, '');
    if (!txt) return 0;
    txt = txt.replace(/[.,]/g, '');
    var n = parseInt(txt, 10);
    return isNaN(n) ? 0 : n;
}

function RW_normalizarGrupoStats(v) {
    return String(v || 'Sin grupo')
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '');
}

function RW_normalizarRarezaStats(v) {
    return String(v || '')
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/\s+/g, '_');
}

function RW_asegurarStatsHistoricas() {
    if (!window.RW_STATS_HISTORICAS) {
        var totalEl = document.getElementById('stats-total-runas-obtenidas');
        window.RW_STATS_HISTORICAS = {
            total_runas: RW_parseEnteroStats(
                (window.RW_INIT && window.RW_INIT.stats_total_runas !== undefined)
                    ? window.RW_INIT.stats_total_runas
                    : (totalEl ? totalEl.textContent : 0)
            ),
            total_tiradas: RW_parseEnteroStats(window.RW_INIT ? window.RW_INIT.total_tiradas : 0),
            grupos: {}
        };
    }

    document.querySelectorAll('[data-stat-rareza-valor]').forEach(function (el) {
        var grupo = RW_normalizarGrupoStats(el.dataset.statGrupo || 'Sin grupo');
        var rareza = RW_normalizarRarezaStats(el.dataset.statRareza || '');
        if (!rareza) return;
        if (!window.RW_STATS_HISTORICAS.grupos[grupo]) window.RW_STATS_HISTORICAS.grupos[grupo] = {};
        if (window.RW_STATS_HISTORICAS.grupos[grupo][rareza] === undefined) {
            window.RW_STATS_HISTORICAS.grupos[grupo][rareza] = RW_parseEnteroStats(el.textContent);
        }
    });

    return window.RW_STATS_HISTORICAS;
}

function RW_pintarEstadisticasHistoricas() {
    var st = RW_asegurarStatsHistoricas();

    var totalEl = document.getElementById('stats-total-runas-obtenidas');
    if (totalEl) totalEl.textContent = Number(st.total_runas || 0).toLocaleString();

    var tiradasEl = document.getElementById('stats-total-tiradas');
    if (tiradasEl) {
        var totalTiradas = RW_parseEnteroStats(
            (window.RW_INIT && window.RW_INIT.total_tiradas !== undefined)
                ? window.RW_INIT.total_tiradas
                : st.total_tiradas
        );
        st.total_tiradas = totalTiradas;
        tiradasEl.textContent = Number(totalTiradas || 0).toLocaleString();
    }

    document.querySelectorAll('[data-stat-rareza-valor]').forEach(function (el) {
        var grupo = RW_normalizarGrupoStats(el.dataset.statGrupo || 'Sin grupo');
        var rareza = RW_normalizarRarezaStats(el.dataset.statRareza || '');
        if (!rareza) return;
        if (st.grupos[grupo] && st.grupos[grupo][rareza] !== undefined) {
            el.textContent = Number(st.grupos[grupo][rareza] || 0).toLocaleString();
        }
    });
}


// ── RW V115 — desglose vivo de estadisticas desde el panel de runas ───
// Si PHP no pinta $stats_grupos al cargar, antes se quedaba el mensaje
// "Todavia no has conseguido ninguna runa" aunque el panel lateral sí tuviera runas.
// Este bloque reconstruye el desglose desde las cards reales del DOM.
function RW_nombreGrupoStatsVivo(slug) {
    slug = String(slug || '').toLowerCase();
    if (slug === 'basicas') return 'Runas básicas';
    if (slug === 'corruptas') return 'Runas corruptas';
    return slug ? slug.replace(/_/g, ' ') : 'Runas';
}

function RW_nombreRarezaStatsVivo(rareza) {
    rareza = String(rareza || '').toLowerCase();
    return rareza.replace(/_/g, ' ').replace(/^./, function (c) { return c.toUpperCase(); });
}

function RW_detectarRarezaCardStats(card) {
    var orden = ['eterna','divina','mitica','legendaria','epica','rara','poco_comun','comun'];
    for (var i = 0; i < orden.length; i++) {
        if (card.classList.contains(orden[i])) return orden[i];
    }
    return card.dataset.rareza || '';
}

function RW_leerStatsDesdePanelRunas() {
    var orden = ['eterna','divina','mitica','legendaria','epica','rara','poco_comun','comun'];
    var grupos = {};
    var total = 0;

    document.querySelectorAll('.runa-card-btn[data-id]').forEach(function (card) {
        var cantidad = RW_parseEnteroStats(card.dataset.cantidad || '0');
        if (cantidad <= 0) return;

        var grupo = card.dataset.runaGrupo || 'basicas';
        var rareza = RW_detectarRarezaCardStats(card);
        if (!rareza) return;

        if (!grupos[grupo]) grupos[grupo] = {};
        grupos[grupo][rareza] = (grupos[grupo][rareza] || 0) + cantidad;
        total += cantidad;
    });

    return { total: total, grupos: grupos, orden: orden };
}

function RW_quitarMensajeStatsVacio() {
    var seccion = document.getElementById('seccion-estadisticas');
    if (!seccion) return;

    Array.prototype.slice.call(seccion.querySelectorAll('.stats-bloque p')).forEach(function (p) {
        var txt = String(p.textContent || '').toLowerCase();
        if (txt.indexOf('todavia no has conseguido ninguna runa') !== -1 || txt.indexOf('todavía no has conseguido ninguna runa') !== -1) {
            var bloque = p.closest('.stats-bloque');
            if (bloque) bloque.style.display = 'none';
        }
    });
}

function RW_pintarDesgloseStatsVivoDesdePanel() {
    var seccion = document.getElementById('seccion-estadisticas');
    if (!seccion) return;

    var data = RW_leerStatsDesdePanelRunas();
    if (!data || data.total <= 0) return;

    RW_quitarMensajeStatsVacio();

    var st = RW_asegurarStatsHistoricas();
    st.total_runas = Math.max(RW_parseEnteroStats(st.total_runas), data.total);

    var totalEl = document.getElementById('stats-total-runas-obtenidas');
    if (totalEl) totalEl.textContent = Number(st.total_runas || data.total || 0).toLocaleString();

    // RW V116 — si juego.php ya ha pintado el desglose con data-stat-rareza-valor,
    // NO creamos un segundo bloque. Actualizamos el existente y borramos cualquier
    // bloque vivo antiguo que haya quedado de la v115.
    var filasPHP = Array.prototype.slice.call(seccion.querySelectorAll('[data-stat-rareza-valor]'));
    if (filasPHP.length > 0) {
        var bloqueVivoViejo = document.getElementById('stats-desglose-vivo');
        if (bloqueVivoViejo) bloqueVivoViejo.remove();

        filasPHP.forEach(function (el) {
            var grupoNorm = RW_normalizarGrupoStats(el.dataset.statGrupo || '');
            var rarezaNorm = RW_normalizarRarezaStats(el.dataset.statRareza || '');
            var valor = null;

            Object.keys(data.grupos).forEach(function (grupoSlug) {
                if (valor !== null) return;
                var g1 = RW_normalizarGrupoStats(grupoSlug);
                var g2 = RW_normalizarGrupoStats(RW_nombreGrupoStatsVivo(grupoSlug));
                if (grupoNorm === g1 || grupoNorm === g2) {
                    if (data.grupos[grupoSlug][rarezaNorm] !== undefined) {
                        valor = data.grupos[grupoSlug][rarezaNorm];
                    }
                }
            });

            if (valor !== null) {
                el.textContent = Number(valor || 0).toLocaleString();
            }
        });

        return;
    }

    // Solo llegamos aquí si PHP NO pintó ningún desglose. Entonces sí creamos
    // un bloque generado por JS para evitar el mensaje falso de "no tienes runas".
    var bloque = document.getElementById('stats-desglose-vivo');
    if (!bloque) {
        bloque = document.createElement('div');
        bloque.id = 'stats-desglose-vivo';
        var prox = seccion.querySelector('.stats-proximamente');
        if (prox && prox.parentNode) prox.parentNode.insertBefore(bloque, prox);
        else seccion.appendChild(bloque);
    }

    bloque.innerHTML = '';

    Object.keys(data.grupos).forEach(function (grupoSlug) {
        var rarezas = data.grupos[grupoSlug];
        var bloqueGrupo = document.createElement('div');
        bloqueGrupo.className = 'stats-bloque';

        var titulo = document.createElement('div');
        titulo.className = 'stats-bloque-titulo';
        titulo.textContent = RW_nombreGrupoStatsVivo(grupoSlug);
        bloqueGrupo.appendChild(titulo);

        var tabla = document.createElement('div');
        tabla.className = 'stats-tabla';

        data.orden.forEach(function (rareza) {
            if (!rarezas[rareza]) return;
            var fila = document.createElement('div');
            fila.className = 'stats-fila';

            var label = document.createElement('span');
            label.className = 'stats-label stats-label-rareza rareza-' + rareza;
            label.textContent = RW_nombreRarezaStatsVivo(rareza);

            var val = document.createElement('span');
            val.className = 'stats-valor';
            val.setAttribute('data-stat-rareza-valor', '');
            val.setAttribute('data-stat-grupo', RW_nombreGrupoStatsVivo(grupoSlug));
            val.setAttribute('data-stat-rareza', rareza);
            val.textContent = Number(rarezas[rareza] || 0).toLocaleString();

            fila.appendChild(label);
            fila.appendChild(val);
            tabla.appendChild(fila);
        });

        bloqueGrupo.appendChild(tabla);
        bloque.appendChild(bloqueGrupo);
    });
}

function RW_sumarEstadisticasPorRunas(runasGanadas) {
    if (!Array.isArray(runasGanadas) || runasGanadas.length === 0) return;

    var st = RW_asegurarStatsHistoricas();

    runasGanadas.forEach(function (r) {
        if (!r) return;
        var cantidad = RW_parseEnteroStats(r.cantidad || 1) || 1;
        var rareza = RW_normalizarRarezaStats(r.rareza || '');
        var grupoRaw = r.grupo_nombre || r.nombre_grupo || r.grupo || r.group_name || r.collection_name || '';

        // Si el pack no manda grupo, lo buscamos en los botones ya pintados
        // de coleccion/panel lateral usando el id de runa.
        if (!grupoRaw && r.id !== undefined && r.id !== null) {
            var ref = document.querySelector('[data-id="' + String(r.id) + '"][data-grupo]');
            if (ref && ref.dataset) grupoRaw = ref.dataset.grupo || '';
        }

        var grupo = grupoRaw ? RW_normalizarGrupoStats(grupoRaw) : '';

        st.total_runas += cantidad;

        // Si el server manda grupo, actualizamos la fila exacta grupo+rareza.
        // Si no lo manda, no inventamos grupo para evitar sumar en una tabla incorrecta.
        if (grupo && rareza) {
            if (!st.grupos[grupo]) st.grupos[grupo] = {};
            st.grupos[grupo][rareza] = (RW_parseEnteroStats(st.grupos[grupo][rareza]) || 0) + cantidad;
        }
    });

    RW_pintarEstadisticasHistoricas();
}

function RW_aplicarEventoStatsActualizadas(e) {
    var d = (e && e.detail) || {};
    var data = d.respuesta_original || d;

    if (window.RW_INIT && d.stats && d.stats.total_tiradas !== undefined) {
        window.RW_INIT.total_tiradas = RW_parseEnteroStats(d.stats.total_tiradas);
    }

    if (data && Array.isArray(data.runas_ganadas) && data.runas_ganadas.length > 0) {
        RW_sumarEstadisticasPorRunas(data.runas_ganadas);
    } else {
        RW_pintarEstadisticasHistoricas();
    }

    refrescarEstadisticas();
}

document.addEventListener('rw:stats-actualizadas', RW_aplicarEventoStatsActualizadas);

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
        RW_asegurarStatsHistoricas();
        RW_pintarEstadisticasHistoricas();
        RW_pintarDesgloseStatsVivoDesdePanel();
    });
} else {
    RW_asegurarStatsHistoricas();
    RW_pintarEstadisticasHistoricas();
    RW_pintarDesgloseStatsVivoDesdePanel();
}

window.RW_pintarEstadisticasHistoricas = RW_pintarEstadisticasHistoricas;
window.RW_pintarDesgloseStatsVivoDesdePanel = RW_pintarDesgloseStatsVivoDesdePanel;
window.RW_sumarEstadisticasPorRunas = RW_sumarEstadisticasPorRunas;
window.refrescarEstadisticas = refrescarEstadisticas;


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
        if (typeof window.RW_actualizarVisibilidadRunasLaterales === 'function') {
            window.RW_actualizarVisibilidadRunasLaterales();
        }
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
    if (detalle.collection_states !== undefined && window.RW_INIT) {
        window.RW_INIT.collection_states = detalle.collection_states || {};
        if (window.RW_INIT.collection_states.basica_normal && window.RW_INIT.collection_states.basica_normal.completa) {
            window.RW_INIT.basic_collection_complete = true;
            rw_basic_collection_complete = true;
        }
    }
    if (detalle.collection_bulk_bonus !== undefined) {
        collection_bulk_bonus = parseInt(detalle.collection_bulk_bonus, 10) || 0;

        if (window.RW_INIT) {
            window.RW_INIT.collection_bulk_bonus = collection_bulk_bonus;
        }

        if (Array.isArray(window.RW_INIT?.mejoras_completas)) {
            recalcularStatsDesdeMejoras(window.RW_INIT.mejoras_completas);
        } else {
            bulk_runas = Math.max(1, bulk_runas + collection_bulk_bonus);
            actualizarPantalla();
        }
        window.bulk_runas = bulk_runas;
        actualizarPantalla();
    }
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
    RW_actualizarBonusColeccionVisual();
}

function setPointsPs(v) {
    const n = parseFloat(v);
    if (!isNaN(n)) {
        if (window.RW_DEBUG_ECONOMIA) {
            console.log("[RW economia][setPointsPs]", {
                valor_servidor_base: n,
                points_ps_antes: points_ps,
                points_ps_base_antes: points_ps_base
            });
        }
        points_ps_base = Math.max(parseFloat(points_ps_base) || 0, n);
        
        if (typeof aplicarBoosts === "function") {
            aplicarBoosts();
        } else {
            points_ps = Math.max(parseFloat(points_ps) || 0, points_ps_base);
            window.points_ps = points_ps;
            actualizarPantalla();
        }
        if (window.RW_DEBUG_ECONOMIA) {
            console.log("[RW economia][setPointsPs:despues]", {
                points_ps: points_ps,
                points_ps_base: points_ps_base
            });
        }
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
window.getPointsActual = function () {
    return points;
};

window.getCoinsActual = function () {
    return coins;
};

