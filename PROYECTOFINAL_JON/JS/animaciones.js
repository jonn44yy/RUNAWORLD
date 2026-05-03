// ================================================================
// ANIMACIONES.JS — RunaWorld
// ================================================================
// Este archivo contiene TODAS las animaciones del proyecto.
// Está dividido en secciones claramente marcadas.
//
// INDICE:
//   1. UTILIDADES COMPARTIDAS        (funciones matemáticas)
//   2. ANIMACIÓN IDLE DEL BOTÓN      (juego.php)
//   3. PARTÍCULAS DEL BOTÓN          (juego.php)
//   4. NEON EN BORDES                (juego.php — legendaria/mítica)
//   5. PARTÍCULAS DESDE LOS BORDES   (juego.php — legendaria)
//   6. PARTÍCULAS EXPLOSIÓN          (juego.php — mítica)
//   7. MOSTRAR RESULTADO / CARD      (juego.php — todas las rarezas)
//   8. ANIMACIÓN LEGENDARIA          (juego.php)
//   9. ANIMACIÓN MÍTICA              (juego.php)
//  10. CÍRCULO SEGURIDAD CONTRASEÑA  (registro.php)
//  11. ANIMACIÓN ENTRADA ADMIN       (ADMIN/index.php — onda B)
//  12. ANIMACIÓN ENTRADA JUEGO       (juego.php — fade + runa)
// ================================================================


// ================================================================
// 1. UTILIDADES COMPARTIDAS
// ================================================================
// Funciones matemáticas usadas en varias animaciones.
// Se declaran en window para que sean accesibles globalmente.
// ================================================================

window.RW = window.RW || {};

RW.lerp    = (a, b, t) => a + (b - a) * t;
RW.easeIn  = (t)       => t * t * t;
RW.easeOut = (t)       => 1 - Math.pow(1 - t, 3);
RW.easeInOut = (t)     => t < 0.5 ? 2*t*t : -1+(4-2*t)*t;


// ================================================================
// 2. ANIMACIÓN IDLE DEL BOTÓN
// ================================================================
// UBICACIÓN: juego.php
// DESCRIPCIÓN: Si el jugador no hace click durante 10 segundos,
// el botón de "Tirar Runa" empieza a brillar con efecto idle-glow
// para llamar la atención. Se resetea con cada click.
// CSS necesario: clase .idle-glow en style.css
//
// 10/04 — el boton nuevo es el mandala circular, que ya tiene un pulso
// exterior (.rune-click-hint) + el pulso interno del core pulsando solos,
// asi que el idle-glow ya no hace falta. vacio la funcion para que los
// sitios que la llaman (tirada.js, ui.js) sigan funcionando sin tocarlos.
// si algun dia quito las llamadas, puedo borrar esto entero
// ================================================================

let idleTimer = null;

function resetIdleTimer() {
    // por si queda la clase pegada del codigo viejo, la limpio
    clearTimeout(idleTimer);
    const btn = document.getElementById("btn-tirar");
    if (btn) btn.classList.remove("idle-glow");
    // ya no pongo el timeout que volvia a ponerla. el mandala se llama la
    // atencion el solo con las animaciones CSS
}


// ================================================================
// 3. PARTÍCULAS DEL BOTÓN (DORADAS + RUNAS)
// ================================================================
// UBICACIÓN: juego.php
// DESCRIPCIÓN: al pulsar el boton, se disparan dos cosas a la vez:
//   1) 16 puntos dorados saliendo del perimetro circular del mandala
//   2) 8 runas vikingas volando desde el centro con drift aleatorio
// la combinacion queda chula, me gusta como el ojo sigue el glow dorado
// mientras en el fondo flotan las runas esotericas
//
// 20/04 — antes los puntos dorados salian del rectangulo del boton viejo.
// ahora calculo el perimetro del circulo real del mandala para que salgan
// exactamente del borde, queda mas limpio.
// CSS: .particula-btn (style.css — ya existe) + .runa-particula (rune_button.css)
// ================================================================

// runas vikingas que pueden salir volando por el centro
const RUNAS_PARTICULA = ['ᚠ','ᚢ','ᚦ','ᚨ','ᚱ','ᚲ','ᚷ','ᚹ','ᚺ','ᚾ','ᛁ','ᛃ','ᛇ','ᛈ','ᛉ','ᛊ'];

function lanzarParticulasBoton() {
    // si el jugador desactivo las particulas en ajustes > rendimiento, fuera
    if (localStorage.getItem("rw_anim_boton") === "off") return;

    const btn = document.getElementById("btn-tirar");
    if (!btn) return;
    const rect = btn.getBoundingClientRect();
    const cx   = rect.left + rect.width  / 2;
    const cy   = rect.top  + rect.height / 2;
    // el radio real del boton (circulo). uso el mas pequeno por si el
    // boundingRect no es cuadrado perfecto (ej: rotacion del mandala)
    const radio = Math.min(rect.width, rect.height) / 2;

    // PARTE 1: puntos dorados saliendo del perimetro del circulo.
    // 16 puntos repartidos uniformemente alrededor del mandala. cada uno sale
    // disparado hacia afuera en su direccion radial + un poco de distancia random
    const nPuntos = 16;
    for (let i = 0; i < nPuntos; i++) {
        const angulo = (i / nPuntos) * Math.PI * 2;
        // arranco justo en el borde del circulo
        const x0 = cx + Math.cos(angulo) * radio;
        const y0 = cy + Math.sin(angulo) * radio;
        // distancia que recorren hacia afuera
        const dist = 40 + Math.random() * 35;
        const dx = Math.cos(angulo) * dist;
        const dy = Math.sin(angulo) * dist;

        const p = document.createElement("div");
        p.className = "particula-btn";
        p.style.cssText = `
            left: ${x0}px; top: ${y0}px;
            --dx: ${dx}px; --dy: ${dy}px;
        `;
        document.body.appendChild(p);
        setTimeout(() => p.remove(), 1500);
    }

    // PARTE 2: runas vikingas volando desde el centro con drift aleatorio.
    // 8 runas (menos que puntos dorados porque son mas grandes y saturarian)
    const numRunas = 8;
    for (let i = 0; i < numRunas; i++) {
        const runa = document.createElement("div");
        runa.className   = "runa-particula";
        runa.textContent = RUNAS_PARTICULA[Math.floor(Math.random() * RUNAS_PARTICULA.length)];

        // angulo y distancia random en todas direcciones
        const angulo       = Math.random() * Math.PI * 2;
        const radioInicial = 20 + Math.random() * 30;
        const dist         = 80 + Math.random() * 60;
        const x0 = cx + Math.cos(angulo) * radioInicial;
        const y0 = cy + Math.sin(angulo) * radioInicial;
        const dx = Math.cos(angulo) * dist;
        // drift vertical extra para que no salgan todas en linea recta
        const dy = Math.sin(angulo) * dist + 20;
        const size = 14 + Math.random() * 8;

        runa.style.cssText = `
            left: ${x0}px; top: ${y0}px;
            font-size: ${size}px;
            --dx: ${dx}px; --dy: ${dy}px;
        `;
        document.body.appendChild(runa);
        setTimeout(() => runa.remove(), 1200);
    }
}


