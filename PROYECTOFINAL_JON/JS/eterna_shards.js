// eterna_shards.js — runaworld
// fragmentos persistentes que aparecen despues de sacar una runa eterna.
// basicamente el jugador acaba de sacar la runa mas rara del juego, merece
// algo extra que le recuerde el momento. por eso durante 60s le dejo
// poligonos dorados y azules flotando por la pantalla, a juego con los
// poligonos decorativos del fondo del home
//
// indice:
//   1. pool de fragmentos reutilizables (perf)
//   2. spawneo continuo durante el tiempo configurado
//   3. loop de animacion con fade in/out
//   4. iniciarFragmentosEterna() — funcion que llama animaciones.js
//
// lenguaje interno para los poco entendidos:
//   fragmento = cada poligono irregular dorado o azul que flota. pool = array
//               de objetos reutilizables para evitar crear/destruir
//               constantemente y que el GC no se vuelva loco. lo aprendi
//               haciendo particulas en canvas de juegos
//   shards    = plural en ingles, me sale asi en el codigo porque en el
//               gaming suena mejor. en comentarios uso "fragmento" a veces
//               tambien, son la misma cosa
//
// 15/04 creado, 20/04 restaurado porque se borro en un refactor. !hi
// 21/04 cambiado de circulos a poligonos irregulares estilo home/bg,
//       ademas agrandados a 25-61px y bajados los alphas (fill 5%, stroke
//       35%) para conseguir el look "shard fantasma grande" en lugar de
//       "confetti pequeno"


// cuanto duran los fragmentos en pantalla tras una eterna. 60 segundos es
// tiempo de sobra para que el jugador se quede flipando antes de seguir tirando
const SHARDS_DURACION_TOTAL_MS = 60000;

// cada cuanto spawn un fragmento nuevo. si lo pongo muy rapido satura, si lo
// pongo lento se ven pelados. 180ms salen como 5-6 por segundo, sale chulo
const SHARDS_SPAWN_INTERVALO_MS = 180;

// tamano del pool de fragmentos. no deberian estar todos activos a la vez
// porque cada uno dura 4-6s y spawn cada 180ms, asi que con 40 sobra
const SHARDS_POOL_SIZE = 40;

// dos colores: dorado (como todo el juego) y azul claro (el cian de legendaria)
// asi queda variado sin meter muchos colores que no pegan
const SHARDS_COLORES = ["#ffd700", "#80dfff"];


// variables internas del sistema
let _shardsActivo       = false;   // flag para evitar duplicar sesiones si
                                   // salen dos eternas en menos de 60s
let _shardsSpawnTimer   = null;    // interval que spawna nuevos
let _shardsFinalTimer   = null;    // timeout que apaga el sistema a los 60s
let _shardsRafId        = null;    // id de requestAnimationFrame para cancelar
let _shardsCanvas       = null;    // canvas donde se dibujan los fragmentos
let _shardsCtx          = null;
let _shardsPool         = [];      // pool reutilizable


// crear el canvas fullscreen si no existe. lo hago una sola vez y lo reutilizo
// en futuras sesiones para no meter nodos al DOM cada vez
function _crearCanvasShards() {
    if (_shardsCanvas) return _shardsCanvas;
    _shardsCanvas = document.createElement("canvas");
    _shardsCanvas.id = "eterna-shards-canvas";
    Object.assign(_shardsCanvas.style, {
        position: "fixed",
        inset: "0",
        width: "100vw",
        height: "100vh",
        pointerEvents: "none",
        zIndex: "9300",       // debajo de las animaciones especiales (9400+)
                              // pero encima del juego normal
    });
    document.body.appendChild(_shardsCanvas);
    _shardsCtx = _shardsCanvas.getContext("2d");

    // el canvas tiene que resizearse si el jugador cambia el tamano de la
    // ventana, si no las coordenadas se descuadran y los fragmentos aparecen
    // donde no deben
    const resize = () => {
        _shardsCanvas.width  = window.innerWidth;
        _shardsCanvas.height = window.innerHeight;
    };
    resize();
    window.addEventListener("resize", resize);

    // inicializar el pool. cada shard lleva su propio array de vertices que
    // se regenera en cada spawn (es corto, 3-5 puntos, no hace falta
    // preallocatear). rot/vrot para que giren lento en el aire
    for (let i = 0; i < SHARDS_POOL_SIZE; i++) {
        _shardsPool.push({
            active: false,
            x: 0, y: 0,
            vx: 0, vy: 0,
            vertices: [],    // array de {x,y} relativos al centro del shard
            rot: 0, vrot: 0, // angulo y velocidad de giro (rad, rad/ms)
            color: "",
            life: 0,
            maxLife: 0
        });
    }

    return _shardsCanvas;
}


// buscar un slot libre en el pool. si no hay (todos ocupados), robo el que
// tenga menos vida. asi el numero total de fragmentos en pantalla nunca pasa
// del tamano del pool, y no hay lag aunque llevemos 60s de spawn continuo
function _getShardLibre() {
    let masViejo = null, masViejoLife = Infinity;
    for (const s of _shardsPool) {
        if (!s.active) return s;
        if (s.life < masViejoLife) { masViejoLife = s.life; masViejo = s; }
    }
    return masViejo;
}


