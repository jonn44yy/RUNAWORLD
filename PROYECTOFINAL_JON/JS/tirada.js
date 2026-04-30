// tirada.js — runaworld (v2: batch sync)
// version 2: adaptado al sistema de lotes (runa-sync.js). antes cada click
// disparaba un fetch. ahora tirarRuna() solo cuenta clicks en un buffer y
// runa-sync.js se encarga de mandarlos al server en lotes (cada 10 clicks,
// cada 30s, o al cerrar la pestana). la respuesta del server llega como un
// CustomEvent "runas:sync" y procesarRespuestaSync() hace lo mismo que
// hacia el .then() del fetch antiguo
//
// indice:
//   1. tirarRuna()              — registra click, NO fetch
//   2. procesarRespuestaSync()  — todo lo que hacia el .then() viejo
//   3. listeners de runas:sync y runas:recorte
//   4. actualizarPanelRunas()   — sin cambios, update in-place
//   5. guardarProgreso()        — auto-save cada 30s (solo idle)
//
// lenguaje interno:
//   tiradaBloqueada  = flag global (en ui.js) que bloquea clicks durante
//                      animaciones especiales (eterna ~20s, divina ~13s)
//   syncSafetyTimer  = timeout de 25s por si tiradaBloqueada se queda
//                      atascada en true. antes era por fetch, ahora es por
//                      animacion de especial
//   points_ps        = (base_runas + points_add) * multi_pts. base_runas
//                      viene del server, los otros son mejoras + boosts
//   suerte formula   = (1 + b) * c * d. el server manda "d" (suerte_grupo)
//   especiales       = eterna/divina/mitica/legendaria con animacion propia.
//                      en un lote de 10 runas puede haber varias especiales;
//                      solo se anima la mas rara, las demas van al panel
//
// hecho entre marzo y abril, reescrito 23/04 para batching. !hi

var syncSafetyTimer = null;

// ── 1. tirarRuna ─────────────────────────────────────────────
// se llama al pulsar el boton. ya NO hace fetch; solo cuenta el click
// en el buffer de runa-sync.js. el feedback visual es instantaneo
// (particulas + descuento de moneda) aunque el server procese despues
function tirarRuna() {
    if (tiradaBloqueada) return;
    if (coins < 1) return;

    resetIdleTimer();
    lanzarParticulasBoton();

    // descuento visual inmediato. el server confirmara con el valor real
    // cuando llegue la sync. si el jugador tiene 3 coins y clica 5 veces
    // rapido, las 2 ultimas no entran por el if de arriba. limpio
    coins -= 1;
    actualizarPantalla();

    // esto es TODO el cambio gordo. antes aqui habia un fetch de 50 lineas.
    // ahora solo cuento. runa-sync.js manda el lote cuando toca
    window.runaSync.registrarClic(1);
}