// ================================================================
// 4. NEON EN BORDES
// ================================================================
// UBICACIÓN: juego.php
// DESCRIPCIÓN: Activa/desactiva el overlay de neon en los 4 bordes
// de la pantalla. Color amarillo para legendaria, rojo para mítica.
// HTML necesario: <div id="neon-overlay"> con 4 .neon-line dentro.
// CSS necesario: .neon-overlay, .neon-overlay.leg, .neon-overlay.mit
// ================================================================

function activarNeon(tipo) {
    // tipo: "leg" = legendaria (amarillo), "mit" = mítica (rojo)
    const el = document.getElementById("neon-overlay");
    if (el) el.className = "neon-overlay visible " + tipo;
}

function desactivarNeon() {
    const el = document.getElementById("neon-overlay");
    if (el) el.className = "neon-overlay";
}


// ================================================================
// 5. PARTÍCULAS DESDE LOS BORDES
// ================================================================
// UBICACIÓN: juego.php
// DESCRIPCIÓN: Al sacar una runa legendaria, salen partículas
// desde los 4 bordes de la pantalla hacia el centro.
// CSS necesario: clase .event-particula con @keyframes eventPart
// ================================================================

let _particlasActivas = 0;
const _MAX_PARTICULAS = 60;
function lanzarParticulasDesdeEsquinas(tipo, cantidad) {
    if (_particlasActivas >= _MAX_PARTICULAS) return; // throttle
    // tipo: "leg" = amarillo dorado, "mit" = rojo
    const color = tipo === "leg" ? "#ffaa00" : "#ff2244";
    const w = window.innerWidth;
    const h = window.innerHeight;

    for (let i = 0; i < cantidad; i++) {
        setTimeout(() => {
            const p    = document.createElement("div");
            p.className = "event-particula";
            const lado  = Math.floor(Math.random() * 4);
            let x, y, tx, ty;

            if (lado === 0) {       // arriba
                x = Math.random() * w; y = 0;
                tx = (Math.random() - 0.5) * 150; ty = 80 + Math.random() * 150;
            } else if (lado === 1) { // abajo
                x = Math.random() * w; y = h;
                tx = (Math.random() - 0.5) * 150; ty = -(80 + Math.random() * 150);
            } else if (lado === 2) { // izquierda
                x = 0; y = Math.random() * h;
                tx = 80 + Math.random() * 150; ty = (Math.random() - 0.5) * 150;
            } else {                 // derecha
                x = w; y = Math.random() * h;
                tx = -(80 + Math.random() * 150); ty = (Math.random() - 0.5) * 150;
            }

            const size = 4 + Math.random() * 5;
            const dur  = 1.2 + Math.random() * 1;

            p.style.cssText = `
                left:${x}px; top:${y}px;
                width:${size}px; height:${size}px;
                background:${color};
                box-shadow: 0 0 8px ${color}, 0 0 16px ${color};
                --tx:${tx}px; --ty:${ty}px; --dur:${dur}s;
            `;
            document.body.appendChild(p);
            setTimeout(() => p.remove(), dur * 1000);
        }, i * 60);
    }
}


// ================================================================
// 6. PARTÍCULAS EXPLOSIÓN
// ================================================================
// UBICACIÓN: juego.php
// DESCRIPCIÓN: Al explotar la runa mítica, aparecen partículas
// rojas en posiciones aleatorias por toda la pantalla y se
// dispersan hacia afuera. Duran entre 3 y 10 segundos.
// CSS necesario: clase .event-particula con @keyframes eventPart
// ================================================================

function lanzarParticulasExplosion(cantidad) {
    const color = "#ff2244";

    for (let i = 0; i < cantidad; i++) {
        setTimeout(() => {
            const p     = document.createElement("div");
            p.className = "event-particula";
            const x     = Math.random() * window.innerWidth;
            const y     = Math.random() * window.innerHeight;
            const angle = Math.random() * Math.PI * 2;
            const dist  = 100 + Math.random() * 300;
            const tx    = Math.cos(angle) * dist;
            const ty    = Math.sin(angle) * dist;
            const size  = 3 + Math.random() * 6;
            const dur   = 3 + Math.random() * 7;

            p.style.cssText = `
                left:${x}px; top:${y}px;
                width:${size}px; height:${size}px;
                background:${color};
                box-shadow: 0 0 10px ${color}, 0 0 20px ${color};
                --tx:${tx}px; --ty:${ty}px; --dur:${dur}s;
            `;
            document.body.appendChild(p);
            setTimeout(() => p.remove(), dur * 1000);
        }, i * 15);
    }
}


// ================================================================
// 7. MOSTRAR RESULTADO / CARD
// ================================================================
// UBICACIÓN: juego.php
// DESCRIPCIÓN: Gestiona qué zona de resultado se usa según rareza.
//   - Común / Poco común: zona inferior, se sobreescribe siempre
//   - Rara: zona inferior, desaparece en 1s
//   - Épica: zona inferior, desaparece en 2s
//   - Legendaria: zona especial persistente, lanza animación neon
//   - Mítica: lanza lanzarMitica()
// HTML necesario: #resultado-especial, #resultado-tirada
// ================================================================

let resultadoTimeout = null;
let neonTimeout      = null;

const tiemposRareza = {
    comun: 0, poco_comun: 0,
    rara: 5000, epica: 5000, legendaria: 5000, mitica: 5000
};

function mostrarResultado(runa) {
    const rareza = runa.rareza;

    // nota: el hook de iniciarAnimacion/terminarAnimacion lo meti dentro de
    // cada lanzarX (lanzarEterna, lanzarDivina, lanzarMitica) y en la rama
    // de legendaria de abajo. asi se dispara siempre, aunque algo llame
    // directamente a lanzarMitica() sin pasar por aqui (ej: tirada.js lo hacia)

    if (rareza === "eterna") {
        lanzarEterna(runa);
        return;
    }

    if (rareza === "divina") {
        lanzarDivina(runa);
        return;
    }

    if (rareza === "mitica") {
        lanzarMitica(runa);
        return;
    }

    if (rareza === "legendaria") {
        // ocultar boosts activos durante los 10s del neon de legendaria
        if (typeof iniciarAnimacion === "function") iniciarAnimacion();
        setTimeout(() => {
            if (typeof terminarAnimacion === "function") terminarAnimacion();
        }, 10500);

        if (neonTimeout) clearTimeout(neonTimeout);
        desactivarNeon();
        lanzarParticulasDesdeEsquinas("leg", 25);
        setTimeout(() => activarNeon("leg"), 400);
        neonTimeout = setTimeout(() => desactivarNeon(), 10000);

        // Una sola card en resultado-tirada, igual que las demás rarezas
        document.getElementById("resultado-tirada").innerHTML = "";
        mostrarCardEn("resultado-tirada", runa);
        if (resultadoTimeout) clearTimeout(resultadoTimeout);
        resultadoTimeout = setTimeout(() => {
            const el = document.getElementById("resultado-tirada");
            if (el) el.innerHTML = "";
        }, 5000);

    } else {
        document.getElementById("resultado-tirada").innerHTML = "";
        mostrarCardEn("resultado-tirada", runa);

        if (rareza === "rara") {
            setTimeout(() => {
                const el = document.getElementById("resultado-tirada");
                if (el) el.innerHTML = "";
            }, 1000);
        } else if (rareza === "epica") {
            setTimeout(() => {
                const el = document.getElementById("resultado-tirada");
                if (el) el.innerHTML = "";
            }, 2000);
        }
    }
}

