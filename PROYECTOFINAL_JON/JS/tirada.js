// tirada.js — runaworld
// el corazon del juego client-side. cuando el jugador pulsa "tirar runa"
// pasa por aqui: descuenta coin, llama al server, procesa la respuesta,
// dispara la animacion que toque (eterna/divina/mitica/legendaria o
// normal) y actualiza todos los paneles afectados (panel de runas derecho,
// botones de coleccion, stats del header)
//
// indice:
//   1. tirarRuna() — el evento gordo, lanza el fetch y procesa respuesta
//   2. actualizarPanelRunas() — update in-place del panel derecho + coleccion
//   3. guardarProgreso() — auto-save cada 30s + llamable a mano
//
// lenguaje interno para los poco entendidos:
//   tiradaBloqueada  = flag global (definido en ui.js) que bloquea nuevas
//                      tiradas mientras una animacion especial esta en
//                      curso. una eterna dura 20s, si dejas tirar en medio
//                      las animaciones se superponen y queda un cristo
//   safetyTimer      = timeout de 25s por si algo falla y tiradaBloqueada
//                      se queda atascada en true (bug o red caida). pelo de
//                      la dehesa pero mejor que dejar al jugador sin poder
//                      tirar hasta que recargue
//   points_ps        = puntos por segundo. formula: (base_runas + points_add)
//                      * multi_pts. base_runas lo manda el server (depende
//                      de las runas que tiene el jugador), los otros dos son
//                      mejoras. y encima van boosts multiplicando
//   suerte formula   = (1 + b) * c * d. ver cabecera de juego.php para la
//                      explicacion completa. aqui nos llega solo "d" del
//                      server (suerte_grupo), "b" y "c" son client-side
//   especiales       = eterna / divina / mitica / legendaria. tienen
//                      animacion propia y solo se muestra UNA por tirada
//                      (la mas rara gana). las demas de esa misma tirada
//                      van al panel de runas sin cartita, solo sumando
//   panel de runas   = el sidebar derecho con las cartas de cada runa. se
//                      actualiza in-place (sin reconstruir html) porque si
//                      lo reconstruyo entero cada tirada parpadea feo
//
// hecho entre marzo y abril, actualizado cada vez que toco algo del server
// o anado una rareza nueva. !hi

