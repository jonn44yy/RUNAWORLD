window.RW_COLECCION_VERSION = '8.2';
// coleccion.js — runaworld
// todo lo que pasa en la seccion de coleccion: cuando el jugador clica una
// runa desbloqueada se carga su animacion en el iframe central, se pinta el
// panel de stats a la derecha, y se arrancan efectos canvas por rareza. ademas
// este archivo se encarga de las animaciones constantes de los botones (mini
// canvas en los comunes, efecto neon en hover para los especiales)
//
// indice:
//   1. pararAnimColeccion() -- cortar loop activo y limpiar iframe
//   2. seleccionarRunaCol() -- al hacer click: iframe + panel stats + toggle
//   3. animaciones del canvas central por rareza:
//      - animarLegendaria (dorado, runas giratorias)
//      - animarDivina     (cruz + god rays + particulas doradas)
//      - animarMitica     (rojo pulsante con flechas cardinales)
//      - animarPocoComunCanvas (3 anillos verdes)
//      - animarRaraCanvas      (hexagonos azules)
//      - animarEpicaCanvas     (estrella morada girando rapido)
//   4. iniciarMiniCanvas() -- iconos 32x32 animados de los botones comunes
//   5. verAnimacionCompleta() -- boton que carga xxx_animacion.html en iframe
//   6. iniciarNeonBotones() -- hover con luces corriendo en botones especiales
//   7. parche suelto al final: arrancar neons al entrar en coleccion
//
// lenguaje interno para los poco entendidos:
//   col-canvas    = canvas grande del centro. aqui se pinta en loop la
//                   animacion de la rareza seleccionada
//   col-iframe    = iframe que carga RUNAS_HTML/<rareza>.html. es la animacion
//                   "preview" que se ve al seleccionar una runa. si pulsas
//                   "ver animacion" el iframe pasa a <rareza>_animacion.html
//                   que es la version completa (con musica, mas duracion, etc)
//   colRaf        = id del requestAnimationFrame activo del canvas central.
//                   guardado aparte para poder cancelarlo cuando cambiamos
//                   de runa o salimos de coleccion
//   colRunaActual = rareza seleccionada ahora mismo en coleccion. cada frame()
//                   comprueba esta variable y se corta solo si cambia. asi
//                   nunca hay dos animaciones pintando a la vez sobre el
//                   mismo canvas, que es caos visual garantizado
//   neon          = luces corriendo por el borde de los botones especiales
//                   al hacer hover. diferente por rareza: luz dorada para
//                   legendaria, roja con flash para mitica, aura+god-rays
//                   para divina, polvo cosmico + geometria sagrada para eterna
//   mini canvas   = pequeno canvas 32x32 que vive dentro de cada boton de
//                   runa comun. siempre animandose si la runa esta desbloqueada,
//                   no necesita hover. sirve de iconito identificativo por rareza
//
// este archivo es el mas "visual" del proyecto, casi todo son recetas de
// ctx.save() / rotate / draw / restore. el patron se repite mucho: lo explico
// detallado en animarLegendaria y despues abreviado en las demas. !hi


// estado global del canvas central. colRaf es el rAF activo, colRunaActual
// es la rareza seleccionada. ambas las leen varios archivos (ui.js, toggles.js)
// por eso van como variables sueltas y no encapsuladas
let colRaf = null;
let colRunaActual = null;


// parar el loop activo del canvas central y limpiar el iframe. se llama al
// cambiar de seccion (ver mostrarSeccion en ui.js) y antes de seleccionar
// una runa nueva dentro de coleccion
function pararAnimColeccion() {
    if (colRaf) { cancelAnimationFrame(colRaf); colRaf = null; }
    // about:blank descarga el html anterior del iframe. sin esto, el iframe
    // sigue corriendo su script aunque no se vea, gastando cpu de balde
    const iframe = document.getElementById("col-iframe");
    if (iframe) iframe.src = "about:blank";
    colRunaActual = null;
}


// click en una runa desbloqueada. hace cuatro cosas:
//   1. actualiza la clase .sel para el resaltado visual
//   2. muestra u oculta el boton "ver animacion" segun sea especial o no
//   3. carga la animacion de esa rareza en el iframe central
//   4. rellena el panel stats de la derecha con los datos de esa runa
function seleccionarRunaCol(el) {
    // quitar seleccion previa de cualquier tipo de boton (especial o comun)
    document.querySelectorAll(".col-runa-btn.sel, .col-runa-comun.sel").forEach(b => b.classList.remove("sel"));
    el.classList.add("sel");

    const rareza       = el.dataset.rareza;
    const runaFile     = el.dataset.runaFile || rareza;
    const nombre       = el.dataset.nombre;
    const peso         = el.dataset.peso;
    const multiplicador= el.dataset.multiplicador;
    const cantidad     = el.dataset.cantidad;
    const imagen       = el.dataset.imagen;

    // hint solo se ve si no hay ninguna runa seleccionada, lo escondo ya
    var hintEl = document.getElementById("col-canvas-hint");
    if (hintEl) hintEl.style.display = "none";

    // el boton "ver animacion" + toggle ON/OFF solo aplica a rarezas que
    // tienen animacion pesada (las que duran >5s y pueden molestar). para
    // comunes el boton se esconde
    const esEspecial = (rareza === "eterna" || rareza === "divina" || rareza === "legendaria" || rareza === "mitica");
    const rowEl = document.getElementById("btn-anim-row");
    if (rowEl) {
        rowEl.style.display = esEspecial ? "flex" : "none";
        if (esEspecial) {
            // actualizar clases de color segun rareza (dorado legendaria, rojo mitica, etc).
            // el css de btn-ver-anim-<rareza> se encarga del color de fondo/borde
            document.getElementById("btn-ver-anim").className = "btn-ver-anim btn-ver-anim-" + rareza;
            const toggleBtn = document.getElementById("btn-toggle-anim");
            if (toggleBtn) toggleBtn.className = "btn-toggle-anim btn-toggle-anim-" + rareza;
        }
    }

    // cargar el html de la rareza en el iframe. pararAnimColeccion ya limpia
    // cualquier animacion canvas central anterior, asi empiezo limpio
    pararAnimColeccion();
    colRunaActual = runaFile;
    // sincronizar el texto del toggle (ANIM ON / ANIM OFF) con el estado
    // guardado en localStorage para esta rareza. ver toggles.js
    if (rareza === "eterna" || rareza === "divina" || rareza === "legendaria" || rareza === "mitica") actualizarEstadoToggle(runaFile);

    const iframe = document.getElementById("col-iframe");
    if (iframe) iframe.src = "RUNAS_HTML/RUNAS/" + runaFile + ".html";

    // panel stats de la derecha. titulo con el nombre y color por rareza
    var statsTituloEl = document.getElementById("col-stats-titulo");
    if (statsTituloEl) statsTituloEl.textContent = nombre;

    // mapas de color y etiqueta. podria meterlos arriba como constantes pero
    // solo se usan aqui y tampoco es un drama que esten inline. si anado una
    // rareza nueva, es un sitio obvio donde anadir la entrada
    const colores = {
        divina: "var(--divine)", eterna: "rgba(160,110,255,1)", mitica: "var(--red)", legendaria: "var(--gold)",
        epica: "rgba(160,80,255,0.9)", rara: "rgba(60,140,255,0.9)",
        poco_comun: "rgba(50,200,80,0.9)", comun: "rgba(160,160,160,0.7)"
    };
    const labels = {
        divina:"Divina", eterna:"Eterna", mitica:"Mítica", legendaria:"Legendaria", epica:"Épica",
        rara:"Rara", poco_comun:"Poco Común", comun:"Común"
    };
    const colorRareza = colores[rareza] || "var(--silver)";
    const labelRareza = labels[rareza]  || rareza;
    if (statsTituloEl) statsTituloEl.style.color = colorRareza;

    // 27/04 v3: probabilidad fija (sin "Con tu suerte"). getProbStr y
    // getProbBaseStr ahora devuelven el mismo valor (la fraccion 1/X)
    const runaId  = parseInt(el.dataset.id);
    const probStr = getProbStr(runaId);

    // el html del panel de stats lo pinto con innerHTML. son pocas filas y
    // se repinta solo al seleccionar otra runa, no merece la pena DOM API
    // nodo a nodo. el onerror del img elimina el contenedor si la imagen
    // no existe, asi no queda un hueco feo con el icono de imagen rota
    var statsContenidoEl = document.getElementById("col-stats-contenido");
    var puntosPorRuna = parseFloat(multiplicador) || 0;
    var cantidadNum = parseInt(cantidad, 10) || 0;
    var totalPuntosRuna = puntosPorRuna * cantidadNum;
    var fmt = (typeof formatNum === "function") ? formatNum : function(n){ return Math.floor(parseFloat(n) || 0).toLocaleString(); };
    var probLuckStr = (typeof getProbLuckStr === "function") ? getProbLuckStr(runaId) : probStr;

    if (statsContenidoEl) {
        statsContenidoEl.innerHTML = `
            <div class="col-stat-fila">
                <span class="col-stat-label">Rareza</span>
                <span class="col-stat-valor" style="color:${colorRareza}">${labelRareza}</span>
            </div>
            <div class="col-stat-fila">
                <span class="col-stat-label">Rareza base</span>
                <span class="col-stat-valor" style="color:${colorRareza}">${probStr}</span>
            </div>
            <div class="col-stat-fila">
                <span class="col-stat-label">Con suerte</span>
                <span class="col-stat-valor" style="color:${colorRareza}">${probLuckStr}</span>
            </div>
            <div class="col-stat-fila">
                <span class="col-stat-label">Puntos por runa</span>
                <span class="col-stat-valor" style="color:${colorRareza}">${fmt(puntosPorRuna)} pts/s</span>
            </div>
            <div class="col-stat-fila">
                <span class="col-stat-label">En tu poder</span>
                <span class="col-stat-valor">${cantidadNum.toLocaleString()}</span>
            </div>
            <div class="col-stat-fila col-stat-total-runa">
                <span class="col-stat-label">Total generado</span>
                <span class="col-stat-valor" style="color:${colorRareza}">${fmt(totalPuntosRuna)} pts/s</span>
            </div>
            ${imagen ? `<div class="col-stat-imagen"><img src="IMG/${imagen}" alt="${nombre}" onerror="this.parentNode.remove()"></div>` : ""}
        `;
    }

    if (typeof window.rwSyncMobileCollectionStats === "function") {
        window.rwSyncMobileCollectionStats();
    }
}