function mostrarCardEn(elementId, runa) {
    const el = document.getElementById(elementId);
    if (!el) return;
    const card = document.createElement("div");
    card.className = `runa-reveal rareza-${runa.rareza}`;
    card.innerHTML = `
        <div class="runa-nombre">${runa.nombre}</div>
        <div class="runa-rareza-label">✦ ${runa.rareza.replace("_", " ")} ✦</div>
        <div class="runa-bonus">+${runa.multiplicador} pts/seg</div>
    `;
    el.appendChild(card);
}


// ================================================================
// 9. ANIMACIÓN MÍTICA
// ================================================================
// UBICACIÓN: juego.php
// DESCRIPCIÓN: Secuencia completa al sacar una runa mítica:
//   1. Fondo negro aparece (z-index 9400)
//   2. SVG de la runa aparece pequeño en el centro (z-index 9500)
//   3. La runa crece girando, acelerando hasta velocidad máxima
//   4. Explota: flash rojo en pantalla + partículas por toda la pantalla
//   5. El fondo negro desaparece con fade, la card aparece
// HTML necesario: #mitica-runa-svg, #flash-rojo, #resultado-especial
// ================================================================

let tiradaBloqueada = false;

// ============================================================
// ANIMACIÓN DIVINA
// ============================================================

function _divCriarPluma(zIndex) {
    const canvas = document.createElement('canvas');
    const len = 45 + Math.random() * 35, w = Math.round(len * 0.32);
    canvas.width = w+4; canvas.height = Math.round(len)+4;
    canvas.style.cssText = `position:fixed;pointer-events:none;z-index:${zIndex||9005};left:${Math.random()<0.5?Math.random()*28+2:Math.random()*28+70}vw;top:-${len+10}px;opacity:1;`;
    document.body.appendChild(canvas);
    const ctx=canvas.getContext('2d'), cx=w/2+2, tip=4, base=len-4, peak=len*0.35;
    ctx.beginPath(); ctx.moveTo(cx,tip);
    ctx.quadraticCurveTo(cx-w*0.88,peak,cx-w*0.32,base);
    ctx.quadraticCurveTo(cx,base+6,cx+w*0.32,base);
    ctx.quadraticCurveTo(cx+w*0.88,peak,cx,tip);
    ctx.fillStyle='rgba(255,255,235,0.82)'; ctx.strokeStyle='rgba(255,250,200,0.6)'; ctx.lineWidth=0.8;
    ctx.fill(); ctx.stroke();
    ctx.beginPath(); ctx.moveTo(cx,tip); ctx.lineTo(cx,base);
    ctx.strokeStyle='rgba(255,255,200,0.9)'; ctx.lineWidth=1.5; ctx.stroke();
    for(let i=0;i<16;i++){const t=i/16,y=tip+(base-tip)*(t*0.88+0.06),hw=(t<0.4?t/0.4:1-(t-0.4)/0.6)*w*0.85;ctx.beginPath();ctx.moveTo(cx,y);ctx.lineTo(cx-hw,y-4);ctx.moveTo(cx,y);ctx.lineTo(cx+hw,y-4);ctx.strokeStyle='rgba(255,255,200,0.55)';ctx.lineWidth=0.8;ctx.stroke();}
    const drift=(Math.random()-0.5)*80,spin=(Math.random()-0.5)*400,dur=(6+Math.random()*4)*1000,start=performance.now();
    function tick(now){const p=Math.min((now-start)/dur,1);canvas.style.transform=`translateX(${drift*p}px) translateY(${-len+(window.innerHeight+len*2)*p}px) rotate(${spin*p}deg)`;canvas.style.opacity=p<0.08?p/0.08:p>0.85?(1-p)/0.15:1;if(p<1)requestAnimationFrame(tick);else canvas.remove();}
    requestAnimationFrame(tick);
}

function _divDestello() {
    const canvas=document.createElement('canvas'),W=window.innerWidth,H=window.innerHeight;
    canvas.width=W;canvas.height=H;canvas.style.cssText='position:fixed;inset:0;width:100%;height:100%;pointer-events:none;z-index:9460;';
    document.body.appendChild(canvas);const ctx=canvas.getContext('2d');
    const x=W*(0.1+Math.random()*0.8),y=H*(0.1+Math.random()*0.8),maxR=40+Math.random()*80;
    let t=0;function tick(){t++;ctx.clearRect(0,0,W,H);const p=t/30,size=p<0.5?(p/0.5)*maxR:((1-p)/0.5)*maxR,alpha=p<0.5?p*2:(1-p)*2;ctx.save();ctx.translate(x,y);ctx.globalAlpha=alpha*0.9;const g1=ctx.createLinearGradient(-size*2.5,0,size*2.5,0);g1.addColorStop(0,'rgba(255,255,220,0)');g1.addColorStop(0.5,'rgba(255,255,255,1)');g1.addColorStop(1,'rgba(255,255,220,0)');ctx.fillStyle=g1;ctx.fillRect(-size*2.5,-size*0.12,size*5,size*0.24);const g2=ctx.createLinearGradient(0,-size*3.5,0,size*3.5);g2.addColorStop(0,'rgba(255,255,220,0)');g2.addColorStop(0.5,'rgba(255,255,255,1)');g2.addColorStop(1,'rgba(255,255,220,0)');ctx.fillStyle=g2;ctx.fillRect(-size*0.12,-size*3.5,size*0.24,size*7);ctx.restore();if(t<30)requestAnimationFrame(tick);else canvas.remove();}
    requestAnimationFrame(tick);
}

function _divCadenas(zBase) {
    [{startEdge:'top-left',angle:35+Math.random()*20},{startEdge:'bottom-right',angle:25+Math.random()*20},{startEdge:'top-right',angle:145+Math.random()*20}].forEach((cfg,i)=>{
        setTimeout(()=>{
            const canvas=document.createElement('canvas');canvas.style.cssText=`position:fixed;inset:0;width:100%;height:100%;pointer-events:none;z-index:${(zBase||9008)+i};`;document.body.appendChild(canvas);
            const W=window.innerWidth,H=window.innerHeight;canvas.width=W;canvas.height=H;const ctx=canvas.getContext('2d');
            let sx,sy;if(cfg.startEdge==='top-left'){sx=W*0.05;sy=0;}else if(cfg.startEdge==='bottom-right'){sx=W*0.95;sy=H;}else{sx=W*0.95;sy=0;}
            const rad=cfg.angle*Math.PI/180,dirX=Math.cos(rad),dirY=Math.sin(rad),LW=22,LH=36,thick=5,step=LH+4,numLinks=Math.ceil(Math.sqrt(W*W+H*H)/step)+2;
            let drawn=0;
            function drawLink(idx){const px=sx+dirX*idx*step,py=sy+dirY*idx*step,alt=idx%2===0;ctx.save();ctx.translate(px,py);ctx.rotate(cfg.angle*Math.PI/180+(alt?0:Math.PI/2));ctx.beginPath();ctx.roundRect(-LW/2,-LH/2,LW,LH,LW/2);ctx.strokeStyle='rgba(255,215,0,0.92)';ctx.lineWidth=thick;ctx.stroke();ctx.beginPath();ctx.roundRect(-LW/2+thick*0.5,-LH/2+thick*0.5,LW-thick,LH-thick,LW/2*0.55);ctx.strokeStyle='rgba(255,255,180,0.3)';ctx.lineWidth=1;ctx.stroke();ctx.restore();}
            function drawNext(){if(drawn>=numLinks){let op=1;const fade=setInterval(()=>{op-=0.018;if(op<=0){clearInterval(fade);canvas.remove();return;}canvas.style.opacity=op;},50);return;}drawLink(drawn);drawn++;setTimeout(drawNext,22);}
            drawNext();
        },i*400);
    });
    window._flashInterval=setInterval(_divDestello,180);
}