// tirarRuna() — se llama al pulsar el boton o al hacer click en el rectangulo
function tirarRuna() {
    // guardas de entrada: si hay animacion en curso o no tengo monedas, fuera.
    // el orden importa porque tiradaBloqueada es mas frecuente que coins=0
    if (tiradaBloqueada) return;
    if (coins < 1) return;

    resetIdleTimer();           // resetear el temporizador de "jugador AFK"
    lanzarParticulasBoton();    // burbujitas al pulsar (cosmetico)

    // descuento la moneda YA, antes del fetch. asi el ui responde instantaneo
    // y no hay ese "medio segundo raro" entre el click y el feedback visual.
    // si la tirada fallara en el server, la devuelvo al final en el .catch
    coins -= 1;
    actualizarPantalla();
    document.getElementById("btn-tirar").disabled = true;

    // safety net: si por lo que sea tiradaBloqueada no se resetea en 25s,
    // lo fuerzo a false. eterna dura ~20s, divina ~13s — margen suficiente.
    // el fetch normal clearea este timeout mucho antes, solo salta si algo
    // se queda colgado
    const safetyTimer = setTimeout(() => {
        tiradaBloqueada = false;
        document.getElementById("btn-tirar").disabled = false;
    }, 25000);

    fetch("PHP/tirar_runa.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ coins, points })
    })
    .then(r => r.json())
    .then(data => {
        clearTimeout(safetyTimer);
        document.getElementById("btn-tirar").disabled = false;

        if (data.ok) {
            // IMPORTANTE: no sobreescribo coins con el valor del server.
            // el server devuelve el valor que tenia ANTES de procesar (o
            // poco despues), pero desde que empezo el fetch el js local ha
            // estado acumulando coins/seg por mejoras. si pisara aqui coins,
            // el jugador perderia esos centimos acumulados en cada tirada.
            // solo verifico que no sea negativo por si acaso
            if (coins < 0) coins = 0;

            // points_por_seg base viene del server y representa solo la
            // aportacion de las RUNAS del jugador (sin mejoras). las mejoras
            // additive/multi las aplica recalcularStatsDesdeMejoras en ui.js
            // asi que aqui reaplico la formula a mano con la nueva base
            if (data.points_por_seg !== undefined && !isNaN(parseFloat(data.points_por_seg))) {
                _runas_points_ps = parseFloat(data.points_por_seg);
                // formula: (base_runas + add_mejoras) * multi_mejoras
                const nuevoPps = (_runas_points_ps + _mejora_points_add) * _mejora_multi_pts;
                points_ps_base = nuevoPps;
                points_ps      = nuevoPps;
                // y encima los boosts multiplicando. coins_ps_base NO se toca
                // aqui porque las tiradas no afectan a los coins por segundo
                if (typeof aplicarBoosts === 'function') aplicarBoosts();
            }

            // suerte_grupo: el server manda solo la "d" de la formula
            // (1 + b) * c * d. la "b" (mejoras de tienda) y la "c" (boosts)
            // se mantienen igual que estaban. despues de actualizar "d",
            // recalculo suerte_base_val y reaplico boosts para que el panel
            // muestre el valor nuevo al momento
            if (data.suerte_grupo !== undefined) {
                const nuevaD = parseFloat(data.suerte_grupo);
                if (!isNaN(nuevaD) && nuevaD > 0) {
                    suerte_grupo = nuevaD;
                    suerte_base_val = _calcSuerteBase();
                    aplicarBoosts();
                }
            } else if (data.suerte !== undefined) {
                // fallback: versiones viejas del server devolvian .suerte
                // directamente (el valor total ya calculado). se puede quitar
                // cuando este seguro de que todos los servers mandan
                // suerte_grupo
                const nueva = parseFloat(data.suerte);
                if (!isNaN(nueva) && nueva > 0) {
                    suerte_grupo = nueva;
                    suerte_base_val = _calcSuerteBase();
                    aplicarBoosts();
                }
            }
            actualizarPantalla();

            // bonus de grupo conseguidos en esta tirada (ej: completaste la
            // coleccion "Runas Basicas" y te llevas +0.5 de suerte). el server
            // los detecta y me los manda en un array para que los notifique
            // visualmente. la cartita aparece 8s y se desvanece sola
            if (data.bonus_conseguidos && data.bonus_conseguidos.length > 0) {
                data.bonus_conseguidos.forEach(b => {
                    const card = document.createElement("div");
                    card.className = "runa-reveal rareza-legendaria bonus-grupo-notif";
                    card.innerHTML = `<div class="runa-nombre">¡Colección completada!</div>
                        <div class="runa-rareza-label">✦ ${b.grupo} ✦</div>
                        <div class="runa-bonus">${b.descripcion}</div>`;
                    document.getElementById("resultado-especial").appendChild(card);
                    // auto-fade despues de 8s: primero transicion de 0.5s a
                    // opacity 0, luego remove() cuando acaba
                    setTimeout(() => {
                        card.style.transition = "opacity 0.5s";
                        card.style.opacity = "0";
                        setTimeout(() => card.remove(), 500);
                    }, 8000);
                });
            }

            // separar runas ganadas en "especiales" (con animacion propia)
            // y "normales" (solo cartita). las especiales son eterna,
            // legendaria y mitica. divina se maneja aparte mas abajo
            const especiales = data.runas_ganadas.filter(r =>
                r.rareza === "eterna" || r.rareza === "legendaria" || r.rareza === "mitica"
            );
            const normales = data.runas_ganadas.filter(r =>
                r.rareza !== "eterna" && r.rareza !== "legendaria" && r.rareza !== "mitica"
            );

            // las normales se muestran una a una con sus cartitas, sin drama
            normales.forEach(r => mostrarResultado(r));

            // para las especiales agrupo por rareza y SOLO disparo la
            // animacion de la mas rara. si te salen 2 eternas y 3 miticas en
            // la misma tirada (tremendo golpe de suerte), se lanza la
            // animacion de eterna y las miticas van solo al panel.
            // ademas sumo los multiplicadores de las mismas rarezas en una
            // sola cartita: "¡3 Miticas!" con multi = suma de las 3
            const eternas     = especiales.filter(r => r.rareza === "eterna");
            const divinas     = especiales.filter(r => r.rareza === "divina");
            const miticas     = especiales.filter(r => r.rareza === "mitica");
            const legendarias = especiales.filter(r => r.rareza === "legendaria");

            // prioridad de animacion (de mas rara a menos): eterna > divina >
            // mitica > legendaria. el if/else if garantiza que solo una se
            // dispare. getAnimActiva() consulta si el jugador tiene la
            // animacion activada en ajustes (se puede desactivar cada una)
            if (eternas.length > 0) {
                const totalMulti = eternas.reduce((s, r) => s + parseFloat(r.multiplicador), 0);
                const runaE = {
                    nombre: eternas.length > 1 ? `¡${eternas.length} Eternas!` : eternas[0].nombre,
                    rareza: "eterna",
                    multiplicador: totalMulti.toFixed(2)
                };
                if (getAnimActiva("eterna")) {
                    lanzarEterna(runaE);
                } else {
                    mostrarResultado(runaE);
                }
            } else if (divinas.length > 0) {
                const totalMulti = divinas.reduce((s, r) => s + parseFloat(r.multiplicador), 0);
                const runaD = {
                    nombre: divinas.length > 1 ? `¡${divinas.length} Divinas!` : divinas[0].nombre,
                    rareza: "divina",
                    multiplicador: totalMulti.toFixed(2)
                };
                if (getAnimActiva("divina")) {
                    lanzarDivina(runaD);
                } else {
                    mostrarResultado(runaD);
                }
            } else if (miticas.length > 0) {
                const totalMulti = miticas.reduce((s, r) => s + parseFloat(r.multiplicador), 0);
                const runaM = {
                    nombre:        miticas.length > 1 ? `¡${miticas.length} Míticas!` : miticas[0].nombre,
                    rareza:        "mitica",
                    multiplicador: totalMulti.toFixed(2),
                    cantidad:      miticas.length
                };
                if (getAnimActiva("mitica")) {
                    lanzarMitica(runaM);
                } else {
                    mostrarResultado(runaM);
                }
            } else if (legendarias.length > 0) {
                // legendaria no tiene animacion toggle (siempre sale la misma),
                // pero si ya habia una animacion legendaria activa por el idle
                // del boton, la desactivo un poco despues para que no se
                // solape con la cartita
                const totalMulti = legendarias.reduce((s, r) => s + parseFloat(r.multiplicador), 0);
                const runaL = {
                    nombre:        legendarias.length > 1 ? `¡${legendarias.length} Legendarias!` : legendarias[0].nombre,
                    rareza:        "legendaria",
                    multiplicador: totalMulti.toFixed(2),
                    cantidad:      legendarias.length
                };
                mostrarResultado(runaL);
                if (!animLegendariaActiva) setTimeout(desactivarNeon, 100);
            }

            // ultimo paso: refrescar cantidades en el panel lateral y en la
            // coleccion (ver actualizarPanelRunas mas abajo)
            actualizarPanelRunas(data.runas);
        } else {
            // el server dijo que no: devuelvo la moneda que descente al
            // principio. puede pasar por race condition o por antiflood,
            // raro pero hay que cubrirlo
            coins += 1;
            actualizarPantalla();
        }
    })
    .catch(() => {
        // red caida o error de parseo. igual que arriba: devuelvo moneda,
        // reseteo flags y vuelvo a habilitar el boton para que el jugador
        // no se quede pillado
        clearTimeout(safetyTimer);
        tiradaBloqueada = false;
        coins += 1;
        actualizarPantalla();
        document.getElementById("btn-tirar").disabled = false;
    });
}