// construir los vertices de un poligono irregular con N lados. distribuyo
// angulos uniformemente alrededor de un circulo pero meto jitter tanto en el
// angulo como en el radio de cada vertice. sin jitter en angulo salen formas
// simetricas (triangulo equilatero, cuadrado, etc) que es lo contrario de lo
// que queremos. sin jitter en radio salen regulares tipo sello. con ambos
// salen shards que parecen trozos rotos de cristal, cada uno distinto
function _generarVerticesIrregulares(numVerts, radioBase) {
    const verts = [];
    for (let i = 0; i < numVerts; i++) {
        // angulo base uniforme + ruido de hasta ~25° en cada lado
        const anguloBase = (i / numVerts) * Math.PI * 2;
        const ruido = (Math.random() - 0.5) * 0.9;  // rad, ~52° total
        const angulo = anguloBase + ruido;
        // radio entre 55% y 100% del base. si bajo mas del 55% salen
        // concavos muy feos (estrellas pinchudas raras)
        const r = radioBase * (0.55 + Math.random() * 0.45);
        verts.push({ x: Math.cos(angulo) * r, y: Math.sin(angulo) * r });
    }
    return verts;
}


// spawneo un fragmento nuevo en una posicion random. direccion random tambien,
// con drift suave hacia arriba (como si subieran lentamente por la escena)
function _spawnShard() {
    const s = _getShardLibre();
    s.active  = true;
    s.x       = Math.random() * window.innerWidth;
    s.y       = Math.random() * window.innerHeight;
    // velocidad suave, nada agresivo. drift lateral -0.2 a +0.2, vertical
    // siempre hacia arriba (-) para que se sienta flotante
    s.vx      = (Math.random() - 0.5) * 0.4;
    s.vy      = -0.15 - Math.random() * 0.35;

    // numero de lados: 3 (triangulo) 4 (cuad irregular) o 5 (pentagono roto).
    // pesos: mas triangulos y cuadrilateros que pentagonos, que es lo que
    // mas abunda en los poligonos decorativos del home
    const rnd = Math.random();
    const numVerts = rnd < 0.45 ? 3 : (rnd < 0.85 ? 4 : 5);

    // radio base 25-61px. grandes a proposito: como el fill va al 5% y el
    // stroke al 35% (el conjunto queda muy transparente), si los hago
    // pequenos no se ven. grandes y casi traslucidos = look "shard
    // fantasma" en lugar de "confetti pixelado"
    const radioBase = 25 + Math.random() * 36;
    s.vertices = _generarVerticesIrregulares(numVerts, radioBase);

    // rotacion inicial random + giro muy suave (rad/ms). si subes vrot se
    // marean y pierden el look de "floating geometric", dejarlo bajo
    s.rot     = Math.random() * Math.PI * 2;
    s.vrot    = (Math.random() - 0.5) * 0.0015;

    s.color   = SHARDS_COLORES[Math.floor(Math.random() * SHARDS_COLORES.length)];
    s.maxLife = 4000 + Math.random() * 2000;  // 4-6s de vida cada fragmento
    s.life    = s.maxLife;
}