// animacion del canvas central para runas legendarias: 3 anillos dorados
// concentricos girando a velocidades distintas, con runas noruegas en el
// medio, mas un punto central pulsante. este es el patron base que repito
// en las siguientes animaciones, lo comento aqui detallado:
//
//   1. leer canvas y ctx, ajustar dimensiones a las del dom (offsetWidth/Height).
//      si el canvas no tiene tamano puesto, uso 400x360 como fallback
//   2. frame() es un closure que dibuja UN frame y se re-programa con rAF
//      al final. antes de dibujar, se corta solo si colRunaActual ya no es
//      "legendaria" (el jugador ha cambiado de runa)
//   3. t es el tiempo acumulado, lo uso para rotaciones y pulsos (sin() y cos())
//   4. guardo colRaf para poder cancelarlo desde pararAnimColeccion
function animarLegendaria() {
    const canvas = document.getElementById("col-canvas");
    if (!canvas) return;
    const ctx = canvas.getContext("2d");
    let t = 0;
    const W = canvas.width  = canvas.offsetWidth  || 400;
    const H = canvas.height = canvas.offsetHeight || 360;
    const cx = W / 2, cy = H / 2;

    function frame() {
        // escape early si el jugador ya cambio de runa: cada frame se
        // comprueba, y cuando toca dejar de animar simplemente no me
        // reprogramo con rAF. el colRaf viejo caduca solo
        if (colRunaActual !== "legendaria") return;
        ctx.clearRect(0, 0, W, H);
        t += 0.012;

        const R1 = Math.min(W, H) * 0.36;
        const R2 = R1 * 0.65;
        const R3 = R1 * 0.35;

        // anillo exterior girando en sentido horario. el alpha oscila
        // ligeramente con sin(t*2) para que "respire". shadowBlur da el
        // glow dorado tipico de legendaria
        ctx.save();
        ctx.translate(cx, cy);
        ctx.rotate(t * 0.6);
        ctx.strokeStyle = `rgba(255,215,0,${0.55 + 0.2 * Math.sin(t*2)})`;
        ctx.lineWidth = 1.2;
        ctx.shadowColor = "#ffd700";
        ctx.shadowBlur = 18;
        ctx.beginPath(); ctx.arc(0, 0, R1, 0, Math.PI * 2); ctx.stroke();
        // marcas radiales en el anillo, con 4 de cada 16 mas largas (tipo
        // puntos cardinales) para romper la simetria
        for (let i = 0; i < 16; i++) {
            const a = (i / 16) * Math.PI * 2;
            const len = i % 4 === 0 ? 14 : 7;
            ctx.beginPath();
            ctx.moveTo(Math.cos(a) * (R1 - len), Math.sin(a) * (R1 - len));
            ctx.lineTo(Math.cos(a) * R1,          Math.sin(a) * R1);
            ctx.stroke();
        }
        ctx.restore();

        // anillo medio girando en sentido contrario al exterior (contra-rotacion).
        // dos anillos girando en direcciones opuestas dan sensacion de
        // mecanismo cosmico, queda muy chulo para legendaria
        ctx.save();
        ctx.translate(cx, cy);
        ctx.rotate(-t * 0.9);
        ctx.strokeStyle = `rgba(255,215,0,${0.35 + 0.15 * Math.sin(t*3)})`;
        ctx.lineWidth = 0.8;
        ctx.shadowColor = "#ffd700"; ctx.shadowBlur = 10;
        ctx.beginPath(); ctx.arc(0, 0, R2, 0, Math.PI * 2); ctx.stroke();
        // runas noruegas en el anillo medio. los caracteres son unicode
        // futhark antiguo (ᚠᚢᚦᚨᚱᚲᚷᚹ), dan el toque vikingo del juego
        ctx.fillStyle = `rgba(255,215,0,${0.6 + 0.3 * Math.sin(t*2)})`;
        ctx.font = "13px serif";
        ctx.textAlign = "center"; ctx.textBaseline = "middle";
        const runas = ["ᚠ","ᚢ","ᚦ","ᚨ","ᚱ","ᚲ","ᚷ","ᚹ"];
        runas.forEach((r, i) => {
            const a = (i / runas.length) * Math.PI * 2;
            ctx.fillText(r, Math.cos(a) * R2, Math.sin(a) * R2);
        });
        ctx.restore();

        // anillo interior + cruz dentro. gira mas rapido que los otros dos
        // (t*1.4) para dar la sensacion de que el centro "aprieta"
        ctx.save();
        ctx.translate(cx, cy);
        ctx.rotate(t * 1.4);
        ctx.strokeStyle = `rgba(255,215,0,${0.7 + 0.25 * Math.sin(t*4)})`;
        ctx.lineWidth = 1.5;
        ctx.shadowColor = "#ffd700"; ctx.shadowBlur = 25;
        ctx.beginPath(); ctx.arc(0, 0, R3, 0, Math.PI * 2); ctx.stroke();
        ctx.beginPath();
        ctx.moveTo(0, -R3 * 0.7); ctx.lineTo(0, R3 * 0.7);
        ctx.moveTo(-R3 * 0.7, 0); ctx.lineTo(R3 * 0.7, 0);
        ctx.stroke();
        ctx.restore();

        // nucleo central pulsante con halo: primero un radial gradient suave
        // para el resplandor, luego un punto solido encima. el pulso hace
        // que el punto crezca y decrezca 5 +/- 3px cada ~1.5s
        const pulso = 5 + 3 * Math.sin(t * 4);
        const grad = ctx.createRadialGradient(cx, cy, 0, cx, cy, pulso * 3);
        grad.addColorStop(0, "rgba(255,215,0,0.9)");
        grad.addColorStop(1, "rgba(255,215,0,0)");
        ctx.fillStyle = grad;
        ctx.beginPath(); ctx.arc(cx, cy, pulso * 3, 0, Math.PI * 2); ctx.fill();
        ctx.fillStyle = "#ffd700";
        ctx.shadowBlur = 20; ctx.shadowColor = "#ffd700";
        ctx.beginPath(); ctx.arc(cx, cy, pulso, 0, Math.PI * 2); ctx.fill();
        ctx.shadowBlur = 0;

        colRaf = requestAnimationFrame(frame);
    }
    colRaf = requestAnimationFrame(frame);
}


// animacion canvas central para divinas: cruz grande con glow blanco,
// hexagonos concentricos, god-rays y particulas orbitando. comparte patron
// con animarLegendaria pero en color crema claro (rgba(255,250,180))
function animarDivina() {
    if (colRunaActual !== "divina") return;
    const canvas = document.getElementById("col-canvas");
    const ctx    = canvas.getContext("2d");
    const W = canvas.width, H = canvas.height;
    const cx = W/2, cy = H/2;
    let t = 0;

    function frame() {
        if (colRunaActual !== "divina") return;
        ctx.clearRect(0, 0, W, H);
        t += 0.008;

        // atajo: el color crema se repite tanto que guardo el prefijo en D
        // y solo concateno el alpha al usarlo
        const D = 'rgba(255,250,180,';
        const R = Math.min(W, H) * 0.42;

        // fondo con radial dorado tenue, da la sensacion de aura divina
        // sin invadir toda la pantalla
        const bgG = ctx.createRadialGradient(cx,cy,0,cx,cy,R*1.1);
        bgG.addColorStop(0, 'rgba(255,255,200,0.10)');
        bgG.addColorStop(1, 'rgba(0,0,0,0)');
        ctx.fillStyle = bgG; ctx.fillRect(0,0,W,H);

        // anillo exterior punteado girando lento. el setLineDash([3,16])
        // hace que la linea sea un trocito de 3 con un hueco de 16 --> puntos
        ctx.save(); ctx.translate(cx,cy); ctx.rotate(t*0.25);
        ctx.beginPath(); ctx.arc(0,0,R*1.05,0,Math.PI*2);
        ctx.setLineDash([3,16]); ctx.strokeStyle=D+'0.3)'; ctx.lineWidth=1; ctx.stroke();
        ctx.setLineDash([]); ctx.restore();

        // hexagono grande girando muy despacio. el -PI/6 lo orienta con vertice
        // arriba en vez de plano, que queda mas "corona"
        ctx.save(); ctx.translate(cx,cy); ctx.rotate(t*0.15);
        ctx.beginPath();
        for(let i=0;i<6;i++){
            const a=(i/6)*Math.PI*2 - Math.PI/6;
            i===0 ? ctx.moveTo(Math.cos(a)*R,Math.sin(a)*R) : ctx.lineTo(Math.cos(a)*R,Math.sin(a)*R);
        }
        ctx.closePath();
        ctx.shadowColor='rgba(255,250,180,0.6)'; ctx.shadowBlur=14;
        ctx.strokeStyle=D+'0.85)'; ctx.lineWidth=2.5; ctx.stroke();
        ctx.shadowBlur=0; ctx.restore();

        // hexagono interior mas pequeno, girando al reves para contra-rotacion
        ctx.save(); ctx.translate(cx,cy); ctx.rotate(-t*0.2);
        ctx.beginPath();
        for(let i=0;i<6;i++){
            const a=(i/6)*Math.PI*2;
            i===0 ? ctx.moveTo(Math.cos(a)*R*0.6,Math.sin(a)*R*0.6) : ctx.lineTo(Math.cos(a)*R*0.6,Math.sin(a)*R*0.6);
        }
        ctx.closePath();
        ctx.strokeStyle=D+'0.35)'; ctx.lineWidth=1; ctx.stroke(); ctx.restore();

        // circulo interior punteado contra-rotando tambien
        ctx.save(); ctx.translate(cx,cy); ctx.rotate(-t*0.4);
        ctx.beginPath(); ctx.arc(0,0,R*0.45,0,Math.PI*2);
        ctx.setLineDash([4,12]); ctx.strokeStyle=D+'0.4)'; ctx.lineWidth=1; ctx.stroke();
        ctx.setLineDash([]); ctx.restore();

        // cruz central grande con glow blanco (es la forma icono de la divina).
        // la dibujo con roundRect para esquinas suaves en vez de cantos rectos
        const glowA = 0.85 + 0.15*Math.sin(t*2.5);
        const armV = R*0.52, armH = R*0.38;
        ctx.shadowColor = 'rgba(255,255,240,0.95)'; ctx.shadowBlur = 28;
        ctx.fillStyle = `rgba(255,255,245,${glowA})`;
        ctx.beginPath(); ctx.roundRect(cx-6, cy-armV, 12, armV*2, 6); ctx.fill();
        ctx.beginPath(); ctx.roundRect(cx-armH, cy-14, armH*2, 12, 6); ctx.fill();
        ctx.shadowBlur = 0;

        // orbe en la interseccion de la cruz. 3 colorstops para un falloff suave
        const orbG = ctx.createRadialGradient(cx,cy,0,cx,cy,20);
        orbG.addColorStop(0,'rgba(255,255,255,1)');
        orbG.addColorStop(0.5,'rgba(255,255,200,0.5)');
        orbG.addColorStop(1,'rgba(255,255,150,0)');
        ctx.fillStyle = orbG; ctx.beginPath(); ctx.arc(cx,cy,20,0,Math.PI*2); ctx.fill();

        // particulas orbitando el centro. 6 distribuidas en angulo, con un
        // pulso de alpha desfasado por indice para que no palpiten sincronas
        for(let i=0;i<6;i++){
            const a=t*0.6+(i/6)*Math.PI*2;
            const pr=R*0.74;
            ctx.fillStyle=D+(0.5+Math.sin(t*2+i)*0.35)+')';
            ctx.beginPath(); ctx.arc(cx+Math.cos(a)*pr, cy+Math.sin(a)*pr, 2.5,0,Math.PI*2); ctx.fill();
        }

        // OJO: esto deberia ser colRaf (como en las demas animaciones),
        // pero tira de una variable "animColRaf" que no existe en ningun
        // sitio. funciona porque js crea la global implicita y el frame se
        // autocancela por el if colRunaActual !== "divina", pero si algun
        // dia cambio el idioma a strict o refactorizo, peta. TODO arreglar
        animColRaf = requestAnimationFrame(frame);
    }
    frame();
}