// ================================================================
// ANIMACIÓN ETERNA (NEXO UNIVERSAL)
// ================================================================
// La animación dura ~19s. Se lanza en iframe fullscreen igual que
// la Divina. Al terminar muestra card con neón púrpura-azul.
// ================================================================


// ── Lluvia de partículas blancas post-Divina (10 segundos) ────
function iniciarLluviaPostDivina() {
    const DURACION = 10000;
    const inicio   = Date.now();
    let lastSpawn  = 0;
    const VW = window.innerWidth;
    const VH = window.innerHeight;

    function spawnParticula() {
        const startX = Math.random() * VW;
        const size   = 2 + Math.random() * 4;
        const p = document.createElement("div");
        p.style.cssText = `
            position:fixed;
            left:${startX}px;
            top:-12px;
            width:${size}px;
            height:${size}px;
            border-radius:50%;
            background:rgba(255,255,255,${0.55 + Math.random() * 0.45});
            pointer-events:none;
            z-index:9300;
            box-shadow:0 0 6px rgba(255,255,255,0.9), 0 0 12px rgba(180,220,255,0.5);
        `;
        document.body.appendChild(p);

        const vel   = 100 + Math.random() * 150;
        const drift = (Math.random() - 0.5) * 40;
        let y = -12;
        let x = startX;
        let prev = null;

        function caer(ts) {
            if (!prev) prev = ts;
            const dt = Math.min((ts - prev) / 1000, 0.05);
            prev = ts;
            y += vel * dt;
            x += drift * dt;
            p.style.top  = y + "px";
            p.style.left = x + "px";
            p.style.opacity = Math.max(0, 1 - (y / (VH * 0.85)));
            if (y < VH + 20 && parseFloat(p.style.opacity) > 0) {
                requestAnimationFrame(caer);
            } else {
                p.remove();
            }
        }
        requestAnimationFrame(caer);
    }

    function loop(ts) {
        if (Date.now() - inicio > DURACION) return;
        if (ts - lastSpawn > 60) {
            spawnParticula();
            lastSpawn = ts;
        }
        requestAnimationFrame(loop);
    }
    requestAnimationFrame(loop);
}

function lanzarEterna(runa) {
    tiradaBloqueada = true;

    // 20/04: avisar a boosts.js que ocultar las notifs durante los 25s que
    // dura la animacion + fragmentos. ver comentario del sistema en boosts.js
    if (typeof iniciarAnimacion === "function") iniciarAnimacion();
    setTimeout(() => {
        if (typeof terminarAnimacion === "function") terminarAnimacion();
    }, 25000);

    if (resultadoTimeout) clearTimeout(resultadoTimeout);
    if (neonTimeout)      clearTimeout(neonTimeout);
    desactivarNeon();

    const iframe = document.createElement('iframe');
    iframe.src = 'RUNAS_HTML/RUNAS_ANIMADAS/eterna.html';
    iframe.style.cssText = 'position:fixed;inset:0;width:100%;height:100%;border:none;z-index:9500;pointer-events:all;';
    document.body.appendChild(iframe);

    // La animación dura ~22s (F7 extendida). A los 23s quitamos el iframe.
    setTimeout(() => {
        tiradaBloqueada = false;
        iframe.style.transition = 'opacity 1.2s ease-out';
        iframe.style.opacity = '0';

        document.getElementById("resultado-tirada").innerHTML = "";
        mostrarCardEn("resultado-tirada", runa);
        if (resultadoTimeout) clearTimeout(resultadoTimeout);
        resultadoTimeout = setTimeout(() => {
            const el = document.getElementById("resultado-tirada");
            if (el) el.innerHTML = "";
        }, 8000);

        // Neón en tono púrpura — reutilizamos activarNeon con tipo "div"
        // para el glow blanco/dorado, suficiente para eterna
        activarNeon("div");
        neonTimeout = setTimeout(() => desactivarNeon(), 14000);

        setTimeout(() => { try { iframe.remove(); } catch(e){} }, 1400);
    }, 23000);

    // Fragmentos persistentes tras la animación
    setTimeout(() => {
        if (typeof iniciarFragmentosEterna === "function") iniciarFragmentosEterna();
    }, 24500);   // 23000 + 1400 fade + margen

    // Safety: si algo falla, limpiar a los 30s
    setTimeout(() => {
        tiradaBloqueada = false;
        try { iframe.remove(); } catch(e) {}
    }, 30000);
}


function lanzarDivina(runa) {
    tiradaBloqueada = true;

    // ocultar boosts activos durante los 12.5s que dura la animacion divina
    if (typeof iniciarAnimacion === "function") iniciarAnimacion();
    setTimeout(() => {
        if (typeof terminarAnimacion === "function") terminarAnimacion();
    }, 12500);

    if (resultadoTimeout) clearTimeout(resultadoTimeout);
    if (neonTimeout)      clearTimeout(neonTimeout);
    desactivarNeon();

    // Crear iframe fullscreen con la animación divina
    const iframe = document.createElement('iframe');
    iframe.src = 'RUNAS_HTML/RUNAS_ANIMADAS/divina.html';
    iframe.style.cssText = 'position:fixed;inset:0;width:100%;height:100%;border:none;z-index:9400;pointer-events:all;';
    document.body.appendChild(iframe);

    // La animación dura ~12s (5s hasta flash + 6s reveal + 1s margen).
    // A los 12.5s quitamos el iframe, mostramos la card y el neón.
    setTimeout(() => {
        tiradaBloqueada = false;
        iframe.style.transition = 'opacity 1s ease-out';
        iframe.style.opacity = '0';

        document.getElementById("resultado-tirada").innerHTML = "";
        mostrarCardEn("resultado-tirada", runa);
        if (resultadoTimeout) clearTimeout(resultadoTimeout);
        resultadoTimeout = setTimeout(() => {
            const el = document.getElementById("resultado-tirada");
            if (el) el.innerHTML = "";
        }, 8000);

        activarNeon("div");
        neonTimeout = setTimeout(() => desactivarNeon(), 12000);
        iniciarLluviaPostDivina(); // partículas blancas cayendo 10s

        setTimeout(() => { try { iframe.remove(); } catch(e){} }, 1200);
    }, 12500);

    // Safety: si algo falla, limpiar a los 18s
    setTimeout(() => {
        tiradaBloqueada = false;
        try { iframe.remove(); } catch(e) {}
    }, 18000);
}