// actualizarPanelRunas() — refresca cantidades SIN rehacer el html.
// actualizo in-place (cambiando textContent y classes) porque si reconstruyo
// los paneles enteros cada tirada, el navegador reflowea todo y se nota un
// parpadeo feo. ademas pierdes el scroll donde lo tuviera el jugador
function actualizarPanelRunas(runas) {
    if (!runas || runas.length === 0) return;

    // panel derecho (el sidebar de "mis runas")
    // busco cada runa por su data-id y actualizo solo la cantidad. si la
    // runa estaba bloqueada y ahora tiene cantidad > 0, ademas desbloqueo
    runas.forEach(r => {
        const card = document.querySelector(`.runa-card-btn[data-id="${r.id}"]`);
        if (card) {
            card.dataset.cantidad = r.cantidad;
            const cantEl = card.querySelector(".runa-card-cantidad");
            const right  = card.querySelector(".runa-card-right");

            if (r.cantidad > 0 && card.classList.contains("runa-bloqueada")) {
                // desbloquear: quito la clase y meto el span de cantidad +
                // la flecha de expandir. el candado lo reemplaza todo el
                // contenido del lado derecho
                card.classList.remove("runa-bloqueada");
                if (right && !cantEl) {
                    right.innerHTML = `<span class="runa-card-cantidad">x${Number(r.cantidad).toLocaleString()}</span><span class="runa-card-flecha">▾</span>`;
                }
            } else if (cantEl) {
                cantEl.textContent = "x" + Number(r.cantidad).toLocaleString();
            }
        }
    });

    // contador "X/Y desbloqueadas" del panel. cuento las no bloqueadas y el
    // total, lo pinto separado por "/"
    const countEl = document.getElementById("panel-runas-count");
    if (countEl) {
        const desbloqueadas = document.querySelectorAll(".runa-card-btn:not(.runa-bloqueada)").length;
        const total = document.querySelectorAll(".runa-card-btn").length;
        countEl.textContent = desbloqueadas + "/" + total;
    }

    // botones de la seccion coleccion
    // hay dos tipos de boton: .col-runa-btn (especiales, con canvas neon) y
    // .col-runa-comun (comunes, con mini canvas). misma idea para los dos:
    // buscar por data-id, actualizar cantidad, y si pasa de 0 a >0 hacer
    // todo el ritual de desbloqueo (quitar candado, anadir canvas, etc)
    runas.forEach(r => {
        // --- especiales (legendaria / mitica / eterna / divina) ---
        const btnEsp = document.querySelector(`.col-runa-btn[data-id="${r.id}"]`);
        if (btnEsp) {
            const cantAnterior = parseInt(btnEsp.dataset.cantidad) || 0;
            btnEsp.dataset.cantidad = r.cantidad;

            const cantSpan = btnEsp.querySelector(".col-runa-cantidad");
            if (cantSpan) {
                cantSpan.textContent = "x" + Number(r.cantidad).toLocaleString();
            }

            // si era 0 y ahora es >0 = primera vez que sale esta runa
            if (cantAnterior === 0 && r.cantidad > 0) {
                btnEsp.classList.remove("bloqueada");
                btnEsp.classList.add("desbloqueada", r.rareza);
                btnEsp.setAttribute("onclick", "seleccionarRunaCol(this)");

                // anadir el canvas neon al boton si aun no lo tiene. el canvas
                // es donde se pinta la linea animada que da el efecto "neon"
                if (!btnEsp.querySelector(".col-btn-neon")) {
                    const c = document.createElement("canvas");
                    c.className = "col-btn-neon";
                    c.dataset.rareza = r.rareza;
                    btnEsp.insertBefore(c, btnEsp.firstChild);
                }

                // reemplazar el candado por la cantidad
                const candado = btnEsp.querySelector(".col-candado");
                if (candado) {
                    const right = candado.closest(".col-runa-right");
                    if (right) right.innerHTML = `<span class="col-runa-cantidad">x${r.cantidad}</span>`;
                }

                // arrancar la animacion neon del nuevo canvas. setTimeout
                // cortito para que el dom ya tenga el canvas listo
                setTimeout(iniciarNeonBotones, 60);
            }
        }

        // --- comunes (comun / poco_comun / rara / epica) ---
        // misma logica que arriba pero con mini canvas en lugar del neon gordo
        const btnCom = document.querySelector(`.col-runa-comun[data-id="${r.id}"]`);
        if (btnCom) {
            const cantAnterior = parseInt(btnCom.dataset.cantidad) || 0;
            btnCom.dataset.cantidad = r.cantidad;

            const cantSpan = btnCom.querySelector(".col-runa-cantidad");
            if (cantSpan) {
                cantSpan.textContent = "x" + Number(r.cantidad).toLocaleString();
            }

            if (cantAnterior === 0 && r.cantidad > 0) {
                btnCom.classList.remove("bloqueada");
                btnCom.classList.add("desbloqueada", r.rareza);
                btnCom.setAttribute("onclick", "seleccionarRunaCol(this)");

                // el canvas ya existe en todos los botones (activo o no),
                // solo marco el flag data-activa=1 y el loop de iniciarMiniCanvas
                // se encarga de repintarlo
                const miniCanvas = btnCom.querySelector(".col-comun-canvas");
                if (miniCanvas) miniCanvas.dataset.activa = "1";

                const candado = btnCom.querySelector(".col-candado");
                if (candado) {
                    const right = candado.closest(".col-runa-right");
                    if (right) right.innerHTML = `<span class="col-runa-cantidad">x${r.cantidad}</span>`;
                }

                setTimeout(iniciarMiniCanvas, 60);
            }
        }
    });

    // contador de coleccion (arriba, tipo "4 / 20")
    // cuento botones desbloqueados vs total. solo actualizo el numero antes
    // del "/", el total es fijo (no cambia nunca durante la partida)
    const totalEl = document.querySelector(".col-contador-num");
    if (totalEl) {
        const totalSpan = totalEl.querySelector(".col-contador-sep");
        if (totalSpan) {
            const desbloqueadas = document.querySelectorAll(
                ".col-runa-btn.desbloqueada, .col-runa-comun.desbloqueada"
            ).length;
            const total = document.querySelectorAll(
                ".col-runa-btn:not(.col-seccion-header), .col-runa-comun"
            ).length;
            // solo toco el primer nodo texto (el "4"), el " / 20" se queda
            const numNode = totalEl.firstChild;
            if (numNode && numNode.nodeType === Node.TEXT_NODE) {
                numNode.textContent = desbloqueadas;
            }
        }
    }
}

