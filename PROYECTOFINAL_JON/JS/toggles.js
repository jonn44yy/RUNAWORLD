// toggles.js — runaworld
// gestion del on/off de las animaciones especiales (eterna, divina, mitica,
// legendaria). el jugador puede desactivar cada una por separado desde el
// menu de coleccion, por si alguien pasa de ver la animacion de 20s cada
// vez que saca una eterna. el estado vive en localStorage con clave por
// usuario para que sobreviva a recargas y que dos cuentas en el mismo
// navegador no se pisen la configuracion
//
// indice:
//   1. helpers de localStorage (_animKey, getAnimActiva, setAnimActiva)
//   2. variables en memoria espejo del localStorage
//   3. toggleAnimaciones() -- handler del boton cuando lo pulsas
//   4. actualizarEstadoToggle() -- sync al seleccionar runa en coleccion
//   5. _renderToggle() -- pinta el boton segun estado y rareza
//   6. override de mostrarResultado para respetar los toggles
//
// lenguaje interno para los poco entendidos:
//   toggle   = el botoncito "ANIM ON / ANIM OFF" que sale al seleccionar una
//              runa especial en coleccion. cambia de color segun rareza
//              (dorado eterna, rojo mitica, etc)
//   clave ls = "anim_<userid>_<rareza>". el userid evita choques entre
//              cuentas en el mismo navegador
//   override = mostrarResultado() vive en animaciones.js. aqui la envuelvo
//              para que antes de animar compruebe el flag. si esta OFF,
//              pinto solo la cartita como si fuera runa normal
//
// hecho a principios de abril cuando anadi las animaciones pesadas (divina,
// eterna). sin el toggle, si te salia una eterna no podias jugar 20 segundos
// y a algunos les molesta. estable desde entonces. !hi


// clave de localStorage por usuario + rareza. si no hay user_id (no deberia
// pasar en prod, pero si fallara el RW_INIT crashearia todo) guardo bajo
// "guest" para no crashear el juego por esto
const _animUserId = (window.RW_INIT && window.RW_INIT.user_id) ? window.RW_INIT.user_id : "guest";
function _animKey(rareza) { return "anim_" + _animUserId + "_" + rareza; }

// el "no esta en off" es intencional: si nunca se toco el toggle, getItem
// devuelve null, y eso cuenta como ON. asi el jugador nuevo ve las animaciones
// por defecto (opt-out en lugar de opt-in)
function getAnimActiva(rareza) {
    return localStorage.getItem(_animKey(rareza)) !== "off";
}
function setAnimActiva(rareza, valor) {
    localStorage.setItem(_animKey(rareza), valor ? "on" : "off");
}

// variables espejo en memoria. las usan mostrarResultado y tirarRuna por
// performance, para no ir al localStorage varias veces por segundo en las
// tiradas rapidas
let animEternaActiva     = getAnimActiva("eterna");
let animMiticaActiva     = getAnimActiva("mitica");
let animLegendariaActiva = getAnimActiva("legendaria");
let animDivinaActiva     = getAnimActiva("divina");


// handler del click en el boton de toggle. lee la rareza que esta seleccionada
// ahora mismo en coleccion (colRunaActual, definido en coleccion.js) y alterna
// su estado
function toggleAnimaciones() {
    // leo del localStorage como fuente de verdad (no de la variable en memoria)
    // por si algo externo lo cambio, p.ej. otra pestana del mismo usuario
    const r = colRunaActual;
    if (r !== "eterna" && r !== "mitica" && r !== "legendaria" && r !== "divina") return;
    const estadoActual = getAnimActiva(r);
    const nuevoEstado  = !estadoActual;
    setAnimActiva(r, nuevoEstado);
    // sincronizar la variable en memoria para que las tiradas siguientes la
    // lean sin volver al localStorage
    if (r === "eterna")     animEternaActiva     = nuevoEstado;
    if (r === "mitica")     animMiticaActiva     = nuevoEstado;
    if (r === "legendaria") animLegendariaActiva = nuevoEstado;
    if (r === "divina")     animDivinaActiva     = nuevoEstado;
    _renderToggle(r, nuevoEstado);
}


// se llama desde coleccion.js al seleccionar una runa especial para que el
// boton muestre el estado correcto de esa rareza. sin esto, el boton queda
// pegado con el estado de la rareza que estabas viendo antes
function actualizarEstadoToggle(rareza) {
    const r = rareza || colRunaActual;
    if (!r) return;
    const activa = getAnimActiva(r);
    // al paso sincronizo la memoria por si cambio desde otra pestana
    if (r === "eterna")     animEternaActiva     = activa;
    if (r === "mitica")     animMiticaActiva     = activa;
    if (r === "legendaria") animLegendariaActiva = activa;
    if (r === "divina")     animDivinaActiva     = activa;
    _renderToggle(r, activa);
}


// pinta el boton: texto, clase anim-off (css le pone cruz), y color por rareza
function _renderToggle(rareza, activa) {
    const txt = document.getElementById("btn-toggle-anim-txt");
    const btn = document.getElementById("btn-toggle-anim");
    if (!txt || !btn) return;
    txt.textContent = activa ? "ANIM ON" : "ANIM OFF";
    btn.classList.toggle("anim-off", !activa);
    // limpiar cualquier btn-toggle-anim-* previo antes de anadir la nueva.
    // si no, se acumulan y el color depende de cual este la ultima en el
    // className, que es impredecible
    btn.className = btn.className.replace(/btn-toggle-anim-\w+/g, "");
    btn.classList.add("btn-toggle-anim-" + rareza);
}


// override de mostrarResultado
// guardo la original para no perderla y la reemplazo por una version que
// consulta el toggle antes de disparar la animacion. asi animaciones.js
// puede seguir llamando mostrarResultado sin saber nada del sistema de
// toggles, y si en el futuro quiero quitar los toggles me basta con no
// cargar este archivo
const _mostrarResultadoOriginal = mostrarResultado;
mostrarResultado = function(runa) {
    const rareza = runa.rareza;
    // rareza especial con toggle en OFF --> salto animacion y muestro solo
    // cartita, que se borra sola a los 8s para no dejar basura en pantalla
    if ((rareza === "eterna"     && !getAnimActiva("eterna"))    ||
        (rareza === "divina"     && !getAnimActiva("divina"))    ||
        (rareza === "mitica"     && !getAnimActiva("mitica"))    ||
        (rareza === "legendaria" && !getAnimActiva("legendaria"))) {
        document.getElementById("resultado-tirada").innerHTML = "";
        mostrarCardEn("resultado-tirada", runa);
        if (resultadoTimeout) clearTimeout(resultadoTimeout);
        resultadoTimeout = setTimeout(() => {
            const el = document.getElementById("resultado-tirada");
            if (el) el.innerHTML = "";
        }, 8000);
        return;
    }
    // rareza normal o toggle ON --> flujo original sin cambios
    _mostrarResultadoOriginal(runa);
};


// nota: el estado del toggle se sincroniza al seleccionar una runa en la
// coleccion, ver seleccionarRunaCol() en coleccion.js


// ideas futuras / TODO:
//   - un toggle maestro en ajustes que apague todas las animaciones de golpe
//   - toggle para otras animaciones menores (runa normal cayendo, boost
//     conseguido) para los jugadores de "modo turbo"
//   - opcion "saltar animacion con click" para los que quieren verla pero
//     solo la primera vez por sesion