// ── 2. procesarRespuestaSync ─────────────────────────────────
// se llama desde el listener de "runas:sync". recibe el data que manda
// el server tras procesar un lote. es basicamente el .then() del fetch
// viejo pero con una diferencia clave: ahora coins y points vienen del
// server como fuente de verdad (antes los ignoraba y confiaba en el JS,
// ahora el server aplica los pasivos y manda el total correcto)
function procesarRespuestaSync(data) {

    // ── coins: fuente de verdad = servidor ────────────────────
    // coins SI se gastan en cada tirada, asi que el server manda el valor
    // correcto despues de descontar. el cliente lo adopta
    if (data.coins !== undefined) {
        coins = parseFloat(data.coins) || 0;
    }

    // ── points: el MAYOR entre cliente y servidor ─────────────
    // el server calcula points con points_por_seg base (solo runas). pero
    // el cliente usa la version boosteada (runas + mejoras de tienda +
    // boosts activos). como los boosts son client-side, el server no los
    // conoce y manda un valor mas bajo. si pisara el cliente con ese valor
    // los points "caen" de golpe (ej: de 1B a 800M). usando Math.max:
    //   - si el server manda mas (raro pero posible tras reconexion) → sube
    //   - si el server manda menos (lo normal con boosts) → el cliente mantiene
    // points solo bajan al comprar mejoras (comprar_mejora.php valida en
    // server), no en tiradas, asi que el max es seguro aqui
    if (data.points !== undefined) {
        var serverPoints = parseFloat(data.points) || 0;
        points = Math.max(points, serverPoints);
    }

    // points_por_seg base = aportacion de las RUNAS del jugador. las
    // mejoras additive/multi las aplica recalcularStatsDesdeMejoras en
    // ui.js, asi que aqui reaplico la formula con la nueva base
    if (data.points_por_seg !== undefined && !isNaN(parseFloat(data.points_por_seg))) {
        _runas_points_ps = parseFloat(data.points_por_seg);
        var nuevoPps = (_runas_points_ps + _mejora_points_add) * _mejora_multi_pts;
        points_ps_base = nuevoPps;
        points_ps      = nuevoPps;
        if (typeof aplicarBoosts === "function") aplicarBoosts();
    }

    // 27/04 v3: bloque de actualizacion de suerte_grupo eliminado.
    // el server ya no manda .suerte ni .suerte_grupo, las probabilidades son fijas.

    actualizarPantalla();

    // 27/04 v3: bloque de bonus_conseguidos eliminado, los bonus de grupo
    // (que daban suerte) fueron retirados con el sistema de suerte.

    // ── Runas ganadas en el lote ──────────────────────────────
    // si clicks_validos = 0 (sin coins o recortado), no hay runas
    if (!data.runas_ganadas || data.runas_ganadas.length === 0) {
        // nada que animar, pero coins/points ya se actualizaron arriba
        if (data.runas) actualizarPanelRunas(data.runas);
        return;
    }

    // separar especiales de normales
    var especiales = data.runas_ganadas.filter(function (r) {
        return r.rareza === "eterna" || r.rareza === "divina" ||
               r.rareza === "mitica" || r.rareza === "legendaria";
    });
    var normales = data.runas_ganadas.filter(function (r) {
        return r.rareza !== "eterna" && r.rareza !== "divina" &&
               r.rareza !== "mitica" && r.rareza !== "legendaria";
    });

    // cartitas normales, sin drama
    normales.forEach(function (r) { mostrarResultado(r); });

    // agrupar especiales por rareza. solo se anima la mas rara;
    // las demas van al panel sin cartita. si te salen 2 eternas y
    // 3 miticas, se anima eterna y las miticas solo suman
    var eternas     = especiales.filter(function (r) { return r.rareza === "eterna"; });
    var divinas     = especiales.filter(function (r) { return r.rareza === "divina"; });
    var miticas     = especiales.filter(function (r) { return r.rareza === "mitica"; });
    var legendarias = especiales.filter(function (r) { return r.rareza === "legendaria"; });

    // safety net: si la animacion especial se queda colgada 25s, desbloqueo
    // el boton por la fuerza. solo se activa si hay especial
    var hayEspecial = (eternas.length + divinas.length + miticas.length + legendarias.length) > 0;
    if (hayEspecial) {
        clearTimeout(syncSafetyTimer);
        syncSafetyTimer = setTimeout(function () {
            tiradaBloqueada = false;
        }, 25000);
    }

    if (eternas.length > 0) {
        var totalMulti = eternas.reduce(function (s, r) { return s + parseFloat(r.multiplicador); }, 0);
        var runaE = {
            nombre: eternas.length > 1 ? "¡" + eternas.length + " Eternas!" : eternas[0].nombre,
            rareza: "eterna",
            multiplicador: totalMulti.toFixed(2)
        };
        if (getAnimActiva("eterna")) {
            lanzarEterna(runaE);
        } else {
            mostrarResultado(runaE);
        }
    } else if (divinas.length > 0) {
        var totalMulti = divinas.reduce(function (s, r) { return s + parseFloat(r.multiplicador); }, 0);
        var runaD = {
            nombre: divinas.length > 1 ? "¡" + divinas.length + " Divinas!" : divinas[0].nombre,
            rareza: "divina",
            multiplicador: totalMulti.toFixed(2)
        };
        if (getAnimActiva("divina")) {
            lanzarDivina(runaD);
        } else {
            mostrarResultado(runaD);
        }
    } else if (miticas.length > 0) {
        var totalMulti = miticas.reduce(function (s, r) { return s + parseFloat(r.multiplicador); }, 0);
        var runaM = {
            nombre:        miticas.length > 1 ? "¡" + miticas.length + " Míticas!" : miticas[0].nombre,
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
        var totalMulti = legendarias.reduce(function (s, r) { return s + parseFloat(r.multiplicador); }, 0);
        var runaL = {
            nombre:        legendarias.length > 1 ? "¡" + legendarias.length + " Legendarias!" : legendarias[0].nombre,
            rareza:        "legendaria",
            multiplicador: totalMulti.toFixed(2),
            cantidad:      legendarias.length
        };
        mostrarResultado(runaL);
        if (!animLegendariaActiva) setTimeout(desactivarNeon, 100);
    }

    // refrescar cantidades en panel lateral y coleccion
    if (data.runas) actualizarPanelRunas(data.runas);
}

// ── 3. Listeners ─────────────────────────────────────────────
// runas:sync lo dispara runa-sync.js cada vez que el server responde OK
document.addEventListener("runas:sync", function (e) {
    procesarRespuestaSync(e.detail);
});

// runas:recorte se dispara cuando el server recorto clicks
// (sin coins o fuera del rango anti-cheat). informativo, sin drama.
// si quieres avisar al jugador en pantalla, hazlo aqui
document.addEventListener("runas:recorte", function (e) {
    var d = e.detail;
    console.log("[tirada] recorte:", d.enviados, "->", d.validos, "motivo:", d.motivo);
});

// runas:error para errores reales del server (sesion caducada etc)
document.addEventListener("runas:error", function (e) {
    console.warn("[tirada] error del server:", e.detail.error);
});

// ── 4. actualizarPanelRunas ──────────────────────────────────
// sin cambios respecto a la v1: update in-place del sidebar derecho y
// de los botones de coleccion. no reconstruyo html para no perder scroll
function actualizarPanelRunas(runas) {
    if (!runas || runas.length === 0) return;

    // panel derecho (sidebar "mis runas")
    runas.forEach(function (r) {
        var card = document.querySelector('.runa-card-btn[data-id="' + r.id + '"]');
        if (card) {
            card.dataset.cantidad = r.cantidad;
            var cantEl = card.querySelector(".runa-card-cantidad");
            var right  = card.querySelector(".runa-card-right");

            if (r.cantidad > 0 && card.classList.contains("runa-bloqueada")) {
                card.classList.remove("runa-bloqueada");
                if (right && !cantEl) {
                    right.innerHTML = '<span class="runa-card-cantidad">x' +
                        Number(r.cantidad).toLocaleString() +
                        '</span><span class="runa-card-flecha">▾</span>';
                }
            } else if (cantEl) {
                cantEl.textContent = "x" + Number(r.cantidad).toLocaleString();
            }
        }
    });

    // contador "X/Y desbloqueadas"
    var countEl = document.getElementById("panel-runas-count");
    if (countEl) {
        var desbloqueadas = document.querySelectorAll(".runa-card-btn:not(.runa-bloqueada)").length;
        var total = document.querySelectorAll(".runa-card-btn").length;
        countEl.textContent = desbloqueadas + "/" + total;
    }

    // botones de coleccion — especiales (con canvas neon)
    runas.forEach(function (r) {
        var btnEsp = document.querySelector('.col-runa-btn[data-id="' + r.id + '"]');
        if (btnEsp) {
            var cantAnterior = parseInt(btnEsp.dataset.cantidad) || 0;
            btnEsp.dataset.cantidad = r.cantidad;

            var cantSpan = btnEsp.querySelector(".col-runa-cantidad");
            if (cantSpan) {
                cantSpan.textContent = "x" + Number(r.cantidad).toLocaleString();
            }

            if (cantAnterior === 0 && r.cantidad > 0) {
                btnEsp.classList.remove("bloqueada");
                btnEsp.classList.add("desbloqueada", r.rareza);
                btnEsp.setAttribute("onclick", "seleccionarRunaCol(this)");

                if (!btnEsp.querySelector(".col-btn-neon")) {
                    var c = document.createElement("canvas");
                    c.className = "col-btn-neon";
                    c.dataset.rareza = r.rareza;
                    btnEsp.insertBefore(c, btnEsp.firstChild);
                }

                var candado = btnEsp.querySelector(".col-candado");
                if (candado) {
                    var right = candado.closest(".col-runa-right");
                    if (right) right.innerHTML = '<span class="col-runa-cantidad">x' + r.cantidad + '</span>';
                }

                setTimeout(iniciarNeonBotones, 60);
            }
        }

        // comunes (con mini canvas)
        var btnCom = document.querySelector('.col-runa-comun[data-id="' + r.id + '"]');
        if (btnCom) {
            var cantAnterior = parseInt(btnCom.dataset.cantidad) || 0;
            btnCom.dataset.cantidad = r.cantidad;

            var cantSpan = btnCom.querySelector(".col-runa-cantidad");
            if (cantSpan) {
                cantSpan.textContent = "x" + Number(r.cantidad).toLocaleString();
            }

            if (cantAnterior === 0 && r.cantidad > 0) {
                btnCom.classList.remove("bloqueada");
                btnCom.classList.add("desbloqueada", r.rareza);
                btnCom.setAttribute("onclick", "seleccionarRunaCol(this)");

                var miniCanvas = btnCom.querySelector(".col-comun-canvas");
                if (miniCanvas) miniCanvas.dataset.activa = "1";

                var candado = btnCom.querySelector(".col-candado");
                if (candado) {
                    var right = candado.closest(".col-runa-right");
                    if (right) right.innerHTML = '<span class="col-runa-cantidad">x' + r.cantidad + '</span>';
                }

                setTimeout(iniciarMiniCanvas, 60);
            }
        }
    });

    // contador de coleccion "4 / 20"
    var totalEl = document.querySelector(".col-contador-num");
    if (totalEl) {
        var totalSpan = totalEl.querySelector(".col-contador-sep");
        if (totalSpan) {
            var desbloqueadas = document.querySelectorAll(
                ".col-runa-btn.desbloqueada, .col-runa-comun.desbloqueada"
            ).length;
            var numNode = totalEl.firstChild;
            if (numNode && numNode.nodeType === Node.TEXT_NODE) {
                numNode.textContent = desbloqueadas;
            }
        }
    }
}

// ── 5. guardarProgreso ───────────────────────────────────────
// v2 (importante): el server es autoritativo. ya NO mandamos coins/points,
// el server calcula los valores reales (mejoras + runas + elapsed) y nos
// devuelve el resultado. nosotros solo actualizamos el display con lo que
// el server diga. esto cierra el exploit gordo de "mando 9999B y se
// guarda" y tambien sincroniza el rate (coins/seg, points/seg) al BD para
// que tirar_runa.php y comprar_mejora.php usen valores correctos
function guardarProgreso() {
    fetch("PHP/guardar_progreso.php", {
        method: "POST",
        // sin body: el server ya no acepta valores del cliente
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        var msg = document.getElementById("msg-guardado");
        if (msg) {
            msg.textContent = data.ok ? "Partida guardada correctamente." : "Error al guardar.";
            setTimeout(function () { msg.textContent = ""; }, 3000);
        }
        // sincronizo el display con el estado autoritativo del server.
        // si el cliente tenia el display "inflado" por un bug viejo, este
        // call lo corrige: el numero baja al valor real, pero a partir
        // de aqui sigue creciendo bien con la formula correcta
        if (data.ok && typeof window !== "undefined") {
            if (typeof coins  !== "undefined" && data.coins  !== undefined) coins  = parseFloat(data.coins);
            if (typeof points !== "undefined" && data.points !== undefined) points = parseFloat(data.points);
            if (typeof coins_ps  !== "undefined" && data.coins_por_seg  !== undefined) coins_ps  = parseFloat(data.coins_por_seg);
            if (typeof points_ps !== "undefined" && data.points_por_seg !== undefined) points_ps = parseFloat(data.points_por_seg);
        }
    })
    .catch(function () {
        // red caida, nada que hacer. el proximo tick lo reintenta
    });
}

// auto-save cada 30s para cubrir periodos idle
setInterval(guardarProgreso, 30000);


// ideas futuras / TODO:
//   - convertir guardarProgreso a server-side (que el server calcule los
//     pasivos, no confiar en el cliente) para eliminar el riesgo de doble-
//     conteo. requiere tocar guardar_progreso.php
//   - anadir guardar al pasar a background (visibilitychange) para idle
//   - notificacion del navegador al sacar eterna en background
//   - quitar el fallback suerte_grupo vs suerte cuando todo este estable
//   - animacion combinada si salen eterna + mitica en el mismo lote