// animacion canvas central para miticas: mismo patron que legendaria pero
// en rojo (#ff2244), mas rapido y con flechas cardinales en el anillo
// exterior en lugar de marcas radiales. el rojo es el color "peligro" /
// "raro" del juego (mitica es una runa muy peligrosa de conseguir bien)
function animarMitica() {
    const canvas = document.getElementById("col-canvas");
    if (!canvas) return;
    const ctx = canvas.getContext("2d");
    let t = 0;
    const W = canvas.width  = canvas.offsetWidth  || 400;
    const H = canvas.height = canvas.offsetHeight || 360;
    const cx = W / 2, cy = H / 2;

    function frame() {
        if (colRunaActual !== "mitica") return;
        ctx.clearRect(0, 0, W, H);
        t += 0.018;  // mas rapido que legendaria (0.012) porque mitica es mas "urgente"

        const R1 = Math.min(W, H) * 0.38;
        const R2 = R1 * 0.72;
        const R3 = R1 * 0.38;

        // pulso en comun para todo el frame. abs(sin) para que solo sea
        // positivo --> la rareza respira sin apagarse del todo
        const pulsoA = 0.6 + 0.35 * Math.abs(Math.sin(t * 1.5));

        // anillo exterior + flechas cardinales (norte, sur, este, oeste)
        ctx.save();
        ctx.translate(cx, cy);
        ctx.rotate(t * 0.4);
        ctx.strokeStyle = `rgba(255,34,68,${pulsoA})`;
        ctx.lineWidth = 1.5;
        ctx.shadowColor = "#ff2244"; ctx.shadowBlur = 30;
        ctx.beginPath(); ctx.arc(0, 0, R1, 0, Math.PI * 2); ctx.stroke();
        // las 4 flechas apuntan hacia fuera. rotar en cada iteracion y
        // dibujar el triangulo "apuntando arriba" es mas simple que calcular
        // coordenadas de triangulo rotado para cada direccion
        const dirs = [0, Math.PI/2, Math.PI, Math.PI*1.5];
        dirs.forEach(a => {
            ctx.save();
            ctx.rotate(a);
            ctx.beginPath();
            ctx.moveTo(0, -(R1 + 12));
            ctx.lineTo(-5, -(R1 + 4));
            ctx.lineTo(5,  -(R1 + 4));
            ctx.closePath();
            ctx.fillStyle = `rgba(255,34,68,${pulsoA})`;
            ctx.fill();
            ctx.restore();
        });
        ctx.restore();

        // anillo medio contra-rotando con marcas diagonales (4 largas + 4 cortas,
        // alternando con i%2)
        ctx.save();
        ctx.translate(cx, cy);
        ctx.rotate(-t * 1.1);
        ctx.strokeStyle = `rgba(255,34,68,${pulsoA * 0.7})`;
        ctx.lineWidth = 1;
        ctx.shadowColor = "#ff2244"; ctx.shadowBlur = 15;
        ctx.beginPath(); ctx.arc(0, 0, R2, 0, Math.PI * 2); ctx.stroke();
        for (let i = 0; i < 8; i++) {
            const a = (i / 8) * Math.PI * 2;
            const lenMark = i % 2 === 0 ? 16 : 8;
            ctx.beginPath();
            ctx.moveTo(Math.cos(a) * (R2 - lenMark), Math.sin(a) * (R2 - lenMark));
            ctx.lineTo(Math.cos(a) * R2, Math.sin(a) * R2);
            ctx.stroke();
        }
        ctx.restore();

        // anillo interior + 4 diagonales desde el centro, giro mas rapido
        // todavia (t*2) para el efecto "motor caliente"
        ctx.save();
        ctx.translate(cx, cy);
        ctx.rotate(t * 2);
        ctx.strokeStyle = `rgba(255,34,68,${0.8 + 0.2 * Math.sin(t * 5)})`;
        ctx.lineWidth = 2;
        ctx.shadowColor = "#ff2244"; ctx.shadowBlur = 35;
        ctx.beginPath(); ctx.arc(0, 0, R3, 0, Math.PI * 2); ctx.stroke();
        ctx.lineWidth = 1.5;
        for (let i = 0; i < 4; i++) {
            const a = (i / 4) * Math.PI * 2 + Math.PI / 4;
            ctx.beginPath();
            ctx.moveTo(0, 0);
            ctx.lineTo(Math.cos(a) * R3, Math.sin(a) * R3);
            ctx.stroke();
        }
        ctx.restore();

        // nucleo rojo pulsante (igual que legendaria pero mas agresivo)
        const p2 = 6 + 5 * Math.abs(Math.sin(t * 3));
        const gR = ctx.createRadialGradient(cx, cy, 0, cx, cy, p2 * 4);
        gR.addColorStop(0, `rgba(255,34,68,${pulsoA})`);
        gR.addColorStop(1, "rgba(255,34,68,0)");
        ctx.fillStyle = gR;
        ctx.beginPath(); ctx.arc(cx, cy, p2 * 4, 0, Math.PI * 2); ctx.fill();
        ctx.fillStyle = "#ff2244";
        ctx.shadowBlur = 30; ctx.shadowColor = "#ff2244";
        ctx.beginPath(); ctx.arc(cx, cy, p2, 0, Math.PI * 2); ctx.fill();
        ctx.shadowBlur = 0;

        colRaf = requestAnimationFrame(frame);
    }
    colRaf = requestAnimationFrame(frame);
}


// mini canvases: los iconos 32x32 que tienen los botones de runas comunes
// (comun, poco_comun, rara, epica) en la seccion coleccion. se arrancan al
// entrar en coleccion y se animan siempre (no necesitan hover), aunque los
// comunes tampoco tienen mucha gracia (cuadradito gris apagado, a proposito)
function iniciarMiniCanvas() {
    document.querySelectorAll(".col-comun-canvas").forEach(c => {
        const rareza  = c.dataset.rareza;
        const activa  = c.dataset.activa === "1";
        const ctx     = c.getContext("2d");
        const W = 32, H = 32, cx = 16, cy = 16;
        let t = 0;

        function frame() {
            ctx.clearRect(0, 0, W, H);
            t += 0.04;

            // si la runa esta bloqueada --> pinto un candado gris simple y
            // listo, no necesitamos gastar cpu con animaciones de algo que
            // el jugador no tiene
            if (!activa) {
                ctx.strokeStyle = "rgba(120,120,120,0.4)";
                ctx.lineWidth = 1.2;
                ctx.beginPath(); ctx.arc(cx, cy - 3, 5, Math.PI, 0); ctx.stroke();
                ctx.strokeRect(cx - 5, cy, 10, 8);
                requestAnimationFrame(frame);
                return;
            }

            if (rareza === "comun") {
                // comun apagado a proposito: solo un cuadradito gris dim,
                // sin movimiento. la idea es que el jugador entienda que
                // esto es lo mas basico sin tener que leerlo
                ctx.fillStyle = "rgba(140,140,140,0.18)";
                ctx.fillRect(4, 4, 24, 24);
                ctx.strokeStyle = "rgba(140,140,140,0.3)";
                ctx.lineWidth = 0.8;
                ctx.strokeRect(4, 4, 24, 24);

            } else if (rareza === "poco_comun") {
                // verde: 3 circulos concentricos con glow sutil, alpha
                // desfasado por i para que palpiten en cascada
                const radios = [4, 7, 10];
                radios.forEach((r, i) => {
                    const a = 0.3 + i * 0.15;
                    ctx.strokeStyle = `rgba(50,200,80,${a + 0.1 * Math.sin(t + i)})`;
                    ctx.lineWidth = 1;
                    ctx.shadowColor = "#32c850";
                    ctx.shadowBlur  = 4;
                    ctx.beginPath(); ctx.arc(cx, cy, r, 0, Math.PI * 2); ctx.stroke();
                });
                ctx.shadowBlur = 0;

            } else if (rareza === "rara") {
                // azul: un hexagono simple que gira. version mini del
                // hexagono de raraCanvas, menos detallado porque a 32x32
                // los detalles no se ven
                ctx.save();
                ctx.translate(cx, cy);
                ctx.rotate(t * 0.5);
                ctx.strokeStyle = `rgba(60,140,255,${0.6 + 0.3 * Math.sin(t*2)})`;
                ctx.lineWidth = 1.2;
                ctx.shadowColor = "#3c8cff"; ctx.shadowBlur = 8;
                ctx.beginPath();
                for (let i = 0; i < 6; i++) {
                    const a = (i / 6) * Math.PI * 2;
                    i === 0 ? ctx.moveTo(Math.cos(a)*10, Math.sin(a)*10)
                            : ctx.lineTo(Math.cos(a)*10, Math.sin(a)*10);
                }
                ctx.closePath(); ctx.stroke();
                ctx.shadowBlur = 0;
                ctx.restore();

            } else if (rareza === "epica") {
                // morada: estrella de 4 puntas girando rapido (t*3.5). el
                // morado + giro rapido transmiten "energica, peligrosa"
                ctx.save();
                ctx.translate(cx, cy);
                ctx.rotate(t * 3.5);
                ctx.strokeStyle = `rgba(160,80,255,${0.7 + 0.25 * Math.sin(t*4)})`;
                ctx.lineWidth = 1.5;
                ctx.shadowColor = "#a050ff"; ctx.shadowBlur = 12;
                for (let i = 0; i < 4; i++) {
                    const a = (i / 4) * Math.PI * 2;
                    ctx.beginPath();
                    ctx.moveTo(0, 0);
                    ctx.lineTo(Math.cos(a)*10, Math.sin(a)*10);
                    ctx.stroke();
                }
                ctx.shadowBlur = 0;
                ctx.restore();
            }

            // mini canvases usan rAF sin guardarlo en colRaf porque son N
            // loops independientes (uno por boton) y no se paran juntos.
            // se detienen solos cuando la seccion se desmonta (el canvas
            // ya no existe en dom)
            requestAnimationFrame(frame);
        }
        requestAnimationFrame(frame);
    });
}