function lanzarMitica(runa) {
    tiradaBloqueada = true;

    // ocultar boosts activos durante los 9.5s que dura la animacion mitica.
    // este hook es el que faltaba, tirada.js llamaba directamente a esta
    // funcion sin pasar por mostrarResultado, por eso el boost se veia
    // en la mitica pero no en las otras. fixed 20/04
    if (typeof iniciarAnimacion === "function") iniciarAnimacion();
    setTimeout(() => {
        if (typeof terminarAnimacion === "function") terminarAnimacion();
    }, 9500);

    if (resultadoTimeout) clearTimeout(resultadoTimeout);
    if (neonTimeout)      clearTimeout(neonTimeout);
    desactivarNeon();
    const especial = document.getElementById("resultado-especial");
    if (especial) especial.innerHTML = "";

    const runaEl  = document.getElementById("mitica-runa-svg");
    const flashEl = document.getElementById("flash-rojo");
    if (!runaEl || !flashEl) { tiradaBloqueada = false; return; }

    // preparar el SVG para GPU. will-change avisa al navegador de que
    // vamos a animar transform y filter, asi promueve la capa a GPU y no
    // retraza la silueta del SVG en cada frame (era lo que lo hacia pesado)
    runaEl.style.willChange = "transform, filter, opacity";

    const VW = window.innerWidth;
    const VH = window.innerHeight;
    const CX = VW / 2;
    const CY = VH / 2;
    const TAM_INICIAL = 80;
    const TAM_MAXIMO  = Math.min(VW, VH) * 0.78;

    let angulo         = 0;
    let velAngular     = 0;
    let startTime      = null;
    let rafMitica      = null;
    let explosionHecha = false;
    let lastFilterMs   = 0;

    // Fondo negro que tapa el juego durante la animación
    const fondoNegro = document.createElement("div");
    fondoNegro.style.cssText = `
        position:fixed; inset:0;
        background:#000;
        z-index:9400;
        pointer-events:none;
    `;
    document.body.appendChild(fondoNegro);

    function posRuna(x, y, size, opacity) {
        runaEl.style.width     = size + "px";
        runaEl.style.height    = size + "px";
        runaEl.style.left      = x + "px";
        runaEl.style.top       = y + "px";
        runaEl.style.opacity   = opacity;
        runaEl.style.transform = `translate(-50%, -50%) rotate(${angulo}deg)`;
    }

    function animMitica(timestamp) {
        if (!startTime) startTime = timestamp;
        const ms = timestamp - startTime;

        // Fase 1 — 0 a 400ms: runa aparece pequeña
        if (ms < 400) {
            const t = ms / 400;
            posRuna(CX, CY, RW.lerp(TAM_INICIAL * 0.2, TAM_INICIAL, RW.easeOut(t)), t);
            velAngular = 0;

        // Fase 2 — 400 a 2800ms: crece girando, acelera
        } else if (ms < 2800) {
            const t    = (ms - 400) / 2400;
            const size = RW.lerp(TAM_INICIAL, TAM_MAXIMO, RW.easeIn(t));
            velAngular = RW.lerp(0.2, 20, RW.easeIn(t));
            angulo    += velAngular;
            // el filter se actualiza cada ~66ms (4 frames a 60fps) en vez de
            // cada frame. un solo drop-shadow en vez de dos. la diferencia
            // visual es imperceptible pero el coste de GPU baja a la cuarta parte
            if (lastFilterMs === 0 || ms - lastFilterMs > 66) {
                lastFilterMs = ms;
                const glow = RW.lerp(20, 80, t);
                runaEl.style.filter = "drop-shadow(0 0 " + glow + "px rgba(255,34,68,0.9))";
            }
            posRuna(CX, CY, size, 1);

        // Fase 3 — 2800 a 3100ms: explosión
        } else if (ms < 3100) {
            const t = (ms - 2800) / 300;
            posRuna(CX, CY, TAM_MAXIMO * (1 + t * 0.4), 1 - t);
            angulo += velAngular * (1 - t);

            if (!explosionHecha) {
                explosionHecha = true;

                // Flash rojo
                flashEl.style.transition = "none";
                flashEl.style.opacity    = "0.75";
                requestAnimationFrame(() => {
                    flashEl.style.transition = "opacity 1.8s ease-out";
                    flashEl.style.opacity    = "0";
                });

                lanzarParticulasExplosion(25);
                tiradaBloqueada = false;

                // Card de resultado
                document.getElementById("resultado-tirada").innerHTML = "";
                mostrarCardEn("resultado-tirada", runa);
                if (resultadoTimeout) clearTimeout(resultadoTimeout);
                resultadoTimeout = setTimeout(() => {
                    const el = document.getElementById("resultado-tirada");
                    if (el) el.innerHTML = "";
                }, 5000);
            }

        // Fase final — runa queda invisible, fondo negro se va
        } else {
            // Ocultar la runa completamente y no volver a mostrarla
            runaEl.style.opacity  = "0";
            runaEl.style.width    = "0";
            runaEl.style.height   = "0";
            runaEl.style.filter   = "none";
            runaEl.style.willChange = "auto";
            fondoNegro.style.transition = "opacity 0.6s ease-out";
            fondoNegro.style.opacity    = "0";
            setTimeout(() => { try { fondoNegro.remove(); } catch(e){} }, 700);
            cancelAnimationFrame(rafMitica);
            return;
        }

        rafMitica = requestAnimationFrame(animMitica);
    }

    // Safety: si la animación falla, resetear tiradaBloqueada a los 5s
    setTimeout(() => {
        if (tiradaBloqueada) {
            tiradaBloqueada = false;
            try { fondoNegro.remove(); } catch(e){}
        }
        runaEl.style.opacity = "0";
        runaEl.style.width   = "0";
        runaEl.style.height  = "0";
    }, 5000);

    rafMitica = requestAnimationFrame(animMitica);
}


// ================================================================
// 10. CÍRCULO DE SEGURIDAD DE CONTRASEÑA
// ================================================================
// UBICACIÓN: registro.php
// DESCRIPCIÓN: Al escribir la contraseña, aparece un círculo
// orgánico distorsionado alrededor del formulario. Su color va
// de rojo (débil) a verde (muy fuerte). Al escribir la
// confirmación aparece un segundo círculo — se vuelve verde
// si ambas contraseñas coinciden.
// HTML necesario: <canvas id="password-strength-canvas">
// CSS necesario: #password-strength-canvas.visible { opacity:1 }
// ================================================================