// guardarProgreso() — auto-save cada 30s + llamada manual desde ajustes.
// aqui solo mando coins y points (los que cambian en cliente). todo lo
// demas (runas, mejoras, bonus) se guarda en el momento de conseguirlo
// en el server, asi que no hace falta mandarlo desde aqui
function guardarProgreso() {
    fetch("PHP/guardar_progreso.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ coins, points })
    })
    .then(r => r.json())
    .then(data => {
        // mensajito de feedback (solo aparece si existe el elemento, que es
        // el caso solo en la pagina de ajustes). dura 3s y se borra solo
        const msg = document.getElementById("msg-guardado");
        if (msg) {
            msg.textContent = data.ok ? "Partida guardada correctamente." : "Error al guardar.";
            setTimeout(() => msg.textContent = "", 3000);
        }
    });
}

// auto-save cada 30 segundos. si el jugador cierra la pestana se pierde como
// mucho 29 segundos de coins/points, que es asumible. subirlo a 10s se cargaria
// el server, bajarlo a 60s enfada a los jugadores de sesiones cortas. 30s OK
setInterval(guardarProgreso, 30000);


// ideas futuras / TODO:
//   - anadir guardar al pasar a background (visibilitychange) para minimizar
//     la perdida cuando el jugador cambia de pestana
//   - cuando salga una eterna que dispare tambien una notificacion del
//     navegador (Notification API) si el jugador tiene la pestana en background
//   - el fallback de data.suerte (version vieja) ya se podria quitar, todos
//     los servers mandan suerte_grupo desde hace semanas. pero por si acaso
//   - agrupar especiales en mas de 2 rarezas en la misma tirada con una
//     animacion combinada especial (ej: si te salen eterna + mitica podria
//     mezclar las dos animaciones). muy ambicioso pero molaria
//   - queue de tiradas rapidas: que puedas hacer click-click-click y se vayan
//     encolando en lugar de bloquear el boton. requiere mas pensar