// animaciones canvas central para rarezas comunes (no especiales). mas
// modestas que las especiales: sin god-rays, sin particulas complejas,
// solo geometria basica. la idea es que el jugador note diferencia real
// de "calidad" entre una runa comun y una eterna
function animarPocoComunCanvas() {
    const canvas = document.getElementById("col-canvas");
    if (!canvas) return;
    const ctx = canvas.getContext("2d");
    let t = 0;
    const W = canvas.width  = canvas.offsetWidth  || 400;
    const H = canvas.height = canvas.offsetHeight || 360;
    const cx = W/2, cy = H/2;

    // una sola funcion cubre "comun" y "poco_comun" porque comparten guard
    // (if!=comun && !=poco_comun return). dentro elijo que pintar por
    // colRunaActual. ambas son lo mas basico y no me merecia tener dos
    // funciones casi identicas
    function frame() {
        if (colRunaActual !== "poco_comun" && colRunaActual !== "comun") return;
        ctx.clearRect(0, 0, W, H);
        t += 0.012;

        if (colRunaActual === "comun") {
            // comun: cuadrado gris apagado con la palabra COMUN dentro. nada
            // mas. intencional, el jugador debe ver que esto es nada especial
            const alpha = 0.08 + 0.04 * Math.sin(t*2);
            const size  = Math.min(W,H) * 0.25;
            ctx.fillStyle   = `rgba(160,160,160,${alpha})`;
            ctx.strokeStyle = `rgba(160,160,160,${alpha * 4})`;
            ctx.lineWidth   = 1;
            ctx.fillRect(cx - size/2, cy - size/2, size, size);
            ctx.strokeRect(cx - size/2, cy - size/2, size, size);
            ctx.fillStyle = `rgba(140,140,140,0.25)`;
            ctx.font = "11px Oswald, sans-serif";
            ctx.textAlign = "center";
            ctx.fillText("COMÚN", cx, cy + 3);

        } else {
            // poco_comun: 3 anillos verdes concentricos expandiendose en
            // cascada. desfase por i*1.2 hace que palpiten uno detras de
            // otro, no al unisono
            const radios = [
                Math.min(W,H) * 0.12,
                Math.min(W,H) * 0.22,
                Math.min(W,H) * 0.32
            ];
            radios.forEach((r, i) => {
                const phase = t * 0.8 + i * 1.2;
                const alpha = 0.4 + 0.3 * Math.sin(phase);
                ctx.strokeStyle = `rgba(50,200,80,${alpha})`;
                ctx.lineWidth   = 1.5 - i * 0.3;
                ctx.shadowColor = "#32c850";
                ctx.shadowBlur  = 12 - i * 2;
                ctx.beginPath(); ctx.arc(cx, cy, r, 0, Math.PI * 2); ctx.stroke();
            });
            ctx.shadowBlur = 0;
            // punto central verde pulsante
            ctx.fillStyle = `rgba(50,200,80,${0.7 + 0.3*Math.sin(t*3)})`;
            ctx.shadowColor = "#32c850"; ctx.shadowBlur = 15;
            ctx.beginPath(); ctx.arc(cx, cy, 4, 0, Math.PI*2); ctx.fill();
            ctx.shadowBlur = 0;
        }
        colRaf = requestAnimationFrame(frame);
    }
    colRaf = requestAnimationFrame(frame);
}


// rara: hexagonos azules (exterior + interior), centro pulsante. en azul
// marino (#3c8cff). version mas completa que la mini de arriba porque aqui
// tengo el canvas grande para dar detalle
function animarRaraCanvas() {
    const canvas = document.getElementById("col-canvas");
    if (!canvas) return;
    const ctx = canvas.getContext("2d");
    let t = 0;
    const W = canvas.width  = canvas.offsetWidth  || 400;
    const H = canvas.height = canvas.offsetHeight || 360;
    const cx = W/2, cy = H/2;
    const R  = Math.min(W,H) * 0.30;

    function frame() {
        if (colRunaActual !== "rara") return;
        ctx.clearRect(0, 0, W, H);
        t += 0.01;

        // hexagono grande + hexagono chiquito dentro contra-rotando. el
        // interior esta rotado PI/6 adicionales para que sus vertices caigan
        // entre los del exterior (efecto estrella de david)
        ctx.save();
        ctx.translate(cx, cy);
        ctx.rotate(t * 0.4);
        ctx.strokeStyle = `rgba(60,140,255,${0.5 + 0.25*Math.sin(t*2)})`;
        ctx.lineWidth   = 1.5;
        ctx.shadowColor = "#3c8cff"; ctx.shadowBlur = 20;
        ctx.beginPath();
        for (let i = 0; i < 6; i++) {
            const a = (i/6)*Math.PI*2;
            i===0 ? ctx.moveTo(Math.cos(a)*R, Math.sin(a)*R)
                  : ctx.lineTo(Math.cos(a)*R, Math.sin(a)*R);
        }
        ctx.closePath(); ctx.stroke();
        ctx.rotate(-t * 1.1);
        ctx.strokeStyle = `rgba(60,140,255,${0.3 + 0.2*Math.sin(t*3)})`;
        ctx.lineWidth = 0.8;
        ctx.shadowBlur = 8;
        ctx.beginPath();
        for (let i = 0; i < 6; i++) {
            const a = (i/6)*Math.PI*2 + Math.PI/6;
            i===0 ? ctx.moveTo(Math.cos(a)*R*0.5, Math.sin(a)*R*0.5)
                  : ctx.lineTo(Math.cos(a)*R*0.5, Math.sin(a)*R*0.5);
        }
        ctx.closePath(); ctx.stroke();
        ctx.shadowBlur = 0;
        ctx.restore();

        const p = 5 + 3*Math.sin(t*4);
        ctx.fillStyle = `rgba(60,140,255,${0.8 + 0.2*Math.sin(t*3)})`;
        ctx.shadowColor = "#3c8cff"; ctx.shadowBlur = 20;
        ctx.beginPath(); ctx.arc(cx, cy, p, 0, Math.PI*2); ctx.fill();
        ctx.shadowBlur = 0;

        colRaf = requestAnimationFrame(frame);
    }
    colRaf = requestAnimationFrame(frame);
}


// epica: estrella de 4 puntas morada girando rapido (t*3 es 3x mas rapido
// que legendaria). el morado (#a050ff) y la velocidad transmiten energia
// violenta, el salto visual respecto a rara deberia sentirse claramente
function animarEpicaCanvas() {
    const canvas = document.getElementById("col-canvas");
    if (!canvas) return;
    const ctx = canvas.getContext("2d");
    let t = 0;
    const W = canvas.width  = canvas.offsetWidth  || 400;
    const H = canvas.height = canvas.offsetHeight || 360;
    const cx = W/2, cy = H/2;
    const R  = Math.min(W,H) * 0.28;

    function frame() {
        if (colRunaActual !== "epica") return;
        ctx.clearRect(0, 0, W, H);
        t += 0.03;  // rapida (0.03 vs 0.012 de legendaria)

        ctx.save();
        ctx.translate(cx, cy);
        ctx.rotate(t * 3);
        ctx.strokeStyle = `rgba(160,80,255,${0.7 + 0.25*Math.sin(t*5)})`;
        ctx.lineWidth = 2;
        ctx.shadowColor = "#a050ff"; ctx.shadowBlur = 30;
        // 4 brazos saliendo del centro hacia los 4 angulos cardinales.
        // no dibujo una estrella cerrada, son 4 lineas radiales
        for (let i = 0; i < 4; i++) {
            const a = (i/4)*Math.PI*2;
            ctx.beginPath();
            ctx.moveTo(Math.cos(a)*R*0.15, Math.sin(a)*R*0.15);
            ctx.lineTo(Math.cos(a)*R, Math.sin(a)*R);
            ctx.stroke();
        }
        ctx.strokeStyle = `rgba(160,80,255,${0.4 + 0.2*Math.sin(t*4)})`;
        ctx.lineWidth = 1;
        ctx.shadowBlur = 15;
        ctx.beginPath(); ctx.arc(0, 0, R*0.55, 0, Math.PI*2); ctx.stroke();
        ctx.shadowBlur = 0;
        ctx.restore();

        const p = 4 + 3*Math.abs(Math.sin(t*5));
        ctx.fillStyle = `rgba(160,80,255,0.9)`;
        ctx.shadowColor = "#a050ff"; ctx.shadowBlur = 25;
        ctx.beginPath(); ctx.arc(cx, cy, p, 0, Math.PI*2); ctx.fill();
        ctx.shadowBlur = 0;

        colRaf = requestAnimationFrame(frame);
    }
    colRaf = requestAnimationFrame(frame);
}