function iniciarCirculoSeguridad() {
    const canvas = document.getElementById("password-strength-canvas");
    if (!canvas) return;

    const ctx    = canvas.getContext("2d");
    const input  = document.getElementById("password-input");
    const input2 = document.getElementById("password2-input");
    if (!input || !input2) return;

    function resizeCanvas() {
        canvas.width  = window.innerWidth;
        canvas.height = window.innerHeight;
    }
    resizeCanvas();
    window.addEventListener("resize", resizeCanvas);

    const NUM_PUNTOS = 120;

    const puntos = Array.from({ length: NUM_PUNTOS }, () => ({
        offset:    Math.random() * Math.PI * 2,
        speed:     0.3 + Math.random() * 0.5,
        amplitude: 0.5 + Math.random() * 0.5,
    }));

    const puntos2 = Array.from({ length: NUM_PUNTOS }, () => ({
        offset:    Math.random() * Math.PI * 2,
        speed:     0.2 + Math.random() * 0.4,
        amplitude: 0.4 + Math.random() * 0.6,
    }));

    let targetRadius  = 0, currentRadius  = 0;
    let targetRadius2 = 0, currentRadius2 = 0;
    let targetColor   = { r:255, g:34,  b:68 };
    let currentColor  = { r:255, g:34,  b:68 };
    let targetColor2  = { r:255, g:34,  b:68 };
    let currentColor2 = { r:255, g:34,  b:68 };
    let tiempoBase    = 0;

    function calcularSeguridad(pw) {
        if (!pw.length) return 0;
        let s = 0;
        if (pw.length >= 6)  s++;
        if (pw.length >= 8)  s++;
        if (pw.length >= 10) s++;
        if (pw.length >= 12) s++;
        if (/[a-z]/.test(pw)) s++;
        if (/[A-Z]/.test(pw)) s++;
        if (/[0-9]/.test(pw)) s++;
        if (/[^a-zA-Z0-9]/.test(pw)) s += 2;
        return Math.min(s, 10);
    }

    function colorPorNivel(n) {
        const c = [
            {r:255,g:34, b:68 },{r:255,g:60, b:30 },{r:255,g:100,b:0  },{r:255,g:140,b:0  },
            {r:255,g:190,b:0  },{r:255,g:215,b:0  },{r:200,g:230,b:0  },{r:140,g:230,b:20 },
            {r:80, g:220,b:40 },{r:40, g:210,b:60 },{r:0,  g:255,b:100},
        ];
        return c[Math.round(n)];
    }

    function radioPorNivel(n) { return 220 + (n / 10) * 320; }

    function ruido(x, t) {
        return Math.sin(x*2.1+t*0.8)*0.5 + Math.sin(x*3.7+t*1.3)*0.3 + Math.sin(x*1.3+t*0.5)*0.2;
    }

    function dibujar(timestamp) {
        tiempoBase = timestamp * 0.001;

        currentRadius  += (targetRadius  - currentRadius)  * 0.06;
        currentColor.r += (targetColor.r - currentColor.r) * 0.05;
        currentColor.g += (targetColor.g - currentColor.g) * 0.05;
        currentColor.b += (targetColor.b - currentColor.b) * 0.05;

        currentRadius2  += (targetRadius2  - currentRadius2)  * 0.06;
        currentColor2.r += (targetColor2.r - currentColor2.r) * 0.05;
        currentColor2.g += (targetColor2.g - currentColor2.g) * 0.05;
        currentColor2.b += (targetColor2.b - currentColor2.b) * 0.05;

        ctx.clearRect(0, 0, canvas.width, canvas.height);
        const cx = canvas.width / 2, cy = canvas.height / 2;

        // Círculo 1
        if (currentRadius > 5) {
            const r = currentRadius, d = r * 0.18;
            const cr = Math.round(currentColor.r), cg = Math.round(currentColor.g), cb = Math.round(currentColor.b);
            ctx.beginPath();
            for (let i = 0; i <= NUM_PUNTOS; i++) {
                const idx = i % NUM_PUNTOS, p = puntos[idx];
                const angle = (idx / NUM_PUNTOS) * Math.PI * 2;
                const n = ruido(angle + p.offset, tiempoBase * p.speed);
                const x = cx + Math.cos(angle) * (r + n * d * p.amplitude);
                const y = cy + Math.sin(angle) * (r + n * d * p.amplitude);
                i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
            }
            ctx.closePath();
            ctx.shadowColor = `rgb(${cr},${cg},${cb})`; ctx.shadowBlur = 40;
            ctx.strokeStyle = `rgba(${cr},${cg},${cb},0.9)`; ctx.lineWidth = 3; ctx.stroke();
            ctx.shadowBlur  = 80; ctx.strokeStyle = `rgba(${cr},${cg},${cb},0.4)`; ctx.lineWidth = 8; ctx.stroke();
            ctx.shadowBlur  = 120; ctx.strokeStyle = `rgba(${cr},${cg},${cb},0.15)`; ctx.lineWidth = 20; ctx.stroke();
        }

        // Círculo 2
        if (currentRadius2 > 5) {
            const r2 = currentRadius2, d2 = r2 * 0.22;
            const cr2 = Math.round(currentColor2.r), cg2 = Math.round(currentColor2.g), cb2 = Math.round(currentColor2.b);
            ctx.beginPath();
            for (let i = 0; i <= NUM_PUNTOS; i++) {
                const idx = i % NUM_PUNTOS, p = puntos2[idx];
                const angle = (idx / NUM_PUNTOS) * Math.PI * 2;
                const n = ruido(angle + p.offset + 5, tiempoBase * p.speed);
                const x = cx + Math.cos(angle) * (r2 + n * d2 * p.amplitude);
                const y = cy + Math.sin(angle) * (r2 + n * d2 * p.amplitude);
                i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
            }
            ctx.closePath();
            ctx.shadowColor = `rgb(${cr2},${cg2},${cb2})`; ctx.shadowBlur = 40;
            ctx.strokeStyle = `rgba(${cr2},${cg2},${cb2},0.7)`; ctx.lineWidth = 2; ctx.stroke();
            ctx.shadowBlur  = 80; ctx.strokeStyle = `rgba(${cr2},${cg2},${cb2},0.3)`; ctx.lineWidth = 6; ctx.stroke();
        }

        ctx.shadowBlur = 0;
        requestAnimationFrame(dibujar);
    }

    // Eventos contraseña 1
    input.addEventListener("input", function() {
        if (!this.value.length) { targetRadius = 0; return; }
        targetRadius = radioPorNivel(calcularSeguridad(this.value));
        targetColor  = colorPorNivel(calcularSeguridad(this.value));
        canvas.classList.add("visible");
    });
    input.addEventListener("focus", () => canvas.classList.add("visible"));
    input.addEventListener("blur",  function() { if (!this.value.length) targetRadius = 0; });

    // Eventos contraseña 2
    input2.addEventListener("input", function() {
        if (!this.value.length) { targetRadius2 = 0; return; }
        targetRadius2 = radioPorNivel(calcularSeguridad(this.value)) * 1.25;
        targetColor2  = (input.value === this.value)
            ? { r:0, g:255, b:100 }
            : { r:255, g:34, b:68 };
    });
    input2.addEventListener("focus", function() {
        canvas.classList.add("visible");
        if (this.value.length) targetRadius2 = radioPorNivel(calcularSeguridad(this.value)) * 1.25;
    });
    input2.addEventListener("blur", function() { if (!this.value.length) targetRadius2 = 0; });

    requestAnimationFrame(dibujar);
}