// loop principal del canvas. dibuja todos los fragmentos activos, los mueve,
// aplica fade in (primeros 15% de vida) y fade out (ultimo 30%)
function _loopShards(ts) {
    if (!_shardsCtx) return;
    const W = _shardsCanvas.width;
    const H = _shardsCanvas.height;
    _shardsCtx.clearRect(0, 0, W, H);

    // tiempo entre frames para que el movimiento sea fluido sin depender de fps
    if (!_loopShards.lastTs) _loopShards.lastTs = ts;
    const dt = Math.min(ts - _loopShards.lastTs, 50);  // cap 50ms por si hay lag
    _loopShards.lastTs = ts;

    for (const s of _shardsPool) {
        if (!s.active) continue;

        // mover + girar
        s.x   += s.vx   * dt * 0.1;
        s.y   += s.vy   * dt * 0.1;
        s.rot += s.vrot * dt;
        s.life -= dt;

        // salir de pantalla: margen de 25px porque al rotar el poligono sus
        // vertices se extienden algo mas alla del centro, asi evito que se
        // corte en seco cuando aun se ve media punta
        if (s.life <= 0 || s.y < -25 || s.x < -25 || s.x > W + 25) {
            s.active = false;
            continue;
        }

        // calcular alpha segun fase de vida:
        //   fade in: primer 15% de vida (alpha 0 -> 1)
        //   cuerpo:  siguiente 55% (alpha 1 plano)
        //   fade out: ultimo 30% (alpha 1 -> 0)
        const t = s.life / s.maxLife;   // 1 = recien nacido, 0 = muriendo
        let alpha;
        if (t > 0.85)       alpha = (1 - t) / 0.15;   // fade in
        else if (t > 0.30)  alpha = 1;                 // cuerpo plano
        else                alpha = t / 0.30;          // fade out

        // dibujar el poligono. proceso:
        //   1. save() / translate al centro / rotate angulo
        //   2. construir path con moveTo + lineTo iterando vertices
        //   3. closePath para que el ultimo lado conecte con el primero
        //   4. fill muy suave (5%) para que el interior solo se insinue
        //   5. stroke a 35% para contorno visible pero discreto
        //   6. restore
        // la clave del look es justo que sean casi invisibles: como hay
        // decenas en pantalla a la vez, si subes los alphas te queman la
        // retina. mejor muchos poligonos finos y fantasmales que pocos
        // macizos
        _shardsCtx.save();
        _shardsCtx.translate(s.x, s.y);
        _shardsCtx.rotate(s.rot);

        _shardsCtx.beginPath();
        _shardsCtx.moveTo(s.vertices[0].x, s.vertices[0].y);
        for (let i = 1; i < s.vertices.length; i++) {
            _shardsCtx.lineTo(s.vertices[i].x, s.vertices[i].y);
        }
        _shardsCtx.closePath();

        // fill casi invisible, solo para que se "adivine" el relleno
        _shardsCtx.globalAlpha = alpha * 0.05;
        _shardsCtx.fillStyle   = s.color;
        _shardsCtx.fill();

        // stroke a 35% + glow minimo. el blur lo pongo bajito (2) porque
        // con shards grandes, si subo el blur se emborrona el contorno
        // y pierde los bordes nitidos de poligono
        _shardsCtx.globalAlpha = alpha * 0.35;
        _shardsCtx.lineWidth   = 1.2;
        _shardsCtx.strokeStyle = s.color;
        _shardsCtx.shadowColor = s.color;
        _shardsCtx.shadowBlur  = 2;
        _shardsCtx.stroke();

        _shardsCtx.restore();
    }

    // reset de estado global del ctx por si otros sistemas pintan despues
    _shardsCtx.globalAlpha = 1;
    _shardsCtx.shadowBlur  = 0;

    _shardsRafId = requestAnimationFrame(_loopShards);
}


// funcion publica, la llama animaciones.js desde lanzarEterna() despues de
// los 24.5s (cuando termina la animacion principal de la eterna)
function iniciarFragmentosEterna() {
    // si ya habia un session activa, la reseteo (el jugador saco dos eternas
    // seguidas, cosa rarisima pero me curo en salud)
    if (_shardsActivo) {
        _pararFragmentosEterna(true);  // true = limpieza inmediata
    }

    _crearCanvasShards();
    _shardsActivo = true;

    // spawn continuo cada 180ms durante 60s
    _shardsSpawnTimer = setInterval(_spawnShard, SHARDS_SPAWN_INTERVALO_MS);

    // loop de animacion
    _loopShards.lastTs = 0;
    _shardsRafId = requestAnimationFrame(_loopShards);

    // programar el apagado a los 60s. NO se cortan los fragmentos ya activos,
    // solo dejo de spawnear nuevos. los que queden acaban solos por su
    // max_life y el canvas se queda limpio
    _shardsFinalTimer = setTimeout(() => {
        _pararFragmentosEterna(false);
    }, SHARDS_DURACION_TOTAL_MS);
}


// parar el sistema. si inmediato=true limpia todo ya (para reset), si false
// deja que los fragmentos activos terminen su vida natural (usado al cumplir 60s)
function _pararFragmentosEterna(inmediato) {
    _shardsActivo = false;

    // dejar de spawnear nuevos siempre
    if (_shardsSpawnTimer) {
        clearInterval(_shardsSpawnTimer);
        _shardsSpawnTimer = null;
    }
    if (_shardsFinalTimer) {
        clearTimeout(_shardsFinalTimer);
        _shardsFinalTimer = null;
    }

    if (inmediato) {
        // matar todos los fragmentos y cortar el loop ya
        for (const s of _shardsPool) s.active = false;
        if (_shardsRafId) cancelAnimationFrame(_shardsRafId);
        _shardsRafId = null;
        if (_shardsCtx) {
            _shardsCtx.clearRect(0, 0, _shardsCanvas.width, _shardsCanvas.height);
        }
    }
    // si no es inmediato, el loop sigue corriendo hasta que todos acaben solos.
    // cuando no queden activos se podria parar el raf pero tampoco pasa nada
    // por dejarlo, gasta practicamente nada con el pool vacio
}


// ideas futuras / TODO:
//   - quizas dejar tambien que al hacer hover sobre un fragmento haga algo
//     gracioso (tipo explotar y dar un puntito extra). bonus pequeno para
//     reforzar el momento eterna, ya que es la runa mas rara
//   - probar otros colores segun suerte del jugador o algo asi
//   - anadir un shard brillante de 10s en 10s que de coins bonus si lo cazas
//   - para los pentagonos podria permitir algun vertice con radio "largo"
//     para hacer shards en forma de cometa/flecha