// boton "ver animacion completa" del panel de coleccion. cambia el iframe
// al html _animacion de la rareza, que es la version larga con mas efectos
// (y musica si la rareza la tiene). se ejecuta siempre, ignora el toggle
// ANIM ON/OFF porque el jugador lo pidio explicitamente con el boton
function verAnimacionCompleta() {
    if (!colRunaActual) return;
    const iframe = document.getElementById("col-iframe");
    if (iframe) iframe.src = "RUNAS_HTML/RUNAS_ANIMADAS/" + colRunaActual + ".html";
}


// neon en los botones especiales de coleccion. cada boton tiene su propio
// canvas dentro (ver .col-btn-neon) que se activa solo con hover. asi
// mientras no pases el raton por encima no gastamos cpu en animar botones
// que no estas mirando
//
// hay 4 modos de dibujo segun rareza, todos dentro del mismo dibujar():
//   - legendaria/mitica --> luces corriendo por el perimetro del boton
//   - divina            --> aura respiratoria + god-rays + particulas ascendentes
//   - eterna            --> nebulosa + geometria sagrada lenta + polvo cosmico
//
// cada boton mantiene su propio estado (raf, t, isHover, arrays de particulas)
// en el closure, por eso creo los pools una vez por boton al iterar
function iniciarNeonBotones() {
    document.querySelectorAll(".col-btn-neon").forEach(canvas => {
        const rareza = canvas.dataset.rareza;
        const btn    = canvas.closest(".col-runa-btn");
        // si la runa esta bloqueada, el boton no tiene neon (el css ya lo
        // pone gris). me ahorro arrancar el rAF
        if (!btn || btn.classList.contains("bloqueada")) return;

        // estado local de este boton. isHover controla si dibujo o solo
        // reprogramo el rAF vacio. flashMitica es el trigger del flash de
        // fondo que se alterna cada ~0.5s para la rareza mitica
        let raf = null, t = 0, isHover = false, flashMitica = false;

        // el canvas se autoajusta al tamano del boton. lo reengancho a
        // window resize por si el jugador cambia de tamano de ventana
        function resize() {
            canvas.width  = btn.offsetWidth;
            canvas.height = btn.offsetHeight;
        }
        resize();
        const ctx = canvas.getContext("2d");

        // pool de particulas para la eterna. 18 cuerpecitos, cada uno con
        // angulo, velocidad, radio, alpha, hue aleatorio (rango 230-310, que
        // es azul-morado-magenta), y un array de trails para el rastro. los
        // creo una sola vez al arrancar para no alocar en cada frame
        const eternaParts = Array.from({length: 18}, () => ({
            angle: Math.random() * Math.PI * 2,
            radius: 0,
            speed: (Math.random() * 0.005 + 0.002) * (Math.random() > 0.5 ? 1 : -1),
            r: 0.8 + Math.random() * 1.4,
            alpha: 0.2 + Math.random() * 0.5,
            hue: 230 + Math.random() * 80,
            orbitFrac: 0.25 + Math.random() * 0.65,
            trail: [],
        }));

        // pool de particulas para la divina. 14 fragmentos de luz que suben
        // hacia arriba. born=false significa "slot libre". spawn las rellena
        // al entrar hover o cada ~40ms mientras hay hover
        const divinaParts = Array.from({length: 14}, () => ({
            x: 0, y: 0,
            vx: 0, vy: 0,
            alpha: 0,
            r: 0,
            born: false,
            hue: 45 + Math.random() * 25,
        }));
        let divPartT = 0;

        // rellenar las particulas muertas. se invoca en cada "tick entero"
        // del tiempo (divPartT cruza un entero), asi se generan en oleadas
        // pequenas en lugar de todas a la vez
        function spawnDivinaPart(W, H, cx, cy) {
            divinaParts.forEach((p, i) => {
                if (!p.born || p.alpha <= 0) {
                    p.x  = cx + (Math.random() - 0.5) * W * 0.7;
                    p.y  = cy + (Math.random() - 0.5) * H * 0.5 + H * 0.15;
                    p.vx = (Math.random() - 0.5) * 0.4;
                    p.vy = -(0.4 + Math.random() * 0.8);  // siempre hacia arriba
                    p.alpha = 0.6 + Math.random() * 0.4;
                    p.r   = 1 + Math.random() * 1.8;
                    p.born = true;
                    p.hue = 40 + Math.random() * 30;
                }
            });
        }

        function dibujar() {
            const W = canvas.width, H = canvas.height;
            const cx = W / 2, cy = H / 2;
            ctx.clearRect(0, 0, W, H);

            // sin hover, solo reprogramo el rAF pero no dibujo. cuesta
            // casi nada mantener el loop vivo y es mas simple que montar/
            // desmontar rAFs con cada mouseenter/leave
            if (!isHover) { raf = requestAnimationFrame(dibujar); return; }
            t += 0.018;

            // rareza legendaria o mitica: luces corriendo por el perimetro
            // del boton. la tecnica es parametrizar la posicion por "fraccion
            // de perimetro recorrido" y convertirla a (x,y) segun en que
            // lado (top, derecha, bottom, izquierda) cae esa posicion
            if (rareza === "legendaria" || rareza === "mitica") {
                const color  = rareza === "mitica" ? "#ff2244" : "#ffd700";
                const shadow = rareza === "mitica" ? "rgba(255,34,68,0.8)" : "rgba(255,215,0,0.8)";
                const rgb    = rareza === "mitica" ? "255,34,68" : "255,215,0";
                const perimetro = 2 * (W + H);
                // 2 luces corriendo a la vez, desfasadas 0.5 (mitad del
                // perimetro). mitica va 40% mas rapido (t*1.4)
                for (let i = 0; i < 2; i++) {
                    const offset = (t * (rareza === "mitica" ? 1.4 : 1) + i * 0.5) % 1;
                    const pos    = offset * perimetro;
                    // mapear pos (0 a perimetro) al punto (x,y) del borde.
                    // pos<W = borde superior, pos<W+H = derecho, etc
                    let x, y;
                    if      (pos < W)          { x = pos;             y = 0; }
                    else if (pos < W + H)       { x = W;               y = pos - W; }
                    else if (pos < 2*W + H)     { x = W-(pos-W-H);     y = H; }
                    else                        { x = 0;               y = H-(pos-2*W-H); }
                    ctx.save();
                    ctx.shadowColor = shadow; ctx.shadowBlur = 18;
                    ctx.fillStyle = color;
                    ctx.beginPath(); ctx.arc(x, y, 3, 0, Math.PI*2); ctx.fill();
                    ctx.shadowBlur = 0;
                    // trail: 18 puntos detras de la luz, cada uno mas palido
                    // y mas pequeno. el offset -s*0.004 los separa en el tiempo
                    for (let s = 1; s <= 18; s++) {
                        const p2 = ((offset - s * 0.004 + 1) % 1) * perimetro;
                        let sx, sy;
                        if      (p2 < W)        { sx = p2;             sy = 0; }
                        else if (p2 < W+H)      { sx = W;              sy = p2-W; }
                        else if (p2 < 2*W+H)    { sx = W-(p2-W-H);     sy = H; }
                        else                    { sx = 0;              sy = H-(p2-2*W-H); }
                        ctx.fillStyle = `rgba(${rgb},${(1-s/18)*0.4})`;
                        ctx.beginPath(); ctx.arc(sx, sy, 2*(1-s/18)+0.5, 0, Math.PI*2); ctx.fill();
                    }
                    ctx.restore();
                }
                // flash mitica: cuando el pulso sube del 85%, anado la clase
                // mitica-flash al boton para que el css pinte un fondo rojo
                // breve. solo se activa/desactiva al cruzar el umbral, no
                // cada frame, si no quedaria como discoteca
                if (rareza === "mitica") {
                    const fc = Math.abs(Math.sin(t * 2.2));
                    if (fc > 0.85 && !flashMitica) { flashMitica = true;  btn.classList.add("mitica-flash"); }
                    else if (fc <= 0.85 && flashMitica) { flashMitica = false; btn.classList.remove("mitica-flash"); }
                }

            // rareza divina: aura respirando + 8 god-rays girando + fragmentos
            // ascendentes. el conjunto da la sensacion "divinidad brillando"
            } else if (rareza === "divina") {
                const breath = 0.5 + 0.5 * Math.sin(t * 1.4);
                const breath2 = 0.5 + 0.5 * Math.sin(t * 1.4 + Math.PI);  // en oposicion al breath

                // ondas de aura concentricas expandiendose. 3 ondas desfasadas
                // 1/3. cada onda empieza pequena, se expande hasta maxR y
                // se desvanece. el modulo %1 reinicia cada ciclo
                for (let wi = 0; wi < 3; wi++) {
                    const phase = (t * 0.35 + wi / 3) % 1;
                    const maxR  = Math.min(W, H) * 0.62;
                    const r     = phase * maxR;
                    const alpha = (1 - phase) * 0.22 * (0.6 + 0.4 * Math.sin(t * 2));
                    ctx.save();
                    ctx.globalAlpha = alpha;
                    ctx.strokeStyle = `rgba(255,255,210,1)`;
                    ctx.lineWidth   = 1.2 * (1 - phase * 0.5);
                    ctx.shadowColor = 'rgba(255,250,180,0.6)';
                    ctx.shadowBlur  = 12;
                    ctx.beginPath(); ctx.arc(cx, cy, r, 0, Math.PI * 2); ctx.stroke();
                    ctx.restore();
                }

                // god-rays: 8 rayos emanando del centro con gradient que va
                // de opaco a transparente. cada uno tiene longitud y grosor
                // que oscilan con sinusoidal desfasada por indice
                const numRays = 8;
                for (let ri = 0; ri < numRays; ri++) {
                    const ang    = (ri / numRays) * Math.PI * 2 + t * 0.08;
                    const rayLen = Math.min(W, H) * (0.38 + 0.12 * Math.sin(t * 1.8 + ri));
                    const rayA   = (0.06 + 0.04 * Math.sin(t * 2.5 + ri * 1.3)) * breath;
                    const grad   = ctx.createLinearGradient(cx, cy,
                        cx + Math.cos(ang) * rayLen, cy + Math.sin(ang) * rayLen);
                    grad.addColorStop(0,   `rgba(255,255,220,${rayA * 1.8})`);
                    grad.addColorStop(0.4, `rgba(255,250,180,${rayA * 0.8})`);
                    grad.addColorStop(1,   'rgba(255,245,160,0)');
                    ctx.save();
                    ctx.globalAlpha = 1;
                    ctx.strokeStyle = grad;
                    ctx.lineWidth   = 1.5 + Math.sin(t * 3 + ri) * 0.7;
                    ctx.shadowColor = 'rgba(255,250,180,0.4)';
                    ctx.shadowBlur  = 8;
                    ctx.beginPath();
                    ctx.moveTo(cx, cy);
                    ctx.lineTo(cx + Math.cos(ang) * rayLen, cy + Math.sin(ang) * rayLen);
                    ctx.stroke();
                    ctx.restore();
                }

                // halo central: un radial gradient pulsante que respira con
                // el breath. es el "corazon" visual de la divinidad
                const haloR = Math.min(W, H) * 0.18 * (0.88 + 0.12 * breath);
                ctx.save();
                const hg = ctx.createRadialGradient(cx, cy, 0, cx, cy, haloR * 2.5);
                hg.addColorStop(0,   `rgba(255,255,240,${0.18 + 0.12 * breath})`);
                hg.addColorStop(0.5, `rgba(255,250,180,${0.07 + 0.05 * breath})`);
                hg.addColorStop(1,   'rgba(255,245,150,0)');
                ctx.fillStyle = hg;
                ctx.beginPath(); ctx.arc(cx, cy, haloR * 2.5, 0, Math.PI * 2); ctx.fill();
                ctx.restore();

                // micro fragmentos ascendentes. cuando divPartT cruza un
                // entero (cada ~25 frames) respawn las que esten muertas.
                // sin esto, tras unos segundos el pool se queda vacio
                divPartT += 0.04;
                if (Math.floor(divPartT) > Math.floor(divPartT - 0.04)) {
                    spawnDivinaPart(W, H, cx, cy);
                }
                divinaParts.forEach(p => {
                    if (!p.born || p.alpha <= 0) return;
                    p.x    += p.vx;
                    p.y    += p.vy;
                    p.alpha -= 0.008;
                    p.vy   *= 0.99;  // drag, frena el ascenso poco a poco
                    if (p.alpha <= 0) { p.born = false; return; }
                    ctx.save();
                    ctx.globalAlpha = p.alpha;
                    const fg = ctx.createRadialGradient(p.x, p.y, 0, p.x, p.y, p.r * 3);
                    fg.addColorStop(0, `rgba(255,255,220,1)`);
                    fg.addColorStop(1, 'rgba(255,245,150,0)');
                    ctx.fillStyle   = fg;
                    ctx.shadowColor = `hsl(${p.hue},90%,90%)`;
                    ctx.shadowBlur  = 10;
                    ctx.beginPath(); ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2); ctx.fill();
                    ctx.restore();
                });

                // borde con glow sutil respirando. es el "marco" del boton
                const borderA = 0.15 + 0.1 * breath;
                ctx.save();
                ctx.strokeStyle = `rgba(255,255,210,${borderA})`;
                ctx.lineWidth   = 1;
                ctx.shadowColor = 'rgba(255,250,180,0.5)';
                ctx.shadowBlur  = 10;
                ctx.strokeRect(1, 1, W - 2, H - 2);
                ctx.restore();

            // rareza eterna: la mas elaborada. geometria sagrada (anillo
            // punteado + hexagono + anillo interior + dos triangulos
            // entrelazados), nucleo pulsante, y polvo cosmico con trails
            // orbitando. todo muy lento (t*0.04, 0.08, 0.12) para sensacion
            // de escala cosmica, no es la misma "energia" que las demas
            } else if (rareza === "eterna") {
                const R = Math.min(W, H) * 0.42;
                const breath = 0.5 + 0.5 * Math.sin(t * 0.9);

                // nebulosa de fondo: morado muy tenue con fade a transparente
                const nbg = ctx.createRadialGradient(cx, cy, 0, cx, cy, R * 1.1);
                nbg.addColorStop(0, `rgba(100,60,200,${0.06 + 0.03 * breath})`);
                nbg.addColorStop(0.6, `rgba(50,30,120,0.03)`);
                nbg.addColorStop(1, 'rgba(0,0,0,0)');
                ctx.fillStyle = nbg; ctx.fillRect(0, 0, W, H);

                // anillo exterior punteado (setLineDash 2,14) rotando super
                // lento. solo se intuye
                ctx.save();
                ctx.translate(cx, cy); ctx.rotate(t * 0.04);
                ctx.setLineDash([2, 14]);
                ctx.strokeStyle = `rgba(140,100,255,${0.25 + 0.1 * breath})`;
                ctx.lineWidth = 0.8;
                ctx.beginPath(); ctx.arc(0, 0, R * 1.08, 0, Math.PI * 2); ctx.stroke();
                ctx.setLineDash([]);
                ctx.restore();

                // hexagono girando despacio. el -PI/6 orienta con vertice
                // arriba (corona)
                ctx.save();
                ctx.translate(cx, cy); ctx.rotate(t * 0.08);
                ctx.strokeStyle = `rgba(160,110,255,${0.45 + 0.15 * breath})`;
                ctx.lineWidth = 1.2;
                ctx.shadowColor = 'rgba(160,100,255,0.4)'; ctx.shadowBlur = 10;
                ctx.beginPath();
                for (let i = 0; i < 6; i++) {
                    const a = (i / 6) * Math.PI * 2 - Math.PI / 6;
                    i === 0 ? ctx.moveTo(Math.cos(a)*R*0.82, Math.sin(a)*R*0.82)
                            : ctx.lineTo(Math.cos(a)*R*0.82, Math.sin(a)*R*0.82);
                }
                ctx.closePath(); ctx.stroke();
                ctx.shadowBlur = 0;
                ctx.restore();

                // anillo interno con 8 marcas radiales, contra-rotando
                ctx.save();
                ctx.translate(cx, cy); ctx.rotate(-t * 0.12);
                ctx.strokeStyle = `rgba(180,140,255,${0.5 + 0.2 * breath})`;
                ctx.lineWidth   = 1;
                ctx.shadowColor = 'rgba(180,130,255,0.5)'; ctx.shadowBlur = 12;
                ctx.beginPath(); ctx.arc(0, 0, R * 0.52, 0, Math.PI * 2); ctx.stroke();
                for (let i = 0; i < 8; i++) {
                    const a = (i / 8) * Math.PI * 2;
                    const ri = R * 0.52;
                    ctx.beginPath();
                    ctx.moveTo(Math.cos(a) * (ri - 5), Math.sin(a) * (ri - 5));
                    ctx.lineTo(Math.cos(a) * (ri + 5), Math.sin(a) * (ri + 5));
                    ctx.stroke();
                }
                ctx.shadowBlur = 0;
                ctx.restore();

                // dos triangulos entrelazados en el centro (estrella de
                // david / simbologia esoterica). el segundo esta rotado PI
                // respecto al primero, ambos dentro del mismo save
                ctx.save();
                ctx.translate(cx, cy); ctx.rotate(t * 0.06);
                ctx.strokeStyle = `rgba(200,170,255,${0.55 + 0.2 * breath})`;
                ctx.lineWidth   = 1;
                ctx.shadowColor = 'rgba(190,150,255,0.4)'; ctx.shadowBlur = 8;
                ctx.beginPath();
                for (let i = 0; i < 3; i++) {
                    const a = (i / 3) * Math.PI * 2 - Math.PI / 6;
                    i === 0 ? ctx.moveTo(Math.cos(a)*R*0.32, Math.sin(a)*R*0.32)
                            : ctx.lineTo(Math.cos(a)*R*0.32, Math.sin(a)*R*0.32);
                }
                ctx.closePath(); ctx.stroke();
                ctx.rotate(Math.PI);
                ctx.beginPath();
                for (let i = 0; i < 3; i++) {
                    const a = (i / 3) * Math.PI * 2 - Math.PI / 6;
                    i === 0 ? ctx.moveTo(Math.cos(a)*R*0.32, Math.sin(a)*R*0.32)
                            : ctx.lineTo(Math.cos(a)*R*0.32, Math.sin(a)*R*0.32);
                }
                ctx.closePath(); ctx.stroke();
                ctx.shadowBlur = 0;
                ctx.restore();

                // nucleo central: halo grande morado con punto blanco dentro.
                // shadowBlur que respira con el breath para que el glow
                // tambien "respire"
                const nr = 4 + 3 * breath;
                ctx.save();
                const ng2 = ctx.createRadialGradient(cx, cy, 0, cx, cy, nr * 4);
                ng2.addColorStop(0,   `rgba(220,190,255,${0.7 + 0.3 * breath})`);
                ng2.addColorStop(0.4, `rgba(160,110,255,${0.2 + 0.15 * breath})`);
                ng2.addColorStop(1,   'rgba(80,40,180,0)');
                ctx.fillStyle   = ng2;
                ctx.shadowColor = 'rgba(200,160,255,0.8)';
                ctx.shadowBlur  = 18 + 10 * breath;
                ctx.beginPath(); ctx.arc(cx, cy, nr * 4, 0, Math.PI * 2); ctx.fill();
                ctx.fillStyle   = 'rgba(240,220,255,0.95)';
                ctx.shadowBlur  = 20;
                ctx.beginPath(); ctx.arc(cx, cy, nr * 0.5, 0, Math.PI * 2); ctx.fill();
                ctx.restore();

                // polvo estelar con trails largos (22 puntos de historia).
                // orbitR se achata en el eje Y (orbitR*0.55) para que las
                // orbitas sean elipticas y no circulos perfectos
                eternaParts.forEach(p => {
                    p.angle += p.speed;
                    const orbitR = R * p.orbitFrac;
                    const x = cx + Math.cos(p.angle) * orbitR;
                    const y = cy + Math.sin(p.angle) * orbitR * 0.55;
                    p.trail.push({x, y});
                    if (p.trail.length > 22) p.trail.shift();
                    // dibujar el trail como segmentos de linea, cada uno
                    // con alpha mas bajo al acercarse al principio (la cola)
                    if (p.trail.length > 2) {
                        ctx.save();
                        for (let ti = 1; ti < p.trail.length; ti++) {
                            const frac = ti / p.trail.length;
                            ctx.globalAlpha = p.alpha * frac * 0.45;
                            ctx.strokeStyle = `hsl(${p.hue},75%,78%)`;
                            ctx.lineWidth   = p.r * frac * 0.8;
                            ctx.shadowColor = `hsl(${p.hue},80%,80%)`;
                            ctx.shadowBlur  = 4;
                            ctx.beginPath();
                            ctx.moveTo(p.trail[ti-1].x, p.trail[ti-1].y);
                            ctx.lineTo(p.trail[ti].x,   p.trail[ti].y);
                            ctx.stroke();
                        }
                        ctx.restore();
                    }
                    // la particula en si al final del trail, con pulso
                    // desfasado por el angulo (para que no palpiten sincronas)
                    ctx.save();
                    ctx.globalAlpha = p.alpha * (0.5 + 0.5 * Math.sin(t * 2 + p.angle * 3));
                    ctx.fillStyle   = `hsl(${p.hue},78%,80%)`;
                    ctx.shadowColor = `hsl(${p.hue},88%,85%)`;
                    ctx.shadowBlur  = p.r * 5;
                    ctx.beginPath(); ctx.arc(x, y, p.r, 0, Math.PI * 2); ctx.fill();
                    ctx.restore();
                });

                // borde con glow morado sutil (igual que divina pero con color
                // distinto y alpha ligeramente menor)
                const borderA = 0.12 + 0.08 * breath;
                ctx.save();
                ctx.strokeStyle = `rgba(180,130,255,${borderA})`;
                ctx.lineWidth   = 1;
                ctx.shadowColor = 'rgba(160,100,255,0.4)';
                ctx.shadowBlur  = 8;
                ctx.strokeRect(1, 1, W - 2, H - 2);
                ctx.restore();
            }

            raf = requestAnimationFrame(dibujar);
        }

        // handlers del hover. al entrar en divina disparo un spawn
        // inmediato para que no tarde en verse; si no, el jugador entra y
        // ve el aura vacia hasta el siguiente "tick entero" de divPartT
        btn.addEventListener("mouseenter", () => {
            isHover = true;
            if (rareza === "divina") {
                const {width: W2, height: H2} = canvas;
                spawnDivinaPart(W2, H2, W2/2, H2/2);
            }
        });
        // al salir: apago hover, quito el flash de mitica si estaba puesto,
        // y reseteo las particulas de divina (si no, se ven muriendo fuera
        // del hover, raro)
        btn.addEventListener("mouseleave", () => {
            isHover = false;
            flashMitica = false;
            btn.classList.remove("mitica-flash");
            if (rareza === "divina") divinaParts.forEach(p => { p.born = false; p.alpha = 0; });
        });

        window.addEventListener("resize", resize);
        raf = requestAnimationFrame(dibujar);
    });
}