// ================================================================
// 11. ANIMACIÓN ENTRADA ADMIN — ONDA B
// ================================================================
// UBICACIÓN: ADMIN/index.php
// DESCRIPCIÓN: Se muestra una sola vez por sesión (controlado
// con $_SESSION["admin_intro_visto"] en PHP).
// Fases:
//   1. Runa SVG azul aparece en el centro
//   2. Título "RunaWorld" aparece debajo
//   3. Título se desintegra (blur + letter-spacing explota)
//   4. Runa vuela en arco bezier a esquina superior izquierda
//      mientras gira cada vez más rápido
//   5. Pulso de brillo al llegar a la esquina
//   6. Barrido horizontal sinusoidal (onda B) revela el panel
// HTML necesario: #wave-canvas, #intro-runa-svg, #intro-titulo
// ================================================================

function iniciarAnimacionAdmin() {
    const canvas  = document.getElementById("wave-canvas");
    const runa    = document.getElementById("intro-runa-svg");
    const titulo  = document.getElementById("intro-titulo");

    // Bloquear interacción durante la animación
    const blocker = document.createElement("div");
    blocker.style.cssText = "position:fixed;inset:0;z-index:99997;pointer-events:all;";
    document.body.appendChild(blocker);
    if (!canvas || !runa || !titulo) return;

    const ctx = canvas.getContext("2d");
    let W, H, cx, cy;
    let startTime = null, raf = null, angulo = 0;

    function resize() {
        W = canvas.width  = window.innerWidth;
        H = canvas.height = window.innerHeight;
        cx = W / 2; cy = H / 2;
    }
    resize();
    window.addEventListener("resize", resize);

    const runaSize  = Math.min(window.innerWidth, window.innerHeight) * 0.38;
    const runaSmall = 64;

    // Calcular destino dinámico según posición del logo estático
    const logoEl  = document.getElementById("sidebar-runa");
    let destX = 130, destY = 45; // fallback escritorio
    if (logoEl) {
        const rect = logoEl.getBoundingClientRect();
        destX = rect.left + rect.width  / 2;
        destY = rect.top  + rect.height / 2;
    }

    function posicionarRuna(x, y, size, opacity, angle) {
        runa.style.width     = size + "px";
        runa.style.height    = size + "px";
        runa.style.left      = x + "px";
        runa.style.top       = y + "px";
        runa.style.opacity   = opacity;
        runa.style.transform = `translate(-50%, -50%) rotate(${angle}deg)`;
    }

    ctx.fillStyle = "#000";
    ctx.fillRect(0, 0, W, H);

    function animacion(timestamp) {
        if (!startTime) startTime = timestamp;
        const ms = timestamp - startTime;
        const TOTAL = 5000;
        ctx.clearRect(0, 0, W, H);

        if (ms < 800) {
            // Runa aparece
            posicionarRuna(cx, cy, runaSize, ms/800, angulo);
            ctx.fillStyle = "#000"; ctx.fillRect(0,0,W,H);
            angulo += 0.3;

        } else if (ms < 1600) {
            // Título aparece
            const t = (ms-800)/800;
            posicionarRuna(cx, cy, runaSize, 1, angulo);
            titulo.style.opacity   = t;
            titulo.style.left      = cx + "px";
            titulo.style.top       = (cy + runaSize*0.55) + "px";
            titulo.style.transform = "translate(-50%, 0)";
            ctx.fillStyle = "#000"; ctx.fillRect(0,0,W,H);
            angulo += 0.3;

        } else if (ms < 2400) {
            // Título se desintegra
            const t = (ms-1600)/800;
            posicionarRuna(cx, cy, runaSize, 1, angulo);
            titulo.style.opacity       = 1-t;
            titulo.style.filter        = `blur(${t*10}px)`;
            titulo.style.letterSpacing = `${12+t*40}px`;
            ctx.fillStyle = "#000"; ctx.fillRect(0,0,W,H);
            angulo += 0.5;

        } else if (ms < 3200) {
            // Runa vuela a esquina en arco bezier
            const t    = RW.easeInOut((ms-2400)/800);
            const size = RW.lerp(runaSize, runaSmall, t);
            const cpX = cx + W*0.15, cpY = cy - H*0.35;
            const bx  = (1-t)*(1-t)*cx + 2*(1-t)*t*cpX + t*t*destX;
            const by  = (1-t)*(1-t)*cy + 2*(1-t)*t*cpY + t*t*destY;
            posicionarRuna(bx, by, size, 1, angulo);
            titulo.style.opacity = 0;
            ctx.fillStyle = "#000"; ctx.fillRect(0,0,W,H);
            angulo += 4 + t*6;

        } else if (ms < 3700) {
            // Pulso al llegar
            const t     = (ms-3200)/500;
            const pulso = 1 + Math.sin(t*Math.PI)*0.4;
            posicionarRuna(destX, destY, runaSmall*pulso, 1, angulo);
            runa.style.filter = `drop-shadow(0 0 ${20+pulso*25}px rgba(60,120,255,0.9)) drop-shadow(0 0 ${50+pulso*50}px rgba(60,120,255,0.6))`;
            ctx.fillStyle = "#000"; ctx.fillRect(0,0,W,H);
            angulo += 0.5;

        } else if (ms < TOTAL) {
            // Barrido horizontal sinusoidal (Onda B)
            const t      = RW.easeOut((ms-3700)/1300);
            const frente = W * t;
            const amp    = 40 * (1-t);
            const freq   = 0.015;
            const speed  = ms * 0.003;

            ctx.save();
            ctx.fillStyle = "#000011";
            ctx.beginPath();
            ctx.moveTo(frente, 0);
            for (let y = 0; y <= H; y += 4) ctx.lineTo(frente + Math.sin(y*freq+speed)*amp, y);
            ctx.lineTo(W,H); ctx.lineTo(W,0); ctx.closePath(); ctx.fill();
            ctx.restore();

            const a = Math.max(0, 1-t*1.1);
            ctx.beginPath(); ctx.moveTo(frente, 0);
            for (let y = 0; y <= H; y += 4) ctx.lineTo(frente + Math.sin(y*freq+speed)*amp, y);
            ctx.strokeStyle = `rgba(60,120,255,${a*0.9})`; ctx.lineWidth = 2;
            ctx.shadowColor = "#3c78ff"; ctx.shadowBlur = 25; ctx.stroke(); ctx.shadowBlur = 0;

            ctx.beginPath(); ctx.moveTo(frente-15, 0);
            for (let y = 0; y <= H; y += 4) ctx.lineTo(frente-15+Math.sin(y*freq+speed+0.5)*amp*0.7, y);
            ctx.strokeStyle = `rgba(60,120,255,${a*0.3})`; ctx.lineWidth = 6; ctx.stroke();

            posicionarRuna(destX, destY, runaSmall, 1, angulo);
            runa.style.filter = `drop-shadow(0 0 20px rgba(60,120,255,0.9)) drop-shadow(0 0 60px rgba(60,120,255,0.5))`;
            angulo += 0.3;

        } else {
            // Fin — ocultar canvas y runa animada, desbloquear clicks
            canvas.style.display = "none";
            titulo.style.display = "none";
            runa.style.display   = "none";
            blocker.remove();

            // Aplicar glow y click al SVG estático del sidebar
            const svgSidebar = document.getElementById("sidebar-runa");
            if (svgSidebar) {
                svgSidebar.style.transition = "filter 0.8s ease";
                svgSidebar.style.filter     = "drop-shadow(0 0 20px rgba(60,120,255,0.9)) drop-shadow(0 0 60px rgba(60,120,255,0.5))";
                svgSidebar.style.cursor     = "pointer";
                svgSidebar.onclick          = () => { window.location.href = "../juego.php"; };
                svgSidebar.title            = "Volver al juego";
            }
            return;
        }

        raf = requestAnimationFrame(animacion);
    }

    titulo.style.position  = "fixed";
    titulo.style.transform = "translate(-50%, 0)";
    requestAnimationFrame(animacion);
}