// parche suelto para arrancar los neons cuando se entra en coleccion. el
// listener original de mostrarSeccion tambien los arranca con un setTimeout
// de 60ms, pero este segundo listener en el boton propio da una red de
// seguridad por si el mostrarSeccion fallara o tardara. no molesta porque
// si ya estan arrancados, iniciarNeonBotones no duplica nada (itera canvases
// nuevos que cada vez se recrean con resize)
const _origMostrarSeccion = mostrarSeccion;
document.querySelector('[onclick*="coleccion"]')?.addEventListener("click", () => {
    setTimeout(iniciarNeonBotones, 50);
});


// ideas futuras / TODO:
//   - el bug de animColRaf en animarDivina: cambiar por colRaf para alinear
//     con las demas animaciones. funciona de casualidad por la guarda del
//     if colRunaActual pero es una global implicita, no deberia estar asi
//   - las 3 animaciones de rarezas bajas (rara, epica, poco_comun) comparten
//     80% de codigo. se podria hacer una funcion animarRarezaSimple(rareza,
//     color, shape, speed) que las genere a todas, pero mientras funcionen
//     bien y no tengan bugs no me compensa el refactor
//   - el panel stats se repinta entero con innerHTML cada vez que cambias
//     de runa. podria ser un template con data-binding, pero son 7 filas,
//     tampoco pasa nada
//   - probar haptic feedback (vibrate) al hacer hover en movil. quizas
//     demasiado fuerte en tactil, hay que probar
//   - el mini canvas de comun dice "COMÚN" como texto. podria ser i18n si
//     algun dia saco el juego en ingles, pero todo el juego es espanol fijo

// ---- COLECCIÓN v7.1: navegación por listas y variantes ----
(function(){
    var coleccionActual = 'basicas';

    function actualizarContadorColeccion(){
        var visibles = Array.prototype.slice.call(document.querySelectorAll('.coleccion-columna-lista [data-collection]'))
            .filter(function(el){ return el.dataset.collection === coleccionActual; });
        var total = visibles.length;
        var unlocked = visibles.filter(function(el){ return !el.classList.contains('bloqueada'); }).length;
        var num = document.querySelector('#seccion-coleccion .col-contador-num');
        if (num) num.innerHTML = unlocked + '<span class="col-contador-sep">/</span>' + total + '<span class="col-contador-txt"> runas</span>';
    }

    function aplicarFiltroColeccion(slug){
        coleccionActual = slug || 'basicas';
        document.querySelectorAll('.coleccion-lista-pill').forEach(function(btn){
            btn.classList.toggle('active', btn.dataset.collection === coleccionActual);
        });
        document.querySelectorAll('.coleccion-columna-lista [data-collection]').forEach(function(el){
            el.classList.toggle('col-hidden-collection', el.dataset.collection !== coleccionActual);
        });
        document.querySelectorAll('.col-runa-btn.sel, .col-runa-comun.sel').forEach(function(el){ el.classList.remove('sel'); });
        var hint = document.getElementById('col-canvas-hint');
        if (hint) hint.style.display = '';
        pararAnimColeccion();
        actualizarContadorColeccion();
        iniciarMiniCanvas();
        iniciarNeonBotones();
        if (window.RW_aplicarVistaColeccionV81 || window.RW_aplicarVistaColeccionV78) {
            setTimeout(window.RW_aplicarVistaColeccionV81 || window.RW_aplicarVistaColeccionV78, 0);
        }
    }

    document.addEventListener('click', function(e){
        var btn = e.target.closest && e.target.closest('.coleccion-lista-pill');
        if (!btn || btn.disabled || btn.classList.contains('locked')) return;
        aplicarFiltroColeccion(btn.dataset.collection || 'basicas');
    });

    document.addEventListener('click', function(e){
        var btn = e.target.closest && e.target.closest('.coleccion-variante-pill');
        if (!btn || btn.disabled) return;
        document.querySelectorAll('.coleccion-variante-pill').forEach(function(b){ b.classList.remove('active'); });
        btn.classList.add('active');
    });

    function resetScrollColecciones() {
        var sc = document.getElementById('coleccion-listas-scroll');
        if (!sc) return;
        sc.scrollLeft = 0;
        var basicas = sc.querySelector('.coleccion-lista-pill[data-collection="basicas"]');
        if (basicas && typeof basicas.scrollIntoView === 'function') {
            basicas.scrollIntoView({ inline: 'start', block: 'nearest' });
            sc.scrollLeft = 0;
        }
    }

    function activarRuedaHorizontal() {
        var sc = document.getElementById('coleccion-listas-scroll');
        if (!sc || sc.dataset.wheelReady === '1') return;
        sc.dataset.wheelReady = '1';
        sc.addEventListener('wheel', function(ev){
            if (Math.abs(ev.deltaY) <= Math.abs(ev.deltaX)) return;
            sc.scrollLeft += ev.deltaY;
            ev.preventDefault();
        }, { passive: false });
    }

    window.RW_filtrarColeccion = aplicarFiltroColeccion;
    window.addEventListener('load', function(){
        activarRuedaHorizontal();
        aplicarFiltroColeccion('basicas');
        setTimeout(resetScrollColecciones, 30);
        setTimeout(resetScrollColecciones, 250);
    });
})();