// ================================================================
// 12. ANIMACIÓN ENTRADA JUEGO
// ================================================================
// UBICACIÓN: juego.php
// DESCRIPCIÓN: Overlay negro con fade al entrar al juego.
// El título "RunaWorld" aparece con la frase tagline,
// y todo hace fade out revelando el juego.
// HTML necesario: #intro-overlay > #intro-content > #intro-title + #intro-tagline
// CSS necesario: @keyframes introFade, introContent, taglineAppear en style.css
// ================================================================

function iniciarAnimacionJuego() {
    const overlay = document.getElementById("intro-overlay");
    if (!overlay) return;
    setTimeout(() => overlay.remove(), 3500);
}


// ================================================================
// FIX V110 — Animaciones corruptas reales + efecto botón mítica corrupta
// ================================================================
(function () {
    'use strict';
    var RW_CORRUPT_ANIM_MS = { mitica_corrupta: 13500, legendaria_corrupta: 12000 };
    var _rwBtnMiticaTimer = null;
    var _rwIframeTimer = null;
    function _rwText(v) { return String(v || '').toLowerCase(); }
    function _rwRunaText(runa) {
        if (!runa) return '';
        return [runa.nombre, runa.imagen, runa.slug, runa.runa_file, runa.runaFile, runa.animacion_slug, runa.rareza_animacion, runa.variante].map(_rwText).join(' | ');
    }
    function _rwAnimKeyCorrupta(runa) {
        var txt = _rwRunaText(runa);
        if (txt.indexOf('mitica_corrupta') !== -1 || txt.indexOf('mítica corrupta') !== -1 || txt.indexOf('mitica corrupta') !== -1) return 'mitica_corrupta';
        if (txt.indexOf('legendaria_corrupta') !== -1 || txt.indexOf('legendaria corrupta') !== -1) return 'legendaria_corrupta';
        if (runa && runa.variante === 'corrupta') {
            if (runa.rareza === 'mitica') return 'mitica_corrupta';
            if (runa.rareza === 'legendaria') return 'legendaria_corrupta';
        }
        return '';
    }
    window.RW_getAnimKeyCorrupta = _rwAnimKeyCorrupta;
    window.activarUIRojizaMitica = function activarUIRojizaMitica() {
        var btn = document.getElementById('btn-tirar');
        if (!btn) return;
        btn.classList.add('mitica-corrupta-activa');
        document.body.classList.add('rw-mitica-corrupta-ui-activa');
        if (_rwBtnMiticaTimer) clearTimeout(_rwBtnMiticaTimer);
        _rwBtnMiticaTimer = setTimeout(function () {
            btn.classList.remove('mitica-corrupta-activa');
            document.body.classList.remove('rw-mitica-corrupta-ui-activa');
            _rwBtnMiticaTimer = null;
        }, 60000);
    };
    window.RW_lanzarAnimacionCorruptaEspecial = function RW_lanzarAnimacionCorruptaEspecial(runa, key) {
        key = key || _rwAnimKeyCorrupta(runa);
        if (key !== 'mitica_corrupta' && key !== 'legendaria_corrupta') return false;
        if (typeof getAnimActiva === 'function' && !getAnimActiva(key)) {
            if (typeof mostrarCardEn === 'function') mostrarCardEn('resultado-tirada', runa);
            return true;
        }
        if (key === 'mitica_corrupta' && typeof window.activarUIRojizaMitica === 'function') window.activarUIRojizaMitica();
        if (typeof iniciarAnimacion === 'function') iniciarAnimacion();
        tiradaBloqueada = true;
        if (resultadoTimeout) clearTimeout(resultadoTimeout);
        if (neonTimeout) clearTimeout(neonTimeout);
        if (typeof desactivarNeon === 'function') desactivarNeon();
        var old = document.getElementById('rw-corrupta-anim-iframe');
        if (old) old.remove();
        if (_rwIframeTimer) clearTimeout(_rwIframeTimer);
        var iframe = document.createElement('iframe');
        iframe.id = 'rw-corrupta-anim-iframe';
        iframe.src = 'RUNAS_HTML/RUNAS_ANIMADAS/' + key + '.html?v=' + Date.now();
        iframe.setAttribute('allow', 'autoplay');
        iframe.style.cssText = 'position:fixed;inset:0;width:100vw;height:100vh;border:none;background:#000;z-index:9700;pointer-events:none';
        document.body.appendChild(iframe);
        var dur = RW_CORRUPT_ANIM_MS[key] || 12000;
        _rwIframeTimer = setTimeout(function () {
            try { iframe.remove(); } catch (e) {}
            tiradaBloqueada = false;
            if (typeof terminarAnimacion === 'function') terminarAnimacion();
            var el = document.getElementById('resultado-tirada');
            if (el) el.innerHTML = '';
            if (typeof mostrarCardEn === 'function') mostrarCardEn('resultado-tirada', runa);
            if (resultadoTimeout) clearTimeout(resultadoTimeout);
            resultadoTimeout = setTimeout(function () {
                var res = document.getElementById('resultado-tirada');
                if (res) res.innerHTML = '';
            }, 5000);
        }, dur);
        setTimeout(function () {
            if (document.getElementById('rw-corrupta-anim-iframe') === iframe) {
                try { iframe.remove(); } catch (e) {}
                tiradaBloqueada = false;
                if (typeof terminarAnimacion === 'function') terminarAnimacion();
            }
        }, dur + 6000);
        return true;
    };
    var _rwMostrarResultadoBase = window.mostrarResultado || mostrarResultado;
    window.mostrarResultado = mostrarResultado = function (runa) {
        var key = _rwAnimKeyCorrupta(runa);
        if (key === 'mitica_corrupta' || key === 'legendaria_corrupta') {
            if (window.RW_lanzarAnimacionCorruptaEspecial(runa, key)) return;
        }
        return _rwMostrarResultadoBase(runa);
    };
    var _rwLanzarMiticaBase = window.lanzarMitica || lanzarMitica;
    window.lanzarMitica = lanzarMitica = function (runa) {
        var key = _rwAnimKeyCorrupta(runa);
        if (key === 'mitica_corrupta') {
            if (window.RW_lanzarAnimacionCorruptaEspecial(runa, key)) return;
        }
        return _rwLanzarMiticaBase(runa);
    };
})();