// ---- COLECCIÓN v7.9: popup real al completar Básicas + desbloqueo corruptas en vivo ----
(function(){
    const POPUP_KEY_VERSION = 'v2_real_completion_7_9';

    function userKey(){
        var uid = (window.RW_INIT && window.RW_INIT.user_id) ? String(window.RW_INIT.user_id) : 'anon';
        return 'rw_col_basicas_complete_popup_' + POPUP_KEY_VERSION + '_' + uid;
    }

    function getBasicasBtns(){
        return Array.prototype.slice.call(document.querySelectorAll('.coleccion-columna-lista [data-collection="basicas"]'));
    }

    function estaDesbloqueada(el){
        if (!el) return false;
        var cant = parseInt(el.dataset.cantidad || '0', 10) || 0;
        return cant > 0 || el.classList.contains('desbloqueada') || !el.classList.contains('bloqueada');
    }

    function estadoBasicas(){
        var btns = getBasicasBtns();
        var total = btns.length;
        var desbloqueadas = btns.filter(estaDesbloqueada).length;
        return {
            total: total,
            desbloqueadas: desbloqueadas,
            completa: total > 0 && desbloqueadas >= total
        };
    }

    function abrirPopupColeccion(){
        var st = estadoBasicas();
        if (!st.completa) return;
        var modal = document.getElementById('coleccion-completa-modal');
        if (!modal) return;
        modal.classList.add('visible');
        modal.setAttribute('aria-hidden','false');
        var ok = modal.querySelector('.coleccion-completa-ok');
        if (ok) setTimeout(function(){ ok.focus(); }, 50);
    }

    function cerrarPopupColeccion(){
        var modal = document.getElementById('coleccion-completa-modal');
        if (!modal) return;
        modal.classList.remove('visible');
        modal.setAttribute('aria-hidden','true');
        // Solo marco visto si realmente está completa. Así evitamos que un bug previo 6/8 bloquee el popup bueno.
        if (estadoBasicas().completa) {
            try { localStorage.setItem(userKey(), '1'); } catch(e) {}
        }
    }

    function desbloquearCorruptaUI(){
        var st = estadoBasicas();
        if (!st.completa) return false;

        if (window.RW_INIT) window.RW_INIT.basic_collection_complete = true;

        var pill = document.querySelector('.coleccion-variante-pill[data-variant="corrupta"]');
        if (pill) {
            pill.disabled = false;
            pill.classList.remove('locked');
            pill.classList.add('corrupta-unlocked');
            pill.textContent = 'Corrupta · 1%';
            pill.title = 'Variante corrupta desbloqueada: 1% de la runa base';
            pill.setAttribute('aria-label', 'Corrupta desbloqueada, uno por ciento de la runa base');
        }

        document.querySelectorAll('.coleccion-bonus-suerte-v74').forEach(function(box){
            box.classList.add('activo');
            var label = box.querySelector('.coleccion-bonus-label');
            var sub = box.querySelector('.coleccion-bonus-sub');
            if (label) label.textContent = 'Bonus activo';
            if (sub) sub.textContent = 'Colección Básica completada · Total colección x1.50';
        });

        return true;
    }

    function seccionTiradaActiva(){
        var tiradaActiva = document.getElementById('seccion-tirada');
        return !!(tiradaActiva && tiradaActiva.classList.contains('activa'));
    }

    function popupVisto(){
        try { return localStorage.getItem(userKey()) === '1'; } catch(e) { return false; }
    }

    function intentarPopupSoloMenuPrincipal(){
        var st = estadoBasicas();
        if (!st.completa) return false;
        desbloquearCorruptaUI();
        if (!seccionTiradaActiva()) return false;
        if (popupVisto()) return false;
        abrirPopupColeccion();
        return true;
    }

    function intentarPopupCuandoTerminenAnimaciones(maxMs){
        var inicio = Date.now();
        maxMs = maxMs || 32000;
        function tick(){
            desbloquearCorruptaUI();
            if (!estadoBasicas().completa) return;
            var bloqueada = (typeof window.tiradaBloqueada !== 'undefined' && window.tiradaBloqueada) || (typeof tiradaBloqueada !== 'undefined' && tiradaBloqueada);
            if (!bloqueada) {
                intentarPopupSoloMenuPrincipal();
                return;
            }
            if (Date.now() - inicio < maxMs) setTimeout(tick, 700);
        }
        setTimeout(tick, 250);
    }

    window.RW_cerrarPopupColeccion = cerrarPopupColeccion;
    window.RW_abrirPopupColeccion = abrirPopupColeccion;
    window.RW_estadoBasicasColeccion = estadoBasicas;
    window.RW_desbloquearCorruptaColeccion = desbloquearCorruptaUI;
    window.RW_intentarPopupColeccionCompleta = intentarPopupSoloMenuPrincipal;
    window.RW_intentarPopupColeccionTrasAnimacion = intentarPopupCuandoTerminenAnimaciones;

    document.addEventListener('keydown', function(e){ if (e.key === 'Escape') cerrarPopupColeccion(); });
    document.addEventListener('click', function(e){
        var modal = document.getElementById('coleccion-completa-modal');
        if (modal && e.target === modal) cerrarPopupColeccion();
    });

    window.addEventListener('load', function(){
        setTimeout(function(){
            desbloquearCorruptaUI();
            intentarPopupSoloMenuPrincipal();
        }, 700);
    });

    document.addEventListener('click', function(e){
        var nav = e.target.closest && e.target.closest('[data-sec="tirada"], .nav-btn, .mob-nav');
        if (!nav) return;
        setTimeout(intentarPopupSoloMenuPrincipal, 250);
    });

    // Cuando llega una tirada del servidor, el panel de colección se actualiza en tirada.js.
    // Esperamos un poco y luego comprobamos. Si hubo animación especial, esperamos a que se libere tiradaBloqueada.
    document.addEventListener('runas:sync', function(){
        setTimeout(function(){
            desbloquearCorruptaUI();
            intentarPopupCuandoTerminenAnimaciones(35000);
        }, 500);
        setTimeout(function(){
            desbloquearCorruptaUI();
            intentarPopupCuandoTerminenAnimaciones(35000);
        }, 2500);
    });

    // Fallback para cualquier actualización visual manual del inventario.
    document.addEventListener('runaworld:coleccion-actualizada', function(){
        setTimeout(function(){
            desbloquearCorruptaUI();
            intentarPopupCuandoTerminenAnimaciones(35000);
        }, 250);
    });
})();

// ---- COLECCIÓN v8.1: variantes independientes y corruptas listadas ----
(function(){
    function getColeccionActiva(){
        var b = document.querySelector('.coleccion-lista-pill.active');
        return (b && b.dataset.collection) ? b.dataset.collection : 'basicas';
    }
    function getVarianteActiva(){
        var b = document.querySelector('.coleccion-variante-pill.active');
        return (b && b.dataset.variant) ? b.dataset.variant : 'normal';
    }
    function setContador(unlocked, total){
        var num = document.querySelector('#seccion-coleccion .col-contador-num');
        if (num) num.innerHTML = unlocked + '<span class="col-contador-sep">/</span>' + total + '<span class="col-contador-txt"> runas</span>';
    }
    function mostrarEmpty(tipo){
        document.querySelectorAll('.coleccion-empty-state').forEach(function(el){
            el.classList.toggle('col-hidden-collection', el.dataset.emptyCollection !== tipo);
        });
    }
    function ocultarEmpty(){
        document.querySelectorAll('.coleccion-empty-state').forEach(function(el){ el.classList.add('col-hidden-collection'); });
    }
    function resetSeleccionVisual(){
        document.querySelectorAll('.col-runa-btn.sel, .col-runa-comun.sel').forEach(function(el){ el.classList.remove('sel'); });
        var hint = document.getElementById('col-canvas-hint');
        if (hint) hint.style.display = '';
        if (typeof pararAnimColeccion === 'function') pararAnimColeccion();
        var titulo = document.getElementById('col-stats-titulo');
        var cont = document.getElementById('col-stats-contenido');
        if (titulo) titulo.textContent = 'Estadísticas';
        if (cont) cont.innerHTML = '<p class="col-stats-vacio">Selecciona una runa</p>';
    }
    function esOwned(el){
        return !el.classList.contains('bloqueada') && ((parseInt(el.dataset.cantidad || '0', 10) || 0) > 0 || el.classList.contains('desbloqueada'));
    }
    function matchesVista(el, col, variante){
        var elCol = el.dataset.collection || 'basicas';
        var elVar = el.dataset.variant || 'normal';
        if (variante === 'normal') return elCol === col && elVar === 'normal';
        if (variante === 'corrupta') return elCol === col && elVar === 'corrupta';
        return false;
    }
    function aplicarVista(){
        var col = getColeccionActiva();
        var variante = getVarianteActiva();
        var items = Array.prototype.slice.call(document.querySelectorAll('.coleccion-columna-lista [data-collection]'));
        ocultarEmpty();

        document.querySelectorAll('.col-corrupta-section').forEach(function(el){ el.classList.add('col-hidden-collection'); });

        if (variante === 'caos') {
            items.forEach(function(el){ el.classList.add('col-hidden-collection'); });
            mostrarEmpty('caos');
            setContador(0, 0);
            return;
        }

        var visibles = items.filter(function(el){ return matchesVista(el, col, variante); });
        items.forEach(function(el){
            el.classList.toggle('col-hidden-collection', visibles.indexOf(el) === -1);
        });
        if (variante === 'corrupta' && col === 'basicas' && visibles.length > 0) {
            document.querySelectorAll('.col-corrupta-section').forEach(function(el){ el.classList.remove('col-hidden-collection'); });
        }

        if ((col === 'intermedias' || col === 'avanzadas') && visibles.length === 0) {
            mostrarEmpty(col);
            setContador(0, 0);
            return;
        }

        if (variante === 'corrupta' && visibles.length === 0) {
            mostrarEmpty('corrupta');
            setContador(0, 0);
            return;
        }

        var unlocked = visibles.filter(esOwned).length;
        setContador(unlocked, visibles.length);
    }

    window.RW_aplicarVistaColeccionV81 = aplicarVista;
    window.RW_aplicarVistaColeccionV78 = aplicarVista;

    document.addEventListener('click', function(e){
        if (e.target.closest && (e.target.closest('.coleccion-lista-pill') || e.target.closest('.coleccion-variante-pill'))) {
            resetSeleccionVisual();
            setTimeout(aplicarVista, 0);
            setTimeout(function(){
                if (typeof iniciarMiniCanvas === 'function') iniciarMiniCanvas();
                if (typeof iniciarNeonBotones === 'function') iniciarNeonBotones();
            }, 60);
        }
    });
    window.addEventListener('load', function(){ setTimeout(aplicarVista, 80); setTimeout(aplicarVista, 400); });
})();

// ================================================================
// FIX V107 — mostrar fila de animación para corruptas especiales
// ================================================================
(function () {
    'use strict';

    function esAnimKey(key) {
        if (typeof window.RW_esAnimacionEspecialKey === 'function') return window.RW_esAnimacionEspecialKey(key);
        return ['eterna','divina','mitica','legendaria','mitica_corrupta','legendaria_corrupta'].indexOf(String(key || '').toLowerCase()) !== -1;
    }

    function animKeyDeBoton(el) {
        var rareza = String((el && el.dataset && el.dataset.rareza) || '').toLowerCase();
        var file = String((el && el.dataset && el.dataset.runaFile) || rareza).toLowerCase();
        var variant = String((el && el.dataset && el.dataset.variant) || '').toLowerCase();
        var nombre = String((el && el.dataset && el.dataset.nombre) || '').toLowerCase();
        if ((variant === 'corrupta' || nombre.indexOf('corrupt') !== -1) && (rareza === 'mitica' || file === 'mitica_corrupta')) return 'mitica_corrupta';
        if ((variant === 'corrupta' || nombre.indexOf('corrupt') !== -1) && (rareza === 'legendaria' || file === 'legendaria_corrupta')) return 'legendaria_corrupta';
        return file || rareza;
    }

    var oldSeleccionarV107 = window.seleccionarRunaCol || seleccionarRunaCol;
    window.seleccionarRunaCol = seleccionarRunaCol = function (el) {
        oldSeleccionarV107(el);

        var key = animKeyDeBoton(el);
        var row = document.getElementById('btn-anim-row');
        var ver = document.getElementById('btn-ver-anim');
        var toggle = document.getElementById('btn-toggle-anim');

        if (row) row.style.display = esAnimKey(key) ? 'flex' : 'none';
        if (esAnimKey(key)) {
            colRunaActual = key;
            window.colRunaActual = key;
            if (ver) {
                var base = key.indexOf('legendaria') !== -1 ? 'legendaria' : key.indexOf('mitica') !== -1 ? 'mitica' : key;
                ver.className = 'btn-ver-anim btn-ver-anim-' + base;
            }
            if (toggle) {
                var baseToggle = key.indexOf('legendaria') !== -1 ? 'legendaria' : key.indexOf('mitica') !== -1 ? 'mitica' : key;
                toggle.className = 'btn-toggle-anim btn-toggle-anim-' + baseToggle;
            }
            if (typeof actualizarEstadoToggle === 'function') actualizarEstadoToggle(key);
        }
    };
})();
